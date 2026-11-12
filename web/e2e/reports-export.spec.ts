import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { apiSession, uiLogin } from './support/session'

/**
 * Reports module critical flow: run the trial balance and export it as CSV,
 * XLSX and PDF. The assertions are on real downloads and their file signatures.
 */
test.describe('report export', () => {
  test('an accountant exports the trial balance in every format', async ({ page }) => {
    await uiLogin(page, apiSession())
    await page.goto('/reports')

    await page.getByRole('button', { name: 'Run', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Export CSV' })).toBeEnabled()

    const exportAs = async (button: string) => {
      const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: button }).click(),
      ])
      expect(await download.failure()).toBeNull()
      const path = await download.path()
      expect(path).toBeTruthy()
      return { name: download.suggestedFilename(), body: await readFile(path as string) }
    }

    const csv = await exportAs('Export CSV')
    expect(csv.name).toMatch(/\.csv$/)
    expect(csv.body.byteLength).toBeGreaterThan(0)

    const xlsx = await exportAs('Export XLSX')
    expect(xlsx.name).toMatch(/\.xlsx$/)
    expect(xlsx.body.subarray(0, 2).toString('latin1')).toBe('PK') // zip container

    const pdf = await exportAs('Export PDF')
    expect(pdf.name).toMatch(/\.pdf$/)
    expect(pdf.body.subarray(0, 5).toString('latin1')).toBe('%PDF-')
  })
})
