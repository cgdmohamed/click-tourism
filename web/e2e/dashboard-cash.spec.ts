import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/**
 * The cash and bank card shows one number even when some accounts are in other currencies: their balances at the
 * latest recorded rate, marked approximate, with the foreign balances and the rate date beside it.
 */
test('the cash card totals foreign accounts in the base currency and says it is approximate', async ({ page }) => {
  await uiLogin(page, apiSession())
  await page.route('**/api/v1/dashboard/summary*', async (route) => {
    const response = await route.fetch()
    const body = (await response.json()) as { data: Record<string, unknown> }
    await route.fulfill({
      response,
      json: {
        ...body,
        data: {
          ...body.data,
          currency: 'EGP',
          cash_balance_minor: 5_500_000,
          cash_balance_mixed_currency: true,
          cash_balance_approximate: true,
          cash_rates_as_of: '2026-11-01',
          cash_rates_missing: ['EUR'],
          cash_accounts: [
            { id: 'a1', name_ar: 'الخزينة', name_en: 'Cash box', kind: 'cash', currency: 'EGP', balance_minor: 500_000 },
            { id: 'a2', name_ar: 'بنك دولار', name_en: 'USD bank', kind: 'bank', currency: 'USD', balance_minor: 100_000, approx_base_minor: 5_000_000, rate: '50', rate_date: '2026-11-01' },
            { id: 'a3', name_ar: 'بنك يورو', name_en: 'EUR bank', kind: 'bank', currency: 'EUR', balance_minor: 20_000, rate_missing: true },
          ],
        },
      },
    })
  })

  await page.goto('/')
  const card = page.getByRole('link', { name: /أرصدة النقدية والبنوك|Cash & Bank Balances/ })
  await expect(card).toContainText('≈')
  await expect(card).toContainText('55,000.00')
  await expect(card).toContainText(/تقريبي|Approximate/)
  await expect(card).toContainText('$1,000.00')
  await expect(card).toContainText(/لا يوجد سعر صرف لـ EUR|No rate for EUR/)

  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
})
