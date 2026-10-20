import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import SelectedFilePreview from '../upload/SelectedFilePreview.vue'

const createObjectURL = vi.fn<typeof URL.createObjectURL>(() => 'blob:preview-1')
const revokeObjectURL = vi.fn<typeof URL.revokeObjectURL>()

beforeEach(() => {
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }))
  createObjectURL.mockClear()
  revokeObjectURL.mockClear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

function fileOf(name: string, bytes = 2048): File {
  return new File(['x'.repeat(bytes)], name)
}

describe('SelectedFilePreview', () => {
  it('plays a video file from a local URL, without uploading it', () => {
    const file = fileOf('clip.mp4')

    const wrapper = mount(SelectedFilePreview, { props: { file } })

    expect(createObjectURL).toHaveBeenCalledWith(file)
    const video = wrapper.get('video')
    expect(video.attributes('src')).toBe('blob:preview-1')
    expect(video.attributes('controls')).toBeDefined()
    expect(wrapper.find('audio').exists()).toBe(false)
  })

  it('uses an audio player for audio files', () => {
    const wrapper = mount(SelectedFilePreview, { props: { file: fileOf('talk.mp3') } })

    expect(wrapper.get('audio').attributes('src')).toBe('blob:preview-1')
    expect(wrapper.find('video').exists()).toBe(false)
  })

  it('shows the file name and size', () => {
    const wrapper = mount(SelectedFilePreview, { props: { file: fileOf('clip.mp4', 1536) } })

    expect(wrapper.text()).toContain('clip.mp4')
    expect(wrapper.text()).toContain('1.5 KB')
  })

  it('adds the duration once the player has read it', async () => {
    const wrapper = mount(SelectedFilePreview, { props: { file: fileOf('clip.mp4') } })
    const video = wrapper.get('video')
    Object.defineProperty(video.element, 'duration', { value: 125.4 })

    await video.trigger('loadedmetadata')

    // Collapse template whitespace the way the browser renders it
    expect(wrapper.text().replace(/\s+/g, ' ')).toContain('2.0 KB · 2:05')
  })

  it('omits the duration when the player reports it as unknown', async () => {
    const wrapper = mount(SelectedFilePreview, { props: { file: fileOf('live.ts') } })
    const video = wrapper.get('video')
    Object.defineProperty(video.element, 'duration', { value: Infinity })

    await video.trigger('loadedmetadata')

    expect(wrapper.text()).not.toContain('·')
  })

  it.each([
    ['video', 'clip.mkv'],
    ['audio', 'voice.m4a'],
  ])('explains that the %s cannot be previewed when the browser cannot play it', async (tag, name) => {
    const wrapper = mount(SelectedFilePreview, { props: { file: fileOf(name) } })

    await wrapper.get(tag).trigger('error')

    expect(wrapper.find(tag).exists()).toBe(false)
    expect(wrapper.text()).toContain("Your browser can't preview this format, but it can still be uploaded.")
  })

  it('emits remove from a labelled button', async () => {
    const wrapper = mount(SelectedFilePreview, { props: { file: fileOf('clip.mp4') } })

    await wrapper.get('button[aria-label="Remove clip.mp4"]').trigger('click')

    expect(wrapper.emitted('remove')).toHaveLength(1)
  })

  it('releases the local URL when removed from the page', () => {
    const wrapper = mount(SelectedFilePreview, { props: { file: fileOf('clip.mp4') } })

    wrapper.unmount()

    expect(revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
  })
})
