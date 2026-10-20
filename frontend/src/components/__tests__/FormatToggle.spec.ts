import { describe, it, expect } from 'vitest'

import { mount } from '@vue/test-utils'
import FormatToggle from '../upload/FormatToggle.vue'

describe('FormatToggle', () => {
  it('marks the current format as pressed and emits the picked one', async () => {
    const wrapper = mount(FormatToggle, { props: { modelValue: 'srt' } })

    const buttons = wrapper.findAll('button')
    expect(buttons[0]!.attributes('aria-pressed')).toBe('true')

    await buttons[1]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([['vtt']])
  })
})
