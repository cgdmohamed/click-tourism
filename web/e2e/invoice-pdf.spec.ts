import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { ensureInvoice } from './support/invoice-fixture'

/**
 * Invoices module critical flow: sign in, open an invoice and download the
 * server-rendered PDF (ADR-0014).
 *
 * The browser must never fall back to `window.print()`: the assertion is on a
 * real file download whose bytes start with the PDF signature and whose name
 * the server suggested.
 */
const email = process.env.E2E_ADMIN_EMAIL ?? 'admin@example.com'
const password = process.env.E2E_ADMIN_PASSWORD ?? 'password'

test.describe('invoice PDF', () => {
  test('an accountant downloads a server-rendered invoice PDF', async ({ page, request }) => {
    const invoice = await ensureInvoice(request, { email, password })

    await page.goto('/login')
    await page.getByPlaceholder('admin@example.com').fill(email)
    await page.locator('input[autocomplete="current-password"]').fill(password)
    await page.locator('form button[type="submit"]').click()
    await page.waitForURL((url) => !url.pathname.startsWith('/login'))

    await page.goto('/invoices')
    await page.getByRole('link', { name: invoice.number, exact: true }).click()
    await expect(page).toHaveURL(new RegExp(`/invoices/${invoice.id}$`))

    const downloadButton = page.getByRole('button', { name: /PDF/ })
    await expect(downloadButton).toBeVisible()
    await expect(downloadButton).toBeEnabled()

    const download = await Promise.all([
      page.waitForEvent('download'),
      downloadButton.click(),
    ]).then(([event]) => event)

    expect(download.suggestedFilename()).toBe(`invoice-${invoice.number}.pdf`)
    expect(await download.failure()).toBeNull()

    const path = await download.path()
    expect(path).toBeTruthy()
    const body = await readFile(path as string)
    expect(body.subarray(0, 5).toString('latin1')).toBe('%PDF-')
    expect(body.byteLength).toBeGreaterThan(1000)
  })
})
