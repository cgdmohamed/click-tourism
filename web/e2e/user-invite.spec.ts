import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/** ADR-0032: with e-mail set up, a new user can be added without typing a password; an invitation goes out. */
test('adding a user without a password invites them and offers to resend while pending', async ({ page }) => {
  await uiLogin(page, apiSession())
  await page.route('**/api/v1/feature-flags', async (route) => {
    const response = await route.fetch()
    const body = (await response.json()) as { data: Record<string, unknown> }
    await route.fulfill({ response, json: { ...body, data: { ...body.data, mail_configured: true } } })
  })
  let created: Record<string, unknown> = {}
  let resent = 0
  const pending = {
    id: '01HINVITEDUSERINVITEDUSER0', name: 'Nour Invited', email: 'nour.invited@example.com', locale: 'ar', is_active: true, is_available_for_leads: false,
    roles: [], branches: [], primary_branch_id: null, employee: null, last_login_at: null, invite_pending: true, created_at: '2026-11-15T00:00:00Z',
  }
  await page.route('**/api/v1/users?*', async (route) => {
    const response = await route.fetch()
    const body = (await response.json()) as { data: unknown[] }
    await route.fulfill({ response, json: { ...body, data: [pending, ...body.data] } })
  })
  await page.route('**/api/v1/users', (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    created = route.request().postDataJSON()
    return route.fulfill({ status: 201, json: { data: { ...pending, invite_sent: true } } })
  })
  await page.route('**/api/v1/users/*/invite', (route) => {
    resent += 1
    return route.fulfill({ json: { data: pending } })
  })

  await page.goto('/users')
  await page.getByRole('button', { name: /مستخدم جديد|New user/ }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox').first().fill('Nour Invited')
  await dialog.locator('input[type="email"]').fill('nour.invited@example.com')
  await expect(dialog.getByText(/اتركها فارغة|Leave it empty/)).toBeVisible()

  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])

  await dialog.getByRole('button', { name: /^(حفظ|Save)$/ }).click()
  await expect(page.getByRole('status').filter({ hasText: /الدعوة|invitation/ })).toBeVisible()
  expect(created).not.toHaveProperty('password')

  const table = page.getByRole('table')
  await expect(table.getByText(/بانتظار التفعيل|Waiting to activate/).first()).toBeVisible()
  await table.getByRole('button', { name: /إعادة إرسال الدعوة|Resend invitation/ }).first().click()
  await expect.poll(() => resent).toBe(1)
})
