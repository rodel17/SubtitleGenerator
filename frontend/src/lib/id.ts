/**
 * Random id for rows that only exist in the browser.
 *
 * crypto.randomUUID() is only defined in secure contexts (HTTPS or localhost), so
 * on a plain-HTTP deployment fall back to getRandomValues, which is available everywhere.
 */
export function localId(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID()

  const bytes = crypto.getRandomValues(new Uint8Array(16))
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}
