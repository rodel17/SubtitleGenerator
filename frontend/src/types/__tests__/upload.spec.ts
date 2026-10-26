import { describe, it, expect } from 'vitest'

import {
  fileExtension,
  isAcceptedFile,
  languageLabel,
  mediaKindOf,
  subtitleFileName,
  TRANSLATION_TARGETS,
  translationLabel,
} from '../upload'

describe('fileExtension', () => {
  it.each([
    ['clip.mp4', 'mp4'],
    ['CLIP.MP4', 'mp4'],
    ['my.holiday.video.mov', 'mov'],
    ['archive.mp4.zip', 'zip'],
    ['trailing.', ''],
  ])('returns the lowercase last extension of "%s"', (name, expected) => {
    expect(fileExtension(name)).toBe(expected)
  })

  it('returns an empty string when there is no dot', () => {
    expect(fileExtension('mp4')).toBe('')
  })
})

describe('isAcceptedFile', () => {
  it.each(['clip.mp4', 'clip.mov', 'clip.mkv', 'clip.ts', 'clip.mp3', 'clip.wav', 'clip.m4a', 'CLIP.MP4'])(
    'accepts %s',
    (name) => {
      expect(isAcceptedFile(new File([''], name))).toBe(true)
    },
  )

  it.each(['notes.txt', 'screen.webm', 'clip.mp4.zip', 'mp4', 'video'])('rejects %s', (name) => {
    expect(isAcceptedFile(new File([''], name))).toBe(false)
  })

  it('ignores the browser-reported MIME type and goes by extension', () => {
    expect(isAcceptedFile(new File([''], 'clip.mp4', { type: 'text/plain' }))).toBe(true)
  })
})

describe('mediaKindOf', () => {
  it.each(['song.mp3', 'voice.wav', 'memo.m4a', 'LOUD.MP3'])('treats %s as audio', (name) => {
    expect(mediaKindOf(name)).toBe('audio')
  })

  it.each(['clip.mp4', 'clip.mov', 'clip.mkv', 'broadcast.ts', 'unknown.xyz'])(
    'treats %s as video',
    (name) => {
      expect(mediaKindOf(name)).toBe('video')
    },
  )
})

describe('languageLabel', () => {
  it.each([
    ['auto', 'Auto-detect'],
    ['en', 'English'],
    ['fil', 'Filipino'],
    ['es', 'Spanish'],
    ['ja', 'Japanese'],
  ])('labels %s as %s', (code, label) => {
    expect(languageLabel(code)).toBe(label)
  })

  it('returns an unknown code unchanged', () => {
    expect(languageLabel('de')).toBe('de')
  })

  it('returns undefined when no language is set', () => {
    expect(languageLabel(undefined)).toBeUndefined()
  })
})

describe('subtitleFileName', () => {
  it.each([
    ['Team Interview.mp4', 'srt', 'Team Interview.srt'],
    ['clip.final.mov', 'vtt', 'clip.final.vtt'],
    ['no-extension', 'srt', 'no-extension.srt'],
    ['.hidden', 'vtt', '.hidden.vtt'],
    ['Team Interview.mp4', 'both', 'Team Interview.zip'],
  ] as const)('names the %s subtitles %s -> %s', (media, format, expected) => {
    expect(subtitleFileName(media, format)).toBe(expected)
  })
})

describe('translations', () => {
  it('names translated subtitles with the language', () => {
    expect(subtitleFileName('Team Interview.mp4', 'srt', 'fil')).toBe('Team Interview.fil.srt')
    expect(subtitleFileName('Team Interview.mp4', 'both', 'ja')).toBe('Team Interview.ja.zip')
  })

  it('offers Filipino, English, Japanese, Simplified Chinese and Korean', () => {
    expect(TRANSLATION_TARGETS.map((target) => target.value)).toEqual(['fil', 'en', 'ja', 'zh', 'ko'])
    expect(translationLabel('zh')).toBe('Chinese (Simplified)')
    expect(translationLabel('xx')).toBe('xx')
  })
})
