import axios, { isAxiosError } from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  headers: { Accept: 'application/json' },
})

const TOKEN_KEY = 'auth_token'

/** The Sanctum token from Google sign-in, or null when signed out (or storage is blocked). */
export function getAuthToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setAuthToken(token: string | null): void {
  try {
    if (token) {
      localStorage.setItem(TOKEN_KEY, token)
    } else {
      localStorage.removeItem(TOKEN_KEY)
    }
  } catch {
    // Storage blocked (e.g. private mode): the user just has to sign in again next time
  }
}

api.interceptors.request.use((config) => {
  const token = getAuthToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

/**
 * Where the browser goes to start Google sign-in. It's a Laravel web route,
 * not under /api, so it's resolved against the API's origin.
 */
export function googleSignInUrl(): string {
  const apiUrl = new URL(import.meta.env.VITE_API_URL ?? '/api', window.location.href)

  return new URL('/auth/google/redirect', apiUrl).href
}

/** Pull a human-readable message out of an API or network error. */
export function errorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  if (isAxiosError(error)) {
    const data = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined
    const firstValidationError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined

    return firstValidationError ?? data?.message ?? error.message ?? fallback
  }

  return error instanceof Error ? error.message : fallback
}
