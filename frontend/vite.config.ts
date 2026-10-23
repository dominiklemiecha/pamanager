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
      // Materiali e attestati dell'Academy.
      '/academy-file.php': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
      },
      // Allegati e immagini delle comunicazioni (indirizzo salvato nel testo).
      '/communication_file.php': {
        target: process.env.VITE_PROXY_TARGET || 'http://localhost:3100',
        changeOrigin: true,
      },
    },
  },
});
