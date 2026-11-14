import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent, KeyboardEvent } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n'
import { api, errorMessage } from '../lib/api'
import {
  MAX_MESSAGE_CHARS,
  addAssistantError,
  addAssistantReply,
  addUserMessage,
  assistantErrorKey,
  canSend,
  citationLink,
  dropLastTurn,
  formatAsOf,
  fromSaved,
  pickStarters,
  stepEntity,
  toApiMessages,
  uniqueCitations,
} from '../lib/assistant'
import type { ActionCard, ChatMessage, Citation } from '../lib/assistant'
import { linkRecords, parseAnswer } from '../lib/answerText'
import type { Inline } from '../lib/answerText'
import { AssistantRequestError, streamChat } from '../lib/assistantStream'
import { localizedName } from '../lib/names'
import { useFeatureFlags } from '../lib/useSetting'
import { useTheme } from '../theme'
import { Icon } from './icons'
import { Badge, Button, IconButton, InlineNotice, Spinner, cx, Select } from './ui'

type SavedConversation = { id: string; title: string; last_message_at: string | null }

/**
 * The assistant as a full-screen chat (ADR-0017, ADR-0022): the rest of the system is hidden while it
 * is on. Past chats are listed on the side, answers show what the assistant is doing while it works,
 * and the model's text is rendered as structured text (no HTML) with links built from server citations.
 */
