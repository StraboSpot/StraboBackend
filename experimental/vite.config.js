import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  base: '/experimental/dist/',
  build: {
    outDir: 'dist',
    emptyDirOnBuild: true,
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'src/main.js')
      },
      output: {
        // main.js/main.css keep stable names (referenced by index.php, which
        // appends ?v=filemtime); everything else gets a content hash so stale
        // browser caches can never serve an old chunk against new code.
        entryFileNames: 'assets/[name].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: (assetInfo) => {
          const name = assetInfo.names?.[0] ?? assetInfo.name
          return name === 'main.css'
            ? 'assets/[name].[ext]'
            : 'assets/[name]-[hash].[ext]'
        }
      }
    }
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src')
    }
  },
  server: {
    proxy: {
      '/expdb': {
        target: 'http://localhost',
        changeOrigin: true
      }
    }
  }
})
