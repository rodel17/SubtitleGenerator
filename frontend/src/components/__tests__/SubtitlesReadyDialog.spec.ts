import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import { formatTime } from '@/lib/format'
import type { UploadItem } from '@/types/upload'
import SubtitlesReadyDialog from '../upload/SubtitlesReadyDialog.vue'

function item(overrides: Partial<UploadItem> = {}): UploadItem {
  return {
    id: 'item-1',
    serverId: 'server-1',
    name: 'team-interview.mp4',
    kind: 'video',
    size: 1.2 * 1024 * 1024,
    status: 'ready',
    language: 'ja',
    format: 'srt',
    downloadUrl: 'http://api.test/uploads/server-1/subtitles',
    ...overrides,
  }
}

const mountDialog = (overrides: Partial<UploadItem> = {}, props: Record<string, unknown> = {}) =>
  mount(SubtitlesReadyDialog, { props: { item: item(overrides), ...props } })

const button = (wrapper: ReturnType<typeof mountDialog>, name: string) =>
  wrapper.findAll('button').find((candidate) => candidate.text() === name)

describe('SubtitlesReadyDialog', () => {
  it('names the file with its language and size', () => {
    const wrapper = mountDialog()

    expect(wrapper.get('#ready-message').text().replace(/\s+/g, ' ')).toBe('team-interview.mp4 · Japanese · 1.2 MB')
  })

  it.each([
    ['srt', 'Download SRT'],
    ['vtt', 'Download VTT'],
    ['both', 'Download SRT + VTT (.zip)'],
  ] as const)('labels the download for %s', (format, label) => {
    expect(button(mountDialog({ format }), label)?.exists()).toBe(true)
  })

  it('says when the subtitles are deleted if they are not downloaded', () => {
    const expiresAt = '2026-10-25T18:30:00.000000Z'
    const wrapper = mountDialog({ expiresAt })

    expect(wrapper.text().replace(/\s+/g, ' ')).toContain(
      `They're deleted from our servers as soon as you download or email them, or at ${formatTime(expiresAt)} if you don't.`,
    )
  })

  it("offers to email them to the user's own address", async () => {
    const wrapper = mountDialog({}, { email: 'ada@example.com' })

    expect(wrapper.text()).toContain('Sends to ada@example.com')
    await button(wrapper, 'Email to me')!.trigger('click')

    expect(wrapper.emitted('email')).toEqual([['item-1']])
  })

  it('shows a spinner and blocks every action while the email is being requested', () => {
    const wrapper = mountDialog({ emailRequesting: true })

    const email = button(wrapper, 'Sending…')!
    expect(email.attributes('disabled')).toBeDefined()
    expect(email.attributes('aria-busy')).toBe('true')
    expect(email.find('[data-test="spinner"]').exists()).toBe(true)
    expect(button(wrapper, 'Download SRT')!.attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'Discard without saving')!.attributes('disabled')).toBeDefined()
  })

  it('shows why the email could not be sent', () => {
    const wrapper = mountDialog({ emailError: "Email isn't available right now. Please download your subtitles instead." })

    expect(wrapper.get('[role="alert"]').text()).toBe("Email isn't available right now. Please download your subtitles instead.")
  })

  it('emits download with the item id', async () => {
    const wrapper = mountDialog()

    await button(wrapper, 'Download SRT')!.trigger('click')

    expect(wrapper.emitted('download')).toEqual([['item-1']])
  })

  it('shows a spinner and blocks every action while downloading', () => {
    const wrapper = mountDialog({ downloading: true })

    const download = button(wrapper, 'Downloading…')!
    expect(download.attributes('disabled')).toBeDefined()
    expect(download.attributes('aria-busy')).toBe('true')
    expect(download.find('[data-test="spinner"]').exists()).toBe(true)
    expect(button(wrapper, 'Email to me')!.attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'Discard without saving')!.attributes('disabled')).toBeDefined()
  })

  it('shows why the last download did not work', () => {
    const wrapper = mountDialog({ downloadError: 'These subtitles are no longer available.' })

    expect(wrapper.get('[role="alert"]').text()).toBe('These subtitles are no longer available.')
  })

  it('emits discard only after confirming', async () => {
    const wrapper = mountDialog()

    await button(wrapper, 'Discard without saving')!.trigger('click')
    expect(wrapper.emitted('discard')).toBeUndefined()
    await button(wrapper, 'Discard')!.trigger('click')

    expect(wrapper.emitted('discard')).toEqual([['item-1']])
  })

  it('blocks the confirmation buttons while discarding', async () => {
    const wrapper = mountDialog({}, { discarding: true })

    await button(wrapper, 'Discard without saving')!.trigger('click')

    expect(button(wrapper, 'Discarding…')!.attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'Keep')!.attributes('disabled')).toBeDefined()
  })

  it('starts over at the download button for the next upload', async () => {
    const wrapper = mountDialog()
    await button(wrapper, 'Discard without saving')!.trigger('click')

    await wrapper.setProps({ item: item({ id: 'item-2', name: 'next.mp4' }) })

    expect(button(wrapper, 'Download SRT')?.exists()).toBe(true)
    expect(button(wrapper, 'Keep')).toBeUndefined()
  })
})
