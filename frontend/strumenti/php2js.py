"""
Convertitore dei template PHP di Cora in funzioni JavaScript.

Prende un file PHP (o un suo tratto) e produce il corpo di una funzione JS che
riproduce lo stesso HTML: il testo fuori da <?php ?> diventa output, il codice
PHP (sottoinsieme usato dai template: if/elseif/else, foreach, for, while,
switch, match, funzioni, echo, assegnazioni, include) diventa JavaScript che
usa il runtime `P` (frontend/src/php/runtime.ts) per le funzioni di PHP.

Tutte le variabili PHP stanno nell'oggetto `V` (come lo scope di una pagina
PHP): `$x` -> `V.x`. Le funzioni definite nel file diventano `F.nome`.

Uso: python php2js.py file.php inizio fine > uscita.js
  inizio/fine: testo (marcatori) che delimitano il tratto da convertire
  (vuoti = tutto il file).
"""
import json
import re
import sys

# ─────────────────────────────── Lexer ───────────────────────────────

OPERATORI = sorted([
    '===', '!==', '<=>', '**=', '...', '??=', '<<=', '>>=',
    '==', '!=', '<>', '<=', '>=', '&&', '||', '??', '?->', '->', '=>', '::', '++', '--',
    '+=', '-=', '*=', '/=', '.=', '%=', '|=', '&=', '^=', '<<', '>>', '**',
    '+', '-', '*', '/', '%', '.', '=', '<', '>', '!', '?', ':', ',', ';', '(', ')', '[', ']', '{', '}', '&', '|', '^', '~', '@',
], key=len, reverse=True)

CAST = re.compile(r'\(\s*(int|integer|float|double|string|bool|boolean|array|object)\s*\)')


class Tok:
    def __init__(self, tipo, val, pos=0):
        self.tipo, self.val, self.pos = tipo, val, pos

    def __repr__(self):
        return f'{self.tipo}:{self.val!r}'


def lex_php(src):
    """Token di un blocco di codice PHP (senza i delimitatori <?php ?>)."""
    toks, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if c.isspace():
            i += 1
            continue
        if src.startswith('//', i) or c == '#':
            j = src.find('\n', i)
            i = n if j < 0 else j
            continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            i = n if j < 0 else j + 2
            continue
        m = CAST.match(src, i)
        if m:
            toks.append(Tok('cast', m.group(1), i))
            i = m.end()
            continue
        if c == '$' and i + 1 < n and (src[i + 1].isalpha() or src[i + 1] == '_'):
            m = re.compile(r'\$[A-Za-z_]\w*').match(src, i)
            toks.append(Tok('var', m.group(0)[1:], i))
            i = m.end()
            continue
        if c.isdigit() or (c == '.' and i + 1 < n and src[i + 1].isdigit()):
            m = re.compile(r'0x[0-9a-fA-F]+|\d+(?:_\d+)*(?:\.\d+)?(?:[eE][+-]?\d+)?|\.\d+').match(src, i)
            toks.append(Tok('num', m.group(0).replace('_', ''), i))
            i = m.end()
            continue
        if c.isalpha() or c == '_' or c == '\\':
            m = re.compile(r'\\?[A-Za-z_][\w]*(?:\\[A-Za-z_]\w*)*').match(src, i)
            toks.append(Tok('nome', m.group(0).lstrip('\\'), i))
            i = m.end()
            continue
        if c == "'":
            j, buf = i + 1, []
            while j < n and src[j] != "'":
                if src[j] == '\\' and j + 1 < n and src[j + 1] in "'\\":
                    buf.append(src[j + 1])
                    j += 2
                else:
                    buf.append(src[j])
                    j += 1
            toks.append(Tok('str', ''.join(buf), i))
            i = j + 1
            continue
        if c == '"':
            j = i + 1
            while j < n and src[j] != '"':
                j += 2 if src[j] == '\\' else 1
            toks.append(Tok('dstr', src[i + 1:j], i))
            i = j + 1
            continue
        for op in OPERATORI:
            if src.startswith(op, i):
                toks.append(Tok('op', op, i))
                i += len(op)
                break
        else:
            raise SyntaxError(f'carattere inatteso {c!r} in {src[max(0, i - 40):i + 40]!r}')
    toks.append(Tok('fine', None, n))
    return toks


# ─────────────────────────────── Parser espressioni ───────────────────────────────

# (precedenza, associativita') degli operatori binari (PHP 8)
BIN = {
    'or': (1, 'L'), 'xor': (2, 'L'), 'and': (3, 'L'),
    '=': (4, 'R'), '+=': (4, 'R'), '-=': (4, 'R'), '*=': (4, 'R'), '/=': (4, 'R'), '.=': (4, 'R'), '%=': (4, 'R'), '??=': (4, 'R'),
    '?': (5, 'L'),  # ternario
    '??': (6, 'R'), '||': (7, 'L'), '&&': (8, 'L'), '|': (9, 'L'), '^': (10, 'L'), '&': (11, 'L'),
    '==': (12, 'N'), '!=': (12, 'N'), '===': (12, 'N'), '!==': (12, 'N'), '<>': (12, 'N'), '<=>': (12, 'N'),
    '<': (13, 'N'), '<=': (13, 'N'), '>': (13, 'N'), '>=': (13, 'N'),
    '.': (14, 'L'), '<<': (15, 'L'), '>>': (15, 'L'), '+': (16, 'L'), '-': (16, 'L'),
    '*': (17, 'L'), '/': (17, 'L'), '%': (17, 'L'), 'instanceof': (19, 'L'), '**': (20, 'R'),
}


