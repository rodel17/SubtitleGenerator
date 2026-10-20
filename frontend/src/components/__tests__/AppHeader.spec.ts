import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

import AppHeader from '../AppHeader.vue'

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/other', component: { template: '<div />' } },
    ],
  })
  await router.push(path)

  return mount(AppHeader, { global: { plugins: [router] } })
}

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
})
