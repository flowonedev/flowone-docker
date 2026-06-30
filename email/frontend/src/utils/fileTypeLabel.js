// Windows-Explorer-style verbose file type labels for the Drive views.
// Extension takes priority (matches what users see in a desktop file manager),
// mime type is the fallback.

export const FOLDER_TYPE_LABEL = 'File folder'

const EXT_LABELS = {
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

const MIME_FALLBACKS = [
  ['image/', 'Image'],
  ['video/', 'Video'],
  ['audio/', 'Audio'],
  ['text/', 'Text Document'],
]

/**
 * @param {{ original_name?: string, name?: string, mime_type?: string }} file
 * @returns {string} e.g. "Microsoft Word Document", "PNG Image", "INDD File"
 */
export function fileTypeLabel(file) {
  const mime = file?.mime_type || ''
  // FlowOne's own collab editor formats
  if (mime === 'application/vnd.collab.document') return 'FlowOne Document'
  if (mime === 'application/vnd.collab.presentation') return 'FlowOne Presentation'

  const name = file?.original_name || file?.name || ''
  const dot = name.lastIndexOf('.')
  const ext = dot > 0 ? name.slice(dot + 1).toLowerCase() : ''
  if (ext && EXT_LABELS[ext]) return EXT_LABELS[ext]
  if (ext) return `${ext.toUpperCase()} File`

  for (const [prefix, label] of MIME_FALLBACKS) {
    if (mime.startsWith(prefix)) return label
  }
  return 'File'
}
