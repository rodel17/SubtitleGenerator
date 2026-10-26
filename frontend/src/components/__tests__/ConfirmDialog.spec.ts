import { afterEach, describe, it, expect } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'

import ConfirmDialog from '../ConfirmDialog.vue'

enableAutoUnmount(afterEach)

function confirmButton(tone?: 'danger' | 'primary') {
  const wrapper = mount(ConfirmDialog, {
    props: { open: true, title: 'Sure?', message: 'Really.', confirmLabel: 'Go', ...(tone ? { tone } : {}) },
    attachTo: document.body,
  })
  return wrapper.findAll('button').find((button) => button.text() === 'Go')!
}

describe('ConfirmDialog', () => {
  it('shows the confirm button in red by default, for deleting', () => {
    expect(confirmButton().classes()).toContain('bg-red-600')
  })

  it('shows it in the accent colour for a choice that deletes nothing', () => {
    const button = confirmButton('primary')

    expect(button.classes()).toContain('bg-accent')
    expect(button.classes()).not.toContain('bg-red-600')
  })
})
