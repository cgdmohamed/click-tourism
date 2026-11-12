import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import {
  assignableBranches,
  assignableRoles,
  primaryBranchOptions,
  userEditPayload,
  validateUserForm,
  type UserFormState,
} from '../lib/iam'
import { localizedName } from '../lib/names'
import type { Branch, Paginated, RoleRecord, UserAccount } from '../lib/types'
import { ReasonModal } from '../components/ReasonModal'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  IconButton,
  InlineNotice,
  Input,
  Loading,
  Modal,
  PageHeader,
  Pagination,
  Select,
  Table,
  Td,
  Th,
  cx,
} from '../components/ui'

type StatusFilter = 'all' | 'active' | 'inactive'
type RoleOption = { name: string; assignable: boolean }
type BranchOption = { branch: Branch; assignable: boolean }

const emptyUserForm: UserFormState = {
  name: '',
  email: '',
  password: '',
  locale: 'ar',
  roles: [],
  branch_ids: [],
  primary_branch_id: null,
  is_available_for_leads: false,
}

/** iam.ts validation codes → i18n keys. */
const validationKeys: Record<string, MessageKey> = {
  nameRequired: 'validationNameRequired',
  emailRequired: 'validationEmailRequired',
  emailFormat: 'validationEmailFormat',
  passwordRequired: 'validationPasswordRequired',
  passwordShort: 'validationPasswordShort',
  primaryBranchNotAssigned: 'validationPrimaryBranch',
}

