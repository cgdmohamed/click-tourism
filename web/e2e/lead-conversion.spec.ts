import { expect, test } from '@playwright/test'
import { API, apiSession, uiLogin } from './support/session'

/**
 * CRM critical flow: create a lead through the form, convert it to a customer
 * and verify the customer exists server-side. The name is unique per run so
 * the spec is re-runnable against the same installation.
 */
test.describe('lead conversion', () => {
  test('a sales agent converts a new lead into a customer', async ({ page, request }) => {
    const name = `E2E Lead ${Date.now()}`
    const session = apiSession()
    await uiLogin(page, session)
    await page.goto('/leads')

    await page.getByRole('button', { name: 'New lead' }).click()
    await page.getByLabel('Name').fill(name)
    await page.getByRole('button', { name: 'Save', exact: true }).click()

    const row = page.getByRole('row').filter({ hasText: name })
    await expect(row).toBeVisible()
    await row.getByRole('button', { name: 'Convert' }).click()
    await expect(row.getByRole('button', { name: 'Convert' })).toHaveCount(0)

    const { headers } = session
    const customers = await request.get(`${API}/customers?search=${encodeURIComponent(name)}`, { headers })
    expect(customers.ok()).toBeTruthy()
    const found = ((await customers.json()).data as Array<{ name_en?: string; name_ar?: string }>)
      .some((customer) => customer.name_en === name || customer.name_ar === name)
    expect(found, 'the converted lead must exist as a customer').toBeTruthy()
  })
})
