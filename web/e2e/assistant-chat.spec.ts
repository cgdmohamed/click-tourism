import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/**
 * ADR-0022: the assistant is a full-screen chat mode. The provider and the server's assistant
 * routes are replaced here, so the spec checks the screen itself: mode switch, example questions,
 * live steps, structured answer with a record link, saved chats, accessibility.
 */
test('the assistant mode replaces the system with a chat that shows steps, links and saved chats', async ({ page }) => {
  await uiLogin(page, apiSession())

  await page.route('**/api/v1/feature-flags', async (route) => {
    const response = await route.fetch()
    const body = (await response.json()) as { data: Record<string, unknown> }
    await route.fulfill({ response, json: { ...body, data: { ...body.data, assistant_enabled: true, assistant_provider: 'openai' } } })
  })
  await page.route('**/api/v1/assistant/conversations', (route) =>
    route.fulfill({ json: { data: [{ id: '01HZZZZZZZZZZZZZZZZZZZZZZZ', title: 'ما هي الفواتير المفتوحة؟', last_message_at: '2026-11-13T10:00:00Z' }] } }),
  )
  await page.route('**/api/v1/assistant/chat/stream', (route) => {
    const answer = {
      type: 'answer',
      data: {
        answer: 'الفواتير المفتوحة:\n\n1. **الفاتورة لدى العميل**\n- **الرقم**: INV-HQ-2026-000001\n- المبلغ: 2850.00',
        citations: [
          { tool: 'erp_invoices_list', entity: 'invoice', id: null, route: '/invoices', as_of: '2026-11-13T10:00:00Z' },
          { tool: 'erp_invoices_list', entity: 'invoice', id: 'abcdefgh1234', labels: ['INV-HQ-2026-000001'], route: '/invoices', as_of: '2026-11-13T10:00:00Z' },
        ],
        usage: { prompt: 1, completion: 1, total: 2 },
        truncated: false,
        conversation_id: '01HYYYYYYYYYYYYYYYYYYYYYYY',
      },
    }
    const lines = [{ type: 'thinking' }, { type: 'tool', tool: 'erp_invoices_list', entity: 'invoice' }, { type: 'thinking' }, answer]
    return route.fulfill({ status: 200, contentType: 'application/x-ndjson', body: lines.map((line) => JSON.stringify(line)).join('\n') + '\n' })
  })

  await page.goto('/')
  await expect(page.getByRole('navigation', { name: 'Main navigation' })).toBeVisible()
  await page.getByRole('button', { name: /افتح المساعد|Open the assistant/ }).click()

  // Everything else is hidden: no main navigation, no page content.
  await expect(page.getByRole('navigation', { name: 'Main navigation' })).toHaveCount(0)
  await expect(page.getByRole('heading', { name: /كيف أساعدك اليوم|How can I help today/ })).toBeVisible()
  await expect(page.getByRole('list', { name: /أسئلة مقترحة|Suggested questions/ }).getByRole('button')).toHaveCount(4)
  await expect(page.getByRole('button', { name: 'ما هي الفواتير المفتوحة؟' })).toBeVisible()

  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])

  if (process.env.E2E_SHOT_EMPTY) await page.screenshot({ path: process.env.E2E_SHOT_EMPTY })

  await page.locator('#assistant-input').fill('ما هي الفواتير المفتوحة؟')
  await page.keyboard.press('Enter')

  const answer = page.getByRole('log').locator('strong', { hasText: 'الفاتورة لدى العميل' })
  await expect(answer).toBeVisible()
  await expect(page.getByRole('log').getByText('بحثتُ في: الفواتير').or(page.getByRole('log').getByText('Searched: invoices'))).toBeVisible()
  const link = page.getByRole('log').getByRole('link', { name: 'INV-HQ-2026-000001' })
  await expect(link).toHaveAttribute('href', '/invoices/abcdefgh1234')
  await expect(page.getByRole('log')).not.toContainText('**')

  await page.screenshot({ path: process.env.E2E_SHOT ?? 'test-results/assistant-chat.png', fullPage: false })

  // Back to the system.
  await page.getByRole('button', { name: /العودة إلى النظام|Back to the system/ }).click()
  await expect(page.getByRole('navigation', { name: 'Main navigation' })).toBeVisible()
})

