import { useState } from 'react'
import { useI18n } from '../i18n'
import type { AttachedFile } from '../lib/assistantFiles'
import { Icon } from './icons'
import { Button, IconButton, Modal } from './ui'

/**
 * The files of a message: images as small previews that open full size on click, Word and Excel as
 * labelled chips. In the composer each one has a remove button; in a sent message it does not.
 */
export function AttachmentStrip({ files, onRemove }: { files: AttachedFile[]; onRemove?: (file: AttachedFile) => void }) {
  const { t } = useI18n()
  const [zoom, setZoom] = useState<AttachedFile | null>(null)

  return (
    <>
      <ul className="flex flex-wrap items-center gap-2" aria-label={t('assistantAttach')}>
        {files.map((file) => (
          <li key={file.id} className="anim-rise relative">
            {file.kind === 'image' && file.previewUrl ? (
              <button
                type="button"
                onClick={() => setZoom(file)}
                aria-label={t('assistantImageZoom').replace('{name}', file.name)}
                className="block overflow-hidden rounded-12 border border-border-default transition hover:border-border-selected focus-visible:outline-2"
              >
                <img src={file.previewUrl} alt={file.name} className="h-16 w-16 object-cover" />
              </button>
            ) : (
              <span className="text-small inline-flex h-16 max-w-48 items-center gap-2 rounded-12 border border-border-default bg-surface-subtle px-3">
                <Icon name={file.kind === 'excel' ? 'ledger' : 'fileText'} size={18} />
                <bdi className="truncate">{file.name}</bdi>
              </span>
            )}
            {onRemove ? (
              <span className="absolute -end-2 -top-2 rounded-full bg-surface-default shadow-1">
                <IconButton icon="x" label={`${t('assistantAttachRemove')}: ${file.name}`} onClick={() => onRemove(file)} />
              </span>
            ) : null}
          </li>
        ))}
      </ul>
      <Modal
        open={zoom !== null}
        title={zoom?.name ?? ''}
        onClose={() => setZoom(null)}
        footer={
          <Button variant="secondary" onClick={() => setZoom(null)}>
            {t('assistantImageClose')}
          </Button>
        }
      >
        {zoom?.previewUrl ? <img src={zoom.previewUrl} alt={zoom.name} className="mx-auto max-h-[70vh] max-w-full rounded-12 object-contain" /> : null}
      </Modal>
    </>
  )
}
