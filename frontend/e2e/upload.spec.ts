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

function readyDialog(page: Page) {
  return page.getByRole('dialog', { name: 'Your subtitles are ready' })
}

test.describe('in progress list', () => {
  test('lists uploads still being processed or interrupted, with no history', async ({ page }) => {
    api.uploads = [
      fakeUpload({ id: 'a', name: 'product-demo.mov', status: 'transcribing', progress: 40 }),
      fakeUpload({ id: 'b', name: 'half-done.mp4', status: 'uploading', completed_at: null }),
    ]

    await page.goto('/')

    await expect(page.getByRole('heading', { name: 'In progress' })).toBeVisible()
    await expect(uploadsList(page).getByText('Transcribing · 40%')).toBeVisible()
    await expect(uploadsList(page).getByText('Upload was interrupted. Resume to continue where it stopped.')).toBeVisible()
    await expect(page.getByText('View all')).toHaveCount(0)
    await expect(page.getByText('My files')).toHaveCount(0)
  })

  test('shows the empty state when nothing is in progress', async ({ page }) => {
    await page.goto('/')

    await expect(page.getByText('Nothing in progress.')).toBeVisible()
  })

  test('shows an error when the API is unavailable', async ({ page }) => {
    api.onList = (route) => route.fulfill({ status: 500, body: '' })

    await page.goto('/')

    await expect(page.getByText('Request failed with status code 500')).toBeVisible()
  })

  test('deletes an interrupted upload after confirming, showing progress on the button', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'stopped', name: 'interview.mp4', status: 'uploading', completed_at: null })]
    let finishDelete!: () => void
    const deleted = new Promise<void>((resolve) => (finishDelete = resolve))
    await page.route(/\/api\/uploads\/stopped$/, async (route) => {
      if (route.request().method() !== 'DELETE') return route.fallback()
      await deleted
      api.deletions.push('stopped')
      return route.fulfill({ status: 204 })
    })
    await page.goto('/')

    await uploadsList(page).getByRole('button', { name: 'Remove' }).click()
    const dialog = page.getByRole('alertdialog', { name: 'Delete this upload?' })
    await expect(dialog).toContainText(`"interview.mp4" and everything uploaded so far will be deleted.`)
    await dialog.getByRole('button', { name: 'Delete' }).click()

    await expect(dialog.getByRole('button', { name: 'Deleting…' })).toBeDisabled()
    await expect(uploadsList(page).getByText('interview.mp4')).toBeVisible()
    finishDelete()

    await expect(page.getByText('Nothing in progress.')).toBeVisible()
    await expect(dialog).toBeHidden()
    expect(api.deletions).toEqual(['stopped'])
  })

  test('keeps the upload when the confirmation is cancelled', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'kept', status: 'uploading', completed_at: null })]
    await page.goto('/')

    await uploadsList(page).getByRole('button', { name: 'Remove' }).click()
    await page.getByRole('alertdialog').getByRole('button', { name: 'Cancel' }).click()

    await expect(page.getByRole('alertdialog')).toBeHidden()
    await expect(uploadsList(page).getByText('product-demo.mov')).toBeVisible()
    expect(api.deletions).toEqual([])
  })

  test('stops and deletes an upload that is stuck in transcription', async ({ page }) => {
    const stuck = fakeUpload({ id: 'stuck', name: 'stuck.mp4', status: 'transcribing', progress: 95 })
    api.uploads = [stuck]
    // Polling never moves it on
    await page.route(/\/api\/uploads\/stuck$/, (route) =>
      route.request().method() === 'GET' ? api.json(route, 200, { data: stuck }) : route.fallback(),
    )
    await page.goto('/')

    await uploadsList(page).getByRole('button', { name: 'Stop and delete' }).click()
    await page.getByRole('alertdialog', { name: 'Stop processing?' }).getByRole('button', { name: 'Stop and delete' }).click()

    await expect(page.getByText('Nothing in progress.')).toBeVisible()
    expect(api.deletions).toEqual(['stuck'])
  })

  test('removes a failed upload from the list and the server after confirming', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'gone', status: 'failed', error: 'No speech was detected in this file.' })]
    await page.goto('/')

    await uploadsList(page).getByRole('button', { name: 'Remove' }).click()
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click()

    await expect(page.getByText('Nothing in progress.')).toBeVisible()
    expect(api.deletions).toEqual(['gone'])
  })
})

