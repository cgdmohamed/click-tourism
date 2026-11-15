/** What may be attached to an assistant message (ADR-0033): JPG and PNG images, Word (docx) and Excel (xlsx). */
export type FileKind = 'image' | 'word' | 'excel'

export const MAX_FILES = 5
export const MAX_FILE_BYTES = 10 * 1024 * 1024
/** For the file picker; the real check is on the bytes at the server. */
export const ACCEPT = 'image/png,image/jpeg,.png,.jpg,.jpeg,.docx,.xlsx'

/** A file as the screen keeps it after the upload: the preview is a local object URL, never sent anywhere. */
export type AttachedFile = { id: string; name: string; expires_at?: string; kind?: FileKind; previewUrl?: string }

const WORD = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
const EXCEL = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

/** The kind by type, falling back to the extension when the browser gives none (some pastes and drops do). */
export function fileKind(file: { name: string; type: string }): FileKind | null {
  const type = file.type.toLowerCase()
  const name = file.name.toLowerCase()
  if (type === 'image/png' || type === 'image/jpeg') return 'image'
  if (type === WORD) return 'word'
  if (type === EXCEL) return 'excel'
  if (type !== '' && type !== 'application/octet-stream') return null
  if (/\.(png|jpe?g)$/.test(name)) return 'image'
  if (name.endsWith('.docx')) return 'word'
  if (name.endsWith('.xlsx')) return 'excel'
  return null
}

export type FileProblem = 'type' | 'size' | 'count'

/** Why a file cannot be attached now, or null. `held` is how many files the message already has. */
export function fileProblem(file: { name: string; type: string; size: number }, held: number): FileProblem | null {
  if (fileKind(file) === null) return 'type'
  if (file.size <= 0 || file.size > MAX_FILE_BYTES) return 'size'
  if (held >= MAX_FILES) return 'count'
  return null
}

/** The image files among clipboard or drop items, with a name when the browser gives none (a pasted screenshot). */
export function withNames(files: File[]): File[] {
  return files.map((file, index) => {
    if (file.name !== '' && file.name !== 'image.png') return file
    const extension = file.type === 'image/jpeg' ? 'jpg' : 'png'
    return new File([file], `screenshot-${Date.now()}-${index + 1}.${extension}`, { type: file.type })
  })
}
