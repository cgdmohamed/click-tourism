import { expect, request as playwrightRequest, type APIRequestContext, type Page } from '@playwright/test'

export const email = process.env.E2E_ADMIN_EMAIL ?? 'admin@example.com'
export const password = process.env.E2E_ADMIN_PASSWORD ?? 'password'

export const API = '/api/v1'

export type ApiSession = { token: string; headers: Record<string, string>; branchId: string }

async function signIn(request: APIRequestContext): Promise<ApiSession> {
  const login = await request.post(`${API}/auth/login`, {
    data: { email, password, device_name: 'playwright' },
  })
  expect(login.ok(), `login failed with ${login.status()}`).toBeTruthy()
  const token = (await login.json()).data.token as string
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

  const me = await request.get(`${API}/auth/me`, { headers })
  expect(me.ok(), `profile failed with ${me.status()}`).toBeTruthy()
  const branchId = ((await me.json()).data as { branches?: Array<{ id: string }> }).branches?.[0]?.id
  expect(branchId, 'the E2E user must belong to at least one branch').toBeTruthy()

  return { token, headers: { ...headers, 'X-Branch-Id': branchId as string }, branchId: branchId as string }
}

// `auth/login` is throttled to 10 per minute, so the suite signs in once in
// global setup and every spec reuses that token through the environment. The
// login form itself is exercised by invoice-pdf.spec.ts and the a11y spec.
export async function globalSignIn(baseURL: string): Promise<void> {
  const context = await playwrightRequest.newContext({ baseURL })
  const session = await signIn(context)
  process.env.E2E_SESSION = JSON.stringify(session)
  await context.dispose()
}

export function apiSession(): ApiSession {
  const raw = process.env.E2E_SESSION
  if (!raw) throw new Error('E2E_SESSION is missing: global setup did not run')
  return JSON.parse(raw) as ApiSession
}

/** Opens the app signed in (token injected before load) with the UI forced to English. */
export async function uiLogin(page: Page, session: ApiSession): Promise<void> {
  await page.addInitScript((token) => {
    localStorage.setItem('tourism_erp_token', token)
    localStorage.setItem('tourism_erp_locale', 'en')
  }, session.token)
}
