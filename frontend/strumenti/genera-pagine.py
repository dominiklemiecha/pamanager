"""
Genera i moduli TypeScript dei template PHP dello staff (frontend/src/php/).

Per ogni pagina e widget converte il tratto di template (dopo la parte che
legge il database, ora nel backend) con php2js. I file generati non vanno
modificati a mano: si rigenerano con `python strumenti/genera-pagine.py`.
"""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(__file__))
from php2js import converti, funzioni_preambolo  # noqa: E402

RADICE = os.path.normpath(os.path.join(os.path.dirname(__file__), '..', '..'))
PUBBLICO = os.path.join(RADICE, 'public')
USCITA = os.path.join(RADICE, 'frontend', 'src', 'php')

HEADER_ADMIN = "include dirname(__DIR__) . '/includes/header-admin.php';\n?>"
FOOTER_ADMIN = "<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>"

# Dopo l'header, ancora dentro <?php (la pagina include altro prima del template).
HEADER_ADMIN_PHP = "include dirname(__DIR__) . '/includes/header-admin.php';\n"

# Letture del database rimaste nel template: diventano variabili calcolate dal backend.
FERIE_ADMIN = [
    (r"\$pendingCount = \(int\) Database::fetchColumn\(.*?\[\$__leaveCid\]\s*\);", "$pendingCount = (int) $__pendingCountDb;"),
    (r'Database::fetchOne\("SELECT lunch_break_start, lunch_break_end FROM companies WHERE id = \?", \[Tenant::currentCompanyId\(\)\]\)', "$__lunchCfgDb"),
    (r"try \{\s*\$__sickLate = LeaveRequest::sickPendingDocs\(24\);\s*\} catch \(Throwable \$e\) \{ \$__sickLate = \[\]; \}", ""),
]

# (file PHP, modulo generato, marcatore di inizio, marcatore di fine, sostituzioni regex)
PAGINE = [
    ('admin/index.php', 'pagine/admin/index', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/leave-requests.php', 'pagine/admin/leave-requests', HEADER_ADMIN_PHP, FOOTER_ADMIN, FERIE_ADMIN),
    ('admin/work-schedule.php', 'pagine/admin/work-schedule', HEADER_ADMIN_PHP, FOOTER_ADMIN, []),
    ('admin/punch-settings.php', 'pagine/admin/punch-settings', HEADER_ADMIN_PHP, FOOTER_ADMIN, []),
    ('admin/buoni-pasto.php', 'pagine/admin/buoni-pasto', HEADER_ADMIN_PHP, FOOTER_ADMIN, []),
    ('admin/door-settings.php', 'pagine/admin/door-settings', HEADER_ADMIN_PHP, FOOTER_ADMIN, []),
    ('admin/door-log.php', 'pagine/admin/door-log', HEADER_ADMIN_PHP, FOOTER_ADMIN, []),
    ('admin/profile.php', 'pagine/admin/profile', HEADER_ADMIN_PHP, FOOTER_ADMIN, []),
    ('admin/password-resets.php', 'pagine/admin/password-resets', HEADER_ADMIN_PHP, FOOTER_ADMIN, []),
    ('admin/departments.php', 'pagine/admin/departments', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/department-detail.php', 'pagine/admin/department-detail', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/companies.php', 'pagine/admin/companies', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/communications.php', 'pagine/admin/communications', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/documenti-aziendali.php', 'pagine/admin/documenti-aziendali', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/field-visibility.php', 'pagine/admin/field-visibility', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/presenze-export.php', 'pagine/admin/presenze-export', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/attendance.php', 'pagine/admin/attendance', HEADER_ADMIN, FOOTER_ADMIN, []),
]

# (file PHP, nome del widget, marcatore dopo il quale inizia il template, il tratto parte dentro <?php, condizione JS per disegnarlo)
WIDGET = [
    ('includes/_config-tabs.php', '_config-tabs', '(tab "orario")\n */\n', True, 'true'),
    ('includes/_cd-tabs.inc.php', '_cd-tabs.inc', " */\n", True, 'true'),
    ('includes/widget-tenant-wizard.php', 'widget-tenant-wizard', "        exit;\n    }\n}\n?>", False, 'P.t(V.__wTenantWizard)'),
    ('includes/widget-birthday-banner.php', 'widget-birthday-banner',
     "if ($todayViewerName === null && empty($todayOthers) && empty($tomorrowOthers)) return;\n", True, 'P.t(V.__wCompleanni)'),
    ('includes/widget-probation-alerts.php', 'widget-probation-alerts', "if (empty($__probationPending)) return;\n", True, 'P.t(V.__probationPending)'),
    ('includes/widget-availability-heatmap.php', 'widget-availability-heatmap',
     "$__hmMaskL104 = !in_array($__hmCurrentRole, ['admin', 'accountant'], true);\n", True, 'true'),
]

TESTA = '/* Generato da strumenti/genera-pagine.py a partire da public/{src}: non modificare a mano. */\n/* eslint-disable */\n// @ts-nocheck\n'


def scrivi(rel, testo):
    p = os.path.join(USCITA, rel + '.ts')
    os.makedirs(os.path.dirname(p), exist_ok=True)
    with open(p, 'w', encoding='utf8', newline='\n') as f:
        f.write(testo)


def main():
    for src, out, inizio, fine, sostituzioni in PAGINE:
        testo = open(os.path.join(PUBBLICO, src), encoding='utf8').read()
        a = testo.index(inizio) + len(inizio)
        b = testo.index(fine, a)
        funzioni = funzioni_preambolo(testo[:a])
        tratto = testo[a:b]
        for cerca, metti in sostituzioni:
            tratto, quante = re.subn(cerca, lambda _m: metti, tratto, flags=re.S)
            if quante != 1:
                raise SystemExit(f'{src}: sostituzione non trovata: {cerca}')
        if not inizio.rstrip().endswith('?>'):
            tratto = '<?php ' + tratto
        sorgente = ('<?php ' + '\n'.join(funzioni) + ' ?>' if funzioni else '') + tratto
        corpo = converti(sorgente)
        profondita = out.count('/')
        imp = '../' * profondita
        scrivi(out, TESTA.format(src=src) + f"import {{ P }} from '{imp}runtime';\nimport '{imp}widget';\n\n"
               f"export default function render(V: any, F: any): void {{\n  {corpo}\n}}\n")
        print('pagina', src)

    indice = [TESTA.format(src='includes/widget-*.php')]
    for src, nome, inizio, in_php, guardia in WIDGET:
        testo = open(os.path.join(PUBBLICO, src), encoding='utf8').read()
        a = testo.index(inizio) + len(inizio)
        corpo = converti(('<?php ' if in_php else '') + testo[a:])
        scrivi('widget/' + nome, TESTA.format(src=src) + "import { P } from '../runtime';\n\n"
               f"P.registraWidget('{nome}', (V: any, F: any) => {{\n  if (!({guardia})) return;\n  {corpo}\n}});\n")
        indice.append(f"import './{nome}';\n")
        print('widget', src)
    scrivi('widget/index', ''.join(indice))


if __name__ == '__main__':
    main()
