import { beforeEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import { reloadTo } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types/auth'
import AppHeader from '../AppHeader.vue'

vi.mock('@/lib/navigation', () => ({ reloadTo: vi.fn<typeof reloadTo>() }))

const ada: User = { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', avatar: null }

async function mountAt(path: string, user: User | null = ada) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/other', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  useAuthStore().user = user

  return mount(AppHeader, { global: { plugins: [router] } })
}

beforeEach(() => {
  vi.clearAllMocks()
  setActivePinia(createPinia())
})

describe('AppHeader', () => {
  it('links the logo and the Upload tab to the upload page', async () => {
    const wrapper = await mountAt('/')

    const links = wrapper.findAll('a')
    expect(links[0]!.text()).toBe('Subtitle Generator')
    expect(links[0]!.attributes('href')).toBe('/')
    expect(links[1]!.text()).toBe('Upload')
    expect(links[1]!.attributes('href')).toBe('/')
  })

  it('shows the navigation tabs', async () => {
    const wrapper = await mountAt('/')

    expect(wrapper.get('nav').findAll('a').map((link) => link.text())).toEqual(['Upload', 'My files', 'Settings'])
  })

  it('highlights the Upload tab only on the upload page', async () => {
    const onUpload = await mountAt('/')
    const elsewhere = await mountAt('/other')

    expect(onUpload.get('nav a').classes()).toContain('bg-slate-100')
    expect(elsewhere.get('nav a').classes()).not.toContain('bg-slate-100')
  })

  it('shows only the logo while signed out', async () => {
    const wrapper = await mountAt('/', null)

    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Sign out')
  })

  it("shows the user's Google picture and name", async () => {
    const wrapper = await mountAt('/', { ...ada, avatar: 'https://lh3.googleusercontent.com/ada' })

    expect(wrapper.get('img').attributes('src')).toBe('https://lh3.googleusercontent.com/ada')
    expect(wrapper.text()).toContain('Ada Lovelace')
  })

  it('shows the first letter of the name when there is no picture', async () => {
    const wrapper = await mountAt('/')

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('span[aria-hidden="true"]').text()).toBe('A')
  })

  it('signs out, then reloads the sign-in page', async () => {
    const wrapper = await mountAt('/')
    const auth = useAuthStore()
    const signOut = vi.spyOn(auth, 'signOut').mockResolvedValue()

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(signOut).toHaveBeenCalled()
    expect(reloadTo).toHaveBeenCalledWith('/login')
  })
})
