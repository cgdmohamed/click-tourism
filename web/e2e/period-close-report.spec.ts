import { expect, request as playwrightRequest, test } from '@playwright/test'
import { API, apiSession, uiLogin } from './support/session'

/**
 * GAP-QA-01: the close -> report journey. An accountant closes the last period of the open year,
 * the period shows as closed, the trial balance still reports for the year, and the period is
 * reopened. Like the refund spec it changes state, so it only runs on a disposable install
 * (CI sets E2E_APPROVE_POLICY=1; never set it against a real client install).
 */
test('closing a period keeps the reports available, then it is reopened', async ({ page }) => {
  test.skip(process.env.E2E_APPROVE_POLICY !== '1', 'needs a disposable install: set E2E_APPROVE_POLICY=1')

  const session = apiSession()
  await uiLogin(page, session)
  const api = await playwrightRequest.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173', extraHTTPHeaders: session.headers })
  await page.goto('/periods')

  // The last period of the first (open) fiscal year: never the current one, so other specs are unaffected.
  const lastRow = page.locator('table').first().locator('tbody tr').last()
  const status = lastRow.getByRole('combobox')
  await expect(status).toContainText('open')
  const periods = (await (await api.get(`${API}/fiscal-periods`)).json()).data as Array<{ periods?: Array<{ id: string; status: string }> }>
  const target = periods[0].periods!.at(-1)!

  try {
    await status.click()
    await page.getByRole('option', { name: 'closed', exact: true }).click()
    await expect(status).toContainText('closed')

    await page.goto('/reports')
    // Reports still run for the year whose last period is closed (an empty install shows "No data").
    await page.getByRole('button', { name: /^(Run|تشغيل)$/ }).click()
    await expect(page.getByText(/Trial balance|ميزان المراجعة/).first()).toBeVisible()
    await expect(page.getByText(/No data|لا توجد بيانات/).or(page.locator('table').first()).first()).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)
  } finally {
    // Restore through the API so a failed assertion never leaves the period closed.
    await api.patch(`${API}/fiscal-periods/${target.id}/status`, { data: { status: 'open' } })
  }
  const after = (await (await api.get(`${API}/fiscal-periods`)).json()).data as Array<{ periods?: Array<{ id: string; status: string }> }>
  expect(after[0].periods!.at(-1)!.status).toBe('open')
})
