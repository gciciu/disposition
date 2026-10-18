/**
 * Strip trailing country name/code from an address line (e.g. ", Deutschland").
 */
export function withoutCountry(address: string | null | undefined): string {
  if (address == null) {
    return ''
  }
  const trimmed = String(address).trim()
  if (!trimmed) {
    return ''
  }
  const without = trimmed
    .replace(
      /,?\s*(Deutschland|Germany|Österreich|Austria|Schweiz|Switzerland|Liechtenstein|Belgien|Belgium|Niederlande|Netherlands|Luxemburg|Luxembourg|Frankreich|France|Polen|Poland|Tschechien|Czechia|Czech Republic|DE|AT|CH|LI|BE|NL|LU|FR|PL|CZ)\s*$/iu,
      '',
    )
    .replace(/[,\s]+$/u, '')
    .trim()
  return without || trimmed
}
