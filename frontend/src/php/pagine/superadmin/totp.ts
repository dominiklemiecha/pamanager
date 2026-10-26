/* Generato da strumenti/genera-pagine.py a partire da public/superadmin/totp.php: non modificare a mano. */
/* eslint-disable */
// @ts-nocheck
import { P } from '../../runtime';
import '../../widget';

export default function render(V: any, F: any): void {
  P.o("<form method=\"POST\" class=\"box\" autocomplete=\"off\">\n    "); P.o(P.chiama("CSRF::field", [], V, F)); P.o("    <h1>Verifica 2FA</h1>\n    <label>Codice</label>\n    <input class=\"code\" name=\"code\" inputmode=\"numeric\" pattern=\"[0-9]{6}\" maxlength=\"6\" autofocus required>\n    <button>Verifica</button>\n    "); if (P.t(V.error)) { P.o("<div class=\"err\">"); P.o(P.chiama("htmlspecialchars", [V.error], V, F)); P.o("</div>"); } P.o("    <p style=\"margin:16px 0 0;text-align:center;\"><a class=\"plain tiny\" href=\"logout.php\">Annulla</a></p>\n</form>");
}
