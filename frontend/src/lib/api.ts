import axios, { isAxiosError } from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  headers: { Accept: 'application/json' },
})

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
