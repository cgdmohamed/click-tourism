import { expect, test } from '@playwright/test'
import { API, apiSession, uiLogin } from './support/session'

/**
 * Payments module critical flow: a posted receipt is refunded from the browser.
 *
 * Posting requires the accountant-approved accounting policy, and this spec has
 * to approve it, so it only runs against a disposable installation that opts in
 * with E2E_APPROVE_POLICY=1 (CI does; never set it against a real client install).
 * The refund is requested but not approved: approval is maker-checker and the
 * posting itself is covered by the backend PaymentService tests.
 */
test.describe('payment refund', () => {
  test.skip(process.env.E2E_APPROVE_POLICY !== '1', 'needs a disposable install: set E2E_APPROVE_POLICY=1')

  test('an accountant requests a refund on a posted receipt', async ({ page, request }) => {
    const session = apiSession()
    const { headers, branchId } = session

    const policy = await request.put(`${API}/settings`, {
      headers,
      data: { settings: [{ group: 'accounting', key: 'accounting_policy_approved', value: 'true' }] },
    })
    expect(policy.ok(), `policy approval failed with ${policy.status()}`).toBeTruthy()

    const accounts = await request.get(`${API}/accounts?search=1110`, { headers })
    const cash = ((await accounts.json()).data as Array<{ id: string; code: string }>).find((a) => a.code === '1110')
    expect(cash, 'seeded main cash account 1110 is required').toBeTruthy()

    const customerResponse = await request.post(`${API}/customers`, {
      headers,
      data: { type: 'individual', name_ar: 'عميل استرداد', name_en: `E2E Refund Customer ${Date.now()}`, branch_id: branchId },
    })
    expect(customerResponse.ok(), `customer creation failed with ${customerResponse.status()}`).toBeTruthy()
    const customer = (await customerResponse.json()).data as { id: string }

    const paymentResponse = await request.post(`${API}/payments`, {
      headers: { ...headers, 'Idempotency-Key': `playwright-payment-${Date.now()}` },
      data: {
        customer_id: customer.id,
        branch_id: branchId,
        cash_account_id: cash?.id,
        method: 'cash',
        amount_minor: 10000,
        currency: 'EGP',
      },
    })
    expect(paymentResponse.ok(), `payment creation failed with ${paymentResponse.status()}`).toBeTruthy()
    const payment = (await paymentResponse.json()).data as { number: string }

    await uiLogin(page, session)
    await page.goto('/payments')
    const row = page.getByRole('row').filter({ hasText: payment.number })
    await expect(row).toBeVisible()
    await row.getByRole('button', { name: 'Refund', exact: true }).click()

    await page.getByLabel('Amount').fill('40.00')
    await page.getByLabel('Reason').fill('E2E customer cancelled')
    await page.getByRole('button', { name: 'Save', exact: true }).click()

    await row.getByRole('button', { name: 'Refunds', exact: true }).click()
    const refunds = page.getByRole('dialog')
    await expect(refunds).toContainText('40.00')
    await expect(refunds).toContainText(/requested/i)
  })
})
