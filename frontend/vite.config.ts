import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
// import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    // vueDevTools(), // floating DevTools button; uncomment (with the import) to bring it back
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',   // listen on all interfaces, not just localhost
    port: 5173,
    // Besides localhost/IPs, answer the server's public host name (set by docker-compose.yml from the root .env)
    allowedHosts: process.env.APP_DOMAIN ? [process.env.APP_DOMAIN] : [],
    watch: {
      usePolling: true // needed because Docker volume mounts don't always trigger native file-change events
    },
    // No hmr.host: the browser opens the hot-reload websocket to the address it loaded the page from
    // (ws://localhost:5173 locally, wss://<APP_DOMAIN> through Caddy on the server)
  }
})
