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

export type Account = {
  id: string
  code: string
  name_ar: string
  name_en: string
  type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'
  parent_id: string | null
  is_postable: boolean
  is_active: boolean
  balance_minor?: number
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
}

export type Customer = {
  id: string
  type: 'individual' | 'company'
  name_ar: string
  name_en: string
  phone?: string
  email?: string
  tax_id?: string
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
  total_sell_minor?: number
  total_cost_minor?: number
}

export type Booking = {
  id: string
  number: string
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

export type Paginated<T> = {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

export type ApiError = {
  message: string
  code?: string
  errors?: Record<string, string[]>
  requestId?: string
}
