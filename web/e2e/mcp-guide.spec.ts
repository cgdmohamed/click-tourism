import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { apiSession, uiLogin } from './support/session'

/** ADR-0024: Settings > MCP shows setup instructions for Claude and OpenAI, with snippets that never hold a real token. */
test('the MCP tab explains how to connect Claude and OpenAI', async ({ page }) => {
  await uiLogin(page, apiSession())
  await page.goto('/settings?tab=mcp')

  await expect(page.getByRole('tab', { name: 'MCP' })).toBeVisible()
  await expect(page.getByText(/Claude Desktop/).first()).toBeVisible()
  await expect(page.locator('pre', { hasText: 'claude_desktop_config' }).or(page.locator('pre', { hasText: 'mcpServers' })).first()).toContainText('<YOUR_MCP_TOKEN>')

  await page.getByRole('textbox', { name: /عنوان الـgateway|Gateway address/ }).first().fill('https://mcp.example.com')
  await expect(page.locator('pre', { hasText: 'claude mcp add' })).toContainText('https://mcp.example.com/mcp')

  await page.getByRole('button', { name: 'OpenAI', exact: true }).click()
  await expect(page.locator('pre', { hasText: 'api.openai.com/v1/responses' })).toContainText('"server_url": "https://mcp.example.com/mcp"')

  if (process.env.E2E_SHOT) await page.screenshot({ path: process.env.E2E_SHOT, fullPage: true })

  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
})