export function AssistantChat({ onExit }: { onExit: () => void }) {
  const { t, locale, toggleLocale } = useI18n()
  const { theme, toggleTheme } = useTheme()
  const { branches, activeBranch, setActiveBranch } = useAuth()
  const { flags } = useFeatureFlags()
  const queryClient = useQueryClient()
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [conversationId, setConversationId] = useState<string | null>(null)
  const [draft, setDraft] = useState('')
  const [files, setFiles] = useState<Array<{ id: string; name: string }>>([])
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState('')
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [status, setStatus] = useState<string>('')
  const [done, setDone] = useState<string[]>([])
  const [listOpen, setListOpen] = useState(false)
  const [starters, setStarters] = useState(() => pickStarters(locale))
  const abortRef = useRef<AbortController | null>(null)
  const stepsRef = useRef<string[]>([])
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const endRef = useRef<HTMLDivElement>(null)

  const branchId = activeBranch?.id ?? null

  // A different branch is a different data scope: start a clean conversation.
  useEffect(() => {
    abortRef.current?.abort()
    setMessages([])
    setConversationId(null)
    setBusy(false)
  }, [branchId])

  useEffect(() => {
    setStarters(pickStarters(locale))
  }, [locale])

  // The example questions change by themselves while the chat is empty (not for reduced motion).
  const empty = messages.length === 0
  useEffect(() => {
    if (!empty || (typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return
    const timer = window.setInterval(() => setStarters(pickStarters(locale)), 12000)
    return () => window.clearInterval(timer)
  }, [empty, locale])

  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  useEffect(() => {
    endRef.current?.scrollIntoView?.({ block: 'end' })
  }, [messages, busy, status])

  const history = useQuery({
    queryKey: ['assistant-conversations', branchId],
    queryFn: async () => {
      const { data } = await api.get<{ data: SavedConversation[] }>('/assistant/conversations')
      return data.data
    },
    staleTime: 30_000,
  })

  const remove = useMutation({
    mutationFn: async (id: string | null) => {
      if (id === null) await api.delete('/assistant/conversations')
      else await api.delete(`/assistant/conversations/${encodeURIComponent(id)}`)
    },
    onSuccess: async (_data, id) => {
      if (id === null || id === conversationId) startNew()
      await queryClient.invalidateQueries({ queryKey: ['assistant-conversations'] })
    },
  })

  const ask = useCallback(
    async (turns: ChatMessage[], conversation: string | null, attachments: string[] = []) => {
      const controller = new AbortController()
      abortRef.current = controller
      stepsRef.current = []
      setDone([])
      setStatus(t('assistantStepThinking'))
      setBusy(true)
      try {
        await streamChat({
          messages: toApiMessages(turns),
          locale,
          conversationId: conversation,
          attachments,
          signal: controller.signal,
          onEvent: (event) => {
            if (event.type === 'thinking') {
              setStatus(t('assistantStepThinking'))
            } else if (event.type === 'tool' && event.entity === 'action') {
              setStatus(t('assistantStepPreparing'))
            } else if (event.type === 'tool') {
              const label = stepEntity(event.entity, locale)
              if (!stepsRef.current.includes(label)) stepsRef.current = [...stepsRef.current, label]
              setDone(stepsRef.current)
              setStatus(t('assistantStepSearching').replace('{entity}', label))
            } else if (event.type === 'answer') {
              setMessages(addAssistantReply(turns, event.data, stepsRef.current))
              if (event.data.conversation_id) setConversationId(event.data.conversation_id)
              void queryClient.invalidateQueries({ queryKey: ['assistant-conversations'] })
            } else if (event.type === 'error') {
              const key = assistantErrorKey(undefined, event.code)
              setMessages(addAssistantError(turns, key ? t(key) : t('assistantErrGeneric')))
            }
          },
        })
      } catch (cause) {
        if (controller.signal.aborted) return
        const status = cause instanceof AssistantRequestError ? cause.status : axios.isAxiosError(cause) ? cause.response?.status : undefined
        const code = cause instanceof AssistantRequestError ? cause.code : undefined
        const key = assistantErrorKey(status, code)
        const text = key ? t(key) : cause instanceof AssistantRequestError && status && status < 500 ? cause.message : t('assistantErrGeneric')
        setMessages(addAssistantError(turns, text))
      } finally {
        if (abortRef.current === controller) {
          abortRef.current = null
          setBusy(false)
          setStatus('')
        }
      }
    },
    [locale, t, queryClient],
  )

  function submit(event?: FormEvent) {
    event?.preventDefault()
    // A file with no words still sends: the assistant is asked to read it.
    const text = draft.trim() === '' && files.length > 0 ? t('assistantAttachDefault') : draft
    if (!canSend(text, busy) || uploading) return
    const label = files.length > 0 ? `${text}\n[${files.map((file) => file.name).join(', ')}]` : text
    const next = addUserMessage(messages, label)
    const ids = files.map((file) => file.id)
    setMessages(next)
    setDraft('')
    setFiles([])
    void ask(next, conversationId, ids)
  }

  async function attach(list: FileList | null) {
    if (!list || list.length === 0) return
    setUploadError('')
    setUploading(true)
    try {
      let count = files.length
      for (const file of Array.from(list)) {
        if (count >= 3) break
        count += 1
        const form = new FormData()
        form.append('file', file)
        const { data } = await api.post<{ data: { id: string; name: string } }>('/assistant/uploads', form)
        setFiles((current) => [...current, { id: data.data.id, name: data.data.name }])
      }
    } catch (cause) {
      setUploadError(errorMessage(cause))
    } finally {
      setUploading(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
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
    setStatus('')
    // Take the question back into the input so nothing typed is lost.
    const last = [...messages].reverse().find((message) => message.role === 'user')
    if (last) setDraft(last.content)
    setMessages(dropLastTurn(messages))
    inputRef.current?.focus()
  }

  function startNew() {
    abortRef.current?.abort()
    abortRef.current = null
    setBusy(false)
    setStatus('')
    setMessages([])
    setConversationId(null)
    setDraft('')
    setFiles([])
    setUploadError('')
    setStarters(pickStarters(locale))
    setListOpen(false)
    inputRef.current?.focus()
  }

  async function open(id: string) {
    abortRef.current?.abort()
    abortRef.current = null
    setBusy(false)
    setStatus('')
    setListOpen(false)
    try {
      const { data } = await api.get<{ data: { id: string; messages: Parameters<typeof fromSaved>[0] } }>(`/assistant/conversations/${encodeURIComponent(id)}`)
      setMessages(fromSaved(data.data.messages))
      setConversationId(data.data.id)
    } catch (cause) {
      setMessages([{ key: 1, role: 'assistant', content: errorMessage(cause), error: true }])
      setConversationId(null)
    }
    inputRef.current?.focus()
  }

  function retry() {
    const turns = messages.filter((message) => !message.error)
    setMessages(turns)
    void ask(turns, conversationId)
  }

  const provider = flags?.assistant_provider === 'openai' ? 'OpenAI' : (flags?.assistant_provider ?? '')
  const noticeKey: MessageKey = flags?.assistant_redact_pii === false ? 'assistantNoticeRaw' : 'assistantNoticeMasked'
  const privacyUrl = flags?.assistant_privacy_url ?? ''
  const lastIsError = messages.length > 0 && messages[messages.length - 1].error === true
  const saved = history.data ?? []

  const sidebar = (
    <div className="flex h-full min-h-0 flex-col gap-3 p-3">
      <Button type="button" icon="plus" onClick={startNew} className="w-full justify-center">
        {t('assistantNewChat')}
      </Button>
      <p className="text-small px-1 font-semibold text-text-subtle">{t('assistantHistory')}</p>
      <ul className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto" aria-label={t('assistantHistory')}>
        {saved.length === 0 ? <li className="text-small px-1 text-text-subtle">{t('assistantHistoryEmpty')}</li> : null}
        {saved.map((item) => (
          <li key={item.id} className="group flex items-center gap-1">
            <button
              type="button"
              dir="auto"
              onClick={() => void open(item.id)}
              aria-current={item.id === conversationId ? 'true' : undefined}
              className={cx(
                'text-medium min-w-0 flex-1 truncate rounded-12 px-3 py-2 text-start transition hover:bg-surface-hovered',
                item.id === conversationId ? 'bg-surface-selected text-text-default' : 'text-text-subtle',
              )}
            >
              {item.title}
            </button>
            <IconButton icon="trash" label={t('assistantDeleteChat')} onClick={() => remove.mutate(item.id)} disabled={remove.isPending} />
          </li>
        ))}
      </ul>
      {saved.length > 0 ? (
        <Button
          type="button"
          variant="secondary"
          icon="trash"
          disabled={remove.isPending}
          onClick={() => {
            if (window.confirm(t('assistantDeleteAllConfirm'))) remove.mutate(null)
          }}
        >
          {t('assistantDeleteAll')}
        </Button>
      ) : null}
    </div>
  )

  return (
    <div className="flex h-screen min-h-0 bg-surface-canvas" role="region" aria-label={t('assistant')}>
      <aside className="no-print hidden w-72 shrink-0 border-e border-border-subtle bg-surface-default lg:block">{sidebar}</aside>
      {listOpen ? (
        <div className="no-print fixed inset-0 z-40 bg-black/40 lg:hidden" onClick={() => setListOpen(false)}>
          <aside className="h-full w-72 bg-surface-default" onClick={(event) => event.stopPropagation()}>
            {sidebar}
          </aside>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="no-print flex items-center justify-between gap-2 border-b border-border-subtle bg-surface-default px-3 py-2 lg:px-6">
          <div className="flex min-w-0 items-center gap-2">
            <IconButton icon="menu" label={t('assistantHistoryToggle')} variant="secondary" className="lg:hidden" onClick={() => setListOpen(true)} />
            <Icon name="sparkles" className="h-5 w-5 shrink-0 text-brand-1-accent" />
            <h1 className="heading-small truncate">{t('assistant')}</h1>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-2">
            {branches.length > 1 ? (
              <label className="text-small flex items-center gap-2 text-text-subtle">
                <Icon name="building" className="h-4 w-4" />
                <Select
                  aria-label={t('branch')}
                  className="w-44"
                  value={activeBranch?.id ?? ''}
                  onChange={(event) => setActiveBranch(event.target.value)}
                >
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {localizedName(branch, locale)}
                    </option>
                  ))}
                </Select>
              </label>
            ) : null}
            <IconButton icon={theme === 'dark' ? 'sun' : 'moon'} label={theme === 'dark' ? t('switchToLight') : t('switchToDark')} onClick={toggleTheme} />
            <button
              className="text-small inline-flex h-9 items-center gap-2 rounded-12 border border-border-subtle px-2.5 font-medium text-text-subtle transition hover:bg-surface-hovered hover:text-text-default"
              onClick={toggleLocale}
              title={t('language')}
              aria-label={t('language')}
            >
              <Icon name="globe" className="h-4 w-4" />
              {locale === 'ar' ? 'EN' : 'عربي'}
            </button>
            <button
              type="button"
              className="text-small inline-flex h-9 items-center gap-2 rounded-12 bg-surface-selected px-2.5 font-medium text-text-default transition hover:bg-surface-hovered"
              onClick={onExit}
              aria-pressed="true"
              title={t('assistantExit')}
            >
              <Icon name="sparkles" className="h-4 w-4 text-brand-1-accent" />
              <span className="hidden sm:inline">{t('assistantExit')}</span>
            </button>
          </div>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto" role="log" aria-live="polite" aria-relevant="additions" aria-label={t('assistantMessages')}>
          <div className="mx-auto flex min-h-full w-full max-w-3xl flex-col px-4 py-6">
            {empty ? (
              <div className="flex flex-1 flex-col items-center justify-center gap-6 py-10 text-center">
                <span className="flex h-14 w-14 items-center justify-center rounded-full bg-surface-selected">
                  <Icon name="sparkles" className="h-7 w-7 text-brand-1-accent" />
                </span>
                <div className="flex flex-col gap-1">
                  <h2 className="heading-large">{t('assistantGreeting')}</h2>
                  <p className="text-medium text-text-subtle">{t('assistantEmpty')}</p>
                </div>
                <ul className="grid w-full gap-2 sm:grid-cols-2" aria-label={t('assistantStarters')}>
                  {starters.map((question) => (
                    <li key={question}>
                      <button
                        type="button"
                        dir="auto"
                        className="text-medium h-full w-full rounded-12 border border-border-subtle bg-surface-default px-4 py-3 text-start text-text-default transition hover:bg-surface-hovered"
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
                <Button type="button" variant="secondary" icon="refresh" onClick={() => setStarters(pickStarters(locale))}>
                  {t('assistantMoreIdeas')}
                </Button>
              </div>
            ) : (
              <ol className="flex flex-col gap-5">
                {messages.map((message) => (
                  <li key={message.key}>
                    <MessageBubble message={message} onRetry={lastIsError && message.key === messages[messages.length - 1].key ? retry : undefined} />
                  </li>
                ))}
              </ol>
            )}
            {busy ? (
              <div className="text-medium mt-5 flex flex-col gap-1 text-text-subtle" role="status">
                {done.length > 1 ? (
                  <p className="text-small">
                    {t('assistantStepsDone')}: {done.slice(0, -1).join('، ')}
                  </p>
                ) : null}
                <div className="flex items-center gap-2">
                  <Spinner />
                  <span>{status}</span>
                  <span className="typing-dots inline-flex gap-0.5" aria-hidden="true"><span>·</span><span>·</span><span>·</span></span>
                </div>
              </div>
            ) : null}
            <div ref={endRef} />
          </div>
        </div>

        <form onSubmit={submit} className="no-print border-t border-border-subtle bg-surface-default px-4 py-3">
          <div className="mx-auto flex w-full max-w-3xl flex-col gap-2">
            <label className="sr-only" htmlFor="assistant-input">
              {t('assistantInputLabel')}
            </label>
            {files.length > 0 || uploading || uploadError ? (
              <div className="flex flex-wrap items-center gap-2" aria-live="polite">
                {files.map((file) => (
                  <span key={file.id} className="anim-rise text-small inline-flex max-w-full items-center gap-1 rounded-full border border-border-default bg-surface-subtle py-1 pe-1 ps-3">
                    <Icon name="paperclip" size={14} />
                    <bdi className="truncate">{file.name}</bdi>
                    <IconButton icon="x" label={t('assistantAttachRemove')} onClick={() => setFiles((current) => current.filter((item) => item.id !== file.id))} />
                  </span>
                ))}
                {uploading ? <span className="text-small text-text-subtle">{t('assistantAttachUploading')}</span> : null}
                {uploadError ? <span role="alert" className="text-small text-danger-text">{uploadError}</span> : null}
              </div>
            ) : null}
            <div className="flex items-end gap-2 rounded-16 border border-border-default bg-surface-default p-2">
              <input
                ref={fileInputRef}
                type="file"
                accept="image/png,image/jpeg,image/webp,application/pdf"
                multiple
                className="sr-only"
                tabIndex={-1}
                aria-hidden="true"
                onChange={(event) => void attach(event.target.files)}
              />
              <IconButton icon="paperclip" label={t('assistantAttach')} disabled={busy || uploading || files.length >= 3} onClick={() => fileInputRef.current?.click()} />
              <textarea
                id="assistant-input"
                ref={inputRef}
                dir="auto"
                rows={Math.min(6, Math.max(1, draft.split('\n').length))}
                value={draft}
                maxLength={MAX_MESSAGE_CHARS}
                placeholder={t('assistantPlaceholder')}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={onKeyDown}
                className="text-medium min-h-10 flex-1 resize-none bg-transparent px-2 py-2 text-text-default outline-none placeholder:text-text-subtle"
              />
              {busy ? (
                <Button type="button" variant="secondary" icon="x" onClick={stop}>
                  {t('assistantStop')}
                </Button>
              ) : null}
              <Button type="submit" icon="send" disabled={busy || uploading || !(canSend(draft, busy) || (draft.trim() === '' && files.length > 0))}>
                {t('assistantSend')}
              </Button>
            </div>
            <p className="text-small text-center text-text-subtle">
              {t(noticeKey).replace('{provider}', provider)}{' '}
              {files.length > 0 ? `${t('assistantAttachNote')} ` : null}
              {privacyUrl ? (
                <a href={privacyUrl} target="_blank" rel="noopener noreferrer" className="underline">
                  {t('assistantPrivacyLink')}
                </a>
              ) : null}
            </p>
          </div>
        </form>
      </div>
    </div>
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
    <div className={mine ? 'anim-rise flex flex-col items-end gap-1' : 'anim-rise flex flex-col items-start gap-1'}>
      <p className="text-small font-semibold text-text-subtle">{mine ? t('assistantYou') : t('assistantName')}</p>
      {message.steps && message.steps.length > 0 ? (
        <p className="text-small text-text-subtle">
          {t('assistantStepsDone')}: {message.steps.join('، ')}
        </p>
      ) : null}
      <div
        dir="auto"
        className={
          mine
            ? 'text-medium max-w-[85%] whitespace-pre-wrap break-words rounded-16 bg-surface-selected px-4 py-2 text-text-default'
            : 'text-medium max-w-full break-words rounded-16 bg-surface-default px-4 py-3 text-text-default shadow-1'
        }
      >
        {mine ? message.content : <AnswerBody text={message.content} citations={message.citations ?? []} />}
      </div>
      {(message.actions ?? []).map((card) => (
        <ActionCardView key={card.id} initial={card} />
      ))}
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

/** Confirmation card of an action the assistant prepared (ADR-0023): nothing runs until the user confirms. */
function ActionCardView({ initial }: { initial: ActionCard }) {
  const { t, locale } = useI18n()
  const [card, setCard] = useState(initial)
  const [error, setError] = useState('')
  // post / pay / void / delete need a second, explicit "yes" after the card (the card itself is the first).
  const [armed, setArmed] = useState(false)
  const decide = useMutation({
    mutationFn: async (verb: 'confirm' | 'reject') => {
      const { data } = await api.post<{ data: ActionCard }>(`/assistant/actions/${encodeURIComponent(card.id)}/${verb}`)
      return data.data
    },
    onSuccess: (next) => {
      setCard(next)
      setError('')
    },
    onError: (cause) => {
      const code = axios.isAxiosError<{ code?: string }>(cause) ? cause.response?.data?.code : undefined
      if (code === 'assistant_action_expired') setCard({ ...card, status: 'expired' })
      else setError(errorMessage(cause))
    },
  })
  const strong = card.risk === 'post' || card.risk === 'pay' || card.risk === 'void' || card.risk === 'delete'
  const link = card.result ? citationLink({ entity: card.result.entity, id: card.result.id }, locale) : null
  const tone =
    card.status === 'confirmed' ? 'border-success-border' : card.status === 'failed' || card.status === 'expired' ? 'border-danger-border' : 'border-border-selected'
  const statusText: Record<ActionCard['status'], string> = {
    pending: '',
    confirmed: t('assistantActionConfirmed'),
    rejected: t('assistantActionRejected'),
    failed: t('assistantActionFailed'),
    expired: t('assistantActionExpired'),
  }

  return (
    <section className={cx('anim-rise w-full max-w-xl rounded-16 border bg-surface-default p-4 shadow-1', tone)} aria-label={card.title}>
      <h3 className="label flex items-center gap-2">
        <Icon name="sparkles" className="h-4 w-4 text-brand-1-accent" />
        {card.title}
      </h3>
      <dl className="text-medium mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
        {card.rows.map((row, index) => (
          <div key={`${row.label}-${index}`} className="contents">
            <dt className="text-text-subtle">{row.label}</dt>
            <dd dir="auto" className="min-w-0 break-words text-text-default">
              {row.value}
            </dd>
          </div>
        ))}
      </dl>
      {card.status === 'pending' ? (
        <>
          <p className="text-small mt-3 text-text-subtle">{t('assistantActionNote')}</p>
          {armed ? (
            <div className="anim-rise mt-3" role="alert">
              <InlineNotice tone="warning">{t('assistantActionSure')}</InlineNotice>
            </div>
          ) : null}
          <div className="mt-3 flex flex-wrap gap-2">
            <Button
              type="button"
              icon="check"
              variant={armed && (card.risk === 'delete' || card.risk === 'void') ? 'danger' : 'primary'}
              disabled={decide.isPending}
              onClick={() => (strong && !armed ? setArmed(true) : decide.mutate('confirm'))}
            >
              {armed ? t('assistantActionSureYes') : t('assistantActionConfirm')}
            </Button>
            <Button type="button" variant="secondary" disabled={decide.isPending} onClick={() => (armed ? setArmed(false) : decide.mutate('reject'))}>
              {armed ? t('assistantActionBack') : t('assistantActionReject')}
            </Button>
          </div>
        </>
      ) : (
        <div className="mt-3 flex flex-wrap items-center gap-3" role="status">
          {card.status === 'confirmed' ? <Icon name="check" className="anim-check h-4 w-4 text-success-text" /> : null}
          <Badge tone={card.status === 'confirmed' ? 'positive' : card.status === 'rejected' ? 'neutral' : 'negative'}>{statusText[card.status]}</Badge>
          {card.status === 'failed' && card.error?.message ? <span className="text-small text-danger-text">{card.error.message}</span> : null}
          {card.status === 'confirmed' && link?.href ? (
            <Link to={link.href} className="text-small text-brand-1-accent underline">
              {t('assistantActionOpen')}
            </Link>
          ) : null}
        </div>
      )}
      {error ? (
        <div className="mt-3" role="alert">
          <InlineNotice tone="negative">{error}</InlineNotice>
        </div>
      ) : null}
    </section>
  )
}

function InlineRuns({ runs }: { runs: Inline[] }) {
  return (
    <>
      {runs.map((run, index) => {
        if (run.kind === 'link') {
          return (
            <Link key={index} to={run.href} className="text-brand-1-accent underline hover:bg-surface-hovered">
              {run.text}
            </Link>
          )
        }
        return run.kind === 'bold' ? <strong key={index}>{run.text}</strong> : <span key={index}>{run.text}</span>
      })}
    </>
  )
}

/** The model's light markdown as real structure; record numbers become links to the record. */
function AnswerBody({ text, citations }: { text: string; citations: Citation[] }) {
  const { locale } = useI18n()
  const link = (runs: Inline[]) => linkRecords(runs, citations, locale)
  return (
    <div className="flex flex-col gap-2">
      {parseAnswer(text).map((block, index) => {
        if (block.kind === 'p') {
          return (
            <p key={index} className="whitespace-pre-wrap">
              <InlineRuns runs={link(block.inline)} />
            </p>
          )
        }
        const List = block.kind === 'ol' ? 'ol' : 'ul'
        return (
          <List key={index} start={block.kind === 'ol' ? block.start : undefined} className={`flex flex-col gap-1 ps-5 ${block.kind === 'ol' ? 'list-decimal' : 'list-disc'}`}>
            {block.items.map((item, itemIndex) => (
              <li key={itemIndex}>
                <InlineRuns runs={link(item.inline)} />
                {item.children.length > 0 ? (
                  <ul className="mt-1 flex list-disc flex-col gap-0.5 ps-5">
                    {item.children.map((child, childIndex) => (
                      <li key={childIndex}>
                        <InlineRuns runs={link(child)} />
                      </li>
                    ))}
                  </ul>
                ) : null}
              </li>
            ))}
          </List>
        )
      })}
    </div>
  )
}
