/** Shared wording for master-data codes that the system generates (EMP-0001, CC-001, BR-01, ...). */

/** Shown in create forms in place of a code input. */
export function autoCodeNote(ar: boolean): string {
  return ar ? 'يُنشأ الكود تلقائياً عند الحفظ.' : 'The code is generated automatically when you save.'
}

/** Success message after create; names the generated code so the user can find the record. */
export function createdWithCode(ar: boolean, labelAr: string, labelEn: string, code: string | null | undefined): string {
  if (!code) return ar ? `تم إنشاء ${labelAr}.` : `${labelEn} created.`
  return ar ? `تم إنشاء ${labelAr} بالكود ${code}.` : `${labelEn} created with code ${code}.`
}
