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

  it('offers SRT, VTT and Both in a labelled group', () => {
    const wrapper = mount(FormatToggle, { props: { modelValue: 'srt' } })

    const group = wrapper.get('[role="group"]')
    expect(wrapper.get(`#${group.attributes('aria-labelledby')}`).text()).toBe('Output format')
    expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['SRT', 'VTT', 'Both'])
  })

  it('presses exactly one button for each format', async () => {
    const wrapper = mount(FormatToggle, { props: { modelValue: 'both' } })

    const pressed = () => wrapper.findAll('button').map((button) => button.attributes('aria-pressed'))
    expect(pressed()).toEqual(['false', 'false', 'true'])

    await wrapper.setProps({ modelValue: 'vtt' })
    expect(pressed()).toEqual(['false', 'true', 'false'])
  })

  it('uses plain buttons so the form is never submitted', () => {
    const wrapper = mount(FormatToggle, { props: { modelValue: 'srt' } })

    expect(wrapper.findAll('button').every((button) => button.attributes('type') === 'button')).toBe(true)
  })
})