test('an action the assistant prepares waits for the user and then runs as confirmed', async ({ page }) => {
  await uiLogin(page, apiSession())
  const pending = {
    id: '01HACTIONACTIONACTIONACTION', tool: 'erp_customer_create', title: 'إنشاء عميل', status: 'pending', expires_at: '2099-01-01T00:00:00Z', result: null, error: null,
    rows: [{ label: 'الاسم بالإنجليزية', value: 'Nile Co' }, { label: 'الاسم بالعربية', value: 'شركة النيل' }],
  }
  let confirmed = 0
  await page.route('**/api/v1/feature-flags', async (route) => {
    const response = await route.fetch()
    const body = (await response.json()) as { data: Record<string, unknown> }
    await route.fulfill({ response, json: { ...body, data: { ...body.data, assistant_enabled: true, assistant_provider: 'openai' } } })
  })
  await page.route('**/api/v1/assistant/conversations', (route) => route.fulfill({ json: { data: [] } }))
  await page.route('**/api/v1/assistant/chat/stream', (route) => {
    const answer = { type: 'answer', data: { answer: 'جهّزت العميل، راجع البطاقة.', citations: [], usage: { prompt: 1, completion: 1, total: 2 }, truncated: false, actions: [pending], conversation_id: null } }
    const lines = [{ type: 'thinking' }, { type: 'tool', tool: 'erp_customer_create', entity: 'action' }, answer]
    return route.fulfill({ status: 200, contentType: 'application/x-ndjson', body: lines.map((line) => JSON.stringify(line)).join('\n') + '\n' })
  })
  await page.route('**/api/v1/assistant/actions/*/confirm', (route) => {
    confirmed += 1
    return route.fulfill({ json: { data: { ...pending, status: 'confirmed', result: { entity: 'customer', id: 'customer12345' } } } })
  })

  await page.goto('/')
  await page.getByRole('button', { name: /افتح المساعد|Open the assistant/ }).click()
  await page.locator('#assistant-input').fill('أنشئ عميل شركة النيل')
  await page.keyboard.press('Enter')

  const card = page.getByRole('region', { name: 'إنشاء عميل' })
  await expect(card).toBeVisible()
  await expect(card).toContainText('Nile Co')
  expect(confirmed, 'nothing runs before the user decides').toBe(0)

  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])

  await card.getByRole('button', { name: /تأكيد وتنفيذ|Confirm and run/ }).click()
  await expect(card.getByRole('status')).toContainText(/تم التنفيذ|Done/)
  await expect(card.getByRole('link', { name: /افتح السجل|Open record/ })).toHaveAttribute('href', '/customers/customer12345')
  expect(confirmed).toBe(1)
})

test('an action that posts or pays asks a second time, and going back runs nothing', async ({ page }) => {
  await uiLogin(page, apiSession())
  const pending = {
    id: '01HACTIONPAYPAYPAYPAYPAY', tool: 'erp_commission_pay', title: 'صرف عمولة', status: 'pending', risk: 'pay', expires_at: '2099-01-01T00:00:00Z', result: null, error: null,
    rows: [{ label: 'السجل', value: 'CM-000007' }, { label: 'حساب الدفع', value: 'الخزينة' }, { label: 'ما سيحدث', value: 'ستُصرف العمولة من الحساب المحدد وتُسجَّل في القيود.' }],
  }
  let confirmed = 0
  await page.route('**/api/v1/feature-flags', async (route) => {
    const response = await route.fetch()
    const body = (await response.json()) as { data: Record<string, unknown> }
    await route.fulfill({ response, json: { ...body, data: { ...body.data, assistant_enabled: true, assistant_provider: 'openai' } } })
  })
  await page.route('**/api/v1/assistant/conversations', (route) => route.fulfill({ json: { data: [] } }))
  await page.route('**/api/v1/assistant/chat/stream', (route) => {
    const answer = { type: 'answer', data: { answer: 'جهّزت صرف العمولة، راجع البطاقة.', citations: [], usage: { prompt: 1, completion: 1, total: 2 }, truncated: false, actions: [pending], conversation_id: null } }
    return route.fulfill({ status: 200, contentType: 'application/x-ndjson', body: [{ type: 'thinking' }, answer].map((line) => JSON.stringify(line)).join('\n') + '\n' })
  })
  await page.route('**/api/v1/assistant/actions/*/confirm', (route) => {
    confirmed += 1
    return route.fulfill({ json: { data: { ...pending, status: 'confirmed', result: { entity: 'commission', id: 'commission12345' } } } })
  })

  await page.goto('/')
  await page.getByRole('button', { name: /افتح المساعد|Open the assistant/ }).click()
  await page.locator('#assistant-input').fill('اصرف عمولة CM-000007 من الخزينة')
  await page.keyboard.press('Enter')

  const card = page.getByRole('region', { name: 'صرف عمولة' })
  await expect(card).toContainText('ستُصرف العمولة من الحساب المحدد')

  // First press only arms it; a warning appears and nothing ran.
  await card.getByRole('button', { name: /تأكيد وتنفيذ|Confirm and run/ }).click()
  await expect(card.getByRole('alert')).toContainText(/لا يمكن التراجع عنه بسهولة|hard to undo/)
  expect(confirmed).toBe(0)

  // Going back disarms it.
  await card.getByRole('button', { name: /تراجع|Go back/ }).click()
  await expect(card.getByRole('alert')).toHaveCount(0)
  expect(confirmed).toBe(0)

  // Arm again and confirm explicitly.
  await card.getByRole('button', { name: /تأكيد وتنفيذ|Confirm and run/ }).click()
  await card.getByRole('button', { name: /نعم، نفّذ الآن|Yes, run it now/ }).click()
  await expect(card.getByRole('status')).toContainText(/تم التنفيذ|Done/)
  expect(confirmed).toBe(1)
})
