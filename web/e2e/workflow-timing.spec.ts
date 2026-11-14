import { expect, test, type Page } from '@playwright/test'
import { API, apiSession, uiLogin } from './support/session'

/**
 * Workflow journeys measured by what matters to a user: the time until the work can be done, the number of
 * actions (clicks, selections, typing) and the screens visited, for the old route and the shortcut that replaced it.
 * Each journey asserts that the shortcut is not slower in actions and prints one line per route; the numbers
 * are also attached to the test report. Wall-clock time depends on the machine, so the hard assertion is on actions.
 */
type Measure = { route: string; ms: number; actions: number; screens: number }
const results: Array<{ journey: string; old: Measure; shortcut: Measure }> = []

async function measure<T>(page: Page, route: string, run: (act: () => void) => Promise<T>): Promise<Measure> {
  let actions = 0
  let screens = 0
  const onNavigated = (frame: unknown) => {
    if (frame === page.mainFrame()) screens += 1
  }
  page.on('framenavigated', onNavigated)
  const start = Date.now()
  await run(() => {
    actions += 1
  })
  page.off('framenavigated', onNavigated)
  return { route, ms: Date.now() - start, actions, screens }
}

test.describe.configure({ mode: 'serial' })

test.afterAll(() => {
  const lines = results.map(
    (item) =>
      `${item.journey}: old ${item.old.actions} actions / ${item.old.screens} screens / ${item.old.ms} ms -> shortcut ${item.shortcut.actions} actions / ${item.shortcut.screens} screens / ${item.shortcut.ms} ms`,
  )
  console.log(`\nWORKFLOW TIMING\n${lines.join('\n')}`)
})

test('record a receipt for a customer: Payments > New > pick customer vs the customer page shortcut', async ({ page, request }, info) => {
  const { headers, branchId } = apiSession()
  const name = `Timing Customer ${Date.now()}`
  const created = await request.post(`${API}/customers`, { headers, data: { type: 'individual', name_ar: 'عميل قياس', name_en: name, branch_id: branchId } })
  expect(created.ok()).toBeTruthy()
  const customer = (await created.json()).data as { id: string }
  await uiLogin(page, apiSession())

  // Both routes start on the customer page and end with the receipt saved, so the time is the whole operation.
  const finish = async (act: () => void) => {
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('combobox').first()).toContainText(name)
    act()
    await dialog.getByRole('combobox').nth(3).click()
    act()
    await page.getByRole('option').first().click()
    act()
    await dialog.getByRole('textbox').first().fill('10.00')
    act()
    await dialog.getByRole('button', { name: 'Save' }).click()
    await expect(dialog).toBeHidden()
  }

  await page.goto(`/customers/${customer.id}`)
  await page.getByRole('link', { name: 'Record collection' }).waitFor()
  const old = await measure(page, 'payments list', async (act) => {
    act()
    await page.goto('/payments')
    await page.getByRole('button', { name: 'New receipt' }).waitFor()
    act()
    await page.getByRole('button', { name: 'New receipt' }).click()
    const dialog = page.getByRole('dialog')
    act()
    await dialog.getByRole('combobox').first().click()
    act()
    await page.getByRole('option', { name }).click()
    await finish(act)
  })

  await page.goto(`/customers/${customer.id}`)
  await page.getByRole('link', { name: 'Record collection' }).waitFor()
  const shortcut = await measure(page, 'customer page', async (act) => {
    act()
    await page.getByRole('link', { name: 'Record collection' }).click()
    await finish(act)
  })

  results.push({ journey: 'receipt for a customer', old, shortcut })
  info.annotations.push({ type: 'timing', description: JSON.stringify({ old, shortcut }) })
  expect(shortcut.actions).toBeLessThan(old.actions)
})

test('supplier invoice: Invoices > New > type > supplier vs the supplier page shortcut', async ({ page, request }, info) => {
  const { headers } = apiSession()
  const name = `Timing Supplier ${Date.now()}`
  const created = await request.post(`${API}/suppliers`, { headers, data: { category: 'other', name_ar: 'مورد قياس', name_en: name } })
  expect(created.ok(), `supplier creation failed with ${created.status()}`).toBeTruthy()
  const supplier = (await created.json()).data as { id: string }
  await uiLogin(page, apiSession())

  const old = await measure(page, 'invoices list', async (act) => {
    await page.goto('/invoices')
    await page.getByRole('button', { name: 'New invoice' }).waitFor()
    act()
    await page.getByRole('button', { name: 'New invoice' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('combobox').first().waitFor()
    act()
    await dialog.getByRole('combobox').first().click()
    act()
    await page.getByRole('option', { name: /Supplier/ }).first().click()
    act()
    await dialog.getByRole('combobox').nth(1).click()
    act()
    await page.getByRole('option', { name }).click()
    await expect(dialog).toContainText(name)
  })

  const shortcut = await measure(page, 'supplier page', async (act) => {
    await page.goto(`/suppliers/${supplier.id}`)
    await page.getByRole('link', { name: 'Record supplier invoice' }).waitFor()
    act()
    await page.getByRole('link', { name: 'Record supplier invoice' }).click()
    await expect(page.getByRole('dialog')).toContainText(name)
  })

  results.push({ journey: 'supplier invoice', old, shortcut })
  info.annotations.push({ type: 'timing', description: JSON.stringify({ old, shortcut }) })
  expect(shortcut.actions).toBeLessThan(old.actions)
})

test('reach a waiting expense: Expenses > Filter > Submitted vs the Approvals page', async ({ page, request }, info) => {
  const { headers } = apiSession()
  const categories = ((await (await request.get(`${API}/expense-categories`, { headers })).json()).data ?? []) as Array<{ id: string }>
  test.skip(categories.length === 0, 'needs an expense category')
  const cashAccounts = ((await (await request.get(`${API}/cash-accounts`, { headers })).json()).data ?? []) as Array<{ cash_account_id?: string; id: string; registered?: boolean }>
  const cash = cashAccounts.find((account) => account.registered !== false)
  test.skip(!cash, 'needs a registered cash account')
  const description = `Timing expense ${Date.now()}`
  const created = await request.post(`${API}/expenses`, {
    headers,
    data: { category_id: categories[0].id, expense_date: new Date().toISOString().slice(0, 10), amount_minor: 12500, currency: 'EGP', description, submit: true, cash_account_id: cash?.cash_account_id ?? cash?.id },
  })
  expect(created.ok(), `expense creation failed with ${created.status()}: ${await created.text()}`).toBeTruthy()
  await uiLogin(page, apiSession())

  const old = await measure(page, 'expenses list', async (act) => {
    await page.goto('/expenses')
    await page.getByRole('button', { name: 'Filter' }).waitFor()
    act()
    await page.getByRole('button', { name: 'Filter' }).click()
    const drawer = page.getByRole('dialog')
    act()
    await drawer.getByRole('combobox').first().click()
    act()
    await page.getByRole('option', { name: 'Awaiting approval' }).click()
    act()
    await drawer.getByRole('button', { name: 'Apply' }).click()
    await expect(page.getByText(description).locator('visible=true').first()).toBeVisible()
  })

  const shortcut = await measure(page, 'approvals page', async () => {
    await page.goto('/approvals')
    await expect(page.getByText(description).locator('visible=true').first()).toBeVisible()
  })

  results.push({ journey: 'reach a waiting expense', old, shortcut })
  info.annotations.push({ type: 'timing', description: JSON.stringify({ old, shortcut }) })
  expect(shortcut.actions).toBeLessThan(old.actions)
})
