export type ManagementReportKey = 'profitability' | 'sales' | 'commissions' | 'tax'

export const MANAGEMENT_REPORTS: ManagementReportKey[] = ['profitability', 'sales', 'commissions', 'tax']

export const isManagementReport = (key: string): key is ManagementReportKey =>
  (MANAGEMENT_REPORTS as string[]).includes(key)
