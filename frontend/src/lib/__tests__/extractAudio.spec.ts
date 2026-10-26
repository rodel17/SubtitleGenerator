import { beforeEach, describe, it, expect, vi } from 'vitest'

import { audioFileName, extractAudio, NoAudioTrackError } from '../extractAudio'

type Listener = (event: { message?: string; progress?: number }) => void

/** Stands in for the FFmpeg worker; each test scripts what `exec` does */
const ffmpeg = vi.hoisted(() => ({
  listeners: {} as Record<string, Listener>,
  load: vi.fn<() => Promise<boolean>>(),
  createDir: vi.fn<() => Promise<boolean>>(),
  mount: vi.fn<(type: string, options: { files: File[] }, path: string) => Promise<boolean>>(),
  exec: vi.fn<(args: string[]) => Promise<number>>(),
  readFile: vi.fn<(path: string) => Promise<Uint8Array | string>>(),
  terminate: vi.fn<() => void>(),
}))

vi.mock('@ffmpeg/ffmpeg', () => ({
  FFFSType: { WORKERFS: 'WORKERFS' },
  FFmpeg: class {
    on(event: string, listener: Listener) {
      ffmpeg.listeners[event] = listener
    }
    load = ffmpeg.load
    createDir = ffmpeg.createDir
    mount = ffmpeg.mount
    exec = ffmpeg.exec
    readFile = ffmpeg.readFile
    terminate = ffmpeg.terminate
  },
}))

const video = () => new File(['video bytes'], 'Team Interview.mp4', { type: 'video/mp4', lastModified: 1_700_000_000_000 })

beforeEach(() => {
  ffmpeg.listeners = {}
  ffmpeg.load.mockReset().mockResolvedValue(true)
  ffmpeg.createDir.mockReset().mockResolvedValue(true)
  ffmpeg.mount.mockReset().mockResolvedValue(true)
  ffmpeg.exec.mockReset().mockResolvedValue(0)
  ffmpeg.readFile.mockReset().mockResolvedValue(new Uint8Array([1, 2, 3, 4]))
  ffmpeg.terminate.mockReset()
})

describe('audioFileName', () => {
  it.each([
    ['Team Interview.mp4', 'Team Interview.mka'],
    ['my.holiday.video.MOV', 'my.holiday.video.mka'],
    ['no-extension', 'no-extension.mka'],
    ['.hidden', '.hidden.mka'],
  ])('names the audio of %s %s', (name, expected) => {
    expect(audioFileName(name)).toBe(expected)
  })
})

describe('extractAudio', () => {
  it('copies the first audio track without re-encoding, reading the video in place', async () => {
    const source = video()

    await extractAudio(source)

    expect(ffmpeg.mount).toHaveBeenCalledWith('WORKERFS', { files: [source] }, '/input')
    expect(ffmpeg.exec).toHaveBeenCalledWith([
      '-i', '/input/Team Interview.mp4', '-map', '0:a:0', '-vn', '-c:a', 'copy', '/audio.mka',
    ])
  })

  it("returns the audio as a file named after the video, with the video's modification time", async () => {
    const audio = await extractAudio(video())

    expect(audio.name).toBe('Team Interview.mka')
    expect(audio.type).toBe('audio/x-matroska')
    expect(audio.size).toBe(4)
    expect(audio.lastModified).toBe(1_700_000_000_000)
  })

  it('reports progress as whole percentages, reaching 100 only when done', async () => {
    const onProgress = vi.fn<(percent: number) => void>()
    ffmpeg.exec.mockImplementation(async () => {
      ffmpeg.listeners.progress!({ progress: 0.426 })
      ffmpeg.listeners.progress!({ progress: 1 })
      ffmpeg.listeners.progress!({ progress: Number.NaN })
      return 0
    })

    await extractAudio(video(), { onProgress })

    expect(onProgress.mock.calls).toEqual([[42], [99], [100]])
  })

  it('says when the video has no audio track', async () => {
    ffmpeg.exec.mockImplementation(async () => {
      ffmpeg.listeners.log!({ message: 'Stream map \'0:a:0\' matches no streams.' })
      return 1
    })

    await expect(extractAudio(video())).rejects.toBeInstanceOf(NoAudioTrackError)
  })

  it('fails when FFmpeg cannot extract the audio', async () => {
    ffmpeg.exec.mockResolvedValue(1)

    await expect(extractAudio(video())).rejects.toThrow('FFmpeg could not extract the audio (exit code 1).')
  })

  it('passes on errors such as running out of memory', async () => {
    ffmpeg.exec.mockRejectedValue(new Error('RuntimeError: Aborted(OOM)'))

    await expect(extractAudio(video())).rejects.toThrow('Aborted(OOM)')
  })

  it('always shuts FFmpeg down afterwards, to free its memory', async () => {
    await extractAudio(video())
    ffmpeg.exec.mockRejectedValue(new Error('boom'))
    await extractAudio(video()).catch(() => undefined)

    expect(ffmpeg.terminate).toHaveBeenCalledTimes(2)
  })

  it('stops FFmpeg and rejects with an AbortError when cancelled', async () => {
    const controller = new AbortController()
    ffmpeg.exec.mockImplementation(() => new Promise((_resolve, reject) => {
      ffmpeg.terminate.mockImplementationOnce(() => reject(new Error('called FFmpeg.terminate()')))
    }))

    const extraction = extractAudio(video(), { signal: controller.signal })
    await vi.waitFor(() => expect(ffmpeg.exec).toHaveBeenCalled())
    controller.abort()

    await expect(extraction).rejects.toMatchObject({ name: 'AbortError' })
  })

  it('does not start when already cancelled', async () => {
    const controller = new AbortController()
    controller.abort()

    await expect(extractAudio(video(), { signal: controller.signal })).rejects.toMatchObject({ name: 'AbortError' })
    expect(ffmpeg.load).not.toHaveBeenCalled()
  })
})
