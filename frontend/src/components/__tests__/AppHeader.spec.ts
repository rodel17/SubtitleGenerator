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
  it('links the logo to the upload page', async () => {
    const wrapper = await mountAt('/other')

    const links = wrapper.findAll('a')
    expect(links).toHaveLength(1)
    expect(links[0]!.text()).toBe('Subtitle Generator')
    expect(links[0]!.attributes('href')).toBe('/')
  })

  it('has no navigation: there is only the upload page, since files are not kept', async () => {
    const wrapper = await mountAt('/')

    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('My files')
    expect(wrapper.text()).not.toContain('Settings')
  })

  it('shows only the logo while signed out', async () => {
    const wrapper = await mountAt('/', null)

    expect(wrapper.text()).toBe('Subtitle Generator')
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
