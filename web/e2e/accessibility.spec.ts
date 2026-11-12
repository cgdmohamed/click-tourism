import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/**
 * GAP-QA-02 / AC-UI-006: automated accessibility gate.
 *
 * Fails on any serious or critical axe violation on the key pages. Known debt
 * is listed in KNOWN_VIOLATIONS as "<path>|<rule id>" so it stays visible and
 * the list can only shrink; a new violation, or a new page/rule pair, fails.
 */
const PAGES = [
  { name: 'login', path: '/login', authenticated: false },
  { name: 'dashboard', path: '/', authenticated: true },
  { name: 'bookings', path: '/bookings', authenticated: true },
  { name: 'invoices', path: '/invoices', authenticated: true },
  { name: 'payments', path: '/payments', authenticated: true },
  { name: 'reports', path: '/reports', authenticated: true },
  { name: 'leads', path: '/leads', authenticated: true },
  { name: 'settings', path: '/settings', authenticated: true },
]

// TODO(GAP-QA-02): 0 known violations. Add "<path>|<rule id>" here only with a ticket.
const KNOWN_VIOLATIONS = new Set<string>([])

test.describe('accessibility', () => {
  for (const target of PAGES) {
    test(`${target.name} has no serious or critical axe violations`, async ({ page }) => {
      if (target.authenticated) await uiLogin(page, apiSession())
      else await page.addInitScript(() => localStorage.setItem('tourism_erp_locale', 'en'))

      await page.goto(target.path)
      await page.waitForLoadState('networkidle')
      await expect(page.locator('main, form').first()).toBeVisible()

      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
      const blocking = results.violations.filter(
        (violation) =>
          (violation.impact === 'serious' || violation.impact === 'critical') &&
          !KNOWN_VIOLATIONS.has(`${target.path}|${violation.id}`),
      )

      expect(
        blocking.map((violation) => ({
          rule: violation.id,
          impact: violation.impact,
          nodes: violation.nodes.slice(0, 3).map((node) => node.target.join(' ')),
        })),
      ).toEqual([])
    })
  }
})
