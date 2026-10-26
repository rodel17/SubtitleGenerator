import type { Page, Request, Route } from '@playwright/test'

export interface FakeUpload {
  id: string
  name: string
  extension: string
  mime_type: string | null
  last_modified: number | null
  size: number
  chunk_size: number
  total_chunks: number
  language: string | null
  subtitle_format: 'srt' | 'vtt' | 'both'
  email_when_ready: boolean
  status: 'uploading' | 'assembling' | 'uploaded' | 'transcribing' | 'ready' | 'failed'
  progress: number | null
  error: string | null
  email_status: 'sending' | 'failed' | null
  email_error: string | null
  download_url?: string
  created_at: string
  completed_at: string | null
  expires_at: string | null
}

const downloadUrl = (id: string) => `http://localhost:8000/api/uploads/${id}/subtitles`

export function fakeUpload(overrides: Partial<FakeUpload> = {}): FakeUpload {
  const upload: FakeUpload = {
    id: 'upload-existing',
    name: 'product-demo.mov',
    extension: 'mov',
    mime_type: 'video/quicktime',
    last_modified: null,
    size: 1.2 * 1024 * 1024,
    chunk_size: 5 * 1024 * 1024,
    total_chunks: 1,
    language: 'en',
    subtitle_format: 'srt',
    email_when_ready: false,
    status: 'ready',
    progress: null,
    error: null,
    email_status: null,
    email_error: null,
    created_at: '2026-10-20T12:00:00.000000Z',
    completed_at: '2026-10-20T12:01:00.000000Z',
    expires_at: null,
    ...overrides,
  }

  if (upload.status === 'ready' && !upload.download_url) {
    upload.download_url = downloadUrl(upload.id)
  }

  return upload
}

type Handler = (route: Route, request: Request) => Promise<void> | void

/**
 * An in-memory stand-in for the Laravel uploads API, so browser tests never touch
 * the real database or storage. Override any endpoint to simulate failures.
 */
export class FakeApi {
  uploads: FakeUpload[] = []
  startRequests: Record<string, unknown>[] = []
  chunkIndexes: number[] = []
  /** Chunk indexes stored per upload id, as GET /uploads/{id}/chunks reports them */
  receivedChunks: Record<string, number[]> = {}
  completions: string[] = []
  deletions: string[] = []
  /** Uploads whose subtitles the fake server "emailed" (and then deleted, like the real one) */
  emails: string[] = []

  /** Chunk size the fake server hands out, small so tests need several chunks */
  chunkSize = 4096

  onList?: Handler
  onStart?: Handler
  onChunk?: Handler
  onComplete?: Handler
  onSubtitle?: Handler
  onEmail?: Handler

  constructor(private readonly page: Page) {}

