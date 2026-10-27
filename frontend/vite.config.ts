import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { phpStatici } from './vite-php-statici';

export default defineConfig({
  plugins: [react(), phpStatici()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5180,
    host: true,
    proxy: {
      '/api': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
        // La connessione in tempo reale passa dallo stesso proxy.
        ws: true,
      },
      // Servizio Socket.IO della chat (cartella realtime/), se avviato.
      '/realtime': {
        target: process.env.VITE_REALTIME_TARGET || 'http://localhost:3001',
        changeOrigin: true,
        ws: true,
      },
      // Foto profilo (indirizzi salvati nel database), servite dal backend.
      '/uploads': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
      },
      // PDF del contratto da firmare (incorporato nella pagina).
      '/employee/contract-pdf.php': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
      },
      // Timbratura da carta NFC e verifica pubblica degli attestati: pagine del backend.
      '/punch.php': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
      },
      '/verifica-attestato.php': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
      },
      // Materiali e attestati dell'Academy.
      '/academy-file.php': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
      },
      // Pagine staff agli indirizzi del PHP: la navigazione resta alla SPA, le
      // chiamate degli script delle pagine (POST, fetch) vanno al backend.
      '^/(admin|consulente-lavoro|formatore)/[a-z0-9_-]+\\.php': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
        bypass: (req) => {
          if ((req.method === 'GET' || req.method === 'HEAD') && String(req.headers.accept ?? '').includes('text/html')) return req.url;
          return undefined;
        },
      },
      // Allegati e immagini delle comunicazioni (indirizzo salvato nel testo).
      '/communication_file.php': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
      },
    },
  },
});
