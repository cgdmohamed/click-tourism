import { todayISO } from '../../lib/format'

/** One editable service line of the booking form (amounts are decimal strings as typed). */
export type DraftItem = {
  /** Client-only identity so duplicating/removing a card never mixes up field state; never sent to the API. */
  uid: string
  service_type: string
  supplier_id: string
  description: string
  starts_on: string
  quantity: string
  unit_cost: string
  unit_sell: string
  cost_currency: string
  sell_currency: string
}

export type BookingFormState = {
  customer_id: string
  tour_departure_id: string
  start_date: string
  end_date: string
  pax_adults: string
  pax_children: string
  pax_infants: string
  currency: string
  fx_rate: string
  notes: string
}

export type ItemAmounts = { cost: number; tax: number; sell: number }

let counter = 0
const nextUid = () => `line-${Date.now().toString(36)}-${(counter += 1)}`

export const emptyItem = (currency: string): DraftItem => ({
  uid: nextUid(),
  service_type: 'flight',
  supplier_id: '',
  description: '',
  starts_on: todayISO(),
  quantity: '1',
  unit_cost: '0',
  unit_sell: '0',
  cost_currency: currency,
  sell_currency: currency,
})

export const withUid = (item: Omit<DraftItem, 'uid'>): DraftItem => ({ ...item, uid: nextUid() })

export const SERVICE_TYPES = ['flight', 'hotel', 'transport', 'visa', 'program', 'other'] as const
