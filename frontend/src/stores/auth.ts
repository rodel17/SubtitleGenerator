import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { isAxiosError } from 'axios'
import { api, getAuthToken, setAuthToken } from '@/lib/api'
import { reloadTo } from '@/lib/navigation'
import type { User } from '@/types/auth'

export const SESSION_ENDED_MESSAGE = 'Your session has ended. Please sign in again.'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(getAuthToken())
  const user = ref<User | null>(null)

  const isSignedIn = computed(() => token.value !== null)

  /** Keep the token Google sign-in handed back (see AuthCallbackView). */
  function signIn(newToken: string) {
    setAuthToken(newToken)
    token.value = newToken
  }

  /** Drop the token locally, e.g. after the API answered 401. */
  function forget() {
    setAuthToken(null)
    token.value = null
    user.value = null
  }

  async function loadUser(): Promise<User> {
    const { data } = await api.get<{ data: User }>('/user')
    user.value = data.data
    return data.data
  }

  /** Revoke the token on the server; it's dropped locally even if that fails. */
  async function signOut() {
    try {
      await api.post('/logout')
    } catch {
      // Already revoked or the server is unreachable: signing out locally is what matters
    } finally {
      forget()
    }
  }

  /**
   * Called for every failed API request (see main.ts). A 401 while signed in
   * means the token expired or was revoked: back to the sign-in page.
   */
  function handleApiError(error: unknown) {
    if (isAxiosError(error) && error.response?.status === 401 && isSignedIn.value) {
      forget()
      reloadTo('/login?error=' + encodeURIComponent(SESSION_ENDED_MESSAGE))
    }
  }

  return { token, user, isSignedIn, signIn, forget, loadUser, signOut, handleApiError }
})
