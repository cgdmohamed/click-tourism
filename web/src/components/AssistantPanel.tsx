import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent, KeyboardEvent } from 'react'
import { Link } from 'react-router-dom'
import axios from 'axios'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n'
import { api, errorMessage } from '../lib/api'
import {
  ASSISTANT_REQUEST_TIMEOUT_MS,
  MAX_MESSAGE_CHARS,
  STARTERS,
  addAssistantError,
  addAssistantReply,
  addUserMessage,
  assistantErrorKey,
  canSend,
  citationLink,
  dropLastTurn,
  formatAsOf,
  toApiMessages,
  uniqueCitations,
} from '../lib/assistant'
import type { ChatMessage, ChatReply } from '../lib/assistant'
import { useFeatureFlags } from '../lib/useSetting'
import { Icon } from './icons'
import { Button, IconButton, InlineNotice, Spinner, Textarea } from './ui'

/**
 * Read-only assistant side panel (ADR-0017). The conversation exists only in this component's
 * memory: it is sent with every request, never written to storage, and cleared with the
 * "clear" button, a branch change or sign-out. Citations are links built from the server's
 * citations; the model's text is shown as plain text only.
 */
export function AssistantPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const { flags } = useFeatureFlags()
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [draft, setDraft] = useState('')
  const [busy, setBusy] = useState(false)
  const abortRef = useRef<AbortController | null>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const endRef = useRef<HTMLDivElement>(null)

  const branchId = activeBranch?.id ?? null

  // A different branch is a different data scope: start a clean conversation.
  useEffect(() => {
    abortRef.current?.abort()
    setMessages([])
    setBusy(false)
  }, [branchId])

  useEffect(() => {
    if (open) inputRef.current?.focus()
  }, [open])

  useEffect(() => {
    endRef.current?.scrollIntoView?.({ block: 'end' })
  }, [messages, busy])

  useEffect(() => {
    if (!open) return
    const onKey = (event: globalThis.KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const ask = useCallback(
    async (history: ChatMessage[]) => {
      const controller = new AbortController()
      abortRef.current = controller
      setBusy(true)
      try {
        const { data } = await api.post<{ data: ChatReply }>(
          '/assistant/chat',
          { messages: toApiMessages(history), locale },
          { signal: controller.signal, timeout: ASSISTANT_REQUEST_TIMEOUT_MS },
        )
        setMessages(addAssistantReply(history, data.data))
      } catch (cause) {
        if (axios.isCancel(cause) || controller.signal.aborted) return
        const status = axios.isAxiosError(cause) ? cause.response?.status : undefined
        const code = axios.isAxiosError<{ code?: string }>(cause) ? cause.response?.data?.code : undefined
        // The browser gave up (no response within the turn deadline): same message as a server-side timeout.
        const clientTimeout = axios.isAxiosError(cause) && cause.code === 'ECONNABORTED'
        const key = clientTimeout ? 'assistantErrTimeout' : assistantErrorKey(status, code)
        setMessages(addAssistantError(history, key ? t(key) : status && status < 500 ? errorMessage(cause) : t('assistantErrGeneric')))
      } finally {
        if (abortRef.current === controller) {
          abortRef.current = null
          setBusy(false)
        }
      }
    },
    [locale, t],
  )

  function submit(event?: FormEvent) {
    event?.preventDefault()
    if (!canSend(draft, busy)) return
    const next = addUserMessage(messages, draft)
    setMessages(next)
    setDraft('')
    void ask(next)
  }

  function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault()
      submit()
    }
  }

  function stop() {
    abortRef.current?.abort()
    abortRef.current = null
    setBusy(false)
    // Take the question back into the input so nothing typed is lost.
    const last = [...messages].reverse().find((message) => message.role === 'user')
    if (last) setDraft(last.content)
    setMessages(dropLastTurn(messages))
    inputRef.current?.focus()
  }

  function clear() {
    abortRef.current?.abort()
    abortRef.current = null
    setBusy(false)
    setMessages([])
    setDraft('')
    inputRef.current?.focus()
  }

  function retry() {
    const history = messages.filter((message) => !message.error)
    setMessages(history)
    void ask(history)
  }

  const provider = flags?.assistant_provider === 'openai' ? 'OpenAI' : (flags?.assistant_provider ?? '')
  const noticeKey: MessageKey = flags?.assistant_redact_pii === false ? 'assistantNoticeRaw' : 'assistantNoticeMasked'
  const privacyUrl = flags?.assistant_privacy_url ?? ''
  const lastIsError = messages.length > 0 && messages[messages.length - 1].error === true

  return (
    <aside
      hidden={!open}
      aria-label={t('assistant')}
      className="no-print fixed inset-y-0 end-0 z-40 flex w-full flex-col border-s border-border-subtle bg-surface-default shadow-4 sm:w-[26rem]"
    >
      <div className="flex items-center justify-between gap-2 border-b border-border-subtle px-4 py-3">
        <div className="flex min-w-0 items-center gap-2">
          <Icon name="sparkles" className="h-5 w-5 text-brand-1-accent" />
          <div className="min-w-0">
            <h2 className="heading-small truncate">{t('assistant')}</h2>
            <p className="text-small truncate text-text-subtle">{t('assistantSubtitle')}</p>
          </div>
        </div>
        <div className="flex items-center gap-1">
          <IconButton icon="trash" label={t('assistantClear')} onClick={clear} disabled={messages.length === 0 && draft === ''} />
          <IconButton icon="x" label={t('assistantClose')} onClick={onClose} />
        </div>
      </div>

      <div className="border-b border-border-subtle px-4 py-2">
        <p className="text-small text-text-subtle">
          {t(noticeKey).replace('{provider}', provider)}{' '}
          {privacyUrl ? (
            <a href={privacyUrl} target="_blank" rel="noopener noreferrer" className="underline">
              {t('assistantPrivacyLink')}
            </a>
          ) : null}
        </p>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4" role="log" aria-live="polite" aria-relevant="additions" aria-label={t('assistantMessages')}>
        {messages.length === 0 ? (
          <div className="flex flex-col gap-3">
            <p className="text-medium text-text-subtle">{t('assistantEmpty')}</p>
            <p className="text-small font-semibold text-text-subtle">{t('assistantStarters')}</p>
            <ul className="flex flex-col gap-2">
              {STARTERS[locale].map((question) => (
                <li key={question}>
                  <button
                    type="button"
                    className="text-medium w-full rounded-12 border border-border-subtle px-3 py-2 text-start text-text-default transition hover:bg-surface-hovered"
                    onClick={() => {
                      setDraft(question)
                      inputRef.current?.focus()
                    }}
                  >
                    {question}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ) : (
          <ol className="flex flex-col gap-4">
            {messages.map((message) => (
              <li key={message.key}>
                <MessageBubble message={message} onRetry={lastIsError && message.key === messages[messages.length - 1].key ? retry : undefined} />
              </li>
            ))}
          </ol>
        )}
        {busy ? (
          <div className="text-medium mt-4 flex items-center gap-2 text-text-subtle" role="status">
            <Spinner />
            <span>{t('assistantThinking')}</span>
          </div>
        ) : null}
        <div ref={endRef} />
      </div>

      <form onSubmit={submit} className="flex flex-col gap-2 border-t border-border-subtle px-4 py-3">
        <label className="sr-only" htmlFor="assistant-input">
          {t('assistantInputLabel')}
        </label>
        <Textarea
          id="assistant-input"
          ref={inputRef}
          dir="auto"
          rows={2}
          value={draft}
          maxLength={MAX_MESSAGE_CHARS}
          placeholder={t('assistantPlaceholder')}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={onKeyDown}
          className="min-h-16 resize-none"
        />
        <div className="flex justify-end gap-2">
          {busy ? (
            <Button type="button" variant="secondary" icon="x" onClick={stop}>
              {t('assistantStop')}
            </Button>
          ) : null}
          <Button type="submit" icon="send" disabled={!canSend(draft, busy)}>
            {t('assistantSend')}
          </Button>
        </div>
      </form>
    </aside>
  )
}

function MessageBubble({ message, onRetry }: { message: ChatMessage; onRetry?: () => void }) {
  const { t, locale } = useI18n()
  const mine = message.role === 'user'

  if (message.error) {
    return (
      <div className="flex flex-col gap-2">
        <InlineNotice tone="negative">{message.content}</InlineNotice>
        {onRetry ? (
          <div>
            <Button type="button" variant="secondary" icon="refresh" onClick={onRetry}>
              {t('assistantRetry')}
            </Button>
          </div>
        ) : null}
      </div>
    )
  }

  const citations = uniqueCitations(message.citations ?? [])

  return (
    <div className={mine ? 'flex flex-col items-end gap-1' : 'flex flex-col items-start gap-1'}>
      <p className="text-small font-semibold text-text-subtle">{mine ? t('assistantYou') : t('assistantName')}</p>
      <div
        dir="auto"
        className={
          mine
            ? 'text-medium max-w-full whitespace-pre-wrap break-words rounded-12 bg-surface-selected px-3 py-2 text-text-default'
            : 'text-medium max-w-full whitespace-pre-wrap break-words rounded-12 bg-surface-subtle px-3 py-2 text-text-default'
        }
      >
        {message.content}
      </div>
      {message.truncated ? <p className="text-small text-warning-text">{t('assistantTruncated')}</p> : null}
      {citations.length > 0 ? (
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-small text-text-subtle">{t('assistantSources')}:</span>
          {citations.map((citation) => {
            const link = citationLink(citation, locale)
            const time = formatAsOf(citation.as_of, locale)
            const text = `${link.label}${citation.id ? ` …${citation.id.slice(-6)}` : ''}${time ? ` · ${t('assistantAsOf')} ${time}` : ''}`
            const chip = 'text-small inline-flex items-center gap-1 rounded-full border border-border-subtle px-2 py-0.5'
            return link.href ? (
              <Link key={`${citation.tool}|${citation.id ?? ''}|${citation.route}`} to={link.href} className={`${chip} text-brand-1-accent underline hover:bg-surface-hovered`}>
                {text}
              </Link>
            ) : (
              <span key={`${citation.tool}|${citation.id ?? ''}|${citation.route}`} className={`${chip} text-text-subtle`}>
                {text}
              </span>
            )
          })}
        </div>
      ) : null}
    </div>
  )
}
