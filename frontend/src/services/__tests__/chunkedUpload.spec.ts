import { describe, it, expect } from 'vitest'

import { chunkRanges } from '../chunkedUpload'

describe('chunkRanges', () => {
  it('covers every byte exactly once, with a shorter last chunk', () => {
    expect(chunkRanges(25, 10)).toEqual([
      { index: 0, start: 0, end: 10 },
      { index: 1, start: 10, end: 20 },
      { index: 2, start: 20, end: 25 },
    ])
  })

  it('returns a single chunk for files smaller than the chunk size', () => {
    expect(chunkRanges(3, 10)).toEqual([{ index: 0, start: 0, end: 3 }])
  })

  it('does not add an empty chunk when the size divides evenly', () => {
    expect(chunkRanges(20, 10)).toHaveLength(2)
  })
})
