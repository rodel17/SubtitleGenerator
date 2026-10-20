import type { Page, Request, Route } from '@playwright/test'

export interface FakeUpload {
  id: string
  name: string
  extension: string
  mime_type: string | null
  size: number
  chunk_size: number
  total_chunks: number
  language: string | null
  subtitle_format: 'srt' | 'vtt' | 'both'
  status: 'uploading' | 'uploaded'
  created_at: string
  completed_at: string | null
}

export function fakeUpload(overrides: Partial<FakeUpload> = {}): FakeUpload {
  return {
    id: 'upload-existing',
    name: 'product-demo.mov',
    extension: 'mov',
    mime_type: 'video/quicktime',
    size: 1.2 * 1024 * 1024,
    chunk_size: 5 * 1024 * 1024,
    total_chunks: 1,
    language: 'en',
    subtitle_format: 'srt',
    status: 'uploaded',
    created_at: '2026-10-20T12:00:00.000000Z',
    completed_at: '2026-10-20T12:01:00.000000Z',
    ...overrides,
  }
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
  completions: string[] = []
  deletions: string[] = []

  /** Chunk size the fake server hands out, small so tests need several chunks */
  chunkSize = 4096

  onList?: Handler
  onStart?: Handler
  onChunk?: Handler
  onComplete?: Handler

  constructor(private readonly page: Page) {}

  async install() {
    await this.page.route(/\/api\/uploads(\/.*)?$/, (route, request) => this.handle(route, request))
  }

  private async handle(route: Route, request: Request) {
    const path = new URL(request.url()).pathname.replace(/^.*\/api/, '')
    const method = request.method()

    if (method === 'GET' && path === '/uploads') {
      return this.onList ? this.onList(route, request) : this.json(route, 200, { data: this.uploads })
    }

    if (method === 'POST' && path === '/uploads') {
      const body = request.postDataJSON() as { name: string; size: number; language: string; subtitle_format: FakeUpload['subtitle_format'] }
      this.startRequests.push(body)
      if (this.onStart) return this.onStart(route, request)

      const upload = fakeUpload({
        id: `upload-${this.startRequests.length}`,
        name: body.name,
        extension: body.name.split('.').pop()!.toLowerCase(),
        mime_type: null,
        size: body.size,
        chunk_size: this.chunkSize,
        total_chunks: Math.ceil(body.size / this.chunkSize),
        language: body.language,
        subtitle_format: body.subtitle_format,
        status: 'uploading',
        completed_at: null,
      })
      this.uploads.unshift(upload)
      return this.json(route, 201, { data: upload })
    }

    const chunk = path.match(/^\/uploads\/([^/]+)\/chunks$/)
    if (method === 'POST' && chunk) {
      if (this.onChunk) return this.onChunk(route, request)
      this.chunkIndexes.push(chunkIndexOf(request))
      return route.fulfill({ status: 204 })
    }

    const completion = path.match(/^\/uploads\/([^/]+)\/completion$/)
    if (method === 'POST' && completion) {
      this.completions.push(completion[1]!)
      if (this.onComplete) return this.onComplete(route, request)
      const upload = this.uploads.find((candidate) => candidate.id === completion[1])!
      Object.assign(upload, { status: 'uploaded', completed_at: '2026-10-20T12:05:00.000000Z' })
      return this.json(route, 200, { data: upload })
    }

    const single = path.match(/^\/uploads\/([^/]+)$/)
    if (method === 'DELETE' && single) {
      this.deletions.push(single[1]!)
      this.uploads = this.uploads.filter((candidate) => candidate.id !== single[1])
      return route.fulfill({ status: 204 })
    }

    return route.fulfill({ status: 404 })
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
