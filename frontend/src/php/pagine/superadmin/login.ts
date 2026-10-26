/* Generato da strumenti/genera-pagine.py a partire da public/superadmin/login.php: non modificare a mano. */
/* eslint-disable */
// @ts-nocheck
import { P } from '../../runtime';
import '../../widget';

export default function render(V: any, F: any): void {
  P.o("<form method=\"POST\" class=\"box\" autocomplete=\"off\">\n    "); P.o(P.chiama("CSRF::field", [], V, F)); P.o("    <h1>Superadmin</h1>\n    <label>Username</label>\n    <input name=\"username\" autofocus required>\n    <label>Password</label>\n    <input type=\"password\" name=\"password\" required>\n    <button>Accedi</button>\n    "); if (P.t(V.error)) { P.o("<div class=\"err\">"); P.o(P.chiama("htmlspecialchars", [V.error], V, F)); P.o("</div>"); } P.o("</form>");
}
