import { useI18n } from '../i18n'
import { usePartyOptions, type PartyKind } from '../lib/usePartyOptions'
import { SearchableSelect } from './ui'

/**
 * A party picker that searches on the server.
 *
 * Extracted as a component so list rows can each own a picker: hooks cannot
 * be called inside a `.map()`, and every row needs its own selected value.
 */
export default function PartyPicker({
  kind,
  value,
  onChange,
  placeholder,
  clearLabel,
}: {
  kind: PartyKind
  value: string
  onChange: (value: string) => void
  placeholder?: string
  clearLabel?: string
}) {
  const { locale } = useI18n()
  const picker = usePartyOptions(kind, { value, locale })

  return (
    <SearchableSelect
      value={value}
      onChange={onChange}
      options={picker.options}
      onSearch={picker.setSearch}
      hasMore={picker.hasMore}
      placeholder={placeholder}
      clearLabel={clearLabel}
    />
  )
}
