import type { Plugin } from 'vite';

/**
 * Solo sviluppo: serve i file statici con nome .php (manifest) col tipo giusto
 * e le icone richieste come icon.php?size=N, come fara' nginx in produzione.
 */
export function phpStatici(): Plugin {
  return {
    name: 'cora-php-statici',
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        const url = req.url || '';
        const icona = /^\/assets\/images\/icon\.php\?size=(\d+)/.exec(url);
        if (icona) {
          const n = [72, 96, 128, 144, 152, 192, 384, 512].includes(Number(icona[1])) ? icona[1] : '192';
          req.url = `/assets/images/icon-${n}.png`;
        } else if (/^\/manifest(-cora)?\.json\.php/.test(url)) {
          res.setHeader('Content-Type', 'application/manifest+json');
        }
        next();
      });
    },
  };
}
