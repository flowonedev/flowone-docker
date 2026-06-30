// Icon / colour helpers for Drive items (extracted from MainView).

export function getFileIcon(mimeType: string): string {
  if (!mimeType) return 'draft'
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('video/')) return 'movie'
  if (mimeType.startsWith('audio/')) return 'audio_file'
  if (mimeType.includes('pdf')) return 'picture_as_pdf'
  if (mimeType.includes('sheet') || mimeType.includes('excel') || mimeType.includes('csv')) return 'table_chart'
  if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'slideshow'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'description'
  if (mimeType.includes('zip') || mimeType.includes('compressed') || mimeType.includes('rar')) return 'folder_zip'
  return 'draft'
}

export function getFileIconBg(mimeType: string): string {
  if (!mimeType) return 'background: rgba(156,163,175,0.15)'
  if (mimeType.startsWith('image/')) return 'background: rgba(236,72,153,0.15)'
  if (mimeType.startsWith('video/')) return 'background: rgba(168,85,247,0.15)'
  if (mimeType.includes('pdf')) return 'background: rgba(239,68,68,0.15)'
  if (mimeType.includes('sheet') || mimeType.includes('excel') || mimeType.includes('csv')) return 'background: rgba(34,197,94,0.15)'
  if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'background: rgba(249,115,22,0.15)'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'background: rgba(59,130,246,0.15)'
  if (mimeType.includes('zip') || mimeType.includes('compressed') || mimeType.includes('rar')) return 'background: rgba(245,158,11,0.15)'
  return 'background: rgba(156,163,175,0.15)'
}

export function getFileIconColor(mimeType: string): string {
  if (!mimeType) return 'color: #9ca3af'
  if (mimeType.startsWith('image/')) return 'color: #ec4899'
  if (mimeType.startsWith('video/')) return 'color: #a855f7'
  if (mimeType.includes('pdf')) return 'color: #ef4444'
  if (mimeType.includes('sheet') || mimeType.includes('excel') || mimeType.includes('csv')) return 'color: #22c55e'
  if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'color: #f97316'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'color: #3b82f6'
  if (mimeType.includes('zip') || mimeType.includes('compressed') || mimeType.includes('rar')) return 'color: #f59e0b'
  return 'color: #9ca3af'
}

// Map backend folder colour names/hexes to the app palette (default green)
export function getFolderColor(folder: { color?: string }): string {
  if (!folder.color) return '#22c55e'

  const color = folder.color.toLowerCase()

  if (color.includes('blue') || color === '#0ea5e9' || color === '#06b6d4' || color === '#3b82f6') {
    return '#3b82f6'
  }
  if (color.includes('green') || color === '#16a34a' || color === '#10b981' || color === '#22c55e') {
    return '#22c55e'
  }
  if (color.includes('purple') || color === '#a855f7' || color === '#8b5cf6') {
    return '#a855f7'
  }
  if (color.includes('pink') || color === '#ec4899') {
    return '#ec4899'
  }
  if (color.includes('orange') || color === '#f97316') {
    return '#f97316'
  }
  if (color.includes('yellow') || color === '#eab308') {
    return '#eab308'
  }
  if (color.includes('red') || color === '#ef4444') {
    return '#ef4444'
  }

  if (color.startsWith('#')) return folder.color

  return '#22c55e'
}

export interface SharingStatus {
  label: string
  isPublic: boolean
  hasLink: boolean
}

export function getSharingStatus(item: any): SharingStatus {
  const hasPublicLink = item?.public_token || item?.publicToken || item?.has_public_link || item?.hasPublicLink
  const isPublic = item?.is_public || item?.isPublic || item?.public
  const shareLink = item?.share_link || item?.shareLink

  if (hasPublicLink || shareLink) {
    return { label: 'Public link', isPublic: true, hasLink: true }
  }
  if (isPublic) {
    return { label: 'Public', isPublic: true, hasLink: false }
  }
  return { label: 'Private', isPublic: false, hasLink: false }
}
