import { describe, expect, it } from 'vitest'
import { autoCodeNote, createdWithCode } from './codes'

describe('generated code wording', () => {
  it('explains that the code is generated, in both languages', () => {
    expect(autoCodeNote(false)).toMatch(/generated automatically/)
    expect(autoCodeNote(true)).toMatch(/تلقائياً/)
  })

  it('names the generated code after creation', () => {
    expect(createdWithCode(false, 'موظف', 'Employee', 'EMP-0004')).toBe('Employee created with code EMP-0004.')
    expect(createdWithCode(true, 'موظف', 'Employee', 'EMP-0004')).toBe('تم إنشاء موظف بالكود EMP-0004.')
  })

  it('still confirms when the response carries no code', () => {
    expect(createdWithCode(false, 'موظف', 'Employee', undefined)).toBe('Employee created.')
    expect(createdWithCode(true, 'موظف', 'Employee', null)).toBe('تم إنشاء موظف.')
  })
})
