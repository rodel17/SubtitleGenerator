import { test, expect, type Page } from '@playwright/test'

import { FakeApi, chunkIndexOf, fakeUpload, wavBuffer } from './support/fakeApi'

let api: FakeApi

test.beforeEach(async ({ page }) => {
  api = new FakeApi(page)
  await api.install()
})

/** The drop zone's input; the page also has a hidden one for picking a file to resume */
function fileInput(page: Page) {
  return page.locator('input[type="file"]:not([data-test="resume-picker"])')
}

function uploadsList(page: Page) {
  return page.getByRole('list')
}

async function chooseWav(page: Page, name = 'interview.wav', seconds = 1) {
  await fileInput(page).setInputFiles({ name, mimeType: 'audio/wav', buffer: wavBuffer(seconds) })
}

test.describe('recent uploads', () => {
  test('lists finished and interrupted uploads from the API', async ({ page }) => {
    api.uploads = [
      fakeUpload({ id: 'a', name: 'product-demo.mov', language: 'ja', subtitle_format: 'vtt' }),
      fakeUpload({ id: 'b', name: 'half-done.mp4', status: 'uploading', completed_at: null }),
    ]

    await page.goto('/')

    await expect(uploadsList(page).getByText('Ready · Japanese · VTT · 1.2 MB')).toBeVisible()
    await expect(uploadsList(page).getByText('Upload was interrupted. Resume to continue where it stopped.')).toBeVisible()
  })

  test('links a ready upload to its subtitle file', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'done', subtitle_format: 'both' })]

    await page.goto('/')

    await expect(uploadsList(page).getByRole('link', { name: 'SRT' })).toHaveAttribute(
      'href',
      'http://localhost:8000/api/uploads/done/subtitles/srt',
    )
    await expect(uploadsList(page).getByRole('link', { name: 'VTT' })).toHaveAttribute(
      'href',
      'http://localhost:8000/api/uploads/done/subtitles/vtt',
    )
  })

  test('shows the empty state when there are no uploads', async ({ page }) => {
    await page.goto('/')

    await expect(page.getByText('No uploads yet.')).toBeVisible()
  })

  test('shows an error when the API is unavailable', async ({ page }) => {
    api.onList = (route) => route.fulfill({ status: 500, body: '' })

    await page.goto('/')

    await expect(page.getByText('Request failed with status code 500')).toBeVisible()
  })

  test('removes an upload from the list and the server', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'gone', status: 'failed', error: 'No speech was detected in this file.' })]
    await page.goto('/')

    await uploadsList(page).getByRole('button', { name: 'Remove' }).click()

    await expect(page.getByText('No uploads yet.')).toBeVisible()
    expect(api.deletions).toEqual(['gone'])
  })
})

test.describe('preview', () => {
  test('plays a chosen audio file and shows its length before anything is uploaded', async ({ page }) => {
    await page.goto('/')

    await chooseWav(page, 'interview.wav', 3)

    await expect(page.getByText('Selected (1)')).toBeVisible()
    await expect(page.locator('audio')).toBeVisible()
    await expect(page.getByText('interview.wav')).toBeVisible()
    await expect(page.getByText('23 KB · 0:03')).toBeVisible()
    expect(api.startRequests).toHaveLength(0)
  })

  test('explains when the browser cannot play the chosen video', async ({ page }) => {
    await page.goto('/')

    await fileInput(page).setInputFiles({ name: 'clip.mkv', mimeType: 'video/x-matroska', buffer: Buffer.from('not really a video') })

    await expect(page.getByText("Your browser can't preview this format, but it can still be uploaded.")).toBeVisible()
    await expect(page.getByRole('button', { name: 'Generate subtitles' })).toBeEnabled()
  })

  test('removes a file from the selection', async ({ page }) => {
    await page.goto('/')
    await chooseWav(page, 'interview.wav')

    await page.getByRole('button', { name: 'Remove interview.wav' }).click()

    await expect(page.getByText('Selected')).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Generate subtitles' })).toBeDisabled()
  })

  test('accepts files dropped onto the drop zone', async ({ page }) => {
    await page.goto('/')
    const dataTransfer = await page.evaluateHandle(() => {
      const transfer = new DataTransfer()
      transfer.items.add(new File(['x'], 'dropped.mp3', { type: 'audio/mpeg' }))
      return transfer
    })

    await page.locator('label[for="file-input"]').dispatchEvent('drop', { dataTransfer })

    await expect(page.getByText('dropped.mp3')).toBeVisible()
    await expect(page.getByText('Selected (1)')).toBeVisible()
  })

  test('lists an unsupported file as failed instead of selecting it', async ({ page }) => {
    await page.goto('/')

    await fileInput(page).setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') })

    await expect(uploadsList(page).getByText('notes.txt')).toBeVisible()
    await expect(uploadsList(page).getByText('Unsupported format. Try MP4, MOV, MKV.')).toBeVisible()
    await expect(page.getByText('Selected')).toHaveCount(0)
  })
})

