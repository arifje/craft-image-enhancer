import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';
import {resolve} from 'node:path';

export default defineConfig({
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  plugins: [vue()],
  build: {
    emptyOutDir: true,
    lib: {
      entry: resolve(import.meta.dirname, 'src/web/assets/imageenhancer/src/image-creator.js'),
      name: 'ImageEnhancerImageCreatorBundle',
      formats: ['iife'],
      fileName: () => 'image-creator.js',
    },
    outDir: resolve(import.meta.dirname, 'src/web/assets/imageenhancer/dist/creator'),
    rollupOptions: {
      output: {
        assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css')
          ? 'image-creator.css'
          : 'assets/[name][extname]',
      },
    },
  },
});
