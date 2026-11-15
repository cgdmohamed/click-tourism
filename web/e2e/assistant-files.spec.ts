import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/**
 * ADR-0033: the chat takes files by picker, drag and drop and paste. Only JPG, PNG, Word and Excel are
 * accepted, several at once, each with a preview that can be removed before sending; a sent image shows as a
 * small picture that opens full size.
 */
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='

async function open(page: Page) {
  await uiLogin(page, apiSession())
  let n = 0
  const sent: Array<{ attachments?: string[]; messages?: Array<{ content: string }> }> = []
  await page.route('**/api/v1/feature-flags', async (route) => {
    const response = await route.fetch()
    const body = (await response.json()) as { data: Record<string, unknown> }
    await route.fulfill({ response, json: { ...body, data: { ...body.data, assistant_enabled: true, assistant_provider: 'openai' } } })
  })
  await page.route('**/api/v1/assistant/conversations', (route) => route.fulfill({ json: { data: [] } }))
  await page.route('**/api/v1/assistant/uploads', (route) => {
    n += 1
    const name = /filename="([^"]+)"/.exec(route.request().postData() ?? '')?.[1] ?? `file-${n}`
    return route.fulfill({ status: 201, json: { data: { id: `01HUPLOAD${String(n).padStart(17, '0')}`, name, mime: 'image/png', size_bytes: 70, expires_at: '2099-01-01T00:00:00Z' } } })
  })
  await page.route('**/api/v1/assistant/chat/stream', (route) => {
    sent.push(route.request().postDataJSON())
    const answer = { type: 'answer', data: { answer: 'قرأت الملفات.', citations: [], usage: { prompt: 1, completion: 1, total: 2 }, truncated: false, actions: [], conversation_id: null } }
    return route.fulfill({ status: 200, contentType: 'application/x-ndjson', body: [{ type: 'thinking' }, answer].map((line) => JSON.stringify(line)).join('\n') + '\n' })
  })
  await page.goto('/')
  await page.getByRole('button', { name: /افتح المساعد|Open the assistant/ }).click()
  await expect(page.locator('#assistant-input')).toBeVisible()
  return sent
}

const png = (name: string) => ({ name, mimeType: 'image/png', buffer: Buffer.from(PNG, 'base64') })

test('several images are picked together, previewed and removed before sending', async ({ page }) => {
  await open(page)
  await page.locator('input[type="file"]').setInputFiles([png('a.png'), png('b.png'), png('c.png')])

  const strip = page.getByRole('list', { name: /إرفاق ملف|Attach a file/ })
  await expect(strip.getByRole('img')).toHaveCount(3)
  await page.getByRole('button', { name: /إزالة الملف: b.png|Remove the file: b.png/ }).click()
  await expect(strip.getByRole('img')).toHaveCount(2)
  await expect(strip.getByRole('img', { name: 'b.png' })).toHaveCount(0)

  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
})

test('a pasted screenshot is attached, and a sent image shows small and opens full size', async ({ page }) => {
  const sent = await open(page)
  await page.locator('#assistant-input').evaluate((el, b64) => {
    const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
    const data = new DataTransfer()
    data.items.add(new File([bytes], 'image.png', { type: 'image/png' }))
    el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }))
  }, PNG)
  await expect(page.getByRole('list', { name: /إرفاق ملف|Attach a file/ }).getByRole('img')).toHaveCount(1)

  await page.locator('#assistant-input').fill('اقرأ هذه الصورة')
  await page.keyboard.press('Enter')
  await expect(page.getByText('قرأت الملفات.')).toBeVisible()
  expect(sent[0].attachments).toHaveLength(1)

  // In the sent message the picture replaces the "[name]" text.
  const log = page.getByRole('log')
  await expect(log.getByText(/\[screenshot/)).toHaveCount(0)
  await log.getByRole('button', { name: /تكبير الصورة|Enlarge the image/ }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByRole('img')).toBeVisible()
  await dialog.getByRole('button', { name: /^(إغلاق|Close)$/ }).last().click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
})

test('files are dropped onto the chat, with a hint while dragging', async ({ page }) => {
  await open(page)
  const region = page.getByRole('region', { name: /المساعد|Assistant/ }).first()
  const drop = (type: string, name: string, mime: string) =>
    region.evaluate(
      (el, args) => {
        const [eventType, fileName, fileMime, b64] = args as [string, string, string, string]
        const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
        const data = new DataTransfer()
        data.items.add(new File([bytes], fileName, { type: fileMime }))
        el.dispatchEvent(new DragEvent(eventType, { dataTransfer: data, bubbles: true, cancelable: true }))
      },
      [type, name, mime, PNG],
    )

  await drop('dragenter', 'sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
  await expect(page.getByTestId('drop-overlay')).toBeVisible()
  await drop('drop', 'sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
  await expect(page.getByTestId('drop-overlay')).toHaveCount(0)
  await expect(page.getByRole('list', { name: /إرفاق ملف|Attach a file/ }).getByText('sheet.xlsx')).toBeVisible()
})

test('anything but JPG, PNG, Word and Excel is refused, and so is a sixth file', async ({ page }) => {
  await open(page)
  const input = page.locator('input[type="file"]')
  await input.setInputFiles({ name: 'scan.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') })
  await expect(page.getByRole('alert').filter({ hasText: /JPG|PNG/ })).toBeVisible()
  await input.setInputFiles({ name: 'photo.webp', mimeType: 'image/webp', buffer: Buffer.from('x') })
  await expect(page.getByRole('alert').filter({ hasText: /JPG|PNG/ })).toBeVisible()
  await expect(page.getByRole('list', { name: /إرفاق ملف|Attach a file/ })).toHaveCount(0)

  await input.setInputFiles(['1', '2', '3', '4', '5', '6'].map((i) => png(`p${i}.png`)))
  await expect(page.getByRole('list', { name: /إرفاق ملف|Attach a file/ }).getByRole('img')).toHaveCount(5)
  await expect(page.getByRole('alert').filter({ hasText: /5/ })).toBeVisible()
})
