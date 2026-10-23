/* Generato da strumenti/genera-pagine.py a partire da public/includes/_cd-tabs.inc.php: non modificare a mano. */
/* eslint-disable */
// @ts-nocheck
import { P } from '../runtime';

P.registraWidget('_cd-tabs.inc', (V: any, F: any) => {
  if (!(true)) return;
  if (P.t(!P.t(P.chiama("defined", ["CD_TABS_CSS_LOADED"], V, F)))) { P.chiama("define", ["CD_TABS_CSS_LOADED", true], V, F); P.o("    <style>\n    .cd-tabs {\n        display: flex; gap: 2px; background: #f1f5f9; border-radius: 10px;\n        padding: 4px; flex-wrap: wrap;\n    }\n    .cd-tab {\n        padding: 7px 14px; border-radius: 8px;\n        font-size: 12px; font-weight: 600;\n        color: #6e7191; text-decoration: none;\n        white-space: nowrap; transition: all .12s ease;\n        border: none; background: transparent; cursor: pointer;\n        display: inline-flex; align-items: center; gap: 6px;\n    }\n    .cd-tab:hover { color: #0b3aa4; text-decoration: none; }\n    .cd-tab.active { background: white; color: #0b3aa4; box-shadow: 0 1px 3px rgba(15,23,42,0.08); }\n    .cd-tab .cd-tab-badge {\n        display: inline-flex; align-items: center; justify-content: center;\n        min-width: 18px; height: 18px; padding: 0 5px;\n        background: #fee2e2; color: #b91c1c;\n        border-radius: 999px; font-size: 10px; font-weight: 700;\n    }\n    .cd-tab.active .cd-tab-badge { background: #0b3aa4; color: white; }\n    </style>\n    "); }
});
