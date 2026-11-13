import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, formatDateTime, toMinor, todayISO } from '../lib/format'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Loading,
  Modal,
  Money,
  PageHeader,
  Select,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'
import { localizedName } from '../lib/names'
import { CodeField } from '../components/CodeField'

type AccountRef = { code?: string; name_ar?: string; name_en?: string }

type Category = {
  id: string
  code: string
  name_ar: string
  name_en: string
  asset_account: AccountRef | null
  accumulated_depreciation_account: AccountRef | null
  depreciation_expense_account: AccountRef | null
  disposal_gain_account: AccountRef | null
  disposal_loss_account: AccountRef | null
  default_useful_life_months: number | null
  assets_count?: number | null
}

type BranchRef = { id: string; code: string; name_ar: string; name_en: string }

type Asset = {
  id: string
  code: string
  description: string
  branch_id: string | null
  branch: BranchRef | null
  category: Category | null
  location: string | null
  supplier: { id: string; name?: string; name_ar?: string; name_en?: string } | null
  cost_center: { id: string; name_ar?: string; name_en?: string } | null
  employee: { id: string; name_ar?: string; name_en?: string } | null
  purchase_date: string | null
  capitalization_date: string
  in_service_date: string
  currency: string
  base_currency?: string
  acquisition_value_minor: number
  acquisition_value_base_minor: number
  residual_value_minor: number
  useful_life_months: number
  depreciation_method: string
  depreciation_convention: 'month_of_purchase' | 'next_month' | 'prorate_days'
  first_depreciation_period: string
  monthly_depreciation_minor: number
  status: 'draft' | 'active' | 'fully_depreciated' | 'disposed'
  accumulated_depreciation_minor: number
  impairment_minor: number
  total_accumulated_minor: number
  depreciable_minor: number
  remaining_depreciable_minor: number
  carrying_value_minor: number
  last_depreciated_period: string | null
  disposed_on: string | null
  disposal_type: string | null
  disposal_proceeds_minor: number
  disposal_gain_loss_minor: number
  capitalization_journal_entry_id: string | null
  acquisition_journal_entry_id?: string | null
  disposal_depreciation_minor?: number
  disposal_journal_entry_id: string | null
  attachments: Array<{ name?: string; url?: string }>
  impairment_document_ids: string[]
  applied_impairment_document_ids: string[]
  notes: string | null
  approved_at: string | null
}

type ScheduleRow = {
  period: string
  amount_minor: number
  accumulated_minor: number
  carrying_value_minor: number
  status: string
}

type DepreciationItem = {
  id: string
  asset_id: string | null
  asset_code: string | null
  asset_description: string | null
  category: string | null
  period: string
  amount_minor: number
  accumulated_after_minor: number
  carrying_after_minor: number
  status: string
  failure_reason: string | null
}

type DepreciationRun = {
  id: string
  number: string
  period: string
  run_date: string
  status: 'draft' | 'posted' | 'failed' | 'reversed'
  assets_count: number
  total_amount_minor: number
  currency?: string
  journal_entry_id: string | null
  failure_reason: string | null
  notes: string | null
  posted_at: string | null
  items: DepreciationItem[]
}

type Preview = {
  period: string
  run_date: string
  currency?: string
  assets_count: number
  total_amount_minor: number
  items: Array<{
    asset_id: string
    asset_code: string
    asset_description: string
    category: string | null
    amount_minor: number
    accumulated_after_minor: number
    carrying_after_minor: number
  }>
}

type ReconciliationRow = {
  category_id: string
  category_code: string
  category_name: string
  register_cost_minor: number
  gl_cost_minor: number | null
  cost_difference_minor: number | null
  register_accumulated_minor: number
  gl_accumulated_minor: number | null
  accumulated_difference_minor: number | null
  shared_accounts?: boolean
  is_reconciled: boolean
}

type ReconciliationAccount = {
  role: 'cost' | 'accumulated'
  account: AccountRef
  category_codes: string[]
  register_minor: number
  gl_minor: number
  difference_minor: number
  is_reconciled: boolean
  is_classified_fixed_asset: boolean
}

type UnregisteredAccount = {
  account: AccountRef
  gl_minor: number
  difference_minor: number
}

type Reconciliation = {
  as_of: string | null
  scope: 'branch' | 'global'
  currency?: string
  is_reconciled: boolean
  cost_difference_minor: number
  accumulated_difference_minor: number
  note: string
  categories: ReconciliationRow[]
  accounts?: ReconciliationAccount[]
  unregistered_accounts?: UnregisteredAccount[]
}

type AuditEvent = {
  id: string
  action: string
  actor_id: number | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  created_at: string | null
}

type Transfer = {
  id: string
  transfer_date: string
  reason: string | null
  from_branch: BranchRef | null
  to_branch: BranchRef | null
  from_cost_center: { name_ar?: string; name_en?: string } | null
  to_cost_center: { name_ar?: string; name_en?: string } | null
}

type AuditTrail = {
  asset: Asset
  base_currency?: string
  events: AuditEvent[]
  transfers: Transfer[]
}

type Tab = 'register' | 'depreciation' | 'reconciliation' | 'audit'

type LifecycleMode = 'transfer' | 'dispose' | 'impair'

type LifecycleState = {
  mode: LifecycleMode
  asset: Asset
  toBranchId: string
  transferDate: string
  reason: string
  disposalDate: string
  disposalType: 'sale' | 'retirement' | 'scrap'
  proceeds: string
  impairmentAmount: string
  documentDate: string
  memo: string
  submit: boolean
}

const STORAGE_KEY = 'fixed-assets'

const emptyAssetForm = {
  category_id: '',
  description: '',
  location: '',
  capitalization_date: todayISO(),
  in_service_date: todayISO(),
  currency: 'EGP',
  fx_rate: '',
  acquisition_journal_entry_id: '',
  acquisition_value: '',
  residual_value: '0',
  useful_life_months: '60',
  depreciation_convention: 'month_of_purchase' as Asset['depreciation_convention'],
  notes: '',
}

const emptyCategoryForm = {
  name_ar: '',
  name_en: '',
  default_useful_life_months: '',
}

function currentPeriod(): string {
  return new Date().toISOString().slice(0, 7)
}

