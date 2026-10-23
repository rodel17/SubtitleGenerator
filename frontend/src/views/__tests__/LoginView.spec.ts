import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

import LoginView from '../LoginView.vue'

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/login', component: LoginView }],
  })
  await router.push(path)

  return mount(LoginView, { global: { plugins: [router] } })
}

describe('LoginView', () => {
  it('links to Google sign-in on the API', async () => {
    vi.stubEnv('VITE_API_URL', 'http://api.test:8000/api')

    const link = (await mountAt('/login')).get('a')

    expect(link.text()).toBe('Sign in with Google')
    expect(link.attributes('href')).toBe('http://api.test:8000/auth/google/redirect')
    vi.unstubAllEnvs()
  })

  it('shows the error the backend sent back', async () => {
    const wrapper = await mountAt('/login?error=' + encodeURIComponent('Google sign-in was cancelled.'))

    expect(wrapper.get('[role="alert"]').text()).toBe('Google sign-in was cancelled.')
  })

  it('shows no error on a normal visit', async () => {
    expect((await mountAt('/login')).find('[role="alert"]').exists()).toBe(false)
  })

  it('shows that it is redirecting once clicked', async () => {
    const wrapper = await mountAt('/login')
    const link = wrapper.get('a')
    link.element.addEventListener('click', (event) => event.preventDefault())

    await link.trigger('click')

    expect(link.text()).toBe('Redirecting to Google…')
    expect(link.attributes('aria-disabled')).toBe('true')
  })
})
