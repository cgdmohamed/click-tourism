import { describe, expect, it } from 'vitest'
import { formAnswerText, isFormComplete, type AssistantForm } from './assistantForm'

const form: AssistantForm = {
  title: 'Which Ahmed?',
  fields: [
    {
      id: 'customer',
      label: 'Which customer?',
      type: 'radio',
      required: true,
      options: [
        { id: 'C-0001', label: 'Ahmed Ali' },
        { id: 'C-0002', label: 'Ahmed Samy' },
      ],
    },
    {
      id: 'extras',
      label: 'Services',
      type: 'checkbox',
      required: false,
      options: [
        { id: 'visa', label: 'Visa' },
        { id: 'flight', label: 'Flight' },
      ],
    },
    { id: 'note', label: 'Note', type: 'text', required: false },
  ],
}

describe('assistant form', () => {
  it('is incomplete until every required field is answered', () => {
    expect(isFormComplete(form, {})).toBe(false)
    expect(isFormComplete(form, { extras: ['visa'] })).toBe(false)
    expect(isFormComplete(form, { customer: 'C-0002' })).toBe(true)
  })

  it('treats a blank text answer as not answered', () => {
    expect(isFormComplete(form, { customer: '', note: '  ' })).toBe(false)
  })

  it('writes the answers with the chosen ids and skips empty optional fields', () => {
    const text = formAnswerText(form, { customer: 'C-0002', extras: ['visa', 'flight'], note: '  ' }, 'en')
    expect(text).toBe('My answers:\n- Which customer?: Ahmed Samy [id: C-0002]\n- Services: Visa [id: visa]، Flight [id: flight]')
  })

  it('uses the Arabic heading', () => {
    expect(formAnswerText(form, { customer: 'C-0001' }, 'ar').startsWith('إجاباتي:')).toBe(true)
  })

  it('carries a date and a number like a typed answer', () => {
    const when: AssistantForm = {
      fields: [
        { id: 'starts_on', label: 'Departure', type: 'date', required: true },
        { id: 'seats', label: 'Seats', type: 'number', required: false },
      ],
    }
    expect(isFormComplete(when, {})).toBe(false)
    expect(isFormComplete(when, { starts_on: '2026-12-01' })).toBe(true)
    expect(formAnswerText(when, { starts_on: '2026-12-01', seats: '20' }, 'en')).toBe('My answers:\n- Departure: 2026-12-01\n- Seats: 20')
  })

  it('counts a zero as an answer', () => {
    const kids: AssistantForm = { fields: [{ id: 'children', label: 'Children', type: 'number', required: true }] }
    expect(isFormComplete(kids, { children: '0' })).toBe(true)
    expect(formAnswerText(kids, { children: '0' }, 'en')).toBe('My answers:\n- Children: 0')
  })
})
