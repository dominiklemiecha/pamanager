/**
 * Confronto visivo tra il vecchio PHP (baseline :8899) e il nuovo frontend
 * (:5180) sugli stessi dati sintetici. Per ogni caso apre la pagina sui due
 * sistemi a 1512 e 390 px, esegue le stesse azioni e confronta gli screenshot
 * pixel per pixel. Esito e immagini in test/visivo/.risultati/.
 *
 * Uso: node test/visivo/confronta.mjs [filtro]
 */
import { chromium } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';
import { CASI } from './casi.mjs';

const PHP = process.env.PHP_URL || 'http://localhost:8899';
const NODE = process.env.FRONT_URL || 'http://localhost:5180';
const OUT = path.join(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Z]:)/, '$1')), '.risultati');
const SOGLIA = 0.001; // al massimo lo 0,1% di pixel diversi
const filtro = process.argv[2] || '';
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const esiti = [];
for (const caso of CASI.filter((c) => c.nome.includes(filtro))) {
  for (const [w, h] of caso.misure ?? [[1512, 900], [390, 844]]) {
    const scatti = {};
    for (const [lato, base] of [['php', PHP], ['node', NODE]]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, locale: 'it-IT', timezoneId: 'Europe/Rome' });
      const page = await ctx.newPage();
      if (caso.utente) await caso.login(page, base, lato);
      await page.goto(base + caso.percorso);
      await page.waitForLoadState('networkidle');
      if (caso.azione) await caso.azione(page, lato);
      await page.waitForTimeout(caso.attesa ?? 600);
      // Il cursore lampeggiante nei campi non conta come differenza.
      await page.addStyleTag({ content: '*{caret-color:transparent!important}' });
      const file = path.join(OUT, `${caso.nome}-${w}-${lato}.png`);
      await page.screenshot({ path: file, fullPage: !!caso.paginaIntera });
      scatti[lato] = PNG.sync.read(fs.readFileSync(file));
      await ctx.close();
    }
    const a = scatti.php;
    const b = scatti.node;
    let diversi;
    if (a.width !== b.width || a.height !== b.height) {
      diversi = Infinity;
    } else {
      const diff = new PNG({ width: a.width, height: a.height });
      diversi = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: 0.1 });
      fs.writeFileSync(path.join(OUT, `${caso.nome}-${w}-diff.png`), PNG.sync.write(diff));
    }
    const quota = diversi / (a.width * a.height);
    const ok = quota <= SOGLIA;
    esiti.push({ caso: caso.nome, larghezza: w, diversi, quota: Number.isFinite(quota) ? +(quota * 100).toFixed(3) : 'dimensioni diverse', esito: ok ? 'PASS' : 'FAIL' });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${caso.nome} @${w}: ${Number.isFinite(diversi) ? diversi + ' pixel diversi' : `dimensioni ${a.width}x${a.height} contro ${b.width}x${b.height}`}`);
  }
}
await browser.close();
fs.writeFileSync(path.join(OUT, 'esiti.json'), JSON.stringify(esiti, null, 2));
process.exit(esiti.some((e) => e.esito === 'FAIL') ? 1 : 0);
