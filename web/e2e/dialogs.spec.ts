import AxeBuilder from '@axe-core/playwright'
import { expect, request as playwrightRequest, test, type Page } from '@playwright/test'
import { API, apiSession, uiLogin } from './support/session'

/**
 * Accessibility of the dialogs added with the approved requirements (accruals, revaluation,
 * documents): each opens on real data and must have no serious or critical axe violation.
 */
async function noBlockingViolations(page: Page, selector: string) {
  const results = await new AxeBuilder({ page }).include(selector).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => ({ id: v.id, nodes: v.nodes.slice(0, 2).map((n) => n.target.join(' ')) }))).toEqual([])
}

test('the cost accrual and revaluation dialogs open and are accessible', async ({ page }) => {
  await uiLogin(page, apiSession())
  await page.goto('/periods')

  await page.getByRole('button', { name: 'Cost accrual' }).first().click()
  const accrual = page.getByRole('dialog')
  await expect(accrual).toBeVisible()
  await noBlockingViolations(page, '[role="dialog"]')
  await page.keyboard.press('Escape')
  await expect(accrual).toHaveCount(0)

  await page.getByRole('button', { name: 'Revalue currencies' }).first().click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await noBlockingViolations(page, '[role="dialog"]')
})

test('the documents card and its preview dialog are accessible', async ({ page }) => {
  const session = apiSession()
  const api = await playwrightRequest.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173' })
  const suffix = Date.now().toString().slice(-6)
  const customer = await api.post(`${API}/customers`, {
    headers: session.headers,
    data: { name_ar: `عميل مستندات ${suffix}`, name_en: `Documents customer ${suffix}` },
  })
  expect(customer.ok(), `customer: ${customer.status()}`).toBeTruthy()
  const customerId = (await customer.json()).data.id as string

  // A 1x1 PNG, so the preview dialog has something to render.
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')
  const upload = await api.post(`${API}/attachments`, {
    headers: session.headers,
    multipart: {
      attachable_type: 'customer',
      attachable_id: customerId,
      category: 'other',
      'files[]': { name: 'proof.png', mimeType: 'image/png', buffer: png },
    },
  })
  expect(upload.ok(), `upload: ${upload.status()} ${await upload.text()}`).toBeTruthy()

  await uiLogin(page, session)
  await page.goto(`/customers/${customerId}`)
  await expect(page.getByText('proof.png')).toBeVisible()
  await noBlockingViolations(page, 'main')

  await page.getByRole('button', { name: 'Preview' }).first().click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await noBlockingViolations(page, '[role="dialog"]')
})
