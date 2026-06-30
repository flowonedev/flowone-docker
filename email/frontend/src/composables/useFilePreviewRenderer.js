/**
 * useFilePreviewRenderer - Shared file-preview parsing utilities.
 *
 * Single source of truth for the docx/xlsx/csv parse-to-HTML pipeline
 * used by:
 *  - components/AttachmentPreview.vue (email attachment modal)
 *  - views/DriveView.vue (Drive file preview)
 *  - addons/moodboards/components/MoodFilePreview.vue
 *
 * Pure, stateless functions — no Vue refs needed. Callers own state and
 * caching. Heavy parser libs (mammoth, xlsx) are dynamically imported
 * the first time they're used so they don't bloat the initial bundle
 * for users who never open a Word/Excel preview.
 */

let _mammothPromise = null
let _xlsxPromise = null

function loadMammoth() {
  if (!_mammothPromise) _mammothPromise = import('mammoth')
  return _mammothPromise
}

function loadXlsx() {
  if (!_xlsxPromise) _xlsxPromise = import('xlsx')
  return _xlsxPromise
}

// Style map shared with DriveView's preview pane. Mirrors the original
// inline definition there so the migration is behavior-preserving.
const DOCX_STYLE_MAP = [
  "p[style-name='Heading 1'] => h1:fresh",
  "p[style-name='Heading 2'] => h2:fresh",
  "p[style-name='Heading 3'] => h3:fresh",
  "p[style-name='Title'] => h1.doc-title:fresh",
  "p[style-name='Subtitle'] => p.doc-subtitle:fresh",
  "p[style-name='Quote'] => blockquote:fresh",
  "p[style-name='Intense Quote'] => blockquote.intense:fresh",
  "r[style-name='Strong'] => strong",
  "r[style-name='Emphasis'] => em",
  "p[style-name='List Paragraph'] => li:fresh",
]

/**
 * Render a .docx ArrayBuffer to HTML using mammoth, with image
 * conversion (images are inlined as base64 data URIs so no extra HTTP
 * fetches are needed) and the shared style map.
 *
 * @param {ArrayBuffer} arrayBuffer
 * @returns {Promise<{ html: string, messages: Array }>}
 */
export async function renderDocxToHtml(arrayBuffer) {
  const mammothMod = await loadMammoth()
  const mammoth = mammothMod.default || mammothMod
  const result = await mammoth.convertToHtml(
    { arrayBuffer },
    {
      styleMap: DOCX_STYLE_MAP,
      convertImage: mammoth.images.imgElement(function (image) {
        return image.read('base64').then(function (imageBuffer) {
          return { src: 'data:' + image.contentType + ';base64,' + imageBuffer }
        })
      }),
    }
  )
  return { html: result.value, messages: result.messages || [] }
}

/**
 * Read an Excel/CSV ArrayBuffer and return its sheet names. Use to
 * populate the sheet-tab UI before rendering any individual sheet.
 *
 * @param {ArrayBuffer} arrayBuffer
 * @returns {Promise<string[]>}
 */
export async function getExcelSheetNames(arrayBuffer) {
  const XLSX = await loadXlsx()
  const workbook = XLSX.read(arrayBuffer, { type: 'array' })
  return workbook.SheetNames || []
}

/**
 * Render a single sheet from an Excel/CSV ArrayBuffer to an HTML table
 * string via XLSX.utils.sheet_to_html.
 *
 * @param {ArrayBuffer} arrayBuffer
 * @param {number} sheetIndex
 * @returns {Promise<string>} HTML string ('' if the sheet doesn't exist)
 */
export async function renderExcelSheetToHtml(arrayBuffer, sheetIndex = 0) {
  const XLSX = await loadXlsx()
  const workbook = XLSX.read(arrayBuffer, { type: 'array' })
  const name = workbook.SheetNames?.[sheetIndex]
  if (!name) return ''
  const sheet = workbook.Sheets[name]
  return XLSX.utils.sheet_to_html(sheet, {
    editable: false,
    header: '',
    footer: '',
  })
}

/**
 * Classify a file into one of the preview buckets. Single source of
 * truth for the bucketing logic that AttachmentPreview, DriveView and
 * MoodFilePreview previously each implemented separately.
 *
 * Note: `.csv` is bucketed as 'excel' so callers that route on the
 * result get the table layout (xlsx parses CSV natively).
 *
 * @param {string} filename
 * @param {string} [mimeType]
 * @returns {'image'|'pdf'|'docx'|'doc'|'excel'|'ppt'|'video'|'audio'|'text'|'unknown'}
 */
export function classifyFile(filename, mimeType) {
  const name = (filename || '').toLowerCase()
  const mime = (mimeType || '').toLowerCase()

  if (mime.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp|svg|bmp|ico|avif)$/.test(name)) {
    return 'image'
  }
  if (mime === 'application/pdf' || name.endsWith('.pdf')) {
    return 'pdf'
  }
  // Word: .docx renders via mammoth; .doc has no client-side renderer.
  if (
    mime.includes('wordprocessingml') ||
    name.endsWith('.docx')
  ) {
    return 'docx'
  }
  if (mime === 'application/msword' || name.endsWith('.doc')) {
    return 'doc'
  }
  // Excel + CSV + OpenDocument spreadsheets. SheetJS (xlsx) parses binary
  // .xls, CSV, and the OpenDocument .ods family natively, so they all share
  // the table renderer.
  if (
    mime.includes('spreadsheetml') ||
    mime.includes('ms-excel') ||
    mime.includes('opendocument.spreadsheet') ||
    /\.(xlsx|xls|csv|ods|ots|fods)$/.test(name)
  ) {
    return 'excel'
  }
  if (
    mime.includes('presentationml') ||
    mime.includes('ms-powerpoint') ||
    /\.(pptx|ppt)$/.test(name)
  ) {
    return 'ppt'
  }
  if (mime.startsWith('video/') || /\.(mp4|webm|ogg|mov|avi|mkv)$/.test(name)) {
    return 'video'
  }
  if (mime.startsWith('audio/') || /\.(mp3|wav|ogg|m4a|flac|aac)$/.test(name)) {
    return 'audio'
  }
  if (
    mime.startsWith('text/') ||
    mime === 'application/json' ||
    /\.(txt|json|xml|html|css|js|md|log|ini|yml|yaml|toml|cfg)$/.test(name)
  ) {
    return 'text'
  }
  return 'unknown'
}
