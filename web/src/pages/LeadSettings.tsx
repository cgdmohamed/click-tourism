import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { LeadSourceConfig, LeadStageConfig } from '../lib/types'
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
  PageHeader,
  Table,
  Td,
  Th,
} from '../components/ui'

type SourceForm = { code: string; name_ar: string; name_en: string; is_active: boolean }
type StageForm = { code: string; name_ar: string; name_en: string; sort_order: string; is_won: boolean; is_lost: boolean }

const emptySource: SourceForm = { code: '', name_ar: '', name_en: '', is_active: true }
const emptyStage: StageForm = { code: '', name_ar: '', name_en: '', sort_order: '0', is_won: false, is_lost: false }

export default function LeadSettings() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const canManage = can('leads.manage_rules')
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [sourceOpen, setSourceOpen] = useState(false)
  const [sourceCode, setSourceCode] = useState<string | null>(null)
  const [sourceForm, setSourceForm] = useState<SourceForm>(emptySource)
  const [sourceError, setSourceError] = useState('')

  const [stageOpen, setStageOpen] = useState(false)
  const [stageCode, setStageCode] = useState<string | null>(null)
  const [stageForm, setStageForm] = useState<StageForm>(emptyStage)
  const [stageError, setStageError] = useState('')

  const sourcesQuery = useQuery({
    queryKey: ['lead-sources', 'include_inactive'],
    queryFn: async () => {
      const { data } = await api.get<{ data: LeadSourceConfig[] }>('/lead-sources', {
        params: { include_inactive: 1 },
      })
      return data.data
    },
  })

  const stagesQuery = useQuery({
    queryKey: ['lead-stages'],
    queryFn: async () => {
      const { data } = await api.get<{ data: LeadStageConfig[] }>('/lead-stages')
      return data.data
    },
  })

  const sources = sourcesQuery.data ?? []
  const stages = stagesQuery.data ?? []

  const saveSource = useMutation({
    mutationFn: async () => {
      const payload = {
        name_ar: sourceForm.name_ar,
        name_en: sourceForm.name_en,
        is_active: sourceForm.is_active,
      }
      if (sourceCode) {
        const { data } = await api.patch<{ data: LeadSourceConfig }>(`/lead-sources/${sourceCode}`, payload)
        return data.data
      }
      const { data } = await api.post<{ data: LeadSourceConfig }>('/lead-sources', {
        ...payload,
        code: sourceForm.code.trim(),
      })
      return data.data
    },
    onSuccess: async () => {
      setSourceOpen(false)
      setSourceCode(null)
      setSourceError('')
      setSourceForm(emptySource)
      await queryClient.invalidateQueries({ queryKey: ['lead-sources'] })
    },
    onError: (cause) => setSourceError(errorMessage(cause)),
  })

  const deleteSource = useMutation({
    mutationFn: async (code: string) => {
      await api.delete(`/lead-sources/${code}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['lead-sources'] })
    },
    onError: (cause) => setSourceError(errorMessage(cause)),
  })

  const saveStage = useMutation({
    mutationFn: async () => {
      const payload = {
        name_ar: stageForm.name_ar,
        name_en: stageForm.name_en,
        sort_order: parseInt(stageForm.sort_order, 10) || 0,
        is_won: stageForm.is_won,
        is_lost: stageForm.is_lost,
      }
      if (stageCode) {
        const { data } = await api.patch<{ data: LeadStageConfig }>(`/lead-stages/${stageCode}`, payload)
        return data.data
      }
      const { data } = await api.post<{ data: LeadStageConfig }>('/lead-stages', {
        ...payload,
        code: stageForm.code.trim(),
      })
      return data.data
    },
    onSuccess: async () => {
      setStageOpen(false)
      setStageCode(null)
      setStageError('')
      setStageForm(emptyStage)
      await queryClient.invalidateQueries({ queryKey: ['lead-stages'] })
    },
    onError: (cause) => setStageError(errorMessage(cause)),
  })

  const deleteStage = useMutation({
    mutationFn: async (code: string) => {
      await api.delete(`/lead-stages/${code}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['lead-stages'] })
    },
    onError: (cause) => setStageError(errorMessage(cause)),
  })

  const openSource = (source?: LeadSourceConfig) => {
    setSourceError('')
    if (source) {
      setSourceCode(source.code)
      setSourceForm({
        code: source.code,
        name_ar: source.name_ar,
        name_en: source.name_en,
        is_active: source.is_active,
      })
    } else {
      setSourceCode(null)
      setSourceForm(emptySource)
    }
    setSourceOpen(true)
  }

  const openStage = (stage?: LeadStageConfig) => {
    setStageError('')
    if (stage) {
      setStageCode(stage.code)
      setStageForm({
        code: stage.code,
        name_ar: stage.name_ar,
        name_en: stage.name_en,
        sort_order: String(stage.sort_order),
        is_won: stage.is_won,
        is_lost: stage.is_lost,
      })
    } else {
      setStageCode(null)
      setStageForm(emptyStage)
    }
    setStageOpen(true)
  }

  return (
    <div>
      <PageHeader
        title={L('مصادر ومراحل العملاء المحتملين', 'Lead Sources & Stages')}
        subtitle={L(
          'تخصيص قنوات جذب العملاء ومراحل خط الأنابيب المستخدمة في كل الوحدات',
          'Configure the acquisition channels and pipeline stages used across the CRM',
        )}
        icon="target"
      />

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <CardHeader
            title={L('المصادر', 'Sources')}
            subtitle={L('قنوات جذب العميل المحتمل', 'Where leads come from')}
            icon="globe"
            actions={
              canManage ? (
                <Button icon="plus" onClick={() => openSource()}>
                  {L('مصدر جديد', 'New source')}
                </Button>
              ) : null
            }
          />
          {sourcesQuery.isLoading ? <Loading /> : null}
          {sourcesQuery.isError ? (
            <ErrorState message={errorMessage(sourcesQuery.error)} onRetry={() => void sourcesQuery.refetch()} />
          ) : null}
          {!sourcesQuery.isLoading && sources.length === 0 ? <EmptyState /> : null}
          {sources.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <Th>{L('الكود', 'Code')}</Th>
                  <Th>{L('الاسم (عربي)', 'Name (AR)')}</Th>
                  <Th>{L('الاسم (إنجليزي)', 'Name (EN)')}</Th>
                  <Th>{t('status')}</Th>
                  <Th className="text-end">{L('الإجراءات', 'Actions')}</Th>
                </tr>
              </thead>
              <tbody>
                {sources.map((source) => (
                  <tr key={source.code}>
                    <Td className="font-medium" dir="ltr">{source.code}</Td>
                    <Td>{source.name_ar}</Td>
                    <Td>{source.name_en}</Td>
                    <Td>
                      <Badge tone={source.is_active ? 'positive' : 'neutral'}>
                        {source.is_active ? L('مفعل', 'Active') : L('معطل', 'Inactive')}
                      </Badge>
                    </Td>
                    <Td className="text-end">
                      {canManage ? (
                        <>
                          <Button variant="ghost" icon="edit" onClick={() => openSource(source)} />
                          <Button
                            variant="ghost"
                            icon="trash"
                            onClick={() => {
                              if (window.confirm(L(`حذف المصدر ${source.code}؟`, `Delete source ${source.code}?`))) {
                                deleteSource.mutate(source.code)
                              }
                            }}
                          />
                        </>
                      ) : null}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
          {sourceError && !sourceOpen ? <p className="px-5 py-3 text-sm text-danger-text">{sourceError}</p> : null}
        </Card>

        <Card>
          <CardHeader
            title={L('المراحل', 'Stages')}
            subtitle={L('ترتيب خط أنابيب المبيعات وحالات البيع/الخسارة', 'Pipeline order and won/lost semantics')}
            icon="listChecks"
            actions={
              canManage ? (
                <Button icon="plus" onClick={() => openStage()}>
                  {L('مرحلة جديدة', 'New stage')}
                </Button>
              ) : null
            }
          />
          {stagesQuery.isLoading ? <Loading /> : null}
          {stagesQuery.isError ? (
            <ErrorState message={errorMessage(stagesQuery.error)} onRetry={() => void stagesQuery.refetch()} />
          ) : null}
          {!stagesQuery.isLoading && stages.length === 0 ? <EmptyState /> : null}
          {stages.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <Th>{L('الترتيب', 'Order')}</Th>
                  <Th>{L('الكود', 'Code')}</Th>
                  <Th>{L('الاسم (عربي)', 'Name (AR)')}</Th>
                  <Th>{L('الاسم (إنجليزي)', 'Name (EN)')}</Th>
                  <Th>{L('النوع', 'Kind')}</Th>
                  <Th className="text-end">{L('الإجراءات', 'Actions')}</Th>
                </tr>
              </thead>
              <tbody>
                {stages.map((stage) => (
                  <tr key={stage.code}>
                    <Td className="tabular">{stage.sort_order}</Td>
                    <Td className="font-medium" dir="ltr">{stage.code}</Td>
                    <Td>{stage.name_ar}</Td>
                    <Td>{stage.name_en}</Td>
                    <Td>
                      {stage.is_won ? (
                        <Badge tone="positive">{L('تم البيع', 'Won')}</Badge>
                      ) : stage.is_lost ? (
                        <Badge tone="negative">{L('خسارة', 'Lost')}</Badge>
                      ) : (
                        <Badge>{L('قيد التقدم', 'In progress')}</Badge>
                      )}
                    </Td>
                    <Td className="text-end">
                      {canManage ? (
                        <>
                          <Button variant="ghost" icon="edit" onClick={() => openStage(stage)} />
                          <Button
                            variant="ghost"
                            icon="trash"
                            onClick={() => {
                              if (window.confirm(L(`حذف المرحلة ${stage.code}؟`, `Delete stage ${stage.code}?`))) {
                                deleteStage.mutate(stage.code)
                              }
                            }}
                          />
                        </>
                      ) : null}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
          {stageError && !stageOpen ? <p className="px-5 py-3 text-sm text-danger-text">{stageError}</p> : null}
        </Card>
      </div>

      <Modal
        open={sourceOpen}
        title={sourceCode ? L('تعديل المصدر', 'Edit source') : L('مصدر جديد', 'New source')}
        onClose={() => setSourceOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setSourceOpen(false)}>
              {t('cancel')}
            </Button>
            <Button
              disabled={saveSource.isPending || !sourceForm.name_ar || !sourceForm.name_en || (!sourceCode && !sourceForm.code)}
              onClick={() => saveSource.mutate()}
            >
              {saveSource.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('الكود', 'Code')} required hint={sourceCode ? L('لا يمكن تغيير الكود بعد الإنشاء', 'The code cannot change after creation') : undefined}>
            <Input
              value={sourceForm.code}
              onChange={(event) => setSourceForm({ ...sourceForm, code: event.target.value })}
              disabled={sourceCode !== null}
              dir="ltr"
            />
          </Field>
          <Field label={L('الاسم (عربي)', 'Name (AR)')} required>
            <Input value={sourceForm.name_ar} onChange={(event) => setSourceForm({ ...sourceForm, name_ar: event.target.value })} />
          </Field>
          <Field label={L('الاسم (إنجليزي)', 'Name (EN)')} required>
            <Input value={sourceForm.name_en} onChange={(event) => setSourceForm({ ...sourceForm, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('مفعل', 'Active')}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input
                type="checkbox"
                checked={sourceForm.is_active}
                onChange={(event) => setSourceForm({ ...sourceForm, is_active: event.target.checked })}
              />
              {L('المصدر متاح للاختيار', 'Source is selectable')}
            </label>
          </Field>
        </div>
        {sourceError ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{sourceError}</p> : null}
      </Modal>

      <Modal
        open={stageOpen}
        title={stageCode ? L('تعديل المرحلة', 'Edit stage') : L('مرحلة جديدة', 'New stage')}
        onClose={() => setStageOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setStageOpen(false)}>
              {t('cancel')}
            </Button>
            <Button
              disabled={saveStage.isPending || !stageForm.name_ar || !stageForm.name_en || (!stageCode && !stageForm.code)}
              onClick={() => saveStage.mutate()}
            >
              {saveStage.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('الكود', 'Code')} required hint={stageCode ? L('لا يمكن تغيير الكود بعد الإنشاء', 'The code cannot change after creation') : undefined}>
            <Input
              value={stageForm.code}
              onChange={(event) => setStageForm({ ...stageForm, code: event.target.value })}
              disabled={stageCode !== null}
              dir="ltr"
            />
          </Field>
          <Field label={L('الترتيب', 'Order')}>
            <Input
              value={stageForm.sort_order}
              onChange={(event) => setStageForm({ ...stageForm, sort_order: event.target.value })}
              inputMode="numeric"
              dir="ltr"
            />
          </Field>
          <Field label={L('الاسم (عربي)', 'Name (AR)')} required>
            <Input value={stageForm.name_ar} onChange={(event) => setStageForm({ ...stageForm, name_ar: event.target.value })} />
          </Field>
          <Field label={L('الاسم (إنجليزي)', 'Name (EN)')} required>
            <Input value={stageForm.name_en} onChange={(event) => setStageForm({ ...stageForm, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('مرحلة بيع', 'Won stage')}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input
                type="checkbox"
                checked={stageForm.is_won}
                onChange={(event) =>
                  setStageForm({ ...stageForm, is_won: event.target.checked, is_lost: event.target.checked ? false : stageForm.is_lost })
                }
              />
              {L('الوصول لهذه المرحلة يعني بيعًا', 'Reaching this stage marks the lead won')}
            </label>
          </Field>
          <Field label={L('مرحلة خسارة', 'Lost stage')}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input
                type="checkbox"
                checked={stageForm.is_lost}
                onChange={(event) =>
                  setStageForm({ ...stageForm, is_lost: event.target.checked, is_won: event.target.checked ? false : stageForm.is_won })
                }
              />
              {L('الوصول لهذه المرحلة يعني خسارة', 'Reaching this stage marks the lead lost')}
            </label>
          </Field>
        </div>
        {stageError ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{stageError}</p> : null}
      </Modal>
    </div>
  )
}
