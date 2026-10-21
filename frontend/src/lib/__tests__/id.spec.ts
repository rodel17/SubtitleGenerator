import { afterEach, describe, expect, it, vi } from 'vitest'
import { localId } from '../id'

describe('localId', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('uses crypto.randomUUID when the page is a secure context', () => {
    vi.spyOn(crypto, 'randomUUID').mockReturnValue('11111111-2222-3333-4444-555555555555')

    expect(localId()).toBe('11111111-2222-3333-4444-555555555555')
  })

  it('falls back to random bytes over plain HTTP, where randomUUID is missing', () => {
    vi.stubGlobal('crypto', { getRandomValues: crypto.getRandomValues.bind(crypto) })

    const first = localId()
    const second = localId()

    expect(first).toMatch(/^[0-9a-f]{32}$/)
    expect(second).not.toBe(first)
  })
})
