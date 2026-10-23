/* Generato da strumenti/genera-pagine.py a partire da public/admin/door-settings.php: non modificare a mano. */
/* eslint-disable */
// @ts-nocheck
import { P } from '../../runtime';
import '../../widget';

export default function render(V: any, F: any): void {
  P.include((P.s(P.chiama("dirname", [P.costante("__DIR__")], V, F)) + P.s("/includes/_config-tabs.php")), V, F); P.o("\n<div class=\"admin-page\">\n    <div class=\"cfg-card\" style=\"max-width: 760px;\">\n        <h3>Modulo Apertura Porta</h3>\n        <p class=\"desc\">Configura il dispositivo che gestisce l'apertura della porta dell'ufficio. Il pulsante apre l'interfaccia del dispositivo in una nuova scheda.</p>\n\n        <div class=\"cfg-actions\" style=\"justify-content: flex-start; border-top: none; padding-top: 0; margin-top: 4px;\">\n            <a href=\"http://door.local\" target=\"_blank\" rel=\"noopener\" class=\"cfg-btn cfg-btn-primary\">Configura</a>\n        </div>\n    </div>\n</div>\n\n");
}
