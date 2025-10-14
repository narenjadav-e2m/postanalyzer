import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import svgr from 'vite-plugin-svgr';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig({
  base: '/wp-content/plugins/postanalyzer/build/',
  plugins: [react(), svgr(), tailwindcss()],
  root: 'src',
  optimizeDeps: {
    include: ['jquery', 'select2'], // pre-bundle jQuery and Select2
  },
  build: {
    outDir: '../build',
    emptyOutDir: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/main.jsx'),
      output: {
        entryFileNames: 'index.js',
        chunkFileNames: '[name].js',
        assetFileNames: '[name].[ext]',
      },
    },
  },
});
