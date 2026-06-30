// MariaDB DECIMAL columns arrive as strings via PDO/JSON, so values like
// cpu_load_1m can be "0.15" instead of 0.15. Coerce before formatting.
export function formatLoad(value, decimals = 2) {
  if (value === null || value === undefined || value === '') return '-'
  const num = Number(value)
  return Number.isFinite(num) ? num.toFixed(decimals) : '-'
}
