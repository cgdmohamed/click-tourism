export type Money = {
  amount: string
  amountMinor: number
  currency: string
}

export type Branch = {
  id: string
  code: string
  name_ar: string
  name_en: string
  accounting_mode: 'dimension' | 'customer' | 'independent'
  is_active: boolean
}

export type User = {
  id: string
  name: string
  email: string
  locale: 'ar' | 'en'
  roles: string[]
  permissions: string[]
  branches: Branch[]
}

export type AccountClassification =
  | 'current_asset'
  | 'non_current_asset'
  | 'fixed_asset'
  | 'prepayment'
  | 'current_liability'
  | 'non_current_liability'
  | 'equity'

export type Account = {
  id: string
  code: string
  name_ar: string
  name_en: string
  type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'
  parent_id: string | null
  is_postable: boolean
  is_active: boolean
  /** Balance-sheet class; null = unclassified. Only for asset/liability/equity. */
  classification?: AccountClassification | null
  balance_minor?: number
  /** Set when the account is the receivable/payable control account. */
  control_party_type?: 'customer' | 'supplier' | null
}

export type JournalLine = {
  id: string
  account_id: string
  account?: Account
  debit_minor: number
  credit_minor: number
  description?: string
}

export type JournalEntry = {
  id: string
  number: string
  entry_date: string
  status: 'draft' | 'posted'
  memo?: string
  currency: string
  total_minor: number
  lines?: JournalLine[]
  reversal_of_id?: string | null
  reversed_by_id?: string | null
  created_by_id?: string | null
  source_type?: string | null
}

export type Refund = {
  id: string
  number: string
  payment_id: string | null
  booking_id: string | null
  amount_minor: number
  currency: string
  reason: string
  status: string
  approved_at: string | null
}

export type Customer = {
  id: string
  type: 'individual' | 'company'
  name_ar: string
  name_en: string
  phone?: string
  email?: string
  tax_id?: string
  credit_limit_minor: number
  currency: string
  branch_id: string
  balance_minor?: number
  is_active: boolean
}

export type Supplier = {
  id: string
  category: string
  name_ar: string
  name_en: string
  phone?: string
  email?: string
  settlement_currency: string
  branch_id: string
  balance_minor?: number
  is_active: boolean
}

export type PaymentMethod = {
  id: string
  code: string
  name_ar: string
  name_en: string
  type: string
  default_cash_account_id: string | null
  provider_name: string | null
  account_name: string | null
  account_reference: string | null
  iban: string | null
  instructions: string | null
  default_cash_account: { id: string; code: string; name_ar: string; name_en: string; currency: string; is_active: boolean } | null
  is_active: boolean
}

export type BookingItem = {
  id?: string
  service_type: 'flight' | 'hotel' | 'transport' | 'visa' | 'program' | 'other'
  supplier_id?: string | null
  description: string
  starts_on?: string
  ends_on?: string
  quantity: number
  unit_cost_minor: number
  unit_sell_minor: number
  cost_currency: string
  sell_currency: string
  tax_profile_id?: string | null
  tax_profile_name?: string | null
  tax_rate?: string
  tax_minor?: number
  supplier_name_ar?: string | null
  supplier_name_en?: string | null
  total_sell_minor?: number
  total_cost_minor?: number
}

export type TaxProfile = {
  id: string
  name: string
  rate: string
  is_inclusive: boolean
}

export type BookingStatusHistoryRow = {
  from: string | null
  to: string
  reason: string | null
  at: string | null
}

export type Booking = {
  id: string
  number: string
  tour_departure_id?: string | null
  customer_id: string
  customer?: Customer
  branch_id: string
  owner_user_id: string
  status: 'draft' | 'quoted' | 'confirmed' | 'in_progress' | 'completed' | 'closed' | 'cancelled' | 'no_show'
  start_date: string
  end_date?: string
  pax_adults: number
  pax_children: number
  pax_infants: number
  currency: string
  fx_rate: string
  total_sell_minor: number
  total_cost_minor: number
  margin_minor: number
  tax_minor?: number
  paid_minor?: number
  balance_minor?: number
  notes?: string | null
  cancellation_reason?: string | null
  cancellation_fee_minor?: number
  margin_approval_status?: 'pending' | 'approved' | null
  margin_approval_reason?: string | null
  margin_requested_by?: string | null
  allowed_transitions?: string[]
  status_history?: BookingStatusHistoryRow[]
  payments_count?: number
  invoices_count?: number
  items?: BookingItem[]
}

