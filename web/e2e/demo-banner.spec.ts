import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

/**
 * The demo notice can be closed. It stays closed for the browser session and comes back when the demo
 * database is reset (a new generation), because that is when the data the user typed disappears.
 */
async function mockDemo(page: import('@playwright/test').Page, generation: { value: string }) {
  await page.route('**/api/v1/demo/status', (route) => route.fulfill({
    json: { data: { enabled: true, reset_seconds: 600, next_reset_at: new Date(Date.now() + 600_000).toISOString(), generation: generation.value } },
  }))
}

test('the demo notice closes, stays closed on reload, and returns after a reset', async ({ page }) => {
  const generation = { value: 'g1' }
  await mockDemo(page, generation)
  await page.goto('/login')

  const banner = page.getByRole('status').filter({ hasText: /Demo Mode|نسخة تجريبية/ })
  await expect(banner).toBeVisible()
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([])

  await page.getByRole('button', { name: /Dismiss demo notice|إغلاق تنبيه النسخة التجريبية/ }).click()
  await expect(banner).toBeHidden()

  await page.reload()
  await expect(page.getByRole('heading').first()).toBeVisible()
  await expect(banner).toBeHidden()

  generation.value = 'g2'
  await page.reload()
  await expect(banner).toBeVisible()
})
