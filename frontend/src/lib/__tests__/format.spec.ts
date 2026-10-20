import { describe, it, expect } from 'vitest'

import { formatBytes, formatDuration } from '../format'

describe('formatBytes', () => {
  it.each([
    [0, '0 B'],
    [1, '1 B'],
    [1023, '1023 B'],
    [1024, '1.0 KB'],
    [1536, '1.5 KB'],
    [10 * 1024, '10 KB'],
    [5 * 1024 * 1024, '5.0 MB'],
    [1.2 * 1024 * 1024, '1.2 MB'],
    [2 * 1024 ** 3, '2.0 GB'],
    [3 * 1024 ** 4, '3.0 TB'],
  ])('formats %d bytes as "%s"', (bytes, expected) => {
    expect(formatBytes(bytes)).toBe(expected)
  })

  it('stays in TB for sizes beyond the largest unit', () => {
    expect(formatBytes(2048 * 1024 ** 4)).toBe('2048 TB')
  })
})

describe('formatDuration', () => {
  it.each([
    [0, '0:00'],
    [5, '0:05'],
    [59.9, '0:59'],
    [60, '1:00'],
    [61, '1:01'],
    [599, '9:59'],
    [3599, '59:59'],
    [3600, '1:00:00'],
    [3725, '1:02:05'],
    [36000, '10:00:00'],
  ])('formats %d seconds as "%s"', (seconds, expected) => {
    expect(formatDuration(seconds)).toBe(expected)
  })
})