  /** Signs the browser in as a fake user, then fakes the uploads API */
  async install() {
    await this.page.addInitScript(() => localStorage.setItem('auth_token', 'e2e-token'))
    await this.page.route(/\/api\/user$/, (route) =>
      this.json(route, 200, { data: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', avatar: null } }),
    )
    await this.page.route(/\/api\/uploads(\/.*)?$/, (route, request) => this.handle(route, request))
  }

  private async handle(route: Route, request: Request) {
    const path = new URL(request.url()).pathname.replace(/^.*\/api/, '')
    const method = request.method()

    if (method === 'GET' && path === '/uploads') {
      return this.onList ? this.onList(route, request) : this.json(route, 200, { data: this.uploads })
    }

    if (method === 'POST' && path === '/uploads') {
      const body = request.postDataJSON() as {
        name: string
        size: number
        last_modified: number | null
        language: string
        subtitle_format: FakeUpload['subtitle_format']
        email_when_ready: boolean
      }
      this.startRequests.push(body)
      if (this.onStart) return this.onStart(route, request)

      const upload = fakeUpload({
        id: `upload-${this.startRequests.length}`,
        name: body.name,
        extension: body.name.split('.').pop()!.toLowerCase(),
        mime_type: null,
        last_modified: body.last_modified,
        size: body.size,
        chunk_size: this.chunkSize,
        total_chunks: Math.ceil(body.size / this.chunkSize),
        language: body.language,
        subtitle_format: body.subtitle_format,
        email_when_ready: body.email_when_ready,
        status: 'uploading',
        completed_at: null,
      })
      this.uploads.unshift(upload)
      return this.json(route, 201, { data: upload })
    }

    const chunk = path.match(/^\/uploads\/([^/]+)\/chunks$/)
    if (method === 'GET' && chunk) {
      const upload = this.uploads.find((candidate) => candidate.id === chunk[1])!
      return this.json(route, 200, {
        data: {
          chunk_size: upload.chunk_size,
          total_chunks: upload.total_chunks,
          received: (this.receivedChunks[upload.id] ?? []).toSorted((a, b) => a - b),
        },
      })
    }

    if (method === 'POST' && chunk) {
      if (this.onChunk) return this.onChunk(route, request)
      const index = chunkIndexOf(request)
      this.chunkIndexes.push(index)
      this.receivedChunks[chunk[1]!] = [...new Set([...(this.receivedChunks[chunk[1]!] ?? []), index])]
      return route.fulfill({ status: 204 })
    }

    const completion = path.match(/^\/uploads\/([^/]+)\/completion$/)
    if (method === 'POST' && completion) {
      this.completions.push(completion[1]!)
      if (this.onComplete) return this.onComplete(route, request)
      const upload = this.uploads.find((candidate) => candidate.id === completion[1])!
      Object.assign(upload, { status: 'assembling', progress: 0 })
      return this.json(route, 202, { data: upload })
    }

    const subtitle = path.match(/^\/uploads\/([^/]+)\/subtitles$/)
    if (method === 'GET' && subtitle) {
      if (this.onSubtitle) return this.onSubtitle(route, request)
      const upload = this.uploads.find((candidate) => candidate.id === subtitle[1])
      if (!upload) return route.fulfill({ status: 404 })
      const body = {
        srt: '1\n00:00:00,000 --> 00:00:01,000\nHello\n',
        vtt: 'WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello\n',
        both: 'PK (a zip of both files)',
      }[upload.subtitle_format]
      return route.fulfill({ status: 200, contentType: 'application/octet-stream', body })
    }

    const email = path.match(/^\/uploads\/([^/]+)\/email$/)
    if (method === 'POST' && email) {
      if (this.onEmail) return this.onEmail(route, request)
      const upload = this.uploads.find((candidate) => candidate.id === email[1])!
      Object.assign(upload, { email_status: 'sending', email_error: null })
      return this.json(route, 202, { data: upload })
    }

    const single = path.match(/^\/uploads\/([^/]+)$/)
    if (method === 'GET' && single) {
      const upload = this.uploads.find((candidate) => candidate.id === single[1])
      if (!upload) return route.fulfill({ status: 404 })
      if (upload.status === 'ready' && upload.email_status === 'sending') {
        // "Sent": like the real server, the upload is deleted once its email is out
        this.emails.push(upload.id)
        this.uploads = this.uploads.filter((candidate) => candidate !== upload)
        return route.fulfill({ status: 404 })
      }
      this.advance(upload)
      return this.json(route, 200, { data: upload })
    }

    if (method === 'DELETE' && single) {
      this.deletions.push(single[1]!)
      this.uploads = this.uploads.filter((candidate) => candidate.id !== single[1])
      return route.fulfill({ status: 204 })
    }

    return route.fulfill({ status: 404 })
  }

  /** Each time the app polls, move processing one step on: assembling → transcribing 50% → ready. */
  private advance(upload: FakeUpload) {
    if (upload.status === 'assembling' || upload.status === 'uploaded') {
      Object.assign(upload, { status: 'transcribing', progress: 50, completed_at: '2026-10-20T12:05:00.000000Z' })
    } else if (upload.status === 'transcribing') {
      Object.assign(upload, {
        status: 'ready',
        progress: null,
        download_url: downloadUrl(upload.id),
        expires_at: '2026-10-20T18:05:00.000000Z',
        email_status: upload.email_when_ready ? 'sending' : null,
      })
    }
  }

  json(route: Route, status: number, body: unknown) {
    return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  }
}

/** Read the `index` field out of a multipart chunk request. */
export function chunkIndexOf(request: Request): number {
  const body = request.postDataBuffer()?.toString('latin1') ?? ''
  const match = body.match(/name="index"\r\n\r\n(\d+)/)
  return Number(match?.[1])
}

/** A valid PCM WAV file, so the browser can play it and read its duration. */
export function wavBuffer(seconds: number, sampleRate = 8000): Buffer {
  const samples = seconds * sampleRate
  const header = Buffer.alloc(44)
  header.write('RIFF', 0)
  header.writeUInt32LE(36 + samples, 4)
  header.write('WAVE', 8)
  header.write('fmt ', 12)
  header.writeUInt32LE(16, 16)
  header.writeUInt16LE(1, 20) // PCM
  header.writeUInt16LE(1, 22) // mono
  header.writeUInt32LE(sampleRate, 24)
  header.writeUInt32LE(sampleRate, 28)
  header.writeUInt16LE(1, 32)
  header.writeUInt16LE(8, 34)
  header.write('data', 36)
  header.writeUInt32LE(samples, 40)

  return Buffer.concat([header, Buffer.alloc(samples, 0x80)])
}
