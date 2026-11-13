import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import axios from 'axios'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { connectionState, mcpStatusError, recentOrEmpty, tokenSummary } from '../lib/mcpStatus'
import type { McpStatus } from '../lib/mcpStatus'
import { Badge, Card, CardHeader, EmptyState, ErrorState, InlineNotice, Loading, Table, Td, Th } from './ui'

/**
 * Read-only MCP status (ADR-0016/0017): integration switch, token totals, last connection
 * and the most recent token usage. Tokens are created and revoked on the Profile page;
 * this card never shows a token value.
 */
export default function McpStatusCard() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const allowed = can('settings.view')

  const statusQuery = useQuery({
    queryKey: ['mcp-status'],
    enabled: allowed,
    retry: false,
    queryFn: async () => {
      const { data } = await api.get<{ data: McpStatus }>('/mcp/status')
      return data.data
    },
  })

  if (!allowed) return null

  const fill = (key: MessageKey, values: Record<string, string>) =>
    t(key).replace(/\{(\w+)\}/g, (_, name: string) => values[name] ?? '')

  const status = statusQuery.data
  const statusCode = axios.isAxiosError(statusQuery.error) ? statusQuery.error.response?.status : undefined
  const failure = mcpStatusError(statusCode)
  const state = status ? connectionState(status) : null
  const stateLabel = state === 'active' ? t('mcpStatusConnected') : state === 'idle' ? t('mcpStatusEnabled') : t('mcpStatusOff')
  const stateTone = state === 'active' ? 'positive' : state === 'idle' ? 'warning' : 'neutral'
  const totals = tokenSummary(status?.tokens)
  const rows = recentOrEmpty(status)

  return (
    <Card className="mt-6">
      <CardHeader
        title={t('mcpStatusTitle')}
        subtitle={t('mcpStatusSubtitle')}
        icon="shield"
        actions={
          <Link to="/profile" className="text-small font-medium text-brand-1-accent hover:underline">
            {t('mcpStatusManage')} ↗
          </Link>
        }
      />

      {statusQuery.isLoading ? <Loading /> : null}
      {statusQuery.isError ? (
        failure === 'forbidden' ? (
          <div className="px-5 py-5">
            <InlineNotice tone="negative">{t('permissionDenied')}</InlineNotice>
          </div>
        ) : statusCode === 404 ? (
          <div className="px-5 py-5">
            <InlineNotice tone="warning">{t('mcpStatusUnavailable')}</InlineNotice>
          </div>
        ) : (
          <ErrorState message={errorMessage(statusQuery.error)} onRetry={() => void statusQuery.refetch()} />
        )
      ) : null}

      {status ? (
        <div className="flex flex-col gap-5 px-5 py-5">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={stateTone}>{stateLabel}</Badge>
            <Badge tone="neutral">
              {fill('mcpStatusTokens', {
                active: String(totals.active),
                expired: String(totals.expired),
                total: String(totals.total),
              })}
            </Badge>
            <Badge tone="neutral">
              {fill('mcpStatusLastConnection', {
                value: status.last_connection_at ? formatDateTime(status.last_connection_at, locale) : t('mcpStatusNever'),
              })}
            </Badge>
          </div>

          <section aria-label={t('mcpStatusRecent')}>
            <h3 className="label mb-3">{t('mcpStatusRecent')}</h3>
            {rows.length === 0 ? (
              <EmptyState title={t('mcpStatusNoRecent')} hint={t('mcpStatusNoRecentHint')} />
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>{t('mcpTokenName')}</Th>
                    <Th>{t('mcpOwner')}</Th>
                    <Th>{t('mcpLastUsed')}</Th>
                    <Th>{t('mcpExpires')}</Th>
                    <Th>{t('status')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id}>
                      <Td>{row.name}</Td>
                      <Td>
                        {row.user ? (
                          <span className="flex flex-col">
                            <span>{row.user.name}</span>
                            <span className="text-small text-text-subtlest" dir="ltr">
                              {row.user.email}
                            </span>
                          </span>
                        ) : (
                          '—'
                        )}
                      </Td>
                      <Td>{row.last_used_at ? formatDateTime(row.last_used_at, locale) : t('mcpNeverUsed')}</Td>
                      <Td>{row.expires_at ? formatDateTime(row.expires_at, locale) : '—'}</Td>
                      <Td>
                        <Badge tone={row.state === 'active' ? 'positive' : 'neutral'}>
                          {row.state === 'active' ? t('mcpActive') : t('mcpExpired')}
                        </Badge>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </section>

          <p className="text-small text-text-subtle">{t('mcpStatusReadOnlyNote')}</p>
        </div>
      ) : null}
    </Card>
  )
}
