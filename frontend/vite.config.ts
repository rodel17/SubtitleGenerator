import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    vueDevTools(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',   // listen on all interfaces, not just localhost
    port: 5173,
    watch: {
      usePolling: true // needed because Docker volume mounts don't always trigger native file-change events
    },
    hmr: {
      host: 'localhost' // so the browser's websocket connects back correctly
    }
  }
})
