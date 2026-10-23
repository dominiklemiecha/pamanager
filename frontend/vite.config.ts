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
      // Foto profilo (indirizzi salvati nel database), servite dal backend.
      '/uploads': {
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
