import { exponent } from './format'

export type ErrorLocale = 'ar' | 'en'

type Rule = {
  pattern: RegExp
  /** Arabic text; a function receives the regex match so dynamic parts can be preserved. */
  ar: string | ((match: RegExpMatchArray) => string)
}

const STATUS_AR: Record<string, string> = {
  open: 'مفتوحة',
  closed: 'مغلقة',
  soft_closed: 'مغلقة جزئياً',
  locked: 'مقفلة',
  draft: 'مسودة',
  posted: 'مرحّل',
  reversed: 'معكوس',
  confirmed: 'مؤكد',
  quoted: 'مسعّر',
  cancelled: 'ملغي',
  canceled: 'ملغي',
  issued: 'مصدر',
  paid: 'مدفوع',
  void: 'ملغى',
  voided: 'ملغى',
  approved: 'معتمد',
  rejected: 'مرفوض',
  submitted: 'مُرسل',
  settled: 'مسوّى',
}

const PARTY_AR: Record<string, string> = {
  customer: 'العميل',
  supplier: 'المورد',
  employee: 'الموظف',
}

const NOT_FOUND_AR: Record<string, string> = {
  Customer: 'العميل',
  Supplier: 'المورد',
  Party: 'الطرف',
  Booking: 'الحجز',
  Payment: 'الدفعة',
  Branch: 'الفرع',
  Owner: 'المسؤول',
  Actor: 'المستخدم',
  'Booking item': 'بند الحجز',
  'Supplier obligation': 'التزام المورد',
  'Allocation booking': 'حجز التخصيص',
}

const BELONGS_AR: Record<string, string> = {
  'cash account': 'حساب النقدية',
  invoice: 'الفاتورة',
  obligation: 'الالتزام',
  supplier: 'المورد',
}

const VERB_AR: Record<string, string> = {
  edited: 'تعديل',
  submitted: 'إرسال',
  approved: 'اعتماد',
  rejected: 'رفض',
  posted: 'ترحيل',
  issued: 'إصدار',
  reversed: 'عكس',
  cancelled: 'إلغاء',
  credited: 'إصدار إشعار دائن على',
  settled: 'تسوية',
  corrected: 'تصحيح',
}

const NOUN_AR: Record<string, string> = {
  documents: 'المستندات',
  expenses: 'المصروفات',
  invoices: 'الفواتير',
  assets: 'الأصول',
  schedules: 'الجداول',
  batches: 'الدفعات',
  entries: 'القيود',
}

const lookup = (table: Record<string, string>, key: string) => table[key] ?? table[key.toLowerCase()] ?? key

/**
 * Raw minor units to a decimal figure. Defaults to two decimals; when a
 * currency code is adjacent in the message its exponent is used instead.
 */
export function formatMinorAmount(raw: string, currency?: string): string {
  const scale = currency ? exponent(currency) : 2
  if (!/^-?\d+$/.test(raw)) return raw
  const negative = raw.startsWith('-')
  const padded = raw.replace('-', '').padStart(scale + 1, '0')
  const whole = padded.slice(0, padded.length - scale)
  const fraction = padded.slice(padded.length - scale)
  return `${negative ? '-' : ''}${whole}${scale ? `.${fraction}` : ''}`
}

/**
 * Ordered table: the first matching rule wins, so more specific patterns come first.
 * Patterns are anchored; dynamic parts (codes, names, numbers) are kept via capture groups.
 */