export default function Users() {
  const { t, locale } = useI18n()
  const { user, can, activeBranch, branches: ownBranches } = useAuth()
  const queryClient = useQueryClient()
  const canView = can('users.view')
  const canManage = can('users.manage')
  const canViewBranches = can('branches.view')

  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<StatusFilter>('all')
  const [roleFilter, setRoleFilter] = useState('')
  const [branchFilter, setBranchFilter] = useState('')
  const [page, setPage] = useState(1)

  const [open, setOpen] = useState(false)
  const [editId, setEditId] = useState<string | null>(null)
  const [form, setForm] = useState<UserFormState>(emptyUserForm)
  const [formErrors, setFormErrors] = useState<Record<string, string>>({})
  const [saveError, setSaveError] = useState('')
  const [actionError, setActionError] = useState('')
  const [deactivateTarget, setDeactivateTarget] = useState<UserAccount | null>(null)

  const usersQuery = useQuery({
    queryKey: ['users', search, status, roleFilter, branchFilter, page, activeBranch?.id ?? null],
    enabled: canView,
    queryFn: async () => {
      const { data } = await api.get<Paginated<UserAccount>>('/users', {
        params: {
          search: search || undefined,
          status: status === 'all' ? undefined : status,
          role: roleFilter || undefined,
          branch_id: branchFilter || undefined,
          page,
          per_page: 20,
        },
      })
      return data
    },
  })

  const rolesQuery = useQuery({
    queryKey: ['roles'],
    enabled: canView && can('roles.view'),
    queryFn: async () => {
      const { data } = await api.get<{ data: RoleRecord[] }>('/roles')
      return data.data
    },
  })

  const branchesQuery = useQuery({
    queryKey: ['branches'],
    enabled: canViewBranches,
    queryFn: async () => {
      const { data } = await api.get<{ data: Branch[] }>('/branches')
      return data.data
    },
  })

  const users = useMemo(() => usersQuery.data?.data ?? [], [usersQuery.data])
  const roles = useMemo(() => rolesQuery.data ?? [], [rolesQuery.data])

  const actorBranchIds = useMemo(() => (user?.branches ?? []).map((branch) => branch.id), [user])

  /** Branches the actor may see and assign: everything with branches.view, own assignments otherwise. */
  const assignableBranchList = useMemo(
    () => assignableBranches(branchesQuery.data ?? ownBranches, actorBranchIds, canViewBranches),
    [branchesQuery.data, ownBranches, actorBranchIds, canViewBranches],
  )

  const editingAccount = useMemo(() => users.find((account) => account.id === editId) ?? null, [users, editId])

  /** Every known role, flagged with whether the actor may assign it. */
  const roleCheckOptions = useMemo<RoleOption[]>(() => {
    if (roles.length > 0) {
      const actorPermissions = user?.permissions ?? []
      const assignable = new Set(assignableRoles(roles, actorPermissions).map((role) => role.name))
      return roles.map((role) => ({ name: role.name, assignable: assignable.has(role.name) }))
    }
    // Without roles.view the catalogue is unknown; the actor's own roles are always safe to hand out.
    return [...new Set(user?.roles ?? [])].map((name) => ({ name, assignable: true }))
  }, [roles, user])

  /** Assignable branches plus, when editing, the branches the account already has (kept but locked). */
  const branchCheckOptions = useMemo<BranchOption[]>(() => {
    const options = assignableBranchList.map((branch) => ({ branch, assignable: true }))
    if (!editingAccount) return options
    const seen = new Set(options.map((option) => option.branch.id))
    const inherited = editingAccount.branches
      .filter((branch) => !seen.has(branch.id))
      .map((branch) => ({ branch, assignable: false }))
    return [...options, ...inherited]
  }, [assignableBranchList, editingAccount])

  /** Role names for the filter dropdown; falls back to roles seen on the loaded page. */
  const roleFilterOptions = useMemo(() => {
    if (roles.length > 0) return roles.map((role) => role.name)
    return [...new Set(users.flatMap((account) => account.roles))]
  }, [roles, users])

  const saveUser = useMutation({
    mutationFn: async () => {
      if (editId && editingAccount) {
        // Assignment fields are omitted unless they changed (see userEditPayload).
        const { data } = await api.patch<{ data: UserAccount }>(`/users/${editId}`, userEditPayload(form, editingAccount))
        return data.data
      }
      const payload: Record<string, unknown> = {
        name: form.name.trim(),
        email: form.email.trim(),
        locale: form.locale,
        roles: form.roles,
        branch_ids: form.branch_ids,
        primary_branch_id: form.primary_branch_id || null,
        is_available_for_leads: form.is_available_for_leads,
      }
      if (form.password) payload.password = form.password
      const { data } = await api.post<{ data: UserAccount }>('/users', payload)
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setEditId(null)
      setForm(emptyUserForm)
      setFormErrors({})
      setSaveError('')
      await queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (cause) => setSaveError(errorMessage(cause)),
  })

  const deactivateUser = useMutation({
    mutationFn: async ({ id, reason }: { id: string; reason: string }) => {
      await api.post(`/users/${id}/deactivate`, { reason })
    },
    onSuccess: async () => {
      setDeactivateTarget(null)
      setActionError('')
      await queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (cause) => setActionError(errorMessage(cause)),
  })

  const activateUser = useMutation({
    mutationFn: async (id: string) => {
      await api.post(`/users/${id}/activate`)
    },
    onSuccess: async () => {
      setActionError('')
      await queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (cause) => setActionError(errorMessage(cause)),
  })

  if (!canView) {
    return (
      <div>
        <PageHeader title={t('users')} subtitle={t('usersSubtitle')} icon="users" />
        <InlineNotice tone="warning">{t('permissionDenied')}</InlineNotice>
      </div>
    )
  }

  const fieldError = (field: string): string | undefined => {
    const code = formErrors[field]
    if (!code) return undefined
    return t(validationKeys[code] ?? 'required')
  }

  const openCreate = () => {
    setForm(emptyUserForm)
    setEditId(null)
    setFormErrors({})
    setSaveError('')
    setOpen(true)
  }

  const openEdit = (account: UserAccount) => {
    setForm({
      name: account.name,
      email: account.email,
      password: '',
      locale: account.locale,
      roles: [...account.roles],
      branch_ids: account.branches.map((branch) => branch.id),
      primary_branch_id: account.primary_branch_id,
      is_available_for_leads: account.is_available_for_leads,
    })
    setEditId(account.id)
    setFormErrors({})
    setSaveError('')
    setOpen(true)
  }

  const submitUser = () => {
    const validation = validateUserForm(form, !editId)
    setFormErrors(validation)
    setSaveError('')
    if (Object.keys(validation).length > 0) return
    saveUser.mutate()
  }

  const toggleRole = (name: string, checked: boolean) => {
    setForm((current) => ({
      ...current,
      roles: checked ? [...current.roles, name] : current.roles.filter((role) => role !== name),
    }))
  }

  const toggleBranch = (id: string, checked: boolean) => {
    setForm((current) => {
      const branch_ids = checked ? [...current.branch_ids, id] : current.branch_ids.filter((branch) => branch !== id)
      // Dropping the primary branch clears the selection instead of failing validation.
      const primary_branch_id =
        current.primary_branch_id && branch_ids.includes(current.primary_branch_id) ? current.primary_branch_id : null
      return { ...current, branch_ids, primary_branch_id }
    })
  }

  const branchName = (branch: Branch) => (localizedName(branch, locale))

  return (
    <div>
      <PageHeader
        title={t('users')}
        subtitle={t('usersSubtitle')}
        icon="users"
        actions={canManage ? <Button icon="plus" onClick={openCreate}>{t('newUser')}</Button> : null}
      />

      {!canManage ? (
        <div className="mb-4">
          <InlineNotice tone="warning">{t('permissionDenied')}</InlineNotice>
        </div>
      ) : null}
      {actionError && !deactivateTarget ? (
        <div className="mb-4">
          <InlineNotice tone="negative">{actionError}</InlineNotice>
        </div>
      ) : null}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder={t('search')}
            icon="search"
            className="max-w-xs"
          />
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as StatusFilter)
              setPage(1)
            }}
            className="w-44"
          >
            <option value="all">{t('allStatuses')}</option>
            <option value="active">{t('active')}</option>
            <option value="inactive">{t('inactive')}</option>
          </Select>
          <Select
            value={roleFilter}
            onChange={(event) => {
              setRoleFilter(event.target.value)
              setPage(1)
            }}
            className="w-48"
          >
            <option value="">{t('allRoles')}</option>
            {roleFilterOptions.map((name) => (
              <option key={name} value={name}>
                {name}
              </option>
            ))}
          </Select>
          {assignableBranchList.length > 1 ? (
            <Select
              value={branchFilter}
              onChange={(event) => {
                setBranchFilter(event.target.value)
                setPage(1)
              }}
              className="w-48"
            >
              <option value="">{t('allBranches')}</option>
              {assignableBranchList.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branchName(branch)}
                </option>
              ))}
            </Select>
          ) : null}
          <span className="text-small ms-auto text-text-subtle">
            {usersQuery.data?.meta.total ?? users.length} {t('users')}
          </span>
        </div>

        {usersQuery.isLoading ? <Loading /> : null}
        {usersQuery.isError ? (
          <ErrorState message={errorMessage(usersQuery.error)} onRetry={() => void usersQuery.refetch()} />
        ) : null}
        {usersQuery.data && users.length === 0 ? <EmptyState icon="users" title={t('noData')} /> : null}

        {users.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('name')}</Th>
                <Th>{t('email')}</Th>
                <Th>{t('roles')}</Th>
                <Th>{t('branches')}</Th>
                <Th>{t('lastLogin')}</Th>
                <Th className="text-end">{t('actions')}</Th>
              </tr>
            </thead>
            <tbody>
              {users.map((account) => (
                <tr key={account.id} className={cx(!account.is_active && 'bg-surface-subtle')}>
                  <Td>
                    <div className="flex flex-wrap items-center gap-2">
                      <span className={cx('font-medium', !account.is_active && 'text-text-subtlest')}>{account.name}</span>
                      <Badge tone={account.is_active ? 'positive' : 'negative'}>
                        {account.is_active ? t('active') : t('inactive')}
                      </Badge>
                    </div>
                    {account.is_available_for_leads ? (
                      <p className="text-small text-text-subtlest">{t('availableForLeads')}</p>
                    ) : null}
                  </Td>
                  <Td dir="ltr">{account.email}</Td>
                  <Td>
                    {account.roles.length === 0 ? (
                      <span className="text-text-subtlest">—</span>
                    ) : (
                      <span className="flex flex-wrap gap-1">
                        {account.roles.map((role) => (
                          <Badge key={role} tone="neutral">
                            {role}
                          </Badge>
                        ))}
                      </span>
                    )}
                  </Td>
                  <Td>
                    {account.branches.length === 0 ? (
                      <span className="text-text-subtlest">—</span>
                    ) : (
                      <span className="flex flex-wrap gap-1">
                        {account.branches.map((branch) => (
                          <span
                            key={branch.id}
                            title={branch.id === account.primary_branch_id ? t('primaryBranch') : undefined}
                          >
                            <Badge tone={branch.id === account.primary_branch_id ? 'brand' : 'neutral'}>
                              {branchName(branch)}
                            </Badge>
                          </span>
                        ))}
                      </span>
                    )}
                  </Td>
                  <Td dir="ltr" className="whitespace-nowrap">
                    {formatDateTime(account.last_login_at, locale)}
                  </Td>
                  <Td className="text-end">
                    {canManage ? (
                      <div className="flex justify-end gap-1">
                        <IconButton icon="edit" label={t('edit')} onClick={() => openEdit(account)} />
                        {account.is_active ? (
                          <IconButton
                            icon="xCircle"
                            label={t('deactivateUser')}
                            disabled={account.id === user?.id}
                            title={account.id === user?.id ? t('cannotDeactivateSelf') : t('deactivateUser')}
                            onClick={() => {
                              setActionError('')
                              setDeactivateTarget(account)
                            }}
                          />
                        ) : (
                          <IconButton
                            icon="checkCircle"
                            label={t('activateUser')}
                            onClick={() => {
                              if (window.confirm(t('confirmActivate'))) activateUser.mutate(account.id)
                            }}
                          />
                        )}
                      </div>
                    ) : null}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
        <Pagination
          page={usersQuery.data?.meta.current_page ?? 1}
          lastPage={usersQuery.data?.meta.last_page ?? 1}
          onChange={setPage}
        />
      </Card>

      <Modal
        open={open}
        title={editId ? t('editUser') : t('newUser')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={saveUser.isPending} onClick={submitUser}>
              {saveUser.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('name')} required error={fieldError('name')}>
            <Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <Field label={t('email')} required error={fieldError('email')}>
            <Input
              type="email"
              value={form.email}
              onChange={(event) => setForm({ ...form, email: event.target.value })}
              dir="ltr"
            />
          </Field>
          <Field
            label={t('password')}
            required={!editId}
            error={fieldError('password')}
            hint={editId ? t('passwordOptionalHint') : t('passwordHint')}
          >
            <Input
              type="password"
              value={form.password}
              onChange={(event) => setForm({ ...form, password: event.target.value })}
              dir="ltr"
              autoComplete="new-password"
            />
          </Field>
          <Field label={t('language')}>
            <Select
              value={form.locale}
              onChange={(event) => setForm({ ...form, locale: event.target.value as 'ar' | 'en' })}
            >
              <option value="ar">{t('arabic')}</option>
              <option value="en">{t('english')}</option>
            </Select>
          </Field>
        </div>

        <div className="mt-4 flex flex-col text-text-default">
          <span className="label mb-3">{t('roles')}</span>
          <div className="grid gap-2 rounded-12 border border-border-subtle bg-surface-subtle p-3 sm:grid-cols-2">
            {roleCheckOptions.length === 0 ? (
              <p className="text-small text-text-subtle">{t('noData')}</p>
            ) : null}
            {roleCheckOptions.map((option) => (
              <label
                key={option.name}
                className="text-small flex items-center gap-2"
                title={option.assignable ? undefined : t('cannotAssignPrivileges')}
              >
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded-4 border-border-default disabled:opacity-50"
                  checked={form.roles.includes(option.name)}
                  disabled={!option.assignable}
                  onChange={(event) => toggleRole(option.name, event.target.checked)}
                />
                <span className="truncate" dir="ltr">
                  {option.name}
                </span>
              </label>
            ))}
          </div>
        </div>

        <div className="mt-4 flex flex-col text-text-default">
          <span className="label mb-3">{t('branches')}</span>
          <div className="grid gap-2 rounded-12 border border-border-subtle bg-surface-subtle p-3 sm:grid-cols-2">
            {branchCheckOptions.length === 0 ? (
              <p className="text-small text-text-subtle">{t('noData')}</p>
            ) : null}
            {branchCheckOptions.map((option) => (
              <label
                key={option.branch.id}
                className="text-small flex items-center gap-2"
                title={option.assignable ? undefined : t('cannotAssignBranch')}
              >
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded-4 border-border-default disabled:opacity-50"
                  checked={form.branch_ids.includes(option.branch.id)}
                  disabled={!option.assignable}
                  onChange={(event) => toggleBranch(option.branch.id, event.target.checked)}
                />
                <span className="truncate">{branchName(option.branch)}</span>
              </label>
            ))}
          </div>
        </div>

        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <Field label={t('primaryBranch')} error={fieldError('primary_branch_id')}>
            <Select
              value={form.primary_branch_id ?? ''}
              onChange={(event) => setForm({ ...form, primary_branch_id: event.target.value || null })}
            >
              <option value="">{t('none')}</option>
              {primaryBranchOptions(
                branchCheckOptions.map((option) => option.branch),
                form.branch_ids,
                locale,
              ).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('availableForLeads')}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input
                type="checkbox"
                className="h-4 w-4 rounded-4 border-border-default"
                checked={form.is_available_for_leads}
                onChange={(event) => setForm({ ...form, is_available_for_leads: event.target.checked })}
              />
              {t('availableForLeads')}
            </label>
          </Field>
        </div>

        {saveError ? (
          <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">
            {saveError}
          </p>
        ) : null}
      </Modal>

      <ReasonModal
        open={Boolean(deactivateTarget)}
        title={t('deactivateUser')}
        description={t('deactivateUserDescription')}
        confirmLabel={t('deactivateUser')}
        pending={deactivateUser.isPending}
        error={actionError}
        onClose={() => {
          setDeactivateTarget(null)
          setActionError('')
        }}
        onConfirm={(reason) => {
          if (deactivateTarget) deactivateUser.mutate({ id: deactivateTarget.id, reason })
        }}
      />
    </div>
  )
}
