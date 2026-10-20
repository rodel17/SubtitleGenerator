/** Hand a fetched file to the browser's normal "save file" behaviour. */
export function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.append(link)
  link.click()
  link.remove()
  // Some browsers start reading the URL after click() returns
  setTimeout(() => URL.revokeObjectURL(url), 0)
}
