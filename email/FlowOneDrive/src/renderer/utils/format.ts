// Formatting helpers shared by the Drive desktop views.
// Mirrors the web Drive UI (email/frontend) so both stay consistent.

export const FOLDER_TYPE_LABEL = 'File folder'

// Windows-Explorer-style verbose file type labels (extension first, mime fallback)
const EXT_LABELS: Record<string, string> = {
  // Office
  doc: 'Microsoft Word Document',
  docx: 'Microsoft Word Document',
  xls: 'Microsoft Excel Worksheet',
  xlsx: 'Microsoft Excel Worksheet',
  ppt: 'Microsoft PowerPoint Presentation',
  pptx: 'Microsoft PowerPoint Presentation',
  odt: 'OpenDocument Text',
  ods: 'OpenDocument Spreadsheet',
  odp: 'OpenDocument Presentation',
  // Documents
  pdf: 'PDF Document',
  txt: 'Text Document',
  rtf: 'Rich Text Document',
  md: 'Markdown Document',
  csv: 'CSV File',
  // Archives
  zip: 'ZIP Archive',
  rar: 'RAR File',
  '7z': '7-Zip Archive',
  tar: 'TAR Archive',
  gz: 'GZ Archive',
  // Images
  png: 'PNG Image',
  jpg: 'JPG Image',
  jpeg: 'JPEG Image',
  gif: 'GIF Image',
  svg: 'SVG Image',
  webp: 'WebP Image',
  avif: 'AVIF Image',
  bmp: 'BMP Image',
  ico: 'Icon File',
  psd: 'Photoshop Document',
  ai: 'Illustrator Document',
  indd: 'INDD File',
  fig: 'Figma File',
  // Video
  mp4: 'MP4 Video',
  mov: 'QuickTime Video',
  avi: 'AVI Video',
  mkv: 'MKV Video',
  webm: 'WebM Video',
  // Audio
  mp3: 'MP3 Audio',
  wav: 'WAV Audio',
  flac: 'FLAC Audio',
  m4a: 'M4A Audio',
  ogg: 'OGG Audio',
  // Code / web
  html: 'HTML Document',
  css: 'CSS File',
  js: 'JavaScript File',
  ts: 'TypeScript File',
  json: 'JSON File',
  xml: 'XML File',
  php: 'PHP File',
  sql: 'SQL File',
  sh: 'Shell Script',
}

const MIME_FALLBACKS: Array<[string, string]> = [
  ['image/', 'Image'],
  ['video/', 'Video'],
  ['audio/', 'Audio'],
  ['text/', 'Text Document'],
]

export function fileTypeLabel(filename: string, mimeType?: string): string {
  const dot = filename ? filename.lastIndexOf('.') : -1
  const ext = dot > 0 ? filename.slice(dot + 1).toLowerCase() : ''
  if (ext && EXT_LABELS[ext]) return EXT_LABELS[ext]
  if (ext) return `${ext.toUpperCase()} File`

  const mime = mimeType || ''
  for (const [prefix, label] of MIME_FALLBACKS) {
    if (mime.startsWith(prefix)) return label
  }
  return 'File'
}

// Sizes with 1 decimal, matching the web list view ("29.3 MB", "2.5 GB", "205 B")
export function formatSize(bytes: number): string {
  if (!bytes) return '—'
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB'
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB'
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return bytes + ' B'
}

// Compact relative dates: "2m ago", "8h ago", "2d ago", "Jun 3, 2026"
export function formatRelativeDate(dateStr: string): string {
  if (!dateStr) return '—'
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now.getTime() - date.getTime()
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))

  if (days === 0) {
    const hours = Math.floor(diff / (1000 * 60 * 60))
    if (hours === 0) {
      const minutes = Math.floor(diff / (1000 * 60))
      if (minutes < 1) return 'Just now'
      return `${minutes}m ago`
    }
    return `${hours}h ago`
  }
  if (days < 7) return `${days}d ago`
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

export function formatDuration(seconds: number): string {
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  if (mins === 0) return `${secs}s`
  return `${mins}m ${secs}s`
}

export function formatTrackingDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return `${h}h ${m}m`
}
