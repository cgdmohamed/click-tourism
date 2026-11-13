import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import {
  FALLBACK_LIMITS,
  defaultExpiry,
  expiryChoices,
  splitDuration,
  tokenState,
  validateTokenName,
} from '../lib/mcpTokens'
import type { McpToken, McpTokenLimits } from '../lib/mcpTokens'
import { Badge, Button, Card, CardHeader, EmptyState, Field, InlineNotice, Input, Loading, Modal, Select, Table, Td, Th } from './ui'

type Listing = { data: McpToken[]; meta?: McpTokenLimits }

/**
 * "MCP access tokens" section of the Profile page (ADR-0016). The caller decides visibility
 * (permission mcp.use and the installation flag mcp_enabled); the API stays the authority.
 */
export default function McpTokensCard() {
  const { t, locale } = useI18n()
  const { can, user } = useAuth()
  const queryClient = useQueryClient()
  const isAdmin = can('mcp.admin')

  const [showAll, setShowAll] = useState(false)
  const [name, setName] = useState('')
  const [nameError, setNameError] = useState('')
  const [minutes, setMinutes] = useState<number | null>(null)
  const [created, setCreated] = useState<{ name: string; token: string } | null>(null)
  const [copied, setCopied] = useState<'yes' | 'failed' | ''>('')
  const [error, setError] = useState('')
  const [revoking, setRevoking] = useState<McpToken | null>(null)

  const listing = useQuery({
    queryKey: ['mcp-tokens', showAll],
    queryFn: async () => {
      const { data } = await api.get<Listing>('/mcp/tokens', { params: showAll ? { all: 1 } : undefined })
      return data
    },
    retry: false,
  })

  const limits = listing.data?.meta ?? FALLBACK_LIMITS
  const choices = expiryChoices(limits.max_minutes)
  const selected = minutes !== null && choices.includes(minutes) ? minutes : defaultExpiry(limits)
  const tokens = listing.data?.data ?? []

  const create = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: McpToken & { token: string } }>('/mcp/tokens', {
        name: name.trim(),
        expires_in_minutes: selected,
      })
      return data.data
    },
    onSuccess: async (token) => {
      setCreated({ name: token.name, token: token.token })
      setCopied('')
      setName('')
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['mcp-tokens'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const revoke = useMutation({
    mutationFn: async (token: McpToken) => {
      await api.delete(`/mcp/tokens/${token.id}`)
    },
    onSuccess: async () => {
      setRevoking(null)
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['mcp-tokens'] })
    },
    onError: (cause) => {
      setRevoking(null)
      setError(errorMessage(cause))
    },
  })

  function submit() {
    const problem = validateTokenName(name)
    setNameError(problem === 'required' ? t('mcpNameRequired') : problem === 'tooLong' ? t('mcpNameTooLong') : '')
    setError('')
    if (problem === null) create.mutate()
  }

  async function copyToken() {
    if (!created) return
    try {
      await navigator.clipboard.writeText(created.token)
      setCopied('yes')
    } catch {
      setCopied('failed')
    }
  }

  const fill = (key: MessageKey, values: Record<string, string>) => t(key).replace(/\{(\w+)\}/g, (_, name: string) => values[name] ?? '')

  const duration = (value: number) => {
    const { hours, minutes: rest } = splitDuration(value)
    return [hours > 0 ? `${hours} ${t('mcpHoursShort')}` : '', rest > 0 ? `${rest} ${t('mcpMinutesShort')}` : ''].filter(Boolean).join(' ')
  }

  return (
    <Card className="min-w-0 xl:col-span-3">
      <CardHeader
        title={t('mcpTokens')}
        subtitle={t('mcpTokensSubtitle')}
        icon="shield"
        actions={
          isAdmin ? (
            <label className="text-medium flex items-center gap-2">
              <input type="checkbox" checked={showAll} onChange={(event) => setShowAll(event.target.checked)} />
              {t('mcpAllTokens')}
            </label>
          ) : null
        }
      />
      <div className="flex flex-col gap-5 px-5 py-5">
        <InlineNotice tone="info">{t('mcpTokensInfo')}</InlineNotice>

        {created ? (
          <div className="flex flex-col gap-3 rounded-12 border border-warning-border bg-warning-background p-4" role="status">
            <p className="text-medium font-semibold text-warning-text">{fill('mcpTokenCreatedTitle', { name: created.name })}</p>
            <p className="text-medium text-warning-text">{t('mcpTokenOnceWarning')}</p>
            <div className="flex flex-wrap items-center gap-2">
              <Input
                readOnly
                value={created.token}
                dir="ltr"
                aria-label={t('mcpTokenValue')}
                className="min-w-0 flex-1 font-mono"
                onFocus={(event) => event.currentTarget.select()}
              />
              <Button type="button" variant="secondary" icon={copied === 'yes' ? 'check' : 'copy'} onClick={() => void copyToken()}>
                {copied === 'yes' ? t('mcpCopied') : t('mcpCopy')}
              </Button>
              <Button type="button" variant="ghost" onClick={() => setCreated(null)}>
                {t('mcpSavedIt')}
              </Button>
            </div>
            {copied === 'failed' ? <InlineNotice tone="negative">{t('mcpCopyFailed')}</InlineNotice> : null}
          </div>
        ) : null}

        <form
          className="grid items-start gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]"
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          <Field label={t('mcpTokenName')} required error={nameError || undefined} hint={t('mcpTokenNameHint')}>
            <Input value={name} maxLength={80} onChange={(event) => setName(event.target.value)} autoComplete="off" />
          </Field>
          <Field label={t('mcpExpiry')} hint={fill('mcpExpiryHint', { max: duration(limits.max_minutes) })}>
            <Select value={selected} onChange={(event) => setMinutes(Number(event.target.value))}>
              {choices.map((choice) => (
                <option key={choice} value={choice}>
                  {duration(choice)}
                </option>
              ))}
            </Select>
          </Field>
          <div className="md:pt-9">
            <Button type="submit" icon="plus" disabled={create.isPending}>
              {create.isPending ? t('saving') : t('mcpCreate')}
            </Button>
          </div>
        </form>

        {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}

        {listing.isLoading ? (
          <Loading />
        ) : listing.isError ? (
          <InlineNotice tone="negative">{errorMessage(listing.error)}</InlineNotice>
        ) : tokens.length === 0 ? (
          <EmptyState title={t('mcpNoTokens')} />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>{t('mcpTokenName')}</Th>
                {showAll ? <Th>{t('mcpOwner')}</Th> : null}
                <Th>{t('mcpCreated')}</Th>
                <Th>{t('mcpExpires')}</Th>
                <Th>{t('mcpLastUsed')}</Th>
                <Th>{t('status')}</Th>
                <Th>{t('actions')}</Th>
              </tr>
            </thead>
            <tbody>
              {tokens.map((token) => {
                const state = tokenState(token)
                return (
                  <tr key={token.id}>
                    <Td>{token.name}</Td>
                    {showAll ? (
                      <Td>
                        {token.user ? (
                          <span className="flex flex-col">
                            <span>{token.user.name}</span>
                            <span className="text-small text-text-subtlest" dir="ltr">
                              {token.user.email}
                            </span>
                          </span>
                        ) : (
                          '—'
                        )}
                      </Td>
                    ) : null}
                    <Td>{formatDateTime(token.created_at, locale)}</Td>
                    <Td>{formatDateTime(token.expires_at, locale)}</Td>
                    <Td>{token.last_used_at ? formatDateTime(token.last_used_at, locale) : t('mcpNeverUsed')}</Td>
                    <Td>
                      <Badge tone={state === 'active' ? 'positive' : 'neutral'}>{state === 'active' ? t('mcpActive') : t('mcpExpired')}</Badge>
                    </Td>
                    <Td>
                      <Button type="button" variant="danger" icon="trash" onClick={() => setRevoking(token)}>
                        {t('mcpRevoke')}
                      </Button>
                    </Td>
                  </tr>
                )
              })}
            </tbody>
          </Table>
        )}
      </div>

      <Modal
        open={revoking !== null}
        title={t('mcpRevokeTitle')}
        onClose={() => setRevoking(null)}
        footer={
          <>
            <Button type="button" variant="ghost" onClick={() => setRevoking(null)}>
              {t('cancel')}
            </Button>
            <Button type="button" variant="danger" icon="trash" disabled={revoke.isPending} onClick={() => revoking && revoke.mutate(revoking)}>
              {revoke.isPending ? t('saving') : t('mcpRevoke')}
            </Button>
          </>
        }
      >
        {revoking ? (
          <p className="text-medium">
            {revoking.user && revoking.user.id !== user?.id
              ? fill('mcpRevokeOtherBody', { name: revoking.name, user: revoking.user.name })
              : fill('mcpRevokeBody', { name: revoking.name })}
          </p>
        ) : null}
      </Modal>
    </Card>
  )
}
