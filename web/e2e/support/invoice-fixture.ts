import { expect, type APIRequestContext } from '@playwright/test'

export type E2EInvoice = { id: string; number: string }

type Credentials = { email: string; password: string }

const API = '/api/v1'

/**
 * Returns an invoice the browser can open, creating the minimum fixtures when
 * the target installation has none.
 *
 * Seeded demo data already contains invoices, so the happy path is a read.
 * A fresh non-demo install still gets a valid draft invoice: the chart of
 * accounts is part of every install, and a draft invoice needs no posting,
 * fiscal period or maker-checker approval.
 */
export async function ensureInvoice(request: APIRequestContext, credentials: Credentials): Promise<E2EInvoice> {
  const login = await request.post(`${API}/auth/login`, {
    data: { ...credentials, device_name: 'playwright' },
  })
  expect(login.ok(), `login failed with ${login.status()}`).toBeTruthy()
  const token = (await login.json()).data.token as string
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

  const me = await request.get(`${API}/auth/me`, { headers })
  expect(me.ok(), `profile failed with ${me.status()}`).toBeTruthy()
  const profile = (await me.json()).data as { branches?: Array<{ id: string }> }
  const branchId = profile.branches?.[0]?.id
  expect(branchId, 'the E2E user must belong to at least one branch').toBeTruthy()
  const branchHeaders = { ...headers, 'X-Branch-Id': branchId as string }

  const listed = await request.get(`${API}/invoices?per_page=1`, { headers: branchHeaders })
  expect(listed.ok(), `invoice list failed with ${listed.status()}`).toBeTruthy()
  const existing = (await listed.json()).data as E2EInvoice[]
  if (existing.length > 0) {
    return existing[0]
  }

  // 4100 is the seeded sales-revenue account used by the backend invoicing
  // tests. Fall back to the first postable account so an install with a
  // customized chart still works.
  const accountsResponse = await request.get(`${API}/accounts?search=4100`, { headers: branchHeaders })
  expect(accountsResponse.ok(), `account lookup failed with ${accountsResponse.status()}`).toBeTruthy()
  const accounts = (await accountsResponse.json()).data as Array<{ id: string; code: string; is_postable?: boolean }>
  const revenue = accounts.find((account) => account.code === '4100')
    ?? accounts.find((account) => account.is_postable !== false)
  expect(revenue, 'no postable revenue account exists to invoice against').toBeTruthy()

  const customersResponse = await request.get(`${API}/customers?per_page=1`, { headers: branchHeaders })
  expect(customersResponse.ok(), `customer list failed with ${customersResponse.status()}`).toBeTruthy()
  let customer = ((await customersResponse.json()).data as Array<{ id: string }>)[0]
  if (!customer) {
    const created = await request.post(`${API}/customers`, {
      headers: branchHeaders,
      data: {
        type: 'individual',
        name_ar: 'عميل اختبار الواجهة',
        name_en: 'Browser E2E Customer',
        branch_id: branchId,
      },
    })
    expect(created.ok(), `customer creation failed with ${created.status()}`).toBeTruthy()
    customer = (await created.json()).data as { id: string }
  }

  const invoice = await request.post(`${API}/invoices`, {
    headers: { ...branchHeaders, 'Idempotency-Key': `playwright-${Date.now()}` },
    data: {
      type: 'customer_invoice',
      party_type: 'customer',
      party_id: customer.id,
      issue_date: new Date().toISOString().slice(0, 10),
      currency: 'EGP',
      memo: 'Playwright invoice PDF flow',
      lines: [
        {
          account_id: revenue?.id,
          description: 'Playwright invoice line',
          quantity: 1,
          unit_price_minor: 10000,
          credit_minor: 10000,
        },
      ],
    },
  })
  expect(invoice.ok(), `invoice creation failed with ${invoice.status()}`).toBeTruthy()

  return (await invoice.json()).data as E2EInvoice
}
