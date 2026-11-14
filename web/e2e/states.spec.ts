import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/**
 * Loading, empty and error primitives (audit 7.1-7.2): a mocked 500 renders the
 * translated ErrorState with the server text only under "details", an empty list
 * renders EmptyState, a slow list renders a skeleton, and none of them has a
 * serious or critical axe violation at desktop, tablet and phone widths.
 */
const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
]

const emptyList = { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null }, links: {} }

async function blocking(page: Page) {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  return results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => ({ rule: v.id, impact: v.impact, nodes: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) }))
}

for (const viewport of VIEWPORTS) {
  test.describe(`states at ${viewport.name}px`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } })

    test('a 500 renders a friendly, accessible error with details and a retry', async ({ page }) => {
      await uiLogin(page, apiSession())
      await page.route('**/api/v1/customers?**', (route) =>
        route.fulfill({
          status: 500,
          contentType: 'application/json',
          headers: { 'X-Request-Id': 'req-e2e-500' },
          body: JSON.stringify({ message: 'SQLSTATE[HY000]: raw server text' }),
        }),
      )
      await page.goto('/customers')

      const alert = page.getByRole('alert').filter({ hasText: 'The server hit an error' })
      await expect(alert).toBeVisible()
      await expect(alert.getByRole('button', { name: 'Retry' })).toBeVisible()
      // The raw server text is not the headline; it sits in the collapsed details.
      await expect(alert.getByText('SQLSTATE[HY000]: raw server text')).toBeHidden()
      await alert.getByText('Technical details').click()
      await expect(alert.getByText('SQLSTATE[HY000]: raw server text')).toBeVisible()
      await expect(alert.getByText('req-e2e-500')).toBeVisible()

      expect(await blocking(page)).toEqual([])
    })

    test('an empty list renders the empty state', async ({ page }) => {
      await uiLogin(page, apiSession())
      await page.route('**/api/v1/invoices?**', (route) =>
        route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(emptyList) }),
      )
      await page.goto('/invoices')
      await expect(page.getByText('No data')).toBeVisible()
      expect(await blocking(page)).toEqual([])
    })

    test('a slow list shows a labelled skeleton, not a spinner', async ({ page }) => {
      await uiLogin(page, apiSession())
      await page.route('**/api/v1/customers?**', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 3000))
        await route.continue()
      })
      await page.goto('/customers')
      const status = page.getByRole('status').filter({ hasText: 'Loading' })
      await expect(status).toBeVisible()
      await expect(status.locator('.animate-spin')).toHaveCount(0)
      expect(await blocking(page)).toEqual([])
    })
  })
}
