import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { permissionGroupLabel, permissionLabel, roleLabel } from '../lib/permissionLabels'
import { api, errorMessage } from '../lib/api'
import { groupPermissionsByPrefix, roleSubsetOfActor } from '../lib/iam'
import type { RoleRecord } from '../lib/types'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorState,
  Field,
  IconButton,
  InlineNotice,
  Input,
  Loading,
  Modal,
  PageHeader,
  Table,
  Td,
  Th,
  cx,
} from '../components/ui'

type PermissionGroup = { prefix: string; items: string[] }

/** Checkbox grid of permissions, grouped by prefix. Locked permissions stay visible with a marker. */
function PermissionGroups({
  groups,
  selection,
  disabled,
  notHeld,
  onToggle,
}: {
  groups: PermissionGroup[]
  selection: string[]
  disabled: boolean
  notHeld: Set<string>
  onToggle: (permission: string, checked: boolean) => void
}) {
  const { t, locale } = useI18n()
  if (groups.length === 0) return <p className="text-medium text-text-subtle">{t('noData')}</p>
  return (
    <div className="flex flex-col gap-3">
      {groups.map((group) => (
        <div key={group.prefix} className="rounded-12 border border-border-subtle bg-surface-subtle p-3">
          <p className="text-small mb-2 font-semibold tracking-wide text-text-subtlest">
            {permissionGroupLabel(group.prefix, locale)}
          </p>
          <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            {group.items.map((permission) => {
              const locked = disabled || notHeld.has(permission)
              return (
                <label
                  key={permission}
                  className="text-small flex items-center gap-2"
                  title={notHeld.has(permission) ? t('cannotAssignPrivileges') : permission}
                >
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded-4 border-border-default disabled:opacity-50"
                    checked={selection.includes(permission)}
                    disabled={locked}
                    onChange={(event) => onToggle(permission, event.target.checked)}
                  />
                  <span className="truncate">{permissionLabel(permission, locale)}</span>
                  {notHeld.has(permission) ? <Badge tone="neutral">{t('notHeldPermission')}</Badge> : null}
                </label>
              )
            })}
          </div>
        </div>
      ))}
    </div>
  )
}

