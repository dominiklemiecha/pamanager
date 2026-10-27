"""
Genera i moduli TypeScript dei template PHP dello staff (frontend/src/php/).

Per ogni pagina e widget converte il tratto di template (dopo la parte che
legge il database, ora nel backend) con php2js. I file generati non vanno
modificati a mano: si rigenerano con `python strumenti/genera-pagine.py`.
"""
import json
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

UTENTI_ESTERNI = [
    (r"\$linkableCompanies = !empty\(\$accessibleCids\).*?: \[\];", "$linkableCompanies = $__aziendeGestibili;"),
    (r"if \(!empty\(\$accessibleCids\)\) \{\s*\$allCompanies = .*?ORDER BY name\"\);\s*\}", "$allCompanies = $__aziendeGestibili;"),
    (r"\$assignedCompanyIds = \$current \? Tenant::getUserCompanyIds\(\(int\)\$current\['id'\]\) : \[\];", "$assignedCompanyIds = $__aziendeAssegnate;"),
]

# Il nuovo stack non ha responsabili di reparto ne' commercialisti: via le loro opzioni e sezioni dai template.
# Niente piu' visibilita' dei campi (serviva solo per il commercialista): via i chip dal modulo.
SENZA_VISIBILITA = [
    (r" <\?php \$renderVisChip\('[a-z_]+'\); \?>", ""),
]
SENZA_RESP_REPARTI = [
    (r" && \$dept\['admin_count'\] == 0", ""),
    (r'\s*<div class="dp-stat">\s*<div class="dp-stat-ic">\s*<svg[^>]*><path d="M12 22s8-4[^<]*/></svg>\s*</div>.*?<span class="dp-stat-l">Admin</span>\s*</div>\s*</div>', ""),
]
SENZA_RESP_DETTAGLIO = [
    (r'\s*<div class="banner-stat">\s*<span class="banner-stat-value"><\?= count\(\$deptAdmins\) \?></span>\s*<span class="banner-stat-label">Admin</span>\s*</div>', ""),
    (r"<!-- Sezione Admin -->.*?(?=<!-- Sezione Dipendenti -->)", ""),
]
# Il template parte dalla configurazione di visibilita' (le closure prima dell'header servono al template).
INIZIO_DIPENDENTI = "$visibilityConfig = FieldVisibility::getConfig();\n"
DIPENDENTI = [
    (r"\$search = \$_GET\['search'\].*?include dirname\(__DIR__\) \. '/includes/header-admin\.php';", ""),
    (r'Database::fetchColumn\(\s*"SELECT timbratura_enabled FROM companies WHERE id = \?", \[\$__currCid\]\s*\)', "$__timbratureDb"),
    (r'Database::fetchOne\("SELECT buoni_pasto_enabled, [^"]*", \[\$__wdCompId\]\)', "$__bpCompDb"),
    (r'Database::fetchOne\("SELECT default_ccnl_id FROM companies WHERE id = \?", \[[^;]*?\]\)', "$__defaultCcnlDb"),
    (r'Database::fetchAll\("SELECT id, name FROM departments WHERE company_id = \? AND is_active = TRUE ORDER BY name", \[\(int\)\$employee\[\'company_id\'\]\]\)', "$__deptsInlineDb"),
] + SENZA_VISIBILITA

ASSUNZIONI = [
    (r'Database::fetchAll\("SELECT id, name FROM departments WHERE company_id = \? AND is_active = TRUE ORDER BY name", \[[^;]*?\]\)', "$__deptsDb"),
]

# Academy: le variabili della pagina (corso, schede, elenchi) arrivano dal backend.
INIZIO_ACADEMY = "$errMessage = (string) ($_GET['err'] ?? '');\n"
ACADEMY = [
    (r"\$course = null;\s*if \(\$courseId > 0\).*?include __DIR__ \. \(\$readOnly \? '/header-admin-reparto\.php' : '/header-admin\.php'\);", ""),
]
FOOTER_INCLUDE = "<?php include __DIR__ . '/footer-admin.php'; ?>"

# Consulente: elenco delle richieste e costante del logo Wrike (dichiarata prima dell'header nel PHP).
CONSULENTE_ASSUNZIONI = [
    (r"\$cid = class_exists\('Tenant'\) \? Tenant::currentCompanyId\(\) : 1;\s*\$rows = Database::fetchAll\(.*?\]\s*\);", ""),
]
CONSULENTE_PROFILO = [
    (r"\A", "<?php define('WRIKE_NAVY', '#15243d'); ?>"),
]

