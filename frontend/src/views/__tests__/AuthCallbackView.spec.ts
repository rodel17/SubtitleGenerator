import { beforeEach, describe, it, expect } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import { getAuthToken } from '@/lib/api'
import AuthCallbackView from '../AuthCallbackView.vue'

async function visit(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'upload', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/auth/callback', component: AuthCallbackView },
    ],
  })
  await router.push(path)
  mount(AuthCallbackView, { global: { plugins: [router] } })
  await flushPromises()

  return router
}

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
})

describe('AuthCallbackView', () => {
  it('keeps the token from the URL fragment and opens the upload page', async () => {
    const router = await visit('/auth/callback#token=' + encodeURIComponent('1|abc'))

    expect(getAuthToken()).toBe('1|abc')
    expect(router.currentRoute.value.fullPath).toBe('/')
  })

  it('returns to sign-in with a message when there is no token', async () => {
    const router = await visit('/auth/callback')

    expect(getAuthToken()).toBeNull()
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.error).toBe('Sign-in failed. Please try again.')
  })
})
