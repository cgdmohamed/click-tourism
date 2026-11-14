import { API_BASE, getStoredBranch, getStoredLocale, getToken, setToken } from './api'
import type { ChatReply } from './assistant'

/** One line of the NDJSON stream of POST /assistant/chat/stream (ADR-0022). */
export type StreamEvent =
  | { type: 'thinking' }
  | { type: 'tool'; tool: string; entity: string }
  | { type: 'answer'; data: ChatReply }
  | { type: 'error'; code: string; message: string }

/** Splits a text buffer into complete JSON lines and the unfinished rest; broken lines are skipped. */
export function parseNdjson(buffer: string): { events: StreamEvent[]; rest: string } {
  const lines = buffer.split('\n')
  const rest = lines.pop() ?? ''
  const events: StreamEvent[] = []
  for (const line of lines) {
    if (line.trim() === '') continue
    try {
      const event = JSON.parse(line) as StreamEvent
      if (event && typeof event.type === 'string') events.push(event)
    } catch {
      // A damaged line is ignored; the answer event still arrives or the stream ends with an error.
    }
  }
  return { events, rest }
}

export class AssistantRequestError extends Error {
  status?: number
  code?: string
  constructor(message: string, status?: number, code?: string) {
    super(message)
    this.status = status
    this.code = code
  }
}

type Options = {
  messages: Array<{ role: 'user' | 'assistant'; content: string }>
  locale: 'ar' | 'en'
  conversationId: string | null
  /** Ids of files uploaded with POST /assistant/uploads, read together with the last message. */
  attachments?: string[]
  signal: AbortSignal
  onEvent: (event: StreamEvent) => void
}

/** Sends one turn and reports progress events as they arrive. Resolves when the stream ends. */
export async function streamChat({ messages, locale, conversationId, attachments, signal, onEvent }: Options): Promise<void> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/x-ndjson, application/json',
    'Accept-Language': getStoredLocale(),
  }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  const branch = getStoredBranch()
  if (branch) headers['X-Branch-Id'] = branch

  const response = await fetch(`${API_BASE}/assistant/chat/stream`, {
    method: 'POST',
    headers,
    signal,
    body: JSON.stringify({ messages, locale, ...(conversationId ? { conversation_id: conversationId } : {}), ...(attachments && attachments.length > 0 ? { attachments } : {}) }),
  })

  if (!response.ok) {
    if (response.status === 401) {
      setToken(null)
      if (!window.location.pathname.startsWith('/login')) window.location.assign('/login')
    }
    let body: { message?: string; code?: string } = {}
    try {
      body = (await response.json()) as { message?: string; code?: string }
    } catch {
      // not JSON
    }
    throw new AssistantRequestError(body.message ?? 'Request failed', response.status, body.code)
  }

  if (!response.body) throw new AssistantRequestError('The stream is not available', response.status)
  const reader = response.body.getReader()
  const decoder = new TextDecoder()
  let buffer = ''
  for (;;) {
    const { done, value } = await reader.read()
    buffer += decoder.decode(value, { stream: !done })
    const parsed = parseNdjson(done ? `${buffer}\n` : buffer)
    buffer = parsed.rest
    parsed.events.forEach(onEvent)
    if (done) break
  }
}
