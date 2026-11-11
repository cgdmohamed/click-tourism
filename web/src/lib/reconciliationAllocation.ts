export type AllocationLine = { id: string; remaining_minor: number }

export type AllocationItem = { bank_line_id: string; journal_line_id: string; amount_minor: number }

/**
 * Pair selected bank lines with selected ledger lines by sweeping their
 * remaining amounts, so one-to-many, many-to-one and many-to-many selections
 * consume every line in full (the API refuses partial consumption).
 * Integer minor units only.
 */
export function allocateMatch(bankLines: AllocationLine[], bookLines: AllocationLine[]): AllocationItem[] {
  const items: AllocationItem[] = []
  const banks = bankLines.map((line) => ({ id: line.id, left: line.remaining_minor }))
  const books = bookLines.map((line) => ({ id: line.id, left: line.remaining_minor }))
  let i = 0
  let j = 0
  while (i < banks.length && j < books.length) {
    const amount = Math.min(banks[i].left, books[j].left)
    if (amount > 0) items.push({ bank_line_id: banks[i].id, journal_line_id: books[j].id, amount_minor: amount })
    banks[i].left -= amount
    books[j].left -= amount
    if (banks[i].left <= 0) i += 1
    if (books[j].left <= 0) j += 1
  }
  return items
}
