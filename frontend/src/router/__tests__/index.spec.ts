import { beforeEach, describe, it, expect, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError, AxiosHeaders, type AxiosInstance, type AxiosResponse } from 'axios'

import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import router from '../index'

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { get: vi.fn<AxiosInstance['get']>() },
}))

const get = vi.mocked(api.get)

function responseError(status: number): AxiosError {
  const response = { status, statusText: '', data: {}, headers: {}, config: { headers: new AxiosHeaders() } } as AxiosResponse
  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_REQUEST', undefined, undefined, response)
}

beforeEach(async () => {
  localStorage.clear()
  vi.clearAllMocks()
  setActivePinia(createPinia())
  // Start every test from a public page so each push runs the guard
  await router.replace('/auth/callback')
})

describe('router guard', () => {
  it('sends a signed-out visitor to the sign-in page', async () => {
    await router.push('/')

    expect(router.currentRoute.value.name).toBe('login')
  })

  it('loads the user once, then lets a signed-in user in', async () => {
    useAuthStore().signIn('1|abc')
    get.mockResolvedValue({ data: { data: { id: 1, name: 'Ada', email: 'ada@example.com', avatar: null } } })

    await router.push('/')
    await router.push('/about')

    expect(router.currentRoute.value.name).toBe('about')
    expect(useAuthStore().user?.name).toBe('Ada')
    expect(get).toHaveBeenCalledTimes(1)
  })

  it('signs out and sends to sign-in when the stored token is rejected', async () => {
    useAuthStore().signIn('expired')
    get.mockRejectedValue(responseError(401))

    await router.push('/')

    expect(router.currentRoute.value.name).toBe('login')
    expect(useAuthStore().isSignedIn).toBe(false)
  })

  it('keeps the user signed in when the server is unreachable', async () => {
    useAuthStore().signIn('1|abc')
    get.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))

    await router.push('/')

    expect(router.currentRoute.value.name).toBe('upload')
    expect(useAuthStore().isSignedIn).toBe(true)
  })

  it('skips the sign-in page for a signed-in user', async () => {
    useAuthStore().signIn('1|abc')
    get.mockResolvedValue({ data: { data: { id: 1, name: 'Ada', email: 'ada@example.com', avatar: null } } })

    await router.push('/login')

    expect(router.currentRoute.value.name).toBe('upload')
  })
})
