/* Generato da strumenti/genera-pagine.py a partire da public/includes/widget-tenant-wizard.php: non modificare a mano. */
/* eslint-disable */
// @ts-nocheck
import { P } from '../runtime';

P.registraWidget('widget-tenant-wizard', (V: any, F: any) => {
  if (!(P.t(V.__wTenantWizard))) return;
  P.o("\n<div class=\"tenant-wizard\">\n    <div style=\"flex:1; min-width:240px;\">\n        <h3>&#127970; Benvenuto! Dai un nome alla tua azienda</h3>\n        <p>Il sistema e' in modalita multi-azienda. Inserisci il nome della tua azienda per iniziare. Potrai crearne altre in seguito.</p>\n    </div>\n    <form method=\"POST\">\n        "); P.o(P.chiama("CSRF::field", [], V, F)); P.o("        <input type=\"hidden\" name=\"action\" value=\"tenant_setup\">\n        <input type=\"text\" name=\"company_name\" required maxlength=\"120\" placeholder=\"Es. Connecteed S.r.l.\" autofocus>\n        <button type=\"submit\">Salva &amp; Inizia</button>\n    </form>\n</div>\n");
});
