import { useQueries } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { api } from './api'
import type { Paginated } from './types'
import { SOURCES } from './approvals'

/** How many records wait for this user; one cheap request (per_page=1) per approvable source. */
export function useApprovalCount(): number {
  const { can, activeBranch } = useAuth()
  const endpoints = [
    ...SOURCES.filter((source) => can(source.permission)).map((source) => ({ key: source.key, list: source.list, status: source.status })),
    ...(can('refunds.approve') ? [{ key: 'refunds', list: '/refunds', status: 'requested' }] : []),
  ]
  const results = useQueries({
    queries: endpoints.map((endpoint) => ({
      queryKey: ['approvals-count', endpoint.key, activeBranch?.id ?? null],
      queryFn: async () => (await api.get<Paginated<unknown>>(endpoint.list, { params: { status: endpoint.status, per_page: 1 } })).data.meta.total,
      staleTime: 60_000,
    })),
  })
  return results.reduce((sum, result) => sum + (result.data ?? 0), 0)
}
