import type { Locale } from '../i18n'

type Label = { ar: string; en: string }

/**
 * Display names for the stable role and permission codes the API returns.
 * The codes themselves never change (routes check them by name); only what the
 * screen shows is translated. An unknown code (a custom role, a permission added
 * later) falls back to the code, so nothing is ever hidden.
 */

const ROLES: Record<string, Label> = {
  admin: { ar: 'مدير النظام', en: 'Administrator' },
  accountant: { ar: 'محاسب', en: 'Accountant' },
  senior_accountant: { ar: 'محاسب أول', en: 'Senior accountant' },
  finance_manager: { ar: 'مدير مالي', en: 'Finance manager' },
  sales: { ar: 'مبيعات', en: 'Sales' },
  reservations: { ar: 'حجوزات', en: 'Reservations' },
  supervisor: { ar: 'مشرف', en: 'Supervisor' },
  cashier: { ar: 'أمين صندوق', en: 'Cashier' },
  viewer: { ar: 'مشاهد (قراءة فقط)', en: 'Viewer (read only)' },
}

const GROUPS: Record<string, Label> = {
  accounting: { ar: 'المحاسبة', en: 'Accounting' },
  accounts: { ar: 'دليل الحسابات', en: 'Chart of accounts' },
  assets: { ar: 'الأصول الثابتة', en: 'Fixed assets' },
  assistant: { ar: 'المساعد الذكي', en: 'Assistant' },
  attachments: { ar: 'المرفقات', en: 'Attachments' },
  audit: { ar: 'سجل التدقيق', en: 'Audit trail' },
  bookings: { ar: 'الحجوزات', en: 'Bookings' },
  branches: { ar: 'الفروع', en: 'Branches' },
  cash_accounts: { ar: 'الخزائن والبنوك', en: 'Cash and bank accounts' },
  commission_rules: { ar: 'قواعد العمولات', en: 'Commission rules' },
  commissions: { ar: 'العمولات', en: 'Commissions' },
  cost_centers: { ar: 'مراكز التكلفة', en: 'Cost centers' },
  customers: { ar: 'العملاء', en: 'Customers' },
  documents: { ar: 'السندات', en: 'Vouchers' },
  employees: { ar: 'الموظفون', en: 'Employees' },
  expense_categories: { ar: 'فئات المصروفات', en: 'Expense categories' },
  expenses: { ar: 'المصروفات', en: 'Expenses' },
  invoices: { ar: 'الفواتير', en: 'Invoices' },
  journal: { ar: 'قيود اليومية', en: 'Journal entries' },
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  mcp: { ar: 'ربط MCP', en: 'MCP access' },
  opening_balances: { ar: 'الأرصدة الافتتاحية', en: 'Opening balances' },
  payments: { ar: 'التحصيلات والمدفوعات', en: 'Payments' },
  periods: { ar: 'الفترات المحاسبية', en: 'Accounting periods' },
  reconciliation: { ar: 'التسوية البنكية', en: 'Bank reconciliation' },
  refunds: { ar: 'الاستردادات', en: 'Refunds' },
  reports: { ar: 'التقارير', en: 'Reports' },
  roles: { ar: 'الأدوار', en: 'Roles' },
  schedules: { ar: 'الجداول المحاسبية', en: 'Accounting schedules' },
  settings: { ar: 'الإعدادات', en: 'Settings' },
  supplier_obligations: { ar: 'التزامات الموردين', en: 'Supplier obligations' },
  supplier_payments: { ar: 'مدفوعات الموردين', en: 'Supplier payments' },
  suppliers: { ar: 'الموردون', en: 'Suppliers' },
  transfers: { ar: 'التحويلات', en: 'Transfers' },
  users: { ar: 'المستخدمون', en: 'Users' },
}

