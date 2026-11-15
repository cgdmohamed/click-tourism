import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

/**
 * Signed-out screens (ADR-0031): the login page offers "forgot password" only when the server can send
 * e-mail, the request never says whether an account exists, and the reset link sets a password once.
 */
async function branding(page: import('@playwright/test').Page, available: boolean) {
  await page.route('**/api/v1/branding', (route) =>
    route.fulfill({ json: { data: { company_name_ar: '', company_name_en: '', has_logo: false, logo_version: null, password_reset_available: available } } }),
  )
}

async function noSeriousViolations(page: import('@playwright/test').Page) {
  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
}

test('the login page links to password reset when the server can send e-mail', async ({ page }) => {
  await branding(page, true)
  await page.goto('/login')
  await expect(page.getByRole('heading', { name: /تسجيل الدخول|Sign in/ })).toBeVisible()
  await page.getByRole('link', { name: /نسيت كلمة المرور|Forgot your password/ }).click()
  await expect(page).toHaveURL(/\/forgot-password$/)
  await noSeriousViolations(page)
})

test('without a mailer the login page tells the user to ask an administrator', async ({ page }) => {
  await branding(page, false)
  await page.goto('/login')
  await expect(page.getByRole('link', { name: /نسيت كلمة المرور|Forgot your password/ })).toHaveCount(0)
  await expect(page.getByText(/اطلب من المدير|Ask an administrator/)).toBeVisible()
  await noSeriousViolations(page)
})

test('asking for a link shows the same message for any e-mail', async ({ page }) => {
  await branding(page, true)
  let body: { email?: string } = {}
  await page.route('**/api/v1/auth/forgot-password', (route) => {
    body = route.request().postDataJSON()
    return route.fulfill({ json: { data: { message: 'sent' } } })
  })
  await page.goto('/forgot-password')
  await page.locator('input[type="email"]').fill('nobody@example.com')
  await page.getByRole('button', { name: /أرسل الرابط|Send the link/ }).click()
  await expect(page.getByRole('status')).toContainText(/تحقق من بريدك|Check your e-mail|60/)
  expect(body.email).toBe('nobody@example.com')
  await noSeriousViolations(page)
})

test('a server without a mailer says so on the forgot page', async ({ page }) => {
  await branding(page, true)
  await page.route('**/api/v1/auth/forgot-password', (route) => route.fulfill({ status: 503, json: { message: 'x', code: 'password_reset_unavailable' } }))
  await page.goto('/forgot-password')
  await page.locator('input[type="email"]').fill('a@example.com')
  await page.getByRole('button', { name: /أرسل الرابط|Send the link/ }).click()
  await expect(page.getByText(/غير مفعّلة على هذا الخادم|not set up on this server/)).toBeVisible()
})

test('the reset link checks the password, saves it once and points to sign in', async ({ page }) => {
  await branding(page, true)
  let sent: Record<string, string> = {}
  await page.route('**/api/v1/auth/reset-password', (route) => {
    sent = route.request().postDataJSON()
    return route.fulfill({ json: { data: { message: 'ok' } } })
  })
  await page.goto('/reset-password?token=abc123&email=maha%40example.com')
  await noSeriousViolations(page)

  const fields = page.locator('input[autocomplete="new-password"]')
  await fields.nth(0).fill('short')
  await fields.nth(1).fill('short')
  await page.getByRole('button', { name: /حفظ كلمة المرور|Save password/ }).click()
  await expect(page.getByText(/أقل من 12|shorter than 12/)).toBeVisible()
  expect(sent).toEqual({})

  await fields.nth(0).fill('a-brand-new-passphrase-1')
  await fields.nth(1).fill('different-passphrase-22')
  await page.getByRole('button', { name: /حفظ كلمة المرور|Save password/ }).click()
  await expect(page.getByText(/غير متطابقتين|do not match/)).toBeVisible()

  await fields.nth(1).fill('a-brand-new-passphrase-1')
  await page.getByRole('button', { name: /حفظ كلمة المرور|Save password/ }).click()
  await expect(page.getByRole('status')).toContainText(/تم|changed|جلساتك|sessions/)
  expect(sent).toMatchObject({ email: 'maha@example.com', token: 'abc123', password: 'a-brand-new-passphrase-1' })
  await expect(page.getByRole('link', { name: /دخول|Sign in/ })).toBeVisible()
})

test('an expired or used link offers a new one', async ({ page }) => {
  await branding(page, true)
  await page.route('**/api/v1/auth/reset-password', (route) => route.fulfill({ status: 422, json: { message: 'x', code: 'invalid_reset_link' } }))
  await page.goto('/reset-password?token=old&email=maha%40example.com')
  const fields = page.locator('input[autocomplete="new-password"]')
  await fields.nth(0).fill('a-brand-new-passphrase-1')
  await fields.nth(1).fill('a-brand-new-passphrase-1')
  await page.getByRole('button', { name: /حفظ كلمة المرور|Save password/ }).click()
  await expect(page.getByText(/غير صالح|not valid/)).toBeVisible()
  await page.getByRole('link', { name: /اطلب رابطاً جديداً|Request a new link/ }).click()
  await expect(page).toHaveURL(/\/forgot-password$/)
})

test('a reset page without a token is not usable', async ({ page }) => {
  await branding(page, true)
  await page.goto('/reset-password')
  await expect(page.getByText(/غير صالح|not valid/)).toBeVisible()
})