class Parser:
    def __init__(self, toks, conv):
        self.t, self.i, self.conv = toks, 0, conv

    @property
    def cur(self):
        return self.t[self.i]

    def avanti(self):
        t = self.t[self.i]
        self.i += 1
        return t

    def vede(self, tipo, val=None):
        t = self.cur
        return t.tipo == tipo and (val is None or t.val == val)

    def vedeop(self, val):
        return self.vede('op', val)

    def atteso(self, tipo, val=None):
        if not self.vede(tipo, val):
            raise SyntaxError(f'atteso {tipo} {val!r}, trovato {self.cur!r} vicino a {self.contesto()}')
        return self.avanti()

    def contesto(self):
        return ' '.join(str(t.val) for t in self.t[max(0, self.i - 6):self.i + 6])

    # ---- espressioni ----
    def espr(self, minp=0):
        sx = self.unario()
        while True:
            t = self.cur
            op = t.val if t.tipo == 'op' else (t.val.lower() if t.tipo == 'nome' and t.val.lower() in ('and', 'or', 'xor', 'instanceof') else None)
            if op not in BIN:
                break
            p, assoc = BIN[op]
            if p < minp:
                break
            self.avanti()
            if op == '?':
                if self.vedeop(':'):  # ?:
                    self.avanti()
                    dx = self.espr(p + 1)
                    sx = f'P.elvis({sx}, () => ({dx}))'
                else:
                    a = self.espr(0)
                    self.atteso('op', ':')
                    b = self.espr(p + 1)
                    sx = f'(P.t({sx}) ? ({a}) : ({b}))'
                continue
            dx = self.espr(p + (0 if assoc == 'R' else 1))
            sx = self.binario(op, sx, dx)
        return sx

    def binario(self, op, a, b):
        if op in ('=', '+=', '-=', '*=', '/=', '.=', '%=', '??='):
            return self.assegna(op, a, b)
        mappa = {
            '.': f'(P.s({a}) + P.s({b}))', '+': f'P.add({a}, {b})', '-': f'(P.n({a}) - P.n({b}))',
            '*': f'(P.n({a}) * P.n({b}))', '/': f'(P.n({a}) / P.n({b}))', '%': f'P.mod({a}, {b})', '**': f'(P.n({a}) ** P.n({b}))',
            '===': f'P.seq({a}, {b})', '!==': f'!P.seq({a}, {b})', '==': f'P.eq({a}, {b})', '!=': f'!P.eq({a}, {b})', '<>': f'!P.eq({a}, {b})',
            '<': f'P.lt({a}, {b})', '>': f'P.lt({b}, {a})', '<=': f'!P.lt({b}, {a})', '>=': f'!P.lt({a}, {b})', '<=>': f'P.cmp({a}, {b})',
            '&&': f'(P.t({a}) && P.t({b}))', 'and': f'(P.t({a}) && P.t({b}))', '||': f'(P.t({a}) || P.t({b}))', 'or': f'(P.t({a}) || P.t({b}))',
            'xor': f'(P.t({a}) !== P.t({b}))', '??': f'P.nc(() => ({a}), () => ({b}))',
            '&': f'(P.n({a}) & P.n({b}))', '|': f'(P.n({a}) | P.n({b}))', '^': f'(P.n({a}) ^ P.n({b}))',
            '<<': f'(P.n({a}) << P.n({b}))', '>>': f'(P.n({a}) >> P.n({b}))', 'instanceof': 'false',
        }
        return mappa[op]

    @staticmethod
    def dividi_virgole(s):
        """Elementi di primo livello separati da virgola (parentesi e stringhe bilanciate)."""
        out, livello, inizio, in_str = [], 0, 0, None
        i = 0
        while i < len(s):
            ch = s[i]
            if in_str:
                if ch == chr(92):
                    i += 2
                    continue
                if ch == in_str:
                    in_str = None
            elif ch in '"\'`':
                in_str = ch
            elif ch in '([{':
                livello += 1
            elif ch in ')]}':
                livello -= 1
            elif ch == ',' and livello == 0:
                out.append(s[inizio:i].strip())
                inizio = i + 1
            i += 1
        if s[inizio:].strip():
            out.append(s[inizio:].strip())
        return out

    def assegna(self, op, a, b):
        # [$x, $y] = ... / list($x, $y) = ...: destrutturazione
        if op == '=' and (a.startswith('[') or a.startswith('P.LIST([')):
            interno = a[1:-1] if a.startswith('[') else a[len('P.LIST(['):-2]
            elementi = self.dividi_virgole(interno)
            parti = [self.scrivi(el, f'P.g(__t, {i})') for i, el in enumerate(elementi) if el]
            return '((__t) => { ' + ' '.join(x + ';' for x in parti) + ' return __t; })(' + b + ')'
        # a e' un'espressione "lvalue": P.g(V.x, 'k') -> P.set(V.x, 'k', ...) ; V.x[] (P.push)
        if a.startswith('P.PUSH('):
            base = a[len('P.PUSH('):-1]
            return f'P.push({base}, {b})'
        if op == '=':
            return self.scrivi(a, b)
        if op == '??=':
            return f'(P.isset(() => {a}) ? {a} : {self.scrivi(a, b)})'
        bop = {'+=': '+', '-=': '-', '*=': '*', '/=': '/', '.=': '.', '%=': '%'}[op]
        return self.scrivi(a, self.binario(bop, a, b))

    def scrivi(self, a, val):
        m = re.fullmatch(r'P\.g\((.*), (.*)\)', a)
        if m and a.count('P.g(') >= 1:
            # ultimo accesso: P.g(base, chiave) -> P.set(base, chiave, val)
            base, chiave = self.dividi_g(a)
            return f'P.set({self.lvalue(base)}, {chiave}, {val})'
        return f'({a} = {val})'

    def lvalue(self, base):
        # Il contenitore deve esistere (in PHP $a['x']['y'] = 1 crea gli array intermedi)
        if base.startswith('P.g('):
            b, k = self.dividi_g(base)
            return f'P.auto({self.lvalue(b)}, {k})'
        m = re.fullmatch(r'(V|L\d+)\.(\w+)', base)
        if m:
            return f'P.autov({m.group(1)}, {json.dumps(m.group(2))})'
        return base

    @staticmethod
    def dividi_g(a):
        # P.g(BASE, KEY) con parentesi bilanciate: separa all'ultima virgola di primo livello
        interno = a[4:-1]
        livello, ultima = 0, -1
        in_str = None
        for i, ch in enumerate(interno):
            if in_str:
                if ch == '\\':
                    continue
                if ch == in_str:
                    in_str = None
                continue
            if ch in '"\'`':
                in_str = ch
            elif ch in '([{':
                livello += 1
            elif ch in ')]}':
                livello -= 1
            elif ch == ',' and livello == 0:
                ultima = i
        return interno[:ultima].strip(), interno[ultima + 1:].strip()

    def unario(self):
        t = self.cur
        if t.tipo == 'op' and t.val == '!':
            self.avanti()
            return f'!P.t({self.espr(18)})'
        if t.tipo == 'op' and t.val == '-':
            self.avanti()
            return f'(-P.n({self.espr(18)}))'
        if t.tipo == 'op' and t.val == '+':
            self.avanti()
            return f'P.n({self.espr(18)})'
        if t.tipo == 'op' and t.val == '@':
            self.avanti()
            return self.espr(18)
        if t.tipo == 'op' and t.val in ('++', '--'):
            self.avanti()
            v = self.postfisso(self.primario())
            return self.scrivi(v, f'(P.n({v}) {t.val[0]} 1)')
        if t.tipo == 'cast':
            self.avanti()
            v = self.espr(18)
            return {
                'int': f'P.int({v})', 'integer': f'P.int({v})', 'float': f'P.float({v})', 'double': f'P.float({v})',
                'string': f'P.s({v})', 'bool': f'P.t({v})', 'boolean': f'P.t({v})', 'array': f'P.arr({v})', 'object': v,
            }[t.val]
        if t.tipo == 'nome' and t.val.lower() == 'new':
            self.avanti()
            nome = self.atteso('nome').val
            args = self.argomenti() if self.vedeop('(') else []
            return self.postfisso(f'P.nuovo({json.dumps(nome)}, [{", ".join(args)}])')
        if t.tipo == 'nome' and t.val.lower() == 'clone':
            self.avanti()
            return f'P.clona({self.espr(18)})'
        if t.tipo == 'nome' and t.val.lower() == 'print':
            self.avanti()
            return f'(P.o({self.espr(5)}), 1)'
        return self.postfisso(self.primario())

    def argomenti(self):
        self.atteso('op', '(')
        args = []
        while not self.vedeop(')'):
            if self.vedeop('...'):
                self.avanti()
                args.append('...P.arr(' + self.espr(0) + ')')
            else:
                # argomenti con nome (PHP 8): nome: valore -> si ignora il nome
                if self.vede('nome') and self.t[self.i + 1].tipo == 'op' and self.t[self.i + 1].val == ':' and not (self.t[self.i + 2].tipo == 'op' and self.t[self.i + 2].val == ':'):
                    self.avanti()
                    self.avanti()
                args.append(self.espr(0))
            if self.vedeop(','):
                self.avanti()
        self.atteso('op', ')')
        return args

    def primario(self):
        t = self.avanti()
        if t.tipo == 'var':
            if t.val == 'this':
                return 'this'
            return f'{self.conv.scope[-1]}.{t.val}'
        if t.tipo == 'num':
            return t.val if not t.val.startswith('0x') else str(int(t.val, 16))
        if t.tipo == 'str':
            return json.dumps(t.val)
        if t.tipo == 'dstr':
            return self.interpola(t.val)
        if t.tipo == 'op' and t.val == '(':
            v = self.espr(0)
            self.atteso('op', ')')
            return f'({v})'
        if t.tipo == 'op' and t.val == '[':
            return self.array(']')
        if t.tipo == 'op' and t.val == '&':
            return self.primario()
        if t.tipo == 'nome':
            nome = t.val
            low = nome.lower()
            if low in ('true', 'false', 'null'):
                return {'true': 'true', 'false': 'false', 'null': 'null'}[low]
            if low == 'array' and self.vedeop('('):
                self.avanti()
                return self.array(')')
            if low == 'isset':
                args = self.argomenti()
                return '(' + ' && '.join(f'P.isset(() => {a})' for a in args) + ')'
            if low == 'empty':
                args = self.argomenti()
                return f'P.empty(() => {args[0]})'
            if low == 'list':
                args = self.argomenti()
                return f'P.LIST([{", ".join(args)}])'
            if low == 'static' and self.vede('nome') and self.cur.val.lower() in ('function', 'fn'):
                low = self.avanti().val.lower()
            if low == 'fn':
                return self.freccia()
            if low == 'function':
                return self.chiusura()
            if low == 'match':
                return self.match()
            if low in ('include', 'require', 'include_once', 'require_once'):
                v = self.espr(0)
                return f'P.include({v}, V, F)'
            if low == 'exit' or low == 'die':
                if self.vedeop('('):
                    self.argomenti()
                return 'P.exit()'
            if self.vedeop('::'):
                self.avanti()
                if self.vede('var'):
                    membro = self.avanti().val
                    return f'P.statico({json.dumps(nome)}, {json.dumps("$" + membro)})'
                membro = self.atteso('nome').val
                if self.vedeop('('):
                    args = self.argomenti()
                    return f'P.chiama({json.dumps(nome + "::" + membro)}, [{", ".join(args)}], V, F)'
                return f'P.costante({json.dumps(nome + "::" + membro)})'
            if self.vedeop('('):
                args = self.argomenti()
                if nome in self.conv.funzioni:
                    return f'F.{nome}({", ".join(args)})'
                return f'P.chiama({json.dumps(nome)}, [{", ".join(args)}], V, F)'
            return f'P.costante({json.dumps(nome)})'
        raise SyntaxError(f'espressione inattesa {t!r} vicino a {self.contesto()}')

    def postfisso(self, v):
        while True:
            if self.vedeop('['):
                self.avanti()
                if self.vedeop(']'):
                    self.avanti()
                    v = f'P.PUSH({v})'
                    continue
                k = self.espr(0)
                self.atteso('op', ']')
                v = f'P.g({v}, {k})'
            elif self.vedeop('{') and False:
                pass
            elif self.vedeop('->') or self.vedeop('?->'):
                self.avanti()
                if self.vede('nome'):
                    membro = self.avanti().val
                elif self.vede('var'):
                    membro = None
                    self.avanti()
                else:
                    raise SyntaxError('membro atteso')
                if self.vedeop('('):
                    args = self.argomenti()
                    v = f'P.metodo({v}, {json.dumps(membro)}, [{", ".join(args)}])'
                else:
                    v = f'P.g({v}, {json.dumps(membro)})'
            elif self.vedeop('('):
                args = self.argomenti()
                v = f'P.invoca({v}, [{", ".join(args)}])'
            elif self.vedeop('++') or self.vedeop('--'):
                op = self.avanti().val
                v = f'P.postinc(() => {v}, (x) => {self.scrivi(v, "x")}, {1 if op == "++" else -1})'
            else:
                return v

    def array(self, chiusa):
        voci, chiavi = [], False
        while not self.vedeop(chiusa):
            if self.vedeop('...'):
                self.avanti()
                voci.append(('...', self.espr(0)))
            else:
                a = self.espr(0)
                if self.vedeop('=>'):
                    self.avanti()
                    b = self.espr(0)
                    voci.append((a, b))
                    chiavi = True
                else:
                    voci.append((None, a))
            if self.vedeop(','):
                self.avanti()
        self.atteso('op', chiusa)
        if not chiavi and not any(k == '...' for k, _ in voci):
            return '[' + ', '.join(v for _, v in voci) + ']'
        parti = []
        for k, v in voci:
            if k == '...':
                parti.append(f'[null, {v}, true]')
            else:
                parti.append(f'[{k if k is not None else "null"}, {v}]')
        return 'P.assoc([' + ', '.join(parti) + '])'

    def parametri(self):
        self.atteso('op', '(')
        ps = []
        while not self.vedeop(')'):
            # tipo opzionale
            while self.vede('nome') or self.vedeop('?') or self.vedeop('|'):
                self.avanti()
            if self.vedeop('&'):
                self.avanti()
            if self.vedeop('...'):
                self.avanti()
            nome = self.atteso('var').val
            default = None
            if self.vedeop('='):
                self.avanti()
                default = self.espr(0)
            ps.append((nome, default))
            if self.vedeop(','):
                self.avanti()
        self.atteso('op', ')')
        return ps

    def freccia(self):
        ps = self.parametri()
        if self.vedeop(':'):
            self.avanti()
            while self.vede('nome') or self.vedeop('?') or self.vedeop('|'):
                self.avanti()
        self.atteso('op', '=>')
        nome, padre = self.conv.apri_scope(True)
        corpo = self.espr(4)
        self.conv.chiudi_scope()
        return self.conv.funzione_js(ps, f'return {corpo};', nome, padre)

    def chiusura(self):
        ps = self.parametri()
        if self.vede('nome') and self.cur.val.lower() == 'use':
            self.avanti()
            self.atteso('op', '(')
            while not self.vedeop(')'):
                self.avanti()
            self.atteso('op', ')')
        if self.vedeop(':'):
            self.avanti()
            while self.vede('nome') or self.vedeop('?') or self.vedeop('|'):
                self.avanti()
        self.atteso('op', '{')
        nome, padre = self.conv.apri_scope(True)
        corpo = self.conv.blocco_graffe(self)
        self.conv.chiudi_scope()
        return self.conv.funzione_js(ps, corpo, nome, padre)

    def match(self):
        self.atteso('op', '(')
        soggetto = self.espr(0)
        self.atteso('op', ')')
        self.atteso('op', '{')
        rami = []
        while not self.vedeop('}'):
            if self.vede('nome') and self.cur.val.lower() == 'default':
                self.avanti()
                conds = None
            else:
                conds = [self.espr(0)]
                while self.vedeop(','):
                    self.avanti()
                    if self.vedeop('=>'):
                        break
                    conds.append(self.espr(0))
            self.atteso('op', '=>')
            val = self.espr(0)
            rami.append((conds, val))
            if self.vedeop(','):
                self.avanti()
        self.atteso('op', '}')
        js = '(() => { const __m = ' + soggetto + '; '
        for conds, val in rami:
            if conds is None:
                js += f'return {val}; '
            else:
                js += 'if (' + ' || '.join(f'P.seq(__m, {c})' for c in conds) + f') return {val}; '
        js += 'throw new Error("match senza ramo"); })()'
        return js

    def interpola(self, s):
        """Stringa tra virgolette doppie: escape e variabili."""
        parti, buf, i = [], [], 0
        esc = {'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\', '$': '$', 'v': '\v', 'e': '\x1b', 'f': '\f', '0': '\0'}
        while i < len(s):
            c = s[i]
            if c == '\\' and i + 1 < len(s):
                d = s[i + 1]
                if d in esc:
                    buf.append(esc[d])
                    i += 2
                    continue
                if d == 'u' and i + 2 < len(s) and s[i + 2] == '{':
                    j = s.index('}', i)
                    buf.append(chr(int(s[i + 3:j], 16)))
                    i = j + 1
                    continue
                buf.append(c)
                i += 1
                continue
            if c == '{' and i + 1 < len(s) and s[i + 1] == '$':
                j, livello = i + 1, 1
                while livello:
                    j += 1
                    livello += {'{': 1, '}': -1}.get(s[j], 0)
                if buf:
                    parti.append(json.dumps(''.join(buf)))
                    buf = []
                p = Parser(lex_php(s[i + 1:j]), self.conv)
                parti.append(f'P.s({p.espr(0)})')
                i = j + 1
                continue
            if c == '$' and i + 1 < len(s) and (s[i + 1].isalpha() or s[i + 1] == '_'):
                m = re.compile(r'\$([A-Za-z_]\w*)(\[(?:\'[^\']*\'|"[^"]*"|\$?\w+)\]|->[A-Za-z_]\w*)?').match(s, i)
                if buf:
                    parti.append(json.dumps(''.join(buf)))
                    buf = []
                v = f'{self.conv.scope[-1]}.{m.group(1)}'
                if m.group(2):
                    acc = m.group(2)
                    if acc.startswith('->'):
                        v = f'P.g({v}, {json.dumps(acc[2:])})'
                    else:
                        k = acc[1:-1]
                        if k.startswith('$'):
                            k = f'{self.conv.scope[-1]}.{k[1:]}'
                        elif k[0] in '\'"':
                            k = json.dumps(k[1:-1])
                        elif not k.isdigit():
                            k = json.dumps(k)
                        v = f'P.g({v}, {k})'
                parti.append(f'P.s({v})')
                i = m.end()
                continue
            buf.append(c)
            i += 1
        if buf:
            parti.append(json.dumps(''.join(buf)))
        if not parti:
            return '""'
        return '(' + ' + '.join(parti) + ')'


# ─────────────────────────────── Istruzioni ───────────────────────────────

class Convertitore:
    def __init__(self):
        self.funzioni = set()
        self.scope = ['V']
        self.righe = []
        self.livello = 1

    def emetti(self, s):
        self.righe.append('  ' * self.livello + s)

    # Scope delle variabili: V per il template; ogni funzione ha il suo oggetto
    # (Lk). Le funzioni con nome non vedono le variabili del template (come in
    # PHP); le funzioni freccia e le chiusure le leggono (prototipo).
    def apri_scope(self, eredita):
        self.n_scope = getattr(self, 'n_scope', 0) + 1
        nome = f'L{self.n_scope}'
        padre = self.scope[-1] if eredita else None
        self.scope.append(nome)
        return nome, padre

    def chiudi_scope(self):
        self.scope.pop()

    def funzione_js(self, ps, corpo, nome_scope, padre):
        args = ', '.join(f'__a{i}' for i in range(len(ps)))
        init = ''.join(
            f'{nome_scope}.{n} = __a{i} === undefined ? {d if d is not None else "null"} : __a{i}; ' for i, (n, d) in enumerate(ps))
        crea = f'Object.create({padre})' if padre else '{}'
        return f'(({args}) => {{ const {nome_scope} = {crea}; {init}{corpo} }})'

    # Un blocco di codice: lista di token, istruzioni fino a fine
    def istruzioni(self, p, fine_alt=()):
        """Converte istruzioni finche' non incontra una parola di chiusura alternativa o la fine."""
        out = []
        while not p.vede('fine'):
            t = p.cur
            if t.tipo == 'op' and t.val == '}':
                break
            if t.tipo == 'nome' and t.val.lower() in fine_alt:
                break
            out.append(self.istruzione(p))
        return ' '.join(x for x in out if x)

    def blocco_graffe(self, p):
        corpo = self.istruzioni(p)
        p.atteso('op', '}')
        return corpo

    def corpo_controllo(self, p):
        """Dopo if(...)/foreach(...): '{ ... }' oppure ':' (sintassi alternativa, il corpo continua nei blocchi successivi) oppure una sola istruzione."""
        if p.vedeop('{'):
            p.avanti()
            return ('graffe', self.blocco_graffe(p))
        if p.vedeop(':'):
            p.avanti()
            return ('alt', None)
        return ('singola', self.istruzione(p))

    def istruzione(self, p):
        t = p.cur
        if t.tipo == 'op' and t.val == ';':
            p.avanti()
            return ''
        if t.tipo == 'op' and t.val == '{':
            p.avanti()
            return '{ ' + self.blocco_graffe(p) + ' }'
        if t.tipo == 'nome':
            k = t.val.lower()
            if k == 'echo':
                p.avanti()
                args = [p.espr(0)]
                while p.vedeop(','):
                    p.avanti()
                    args.append(p.espr(0))
                self.fine_istr(p)
                return ' '.join(f'P.o({a});' for a in args)
            if k in ('if', 'elseif'):
                p.avanti()
                if k == 'elseif':
                    return self.ramo_alt(p, 'elseif')
                p.atteso('op', '(')
                c = p.espr(0)
                p.atteso('op', ')')
                tipo, corpo = self.corpo_controllo(p)
                if tipo == 'alt':
                    return f'@@IF@@ if (P.t({c})) {{'
                js = f'if (P.t({c})) {{ {corpo} }}'
                while p.vede('nome') and p.cur.val.lower() in ('elseif', 'else'):
                    kw = p.avanti().val.lower()
                    if kw == 'else' and p.vede('nome') and p.cur.val.lower() == 'if':
                        p.avanti()
                        kw = 'elseif'
                    if kw == 'elseif':
                        p.atteso('op', '(')
                        c2 = p.espr(0)
                        p.atteso('op', ')')
                        _, corpo2 = self.corpo_controllo(p)
                        js += f' else if (P.t({c2})) {{ {corpo2} }}'
                    else:
                        _, corpo2 = self.corpo_controllo(p)
                        js += f' else {{ {corpo2} }}'
                return js
            if k == 'else':
                p.avanti()
                if p.vedeop(':'):
                    p.avanti()
                    return '} else {'
                if p.vede('nome') and p.cur.val.lower() == 'if':
                    p.avanti()
                    return self.ramo_alt(p, 'elseif')
                raise SyntaxError('else inatteso vicino a ' + p.contesto())
            if k in ('endif', 'endforeach', 'endfor', 'endwhile', 'endswitch'):
                p.avanti()
                self.fine_istr(p)
                return '}' if k != 'endswitch' else '} }'
            if k == 'foreach':
                p.avanti()
                p.atteso('op', '(')
                sorgente = p.espr(0)
                if not (p.vede('nome') and p.cur.val.lower() == 'as'):
                    raise SyntaxError('as atteso')
                p.avanti()
                a = self.bersaglio(p)
                chiave = None
                if p.vedeop('=>'):
                    p.avanti()
                    chiave, a = a, self.bersaglio(p)
                p.atteso('op', ')')
                testa = f'for (const [__k, __v] of P.iter({sorgente})) {{ ' + (f'{self.assegna_bersaglio(chiave, "__k")} ' if chiave else '') + f'{self.assegna_bersaglio(a, "__v")}'
                tipo, corpo = self.corpo_controllo(p)
                if tipo == 'alt':
                    return testa
                return f'{testa} {corpo} }}'
            if k == 'for':
                p.avanti()
                p.atteso('op', '(')
                parti = [[], [], []]
                for idx in range(3):
                    while not (p.vedeop(';') if idx < 2 else p.vedeop(')')):
                        parti[idx].append(p.espr(0))
                        if p.vedeop(','):
                            p.avanti()
                    p.avanti()
                testa = f'for ({", ".join(parti[0])}; {" && ".join(f"P.t({x})" for x in parti[1]) or "true"}; {", ".join(parti[2])}) {{'
                tipo, corpo = self.corpo_controllo(p)
                if tipo == 'alt':
                    return testa
                return f'{testa} {corpo} }}'
            if k == 'while':
                p.avanti()
                p.atteso('op', '(')
                c = p.espr(0)
                p.atteso('op', ')')
                tipo, corpo = self.corpo_controllo(p)
                if tipo == 'alt':
                    return f'while (P.t({c})) {{'
                return f'while (P.t({c})) {{ {corpo} }}'
            if k == 'switch':
                p.avanti()
                p.atteso('op', '(')
                c = p.espr(0)
                p.atteso('op', ')')
                if p.vedeop(':'):
                    p.avanti()
                    return f'{{ const __sw = {c}; switch (true) {{'
                p.atteso('op', '{')
                corpo = self.istruzioni(p)
                p.atteso('op', '}')
                return f'{{ const __sw = {c}; switch (true) {{ {corpo} }} }}'
            if k == 'case':
                p.avanti()
                c = p.espr(0)
                if p.vedeop(':') or p.vedeop(';'):
                    p.avanti()
                return f'case P.eq(__sw, {c}):'
            if k == 'default':
                p.avanti()
                if p.vedeop(':') or p.vedeop(';'):
                    p.avanti()
                return 'default:'
            if k in ('break', 'continue'):
                p.avanti()
                if p.vede('num'):
                    p.avanti()
                self.fine_istr(p)
                return f'{k};'
            if k == 'return':
                p.avanti()
                if p.vedeop(';') or p.vede('fine'):
                    self.fine_istr(p)
                    return 'return null;'
                v = p.espr(0)
                self.fine_istr(p)
                return f'return {v};'
            if k == 'function' and p.t[p.i + 1].tipo == 'nome':
                p.avanti()
                nome = p.avanti().val
                self.funzioni.add(nome)
                ps = p.parametri()
                if p.vedeop(':'):
                    p.avanti()
                    while p.vede('nome') or p.vedeop('?') or p.vedeop('|'):
                        p.avanti()
                p.atteso('op', '{')
                sc, padre = self.apri_scope(False)
                corpo = self.blocco_graffe(p)
                self.chiudi_scope()
                return f'F.{nome} = {self.funzione_js(ps, corpo, sc, padre)};'
            if k in ('global', 'static'):
                p.avanti()
                while not p.vedeop(';'):
                    p.avanti()
                p.avanti()
                return ''
            if k == 'unset':
                p.avanti()
                args = p.argomenti()
                self.fine_istr(p)
                return ' '.join(f'P.unset(() => {a}, (x) => {p.scrivi(a, "x")});' for a in args)
            if k in ('try',):
                p.avanti()
                p.atteso('op', '{')
                corpo = self.blocco_graffe(p)
                js = f'try {{ {corpo} }}'
                while p.vede('nome') and p.cur.val.lower() == 'catch':
                    p.avanti()
                    p.atteso('op', '(')
                    while not p.vedeop(')'):
                        p.avanti()
                    p.avanti()
                    p.atteso('op', '{')
                    corpo2 = self.blocco_graffe(p)
                    js += f' catch (__e) {{ {corpo2} }}'
                if p.vede('nome') and p.cur.val.lower() == 'finally':
                    p.avanti()
                    p.atteso('op', '{')
                    js += f' finally {{ {self.blocco_graffe(p)} }}'
                if 'catch' not in js and 'finally' not in js:
                    js += ' catch (__e) {}'
                return js
        v = p.espr(0)
        self.fine_istr(p)
        return f'{v};'

    def ramo_alt(self, p, kw):
        p.atteso('op', '(')
        c = p.espr(0)
        p.atteso('op', ')')
        tipo, corpo = self.corpo_controllo(p)
        if tipo == 'alt':
            return f'}} else if (P.t({c})) {{'
        raise SyntaxError('elseif con graffe fuori da un if')

    def bersaglio(self, p):
        if p.vede('nome') and p.cur.val.lower() == 'list':
            p.avanti()
            p.atteso('op', '(')
            vs = []
            while not p.vedeop(')'):
                vs.append(p.atteso('var').val)
                if p.vedeop(','):
                    p.avanti()
            p.avanti()
            return ('list', vs)
        if p.vedeop('['):
            p.avanti()
            vs = []
            while not p.vedeop(']'):
                vs.append(p.atteso('var').val)
                if p.vedeop(','):
                    p.avanti()
            p.avanti()
            return ('list', vs)
        if p.vedeop('&'):
            p.avanti()
        return ('var', p.atteso('var').val)

    def assegna_bersaglio(self, b, sorgente):
        sc = self.scope[-1]
        if b[0] == 'var':
            return f'{sc}.{b[1]} = {sorgente};'
        return ' '.join(f'{sc}.{n} = P.g({sorgente}, {i});' for i, n in enumerate(b[1]))

    @staticmethod
    def fine_istr(p):
        if p.vedeop(';'):
            p.avanti()


def converti(sorgente):
    """Converte un template PHP completo (HTML + blocchi PHP) nel corpo di una funzione JS."""
    conv = Convertitore()
    pezzi = []
    i, n = 0, len(sorgente)
    apertura = re.compile(r'<\?(php\b|=)')
    while i < n:
        m = apertura.search(sorgente, i)
        if not m:
            pezzi.append(('html', sorgente[i:]))
            break
        if m.start() > i:
            pezzi.append(('html', sorgente[i:m.start()]))
        # Fine del blocco come nel lexer di PHP: "?>" non conta dentro stringhe e commenti /* */,
        # mentre chiude anche un commento // o #.
        j = m.end()
        while j < n:
            c = sorgente[j]
            if sorgente.startswith('?>', j):
                break
            if sorgente.startswith('/*', j):
                k = sorgente.find('*/', j + 2)
                j = n if k < 0 else k + 2
                continue
            if c == '#' or sorgente.startswith('//', j):
                while j < n and sorgente[j] != '\n' and not sorgente.startswith('?>', j):
                    j += 1
                continue
            if c in '\'"':
                j += 1
                while j < n and sorgente[j] != c:
                    j += 2 if sorgente[j] == chr(92) else 1
                j += 1
                continue
            j += 1
        codice = sorgente[m.end():j]
        if m.group(1) == '=':
            codice = 'echo ' + codice + ';'
        pezzi.append(('php', codice))
        # In PHP un "?>" mangia un solo a-capo subito dopo
        i = j + 2
        if sorgente.startswith('\r\n', i):
            i += 2
        elif sorgente.startswith('\n', i):
            i += 1

    # Tutto il codice in un unico flusso di token: l'HTML diventa un'istruzione echo
    toks = []
    for tipo, testo in pezzi:
        if tipo == 'html':
            toks.append(Tok('nome', 'echo'))
            toks.append(Tok('str', testo))
            toks.append(Tok('op', ';'))
        else:
            for t in lex_php(testo)[:-1]:
                toks.append(t)
            # un blocco senza ';' finale (es. "<?= $x ?>" o "<?php endif ?>") chiude l'istruzione
            if toks and not (toks[-1].tipo == 'op' and toks[-1].val in (';', ':', '{', '}')):
                toks.append(Tok('op', ';'))
    toks.append(Tok('fine', None))

    # Prima passata: nomi delle funzioni definite (per chiamarle come F.nome)
    for k in range(len(toks) - 1):
        if toks[k].tipo == 'nome' and toks[k].val.lower() == 'function' and toks[k + 1].tipo == 'nome':
            conv.funzioni.add(toks[k + 1].val)

    p = Parser(toks, conv)
    corpo = conv.istruzioni(p)
    if not p.vede('fine'):
        raise SyntaxError('codice non consumato vicino a ' + p.contesto())
    corpo = corpo.replace('@@IF@@ ', '')
    return corpo

def funzioni_preambolo(codice):
    """Definizioni di funzione (function nome(...) {...}) contenute nel codice PHP prima del template."""
    out = []
    for m in re.finditer(r'\bfunction\s+[A-Za-z_]\w*\s*\(', codice):
        inizio = codice.rfind('\n', 0, m.start()) + 1
        if codice[inizio:m.start()].strip() != '':
            continue
        i = codice.index('{', m.end())
        livello, j, in_str = 0, i, None
        while True:
            ch = codice[j]
            if in_str:
                if ch == chr(92):
                    j += 2
                    continue
                if ch == in_str:
                    in_str = None
            elif ch in '\'"':
                in_str = ch
            elif ch == '{':
                livello += 1
            elif ch == '}':
                livello -= 1
                if livello == 0:
                    break
            j += 1
        out.append(codice[m.start():j + 1])
    return out


def converti_pagina(testo, inizio, fine):
    """Template tra i due marcatori, con in testa le funzioni definite prima (helper di formattazione)."""
    a = testo.index(inizio) + len(inizio) if inizio else 0
    b = testo.index(fine, a) if fine else len(testo)
    funzioni = funzioni_preambolo(testo[:a])
    sorgente = ('<?php ' + '\n'.join(funzioni) + ' ?>' if funzioni else '') + testo[a:b]
    return converti(sorgente)


if __name__ == '__main__':
    testo = open(sys.argv[1], encoding='utf8').read()
    inizio = sys.argv[2] if len(sys.argv) > 2 else ''
    fine = sys.argv[3] if len(sys.argv) > 3 else ''
    print(converti_pagina(testo, inizio, fine))
