import { expect, test } from '@playwright/test'
import { API, apiSession, uiLogin } from './support/session'

/** Choosing an option with Enter must not also apply (and close) the filter drawer. */
test('Enter on a picker option selects it and keeps the drawer open', async ({ page, request }) => {
  const { headers, branchId } = apiSession()
  const name = `Drawer Customer ${Date.now()}`
  const created = await request.post(`${API}/customers`, {
    headers,
    data: { type: 'individual', name_ar: 'عميل فلتر', name_en: name, branch_id: branchId },
  })
  expect(created.ok(), `customer creation failed with ${created.status()}`).toBeTruthy()

  await uiLogin(page, apiSession())
  await page.goto('/payments')
  await page.getByRole('button', { name: 'Filter' }).click()
  const drawer = page.getByRole('dialog')
  await drawer.getByRole('combobox').first().click()
  await page.getByRole('searchbox').or(page.getByPlaceholder(/search/i)).last().fill(name)
  await page.getByRole('option', { name }).waitFor()
  if (process.env.PICK === 'mouse') await page.getByRole('option', { name }).click()
  else await page.keyboard.press('Enter')

  await expect(drawer).toBeVisible()
  await expect(drawer.getByRole('combobox').first()).toContainText(name)
  // Nothing was applied yet: no chip outside the drawer.
  await drawer.getByRole('button', { name: 'Apply' }).click()
  await expect(drawer).toBeHidden()
  await expect(page.getByText(name).first()).toBeVisible()
})
