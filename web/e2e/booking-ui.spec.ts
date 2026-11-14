import AxeBuilder from '@axe-core/playwright'
import { expect, request as playwrightRequest, test } from '@playwright/test'
import { API, apiSession, uiLogin } from './support/session'

/** UI review fixes 1-3: bookings as cards on a phone, labelled item fields, one money format. */
test('the booking form labels every item field and the list is readable on a phone', async ({ page }) => {
  const session = apiSession()
  const api = await playwrightRequest.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173', extraHTTPHeaders: session.headers })
  const suffix = Date.now().toString().slice(-6)
  const customer = await api.post(`${API}/customers`, { data: { name_ar: `عميل حجز ${suffix}`, name_en: `Booking customer ${suffix}` } })
  expect(customer.ok(), `customer ${customer.status()}`).toBeTruthy()
  const customerId = (await customer.json()).data.id as string
  const booking = await api.post(`${API}/bookings`, {
    data: {
      customer_id: customerId, branch_id: session.branchId, start_date: '2027-03-01', currency: 'EGP',
      items: [{ service_type: 'hotel', description: 'Hotel', quantity: 2, unit_cost_minor: 1000000, unit_sell_minor: 1254000 }],
    },
  })
  expect(booking.ok(), `booking ${booking.status()} ${await booking.text()}`).toBeTruthy()
  const number = (await booking.json()).data.number as string

  // Phone: cards, not a cut-off table.
  await page.setViewportSize({ width: 390, height: 844 })
  await uiLogin(page, session)
  await page.goto('/bookings')
  const cards = page.getByRole('list', { name: 'Bookings' })
  await expect(cards).toBeVisible()
  await expect(cards.getByRole('button', { name: number })).toBeVisible()
  // One money format: thousands separators and a currency symbol, never "EGP 12540.00".
  await expect(cards.locator('li').first()).toContainText(/EGP\s?\d{1,3}(,\d{3})+\.\d{2}/)
  await expect(cards).not.toContainText(/EGP\s?\d{4,}\.\d{2}/)
  await expect(page.locator('table')).toBeHidden()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow).toBe(0)

  // Desktop: the table is back and the new-booking form labels each item field.
  await page.setViewportSize({ width: 1440, height: 900 })
  await expect(page.locator('table').first()).toBeVisible()
  await page.getByRole('button', { name: /New booking|حجز جديد/ }).click()
  const dialog = page.getByRole('dialog')
  for (const label of [/Service/, /Supplier/, /Description/, /Quantity/, /Unit cost/, /Unit price/]) {
    await expect(dialog.getByText(label).first()).toBeVisible()
  }
  const axe = await new AxeBuilder({ page }).include('[role="dialog"]').withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => ({ id: v.id, nodes: v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')} :: ${n.any[0]?.message ?? ''}`) }))).toEqual([])
})