const PERMISSIONS: Record<string, Label> = {
  'accounting.approve_policy': { ar: 'اعتماد السياسة المحاسبية', en: 'Approve accounting policy' },
  'accounts.create': { ar: 'إنشاء حساب', en: 'Create accounts' },
  'accounts.post': { ar: 'السماح بالترحيل على الحسابات', en: 'Allow posting to accounts' },
  'accounts.update': { ar: 'تعديل الحسابات', en: 'Edit accounts' },
  'accounts.view': { ar: 'عرض الحسابات', en: 'View accounts' },
  'assets.approve': { ar: 'اعتماد الأصول', en: 'Approve assets' },
  'assets.manage': { ar: 'إدارة الأصول', en: 'Manage assets' },
  'assets.view': { ar: 'عرض الأصول', en: 'View assets' },
  'assistant.use': { ar: 'استخدام المساعد الذكي', en: 'Use the assistant' },
  'attachments.delete': { ar: 'حذف المرفقات', en: 'Delete attachments' },
  'attachments.extract': { ar: 'استخراج بيانات المرفقات', en: 'Extract attachment data' },
  'attachments.sensitive': { ar: 'عرض المرفقات الحساسة', en: 'View sensitive attachments' },
  'attachments.upload': { ar: 'رفع المرفقات', en: 'Upload attachments' },
  'attachments.view': { ar: 'عرض المرفقات', en: 'View attachments' },
  'audit.export': { ar: 'تصدير سجل التدقيق', en: 'Export the audit trail' },
  'audit.view': { ar: 'عرض سجل التدقيق', en: 'View the audit trail' },
  'bookings.approve_margin': { ar: 'اعتماد هامش الربح', en: 'Approve margin' },
  'bookings.cancel': { ar: 'إلغاء الحجز', en: 'Cancel bookings' },
  'bookings.create': { ar: 'إنشاء حجز', en: 'Create bookings' },
  'bookings.override_credit_limit': { ar: 'تجاوز حد الائتمان', en: 'Override credit limit' },
  'bookings.transition': { ar: 'تغيير حالة الحجز', en: 'Change booking status' },
  'bookings.update': { ar: 'تعديل الحجز', en: 'Edit bookings' },
  'bookings.view': { ar: 'عرض الحجوزات', en: 'View bookings' },
  'branches.create': { ar: 'إنشاء فرع', en: 'Create branches' },
  'branches.view': { ar: 'عرض الفروع', en: 'View branches' },
  'branches.view_all': { ar: 'عرض كل الفروع', en: 'View all branches' },
  'cash_accounts.manage': { ar: 'إدارة الخزائن والبنوك', en: 'Manage cash and bank accounts' },
  'commission_rules.approve': { ar: 'اعتماد قواعد العمولات', en: 'Approve commission rules' },
  'commission_rules.manage': { ar: 'إدارة قواعد العمولات', en: 'Manage commission rules' },
  'commissions.approve': { ar: 'اعتماد العمولات', en: 'Approve commissions' },
  'commissions.override': { ar: 'تعديل العمولة يدوياً', en: 'Override commissions' },
  'commissions.pay': { ar: 'صرف العمولات', en: 'Pay commissions' },
  'commissions.post': { ar: 'ترحيل العمولات', en: 'Post commissions' },
  'commissions.reverse': { ar: 'عكس العمولات', en: 'Reverse commissions' },
  'commissions.view': { ar: 'عرض العمولات', en: 'View commissions' },
  'cost_centers.manage': { ar: 'إدارة مراكز التكلفة', en: 'Manage cost centers' },
  'cost_centers.view': { ar: 'عرض مراكز التكلفة', en: 'View cost centers' },
  'customers.create': { ar: 'إضافة عميل', en: 'Create customers' },
  'customers.update': { ar: 'تعديل العملاء', en: 'Edit customers' },
  'customers.view': { ar: 'عرض العملاء', en: 'View customers' },
  'documents.approve': { ar: 'اعتماد السندات', en: 'Approve vouchers' },
  'documents.create': { ar: 'إنشاء سندات', en: 'Create vouchers' },
  'documents.view': { ar: 'عرض السندات', en: 'View vouchers' },
  'employees.manage': { ar: 'إدارة الموظفين', en: 'Manage employees' },
  'employees.view': { ar: 'عرض الموظفين', en: 'View employees' },
  'expense_categories.manage': { ar: 'إدارة فئات المصروفات', en: 'Manage expense categories' },
  'expenses.approve': { ar: 'اعتماد المصروفات', en: 'Approve expenses' },
  'expenses.create': { ar: 'إنشاء مصروفات', en: 'Create expenses' },
  'expenses.view': { ar: 'عرض المصروفات', en: 'View expenses' },
  'invoices.approve': { ar: 'اعتماد الفواتير', en: 'Approve invoices' },
  'invoices.create': { ar: 'إنشاء فواتير', en: 'Create invoices' },
  'invoices.view': { ar: 'عرض الفواتير', en: 'View invoices' },
  'journal.create': { ar: 'إنشاء قيود', en: 'Create entries' },
  'journal.delete_drafts': { ar: 'حذف مسودات القيود', en: 'Delete draft entries' },
  'journal.post_own_drafts': { ar: 'ترحيل مسوداته الخاصة', en: 'Post own drafts' },
  'journal.reverse': { ar: 'عكس القيود', en: 'Reverse entries' },
  'journal.view': { ar: 'عرض القيود', en: 'View entries' },
  'leads.convert': { ar: 'تحويل العميل المحتمل إلى عميل', en: 'Convert leads' },
  'leads.create': { ar: 'إضافة عميل محتمل', en: 'Create leads' },
  'leads.manage_rules': { ar: 'إدارة قواعد التوزيع', en: 'Manage assignment rules' },
  'leads.update': { ar: 'تعديل العملاء المحتملين', en: 'Edit leads' },
  'leads.view': { ar: 'عرض العملاء المحتملين', en: 'View leads' },
  'mcp.admin': { ar: 'إدارة ربط MCP', en: 'Administer MCP' },
  'mcp.use': { ar: 'استخدام ربط MCP', en: 'Use MCP' },
  'opening_balances.manage': { ar: 'إدارة الأرصدة الافتتاحية', en: 'Manage opening balances' },
  'opening_balances.post': { ar: 'ترحيل الأرصدة الافتتاحية', en: 'Post opening balances' },
  'opening_balances.reverse': { ar: 'عكس الأرصدة الافتتاحية', en: 'Reverse opening balances' },
  'opening_balances.view': { ar: 'عرض الأرصدة الافتتاحية', en: 'View opening balances' },
  'payments.create': { ar: 'تسجيل تحصيل أو دفعة', en: 'Record payments' },
  'payments.manage': { ar: 'إدارة التحصيلات', en: 'Manage payments' },
  'payments.reverse': { ar: 'عكس التحصيلات', en: 'Reverse payments' },
  'payments.view': { ar: 'عرض التحصيلات', en: 'View payments' },
  'periods.close': { ar: 'إقفال الفترات', en: 'Close periods' },
  'periods.manage': { ar: 'إدارة الفترات', en: 'Manage periods' },
  'periods.post_soft_closed': { ar: 'الترحيل في فترة مقفلة مبدئياً', en: 'Post in soft-closed periods' },
  'periods.reopen': { ar: 'إعادة فتح الفترات', en: 'Reopen periods' },
  'periods.view': { ar: 'عرض الفترات', en: 'View periods' },
  'reconciliation.approve': { ar: 'اعتماد التسوية', en: 'Approve reconciliations' },
  'reconciliation.manage': { ar: 'إدارة التسوية', en: 'Manage reconciliations' },
  'reconciliation.view': { ar: 'عرض التسوية', en: 'View reconciliations' },
  'refunds.approve': { ar: 'اعتماد الاستردادات', en: 'Approve refunds' },
  'refunds.create': { ar: 'طلب استرداد', en: 'Request refunds' },
  'reports.export': { ar: 'تصدير التقارير', en: 'Export reports' },
  'reports.schedule': { ar: 'جدولة التقارير', en: 'Schedule reports' },
  'reports.view': { ar: 'عرض التقارير', en: 'View reports' },
  'roles.manage': { ar: 'إدارة الأدوار', en: 'Manage roles' },
  'roles.view': { ar: 'عرض الأدوار', en: 'View roles' },
  'schedules.approve': { ar: 'اعتماد الجداول المحاسبية', en: 'Approve schedules' },
  'schedules.manage': { ar: 'إدارة الجداول المحاسبية', en: 'Manage schedules' },
  'schedules.view': { ar: 'عرض الجداول المحاسبية', en: 'View schedules' },
  'settings.update': { ar: 'تعديل الإعدادات', en: 'Edit settings' },
  'settings.view': { ar: 'عرض الإعدادات', en: 'View settings' },
  'supplier_obligations.create': { ar: 'إنشاء التزام مورد', en: 'Create supplier obligations' },
  'supplier_obligations.view': { ar: 'عرض التزامات الموردين', en: 'View supplier obligations' },
  'supplier_payments.create': { ar: 'إنشاء دفعة مورد', en: 'Create supplier payments' },
  'supplier_payments.reverse': { ar: 'عكس دفعات الموردين', en: 'Reverse supplier payments' },
  'supplier_payments.view': { ar: 'عرض مدفوعات الموردين', en: 'View supplier payments' },
  'suppliers.create': { ar: 'إضافة مورد', en: 'Create suppliers' },
  'suppliers.update': { ar: 'تعديل الموردين', en: 'Edit suppliers' },
  'suppliers.view': { ar: 'عرض الموردين', en: 'View suppliers' },
  'transfers.create': { ar: 'إنشاء تحويل', en: 'Create transfers' },
  'transfers.reverse': { ar: 'عكس التحويلات', en: 'Reverse transfers' },
  'transfers.view': { ar: 'عرض التحويلات', en: 'View transfers' },
  'users.manage': { ar: 'إدارة المستخدمين', en: 'Manage users' },
  'users.view': { ar: 'عرض المستخدمين', en: 'View users' },
}

/** `finance_manager` → "مدير مالي"; a custom role keeps its own name. */
export function roleLabel(name: string, locale: Locale): string {
  return ROLES[name]?.[locale] ?? name
}

/** `bookings.create` → "إنشاء حجز"; an unknown code is shown as is. */
export function permissionLabel(code: string, locale: Locale): string {
  return PERMISSIONS[code]?.[locale] ?? code
}

/** `bookings` → "الحجوزات" (the group a permission belongs to). */
export function permissionGroupLabel(prefix: string, locale: Locale): string {
  return GROUPS[prefix]?.[locale] ?? prefix
}

/** Codes this table knows; the test compares it with the seeded catalogue. */
export const knownPermissionCodes = Object.keys(PERMISSIONS)
export const knownRoleNames = Object.keys(ROLES)