const RULES: Rule[] = [
  // --- Auth / permissions -------------------------------------------------
  { pattern: /^Unauthenticated\.?$/, ar: 'انتهت الجلسة، يرجى تسجيل الدخول مجدداً.' },
  { pattern: /^(Forbidden|This action is unauthorized)\.?$/, ar: 'ليست لديك صلاحية لتنفيذ هذا الإجراء.' },
  { pattern: /^User does not have the right (permissions|roles)\.?$/, ar: 'ليست لديك الصلاحية الكافية لتنفيذ هذا الإجراء.' },
  { pattern: /^Actor does not have the (.+) permission\.$/, ar: (m) => `لا يملك المستخدم صلاحية «${m[1]}».` },
  { pattern: /^You are not assigned to the requested branch\.$/, ar: 'لست معيّناً على الفرع المطلوب.' },
  { pattern: /^You are not assigned to this branch\.$/, ar: 'لست معيّناً على هذا الفرع.' },
  { pattern: /^You are not assigned to this record's branch\.$/, ar: 'لست معيّناً على فرع هذا السجل.' },
  { pattern: /^No branch is assigned to this user\.$/, ar: 'لم يتم تعيين أي فرع لهذا المستخدم.' },
  { pattern: /^This record belongs to another branch\.$/, ar: 'هذا السجل يتبع فرعاً آخر.' },
  { pattern: /^The party belongs to another branch\.$/, ar: 'الطرف المحدد يتبع فرعاً آخر.' },
  { pattern: /^Closing a year needs access to every branch that has activity in it\.$/, ar: 'إقفال السنة يتطلب الوصول إلى كل الفروع التي لها نشاط فيها.' },
  {
    pattern: /^Segregation of duties: the (.+) cannot be performed by the same actor who created the draft\.$/,
    ar: (m) => `فصل المهام: لا يمكن تنفيذ «${m[1]}» بواسطة نفس المستخدم الذي أنشأ المسودة.`,
  },
  { pattern: /^The person who created or submitted an expense cannot approve it\.$/, ar: 'لا يمكن لمن أنشأ المصروف أو أرسله اعتماده.' },
  { pattern: /^The person who submitted a document cannot approve it\.$/, ar: 'لا يمكن لمن أرسل المستند اعتماده.' },
  { pattern: /^The requester cannot approve their own negative-margin booking\.$/, ar: 'لا يمكن لمقدّم الطلب اعتماد حجزه ذي الهامش السالب بنفسه.' },
  { pattern: /^You may not assign privileges you do not hold\.$/, ar: 'لا يمكنك منح صلاحيات لا تمتلكها.' },
  { pattern: /^You may not assign a branch you are not assigned to\.$/, ar: 'لا يمكنك تعيين فرع غير مُسند إليك.' },
  { pattern: /^You cannot deactivate your own account\.$/, ar: 'لا يمكنك إيقاف حسابك الخاص.' },
  { pattern: /^The last active administrator cannot be deactivated or demoted\.$/, ar: 'لا يمكن إيقاف أو تخفيض آخر مدير نظام نشط.' },
  { pattern: /^This role is a system role and cannot be modified or deleted\.$/, ar: 'هذا دور نظامي ولا يمكن تعديله أو حذفه.' },
  { pattern: /^This role is assigned to users and cannot be deleted\.$/, ar: 'هذا الدور مُسند لمستخدمين ولا يمكن حذفه.' },
  { pattern: /^The primary branch must be one of the assigned branches\.$/, ar: 'يجب أن يكون الفرع الرئيسي من ضمن الفروع المُسندة.' },
  { pattern: /^The current password is incorrect\.$/, ar: 'كلمة المرور الحالية غير صحيحة.' },

  // --- Accounting policy / periods / posting ------------------------------
  {
    pattern: /^Accounting (auto-)?posting is disabled until the accounting policy is approved by an accountant\..*$/,
    ar: 'الترحيل المحاسبي (التلقائي واليدوي) معطّل حتى يعتمد المحاسب السياسة المحاسبية.',
  },
  {
    pattern: /^Fiscal period (.+) is ([a-z_]+); (posting|invoicing) is not allowed\.$/,
    ar: (m) => `الفترة المالية ${m[1]} ${lookup(STATUS_AR, m[2])}؛ ${m[3] === 'posting' ? 'الترحيل' : 'إصدار الفواتير'} غير مسموح.`,
  },
  { pattern: /^No fiscal period covers (.+?)\. Create and open the period first\.$/, ar: (m) => `لا توجد فترة مالية تغطي التاريخ ${m[1]}. أنشئ الفترة وافتحها أولاً.` },
  { pattern: /^This period belongs to a closed fiscal year\. Reopen the year instead\.$/, ar: 'هذه الفترة تتبع سنة مالية مغلقة. أعد فتح السنة بدلاً من ذلك.' },
  { pattern: /^The dates overlap the existing fiscal year (.+)\.$/, ar: (m) => `التواريخ تتداخل مع السنة المالية الحالية ${m[1]}.` },
  { pattern: /^Only a closed fiscal year can be reopened\.$/, ar: 'يمكن إعادة فتح السنة المالية المغلقة فقط.' },
  { pattern: /^The year cannot be closed: (.+)$/, ar: (m) => `لا يمكن إقفال السنة: ${m[1]}` },
  {
    pattern: /^(\d+) draft journal entries in this period must be posted or discarded before closing it\.$/,
    ar: (m) => `يجب ترحيل أو حذف ${m[1]} من قيود اليومية المسودة في هذه الفترة قبل إقفالها.`,
  },
  {
    pattern: /^Journal entry is not balanced in ([A-Z]{3}): debits (-?\d+) vs credits (-?\d+)\.$/,
    ar: (m) => `قيد اليومية غير متوازن بعملة ${m[1]}: المدين ${formatMinorAmount(m[2], m[1])} مقابل الدائن ${formatMinorAmount(m[3], m[1])}.`,
  },
  {
    pattern: /^Journal entry is not balanced: debits (-?\d+) vs credits (-?\d+)\.$/,
    ar: (m) => `قيد اليومية غير متوازن: المدين ${formatMinorAmount(m[1])} مقابل الدائن ${formatMinorAmount(m[2])}.`,
  },
  {
    pattern: /^The ([A-Z]{3}) lines are not balanced: debits (-?\d+) vs credits (-?\d+)\.$/,
    ar: (m) => `بنود ${m[1]} غير متوازنة: المدين ${formatMinorAmount(m[2], m[1])} مقابل الدائن ${formatMinorAmount(m[3], m[1])}.`,
  },
  {
    pattern: /^Base currency entry is not balanced: (-?\d+) vs (-?\d+)\.$/,
    ar: (m) => `القيد بالعملة الأساسية غير متوازن: ${formatMinorAmount(m[1])} مقابل ${formatMinorAmount(m[2])}.`,
  },
  { pattern: /^Document lines must balance \(total debit must equal total credit\)\.$/, ar: 'يجب أن تتوازن بنود المستند (إجمالي المدين = إجمالي الدائن).' },
  { pattern: /^A journal entry needs at least two lines\.$/, ar: 'يحتاج قيد اليومية إلى بندين على الأقل.' },
  { pattern: /^A batch needs at least two lines\.$/, ar: 'تحتاج الدفعة إلى بندين على الأقل.' },
  { pattern: /^A document needs at least one line\.$/, ar: 'يحتاج المستند إلى بند واحد على الأقل.' },
  { pattern: /^An invoice needs at least one line\.$/, ar: 'تحتاج الفاتورة إلى بند واحد على الأقل.' },
  { pattern: /^A booking needs at least one service item\.$/, ar: 'يحتاج الحجز إلى خدمة واحدة على الأقل.' },
  { pattern: /^Each line must have exactly one of debit or credit greater than zero\.$/, ar: 'يجب أن يحتوي كل بند على مدين أو دائن فقط بقيمة أكبر من صفر.' },
  { pattern: /^A line cannot have both debit and credit\.$/, ar: 'لا يمكن أن يحتوي البند على مدين ودائن معاً.' },
  { pattern: /^Line (\d+): enter exactly one of debit or credit, greater than zero\.$/, ar: (m) => `البند ${m[1]}: أدخل مدين أو دائن فقط بقيمة أكبر من صفر.` },
  { pattern: /^Debit and credit amounts cannot be negative\.$/, ar: 'لا يمكن أن تكون مبالغ المدين والدائن سالبة.' },
  { pattern: /^Invalid posting line\.$/, ar: 'بند ترحيل غير صالح.' },
  { pattern: /^Journal lines are immutable once created\.$/, ar: 'بنود القيد لا يمكن تعديلها بعد إنشائها.' },
  { pattern: /^Posted journal entries are immutable; create a reversal instead\.$/, ar: 'القيود المرحّلة لا يمكن تعديلها؛ أنشئ قيداً عكسياً بدلاً من ذلك.' },
  { pattern: /^Posted journal entries cannot be deleted; create a reversal instead\.$/, ar: 'القيود المرحّلة لا يمكن حذفها؛ أنشئ قيداً عكسياً بدلاً من ذلك.' },
  { pattern: /^Only draft journal entries can be posted\.$/, ar: 'يمكن ترحيل قيود اليومية المسودة فقط.' },
  { pattern: /^Only posted entries can be reversed\.$/, ar: 'يمكن عكس القيود المرحّلة فقط.' },
  { pattern: /^This entry has already been reversed\.$/, ar: 'تم عكس هذا القيد مسبقاً.' },
  { pattern: /^This batch has already been posted or reversed and cannot be posted again\.$/, ar: 'تم ترحيل هذه الدفعة أو عكسها مسبقاً ولا يمكن ترحيلها مرة أخرى.' },
  { pattern: /^This event has already been posted\. Use a reversal to correct it\.$/, ar: 'تم ترحيل هذا الحدث مسبقاً. استخدم قيداً عكسياً للتصحيح.' },
  { pattern: /^Only posted batches can be reversed\.$/, ar: 'يمكن عكس الدفعات المرحّلة فقط.' },
  { pattern: /^An opening balance batch is already posted for this branch and date\. Reverse it first\.$/, ar: 'توجد دفعة أرصدة افتتاحية مرحّلة لهذا الفرع وهذا التاريخ. اعكسها أولاً.' },

  // --- Accounts and parties ------------------------------------------------
  { pattern: /^Account not found: (.+)$/, ar: (m) => `الحساب غير موجود: ${m[1]}` },
  { pattern: /^Account (.+) not found\.$/, ar: (m) => `الحساب ${m[1]} غير موجود.` },
  { pattern: /^Account (.+) is not configured\. Complete the chart of accounts before posting\.$/, ar: (m) => `الحساب ${m[1]} غير مهيّأ. أكمل شجرة الحسابات قبل الترحيل.` },
  { pattern: /^Account (.+) is not configured\.$/, ar: (m) => `الحساب ${m[1]} غير مهيّأ.` },
  { pattern: /^Line (\d+): account (.+) is not active and postable\.$/, ar: (m) => `البند ${m[1]}: الحساب ${m[2]} غير نشط أو غير قابل للترحيل.` },
  { pattern: /^Account (.+) is not active and postable\.$/, ar: (m) => `الحساب ${m[1]} غير نشط أو غير قابل للترحيل.` },
  { pattern: /^Line (\d+): choose an active, postable account\.$/, ar: (m) => `البند ${m[1]}: اختر حساباً نشطاً وقابلاً للترحيل.` },
  {
    pattern: /^Account (.+) must be an active, postable (asset|expense) account\.$/,
    ar: (m) => `يجب أن يكون الحساب ${m[1]} حساب ${m[2] === 'asset' ? 'أصول' : 'مصروفات'} نشطاً وقابلاً للترحيل.`,
  },
  { pattern: /^Line (\d+): account (.+) only accepts ([A-Z]{3})\.$/, ar: (m) => `البند ${m[1]}: الحساب ${m[2]} يقبل العملة ${m[3]} فقط.` },
  { pattern: /^Account (.+) (?:only accepts|is restricted to) ([A-Z]{3})\.$/, ar: (m) => `الحساب ${m[1]} يقبل العملة ${m[2]} فقط.` },
  { pattern: /^Account (.+) is already registered as a cash or bank account\.$/, ar: (m) => `الحساب ${m[1]} مسجّل مسبقاً كحساب نقدية أو بنك.` },
  { pattern: /^Control account (.+) is missing or not postable\.$/, ar: (m) => `حساب الرقابة ${m[1]} غير موجود أو غير قابل للترحيل.` },
  { pattern: /^An account is required on each (document|invoice) line\.$/, ar: (m) => `الحساب مطلوب في كل بند ${m[1] === 'document' ? 'مستند' : 'فاتورة'}.` },
  { pattern: /^Line (\d+): receivable and payable lines must name the customer or supplier\.$/, ar: (m) => `البند ${m[1]}: بنود الذمم المدينة والدائنة يجب أن تحدد العميل أو المورد.` },
  { pattern: /^Line (\d+): account (.+) takes a (customer|supplier) as its party\.$/, ar: (m) => `البند ${m[1]}: الحساب ${m[2]} يقبل ${lookup(PARTY_AR, m[3])} كطرف.` },
  { pattern: /^Line (\d+): the selected party does not exist\.$/, ar: (m) => `البند ${m[1]}: الطرف المحدد غير موجود.` },
  { pattern: /^The selected (customer|supplier) does not exist\.$/, ar: (m) => `${lookup(PARTY_AR, m[1])} المحدد غير موجود.` },
  { pattern: /^The selected party does not belong to this branch\.$/, ar: 'الطرف المحدد لا يتبع هذا الفرع.' },
  {
    pattern: /^(Customer|Supplier|Party|Booking|Payment|Branch|Owner|Actor|Booking item|Supplier obligation|Allocation booking) not found\.$/,
    ar: (m) => `${lookup(NOT_FOUND_AR, m[1])} غير موجود.`,
  },
  { pattern: /^Unsupported party type: (.+)$/, ar: (m) => `نوع الطرف غير مدعوم: ${m[1]}` },

  // --- FX / currency --------------------------------------------------------
  {
    pattern: /^No FX rate for ([A-Z]{3}) on or before (.+?)\. Enter a rate before posting\.$/,
    ar: (m) => `لا يوجد سعر صرف للعملة ${m[1]} في تاريخ ${m[2]} أو قبله. أدخل سعر الصرف قبل الترحيل.`,
  },
  { pattern: /^(FX rate must be a positive decimal|The FX rate must be greater than zero)\.$/, ar: 'يجب أن يكون سعر الصرف رقماً موجباً أكبر من صفر.' },
  { pattern: /^The base currency always has a rate of 1 and cannot be stored as an FX rate\.$/, ar: 'العملة الأساسية سعرها دائماً 1 ولا يمكن حفظها كسعر صرف.' },
  { pattern: /^Currency must be a 3-letter code\.$/, ar: 'رمز العملة يجب أن يتكون من 3 أحرف.' },
  { pattern: /^Payment currency must match the (booking|obligation) currency\.$/, ar: (m) => `يجب أن تطابق عملة الدفعة عملة ${m[1] === 'booking' ? 'الحجز' : 'الالتزام'}.` },
  { pattern: /^The payment currency must match the invoice currency\.$/, ar: 'يجب أن تطابق عملة الدفعة عملة الفاتورة.' },
  { pattern: /^The expense currency must match the ([A-Z]{3}) payment account\.$/, ar: (m) => `يجب أن تطابق عملة المصروف عملة حساب الدفع (${m[1]}).` },
  { pattern: /^Historical and settlement FX rates are required for realized FX calculation\.$/, ar: 'أسعار الصرف التاريخية وسعر التسوية مطلوبة لحساب فروق العملة المحققة.' },

  // --- Allocation / settlement ----------------------------------------------
  { pattern: /^Allocation exceeds the outstanding balance of booking (.+)\.$/, ar: (m) => `التخصيص يتجاوز الرصيد المستحق للحجز ${m[1]}.` },
  { pattern: /^Application exceeds the outstanding balance of obligation (.+) or its issued invoice\.$/, ar: (m) => `التطبيق يتجاوز الرصيد المستحق للالتزام ${m[1]} أو فاتورته المصدرة.` },
  { pattern: /^Payment exceeds the outstanding balance of obligation (.+)\.$/, ar: (m) => `الدفعة تتجاوز الرصيد المستحق للالتزام ${m[1]}.` },
  { pattern: /^The amount exceeds the outstanding balance of invoice (.+)\.$/, ar: (m) => `المبلغ يتجاوز الرصيد المستحق للفاتورة ${m[1]}.` },
  { pattern: /^Payment exceeds the issued supplier invoice outstanding amount\.$/, ar: 'الدفعة تتجاوز المبلغ المستحق على فاتورة المورد المصدرة.' },
  { pattern: /^The amount exceeds the unallocated part of this receipt\.$/, ar: 'المبلغ يتجاوز الجزء غير المخصص من هذا السند.' },
  { pattern: /^The amount exceeds the unapplied part of this supplier payment\.$/, ar: 'المبلغ يتجاوز الجزء غير المطبّق من دفعة المورد.' },
  { pattern: /^Total allocation exceeds the unallocated payment amount\.$/, ar: 'إجمالي التخصيص يتجاوز المبلغ غير المخصص من الدفعة.' },
  { pattern: /^Allocated amount exceeds the payment amount\.$/, ar: 'المبلغ المخصص يتجاوز مبلغ الدفعة.' },
  { pattern: /^Application exceeds the unapplied amount of this payment\.$/, ar: 'التطبيق يتجاوز المبلغ غير المطبّق من هذه الدفعة.' },
  { pattern: /^Refund exceeds the refundable amount of this payment\.$/, ar: 'الاسترداد يتجاوز المبلغ القابل للاسترداد من هذه الدفعة.' },
  { pattern: /^Credit notes cannot exceed the invoice total\.$/, ar: 'إشعارات الدائن لا يمكن أن تتجاوز إجمالي الفاتورة.' },
  { pattern: /^Cleared amount exceeds outstanding custody\.$/, ar: 'المبلغ المسوّى يتجاوز العهدة المستحقة.' },
  { pattern: /^Settlement plus cash return exceeds the outstanding advance\.$/, ar: 'التسوية مع النقدية المرتجعة تتجاوز السلفة المستحقة.' },
  { pattern: /^Item (\d+): the amount exceeds the unmatched amount of the line\.$/, ar: (m) => `العنصر ${m[1]}: المبلغ يتجاوز المبلغ غير المطابق للبند.` },
  { pattern: /^Insufficient balance in (.+): the transfer would overdraw the account\.$/, ar: (m) => `الرصيد غير كافٍ في ${m[1]}: التحويل سيؤدي إلى سحب على المكشوف.` },
  { pattern: /^Cannot allocate (?:a payment )?to another customer's booking\.$/, ar: 'لا يمكن التخصيص لحجز يخص عميلاً آخر.' },
  { pattern: /^Cannot apply a receipt to another customer's invoice\.$/, ar: 'لا يمكن تطبيق سند قبض على فاتورة عميل آخر.' },
  { pattern: /^Cannot apply a payment to another supplier's invoice\.$/, ar: 'لا يمكن تطبيق دفعة على فاتورة مورد آخر.' },
  { pattern: /^Cannot allocate (?:a payment )?to a booking in another branch\.$/, ar: 'لا يمكن التخصيص لحجز في فرع آخر.' },
  { pattern: /^Only posted payments can be (allocated|unallocated)\.$/, ar: (m) => `يمكن ${m[1] === 'allocated' ? 'تخصيص' : 'إلغاء تخصيص'} الدفعات المرحّلة فقط.` },
  { pattern: /^Obligation (.+) is ([a-z_]+) and cannot be settled\.$/, ar: (m) => `الالتزام ${m[1]} بحالة «${lookup(STATUS_AR, m[2])}» ولا يمكن تسويته.` },
  { pattern: /^Duplicate booking within allocation request\.$/, ar: 'حجز مكرر داخل طلب التخصيص.' },
  { pattern: /^At least one allocation is required\.$/, ar: 'مطلوب تخصيص واحد على الأقل.' },
  { pattern: /^This supplier payment has no unapplied amount\.$/, ar: 'لا يوجد مبلغ غير مطبّق في دفعة المورد هذه.' },

  // --- Cost centers -----------------------------------------------------------
  { pattern: /^Percentage allocations sum to (.+)% but must equal 100%\.$/, ar: (m) => `مجموع نسب التوزيع ${m[1]}% ويجب أن يساوي 100%.` },
  { pattern: /^Allocation (\d+): cost center (.+) is not active\.$/, ar: (m) => `التوزيع ${m[1]}: مركز التكلفة ${m[2]} غير نشط.` },
  { pattern: /^Allocation (\d+): exact amount must be greater than zero\.$/, ar: (m) => `التوزيع ${m[1]}: المبلغ المحدد يجب أن يكون أكبر من صفر.` },
  { pattern: /^Allocation (\d+): percent must be between 0 and 100\.$/, ar: (m) => `التوزيع ${m[1]}: النسبة يجب أن تكون بين 0 و100.` },
  { pattern: /^Exact allocations exceed the journal line amount\.$/, ar: 'المبالغ المحددة للتوزيع تتجاوز مبلغ بند القيد.' },
  { pattern: /^Allocations can only be recorded against posted journal lines\.$/, ar: 'يمكن تسجيل التوزيع على بنود القيود المرحّلة فقط.' },
  { pattern: /^The journal line has no allocatable amount\.$/, ar: 'لا يوجد مبلغ قابل للتوزيع في بند القيد.' },
  { pattern: /^Cannot archive a center that still carries allocations\.$/, ar: 'لا يمكن أرشفة مركز تكلفة ما زالت عليه توزيعات.' },
  { pattern: /^Cost center is already archived\.$/, ar: 'مركز التكلفة مؤرشف مسبقاً.' },
  { pattern: /^(?:Assigning parent .+ would create|Re-parenting would create) a cycle in the cost center tree\.$/, ar: 'هذا الإسناد سيُنشئ حلقة في شجرة مراكز التكلفة.' },
  { pattern: /^Parent cost center (.+) not found\.$/, ar: (m) => `مركز التكلفة الأب ${m[1]} غير موجود.` },

  // --- Documents / invoices / bookings / expenses -----------------------------
  { pattern: /^Invoice (.+) has already been issued\.$/, ar: (m) => `الفاتورة ${m[1]} صدرت مسبقاً.` },
  {
    pattern: /^Only (draft|submitted|approved|posted|issued) (documents|expenses|invoices|assets|schedules|batches|entries) can be (edited|submitted|approved|rejected|posted|issued|reversed|cancelled|credited|settled|corrected)\.$/,
    ar: (m) => `يمكن ${lookup(VERB_AR, m[3])} ${lookup(NOUN_AR, m[2])} بحالة «${lookup(STATUS_AR, m[1])}» فقط.`,
  },
  {
    pattern: /^Only draft or submitted (documents|invoices) can be (cancelled|voided)\.$/,
    ar: (m) => `يمكن ${m[2] === 'cancelled' ? 'إلغاء' : 'إبطال'} ${lookup(NOUN_AR, m[1])} المسودة أو المُرسلة فقط.`,
  },
  { pattern: /^Only draft or quoted bookings can request margin approval\.$/, ar: 'يمكن طلب اعتماد الهامش للحجوزات المسودة أو المسعّرة فقط.' },
  { pattern: /^Negative-margin booking requires manager approval before confirmation\.$/, ar: 'الحجز ذو الهامش السالب يتطلب موافقة المدير قبل التأكيد.' },
  { pattern: /^Cannot move a booking from (.+) to (.+)\.$/, ar: (m) => `لا يمكن نقل الحجز من الحالة «${lookup(STATUS_AR, m[1])}» إلى «${lookup(STATUS_AR, m[2])}».` },
  { pattern: /^This booking can no longer be cancelled\.$/, ar: 'لا يمكن إلغاء هذا الحجز بعد الآن.' },
  { pattern: /^The expense category is inactive\.$/, ar: 'فئة المصروف غير نشطة.' },
  { pattern: /^Choose an active expense category\.$/, ar: 'اختر فئة مصروف نشطة.' },
  { pattern: /^The expense amount must be greater than zero\.$/, ar: 'يجب أن يكون مبلغ المصروف أكبر من صفر.' },
  { pattern: /^Tax must be zero or less than the total amount \(tax is included in the amount\)\.$/, ar: 'يجب أن تكون الضريبة صفراً أو أقل من الإجمالي (الضريبة مشمولة في المبلغ).' },
  { pattern: /^(?:Choose an active cash or bank account|A valid active cash or bank account is required|Select a cash or bank account)\.$/, ar: 'اختر حساب نقدية أو بنك نشطاً.' },
  { pattern: /^Cash account (.+) is inactive\.$/, ar: (m) => `حساب النقدية ${m[1]} غير نشط.` },
  { pattern: /^Cash account (.+) belongs to another branch\.$/, ar: (m) => `حساب النقدية ${m[1]} يتبع فرعاً آخر.` },
  { pattern: /^The (cash account|invoice|obligation|supplier) belongs to another (branch|supplier)\.$/, ar: (m) => `${lookup(BELONGS_AR, m[1])} يتبع ${m[2] === 'branch' ? 'فرعاً' : 'مورداً'} آخر.` },
  { pattern: /^(?:The |Payment |Refund |Advance |Custody |Obligation |Supplier payment |Application |Allocation |Voucher |Transfer |The transfer |The credit )amount must be greater than zero\.$/, ar: 'يجب أن يكون المبلغ أكبر من صفر.' },
  { pattern: /^Voucher amount must be a positive integer in minor currency units\.$/, ar: 'يجب أن يكون مبلغ السند عدداً صحيحاً موجباً بالوحدات الصغرى للعملة.' },
  { pattern: /^A (?:cancellation )?reason is required\.$/, ar: 'السبب مطلوب.' },
  { pattern: /^Select the required customer or supplier for this voucher\.$/, ar: 'اختر العميل أو المورد المطلوب لهذا السند.' },
  { pattern: /^Choose a payment account, or a supplier to book the expense on account\.$/, ar: 'اختر حساب دفع، أو مورداً لتسجيل المصروف على الحساب.' },

  // --- Bank reconciliation / import -------------------------------------------
  { pattern: /^This reconciliation is approved and locked\. Create a new reconciliation to correct it\.$/, ar: 'هذه التسوية معتمدة ومقفلة. أنشئ تسوية جديدة للتصحيح.' },
  { pattern: /^This statement file has already been imported for this bank account\.$/, ar: 'تم استيراد ملف كشف الحساب هذا مسبقاً لهذا الحساب البنكي.' },
  { pattern: /^Every transaction in this statement has already been imported\.$/, ar: 'تم استيراد جميع معاملات هذا الكشف مسبقاً.' },
  { pattern: /^No usable transaction rows were found in the statement\.$/, ar: 'لم يتم العثور على صفوف معاملات صالحة في الكشف.' },
  { pattern: /^The statement has no data rows\.$/, ar: 'كشف الحساب لا يحتوي على صفوف بيانات.' },
  { pattern: /^The uploaded statement file is empty\.$/, ar: 'ملف كشف الحساب المرفوع فارغ.' },
  { pattern: /^Unsupported statement format "(.+)"\. Upload a CSV or XLSX file\.$/, ar: (m) => `صيغة الكشف «${m[1]}» غير مدعومة. ارفع ملف CSV أو XLSX.` },
  { pattern: /^Missing required column: (.+)$/, ar: (m) => `العمود المطلوب مفقود: ${m[1]}` },
  { pattern: /^Invalid amount "(.*)" in the statement\.$/, ar: (m) => `مبلغ غير صالح «${m[1]}» في الكشف.` },
  { pattern: /^Date "(.*)" does not match the configured format "(.*)"\.$/, ar: (m) => `التاريخ «${m[1]}» لا يطابق الصيغة «${m[2]}».` },
  { pattern: /^Item (\d+): this bank and ledger line are already paired\.$/, ar: (m) => `العنصر ${m[1]}: بند البنك وبند الدفتر مطابقان مسبقاً.` },
  { pattern: /^Bank reconciliation needs a registered bank account, not a cash box\.$/, ar: 'التسوية البنكية تتطلب حساباً بنكياً مسجّلاً وليس صندوق نقدية.' },

  // --- Currencies / company branding ---------------------------------------------
  { pattern: /^The currency code must be exactly 3 letters \(A-Z\), for example USD\.$/, ar: 'رمز العملة يجب أن يكون 3 أحرف إنجليزية، مثل USD.' },
  { pattern: /^This currency code already exists\.$/, ar: 'رمز العملة موجود بالفعل.' },
  { pattern: /^The currency code cannot be changed after creation\.$/, ar: 'لا يمكن تغيير رمز العملة بعد إنشائها.' },
  { pattern: /^The decimals of (\w+) cannot be changed because the currency is already in use\.$/, ar: (m) => `لا يمكن تغيير الخانات العشرية للعملة ${m[1]} لأنها مستخدمة بالفعل.` },
  { pattern: /^The base currency (\w+) cannot be deactivated\.$/, ar: (m) => `لا يمكن إيقاف العملة الأساسية ${m[1]}.` },
  { pattern: /^The base currency (\w+) cannot be deleted\.$/, ar: (m) => `لا يمكن حذف العملة الأساسية ${m[1]}.` },
  { pattern: /^Currency (\w+) is referenced by existing records and cannot be deleted; deactivate it instead\.$/, ar: (m) => `العملة ${m[1]} مستخدمة في سجلات قائمة ولا يمكن حذفها؛ قم بإيقافها بدلاً من ذلك.` },
  { pattern: /^The base currency must be an active currency\.$/, ar: 'يجب أن تكون العملة الأساسية عملة نشطة.' },
  { pattern: /^The company name is required and must be at most 120 characters\.$/, ar: 'اسم الشركة مطلوب ولا يتجاوز 120 حرفاً.' },
  { pattern: /^The logo must be a non-empty file of at most 1 MB\.$/, ar: 'يجب أن يكون الشعار ملفاً غير فارغ لا يتجاوز 1 ميجابايت.' },
  { pattern: /^The logo field must not be greater than 1024 kilobytes\.$/, ar: 'يجب ألا يتجاوز حجم الشعار 1 ميجابايت.' },
  { pattern: /^The logo must be a PNG or JPEG image \(SVG, GIF and other formats are not allowed\)\.$/, ar: 'يجب أن يكون الشعار صورة PNG أو JPEG فقط (لا يُسمح بـ SVG أو GIF).' },
  { pattern: /^The logo dimensions must be between 16 and (\d+) pixels per side\.$/, ar: (m) => `يجب أن تكون أبعاد الشعار بين 16 و${m[1]} بكسل.` },
  { pattern: /^The logo could not be stored\.$/, ar: 'تعذّر حفظ الشعار.' },

  // --- Generic ------------------------------------------------------------------
  { pattern: /^The given data was invalid\.$/, ar: 'البيانات المُدخلة غير صالحة.' },
  { pattern: /^Server Error$/, ar: 'حدث خطأ في الخادم.' },
  { pattern: /^Network Error$/, ar: 'تعذّر الاتصال بالخادم.' },
  { pattern: /^timeout of \d+ms exceeded$/, ar: 'انتهت مهلة الاتصال بالخادم.' },
]

/**
 * Translates a backend (DomainException / authorization) message for display.
 * English and unknown messages pass through unchanged.
 */
export function translateError(message: string, locale: ErrorLocale): string {
  if (locale !== 'ar' || !message) return message
  const text = message.trim()
  for (const rule of RULES) {
    const match = text.match(rule.pattern)
    if (match) return typeof rule.ar === 'string' ? rule.ar : rule.ar(match)
  }
  return message
}
