import { useState, type FormEvent } from 'react'
import { useI18n } from '../i18n'
import { formAnswerText, isFormComplete, type AssistantForm, type FormAnswers } from '../lib/assistantForm'
import { Button, Textarea } from './ui'

/**
 * A short form the assistant shows instead of guessing (ADR-0029): radio buttons, checkboxes and a text
 * field. Sending it turns the answers into the user's next message; after that it is read-only.
 */
export function AssistantFormCard({ form, answered, onSubmit }: { form: AssistantForm; answered: boolean; onSubmit: (text: string) => void }) {
  const { t, locale } = useI18n()
  const [answers, setAnswers] = useState<FormAnswers>({})
  const complete = isFormComplete(form, answers)
  // One question with one answer needs no button: picking the option sends it.
  const instant = form.fields.length === 1 && form.fields[0].type === 'radio'

  function toggle(fieldId: string, optionId: string, checked: boolean) {
    setAnswers((current) => {
      const list = Array.isArray(current[fieldId]) ? (current[fieldId] as string[]) : []
      return { ...current, [fieldId]: checked ? [...list, optionId] : list.filter((id) => id !== optionId) }
    })
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    if (answered || !complete) return
    onSubmit(formAnswerText(form, answers, locale))
  }

  return (
    <form
      onSubmit={submit}
      aria-label={form.title || t('assistantFormTag')}
      className="anim-rise flex w-full max-w-md flex-col gap-4 rounded-16 border border-border-subtle bg-surface-default p-4 shadow-1"
    >
      <p className="text-small font-semibold text-text-default">{form.title || t('assistantFormTag')}</p>
      {form.fields.map((field) => (
        <fieldset key={field.id} disabled={answered} className="flex min-w-0 flex-col gap-2">
          <legend className="text-small mb-1 font-semibold text-text-default">
            {field.label}
            {!field.required ? <span className="ms-2 font-normal text-text-subtle">({t('assistantFormOptional')})</span> : null}
          </legend>
          {field.type === 'text' ? (
            <Textarea
              rows={2}
              value={typeof answers[field.id] === 'string' ? (answers[field.id] as string) : ''}
              placeholder={field.placeholder}
              aria-label={field.label}
              maxLength={500}
              onChange={(event) => setAnswers((current) => ({ ...current, [field.id]: event.target.value }))}
            />
          ) : (
            (field.options ?? []).map((option) => {
              const value = answers[field.id]
              const checked = Array.isArray(value) ? value.includes(option.id) : value === option.id
              return (
                <label key={option.id} className="text-medium flex cursor-pointer items-start gap-3 rounded-8 px-1 py-1 text-text-default hover:bg-surface-hovered">
                  <input
                    type={field.type}
                    name={`${field.id}`}
                    value={option.id}
                    checked={checked}
                    onChange={(event) => {
                      if (field.type !== 'radio') return toggle(field.id, option.id, event.target.checked)
                      setAnswers((current) => ({ ...current, [field.id]: option.id }))
                      if (instant && !answered) onSubmit(formAnswerText(form, { [field.id]: option.id }, locale))
                    }}
                    className="mt-1 size-4 shrink-0"
                  />
                  <span className="flex min-w-0 flex-col">
                    <span className="break-words">{option.label}</span>
                    {option.hint ? <span className="text-small break-words text-text-subtle">{option.hint}</span> : null}
                  </span>
                </label>
              )
            })
          )}
        </fieldset>
      ))}
      {instant && !answered ? null : (
        <Button type="submit" disabled={answered || !complete}>
          {answered ? t('assistantFormAnswered') : t('assistantFormSubmit')}
        </Button>
      )}
    </form>
  )
}