export default function Roles() {
  const { t, locale } = useI18n()
  const { user, can } = useAuth()
  const queryClient = useQueryClient()
  const canView = can('roles.view')
  const canManage = can('roles.manage')

  const [selectedRoleId, setSelectedRoleId] = useState<number | null>(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingRole, setEditingRole] = useState<RoleRecord | null>(null)
  const [roleForm, setRoleForm] = useState({ name: '', permissions: [] as string[] })
  const [modalError, setModalError] = useState('')
  const [panelSelection, setPanelSelection] = useState<string[]>([])
  const [panelError, setPanelError] = useState('')
  const [listError, setListError] = useState('')

  const rolesQuery = useQuery({
    queryKey: ['roles'],
    enabled: canView,
    queryFn: async () => {
      const { data } = await api.get<{ data: RoleRecord[] }>('/roles')
      return data.data
    },
  })

  const permissionsQuery = useQuery({
    queryKey: ['permissions'],
    enabled: canView,
    queryFn: async () => {
      const { data } = await api.get<{ data: string[] }>('/permissions')
      return data.data
    },
  })

  const roles = useMemo(() => rolesQuery.data ?? [], [rolesQuery.data])
  const catalog = useMemo(() => permissionsQuery.data ?? [], [permissionsQuery.data])

  const groupedCatalog = useMemo<PermissionGroup[]>(() => groupPermissionsByPrefix(catalog), [catalog])

  const selectedRole = useMemo(
    () => roles.find((role) => role.id === selectedRoleId) ?? null,
    [roles, selectedRoleId],
  )

  /** Catalogue permissions the actor does not hold: visible but locked. */
  const notHeld = useMemo(() => {
    const held = new Set(user?.permissions ?? [])
    return new Set(catalog.filter((permission) => !held.has(permission)))
  }, [user, catalog])

  useEffect(() => {
    setPanelSelection(selectedRole?.permissions ?? [])
    setPanelError('')
  }, [selectedRole])

  const saveRole = useMutation({
    mutationFn: async () => {
      const payload = { name: roleForm.name.trim(), permissions: roleForm.permissions }
      if (editingRole) {
        const { data } = await api.patch<{ data: RoleRecord }>(`/roles/${editingRole.id}`, payload)
        return data.data
      }
      const { data } = await api.post<{ data: RoleRecord }>('/roles', payload)
      return data.data
    },
    onSuccess: async () => {
      setModalOpen(false)
      setEditingRole(null)
      setRoleForm({ name: '', permissions: [] })
      setModalError('')
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
    },
    onError: (cause) => setModalError(errorMessage(cause)),
  })

  const savePanel = useMutation({
    mutationFn: async () => {
      if (!selectedRole) return null
      const { data } = await api.patch<{ data: RoleRecord }>(`/roles/${selectedRole.id}`, {
        permissions: panelSelection,
      })
      return data.data
    },
    onSuccess: async () => {
      setPanelError('')
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
    },
    onError: (cause) => setPanelError(errorMessage(cause)),
  })

  const deleteRole = useMutation({
    mutationFn: async (role: RoleRecord) => {
      await api.delete(`/roles/${role.id}`)
    },
    onSuccess: async (_result, role) => {
      setListError('')
      setSelectedRoleId((current) => (current === role.id ? null : current))
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
    },
    onError: (cause) => setListError(errorMessage(cause)),
  })

  if (!canView) {
    return (
      <div>
        <PageHeader title={t('rolesPermissions')} subtitle={t('rolesSubtitle')} icon="shield" />
        <InlineNotice tone="warning">{t('permissionDenied')}</InlineNotice>
      </div>
    )
  }

  const openCreate = () => {
    setRoleForm({ name: '', permissions: [] })
    setEditingRole(null)
    setModalError('')
    setModalOpen(true)
  }

  const openEdit = (role: RoleRecord) => {
    setRoleForm({ name: role.name, permissions: [...role.permissions] })
    setEditingRole(role)
    setModalError('')
    setModalOpen(true)
  }

  const submitRole = () => {
    if (!roleForm.name.trim()) {
      setModalError(t('roleNameRequired'))
      return
    }
    if (!roleSubsetOfActor(roleForm.permissions, user?.permissions ?? [])) {
      setModalError(t('cannotAssignPrivileges'))
      return
    }
    setModalError('')
    saveRole.mutate()
  }

  const submitPanel = () => {
    if (!roleSubsetOfActor(panelSelection, user?.permissions ?? [])) {
      setPanelError(t('cannotAssignPrivileges'))
      return
    }
    setPanelError('')
    savePanel.mutate()
  }

  const selectRole = (id: number) => {
    setSelectedRoleId(id)
    setPanelError('')
  }

  const panelEditable = Boolean(selectedRole && !selectedRole.is_system && canManage)
  const panelGroups =
    groupedCatalog.length > 0 && selectedRole
      ? groupedCatalog
      : groupPermissionsByPrefix(selectedRole?.permissions ?? [])

  return (
    <div>
      <PageHeader
        title={t('rolesPermissions')}
        subtitle={t('rolesSubtitle')}
        icon="shield"
        actions={canManage ? <Button icon="plus" onClick={openCreate}>{t('newRole')}</Button> : null}
      />

      <Card>
        {listError ? (
          <div className="px-5 pt-4">
            <InlineNotice tone="negative">{listError}</InlineNotice>
          </div>
        ) : null}
        {rolesQuery.isLoading ? <Loading /> : null}
        {rolesQuery.isError ? (
          <ErrorState message={errorMessage(rolesQuery.error)} onRetry={() => void rolesQuery.refetch()} />
        ) : null}
        {rolesQuery.data && roles.length === 0 ? <EmptyState icon="shield" title={t('noData')} /> : null}

        {roles.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('role')}</Th>
                <Th>{t('type')}</Th>
                <Th className="text-end">{t('users')}</Th>
                <Th className="text-end">{t('permissions')}</Th>
                <Th className="text-end">{t('actions')}</Th>
              </tr>
            </thead>
            <tbody>
              {roles.map((role) => (
                <tr
                  key={role.id}
                  onClick={() => selectRole(role.id)}
                  className={cx(
                    'cursor-pointer transition',
                    selectedRoleId === role.id ? 'bg-surface-selected' : 'hover:bg-surface-hovered',
                  )}
                >
                  <Td className="font-medium">
                    {roleLabel(role.name, locale)}
                  </Td>
                  <Td>
                    <Badge tone={role.is_system ? 'brand' : 'neutral'}>
                      {role.is_system ? t('systemRole') : t('customRole')}
                    </Badge>
                  </Td>
                  <Td className="text-end tabular">{role.users_count}</Td>
                  <Td className="text-end tabular">{role.permissions.length}</Td>
                  <Td className="text-end">
                    <div className="flex justify-end gap-1" onClick={(event) => event.stopPropagation()}>
                      <IconButton icon="eye" label={t('viewRole')} onClick={() => selectRole(role.id)} />
                      {canManage && !role.is_system ? (
                        <>
                          <IconButton icon="edit" label={t('editRole')} onClick={() => openEdit(role)} />
                          <IconButton
                            icon="trash"
                            label={t('deleteRole')}
                            onClick={() => {
                              if (window.confirm(t('deleteRoleConfirm'))) deleteRole.mutate(role)
                            }}
                          />
                        </>
                      ) : null}
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
      </Card>

      {selectedRole ? (
        <Card className="mt-6">
          <CardHeader
            title={roleLabel(selectedRole.name, locale)}
            subtitle={`${t('users')}: ${selectedRole.users_count} · ${t('permissions')}: ${selectedRole.permissions.length}`}
            icon="shield"
            actions={
              panelEditable ? (
                <Button icon="save" disabled={savePanel.isPending} onClick={submitPanel}>
                  {savePanel.isPending ? t('saving') : t('save')}
                </Button>
              ) : null
            }
          />
          <div className="px-5 py-5">
            {selectedRole.is_system ? (
              <div className="mb-4">
                <InlineNotice tone="info">{t('systemRoleNote')}</InlineNotice>
              </div>
            ) : null}
            {!canManage && !selectedRole.is_system ? (
              <div className="mb-4">
                <InlineNotice tone="warning">{t('permissionDenied')}</InlineNotice>
              </div>
            ) : null}
            <PermissionGroups
              groups={panelGroups}
              selection={panelSelection}
              disabled={!panelEditable}
              notHeld={notHeld}
              onToggle={(permission, checked) =>
                setPanelSelection((current) =>
                  checked ? [...current, permission] : current.filter((item) => item !== permission),
                )
              }
            />
            {panelError ? (
              <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">
                {panelError}
              </p>
            ) : null}
          </div>
        </Card>
      ) : (
        <p className="text-medium mt-6 text-text-subtle">{t('selectRoleHint')}</p>
      )}

      <Modal
        open={modalOpen}
        title={editingRole ? t('editRole') : t('newRole')}
        onClose={() => setModalOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={saveRole.isPending} onClick={submitRole}>
              {saveRole.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <Field label={t('role')} required hint={t('roleNameHint')}>
          <Input
            value={roleForm.name}
            onChange={(event) => setRoleForm({ ...roleForm, name: event.target.value })}
            dir="ltr"
          />
        </Field>
        <div className="mt-4 flex flex-col text-text-default">
          <span className="label mb-3">{t('permissions')}</span>
          <PermissionGroups
            groups={groupedCatalog}
            selection={roleForm.permissions}
            disabled={false}
            notHeld={notHeld}
            onToggle={(permission, checked) =>
              setRoleForm((current) => ({
                ...current,
                permissions: checked
                  ? [...current.permissions, permission]
                  : current.permissions.filter((item) => item !== permission),
              }))
            }
          />
        </div>
        {modalError ? (
          <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">
            {modalError}
          </p>
        ) : null}
      </Modal>
    </div>
  )
}
