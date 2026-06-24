import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Compiles the self-contained Torque dashboard stylesheet to a single
// `dist/torque.css`, served inline by Torque::css() / the asset route. The
// dashboard ships zero JavaScript of its own — Livewire 4 (a hard dependency)
// provides its runtime + Alpine in the host app. No host build step is needed.
export default defineConfig({
  plugins: [tailwindcss()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    rollupOptions: {
      input: 'resources/css/torque.css',
      output: {
        // Stable, hashless filename so the PHP helpers can find it.
        assetFileNames: 'torque.[ext]',
      },
    },
  },
});
