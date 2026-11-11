import { describe, expect, it } from 'vitest'
import {
  assignableBranches,
  assignableRoles,
  groupPermissionsByPrefix,
  primaryBranchOptions,
  roleSubsetOfActor,
  validateUserForm,
  type UserFormState,
} from './iam'
import type { Branch, RoleRecord } from './types'

const branch = (id: string, name = id): Branch => ({
  id,
  code: id.toUpperCase(),
  name_ar: name,
  name_en: name,
  accounting_mode: 'dimension',
  is_active: true,
})

const role = (id: number, name: string, permissions: string[]): RoleRecord => ({
  id,
  name,
  guard: 'web',
  permissions,
  is_system: false,
  users_count: 0,
})

describe('roleSubsetOfActor', () => {
  it('accepts an empty role (no permissions to grant)', () => {
    expect(roleSubsetOfActor([], ['users.view'])).toBe(true)
  })

  it('accepts an exact match and a strict subset', () => {
    expect(roleSubsetOfActor(['users.view'], ['users.view'])).toBe(true)
    expect(roleSubsetOfActor(['users.view'], ['users.view', 'users.manage'])).toBe(true)
  })

  it('rejects a role with a permission the actor lacks', () => {
    expect(roleSubsetOfActor(['users.view', 'roles.manage'], ['users.view'])).toBe(false)
    expect(roleSubsetOfActor(['users.view'], [])).toBe(false)
  })

  it('accepts any role when the actor holds everything', () => {
    expect(roleSubsetOfActor(['users.view', 'roles.manage', 'journal.create'], ['*'])).toBe(false)
    expect(roleSubsetOfActor(['users.view', 'roles.manage'], ['users.view', 'roles.manage', 'journal.create'])).toBe(true)
  })
})

describe('assignableRoles', () => {
  const cashier = role(1, 'cashier', ['payments.view'])
  const admin = role(2, 'admin', ['users.view', 'users.manage'])
  const empty = role(3, 'viewer', [])

  it('keeps roles within the actor permissions and drops the rest', () => {
    const actor = ['payments.view', 'users.view']
    expect(assignableRoles([cashier, admin, empty], actor)).toEqual([cashier, empty])
  })

  it('keeps everything when the actor holds every permission', () => {
    const actor = ['payments.view', 'users.view', 'users.manage']
    expect(assignableRoles([cashier, admin, empty], actor)).toEqual([cashier, admin, empty])
  })

  it('preserves the incoming order', () => {
    const actor = ['payments.view']
    expect(assignableRoles([admin, cashier], actor)).toEqual([cashier])
  })
})

describe('assignableBranches', () => {
  const branches = [branch('b1'), branch('b2'), branch('b3')]

  it('returns every branch when the actor can view all', () => {
    expect(assignableBranches(branches, ['b1'], true)).toEqual(branches)
  })

  it('returns only the actor branches without view-all', () => {
    expect(assignableBranches(branches, ['b1', 'b3'], false)).toEqual([branches[0], branches[2]])
    expect(assignableBranches(branches, [], false)).toEqual([])
  })
})

describe('validateUserForm', () => {
  const valid: UserFormState = {
    name: 'Sara Hassan',
    email: 'sara@example.com',
    password: 'correct-horse-battery',
    locale: 'ar',
    roles: ['cashier'],
    branch_ids: ['b1', 'b2'],
    primary_branch_id: 'b2',
    is_available_for_leads: true,
  }

  it('accepts a complete create form', () => {
    expect(validateUserForm(valid, true)).toEqual({})
  })

  it('accepts an edit form without a password', () => {
    expect(validateUserForm({ ...valid, password: '' }, false)).toEqual({})
  })

  it('requires the name', () => {
    expect(validateUserForm({ ...valid, name: '  ' }, true)).toEqual({ name: 'nameRequired' })
  })

  it('rejects a missing or malformed email', () => {
    expect(validateUserForm({ ...valid, email: '' }, true)).toEqual({ email: 'emailRequired' })
    expect(validateUserForm({ ...valid, email: 'not-an-email' }, true)).toEqual({ email: 'emailFormat' })
  })

  it('requires a password on create only, and enforces 12 characters', () => {
    expect(validateUserForm({ ...valid, password: '' }, true)).toEqual({ password: 'passwordRequired' })
    expect(validateUserForm({ ...valid, password: 'short' }, true)).toEqual({ password: 'passwordShort' })
    expect(validateUserForm({ ...valid, password: 'short' }, false)).toEqual({ password: 'passwordShort' })
  })

  it('requires the primary branch to be one of the assigned branches', () => {
    expect(validateUserForm({ ...valid, primary_branch_id: 'b3' }, true)).toEqual({
      primary_branch_id: 'primaryBranchNotAssigned',
    })
    expect(validateUserForm({ ...valid, primary_branch_id: 'b3' }, false)).toEqual({
      primary_branch_id: 'primaryBranchNotAssigned',
    })
    expect(validateUserForm({ ...valid, primary_branch_id: null }, true)).toEqual({})
  })
})

describe('groupPermissionsByPrefix', () => {
  it('groups by the text before the first dot and sorts prefixes and items', () => {
    expect(groupPermissionsByPrefix(['journal.create', 'users.view', 'journal.post', 'users.manage'])).toEqual([
      { prefix: 'journal', items: ['journal.create', 'journal.post'] },
      { prefix: 'users', items: ['users.manage', 'users.view'] },
    ])
  })

  it('keeps a permission without a dot in a group of its own', () => {
    expect(groupPermissionsByPrefix(['admin', 'users.view'])).toEqual([
      { prefix: 'admin', items: ['admin'] },
      { prefix: 'users', items: ['users.view'] },
    ])
  })

  it('is stable: scrambled input yields the same sorted output', () => {
    const input = ['users.manage', 'journal.post', 'users.view', 'journal.create', 'expenses.approve']
    const first = groupPermissionsByPrefix(input)
    const second = groupPermissionsByPrefix([...input].reverse())
    expect(first).toEqual(second)
    expect(first.map((group) => group.prefix)).toEqual(['expenses', 'journal', 'users'])
  })

  it('returns an empty list for empty input', () => {
    expect(groupPermissionsByPrefix([])).toEqual([])
  })
})

describe('primaryBranchOptions', () => {
  it('offers only the selected branches, labelled per locale', () => {
    const branches = [branch('b1', 'القاهرة'), branch('b2', 'الرياض'), branch('b3', 'جدة')]
    expect(primaryBranchOptions(branches, ['b1', 'b3'], 'ar')).toEqual([
      { value: 'b1', label: 'القاهرة' },
      { value: 'b3', label: 'جدة' },
    ])
    expect(primaryBranchOptions(branches, ['b2'], 'en')).toEqual([{ value: 'b2', label: 'الرياض' }])
  })

  it('returns nothing when nothing is selected', () => {
    expect(primaryBranchOptions([branch('b1')], [], 'en')).toEqual([])
  })
})
