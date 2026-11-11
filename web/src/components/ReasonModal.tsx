import { useEffect, useState } from 'react'
import { useI18n } from '../i18n'
import { Button, Field, Modal, Textarea } from './ui'

/** Asks for a mandatory reason before a document is cancelled/reversed. */
export function ReasonModal({
  open,
  title,
  description,
  confirmLabel,
  pending,
  error,
  onClose,
  onConfirm,
}: {
  open: boolean
  title: string
  description?: string
  confirmLabel: string
  pending: boolean
  error: string
  onClose: () => void
  onConfirm: (reason: string) => void
}) {
  const { t, locale } = useI18n()
  const [reason, setReason] = useState('')

  useEffect(() => {
    if (open) setReason('')
  }, [open])

  return (
    <Modal
      open={open}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button disabled={pending || reason.trim() === ''} onClick={() => onConfirm(reason.trim())}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      {description ? <p className="mb-4 text-medium text-text-subtle">{description}</p> : null}
      <Field label={locale === 'ar' ? 'السبب' : 'Reason'} required>
        <Textarea value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} />
      </Field>
      {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
    </Modal>
  )
}
