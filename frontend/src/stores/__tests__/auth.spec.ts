import { beforeEach, describe, it, expect, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError, AxiosHeaders, type AxiosInstance, type AxiosResponse } from 'axios'

import { api, getAuthToken } from '@/lib/api'
import { reloadTo } from '@/lib/navigation'
import type { User } from '@/types/auth'
import { SESSION_ENDED_MESSAGE, useAuthStore } from '../auth'

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { get: vi.fn<AxiosInstance['get']>(), post: vi.fn<AxiosInstance['post']>() },
}))
vi.mock('@/lib/navigation', () => ({ reloadTo: vi.fn<typeof reloadTo>() }))

const get = vi.mocked(api.get)
const post = vi.mocked(api.post)
const reload = vi.mocked(reloadTo)

const ada: User = { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', avatar: null }

function responseError(status: number): AxiosError {
  const response = { status, statusText: '', data: {}, headers: {}, config: { headers: new AxiosHeaders() } } as AxiosResponse
  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_REQUEST', undefined, undefined, response)
}

beforeEach(() => {
  localStorage.clear()
  vi.clearAllMocks()
  setActivePinia(createPinia())
})

describe('auth store', () => {
  it('starts signed in when a token was kept from an earlier visit', () => {
    localStorage.setItem('auth_token', 'earlier-token')

    expect(useAuthStore().isSignedIn).toBe(true)
  })

  it('keeps the token from Google sign-in for the next visit', () => {
    const auth = useAuthStore()

    auth.signIn('1|abc')

    expect(auth.isSignedIn).toBe(true)
    expect(getAuthToken()).toBe('1|abc')
  })

  it('loads the signed-in user', async () => {
    get.mockResolvedValue({ data: { data: ada } })
    const auth = useAuthStore()

    await auth.loadUser()

    expect(get).toHaveBeenCalledWith('/user')
    expect(auth.user).toEqual(ada)
  })

  it('revokes the token on the server when signing out', async () => {
    post.mockResolvedValue({ status: 204 })
    const auth = useAuthStore()
    auth.signIn('1|abc')
    auth.user = ada

    await auth.signOut()

    expect(post).toHaveBeenCalledWith('/logout')
    expect(auth.isSignedIn).toBe(false)
    expect(auth.user).toBeNull()
    expect(getAuthToken()).toBeNull()
  })

  it('still signs out locally when the server cannot be reached', async () => {
    post.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))
    const auth = useAuthStore()
    auth.signIn('1|abc')

    await auth.signOut()

    expect(auth.isSignedIn).toBe(false)
    expect(getAuthToken()).toBeNull()
  })

  describe('handleApiError', () => {
    it('sends the user back to sign-in when the API rejects their token', () => {
      const auth = useAuthStore()
      auth.signIn('expired')

      auth.handleApiError(responseError(401))

      expect(auth.isSignedIn).toBe(false)
      expect(reload).toHaveBeenCalledWith('/login?error=' + encodeURIComponent(SESSION_ENDED_MESSAGE))
    })

    it('ignores other errors', () => {
      const auth = useAuthStore()
      auth.signIn('1|abc')

      auth.handleApiError(responseError(404))
      auth.handleApiError(responseError(500))
      auth.handleApiError(new AxiosError('Network Error', 'ERR_NETWORK'))

      expect(auth.isSignedIn).toBe(true)
      expect(reload).not.toHaveBeenCalled()
    })

    it('does nothing for a 401 when already signed out', () => {
      useAuthStore().handleApiError(responseError(401))

      expect(reload).not.toHaveBeenCalled()
    })
  })
})