test.describe('uploading', () => {
  test('uploads the file in chunks with the chosen options', async ({ page }) => {
    await page.goto('/')
    await chooseWav(page, 'interview.wav', 1) // 8,044 bytes -> 2 chunks of 4,096
    await page.getByLabel('Spoken language').selectOption('ja')
    await page.getByRole('button', { name: 'VTT' }).click()

    await page.getByRole('button', { name: 'Generate subtitles' }).click()

    await expect(uploadsList(page).getByText('Transcribing · 50%')).toBeVisible()
    await expect(uploadsList(page).getByText('Ready · Japanese · VTT · 7.9 KB')).toBeVisible()
    await expect(uploadsList(page).getByRole('link', { name: 'Download' })).toHaveAttribute(
      'href',
      'http://localhost:8000/api/uploads/upload-1/subtitles/vtt',
    )
    expect(api.startRequests).toEqual([
      {
        name: 'interview.wav',
        size: 8044,
        mime_type: 'audio/wav',
        last_modified: expect.any(Number),
        language: 'ja',
        subtitle_format: 'vtt',
      },
    ])
    expect(api.chunkIndexes.toSorted()).toEqual([0, 1])
    expect(api.completions).toEqual(['upload-1'])
    await expect(page.getByText('Selected')).toHaveCount(0)
  })

  test('shows the server reason when it refuses to start the upload', async ({ page }) => {
    api.onStart = (route) =>
      api.json(route, 422, {
        message: 'The file is larger than the 2 GB limit.',
        errors: { size: ['The file is larger than the 2 GB limit.'] },
      })
    await page.goto('/')
    await chooseWav(page)

    await page.getByRole('button', { name: 'Generate subtitles' }).click()

    await expect(uploadsList(page).getByText('The file is larger than the 2 GB limit.')).toBeVisible()
    expect(api.chunkIndexes).toHaveLength(0)
  })

  test('shows the server reason when the file is not real media', async ({ page }) => {
    api.onComplete = (route) =>
      api.json(route, 422, { errors: { file: ['The file is not a supported video or audio file.'] } })
    await page.goto('/')
    await chooseWav(page)

    await page.getByRole('button', { name: 'Generate subtitles' }).click()

    await expect(uploadsList(page).getByText('The file is not a supported video or audio file.')).toBeVisible()
  })

  test('retries a chunk that failed and still finishes', async ({ page }) => {
    const statuses = [503] // first chunk request fails, the rest succeed
    api.onChunk = (route, request) => {
      api.chunkIndexes.push(chunkIndexOf(request))
      return route.fulfill({ status: statuses.shift() ?? 204 })
    }
    await page.goto('/')
    await chooseWav(page)

    await page.getByRole('button', { name: 'Generate subtitles' }).click()

    await expect(uploadsList(page).getByText('Ready ·')).toBeVisible({ timeout: 10_000 })
    expect(api.chunkIndexes).toHaveLength(3)
    expect([...new Set(api.chunkIndexes)].toSorted()).toEqual([0, 1])
  })

  test('shows progress and cancels a running upload', async ({ page }) => {
    api.onChunk = () => new Promise(() => {}) // hold every chunk so the upload stays in progress
    await page.goto('/')
    await chooseWav(page)

    await page.getByRole('button', { name: 'Generate subtitles' }).click()
    await expect(uploadsList(page).getByText(/Uploading · \d+%/)).toBeVisible()
    await page.getByRole('button', { name: 'Cancel upload' }).click()

    await expect(page.getByText('No uploads yet.')).toBeVisible()
    await expect.poll(() => api.deletions).toEqual(['upload-1'])
  })

  test('uploads several files one after another', async ({ page }) => {
    await page.goto('/')
    await fileInput(page).setInputFiles([
      { name: 'first.wav', mimeType: 'audio/wav', buffer: wavBuffer(1) },
      { name: 'second.wav', mimeType: 'audio/wav', buffer: wavBuffer(1) },
    ])

    await page.getByRole('button', { name: 'Generate subtitles' }).click()

    await expect(uploadsList(page).getByText('Ready ·')).toHaveCount(2, { timeout: 10_000 })
    expect(api.startRequests.map((request) => request.name)).toEqual(['first.wav', 'second.wav'])
  })

  test('resumes an upload interrupted before a reload, sending only the missing chunks', async ({ page }) => {
    const file = { name: 'interview.wav', mimeType: 'audio/wav', buffer: wavBuffer(1) } // 2 chunks
    api.uploads = [
      fakeUpload({
        id: 'half-done',
        name: 'interview.wav',
        size: file.buffer.length,
        chunk_size: api.chunkSize,
        total_chunks: 2,
        status: 'uploading',
        completed_at: null,
      }),
    ]
    api.receivedChunks['half-done'] = [0]
    await page.goto('/')

    const chooser = page.waitForEvent('filechooser')
    await uploadsList(page).getByRole('button', { name: 'Resume' }).click()
    await (await chooser).setFiles(file)

    await expect(uploadsList(page).getByText('Ready ·')).toBeVisible({ timeout: 10_000 })
    expect(api.chunkIndexes).toEqual([1])
    expect(api.startRequests).toHaveLength(0)
    expect(api.completions).toEqual(['half-done'])
  })

  test('refuses to resume with a different file', async ({ page }) => {
    api.uploads = [
      fakeUpload({ id: 'half-done', name: 'interview.wav', size: 8044, status: 'uploading', completed_at: null }),
    ]
    await page.goto('/')

    const chooser = page.waitForEvent('filechooser')
    await uploadsList(page).getByRole('button', { name: 'Resume' }).click()
    await (await chooser).setFiles({ name: 'other.wav', mimeType: 'audio/wav', buffer: wavBuffer(1) })

    await expect(uploadsList(page).getByText('That is a different file. Choose "interview.wav" (7.9 KB) to resume.')).toBeVisible()
    expect(api.chunkIndexes).toHaveLength(0)
  })

  test('asks before leaving the page while an upload is running', async ({ page }) => {
    api.onChunk = () => new Promise(() => {})
    await page.goto('/')
    await chooseWav(page)
    await page.getByRole('button', { name: 'Generate subtitles' }).click()
    await expect(uploadsList(page).getByText(/Uploading/)).toBeVisible()

    const dialog = page.waitForEvent('dialog')
    await page.close({ runBeforeUnload: true })

    expect((await dialog).type()).toBe('beforeunload')
    await (await dialog).dismiss()
  })
})
