import axios, { AxiosError } from 'axios'
import { translateError } from './errors'
import type { ApiError } from './types'

const TOKEN_KEY = 'tourism_erp_token'
const LOCALE_KEY = 'tourism_erp_locale'
const BRANCH_KEY = 'tourism_erp_branch'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
  timeout: 30000,
})

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

export function getStoredLocale(): 'ar' | 'en' {
  return localStorage.getItem(LOCALE_KEY) === 'en' ? 'en' : 'ar'
}

export function setStoredLocale(locale: 'ar' | 'en') {
  localStorage.setItem(LOCALE_KEY, locale)
}

export function getStoredBranch(): string | null {
  return localStorage.getItem(BRANCH_KEY)
}

export function setStoredBranch(branchId: string | null) {
  if (branchId) localStorage.setItem(BRANCH_KEY, branchId)
  else localStorage.removeItem(BRANCH_KEY)
}

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  const locale = getStoredLocale()
  config.headers['Accept-Language'] = locale
  const branch = getStoredBranch()
  if (branch && !config.headers['X-Branch-Id']) config.headers['X-Branch-Id'] = branch
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiError>) => {
    if (error.response?.status === 401) {
      setToken(null)
      if (!window.location.pathname.startsWith('/login')) {
        window.location.assign('/login')
      }
    }
    return Promise.reject(error)
  },
)

function rawErrorMessage(error: unknown, fallback: string): string {
  if (axios.isAxiosError<ApiError>(error)) {
    const data = error.response?.data
    if (data?.errors) {
      const first = Object.values(data.errors)[0]
      if (first?.length) return first[0]
    }
    return data?.message ?? error.message ?? fallback
  }
  if (error instanceof Error) return error.message
  return fallback
}

/** Human-readable error text; backend messages are translated when the UI locale is Arabic. */
export function errorMessage(error: unknown, fallback = 'Unexpected error'): string {
  return translateError(rawErrorMessage(error, fallback), getStoredLocale())
}

export function idempotencyKey(): string {
  if (crypto.randomUUID) return crypto.randomUUID()
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`
}
