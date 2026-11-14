import { useI18n } from '../i18n'
import type { AppNotification } from './types'

/**
 * Presentation helpers for in-app notifications (GAP-CORE-01).
 *
 * The API sends a stable `type`; the UI localises known types and falls back to
 * the server-provided title so a new backend event still renders sensibly.
 */
export function useNotificationText() {
  const { t, locale } = useI18n()

  const labelFor = (notification: AppNotification) => {
    switch (notification.type) {
      case 'lead.reassigned':
        return t('notificationLeadReassigned')
      case 'lead.escalated':
        return t('notificationLeadEscalated')
      case 'invoice.approved':
        return t('notificationInvoiceApproved')
      case 'expense.approved':
        return t('notificationExpenseApproved')
      case 'expense.rejected':
        return t('notificationExpenseRejected')
      case 'refund.requested':
        return locale === 'ar' ? `طلب استرداد ${String(notification.data.number ?? '')} بانتظار اعتمادك`.trim() : `Refund ${String(notification.data.number ?? '')} is waiting for approval`.trim()
      case 'report_schedule.failed':
        return locale === 'ar' ? 'تعذّر إرسال تقرير مجدول' : 'A scheduled report could not be sent'
      default:
        return notification.title
    }
  }

  const targetFor = (notification: AppNotification) => {
    if (notification.type.startsWith('lead.')) return '/leads'
    if (notification.type === 'invoice.approved') {
      const id = notification.data.invoice_id
      return typeof id === 'string' ? `/invoices/${id}` : '/invoices'
    }
    if (notification.type.startsWith('expense.')) return '/expenses'
    if (notification.type === 'refund.requested') return '/approvals'
    if (notification.type === 'report_schedule.failed') return '/reports'
    return null
  }

  const formatWhen = (value: string) => {
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(date)
  }

  return { labelFor, targetFor, formatWhen }
}
