import { describe, expect, it } from 'vitest'
import { describeError, recallError, rememberError } from './errors'

const http = (status: number, data: Record<string, unknown> = {}, headers: Record<string, string> = {}) => ({
  isAxiosError: true,
  message: `Request failed with status code ${status}`,
  response: { status, data, headers },
})

describe('describeError', () => {
  const cases: Array<[number, string, string, string]> = [
    [401, 'unauthorized', 'انتهت جلستك', 'Your session has ended'],
    [403, 'forbidden', 'ليست لديك صلاحية لهذا الإجراء', 'You do not have permission for this'],
    [404, 'notFound', 'لم يُعثر على السجل المطلوب', 'The record was not found'],
    [422, 'validation', 'البيانات المرسلة غير صالحة', 'The submitted data is not valid'],
    [429, 'rateLimit', 'طلبات كثيرة في وقت قصير', 'Too many requests'],
    [500, 'server', 'حدث خطأ في الخادم', 'The server hit an error'],
    [503, 'server', 'حدث خطأ في الخادم', 'The server hit an error'],
  ]

  it.each(cases)('maps HTTP %i in both languages', (status, kind, ar, en) => {
    expect(describeError(http(status), 'ar')).toMatchObject({ kind, status, title: ar })
    expect(describeError(http(status), 'en')).toMatchObject({ kind, status, title: en })
  })

  it('keeps the server message as detail, never as the headline', () => {
    const d = describeError(http(500, { message: 'SQLSTATE[HY000]: boom' }), 'en')
    expect(d.title).not.toContain('SQLSTATE')
    expect(d.detail).toBe('SQLSTATE[HY000]: boom')
  })

  it('translates a known server message in the details when Arabic', () => {
    expect(describeError(http(500, { message: 'Server Error' }), 'ar').detail).toBe('حدث خطأ في الخادم.')
  })

  it('prefers the first validation error as detail', () => {
    const d = describeError(http(422, { message: 'The given data was invalid.', errors: { name: ['Name is required'] } }), 'en')
    expect(d.detail).toBe('Name is required')
  })

  it('reads the request id from the header, then from the body', () => {
    expect(describeError(http(500, { requestId: 'body-id' }, { 'x-request-id': 'hdr-id' }), 'en').requestId).toBe('hdr-id')
    expect(describeError(http(500, { requestId: 'body-id' }), 'en').requestId).toBe('body-id')
    expect(describeError(http(500), 'en').requestId).toBeUndefined()
  })

  it('distinguishes offline, unreachable server and timeout', () => {
    const noResponse = { isAxiosError: true, code: 'ERR_NETWORK', message: 'Network Error' }
    expect(describeError(noResponse, 'en', false)).toMatchObject({ kind: 'offline', title: 'You are offline' })
    expect(describeError(noResponse, 'ar', false).title).toBe('لا يوجد اتصال بالإنترنت')
    expect(describeError(noResponse, 'en', true)).toMatchObject({ kind: 'network', title: 'Could not reach the server' })
    expect(describeError({ isAxiosError: true, code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded' }, 'ar', true)).toMatchObject({
      kind: 'timeout',
      title: 'استغرق الطلب وقتاً طويلاً',
    })
  })

  it('falls back to an unknown error for non-HTTP values', () => {
    expect(describeError(new Error('x'), 'en', true).kind).toBe('unknown')
    expect(describeError('boom', 'ar', true).title).toBe('حدث خطأ غير متوقع')
  })
})

describe('rememberError / recallError', () => {
  it('recovers the original error from its formatted message', () => {
    const error = http(500)
    rememberError('some message', error)
    expect(recallError('some message')).toBe(error)
    expect(recallError('never seen')).toBeUndefined()
  })

  it('is bounded', () => {
    for (let i = 0; i < 80; i += 1) rememberError(`m${i}`, { i })
    expect(recallError('m0')).toBeUndefined()
    expect(recallError('m79')).toEqual({ i: 79 })
  })
})
