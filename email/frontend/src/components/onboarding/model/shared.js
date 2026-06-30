export function boldKeywords(text) {
  if (!text) return ''
  const escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  return escaped.replace(
    /\b(What|Why|Where|Mi|Miért|Miert|Hol):/g,
    '<span class="font-semibold text-surface-700 dark:text-surface-200">$1:</span>'
  )
}

export function computeConnPath(fromPos, toPos, cW, cH) {
  const a = { x: fromPos.x * cW, y: fromPos.y * cH }
  const b = { x: toPos.x * cW, y: toPos.y * cH }
  const dx = b.x - a.x
  const dy = b.y - a.y
  const t = 0.4
  const cp1x = a.x + dx * t
  const cp1y = a.y + dy * t
  const cp2x = b.x - dx * t
  const cp2y = b.y - dy * t
  return `M ${a.x} ${a.y} C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${b.x} ${b.y}`
}

export function computePathLength(fromPos, toPos, cW, cH) {
  const ax = fromPos.x * cW, ay = fromPos.y * cH
  const bx = toPos.x * cW, by = toPos.y * cH
  return Math.sqrt((bx - ax) ** 2 + (by - ay) ** 2) * 1.3
}