test.describe('ready subtitles', () => {
  test('asks to download them in a dialog that cannot be dismissed', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'done', name: 'product-demo.mov', language: 'ja' })]
    await page.goto('/')

    await expect(readyDialog(page)).toContainText('product-demo.mov · Japanese · 1.2 MB')
    await page.keyboard.press('Escape')
    await page.mouse.click(5, 5)

    await expect(readyDialog(page)).toBeVisible()
    await expect(page.getByText('Nothing in progress.')).toBeVisible()
  })

  test('downloads the file under the media name, then deletes it from the server', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'done', name: 'Team Interview.mov' })]
    await page.goto('/')

    const download = page.waitForEvent('download')
    await readyDialog(page).getByRole('button', { name: 'Download SRT' }).click()

    expect((await download).suggestedFilename()).toBe('Team Interview.srt')
    await expect(readyDialog(page)).toBeHidden()
    expect(api.deletions).toEqual(['done'])
  })

  test('downloads both formats as one zip', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'done', name: 'Team Interview.mov', subtitle_format: 'both' })]
    await page.goto('/')

    const download = page.waitForEvent('download')
    await readyDialog(page).getByRole('button', { name: 'Download SRT + VTT (.zip)' }).click()

    expect((await download).suggestedFilename()).toBe('Team Interview.zip')
    await expect.poll(() => api.deletions).toEqual(['done'])
  })

  test('shows a spinner on the button while the file downloads', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'done' })]
    let release!: () => void
    const held = new Promise<void>((resolve) => (release = resolve))
    api.onSubtitle = async (route) => {
      await held
      return route.fulfill({ status: 200, contentType: 'text/plain', body: '1\n00:00:00,000 --> 00:00:01,000\nHi\n' })
    }
    await page.goto('/')

    await readyDialog(page).getByRole('button', { name: 'Download SRT' }).click()

    const busy = readyDialog(page).getByRole('button', { name: 'Downloading…' })
    await expect(busy).toBeDisabled()
    await expect(busy.locator('[data-test="spinner"]')).toBeVisible()
    const download = page.waitForEvent('download')
    release()
    expect((await download).suggestedFilename()).toBe('product-demo.srt')
    await expect(readyDialog(page)).toBeHidden()
  })

  test('keeps the subtitles and says so when the download fails', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'done' })]
    api.onSubtitle = (route) => route.fulfill({ status: 500 })
    await page.goto('/')

    await readyDialog(page).getByRole('button', { name: 'Download SRT' }).click()

    await expect(readyDialog(page).getByRole('alert')).toHaveText('The subtitles could not be downloaded. Please try again.')
    expect(api.deletions).toEqual([])
  })

  test('discards the subtitles without saving after a second confirmation', async ({ page }) => {
    api.uploads = [fakeUpload({ id: 'unwanted' })]
    await page.goto('/')

    await readyDialog(page).getByRole('button', { name: 'Discard without saving' }).click()
    await readyDialog(page).getByRole('button', { name: 'Discard', exact: true }).click()

    await expect(readyDialog(page)).toBeHidden()
    expect(api.deletions).toEqual(['unwanted'])
  })

  test('shows the next ready upload once the first is downloaded', async ({ page }) => {
    api.uploads = [
      fakeUpload({ id: 'new', name: 'second.mp4', expires_at: '2026-10-20T18:30:00.000000Z' }),
      fakeUpload({ id: 'old', name: 'first.mp4', expires_at: '2026-10-20T18:10:00.000000Z' }),
    ]
    await page.goto('/')

    await expect(readyDialog(page)).toContainText('first.mp4')
    await readyDialog(page).getByRole('button', { name: 'Download SRT' }).click()

    await expect(readyDialog(page)).toContainText('second.mp4')
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
    await expect(readyDialog(page)).toContainText('interview.wav · Japanese · 7.9 KB')
    const download = page.waitForEvent('download')
    await readyDialog(page).getByRole('button', { name: 'Download VTT' }).click()
    expect((await download).suggestedFilename()).toBe('interview.vtt')
    await expect.poll(() => api.deletions).toEqual(['upload-1'])
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

    await expect(readyDialog(page)).toBeVisible({ timeout: 10_000 })
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
    await page.getByRole('alertdialog', { name: 'Stop this upload?' }).getByRole('button', { name: 'Stop upload' }).click()

    await expect(page.getByText('Nothing in progress.')).toBeVisible()
    await expect.poll(() => api.deletions).toEqual(['upload-1'])
  })

  test('uploads several files one after another', async ({ page }) => {
    await page.goto('/')
    await fileInput(page).setInputFiles([
      { name: 'first.wav', mimeType: 'audio/wav', buffer: wavBuffer(1) },
      { name: 'second.wav', mimeType: 'audio/wav', buffer: wavBuffer(1) },
    ])

    await page.getByRole('button', { name: 'Generate subtitles' }).click()

    await expect(readyDialog(page)).toContainText('first.wav', { timeout: 10_000 })
    await readyDialog(page).getByRole('button', { name: 'Download SRT' }).click()
    await expect(readyDialog(page)).toContainText('second.wav', { timeout: 10_000 })
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

    await expect(readyDialog(page)).toBeVisible({ timeout: 10_000 })
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
