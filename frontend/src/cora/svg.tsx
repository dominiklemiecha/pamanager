/**
 * Icone SVG di Cora inserite nel markup, come CoraUi::svg() del PHP: stesso
 * file, stesse pulizie; con "tinta" i colori diventano currentColor e
 * l'icona segue il colore del testo.
 */
const FILE = import.meta.glob('/public/assets/images/cora/*.svg', { query: '?raw', import: 'default', eager: true }) as Record<string, string>;
const cache = new Map<string, string>();

export function svgCora(nome: string, tinta = false): string {
  const chiave = nome + (tinta ? ':t' : '');
  const c = cache.get(chiave);
  if (c !== undefined) return c;
  let s = FILE[`/public/assets/images/cora/${nome}`] ?? '';
  s = s.replace(/style="display: block;" /g, '').replace(/preserveAspectRatio="none" /g, '');
  if (tinta) s = s.replace(/(stroke|fill)="#[0-9A-Fa-f]{3,8}"/g, '$1="currentColor"');
  s = s.replace(/<svg /, '<svg aria-hidden="true" focusable="false" ');
  cache.set(chiave, s);
  return s;
}

/** Contenitore con l'SVG dentro (span, come nel markup PHP). */
export function Svg({ nome, tinta, className }: { nome: string; tinta?: boolean; className?: string }) {
  return <span className={className} dangerouslySetInnerHTML={{ __html: svgCora(nome, tinta) }} />;
}
