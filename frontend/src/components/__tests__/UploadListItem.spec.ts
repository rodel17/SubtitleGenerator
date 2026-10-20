import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import type { UploadItem } from '@/types/upload'
import UploadListItem from '../upload/UploadListItem.vue'

function item(overrides: Partial<UploadItem> = {}): UploadItem {
  return {
    id: 'item-1',
    name: 'team-interview.mp4',
    kind: 'video',
    size: 1.2 * 1024 * 1024,
    status: 'uploaded',
    language: 'en',
    format: 'srt',
    ...overrides,
  }
}

function buttonNames(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('button').map((button) => button.attributes('aria-label') ?? button.text())
}

describe('UploadListItem', () => {
  it('always shows the file name', () => {
    const wrapper = mount(UploadListItem, { props: { item: item({ name: 'podcast-episode-12.mp3' }) } })

    expect(wrapper.text()).toContain('podcast-episode-12.mp3')
  })

  describe('uploading', () => {
    it('shows the percentage and a matching progress bar', () => {
      const wrapper = mount(UploadListItem, { props: { item: item({ status: 'uploading', progress: 64 }) } })

      const bar = wrapper.get('[role="progressbar"]')
      expect(wrapper.text()).toContain('Uploading · 64%')
      expect(bar.attributes('aria-valuenow')).toBe('64')
      expect(bar.attributes('aria-label')).toBe('team-interview.mp4 progress')
      expect(bar.get('div').attributes('style')).toContain('width: 64%')
    })

    it('treats missing progress as 0%', () => {
      const wrapper = mount(UploadListItem, { props: { item: item({ status: 'uploading', progress: undefined }) } })

      expect(wrapper.text()).toContain('Uploading · 0%')
    })

    it('offers only a cancel button, which emits the item id', async () => {
      const wrapper = mount(UploadListItem, { props: { item: item({ status: 'uploading', progress: 10 }) } })

      expect(buttonNames(wrapper)).toEqual(['Cancel upload'])
      await wrapper.get('button[aria-label="Cancel upload"]').trigger('click')

      expect(wrapper.emitted('cancel')).toEqual([['item-1']])
    })
  })

  it.each([
    ['processing', 'Finalizing…'],
    ['transcribing', 'Transcribing…'],
  ] as const)('shows an indeterminate bar and no actions while %s', (status, label) => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status }) } })

    const bar = wrapper.get('[role="progressbar"]')
    expect(wrapper.text()).toContain(label)
    expect(bar.attributes('aria-valuenow')).toBeUndefined()
    expect(bar.find('.animate-indeterminate').exists()).toBe(true)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('shows language, format and size for an uploaded file', () => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status: 'uploaded', language: 'ja', format: 'vtt' }) } })

    expect(wrapper.text()).toContain('Uploaded · Japanese · VTT · 1.2 MB')
    expect(wrapper.find('[role="progressbar"]').exists()).toBe(false)
  })

  it('leaves out details that are not set', () => {
    const wrapper = mount(UploadListItem, {
      props: { item: item({ status: 'uploaded', language: undefined, format: undefined, size: 2048 }) },
    })

    expect(wrapper.text()).toContain('Uploaded · 2.0 KB')
  })

  it('shows the queued state with its details', () => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status: 'queued', format: 'both' }) } })

    expect(wrapper.text()).toContain('Queued · English · BOTH · 1.2 MB')
  })

  it('shows the error message for a failed upload', () => {
    const wrapper = mount(UploadListItem, {
      props: { item: item({ status: 'failed', error: 'Unsupported format. Try MP4, MOV, MKV.' }) },
    })

    expect(wrapper.text()).toContain('Unsupported format. Try MP4, MOV, MKV.')
    expect(wrapper.text()).not.toContain('Uploaded')
  })

  it.each(['queued', 'uploaded', 'failed'] as const)('offers a remove button when %s', async (status) => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status }) } })

    expect(buttonNames(wrapper)).toEqual(['Remove'])
    await wrapper.get('button').trigger('click')

    expect(wrapper.emitted('remove')).toEqual([['item-1']])
  })

  it('offers edit and download for a ready file', async () => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status: 'ready' }) } })

    expect(wrapper.text()).toContain('Ready · English · SRT · 1.2 MB')
    expect(buttonNames(wrapper)).toEqual(['Edit', 'Download'])
    await wrapper.findAll('button')[0]!.trigger('click')
    await wrapper.findAll('button')[1]!.trigger('click')

    expect(wrapper.emitted('edit')).toEqual([['item-1']])
    expect(wrapper.emitted('download')).toEqual([['item-1']])
  })

  it.each([
    ['failed', 'video', 'text-red-700'],
    ['uploaded', 'audio', 'text-amber-700'],
    ['uploaded', 'video', 'text-accent'],
  ] as const)('tints the icon for a %s %s file', (status, kind, tone) => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status, kind }) } })

    expect(wrapper.get('li > span').classes()).toContain(tone)
  })
})
