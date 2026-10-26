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

    it("says when only a video's audio is being uploaded", () => {
      const wrapper = mount(UploadListItem, { props: { item: item({ status: 'uploading', progress: 30, audioOnly: true }) } })

      expect(wrapper.text()).toContain('Uploading audio · 30%')
    })
  })

  describe('preparing the audio of a video', () => {
    it('shows the percentage and a matching progress bar', () => {
      const wrapper = mount(UploadListItem, { props: { item: item({ status: 'preparing', progress: 12 }) } })

      expect(wrapper.text()).toContain('Preparing audio · 12%')
      expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('12')
    })

    it('offers only a cancel button, which emits the item id', async () => {
      const wrapper = mount(UploadListItem, { props: { item: item({ status: 'preparing', progress: 12 }) } })

      expect(buttonNames(wrapper)).toEqual(['Cancel upload'])
      await wrapper.get('button').trigger('click')

      expect(wrapper.emitted('cancel')).toEqual([['item-1']])
    })
  })

  it('says it waits for the user to allow uploading the whole video', () => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status: 'confirm_full_upload' }) } })

    expect(wrapper.text()).toContain('Waiting for your choice: upload the whole video?')
    expect(wrapper.find('[role="progressbar"]').exists()).toBe(false)
  })

  it.each([
    ['processing', 'Finalizing · 40%'],
    ['transcribing', 'Transcribing · 40%'],
  ] as const)('shows the server progress while %s', (status, label) => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status, progress: 40 }) } })

    const bar = wrapper.get('[role="progressbar"]')
    expect(wrapper.text()).toContain(label)
    expect(bar.attributes('aria-valuenow')).toBe('40')
    expect(bar.get('div').attributes('style')).toContain('width: 40%')
  })

  it('shows an indeterminate bar while waiting for a transcription worker', () => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status: 'uploaded' }) } })

    const bar = wrapper.get('[role="progressbar"]')
    expect(wrapper.text()).toContain('Waiting to transcribe…')
    expect(bar.attributes('aria-valuenow')).toBeUndefined()
    expect(bar.find('.animate-indeterminate').exists()).toBe(true)
  })

  it.each(['processing', 'uploaded', 'transcribing'] as const)(
    'offers only a stop button while %s on the server, which emits cancel',
    async (status) => {
      const wrapper = mount(UploadListItem, { props: { item: item({ status, progress: 95 }) } })

      expect(buttonNames(wrapper)).toEqual(['Stop and delete'])
      await wrapper.get('button').trigger('click')

      expect(wrapper.emitted('cancel')).toEqual([['item-1']])
    },
  )

  it.each([
    ['being sent', { emailStatus: 'sending' as const }],
    ['chosen before uploading', { emailWhenReady: true }],
  ])('shows ready subtitles that are %s as on their way by email, with nothing to click', (_case, email) => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status: 'ready', ...email }) } })

    expect(wrapper.text()).toContain('Emailing to you…')
    expect(wrapper.get('[role="progressbar"]').find('.animate-indeterminate').exists()).toBe(true)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('shows translating progress, with a stop button', () => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status: 'translating', progress: 80, translateTo: 'fil' }) } })

    expect(wrapper.text()).toContain('Translating · 80%')
    expect(buttonNames(wrapper)).toEqual(['Stop and delete'])
  })

  it('shows which language a queued file will be translated into', () => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status: 'queued', language: 'en', translateTo: 'fil' }) } })

    expect(wrapper.text()).toContain('Queued · English → Filipino · SRT · 1.2 MB')
  })

  it('leaves out details that are not set', () => {
    const wrapper = mount(UploadListItem, {
      props: { item: item({ status: 'queued', language: undefined, format: undefined, size: 2048 }) },
    })

    expect(wrapper.text()).toContain('Queued · 2.0 KB')
  })

  describe('interrupted', () => {
    const interrupted = () =>
      mount(UploadListItem, {
        props: { item: item({ status: 'interrupted', error: 'Upload was interrupted. Resume to continue where it stopped.' }) },
      })

    it('explains that the upload can continue', () => {
      expect(interrupted().text()).toContain('Upload was interrupted. Resume to continue where it stopped.')
    })

    it('offers remove and resume, each emitting the item id', async () => {
      const wrapper = interrupted()

      expect(buttonNames(wrapper)).toEqual(['Remove', 'Resume'])
      await wrapper.findAll('button')[0]!.trigger('click')
      await wrapper.findAll('button')[1]!.trigger('click')

      expect(wrapper.emitted('remove')).toEqual([['item-1']])
      expect(wrapper.emitted('resume')).toEqual([['item-1']])
    })

    it('tints the icon amber', () => {
      expect(interrupted().get('li > span').classes()).toContain('text-amber-700')
    })
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

  it.each(['queued', 'failed'] as const)('offers a remove button when %s', async (status) => {
    const wrapper = mount(UploadListItem, { props: { item: item({ status }) } })

    expect(buttonNames(wrapper)).toEqual(['Remove'])
    await wrapper.get('button').trigger('click')

    expect(wrapper.emitted('remove')).toEqual([['item-1']])
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
