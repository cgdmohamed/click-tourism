import { Field, Input } from './ui'
import { autoCodeNote } from '../lib/codes'

/**
 * The code slot of a master-data form. The user never types a code: on create it is a short note that the
 * system generates it; on edit it is shown read-only (codes do not change after creation).
 */
export function CodeField({ code, label, ar }: { code?: string | null; label: string; ar: boolean }) {
  if (!code) return <p className="text-small text-text-subtle sm:col-span-2">{autoCodeNote(ar)}</p>
  return (
    <Field label={label}>
      <Input value={code} readOnly dir="ltr" className="tabular" />
    </Field>
  )
}
