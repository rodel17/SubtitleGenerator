import { createRouter, createWebHistory } from 'vue-router'
import { isAxiosError } from 'axios'
import UploadView from '../views/UploadView.vue'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    /** Reachable while signed out; every other route requires Google sign-in */
    public?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'upload',
      component: UploadView,
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/auth/callback',
      name: 'auth-callback',
      component: () => import('../views/AuthCallbackView.vue'),
      meta: { public: true },
    },
    {
      path: '/about',
      name: 'about',
      // route level code-splitting
      // this generates a separate chunk (About.[hash].js) for this route
      // which is lazy-loaded when the route is visited.
      component: () => import('../views/AboutView.vue'),
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.name === 'login' && auth.isSignedIn) return { name: 'upload' }
  if (to.meta.public) return true
  if (!auth.isSignedIn) return { name: 'login' }

  if (!auth.user) {
    try {
      await auth.loadUser()
    } catch (error) {
      // Only a rejected token signs the user out; a server that's down shouldn't
      if (isAxiosError(error) && error.response?.status === 401) {
        auth.forget()
        return { name: 'login' }
      }
    }
  }

  return true
})

export default router