export type Payment = {
  id: string
  number: string
  customer_id: string
  customer?: Customer
  booking_id?: string | null
  branch_id: string
  method: 'cash' | 'bank_transfer' | 'card' | 'wallet'
  payment_method_id: string | null
  payment_method?: PaymentMethod
  cash_account_id: string
  amount_minor: number
  unallocated_minor: number
  currency: string
  paid_at: string
  status: string
}

export type SupplierObligation = {
  id: string
  supplier_id?: string | null
  supplier?: Supplier
  booking_item_id?: string | null
  branch_id?: string
  amount_minor: number
  paid_minor: number
  balance_minor: number
  currency: string
  invoice_reference?: string | null
  invoice_id?: string | null
  accounting_status?: 'recognized' | 'unposted'
  status: 'open' | 'partially_paid' | 'paid'
}

export type SupplierPayment = {
  id: string
  supplier_id?: string | null
  supplier?: Supplier
  supplier_obligation_id?: string | null
  branch_id?: string
  amount_minor: number
  applied_minor: number
  unallocated_minor: number
  currency: string
  cash_account_id?: string | null
  fx_rate: string
  paid_at?: string | null
  status: string
}

export type Lead = {
  id: string
  name: string
  phone?: string
  email?: string
  source: string
  stage: string
  owner_user_id?: string | null
  branch_id: string
  status: string
  created_at: string
  last_activity_at?: string | null
}

export type AssignmentRule = {
  id: string
  priority: number
  branch_id: string | null
  source_id: string | null
  service_category: string | null
  strategy: string
  target_user_id: string | null
  /** Public reference of the target user (target_user_id is the internal id). */
  target_user?: { id: string; name: string } | null
  max_leads_per_user: number | null
  is_active: boolean
}

export type CashAccountRow = {
  id: string
  name_ar: string
  name_en: string
  kind: string
  currency: string
  balance_minor: number
}

export type AgingBucket = {
  key: string
  label_en: string
  label_ar: string
  amount_minor: number
}

export type MonthlyCashFlow = {
  month: string
  inflow_minor: number
  outflow_minor: number
  net_minor: number
}

export type AttentionItem = {
  id: string
  type: string
  severity: 'high' | 'medium' | 'low'
  amount_minor?: number
  currency?: string | null
  due_date?: string | null
  date?: string | null
  days_overdue?: number
  title: string
  party_name?: string | null
  url: string
  action_url: string
  action_label: string
}

export type WorkQueueItem = {
  id: string
  type: string
  document_type: string
  document_id: string
  document_number: string
  amount_minor: number
  currency: string
  due_date: string
  priority: 'high' | 'medium' | 'low'
  action_url: string
  action_label: string
}

export type DashboardSummary = {
  customers_count: number
  bookings_count: number
  invoices_count: number
  tickets_count: number
  open_bookings: number
  confirmed_bookings: number
  collected_minor: number
  overdue_minor: number
  currency: string
  recent_bookings: Booking[]
  recent_payments: Payment[]
  cash_balance_minor: number | null
  cash_balance_mixed_currency: boolean
  cash_accounts: CashAccountRow[]
  ar_outstanding_minor: number
  ap_outstanding_minor: number
  overdue_ar_minor: number
  overdue_ap_minor: number
  customer_advances_minor: number
  unreconciled_bank_count: number
  net_profit_minor: number
  aging_buckets: AgingBucket[]
  monthly_cash_flow: MonthlyCashFlow[]
  monthly_profit_loss: Array<{ month: string; revenue_minor: number; expenses_minor: number }>
  period_from: string
  period_to: string
  attention_items: AttentionItem[]
  as_of: string
}

