import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { api, getToken, setStoredBranch, setToken } from '../lib/api'
import type { Branch, User } from '../lib/types'

type AuthValue = {
  user: User | null
  loading: boolean
  activeBranch: Branch | null
  branches: Branch[]
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  setActiveBranch: (branchId: string) => void
  refresh: () => Promise<void>
  can: (permission: string) => boolean
}

const AuthContext = createContext<AuthValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [activeBranchId, setActiveBranchId] = useState<string | null>(null)

  const applyUser = useCallback((next: User | null) => {
    setUser(next)
    if (next) {
      const stored = localStorage.getItem('tourism_erp_branch')
      const valid = next.branches.find((branch) => branch.id === stored)
      const chosen = valid ?? next.branches[0] ?? null
      setActiveBranchId(chosen?.id ?? null)
      setStoredBranch(chosen?.id ?? null)
    } else {
      setActiveBranchId(null)
      setStoredBranch(null)
    }
  }, [])

  const refresh = useCallback(async () => {
    if (!getToken()) {
      applyUser(null)
      setLoading(false)
      return
    }
    try {
      const { data } = await api.get<{ data: User }>('/auth/me')
      applyUser(data.data)
    } catch {
      setToken(null)
      applyUser(null)
    } finally {
      setLoading(false)
    }
  }, [applyUser])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const login = useCallback(
    async (email: string, password: string) => {
      const { data } = await api.post<{ data: { token: string; user: User } }>('/auth/login', {
        email,
        password,
        device_name: 'web',
      })
      setToken(data.data.token)
      applyUser(data.data.user)
    },
    [applyUser],
  )

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Token may already be invalid; clearing locally is enough.
    }
    setToken(null)
    applyUser(null)
  }, [applyUser])

  const setActiveBranch = useCallback((branchId: string) => {
    setActiveBranchId(branchId)
    setStoredBranch(branchId)
  }, [])

  const value = useMemo<AuthValue>(() => {
    const branches = user?.branches ?? []
    return {
      user,
      loading,
      branches,
      activeBranch: branches.find((branch) => branch.id === activeBranchId) ?? null,
      login,
      logout,
      setActiveBranch,
      refresh,
      can: (permission: string) => user?.permissions.includes(permission) ?? false,
    }
  }, [user, loading, activeBranchId, login, logout, setActiveBranch, refresh])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside AuthProvider')
  return context
}
