import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { useNotificationText } from '../lib/notifications'
import type { AppNotification, AppNotificationList } from '../lib/types'
import { Icon } from '../components/icons'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Loading,
  PageHeader,
  Pagination,
  cx,
} from '../components/ui'

export default function Notifications() {
  const { t } = useI18n()
  const { labelFor, targetFor, formatWhen } = useNotificationText()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [unreadOnly, setUnreadOnly] = useState(false)

  const query = useQuery({
    queryKey: ['notifications', 'page', page, unreadOnly],
    queryFn: async () => {
      const { data } = await api.get<AppNotificationList>('/notifications', {
        params: { page, per_page: 20, unread: unreadOnly ? 1 : undefined },
      })
      return data
    },
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['notifications'] })

  const markRead = useMutation({
    mutationFn: async (id: string) => {
      await api.post(`/notifications/${id}/read`)
    },
    onSuccess: invalidate,
  })

  const markAllRead = useMutation({
    mutationFn: async () => {
      await api.post('/notifications/read-all')
    },
    onSuccess: invalidate,
  })

  const notifications = query.data?.data ?? []
  const meta = query.data?.meta

  const open = (notification: AppNotification) => {
    if (!notification.read_at) markRead.mutate(notification.id)
    const target = targetFor(notification)
    if (target) navigate(target)
  }

  const toggleUnread = () => {
    setPage(1)
    setUnreadOnly((value) => !value)
  }

  return (
    <div>
      <PageHeader
        title={t('notifications')}
        subtitle={t('notificationsSubtitle')}
        icon="bell"
        actions={
          <>
            <Button variant="secondary" icon="inbox" onClick={toggleUnread}>
              {unreadOnly ? t('showAllNotifications') : t('unreadOnly')}
            </Button>
            <Button
              icon="check"
              onClick={() => markAllRead.mutate()}
              disabled={markAllRead.isPending || (meta?.unread_count ?? 0) === 0}
            >
              {t('markAllRead')}
            </Button>
          </>
        }
      />

      <Card>
        {query.isLoading ? (
          <Loading />
        ) : query.isError ? (
          <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
        ) : notifications.length === 0 ? (
          <EmptyState title={t('noNotifications')} hint={t('noNotificationsHint')} icon="bell" />
        ) : (
          <ul className="divide-y divide-border-subtle">
            {notifications.map((notification) => (
              <li
                key={notification.id}
                className={cx('flex items-start gap-3 px-5 py-4', !notification.read_at && 'bg-surface-subtle')}
              >
                <button
                  type="button"
                  className="flex min-w-0 flex-1 items-start gap-3 text-start"
                  onClick={() => open(notification)}
                >
                  <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-subtle text-text-subtle">
                    <Icon name="bell" className="h-4 w-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="text-medium block font-medium text-text-default">{labelFor(notification)}</span>
                      {!notification.read_at ? <Badge tone="brand">{t('unread')}</Badge> : null}
                    </span>
                    {notification.body ? (
                      <span className="text-small mt-1 block text-text-subtle">{notification.body}</span>
                    ) : null}
                    <span className="text-small mt-1 block text-text-subtlest">{formatWhen(notification.created_at)}</span>
                  </span>
                </button>
                {!notification.read_at ? (
                  <Button variant="secondary" className="shrink-0" onClick={() => markRead.mutate(notification.id)}>
                    {t('markRead')}
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        )}
        {meta ? <Pagination page={meta.current_page} lastPage={meta.last_page} onChange={setPage} /> : null}
      </Card>
    </div>
  )
}
