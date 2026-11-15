import { expect, test } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/**
 * "Void invoice" asks "void for good?" first. That question belongs to one invoice: closing the dialog with
 * its footer button and opening another draft must not show it already open.
 */
test('the void question does not carry over to another invoice', async ({ page, request }) => {
  const session = apiSession()
  const accounts = ((await (await request.get('/api/v1/accounts?search=4100', { headers: session.headers })).json()).data as Array<{ id: string; code: string }>)
  const revenue = accounts.find((a) => a.code === '4100') ?? accounts[0]
  const customers = ((await (await request.get('/api/v1/customers?per_page=1', { headers: session.headers })).json()).data as Array<{ id: string }>)
  expect(revenue && customers[0], 'needs a revenue account and a customer').toBeTruthy()

  const numbers: string[] = []
  for (let i = 0; i < 2; i += 1) {
    const created = await request.post('/api/v1/invoices', {
      headers: { ...session.headers, 'Idempotency-Key': `void-confirm-${Date.now()}-${i}` },
      data: {
        type: 'customer_invoice', party_type: 'customer', party_id: customers[0].id,
        issue_date: new Date().toISOString().slice(0, 10), currency: 'EGP', memo: `void-confirm ${i}`,
        lines: [{ account_id: revenue.id, description: 'Void confirm line', quantity: 1, unit_price_minor: 1000, credit_minor: 1000 }],
      },
    })
    expect(created.ok(), `invoice creation failed with ${created.status()}`).toBeTruthy()
    numbers.push((await created.json()).data.number as string)
  }

  await uiLogin(page, session)
  await page.goto('/invoices')

  await page.getByRole('button', { name: new RegExp(`(تفاصيل|Details) ${numbers[0]}`) }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('button', { name: /إلغاء الفاتورة|Void invoice/ }).click()
  await expect(dialog.getByRole('button', { name: /نعم، ألغِ الفاتورة|Yes, void it/ })).toBeVisible()

  // Close with the footer button (not the corner X), then open the other invoice.
  await dialog.getByRole('button', { name: /^(إغلاق|Close)$/ }).last().click()
  await expect(dialog).toHaveCount(0)
  await page.getByRole('button', { name: new RegExp(`(تفاصيل|Details) ${numbers[1]}`) }).click()

  await expect(page.getByRole('dialog').getByRole('button', { name: /إلغاء الفاتورة|Void invoice/ })).toBeVisible()
  await expect(page.getByRole('button', { name: /نعم، ألغِ الفاتورة|Yes, void it/ })).toHaveCount(0)
})
