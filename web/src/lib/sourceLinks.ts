export type SourceLinkInput = {
  source_type?: string | null
  source_reference?: string | null
  source_public_id?: string | null
}

export type SourceLink = { href: string; permission?: string }

/**
 * Page of the source document that produced a posted journal line (GAP-RPT-04).
 * Invoices and bookings open the record itself; payments are pre-filtered by
 * number; other source types link to their owning module's list. Null when the
 * line has no known source page (manual entries, unknown types).
 */
export function sourceDocumentLink(line: SourceLinkInput): SourceLink | null {
  const reference = line.source_reference ?? ''
  const publicId = line.source_public_id ?? ''
  const search = reference ? `?search=${encodeURIComponent(reference)}` : ''

  switch (line.source_type) {
    case 'invoice':
      return publicId ? { href: `/invoices/${publicId}`, permission: 'invoices.view' } : null
    case 'booking':
      return { href: publicId ? `/bookings?booking_id=${publicId}` : `/bookings${search}` }
    case 'payment':
    case 'refund':
      return { href: `/payments${search}` }
    case 'supplier_payment':
      return { href: '/supplier-settlements' }
    case 'expense':
      return { href: '/expenses', permission: 'expenses.view' }
    case 'accounting_document':
      return { href: '/documents', permission: 'documents.view' }
    case 'accounting_schedule':
      return { href: '/schedules', permission: 'schedules.view' }
    case 'fixed_asset':
    case 'fixed_asset_depreciation_run':
      return { href: '/fixed-assets', permission: 'assets.view' }
    case 'transfer':
      return { href: '/treasury', permission: 'accounts.view' }
    default:
      return null
  }
}