export default function FixedAssets() {
  const { locale } = useI18n()
  const { activeBranch, branches, can } = useAuth()
  const queryClient = useQueryClient()

  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [tab, setTab] = useState<Tab>('register')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [assetFormOpen, setAssetFormOpen] = useState(false)
  const [assetForm, setAssetForm] = useState(emptyAssetForm)
  const [categoryOpen, setCategoryOpen] = useState(false)
  const [categoryForm, setCategoryForm] = useState(emptyCategoryForm)
  const [detailId, setDetailId] = useState<string | null>(null)
  const [lifecycle, setLifecycle] = useState<LifecycleState | null>(null)
  const [period, setPeriod] = useState(currentPeriod())
  const [previewPeriod, setPreviewPeriod] = useState('')
  const [asOf, setAsOf] = useState(todayISO())
  const [globalScope, setGlobalScope] = useState(false)
  const [auditId, setAuditId] = useState('')

  const manage = can('assets.manage')
  const approve = can('assets.approve')
  const viewAll = can('branches.view_all')

  const statusLabels: Record<string, { ar: string; en: string }> = {
    draft: { ar: 'مسودة', en: 'Draft' },
    active: { ar: 'نشط', en: 'Active' },
    fully_depreciated: { ar: 'مهلك بالكامل', en: 'Fully depreciated' },
    disposed: { ar: 'مستبعد', en: 'Disposed' },
    posted: { ar: 'مرحّل', en: 'Posted' },
    failed: { ar: 'فشل', en: 'Failed' },
    pending: { ar: 'معلق', en: 'Pending' },
    scheduled: { ar: 'مجدول', en: 'Scheduled' },
    sale: { ar: 'بيع', en: 'Sale' },
    retirement: { ar: 'إخراج من الخدمة', en: 'Retirement' },
    scrap: { ar: 'تخريد', en: 'Scrap' },
    reversed: { ar: 'مُعكَس', en: 'Reversed' },
    month_of_purchase: { ar: 'شهر الشراء', en: 'Month of purchase' },
    next_month: { ar: 'الشهر التالي', en: 'Next month' },
    prorate_days: { ar: 'تناسب أيام الشهر', en: 'Prorate days' },
  }

  const statusLabel = (status: string) => {
    const label = statusLabels[status]
    return label ? L(label.ar, label.en) : status
  }

  const accountLabel = (account: AccountRef | null) =>
    account ? `${account.code ?? ''} ${locale === 'ar' ? account.name_ar ?? '' : account.name_en ?? ''}`.trim() : '—'

  const categoryLabel = (category: Category | null) =>
    category ? (locale === 'ar' ? category.name_ar || category.code : category.name_en || category.code) : '—'

  const baseCurrency = (asset?: Asset | null) => asset?.base_currency ?? 'EGP'

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: [STORAGE_KEY] })
  }

  const assetsQuery = useQuery({
    queryKey: [STORAGE_KEY, 'assets', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Asset[] }>('/fixed-assets')
      return data.data
    },
  })

  const categoriesQuery = useQuery({
    queryKey: [STORAGE_KEY, 'categories'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Category[] }>('/fixed-asset-categories')
      return data.data
    },
  })

  const runsQuery = useQuery({
    queryKey: [STORAGE_KEY, 'runs', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: DepreciationRun[] }>('/depreciation-runs')
      return data.data
    },
  })

  const previewQuery = useQuery({
    queryKey: [STORAGE_KEY, 'preview', activeBranch?.id, previewPeriod],
    enabled: previewPeriod !== '',
    queryFn: async () => {
      const { data } = await api.get<{ data: Preview }>('/depreciation-runs/preview', { params: { period: previewPeriod } })
      return data.data
    },
  })

  const reconQuery = useQuery({
    queryKey: [STORAGE_KEY, 'reconciliation', activeBranch?.id, asOf, globalScope],
    enabled: tab === 'reconciliation',
    queryFn: async () => {
      const { data } = await api.get<{ data: Reconciliation }>('/reports/fixed-assets/reconciliation', {
        params: { as_of: asOf || undefined, global: globalScope ? 1 : undefined },
      })
      return data.data
    },
  })

  const [exportingFormat, setExportingFormat] = useState<'csv' | 'xlsx' | 'pdf' | null>(null)
  const [exportError, setExportError] = useState('')

  const downloadReconciliation = async (format: 'csv' | 'xlsx' | 'pdf') => {
    setExportingFormat(format)
    setExportError('')
    try {
      const response = await api.get<Blob>('/reports/fixed-assets/reconciliation/export', {
        params: { as_of: asOf || undefined, global: globalScope ? 1 : undefined, format, language: locale },
        responseType: 'blob',
      })
      const disposition = String(response.headers['content-disposition'] ?? '')
      const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ?? `fixed-assets-reconciliation.${format}`
      const href = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = href
      link.download = filename
      link.click()
      URL.revokeObjectURL(href)
    } catch {
      setExportError(L('تعذّر التصدير', 'Export failed'))
    } finally {
      setExportingFormat(null)
    }
  }

  const detail = (assetsQuery.data ?? []).find((asset) => asset.id === detailId) ?? null
  const auditAsset = (assetsQuery.data ?? []).find((asset) => asset.id === auditId) ?? null

  const scheduleQuery = useQuery({
    queryKey: [STORAGE_KEY, 'schedule', detailId],
    enabled: detailId !== null,
    queryFn: async () => {
      const { data } = await api.get<{ data: { periods: ScheduleRow[] } }>(`/fixed-assets/${detailId}/schedule`)
      return data.data.periods
    },
  })

  const auditQuery = useQuery({
    queryKey: [STORAGE_KEY, 'audit', auditId],
    enabled: auditAsset !== null,
    queryFn: async () => {
      const { data } = await api.get<{ data: AuditTrail }>(`/fixed-assets/${auditId}/audit-trail`)
      return data.data
    },
  })

  const assetBase = (assetsQuery.data ?? [])[0]?.base_currency ?? 'EGP'

  const assetFormError = (() => {
    const life = Number(assetForm.useful_life_months)
    if (!Number.isInteger(life) || life < 1 || life > 600) return L('العمر الإنتاجي بين 1 و600 شهر.', 'Useful life must be between 1 and 600 months.')
    if (assetForm.currency.length !== 3) return L('رمز العملة من ثلاثة أحرف.', 'Currency must be a 3-letter code.')
    if (assetForm.currency !== assetBase && assetForm.fx_rate && !(Number(assetForm.fx_rate) > 0))
      return L('سعر الصرف يجب أن يكون أكبر من صفر.', 'The exchange rate must be greater than zero.')
    if (assetForm.in_service_date && assetForm.in_service_date < assetForm.capitalization_date)
      return L('تاريخ بدء الخدمة لا يسبق تاريخ الرسملة.', 'The in-service date cannot be before the capitalization date.')
    if (assetForm.acquisition_value && Number(assetForm.residual_value || '0') >= Number(assetForm.acquisition_value))
      return L('القيمة المتبقية يجب أن تقل عن قيمة الاقتناء.', 'Residual value must be lower than the acquisition value.')
    return ''
  })()

  const createAsset = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: Asset }>('/fixed-assets', {
        category_id: assetForm.category_id,
        description: assetForm.description,
        location: assetForm.location || undefined,
        capitalization_date: assetForm.capitalization_date,
        in_service_date: assetForm.in_service_date || undefined,
        currency: assetForm.currency,
        fx_rate: assetForm.currency !== assetBase && assetForm.fx_rate ? assetForm.fx_rate : undefined,
        acquisition_journal_entry_id: assetForm.acquisition_journal_entry_id.trim() || undefined,
        acquisition_value_minor: toMinor(assetForm.acquisition_value, assetForm.currency),
        residual_value_minor: toMinor(assetForm.residual_value || '0', assetForm.currency),
        useful_life_months: Number(assetForm.useful_life_months),
        depreciation_convention: assetForm.depreciation_convention,
        notes: assetForm.notes || undefined,
      })
      return data.data
    },
    onSuccess: async (asset) => {
      setAssetFormOpen(false)
      setAssetForm(emptyAssetForm)
      setError('')
      setNotice(
        L(
          `سُجّل الأصل ${asset.code} كمسودة. اعتمده لترحيل قيمته إلى دفتر الأستاذ.`,
          `Asset ${asset.code} registered as a draft. Approve it to post its cost to the ledger.`,
        ),
      )
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const createCategory = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: Category }>('/fixed-asset-categories', {
        // No code is sent: the server generates it (FAC-001, ...).
        name_ar: categoryForm.name_ar.trim(),
        name_en: categoryForm.name_en.trim(),
        default_useful_life_months: categoryForm.default_useful_life_months
          ? Number(categoryForm.default_useful_life_months)
          : undefined,
      })
      return data.data
    },
    onSuccess: async (category) => {
      setCategoryOpen(false)
      setCategoryForm(emptyCategoryForm)
      setError('')
      setNotice(L(`أُنشئ تصنيف ${category.code} بحسابات الضبط الافتراضية.`, `Category ${category.code} created with the default control accounts.`))
      await queryClient.invalidateQueries({ queryKey: [STORAGE_KEY, 'categories'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const capitalize = useMutation({
    mutationFn: async (asset: Asset) => {
      const { data } = await api.post<{ data: Asset }>(`/fixed-assets/${asset.id}/capitalize`)
      return data.data
    },
    onSuccess: async (asset) => {
      setError('')
      setNotice(L(`اعتُمد الأصل ${asset.code} ورُحّلت قيمته إلى الأستاذ.`, `Asset ${asset.code} capitalized and posted to the ledger.`))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const lifecycleMutation = useMutation({
    mutationFn: async (state: LifecycleState) => {
      if (state.mode === 'transfer') {
        const { data } = await api.post<{ data: { asset: Asset } }>(`/fixed-assets/${state.asset.id}/transfer`, {
          to_branch_id: state.toBranchId || undefined,
          transfer_date: state.transferDate,
          reason: state.reason || undefined,
        })
        return data.data.asset
      }

      if (state.mode === 'dispose') {
        const { data } = await api.post<{ data: Asset }>(`/fixed-assets/${state.asset.id}/dispose`, {
          disposal_date: state.disposalDate,
          disposal_type: state.disposalType,
          proceeds_minor: toMinor(state.proceeds || '0', baseCurrency(state.asset)),
          notes: state.reason || undefined,
        })
        return data.data
      }

      const { data } = await api.post<{ data: { number: string; status: string } }>(
        `/fixed-assets/${state.asset.id}/impairments`,
        {
          amount_minor: toMinor(state.impairmentAmount, baseCurrency(state.asset)),
          document_date: state.documentDate,
          memo: state.memo || undefined,
          submit: state.submit,
        },
      )
      return data.data
    },
    onSuccess: async (result) => {
      setLifecycle(null)
      setError('')
      if ('number' in result) {
        setNotice(
          L(
            `أُنشئ سند انخفاض القيمة ${result.number} (${statusLabel(result.status)}). لا يُطبّق على السجل إلا بعد ترحيله واعتماده.`,
            `Impairment voucher ${result.number} created (${result.status}). It only reaches the register after it is posted and applied.`,
          ),
        )
      } else {
        setNotice(L('تم تحديث الأصل وتسجيل الحركة في سجل التدقيق.', 'Asset updated and the movement recorded in the audit trail.'))
      }
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const applyImpairment = useMutation({
    mutationFn: async ({ asset, document }: { asset: Asset; document: string }) => {
      const { data } = await api.post<{ data: Asset }>(`/fixed-assets/${asset.id}/impairments/${document}/apply`)
      return data.data
    },
    onSuccess: async () => {
      setError('')
      setNotice(L('طُبّق سند انخفاض القيمة على السجل.', 'Impairment voucher applied to the register.'))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const createRun = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: DepreciationRun }>('/depreciation-runs', { period })
      return data.data
    },
    onSuccess: async (run) => {
      setError('')
      setNotice(
        L(
          `أُنشئت مسودة تشغيل الإهلاك ${run.number} لفترة ${run.period} وتشمل ${run.assets_count} أصلاً. راجعها ثم اعتمد الترحيل.`,
          `Draft depreciation run ${run.number} for ${run.period} covers ${run.assets_count} assets. Review it, then approve posting.`,
        ),
      )
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const postRun = useMutation({
    mutationFn: async (run: DepreciationRun) => {
      const { data } = await api.post<{ data: DepreciationRun }>(`/depreciation-runs/${run.id}/post`)
      return data.data
    },
    onSuccess: async (run) => {
      setError('')
      setNotice(
        L(
          `رُحّل تشغيل الإهلاك ${run.number} بقيد واحد. لا يمكن تكرار الترحيل لنفس الفترة.`,
          `Depreciation run ${run.number} posted as one journal. The same period cannot be posted twice.`,
        ),
      )
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const [reverseTarget, setReverseTarget] = useState<DepreciationRun | null>(null)
  const [reverseReason, setReverseReason] = useState('')

  const reverseRun = useMutation({
    mutationFn: async ({ run, reason }: { run: DepreciationRun; reason: string }) => {
      const { data } = await api.post<{ data: DepreciationRun }>(`/depreciation-runs/${run.id}/reverse`, { reason })
      return data.data
    },
    onSuccess: async (run) => {
      setReverseTarget(null)
      setReverseReason('')
      setError('')
      setNotice(
        L(
          `عُكس تشغيل الإهلاك ${run.number}. يمكن إعادة تشغيل الفترة ${run.period}.`,
          `Depreciation run ${run.number} reversed. Period ${run.period} can be run again.`,
        ),
      )
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const deleteRun = useMutation({
    mutationFn: async (run: DepreciationRun) => {
      await api.delete(`/depreciation-runs/${run.id}`)
    },
    onSuccess: async () => {
      setError('')
      setNotice(L('حُذفت مسودة التشغيل.', 'Draft run deleted.'))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const openLifecycle = (mode: LifecycleMode, asset: Asset) => {
    setError('')
    setLifecycle({
      mode,
      asset,
      toBranchId: branches.find((branch) => branch.id !== activeBranch?.id)?.id ?? '',
      transferDate: todayISO(),
      reason: '',
      disposalDate: todayISO(),
      disposalType: 'sale',
      proceeds: '',
      impairmentAmount: '',
      documentDate: todayISO(),
      memo: '',
      submit: false,
    })
  }

  const detailCurrency = baseCurrency(detail)

  const tabButton = (key: Tab, label: string) => (
    <button
      type="button"
      className={`rounded-12 border px-4 py-2 text-sm font-medium transition ${
        tab === key
          ? 'border-border-selected bg-surface-selected text-text-default'
          : 'border-border-subtle bg-surface-default text-text-subtle hover:bg-surface-hovered'
      }`}
      onClick={() => setTab(key)}
    >
      {label}
    </button>
  )

  const assets = useMemo(() => assetsQuery.data ?? [], [assetsQuery.data])

  return (
    <div>
      <PageHeader
        title={L('الأصول الثابتة والإهلاك', 'Fixed assets & depreciation')}
        subtitle={L(
          'سجل الأصول، إهلاك القسط الثابت، التحويلات التشغيلية، الاستبعاد ومطابقة حسابات الضبط.',
          'Asset register, straight-line depreciation, operational transfers, disposal and control-account reconciliation.',
        )}
        icon="package"
        actions={
          manage ? (
            <>
              <Button variant="secondary" icon="plus" onClick={() => setCategoryOpen(true)}>
                {L('تصنيف جديد', 'New category')}
              </Button>
              <Button
                icon="plus"
                onClick={() => {
                  setAssetForm({
                    ...emptyAssetForm,
                    category_id: (categoriesQuery.data ?? [])[0]?.id ?? '',
                    useful_life_months: String(
                      (categoriesQuery.data ?? [])[0]?.default_useful_life_months ?? 60,
                    ),
                  })
                  setError('')
                  setAssetFormOpen(true)
                }}
              >
                {L('أصل جديد', 'New asset')}
              </Button>
            </>
          ) : null
        }
      />

      {error ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}
      {notice ? (
        <p className="mb-4 rounded-12 border border-border-subtle bg-surface-subtle px-3 py-2 text-medium text-text-default">{notice}</p>
      ) : null}

      <div className="mb-4 flex flex-wrap gap-2">
        {tabButton('register', L('السجل', 'Register'))}
        {tabButton('depreciation', L('تشغيل الإهلاك', 'Depreciation runs'))}
        {tabButton('reconciliation', L('المطابقة', 'Reconciliation'))}
        {tabButton('audit', L('سجل التدقيق', 'Audit trail'))}
      </div>

      {tab === 'register' ? (
        assetsQuery.isLoading ? (
          <Loading />
        ) : assetsQuery.isError ? (
          <ErrorState message={errorMessage(assetsQuery.error)} onRetry={() => void assetsQuery.refetch()} />
        ) : (
          <Card>
            <CardHeader
              title={L('سجل الأصول الثابتة', 'Fixed-asset register')}
              subtitle={L(
                'المسودات قابلة للتعديل. الاعتماد يرحّل القيمة، والتحويل بين الفروع يرحّل قيدين متوازنين بصافي القيمة الدفترية.',
                'Drafts are editable. Capitalization posts cost; a transfer between branches posts one balanced journal per branch at net book value.',
              )}
            />
            {assets.length === 0 ? (
              <EmptyState
                title={L('لا توجد أصول بعد', 'No assets yet')}
                hint={L('أنشئ تصنيفاً ثم سجّل أصلاً لبدء الإهلاك.', 'Create a category, then register an asset to start depreciating.')}
              />
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>{L('الكود', 'Code')}</Th>
                    <Th>{L('البيان', 'Description')}</Th>
                    <Th>{L('التصنيف', 'Category')}</Th>
                    <Th>{L('الفرع', 'Branch')}</Th>
                    <Th>{L('التكلفة', 'Cost')}</Th>
                    <Th>{L('مجمع الإهلاك', 'Accumulated')}</Th>
                    <Th>{L('القيمة الدفترية', 'Carrying value')}</Th>
                    <Th>{L('الحالة', 'Status')}</Th>
                    <Th />
                  </tr>
                </thead>
                <tbody>
                  {assets.map((asset) => (
                    <tr key={asset.id}>
                      <Td>
                        <span className="tabular">{asset.code}</span>
                      </Td>
                      <Td>
                        <div>{asset.description}</div>
                        <div className="text-small text-text-subtle">
                          {asset.location ?? '—'} · {L('عمر', 'Life')} {asset.useful_life_months} {L('شهر', 'months')}
                        </div>
                      </Td>
                      <Td>
                        <div>{categoryLabel(asset.category)}</div>
                        <div className="text-small text-text-subtle">{accountLabel(asset.category?.asset_account ?? null)}</div>
                      </Td>
                      <Td>{asset.branch ? (localizedName(asset.branch, locale)) : '—'}</Td>
                      <Td>
                        <Money amountMinor={asset.acquisition_value_base_minor} currency={baseCurrency(asset)} />
                      </Td>
                      <Td>
                        <Money amountMinor={asset.total_accumulated_minor} currency={baseCurrency(asset)} />
                      </Td>
                      <Td>
                        <Money amountMinor={asset.carrying_value_minor} currency={baseCurrency(asset)} />
                      </Td>
                      <Td>
                        <Badge tone={statusTone(asset.status)}>{statusLabel(asset.status)}</Badge>
                      </Td>
                      <Td>
                        <div className="flex flex-wrap justify-end gap-2">
                          <Button variant="ghost" onClick={() => setDetailId(asset.id)}>
                            {L('عرض', 'View')}
                          </Button>
                          {asset.status === 'draft' && approve ? (
                            <Button variant="secondary" onClick={() => capitalize.mutate(asset)} disabled={capitalize.isPending}>
                              {L('اعتماد', 'Capitalize')}
                            </Button>
                          ) : null}
                          {asset.status === 'active' || asset.status === 'fully_depreciated' ? (
                            <>
                              {manage ? (
                                <Button variant="secondary" onClick={() => openLifecycle('transfer', asset)}>
                                  {L('تحويل', 'Transfer')}
                                </Button>
                              ) : null}
                              {approve ? (
                                <Button variant="danger" onClick={() => openLifecycle('dispose', asset)}>
                                  {L('استبعاد', 'Dispose')}
                                </Button>
                              ) : null}
                            </>
                          ) : null}
                        </div>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        )
      ) : null}

      {tab === 'depreciation' ? (
        <div className="space-y-4">
          <Card>
            <CardHeader
              title={L('معاينة الإهلاك الشهرية', 'Monthly depreciation preview')}
              subtitle={L(
                'معاينة بلا حفظ. يُنشئ التشغيل مسودة قابلة للمراجعة، ثم يُرحّل بقيد واحد فقط بعد الاعتماد.',
                'Read-only preview. The run is stored as a reviewable draft, then posted as exactly one journal after approval.',
              )}
            />
            <div className="flex flex-wrap items-end gap-3 px-5 py-4">
              <Field label={L('الفترة', 'Period')}>
                <Input type="month" value={period} onChange={(event) => setPeriod(event.target.value)} />
              </Field>
              <Button variant="secondary" onClick={() => setPreviewPeriod(period)} disabled={!period}>
                {L('معاينة', 'Preview')}
              </Button>
              {manage ? (
                <Button onClick={() => createRun.mutate()} disabled={createRun.isPending || !period}>
                  {L('إنشاء مسودة تشغيل', 'Create draft run')}
                </Button>
              ) : null}
            </div>
            {previewPeriod !== '' ? (
              previewQuery.isLoading ? (
                <Loading />
              ) : previewQuery.isError ? (
                <ErrorState message={errorMessage(previewQuery.error)} onRetry={() => void previewQuery.refetch()} />
              ) : previewQuery.data ? (
                <div className="px-5 pb-4">
                  <div className="mb-3 flex flex-wrap items-center gap-3">
                    <Badge tone="brand">{previewQuery.data.period}</Badge>
                    <span className="text-small text-text-subtle">
                      {previewQuery.data.assets_count} {L('أصل', 'assets')} ·{' '}
                      <Money
                        amountMinor={previewQuery.data.total_amount_minor}
                        currency={previewQuery.data.currency ?? 'EGP'}
                      />
                    </span>
                  </div>
                  {previewQuery.data.items.length === 0 ? (
                    <EmptyState title={L('لا يوجد إهلاك مستحق', 'No depreciation due')} hint={L('ربما رُحّل هذا الشهر مسبقاً أو لا توجد أصول نشطة.', 'This month may already be posted or there are no active assets.')} />
                  ) : (
                    <Table>
                      <thead>
                        <tr>
                          <Th>{L('الأصل', 'Asset')}</Th>
                          <Th>{L('التصنيف', 'Category')}</Th>
                          <Th>{L('المبلغ', 'Amount')}</Th>
                          <Th>{L('المجمع بعد', 'Accumulated after')}</Th>
                          <Th>{L('القيمة بعد', 'Carrying after')}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {previewQuery.data.items.map((row) => (
                          <tr key={row.asset_id}>
                            <Td>
                              <span className="tabular">{row.asset_code}</span> · {row.asset_description}
                            </Td>
                            <Td>{row.category ?? '—'}</Td>
                            <Td>
                              <Money amountMinor={row.amount_minor} currency={previewQuery.data?.currency ?? 'EGP'} />
                            </Td>
                            <Td>
                              <Money amountMinor={row.accumulated_after_minor} currency={previewQuery.data?.currency ?? 'EGP'} />
                            </Td>
                            <Td>
                              <Money amountMinor={row.carrying_after_minor} currency={previewQuery.data?.currency ?? 'EGP'} />
                            </Td>
                          </tr>
                        ))}
                      </tbody>
                    </Table>
                  )}
                </div>
              ) : null
            ) : null}
          </Card>

          <Card>
            <CardHeader
              title={L('تشغيلات الإهلاك', 'Depreciation runs')}
              subtitle={L(
                'الترحيل مقيّد بالفترة المالية المفتوحة، ولا يمكن ترحيل نفس الأصل لنفس الشهر مرتين.',
                'Posting is refused in closed periods, and the same asset-month can never be posted twice.',
              )}
            />
            {runsQuery.isLoading ? (
              <Loading />
            ) : runsQuery.isError ? (
              <ErrorState message={errorMessage(runsQuery.error)} onRetry={() => void runsQuery.refetch()} />
            ) : (runsQuery.data ?? []).length === 0 ? (
              <EmptyState title={L('لا توجد تشغيلات', 'No runs yet')} hint={L('أنشئ مسودة تشغيل لفترة مفتوحة.', 'Create a draft run for an open period.')} />
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>{L('الرقم', 'Number')}</Th>
                    <Th>{L('الفترة', 'Period')}</Th>
                    <Th>{L('الأصول', 'Assets')}</Th>
                    <Th>{L('المبلغ', 'Amount')}</Th>
                    <Th>{L('الحالة', 'Status')}</Th>
                    <Th>{L('القيد', 'Journal')}</Th>
                    <Th>{L('ملاحظة', 'Note')}</Th>
                    <Th />
                  </tr>
                </thead>
                <tbody>
                  {(runsQuery.data ?? []).map((run) => (
                    <tr key={run.id}>
                      <Td>
                        <span className="tabular">{run.number}</span>
                      </Td>
                      <Td>{run.period}</Td>
                      <Td>{run.assets_count}</Td>
                      <Td>
                        <Money amountMinor={run.total_amount_minor} currency={run.currency ?? 'EGP'} />
                      </Td>
                      <Td>
                        <Badge tone={statusTone(run.status)}>{statusLabel(run.status)}</Badge>
                      </Td>
                      <Td className="text-small text-text-subtle">{run.journal_entry_id ?? '—'}</Td>
                      <Td className="text-small text-text-subtle">{run.failure_reason ?? run.notes ?? '—'}</Td>
                      <Td>
                        <div className="flex justify-end gap-2">
                          {run.status === 'posted' && approve ? (
                            <Button
                              variant="danger"
                              onClick={() => {
                                setError('')
                                setReverseTarget(run)
                              }}
                            >
                              {L('عكس', 'Reverse')}
                            </Button>
                          ) : null}
                          {(run.status === 'draft' || run.status === 'failed') && approve ? (
                            <Button variant="secondary" onClick={() => postRun.mutate(run)} disabled={postRun.isPending}>
                              {L('اعتماد وترحيل', 'Approve & post')}
                            </Button>
                          ) : null}
                          {(run.status === 'draft' || run.status === 'failed') && manage ? (
                            <Button variant="danger" onClick={() => deleteRun.mutate(run)} disabled={deleteRun.isPending}>
                              {L('حذف', 'Delete')}
                            </Button>
                          ) : null}
                        </div>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        </div>
      ) : null}

      {tab === 'reconciliation' ? (
        <Card>
          <CardHeader
            title={L('مطابقة السجل مع حسابات الضبط', 'Register vs. control accounts')}
            subtitle={L(
              'يقارن تكلفة السجل ومجمع الإهلاك مع أرصدة الأستاذ. التحويل بين الفروع يرحّل قيدًا لكل فرع، فتتطابق أرصدة كل فرع وأرصدة المنشأة.',
              'Compares register cost and accumulated depreciation to the ledger. Inter-branch transfers post a journal per branch, so each branch and the installation as a whole should agree.',
            )}
            actions={
              <div className="flex flex-wrap items-end gap-2">
                <Field label={L('كما في', 'As of')}>
                  <Input type="date" value={asOf} onChange={(event) => setAsOf(event.target.value)} />
                </Field>
                {viewAll ? (
                  <label className="flex h-9 items-center gap-2 text-medium text-text-default">
                    <input type="checkbox" checked={globalScope} onChange={(event) => setGlobalScope(event.target.checked)} />
                    {L('كل الفروع', 'All branches')}
                  </label>
                ) : null}
                <Button variant="secondary" onClick={() => void reconQuery.refetch()}>
                  {L('تحديث', 'Refresh')}
                </Button>
                {can('reports.export')
                  ? (['csv', 'xlsx', 'pdf'] as const).map((format) => (
                      <Button
                        key={format}
                        variant="secondary"
                        icon="download"
                        disabled={exportingFormat !== null}
                        onClick={() => void downloadReconciliation(format)}
                      >
                        {format.toUpperCase()}
                      </Button>
                    ))
                  : null}
              </div>
            }
          />
          {exportError ? <p className="px-5 py-2 text-small text-danger-text">{exportError}</p> : null}
          {reconQuery.isLoading ? (
            <Loading />
          ) : reconQuery.isError ? (
            <ErrorState message={errorMessage(reconQuery.error)} onRetry={() => void reconQuery.refetch()} />
          ) : reconQuery.data ? (
            <div className="space-y-4 px-5 py-4">
              <div className="flex flex-wrap items-center gap-3">
                <Badge tone={reconQuery.data.is_reconciled ? 'positive' : 'negative'}>
                  {reconQuery.data.is_reconciled ? L('متوازن', 'Reconciled') : L('فرق', 'Difference')}
                </Badge>
                <Badge tone="brand">{reconQuery.data.scope === 'global' ? L('كل الفروع', 'Global') : L('فرع', 'Branch')}</Badge>
                <span className="text-small text-text-subtle">
                  {L('فرق التكلفة', 'Cost difference')}:{' '}
                  <Money
                    amountMinor={reconQuery.data.cost_difference_minor}
                    currency={reconQuery.data.currency ?? 'EGP'}
                  />{' '}
                  · {L('فرق المجمع', 'Accumulated difference')}:{' '}
                  <Money
                    amountMinor={reconQuery.data.accumulated_difference_minor}
                    currency={reconQuery.data.currency ?? 'EGP'}
                  />
                </span>
              </div>
              <p className="text-small text-text-subtle">{reconQuery.data.note}</p>
              <Table>
                <thead>
                  <tr>
                    <Th>{L('التصنيف', 'Category')}</Th>
                    <Th>{L('تكلفة السجل', 'Register cost')}</Th>
                    <Th>{L('رصيد الأستاذ', 'GL cost')}</Th>
                    <Th>{L('الفرق', 'Difference')}</Th>
                    <Th>{L('مجمع السجل', 'Register accumulated')}</Th>
                    <Th>{L('مجمع الأستاذ', 'GL accumulated')}</Th>
                    <Th>{L('الفرق', 'Difference')}</Th>
                    <Th />
                  </tr>
                </thead>
                <tbody>
                  {reconQuery.data.categories.map((row) => (
                    <tr key={row.category_id}>
                      <Td>
                        <span className="tabular">{row.category_code}</span> · {row.category_name}
                      </Td>
                      <Td>
                        <Money amountMinor={row.register_cost_minor} currency={reconQuery.data?.currency ?? 'EGP'} />
                      </Td>
                      <Td>
                        {row.gl_cost_minor === null ? '—' : <Money amountMinor={row.gl_cost_minor} currency={reconQuery.data?.currency ?? 'EGP'} />}
                      </Td>
                      <Td>
                        {row.cost_difference_minor === null ? '—' : <Money amountMinor={row.cost_difference_minor} currency={reconQuery.data?.currency ?? 'EGP'} />}
                      </Td>
                      <Td>
                        <Money amountMinor={row.register_accumulated_minor} currency={reconQuery.data?.currency ?? 'EGP'} />
                      </Td>
                      <Td>
                        {row.gl_accumulated_minor === null ? '—' : <Money amountMinor={row.gl_accumulated_minor} currency={reconQuery.data?.currency ?? 'EGP'} />}
                      </Td>
                      <Td>
                        {row.accumulated_difference_minor === null ? '—' : <Money amountMinor={row.accumulated_difference_minor} currency={reconQuery.data?.currency ?? 'EGP'} />}
                      </Td>
                      <Td>
                        <Badge tone={row.is_reconciled ? 'positive' : 'negative'}>
                          {row.is_reconciled ? L('متوازن', 'OK') : L('فرق', 'Diff')}
                        </Badge>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>

              {(reconQuery.data.accounts ?? []).length > 0 ? (
                <div>
                  <p className="mb-2 text-medium font-medium text-text-default">{L('حسابات الأستاذ', 'Ledger accounts')}</p>
                  <p className="mb-2 text-small text-text-subtle">
                    {L(
                      'تُقارن كل حسابات الأستاذ مرة واحدة حتى لو شاركتها عدة تصنيفات.',
                      'Each ledger account is compared once, even when several categories share it.',
                    )}
                  </p>
                  <Table>
                    <thead>
                      <tr>
                        <Th>{L('الحساب', 'Account')}</Th>
                        <Th>{L('النوع', 'Role')}</Th>
                        <Th>{L('السجل', 'Register')}</Th>
                        <Th>{L('الأستاذ', 'Ledger')}</Th>
                        <Th>{L('الفرق', 'Difference')}</Th>
                        <Th />
                      </tr>
                    </thead>
                    <tbody>
                      {(reconQuery.data.accounts ?? []).map((row) => (
                        <tr key={`${row.role}-${row.account.code}`}>
                          <Td>
                            {accountLabel(row.account)}
                            <div className="text-small text-text-subtle">{row.category_codes.join(', ')}</div>
                          </Td>
                          <Td>{row.role === 'cost' ? L('التكلفة', 'Cost') : L('مجمع الإهلاك', 'Accumulated')}</Td>
                          <Td>
                            <Money amountMinor={row.register_minor} currency={reconQuery.data?.currency ?? 'EGP'} />
                          </Td>
                          <Td>
                            <Money amountMinor={row.gl_minor} currency={reconQuery.data?.currency ?? 'EGP'} />
                          </Td>
                          <Td>
                            <Money amountMinor={row.difference_minor} currency={reconQuery.data?.currency ?? 'EGP'} />
                          </Td>
                          <Td>
                            <Badge tone={row.is_reconciled ? 'positive' : 'negative'}>
                              {row.is_reconciled ? L('متوازن', 'OK') : L('فرق', 'Diff')}
                            </Badge>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                </div>
              ) : null}

              {(reconQuery.data.unregistered_accounts ?? []).length > 0 ? (
                <div>
                  <p className="mb-2 text-medium font-medium text-text-default">
                    {L('أرصدة أصول ثابتة غير مسجلة في السجل', 'Fixed-asset balances without a register category')}
                  </p>
                  <Table>
                    <thead>
                      <tr>
                        <Th>{L('الحساب', 'Account')}</Th>
                        <Th>{L('رصيد الأستاذ', 'Ledger balance')}</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {(reconQuery.data.unregistered_accounts ?? []).map((row) => (
                        <tr key={row.account.code}>
                          <Td>{accountLabel(row.account)}</Td>
                          <Td>
                            <Money amountMinor={row.gl_minor} currency={reconQuery.data?.currency ?? 'EGP'} />
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                </div>
              ) : null}
            </div>
          ) : null}
        </Card>
      ) : null}

      {tab === 'audit' ? (
        <Card>
          <CardHeader
            title={L('سجل التدقيق والتحويلات', 'Audit trail & transfers')}
            subtitle={L(
              'كل حدث على الأصل موثّق: الإنشاء، الاعتماد، التحويل، الإهلاك، الانخفاض والاستبعاد.',
              'Every lifecycle event is recorded: creation, capitalization, transfers, depreciation, impairment and disposal.',
            )}
            actions={
              <Select value={auditId} onChange={(event) => setAuditId(event.target.value)} className="w-72">
                <option value="">{L('اختر أصلاً', 'Choose an asset')}</option>
                {assets.map((asset) => (
                  <option key={asset.id} value={asset.id}>
                    {asset.code} · {asset.description}
                  </option>
                ))}
              </Select>
            }
          />
          {auditAsset === null ? (
            <EmptyState title={L('اختر أصلاً', 'Choose an asset')} hint={L('لعرض أحداثه وتحويلاته.', 'To view its events and transfers.')} />
          ) : auditQuery.isLoading ? (
            <Loading />
          ) : auditQuery.isError ? (
            <ErrorState message={errorMessage(auditQuery.error)} onRetry={() => void auditQuery.refetch()} />
          ) : auditQuery.data ? (
            <div className="space-y-4 px-5 py-4">
              <div className="flex flex-wrap items-center gap-3">
                <Badge tone={statusTone(auditQuery.data.asset.status)}>{statusLabel(auditQuery.data.asset.status)}</Badge>
                <span className="text-small text-text-subtle">
                  {auditQuery.data.asset.code} · {auditQuery.data.asset.description} ·{' '}
                  {L('القيمة الدفترية', 'Carrying value')}{' '}
                  <Money
                    amountMinor={auditQuery.data.asset.carrying_value_minor}
                    currency={auditQuery.data.base_currency ?? 'EGP'}
                  />
                </span>
              </div>

              <div>
                <p className="mb-2 text-medium font-medium text-text-default">{L('الأحداث', 'Events')}</p>
                {auditQuery.data.events.length === 0 ? (
                  <p className="text-small text-text-subtle">{L('لا توجد أحداث.', 'No events yet.')}</p>
                ) : (
                  <Table>
                    <thead>
                      <tr>
                        <Th>{L('الحدث', 'Action')}</Th>
                        <Th>{L('التاريخ', 'When')}</Th>
                        <Th>{L('المستخدم', 'Actor')}</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {auditQuery.data.events.map((event) => (
                        <tr key={event.id}>
                          <Td className="text-small">{event.action}</Td>
                          <Td>{formatDateTime(event.created_at, locale)}</Td>
                          <Td className="tabular">{event.actor_id ?? '—'}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                )}
              </div>

              <div>
                <p className="mb-2 text-medium font-medium text-text-default">{L('التحويلات', 'Transfers')}</p>
                {auditQuery.data.transfers.length === 0 ? (
                  <p className="text-small text-text-subtle">{L('لا توجد تحويلات.', 'No transfers.')}</p>
                ) : (
                  <Table>
                    <thead>
                      <tr>
                        <Th>{L('التاريخ', 'Date')}</Th>
                        <Th>{L('من', 'From')}</Th>
                        <Th>{L('إلى', 'To')}</Th>
                        <Th>{L('السبب', 'Reason')}</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {auditQuery.data.transfers.map((transfer) => (
                        <tr key={transfer.id}>
                          <Td>{formatDate(transfer.transfer_date, locale)}</Td>
                          <Td>{transfer.from_branch ? (localizedName(transfer.from_branch, locale)) : '—'}</Td>
                          <Td>{transfer.to_branch ? (localizedName(transfer.to_branch, locale)) : '—'}</Td>
                          <Td className="text-small text-text-subtle">{transfer.reason ?? '—'}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                )}
              </div>
            </div>
          ) : null}
        </Card>
      ) : null}

      <Modal
        open={assetFormOpen}
        title={L('أصل ثابت جديد', 'New fixed asset')}
        onClose={() => setAssetFormOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setAssetFormOpen(false)}>
              {L('إلغاء', 'Cancel')}
            </Button>
            <Button
              onClick={() => createAsset.mutate()}
              disabled={createAsset.isPending || !assetForm.category_id || !assetForm.description || !assetForm.acquisition_value || assetFormError !== ''}
            >
              {L('حفظ كمسودة', 'Save as draft')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
        {assetFormError && assetForm.acquisition_value ? <p className="mb-4 text-small text-danger-text">{assetFormError}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('التصنيف', 'Category')} required>
            <Select
              value={assetForm.category_id}
              onChange={(event) => {
                const category = (categoriesQuery.data ?? []).find((item) => item.id === event.target.value)
                setAssetForm({
                  ...assetForm,
                  category_id: event.target.value,
                  useful_life_months: String(category?.default_useful_life_months ?? assetForm.useful_life_months),
                })
              }}
            >
              <option value="">{L('اختر تصنيفاً', 'Choose a category')}</option>
              {(categoriesQuery.data ?? []).map((category) => (
                <option key={category.id} value={category.id}>
                  {category.code} · {localizedName(category, locale)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={L('البيان', 'Description')} required>
            <Input value={assetForm.description} onChange={(event) => setAssetForm({ ...assetForm, description: event.target.value })} maxLength={255} />
          </Field>
          <Field label={L('الموقع', 'Location')}>
            <Input value={assetForm.location} onChange={(event) => setAssetForm({ ...assetForm, location: event.target.value })} maxLength={255} />
          </Field>
          <Field label={L('العملة', 'Currency')} hint={L('تُحوّل القيمة إلى العملة الأساسية عند الحفظ.', 'The value is converted to base currency on save.')}>
            <Input value={assetForm.currency} onChange={(event) => setAssetForm({ ...assetForm, currency: event.target.value.toUpperCase().slice(0, 3) })} maxLength={3} />
          </Field>
          {assetForm.currency !== assetBase ? (
            <Field label={L('سعر الصرف', 'Exchange rate')} hint={L('اتركه فارغاً لاستخدام سعر السوق في تاريخ الرسملة.', 'Leave empty to use the market rate on the capitalization date.')}>
              <Input inputMode="decimal" value={assetForm.fx_rate} onChange={(event) => setAssetForm({ ...assetForm, fx_rate: event.target.value })} />
            </Field>
          ) : null}
          <Field
            label={L('قيد التكلفة المسجل', 'Booked cost journal')}
            hint={L(
              'اختياري: معرّف قيد مرحّل سبق أن حمّل حساب التكلفة (فاتورة مورد/مصروف). عندها لا يُرحَّل قيد رسملة ثانٍ.',
              'Optional: id of a posted journal that already debited the cost account (supplier bill / expense). No second capitalization entry is posted.',
            )}
          >
            <Input value={assetForm.acquisition_journal_entry_id} onChange={(event) => setAssetForm({ ...assetForm, acquisition_journal_entry_id: event.target.value })} maxLength={40} />
          </Field>
          <Field label={L('تاريخ الرسملة', 'Capitalization date')} required>
            <Input type="date" value={assetForm.capitalization_date} onChange={(event) => setAssetForm({ ...assetForm, capitalization_date: event.target.value })} />
          </Field>
          <Field label={L('تاريخ بدء الخدمة', 'In-service date')}>
            <Input type="date" value={assetForm.in_service_date} onChange={(event) => setAssetForm({ ...assetForm, in_service_date: event.target.value })} />
          </Field>
          <Field label={L('قيمة الاقتناء', 'Acquisition value')} required>
            <Input inputMode="decimal" value={assetForm.acquisition_value} onChange={(event) => setAssetForm({ ...assetForm, acquisition_value: event.target.value })} placeholder="12000.00" />
          </Field>
          <Field label={L('القيمة المتبقية', 'Residual value')}>
            <Input inputMode="decimal" value={assetForm.residual_value} onChange={(event) => setAssetForm({ ...assetForm, residual_value: event.target.value })} />
          </Field>
          <Field label={L('العمر الإنتاجي (شهر)', 'Useful life (months)')} required>
            <Input type="number" min={1} max={600} value={assetForm.useful_life_months} onChange={(event) => setAssetForm({ ...assetForm, useful_life_months: event.target.value })} />
          </Field>
          <Field label={L('أساس أول شهر', 'First-month convention')} hint={L('سياسة قابلة للتغيير من الإعدادات.', 'Policy is configurable in settings.')}>
            <Select
              value={assetForm.depreciation_convention}
              onChange={(event) => setAssetForm({ ...assetForm, depreciation_convention: event.target.value as Asset['depreciation_convention'] })}
            >
              <option value="month_of_purchase">{statusLabel('month_of_purchase')}</option>
              <option value="next_month">{statusLabel('next_month')}</option>
              <option value="prorate_days">{statusLabel('prorate_days')}</option>
            </Select>
          </Field>
          <Field label={L('ملاحظات', 'Notes')}>
            <Input value={assetForm.notes} onChange={(event) => setAssetForm({ ...assetForm, notes: event.target.value })} maxLength={2000} />
          </Field>
        </div>
      </Modal>

      <Modal
        open={categoryOpen}
        title={L('تصنيف أصول جديد', 'New asset category')}
        onClose={() => setCategoryOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCategoryOpen(false)}>
              {L('إلغاء', 'Cancel')}
            </Button>
            <Button onClick={() => createCategory.mutate()} disabled={createCategory.isPending || !categoryForm.name_ar.trim() || !categoryForm.name_en.trim()}>
              {L('حفظ', 'Save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <CodeField label={L('الكود', 'Code')} ar={locale === 'ar'} />
          <Field label={L('الاسم بالعربية', 'Arabic name')} required>
            <Input value={categoryForm.name_ar} onChange={(event) => setCategoryForm({ ...categoryForm, name_ar: event.target.value })} maxLength={255} />
          </Field>
          <Field label={L('الاسم بالإنجليزية', 'English name')} required>
            <Input value={categoryForm.name_en} onChange={(event) => setCategoryForm({ ...categoryForm, name_en: event.target.value })} maxLength={255} />
          </Field>
          <Field label={L('العمر الافتراضي (شهر)', 'Default life (months)')}>
            <Input
              type="number"
              min={1}
              max={600}
              value={categoryForm.default_useful_life_months}
              onChange={(event) => setCategoryForm({ ...categoryForm, default_useful_life_months: event.target.value })}
            />
          </Field>
        </div>
        <p className="text-small mt-4 text-text-subtle">
          {L(
            'تُستخدم حسابات الضبط الافتراضية: الأصل، مجمع الإهلاك، مصروف الإهلاك، وأرباح/خسائر الاستبعاد. يمكن تعديلها لاحقاً عبر إعدادات التصنيف.',
            'The default control accounts are used: cost, accumulated depreciation, depreciation expense and disposal gain/loss. They can be adjusted on the category later.',
          )}
        </p>
      </Modal>

      <Modal
        open={lifecycle !== null}
        title={
          lifecycle?.mode === 'transfer'
            ? L('تحويل الأصل', 'Transfer asset')
            : lifecycle?.mode === 'dispose'
              ? L('استبعاد الأصل', 'Dispose asset')
              : L('انخفاض قيمة الأصل', 'Asset impairment')
        }
        onClose={() => setLifecycle(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setLifecycle(null)}>
              {L('رجوع', 'Back')}
            </Button>
            <Button
              variant={lifecycle?.mode === 'dispose' ? 'danger' : 'primary'}
              onClick={() => lifecycle && lifecycleMutation.mutate(lifecycle)}
              disabled={
                lifecycleMutation.isPending ||
                (lifecycle?.mode === 'transfer' && !lifecycle.toBranchId) ||
                (lifecycle?.mode === 'impair' && !lifecycle.impairmentAmount)
              }
            >
              {lifecycle?.mode === 'transfer'
                ? L('تأكيد التحويل', 'Confirm transfer')
                : lifecycle?.mode === 'dispose'
                  ? L('تأكيد الاستبعاد', 'Confirm disposal')
                  : L('إنشاء سند الانخفاض', 'Create impairment voucher')}
            </Button>
          </>
        }
      >
        {lifecycle ? (
          <div className="space-y-4">
            {error ? <p className="rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
            <p className="text-medium text-text-subtle">
              {lifecycle.asset.code} · {lifecycle.asset.description} ·{' '}
              {L('القيمة الدفترية', 'Carrying value')}{' '}
              <Money amountMinor={lifecycle.asset.carrying_value_minor} currency={baseCurrency(lifecycle.asset)} />
            </p>

            {lifecycle.mode === 'transfer' ? (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label={L('إلى فرع', 'To branch')} required>
                  <Select value={lifecycle.toBranchId} onChange={(event) => setLifecycle({ ...lifecycle, toBranchId: event.target.value })}>
                    <option value="">{L('اختر فرعاً', 'Choose a branch')}</option>
                    {branches
                      .filter((branch) => branch.id !== lifecycle.asset.branch_id)
                      .map((branch) => (
                        <option key={branch.id} value={branch.id}>
                          {localizedName(branch, locale)}
                        </option>
                      ))}
                  </Select>
                </Field>
                <Field label={L('تاريخ التحويل', 'Transfer date')} required>
                  <Input type="date" value={lifecycle.transferDate} onChange={(event) => setLifecycle({ ...lifecycle, transferDate: event.target.value })} />
                </Field>
                <Field label={L('السبب', 'Reason')}>
                  <Input value={lifecycle.reason} onChange={(event) => setLifecycle({ ...lifecycle, reason: event.target.value })} maxLength={255} />
                </Field>
              </div>
            ) : null}

            {lifecycle.mode === 'dispose' ? (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label={L('تاريخ الاستبعاد', 'Disposal date')} required>
                  <Input type="date" value={lifecycle.disposalDate} onChange={(event) => setLifecycle({ ...lifecycle, disposalDate: event.target.value })} />
                </Field>
                <Field label={L('نوع الاستبعاد', 'Disposal type')}>
                  <Select
                    value={lifecycle.disposalType}
                    onChange={(event) => setLifecycle({ ...lifecycle, disposalType: event.target.value as LifecycleState['disposalType'] })}
                  >
                    <option value="sale">{statusLabel('sale')}</option>
                    <option value="retirement">{statusLabel('retirement')}</option>
                    <option value="scrap">{statusLabel('scrap')}</option>
                  </Select>
                </Field>
                <Field label={L('حصيلة البيع', 'Proceeds')} hint={L('اتركها صفراً للإخراج بدون حصيلة.', 'Leave at zero for a retirement without proceeds.')}>
                  <Input inputMode="decimal" value={lifecycle.proceeds} onChange={(event) => setLifecycle({ ...lifecycle, proceeds: event.target.value })} />
                </Field>
                <Field label={L('ملاحظات', 'Notes')}>
                  <Input value={lifecycle.reason} onChange={(event) => setLifecycle({ ...lifecycle, reason: event.target.value })} maxLength={255} />
                </Field>
              </div>
            ) : null}

            {lifecycle.mode === 'dispose' ? (
              <p className="text-small text-text-subtle">
                {L(
                  'يُحتسب الإهلاك حتى تاريخ الاستبعاد داخل قيد الاستبعاد. يجب ترحيل الأشهر السابقة أولاً، ولا يسبق التاريخ نهاية آخر شهر مرحّل.',
                  'Depreciation runs to the disposal date inside the disposal entry. Earlier months must be posted first, and the date cannot precede the end of the last posted month.',
                )}
              </p>
            ) : null}

            {lifecycle.mode === 'impair' ? (
              <div className="space-y-4">
                <p className="text-medium text-text-subtle">
                  {L(
                    'ينشئ سند تسوية مسودة أو مُقدَّم. لا يغيّر السجل إلا بعد ترحيل السند واعتماده من صفحة السندات.',
                    'Creates a draft or submitted adjustment voucher. The register only changes after the voucher is posted and applied from the vouchers page.',
                  )}
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label={L('مبلغ الانخفاض', 'Impairment amount')} required>
                    <Input inputMode="decimal" value={lifecycle.impairmentAmount} onChange={(event) => setLifecycle({ ...lifecycle, impairmentAmount: event.target.value })} />
                  </Field>
                  <Field label={L('تاريخ المستند', 'Document date')}>
                    <Input type="date" value={lifecycle.documentDate} onChange={(event) => setLifecycle({ ...lifecycle, documentDate: event.target.value })} />
                  </Field>
                  <Field label={L('ملاحظة السند', 'Voucher memo')}>
                    <Input value={lifecycle.memo} onChange={(event) => setLifecycle({ ...lifecycle, memo: event.target.value })} maxLength={255} />
                  </Field>
                </div>
                <label className="flex items-center gap-2 text-medium text-text-default">
                  <input type="checkbox" checked={lifecycle.submit} onChange={(event) => setLifecycle({ ...lifecycle, submit: event.target.checked })} />
                  {L('تقديم السند للاعتماد مباشرة', 'Submit the voucher for approval immediately')}
                </label>
              </div>
            ) : null}

            {lifecycle.mode === 'transfer' ? (
              <p className="text-small text-text-subtle">
                {L(
                  'عند تغيير الفرع يُرحَّل قيدان متوازنان بصافي القيمة الدفترية (قيد في كل فرع عبر حسابي الفروع الجاري)، ويجب أن يكون تاريخ التحويل في فترة مفتوحة. تغيير مركز التكلفة أو الحائز فقط لا ينشئ قيدًا.',
                  'Changing the branch posts two balanced journals at net book value (one per branch, through the inter-branch clearing accounts); the date must fall in an open period. Changing only the cost center or custodian posts nothing.',
                )}
              </p>
            ) : null}
          </div>
        ) : null}
      </Modal>

      <Modal
        open={reverseTarget !== null}
        title={L('عكس تشغيل الإهلاك', 'Reverse depreciation run')}
        onClose={() => setReverseTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setReverseTarget(null)}>
              {L('رجوع', 'Back')}
            </Button>
            <Button
              variant="danger"
              onClick={() => reverseTarget && reverseRun.mutate({ run: reverseTarget, reason: reverseReason.trim() })}
              disabled={reverseRun.isPending || reverseReason.trim() === ''}
            >
              {L('تأكيد العكس', 'Confirm reversal')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
        <p className="mb-4 text-medium text-text-subtle">
          {reverseTarget ? `${reverseTarget.number} · ${reverseTarget.period}. ` : ''}
          {L(
            'يُنشأ قيد عكسي ويُعاد السجل إلى ما قبل التشغيل، ثم يمكن إعادة تشغيل الفترة. لا يُعكس إلا آخر فترة لكل أصل.',
            'A reversing journal is posted and the register returns to its state before the run, so the period can be run again. Only the latest period of each asset can be reversed.',
          )}
        </p>
        <Field label={L('سبب العكس', 'Reason')} required>
          <Input value={reverseReason} onChange={(event) => setReverseReason(event.target.value)} maxLength={255} />
        </Field>
      </Modal>

      <Modal open={detail !== null} title={detail ? `${detail.code} · ${detail.description}` : ''} onClose={() => setDetailId(null)}>
        {detail ? (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={statusTone(detail.status)}>{statusLabel(detail.status)}</Badge>
              <Badge tone="brand">{categoryLabel(detail.category)}</Badge>
              <span className="text-small text-text-subtle">
                {L('الفرع', 'Branch')}: {detail.branch ? (localizedName(detail.branch, locale)) : '—'} ·{' '}
                {L('الرسملة', 'Capitalized')}: {formatDate(detail.capitalization_date, locale)}
              </span>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
              <div>
                <p className="text-small text-text-subtle">{L('التكلفة', 'Cost')}</p>
                <p className="tabular text-medium">
                  <Money amountMinor={detail.acquisition_value_base_minor} currency={detailCurrency} />
                </p>
              </div>
              <div>
                <p className="text-small text-text-subtle">{L('مجمع الإهلاك', 'Accumulated')}</p>
                <p className="tabular text-medium">
                  <Money amountMinor={detail.total_accumulated_minor} currency={detailCurrency} />
                </p>
              </div>
              <div>
                <p className="text-small text-text-subtle">{L('القيمة الدفترية', 'Carrying value')}</p>
                <p className="tabular text-medium">
                  <Money amountMinor={detail.carrying_value_minor} currency={detailCurrency} />
                </p>
              </div>
              <div>
                <p className="text-small text-text-subtle">{L('الإهلاك الشهري', 'Monthly depreciation')}</p>
                <p className="tabular text-medium">
                  <Money amountMinor={detail.monthly_depreciation_minor} currency={detailCurrency} />
                </p>
              </div>
              <div>
                <p className="text-small text-text-subtle">{L('أول فترة', 'First period')}</p>
                <p className="tabular text-medium">{detail.first_depreciation_period}</p>
              </div>
              <div>
                <p className="text-small text-text-subtle">{L('آخر فترة مرحّلة', 'Last posted period')}</p>
                <p className="tabular text-medium">{detail.last_depreciated_period ?? '—'}</p>
              </div>
            </div>

            <div className="flex flex-wrap gap-2">
              {detail.status === 'draft' && approve ? (
                <Button variant="secondary" onClick={() => capitalize.mutate(detail)} disabled={capitalize.isPending}>
                  {L('اعتماد الرسملة', 'Capitalize')}
                </Button>
              ) : null}
              {detail.status === 'active' || detail.status === 'fully_depreciated' ? (
                <>
                  {manage ? (
                    <Button variant="secondary" onClick={() => openLifecycle('transfer', detail)}>
                      {L('تحويل', 'Transfer')}
                    </Button>
                  ) : null}
                  {manage ? (
                    <Button variant="secondary" onClick={() => openLifecycle('impair', detail)}>
                      {L('انخفاض القيمة', 'Impairment')}
                    </Button>
                  ) : null}
                  {approve ? (
                    <Button variant="danger" onClick={() => openLifecycle('dispose', detail)}>
                      {L('استبعاد', 'Dispose')}
                    </Button>
                  ) : null}
                </>
              ) : null}
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <p className="text-small text-text-subtle">
                {L('قيد الرسملة', 'Capitalization journal')}: {detail.capitalization_journal_entry_id ?? '—'}
              </p>
              <p className="text-small text-text-subtle">
                {L('قيد الاستبعاد', 'Disposal journal')}: {detail.disposal_journal_entry_id ?? '—'}
              </p>
              <p className="text-small text-text-subtle">
                {L('حساب الأصل', 'Cost account')}: {accountLabel(detail.category?.asset_account ?? null)}
              </p>
              <p className="text-small text-text-subtle">
                {L('مجمع الإهلاك', 'Accumulated account')}: {accountLabel(detail.category?.accumulated_depreciation_account ?? null)}
              </p>
              <p className="text-small text-text-subtle">
                {L('مصروف الإهلاك', 'Expense account')}: {accountLabel(detail.category?.depreciation_expense_account ?? null)}
              </p>
              <p className="text-small text-text-subtle">
                {L('المرفقات', 'Attachments')}: {detail.attachments.length}
              </p>
            </div>

            {detail.impairment_document_ids.filter((document) => !detail.applied_impairment_document_ids.includes(document)).length > 0 ? (
              <div>
                <p className="mb-2 text-medium font-medium text-text-default">{L('سندات انخفاض غير مطبقة', 'Unapplied impairment vouchers')}</p>
                <div className="space-y-2">
                  {detail.impairment_document_ids
                    .filter((document) => !detail.applied_impairment_document_ids.includes(document))
                    .map((document) => (
                      <div key={document} className="flex items-center justify-between gap-2 rounded-12 border border-border-subtle px-3 py-2">
                        <span className="text-small tabular text-text-subtle">{document}</span>
                        {approve ? (
                          <Button
                            variant="secondary"
                            onClick={() => applyImpairment.mutate({ asset: detail, document })}
                            disabled={applyImpairment.isPending}
                          >
                            {L('تطبيق', 'Apply')}
                          </Button>
                        ) : null}
                      </div>
                    ))}
                </div>
              </div>
            ) : null}

            <div>
              <p className="mb-2 text-medium font-medium text-text-default">{L('جدول الإهلاك', 'Depreciation schedule')}</p>
              {scheduleQuery.isLoading ? (
                <Loading />
              ) : scheduleQuery.isError ? (
                <ErrorState message={errorMessage(scheduleQuery.error)} onRetry={() => void scheduleQuery.refetch()} />
              ) : (scheduleQuery.data ?? []).length === 0 ? (
                <p className="text-small text-text-subtle">{L('لا يوجد جدول.', 'No schedule.')}</p>
              ) : (
                <Table>
                  <thead>
                    <tr>
                      <Th>{L('الفترة', 'Period')}</Th>
                      <Th>{L('المبلغ', 'Amount')}</Th>
                      <Th>{L('المجمع', 'Accumulated')}</Th>
                      <Th>{L('القيمة الدفترية', 'Carrying value')}</Th>
                      <Th>{L('الحالة', 'Status')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {scheduleQuery.data?.map((row) => (
                      <tr key={row.period}>
                        <Td className="tabular">{row.period}</Td>
                        <Td>
                          <Money amountMinor={row.amount_minor} currency={detailCurrency} />
                        </Td>
                        <Td>
                          <Money amountMinor={row.accumulated_minor} currency={detailCurrency} />
                        </Td>
                        <Td>
                          <Money amountMinor={row.carrying_value_minor} currency={detailCurrency} />
                        </Td>
                        <Td>
                          <Badge tone={statusTone(row.status)}>{statusLabel(row.status)}</Badge>
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}
