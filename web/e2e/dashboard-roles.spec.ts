import { expect, test } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/** Roles without reports.view (sales, reservations, cashier) land on a dashboard without financial requests or errors. */
test('a user without reports.view gets a landing page that asks for no financial data', async ({ page }) => {
  await uiLogin(page, apiSession())
  await page.route('**/api/v1/auth/me', async (route) => {
    const response = await route.fetch()
    const body = await response.json()
    body.data.permissions = ['bookings.view', 'bookings.create', 'customers.view', 'payments.view', 'payments.create', 'leads.view']
    await route.fulfill({ response, json: body })
  })
  const financialCalls: string[] = []
  page.on('request', (request) => {
    if (request.url().includes('/dashboard/summary')) financialCalls.push(request.url())
  })

  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible()
  await expect(page.getByRole('link', { name: /New booking/ })).toBeVisible()
  await expect(page.getByText('Something went wrong')).toHaveCount(0)
  await expect(page.getByRole('alert')).toHaveCount(0)
  // The financial KPIs and the Reports menu entry are not offered.
  await expect(page.getByRole('region', { name: /Financial KPIs/i })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Reports', exact: true })).toHaveCount(0)
  expect(financialCalls).toEqual([])
})
