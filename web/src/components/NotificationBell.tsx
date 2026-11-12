import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { useNotificationText } from '../lib/notifications'
import type { AppNotification, AppNotificationList } from '../lib/types'
import { Icon } from './icons'
import { IconButton, Spinner, cx } from './ui'

/**
 * Header bell for GAP-CORE-01. Shows the signed-in user's unread count and a
 * short recent list; the full list lives on /notifications.
 */
export function NotificationBell() {
  const { t } = useI18n()
  const { labelFor, targetFor, formatWhen } = useNotificationText()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)

  const query = useQuery({
    queryKey: ['notifications', 'bell'],
    queryFn: async () => {
      const { data } = await api.get<AppNotificationList>('/notifications', { params: { per_page: 5 } })
      return data
    },
    refetchInterval: 60_000,
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
  const unread = query.data?.meta.unread_count ?? 0

  const openNotification = (notification: AppNotification) => {
    if (!notification.read_at) markRead.mutate(notification.id)
    setOpen(false)
    const target = targetFor(notification)
    if (target) navigate(target)
  }

  return (
    <div className="relative">
      <span className="relative inline-flex">
        <IconButton icon="bell" label={t('notifications')} onClick={() => setOpen((value) => !value)} />
        {unread > 0 ? (
          <span
            className="text-small pointer-events-none absolute -end-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger-background px-1 font-semibold leading-none text-danger-text"
            aria-hidden="true"
          >
            {unread > 99 ? '99+' : unread}
          </span>
        ) : null}
      </span>
      <span className="sr-only" aria-live="polite">
        {t('unreadNotifications')}: {unread}
      </span>

      {open ? (
        <>
          <div className="fixed inset-0 z-20" aria-hidden="true" onClick={() => setOpen(false)} />
          <div
            className="absolute end-0 z-30 mt-2 w-80 overflow-hidden rounded-16 border border-border-subtle bg-surface-default shadow-2"
            role="dialog"
            aria-label={t('notifications')}
          >
            <div className="flex items-center justify-between gap-2 border-b border-border-subtle px-4 py-3">
              <p className="label text-text-default">{t('notifications')}</p>
              {unread > 0 ? (
                <button
                  type="button"
                  className="text-small font-medium text-brand-1-accent hover:underline"
                  onClick={() => markAllRead.mutate()}
                  disabled={markAllRead.isPending}
                >
                  {t('markAllRead')}
                </button>
              ) : null}
            </div>

            {query.isLoading ? (
              <div className="flex justify-center px-4 py-8">
                <Spinner />
              </div>
            ) : query.isError ? (
              <p className="text-small px-4 py-6 text-danger-text">{errorMessage(query.error)}</p>
            ) : notifications.length === 0 ? (
              <p className="text-small px-4 py-8 text-center text-text-subtle">{t('noNotifications')}</p>
            ) : (
              <ul className="max-h-80 divide-y divide-border-subtle overflow-y-auto">
                {notifications.map((notification) => (
                  <li key={notification.id}>
                    <button
                      type="button"
                      className={cx(
                        'flex w-full items-start gap-3 px-4 py-3 text-start transition hover:bg-surface-hovered',
                        !notification.read_at && 'bg-surface-subtle',
                      )}
                      onClick={() => openNotification(notification)}
                    >
                      <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-subtle text-text-subtle">
                        <Icon name="bell" className="h-4 w-4" />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="text-small block font-medium text-text-default">{labelFor(notification)}</span>
                        {notification.body ? (
                          <span className="text-small mt-0.5 block line-clamp-2 text-text-subtle">{notification.body}</span>
                        ) : null}
                        <span className="text-small mt-1 block text-text-subtlest">{formatWhen(notification.created_at)}</span>
                      </span>
                      {!notification.read_at ? (
                        <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-1-accent" aria-hidden="true" />
                      ) : null}
                    </button>
                  </li>
                ))}
              </ul>
            )}

            <div className="border-t border-border-subtle px-4 py-2 text-center">
              <Link
                to="/notifications"
                className="text-small font-medium text-brand-1-accent hover:underline"
                onClick={() => setOpen(false)}
              >
                {t('viewAllNotifications')}
              </Link>
            </div>
          </div>
        </>
      ) : null}
    </div>
  )
}
