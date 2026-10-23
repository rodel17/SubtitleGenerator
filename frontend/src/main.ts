import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { api } from './lib/api'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
app.use(router)

api.interceptors.response.use(undefined, (error) => {
  useAuthStore().handleApiError(error)
  return Promise.reject(error)
})

app.mount('#app')