export type Paginated<T> = {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

export type SearchItem = {
  id: string
  title_ar: string
  title_en: string
  subtitle?: string
}

export type SearchGroup = {
  type: 'customers' | 'suppliers' | 'bookings' | 'payments' | 'leads'
  items: SearchItem[]
}

export type SearchResults = {
  groups: SearchGroup[]
  total: number
}

export type ApiError = {
  message: string
  code?: string
  errors?: Record<string, string[]>
  requestId?: string
}

/** Full user-management view of an account (GET /users, GET /users/{id}). */
export type UserAccount = {
  id: string
  name: string
  email: string
  locale: 'ar' | 'en'
  is_active: boolean
  is_available_for_leads: boolean
  roles: string[]
  branches: Branch[]
  primary_branch_id: string | null
  employee: { id: string; internal_code: string; name_ar: string; name_en: string; category: string } | null
  last_login_at: string | null
  created_at: string
  permissions?: string[]
}

/** A role as returned by GET /roles (Spatie-style shape). */
export type RoleRecord = {
  id: number
  name: string
  guard: string
  permissions: string[]
  is_system: boolean
  users_count: number
}

export type Employee = {
  id: string
  internal_code: string
  name_ar: string
  name_en: string
  category: 'staff' | 'executive' | 'driver' | 'other'
  branch_id?: string | null
  user_id?: string | number | null
  phone?: string | null
  email?: string | null
  is_active: boolean
  balance_minor?: number
}

export type EmployeeAdvance = {
  id: string
  employee_id: string
  branch_id?: string | null
  amount_minor: number
  outstanding_minor: number
  status: 'requested' | 'approved' | 'disbursed' | 'settled' | 'cancelled'
  cash_account_id?: string | null
  request_date: string
  disbursed_date?: string | null
  settled_date?: string | null
  memo?: string | null
}

export type EmployeeCustody = {
  id: string
  employee_id: string
  branch_id?: string | null
  amount_minor: number
  outstanding_minor: number
  status: 'issued' | 'cleared' | 'returned' | 'closed'
  cash_account_id?: string | null
  item_description?: string | null
  issue_date: string
  returned_date?: string | null
  memo?: string | null
}

export type EmployeeTransaction = {
  id: string
  employee_id: string
  branch_id?: string | null
  type: string
  reference?: string | null
  journal_entry_id?: string | null
  source_type?: string | null
  source_id?: number | null
  transaction_date: string
  currency: string
  fx_rate: string
  amount_minor: number
  balance_minor: number
  memo?: string | null
  created_by?: string | number | null
}

export type InvoiceLineRecord = {
  id?: string
  account_id?: string
  account?: Account | null
  description?: string | null
  quantity?: number | null
  unit_price_minor?: number | null
  debit_minor: number
  credit_minor: number
  tax_minor: number
}

export type InvoiceApplicationRecord = {
  id: string
  payment_id: string | null
  supplier_payment_id: string | null
  payment_number?: string | null
  amount_minor: number
  reversed_at: string | null
  created_at: string | null
}

export type InvoiceRecord = {
  applications?: InvoiceApplicationRecord[]
  id: string
  type: 'customer_invoice' | 'supplier_invoice' | 'credit_note'
  series: string
  number: string
  party_type: 'customer' | 'supplier'
  party_id: number
  party?: Customer | Supplier | null
  branch_id?: string | null
  booking_id?: string | number | null
  issue_date?: string | null
  due_date?: string | null
  currency: string
  fx_rate: string
  status: 'draft' | 'submitted' | 'approved' | 'issued' | 'voided'
  subtotal_minor: number
  tax_minor: number
  total_minor: number
  outstanding_minor: number
  journal_entry_id?: string | null
  credit_note_of_id?: string | null
  memo?: string | null
  approved_at?: string | null
  issued_at?: string | null
  lines?: InvoiceLineRecord[]
  credit_notes?: InvoiceRecord[]
}

export type StatementLine = {
  entry_id: string
  number: string
  entry_date: string
  memo?: string | null
  description?: string | null
  account_code?: string
  source_type?: string | null
  source_id?: number | null
  source_reference?: string | null
  source_public_id?: string | null
  event?: string | null
  party_type?: string | null
  party_id?: number | null
  party?: { name_en?: string | null; name_ar?: string | null; public_id?: string | null } | null
  currency?: string
  debit_minor: number
  credit_minor: number
  base_debit_minor: number
  base_credit_minor: number
  balance_minor: number
}

export type StatementDifference = {
  code: string
  label_en: string
  label_ar: string
  amount_minor: number
}

export type PartyStatement = {
  party_type: 'customer' | 'supplier' | 'employee'
  party: { id: string; name_ar: string; name_en: string }
  currency: string
  basis: 'original' | 'base'
  from: string
  to: string
  control_account: Account
  subaccounts: Array<{ id: string; party_type: string; party_id: number; role: string; account?: Account }>
  opening_minor: number
  total_debit_minor: number
  total_credit_minor: number
  closing_minor: number
  subaccount_closing_minor: number
  tagged_control_closing_minor: number
  reconciliation: {
    ledger_closing_minor: number
    normalized_ledger_minor: number
    operational_open_invoices_minor: number
    unapplied_credits_minor: number
    difference_minor: number
    reconciled: boolean
    differences: StatementDifference[]
  }
  lines: StatementLine[]
  total: number
}

export type CustomerProfile = {
  party_type: 'customer'
  party: Customer
  metrics: {
    bookings_count: number
    open_bookings_count: number
    invoices_count: number
    open_invoices_count: number
    payments_count: number
    last_activity_on?: string | null
  }
  credit?: {
    policy: 'off' | 'warn' | 'block'
    currency: string
    credit_limit_minor: number
    exposure_minor: number
  }
  bookings: Booking[]
  invoices: InvoiceRecord[]
  payments: Payment[]
  statement: PartyStatement
}

export type SupplierProfile = {
  party_type: 'supplier'
  party: Supplier
  metrics: {
    invoices_count: number
    open_invoices_count: number
    obligations_count: number
    open_obligations_count: number
    payments_count: number
    last_activity_on?: string | null
  }
  invoices: InvoiceRecord[]
  obligations: SupplierObligation[]
  payments: SupplierPayment[]
  statement: PartyStatement
}

export type EmployeeProfile = {
  party_type: 'employee'
  party: Employee
  employee: Employee
  user: { id: string; name: string; email: string } | null
  metrics: {
    advances_count: number
    open_advances_count: number
    custodies_count: number
    open_custodies_count: number
    transactions_count: number
    advances_outstanding_minor: number
    custody_outstanding_minor: number
    last_activity_on?: string | null
  }
  advances: EmployeeAdvance[]
  custodies: EmployeeCustody[]
  transactions: EmployeeTransaction[]
  statement: PartyStatement
}

export type ImportJobStatus = 'previewed' | 'queued' | 'running' | 'completed' | 'partial' | 'failed'

export type ImportRowError = {
  row: number
  field: string | null
  code: string
  message: string
  params?: Record<string, unknown>
}

export type ImportTemplateColumn = {
  key: string
  label_en: string
  label_ar: string
  type: string
  required: boolean
  allowed_values: string[] | null
  max_length: number | null
  example: string
  description_en: string
  description_ar: string
}

export type ImportTemplate = {
  entity: string
  version: number
  label_en: string
  label_ar: string
  supports_duplicate_policy: boolean
  default_duplicate_policy: 'skip' | 'update' | 'fail'
  atomic: boolean
  permissions: string[]
  columns: ImportTemplateColumn[]
  options: Array<{ key: string; label_en: string; label_ar: string; required: boolean }>
}

export type ImportTemplates = {
  limits: { max_file_bytes: number; max_rows: number; formats: string[] }
  templates: ImportTemplate[]
}

export type ImportPreviewRow = {
  row: number
  action: 'create' | 'update' | 'skip' | 'error'
  message?: string
  values: Record<string, unknown>
}

export type ImportJob = {
  id: string
  entity: string
  template_version: number
  status: ImportJobStatus
  mode: 'dry_run' | 'commit'
  duplicate_policy: 'skip' | 'update' | 'fail'
  on_error: 'skip' | 'abort'
  options: Record<string, unknown>
  original_name: string
  file_format: 'csv' | 'xlsx'
  file_size: number
  branch_id: string | null
  created_by: string | null
  total_rows: number
  created_count: number
  updated_count: number
  skipped_count: number
  error_count: number
  preview?: ImportPreviewRow[]
  errors?: ImportRowError[]
  warnings?: Array<{ code: string; columns?: string[] }>
  has_error_report: boolean
  failure_code: string | null
  failure_message: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
}

export type AuditActor = {
  id: string
  name: string
  email: string
}

export type AuditBranch = {
  id: string
  name: string
  name_ar: string
  name_en: string
}

export type AuditEvent = {
  id: string
  action: string
  actor: AuditActor | null
  entity_type: string | null
  entity_id: number | null
  branch: AuditBranch | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  ip: string | null
  request_id: string | null
  created_at: string | null
}

export type AuditEventList = {
  data: AuditEvent[]
  meta: {
    current_page: number
    last_page: number
    total: number
    per_page: number
  }
}

export type AuditFilters = {
  actor: string
  action: string
  entity_type: string
  entity_id: string
  branch: string
  from: string
  to: string
}
