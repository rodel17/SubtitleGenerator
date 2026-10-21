import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import FileDropzone from '../upload/FileDropzone.vue'

function chooseFiles(input: HTMLInputElement, files: File[]) {
  Object.defineProperty(input, 'files', { value: files, configurable: true })
}

describe('FileDropzone', () => {
  it('lists the accepted formats and the size limit', () => {
    const wrapper = mount(FileDropzone)

    expect(wrapper.text()).toContain('MP4, MOV, MKV, TS, MP3, WAV, M4A · up to 500 MB')
  })

  it('restricts the file picker to accepted extensions and allows several files', () => {
    const wrapper = mount(FileDropzone)

    const input = wrapper.get('input[type="file"]')
    expect(input.attributes('accept')).toBe('.mp4,.mov,.mkv,.ts,.mp3,.wav,.m4a')
    expect(input.attributes('multiple')).toBeDefined()
  })

  it('opens the file picker when the zone is clicked, through its label', () => {
    const wrapper = mount(FileDropzone)

    expect(wrapper.get('label').attributes('for')).toBe(wrapper.get('input').attributes('id'))
  })

  it('emits the chosen files', async () => {
    const wrapper = mount(FileDropzone)
    const input = wrapper.get('input').element as HTMLInputElement
    const files = [new File(['a'], 'a.mp4'), new File(['b'], 'b.mp3')]
    chooseFiles(input, files)

    await wrapper.get('input').trigger('change')

    expect(wrapper.emitted('add')).toEqual([[files]])
  })

  it('clears the picker so choosing the same file again still works', async () => {
    const wrapper = mount(FileDropzone)
    const input = wrapper.get('input').element as HTMLInputElement
    chooseFiles(input, [new File(['a'], 'a.mp4')])

    await wrapper.get('input').trigger('change')

    expect(input.value).toBe('')
  })

  it('emits nothing when the picker is closed without a choice', async () => {
    const wrapper = mount(FileDropzone)
    chooseFiles(wrapper.get('input').element as HTMLInputElement, [])

    await wrapper.get('input').trigger('change')

    expect(wrapper.emitted('add')).toBeUndefined()
  })

  it('emits the dropped files', async () => {
    const wrapper = mount(FileDropzone)
    const files = [new File(['a'], 'a.mov')]

    await wrapper.get('label').trigger('drop', { dataTransfer: { files } })

    expect(wrapper.emitted('add')).toEqual([[files]])
  })

  it('emits nothing for a drop without files', async () => {
    const wrapper = mount(FileDropzone)

    await wrapper.get('label').trigger('drop', { dataTransfer: { files: [] } })

    expect(wrapper.emitted('add')).toBeUndefined()
  })

  it('highlights the zone while a file is dragged over it', async () => {
    const wrapper = mount(FileDropzone)

    await wrapper.get('label').trigger('dragenter')

    expect(wrapper.text()).toContain('Drop to add your files')
    expect(wrapper.get('label').classes()).toContain('border-accent')
  })

  it.each(['dragleave', 'drop'])('removes the highlight on %s', async (event) => {
    const wrapper = mount(FileDropzone)
    await wrapper.get('label').trigger('dragover')

    await wrapper.get('label').trigger(event, { dataTransfer: { files: [] } })

    expect(wrapper.text()).toContain('Drag and drop your file here')
  })
})
