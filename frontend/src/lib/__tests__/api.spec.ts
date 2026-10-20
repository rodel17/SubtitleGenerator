import { describe, it, expect } from 'vitest'
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios'

import { api, errorMessage } from '../api'

function responseError(status: number, data: unknown): AxiosError {
  const response = {
    status,
    statusText: '',
    data,
    headers: {},
    config: { headers: new AxiosHeaders() },
  } as AxiosResponse

  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_REQUEST', undefined, undefined, response)
}

describe('api', () => {
  it('asks the API for JSON responses', () => {
    expect(api.defaults.headers.Accept).toBe('application/json')
  })
})

describe('errorMessage', () => {
  it('returns the first validation error from a 422 response', () => {
    const error = responseError(422, {
      message: 'The size field must be at least 1. (and 1 more error)',
      errors: {
        size: ['The size field must be at least 1.'],
        name: ['The name field is required.'],
      },
    })

    expect(errorMessage(error)).toBe('The size field must be at least 1.')
  })

  it('falls back to the response message when there are no validation errors', () => {
    const error = responseError(409, { message: 'This upload is already complete.' })

    expect(errorMessage(error)).toBe('This upload is already complete.')
  })

  it('falls back to the axios message when the response has no body', () => {
    const error = responseError(500, '')

    expect(errorMessage(error)).toBe('Request failed with status code 500')
  })

  it('returns the network error message when there is no response', () => {
    const error = new AxiosError('Network Error', 'ERR_NETWORK')

    expect(errorMessage(error)).toBe('Network Error')
  })

  it('returns the message of a plain Error', () => {
    expect(errorMessage(new Error('Disk full'))).toBe('Disk full')
  })

  it('returns the fallback for values that are not errors', () => {
    expect(errorMessage('nope')).toBe('Something went wrong.')
    expect(errorMessage(undefined, 'Upload failed.')).toBe('Upload failed.')
  })
})
