/** A short form the assistant asks the user to answer instead of guessing (ADR-0029). */
export type FormOption = { id: string; label: string; hint?: string }

export type FormField = {
  id: string
  label: string
  type: 'radio' | 'checkbox' | 'text' | 'date' | 'number'
  required: boolean
  placeholder?: string
  options?: FormOption[]
}

export type AssistantForm = { title?: string; fields: FormField[] }

/** radio: one option id, checkbox: several option ids, text, date (YYYY-MM-DD) and number: the entered value. */
export type FormAnswers = Record<string, string | string[]>

function answered(field: FormField, answers: FormAnswers): boolean {
  const value = answers[field.id]
  if (field.type === 'checkbox') return Array.isArray(value) && value.length > 0
  return typeof value === 'string' && value.trim() !== ''
}

/** Every required field has an answer (an optional field may stay empty). */
export function isFormComplete(form: AssistantForm, answers: FormAnswers): boolean {
  return form.fields.every((field) => !field.required || answered(field, answers)) && form.fields.some((field) => answered(field, answers))
}

/**
 * The answers as the user's next chat message: readable in the conversation, and carrying the ids the
 * assistant needs ("[id: C-0001]"), so the choice is exact and not a name match.
 */
export function formAnswerText(form: AssistantForm, answers: FormAnswers, locale: 'ar' | 'en'): string {
  const lines = form.fields
    .filter((field) => answered(field, answers))
    .map((field) => {
      const value = answers[field.id]
      if (field.type === 'text' || field.type === 'date' || field.type === 'number') return `- ${field.label}: ${String(value).trim()}`
      const chosen = (field.options ?? []).filter((option) => (Array.isArray(value) ? value.includes(option.id) : value === option.id))
      return `- ${field.label}: ${chosen.map((option) => `${option.label} [id: ${option.id}]`).join('، ')}`
    })

  return `${locale === 'ar' ? 'إجاباتي:' : 'My answers:'}\n${lines.join('\n')}`
}
