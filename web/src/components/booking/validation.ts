import type { BookingFormState, DraftItem } from './types'

export type ItemErrors = Partial<Record<'description' | 'quantity' | 'unit_cost' | 'unit_sell', string>>

export type BookingErrors = {
  customer?: string
  start_date?: string
  end_date?: string
  pax_adults?: string
  pax_children?: string
  pax_infants?: string
  items?: string
  lines: ItemErrors[]
}

/** What is still missing, shown beside Save and used to jump to the field. */
export type MissingEntry = { key: string; label: string; target: string }

// Same cleaning as toMinor(): thousands commas are ignored, a negative sign is not allowed (API: min 0).
const amountPattern = { test: (value: string) => /^\d+(\.\d+)?$/.test(value.replace(/,/g, '').trim()) }

/**
 * Client-side mirror of the API rules for POST/PATCH /bookings (BookingController): the same required
 * fields and minimums, so the person sees the reason before the request instead of after it.
 */
export function validateBooking(form: BookingFormState, items: DraftItem[], ar: boolean): { errors: BookingErrors; missing: MissingEntry[]; valid: boolean } {
  const L = (a: string, e: string) => (ar ? a : e)
  const errors: BookingErrors = { lines: items.map(() => ({})) }
  const missing: MissingEntry[] = []
  const miss = (key: string, label: string, target: string) => missing.push({ key, label, target })

  if (!form.customer_id) {
    errors.customer = L('اختر العميل.', 'Select a customer.')
    miss('customer', L('العميل', 'Customer'), 'bf-customer')
  }
  if (!form.start_date) {
    errors.start_date = L('تاريخ البداية مطلوب.', 'The start date is required.')
    miss('start_date', L('تاريخ البداية', 'Start date'), 'bf-start-date')
  }
  if (form.start_date && form.end_date && form.end_date < form.start_date) {
    errors.end_date = L('تاريخ النهاية يجب ألا يسبق تاريخ البداية.', 'The end date cannot be before the start date.')
    miss('end_date', L('تاريخ النهاية', 'End date'), 'bf-end-date')
  }
  const adults = Number(form.pax_adults)
  if (!Number.isInteger(adults) || adults < 1) {
    errors.pax_adults = L('بالغ واحد على الأقل.', 'At least one adult.')
    miss('pax_adults', L('عدد البالغين', 'Adults'), 'bf-adults')
  }
  for (const [key, id, label] of [['pax_children', 'bf-children', L('أطفال', 'Children')], ['pax_infants', 'bf-infants', L('رضع', 'Infants')]] as const) {
    const value = Number(form[key])
    if (!Number.isInteger(value) || value < 0) {
      errors[key] = L('رقم صحيح من صفر أو أكثر.', 'A whole number, zero or more.')
      miss(key, label, id)
    }
  }
  if (items.length === 0) {
    errors.items = L('أضف خدمة واحدة على الأقل.', 'Add at least one service.')
    miss('items', L('خدمة واحدة على الأقل', 'At least one service'), 'bf-add-line')
  }
  items.forEach((item, index) => {
    const line = errors.lines[index]
    const name = L(`الخدمة ${index + 1}`, `Service ${index + 1}`)
    if (!item.description.trim()) {
      line.description = L('الوصف مطلوب.', 'The description is required.')
      miss(`d${index}`, `${name}: ${L('الوصف', 'description')}`, `bf-item-${item.uid}-description`)
    } else if (item.description.length > 255) {
      line.description = L('الوصف طويل جدًا (255 حرفًا كحد أقصى).', 'The description is too long (255 characters max).')
      miss(`d${index}`, `${name}: ${L('الوصف', 'description')}`, `bf-item-${item.uid}-description`)
    }
    const quantity = Number(item.quantity)
    if (!/^\d+$/.test(item.quantity.trim()) || !Number.isInteger(quantity) || quantity < 1) {
      line.quantity = L('رقم صحيح 1 أو أكثر.', 'A whole number, 1 or more.')
      miss(`q${index}`, `${name}: ${L('الكمية', 'quantity')}`, `bf-item-${item.uid}-quantity`)
    }
    if (!amountPattern.test(item.unit_cost.trim())) {
      line.unit_cost = L('أدخل مبلغًا صحيحًا (صفر أو أكثر).', 'Enter a valid amount (zero or more).')
      miss(`c${index}`, `${name}: ${L('تكلفة الوحدة', 'unit cost')}`, `bf-item-${item.uid}-unit-cost`)
    }
    if (!amountPattern.test(item.unit_sell.trim())) {
      line.unit_sell = L('أدخل مبلغًا صحيحًا (صفر أو أكثر).', 'Enter a valid amount (zero or more).')
      miss(`p${index}`, `${name}: ${L('سعر الوحدة', 'unit price')}`, `bf-item-${item.uid}-unit-sell`)
    }
  })

  return { errors, missing, valid: missing.length === 0 }
}

/** Moves focus to the first control inside the element with this id. */
export function focusField(id: string): void {
  const root = document.getElementById(id)
  if (!root) return
  const target = root.matches('input,button,select,textarea') ? root : root.querySelector<HTMLElement>('input,button,select,textarea')
  ;(target ?? root).focus()
  root.scrollIntoView({ block: 'center', behavior: 'smooth' })
}
