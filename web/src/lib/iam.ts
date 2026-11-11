import type { Branch, RoleRecord } from './types'

/**
 * Pure identity-and-access logic shared by the Users and Roles pages:
 * which roles/branches an actor may hand out, form validation, and
 * permission grouping. No React, so it is covered by node-env tests.
 */

/** Shape of the Users page create/edit form. */
export type UserFormState = {
  name: string
  email: string
  password: string
  locale: 'ar' | 'en'
  roles: string[]
  branch_ids: string[]
  primary_branch_id: string | null
  is_available_for_leads: boolean
}

/** Stable validation codes; pages map them to i18n keys for display. */
export type UserFormErrorCode =
  | 'nameRequired'
  | 'emailRequired'
  | 'emailFormat'
  | 'passwordRequired'
  | 'passwordShort'
  | 'primaryBranchNotAssigned'

/** True when every permission of the role is held by the actor (an empty role is assignable). */
export function roleSubsetOfActor(rolePermissions: string[], actorPermissions: string[]): boolean {
  const actor = new Set(actorPermissions)
  return rolePermissions.every((permission) => actor.has(permission))
}

/** Roles the actor may assign: those whose permission set is a subset of the actor's. */
export function assignableRoles(roles: RoleRecord[], actorPermissions: string[]): RoleRecord[] {
  return roles.filter((role) => roleSubsetOfActor(role.permissions, actorPermissions))
}

/**
 * Branches the actor may see and assign: all of them with a view-all
 * permission, otherwise only the actor's own assignments.
 */
export function assignableBranches(branches: Branch[], actorBranchIds: string[], hasViewAll: boolean): Branch[] {
  if (hasViewAll) return branches
  const own = new Set(actorBranchIds)
  return branches.filter((branch) => own.has(branch.id))
}

/**
 * Local validation for the user create/edit form. Returns a field→code
 * map; an empty object means the form is valid.
 */
export function validateUserForm(form: UserFormState, isCreate: boolean): Record<string, UserFormErrorCode> {
  const errors: Record<string, UserFormErrorCode> = {}
  if (!form.name.trim()) errors.name = 'nameRequired'
  const email = form.email.trim()
  if (!email) errors.email = 'emailRequired'
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'emailFormat'
  if (isCreate && !form.password) errors.password = 'passwordRequired'
  else if (form.password && form.password.length < 12) errors.password = 'passwordShort'
  if (form.primary_branch_id && !form.branch_ids.includes(form.primary_branch_id)) {
    errors.primary_branch_id = 'primaryBranchNotAssigned'
  }
  return errors
}

/** Groups `journal.create`-style permission names by their prefix, sorted by prefix then item. */
export function groupPermissionsByPrefix(permissions: string[]): Array<{ prefix: string; items: string[] }> {
  const groups = new Map<string, string[]>()
  for (const permission of permissions) {
    const separator = permission.indexOf('.')
    const prefix = separator === -1 ? permission : permission.slice(0, separator)
    const items = groups.get(prefix) ?? []
    items.push(permission)
    groups.set(prefix, items)
  }
  const byName = (a: string, b: string) => (a < b ? -1 : a > b ? 1 : 0)
  return [...groups.entries()]
    .sort(([a], [b]) => byName(a, b))
    .map(([prefix, items]) => ({ prefix, items: [...items].sort(byName) }))
}

/** Select options for the primary-branch dropdown: only branches already ticked in the form. */
export function primaryBranchOptions(
  branches: Branch[],
  selected: string[],
  locale: 'ar' | 'en',
): Array<{ value: string; label: string }> {
  const chosen = new Set(selected)
  return branches
    .filter((branch) => chosen.has(branch.id))
    .map((branch) => ({ value: branch.id, label: locale === 'ar' ? branch.name_ar : branch.name_en }))
}
