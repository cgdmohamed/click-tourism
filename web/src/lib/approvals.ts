/** One kind of record that waits for an approver. Every source reuses an existing list and approve endpoint. */
export type Source = {
  key: string
  permission: string
  list: string
  status: string
  /** Collection page, the destination for sources that have no page of their own per record. */
  page: string
  /** The record's own page, when one exists. */
  detail?: (id: string) => string
  approvePath: (id: string) => string
  /** Present only where the API takes a rejection reason. */
  rejectPath?: (id: string) => string
  label: { ar: string; en: string }
  action: { ar: string; en: string }
}

export const SOURCES: Source[] = [
  {
    key: 'expenses',
    permission: 'expenses.approve',
    list: '/expenses',
    status: 'submitted',
    page: '/expenses',
    detail: (id) => `/expenses/${encodeURIComponent(id)}`,
    approvePath: (id) => `/expenses/${id}/approve`,
    rejectPath: (id) => `/expenses/${id}/reject`,
    label: { ar: 'مصروفات', en: 'Expenses' },
    action: { ar: 'اعتماد', en: 'Approve' },
  },
  {
    key: 'documents',
    permission: 'documents.approve',
    list: '/accounting-documents',
    status: 'submitted',
    page: '/documents',
    detail: (id) => `/documents/${encodeURIComponent(id)}`,
    approvePath: (id) => `/accounting-documents/${id}/approve`,
    rejectPath: (id) => `/accounting-documents/${id}/reject`,
    label: { ar: 'سندات', en: 'Vouchers' },
    action: { ar: 'اعتماد', en: 'Approve' },
  },
  {
    key: 'invoices-submitted',
    permission: 'invoices.approve',
    list: '/invoices',
    status: 'submitted',
    page: '/invoices',
    detail: (id) => `/invoices/${encodeURIComponent(id)}`,
    approvePath: (id) => `/invoices/${id}/approve`,
    label: { ar: 'فواتير بانتظار الاعتماد', en: 'Invoices to approve' },
    action: { ar: 'اعتماد', en: 'Approve' },
  },
  {
    key: 'invoices-approved',
    permission: 'invoices.approve',
    list: '/invoices',
    status: 'approved',
    page: '/invoices',
    detail: (id) => `/invoices/${encodeURIComponent(id)}`,
    approvePath: (id) => `/invoices/${id}/issue`,
    label: { ar: 'فواتير بانتظار الإصدار', en: 'Invoices to issue' },
    action: { ar: 'إصدار', en: 'Issue' },
  },
  {
    key: 'commissions',
    permission: 'commissions.approve',
    list: '/commissions',
    status: 'accrued',
    page: '/commissions',
    approvePath: (id) => `/commissions/${id}/approve`,
    rejectPath: (id) => `/commissions/${id}/reject`,
    label: { ar: 'عمولات', en: 'Commissions' },
    action: { ar: 'اعتماد', en: 'Approve' },
  },
]

/** Refunds need an exchange rate decision, so they are listed and opened on the payments page. */
export const REFUND_SOURCE = { key: 'refunds', permission: 'refunds.approve', status: 'requested' }

export type Row = {
  id: string
  number?: string
  total_minor?: number
  amount_minor?: number
  currency?: string
  status?: string
  expense_date?: string
  issue_date?: string
  accrued_on?: string
  submitted_at?: string | null
  payee_name?: string | null
  memo?: string | null
  description?: string
  reason?: string
  party?: { name_ar: string; name_en: string } | null
}

/** Display amount of a row; sources name it differently. */
export function rowAmount(row: Row): number {
  return row.total_minor ?? row.amount_minor ?? 0
}

export function rowDate(row: Row): string | null {
  return row.submitted_at ?? row.expense_date ?? row.issue_date ?? row.accrued_on ?? null
}

/** Sources the user may act on; the API stays the authority. */
/** Where the record number leads: its own page when the source has one, else the collection. */
export function recordHref(source: Pick<Source, 'page' | 'detail'>, id: string): string {
  return source.detail ? source.detail(id) : source.page
}

export function visibleSources(can: (permission: string) => boolean): Source[] {
  return SOURCES.filter((source) => can(source.permission))
}