# Superadmin: pagine senza cornice; i dati del report arrivano dal backend.
SA_UI = "<?php include __DIR__ . '/_ui.php'; ?></head><body>\n"
SA_FINE = "</body></html>"
SA_CLIC = [
    (r"\$where = \['1=1'\];.*?\$aw = implode\(' AND ', \$aWhere\);", ""),
    (r"if \(\(\$_GET\['export'\] \?\? ''\) === 'storico'\).*?\$maxN = max\(1, \(int\) \(\$topButtons\[0\]\['n'\] \?\? 1\)\);", ""),
    (r"\?>\s*<!DOCTYPE html>.*?<body>", "?>"),
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
    ('admin/departments.php', 'pagine/admin/departments', HEADER_ADMIN, FOOTER_ADMIN, SENZA_RESP_REPARTI),
    ('admin/department-detail.php', 'pagine/admin/department-detail', HEADER_ADMIN, FOOTER_ADMIN, SENZA_RESP_DETTAGLIO),
    ('admin/companies.php', 'pagine/admin/companies', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/communications.php', 'pagine/admin/communications', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/documenti-aziendali.php', 'pagine/admin/documenti-aziendali', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/presenze-export.php', 'pagine/admin/presenze-export', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('admin/attendance.php', 'pagine/admin/attendance', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('includes/_chat-page.inc.php', 'pagine/staff/chat', "include __DIR__ . '/header-' . $__chatLayout . '.php';\n?>", "<?php\n$__chatFooter", []),
    ('admin/employees.php', 'pagine/admin/employees', INIZIO_DIPENDENTI, FOOTER_ADMIN, DIPENDENTI),
    ('admin/hire-requests.php', 'pagine/admin/hire-requests', HEADER_ADMIN_PHP, FOOTER_ADMIN, ASSUNZIONI),
    ('includes/_academy-staff.inc.php', 'pagine/staff/academy', INIZIO_ACADEMY, FOOTER_INCLUDE, ACADEMY),
    ('includes/_academy-templates.inc.php', 'pagine/staff/academy-templates', "include __DIR__ . '/header-admin.php';\n?>", FOOTER_INCLUDE, []),
    ('consulente-lavoro/index.php', 'pagine/esterni/index-consulente', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('accountant/employees.php', 'pagine/esterni/employees', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('accountant/employee-documents.php', 'pagine/esterni/employee-documents', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('accountant/documents.php', 'pagine/esterni/documents', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('consulente-lavoro/leave-requests.php', 'pagine/esterni/leave-requests-consulente', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('accountant/presenze-export.php', 'pagine/esterni/presenze-export', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('accountant/profile.php', 'pagine/esterni/profile-accountant', HEADER_ADMIN, FOOTER_ADMIN, []),
    ('consulente-lavoro/profile.php', 'pagine/esterni/profile-consulente', HEADER_ADMIN, FOOTER_ADMIN, CONSULENTE_PROFILO),
    ('consulente-lavoro/hire-requests.php', 'pagine/esterni/hire-requests', HEADER_ADMIN_PHP, FOOTER_ADMIN, CONSULENTE_ASSUNZIONI),
    ('superadmin/login.php', 'pagine/superadmin/login', '<body class="auth">\n', SA_FINE, []),
    ('superadmin/totp.php', 'pagine/superadmin/totp', '<body class="auth">\n', SA_FINE, []),
    ('superadmin/index.php', 'pagine/superadmin/index', SA_UI, SA_FINE, []),
    ('superadmin/clic.php', 'pagine/superadmin/clic', "SuperAdmin::requireAuth();\n", SA_FINE, SA_CLIC),
    ('admin/_user-role-manager.inc.php', 'pagine/admin/user-role-manager', HEADER_ADMIN, FOOTER_ADMIN, UTENTI_ESTERNI),
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
            if quante == 0:
                raise SystemExit(f'{src}: sostituzione non trovata: {cerca}')
        # Marcatori HTML (es. <body>): il tratto parte fuori da <?php
        if not inizio.rstrip().endswith('?>') and not inizio.lstrip().startswith('<'):
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
    ui = open(os.path.join(PUBBLICO, 'superadmin/_ui.php'), encoding='utf8').read()
    css = ui[ui.index('<style>') + len('<style>'):ui.index('</style>')]
    scrivi('pagine/superadmin/stile', TESTA.format(src='superadmin/_ui.php') + 'export default ' + json.dumps(css) + ';\n')


if __name__ == '__main__':
    main()
