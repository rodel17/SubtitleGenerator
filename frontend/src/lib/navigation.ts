/**
 * Load a page from scratch. Used when signing out or when the session ends,
 * so every store is cleared and polling or in-flight uploads stop.
 */
export function reloadTo(path: string): void {
  window.location.assign(path)
}
