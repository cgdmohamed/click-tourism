import { expect, test } from '@playwright/test'
import { API, apiSession, uiLogin } from './support/session'

/** A deactivated payment method stays selectable in the Payments history filter (old receipts keep their method). */
test('the payments filter lists deactivated methods, the new-receipt form does not', async ({ page, request }) => {
  const { headers } = apiSession()
  const name = `Retired method ${Date.now()}`
  const created = await request.post(`${API}/payment-methods`, { headers, data: { code: `RM${Date.now()}`.slice(0, 20), name_ar: 'طريقة قديمة', name_en: name, type: 'other', is_active: false } })
  test.skip(!created.ok(), `could not create a payment method (${created.status()})`)

  await uiLogin(page, apiSession())
  await page.goto('/payments')
  await page.getByRole('button', { name: 'Filter' }).click()
  const drawer = page.getByRole('dialog')
  await drawer.getByRole('combobox').nth(2).click()
  await expect(page.getByRole('option', { name: new RegExp(`${name} \\(inactive\\)`) })).toBeVisible()
  await page.keyboard.press('Escape')
  await page.keyboard.press('Escape')

  await page.getByRole('button', { name: 'New receipt' }).click()
  await page.getByRole('dialog').getByRole('combobox').nth(4).click()
  await expect(page.getByRole('option', { name: new RegExp(name) })).toHaveCount(0)
})
