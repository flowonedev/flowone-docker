/**
 * docxDiffUtils - Pure helpers for the Drive docx version-compare panel.
 *
 * Extracted from components/drive/compare/DocxCompare.vue so the component
 * stays orchestration + template only (modularity rules). These functions
 * are stateless: callers own all Vue refs/caching.
 *
 * Pipeline:
 *  1. convertDocxToHtml() turns a {content: 'data:...;base64,...'} payload
 *     into rendered HTML (mammoth), falling back to raw-text extraction when
 *     mammoth produces nothing so a version never renders silently blank.
 *  2. extractParagraphs() splits HTML into per-paragraph {text, html} so the
 *     diff can detect inline formatting changes (strikethrough, bold, ...),
 *     not just plain-text changes.
 *  3. computeDocxDiff() aligns the two paragraph lists and classifies each
 *     row (unchanged / changed / formatting-only change / added / removed).
 */

import mammoth from 'mammoth'
import * as Diff from 'diff'

const DOCX_STYLE_MAP = [
  "p[style-name='Heading 1'] => h1:fresh",
  "p[style-name='Heading 2'] => h2:fresh",
  "p[style-name='Heading 3'] => h3:fresh",
  "b => strong",
  "i => em",
  "u => u",
  "strike => s",
]

const BLOCK_TAGS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'div']

/** Decode a `data:...;base64,...` (or bare base64) string to an ArrayBuffer. */
export function base64ToArrayBuffer(base64) {
  const base64Data = base64.replace(/^data:[^;]+;base64,/, '')
  const binaryString = atob(base64Data)
  const bytes = new Uint8Array(binaryString.length)
  for (let i = 0; i < binaryString.length; i++) {
    bytes[i] = binaryString.charCodeAt(i)
  }
  return bytes.buffer
}

function escapeHtml(str) {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

/**
 * Convert a version preview payload to HTML.
 *
 * @param {{content?: string}} content - the {type, content} object returned
 *   by the versions/{id}/preview endpoint.
 * @returns {Promise<{html: string, messages: Array, empty: boolean}>}
 * @throws when no content is provided or the bytes cannot be decoded/parsed.
 */
export async function convertDocxToHtml(content) {
  if (!content?.content) {
    throw new Error('No content provided')
  }

  let arrayBuffer
  try {
    arrayBuffer = base64ToArrayBuffer(content.content)
  } catch {
    throw new Error('Failed to decode document')
  }

  let messages = []
  try {
    const result = await mammoth.convertToHtml({
      arrayBuffer,
      styleMap: DOCX_STYLE_MAP,
      includeDefaultStyleMap: true,
      convertImage: mammoth.images.imgElement(function (image) {
        return image.read('base64').then(function (imageBuffer) {
          return { src: 'data:' + image.contentType + ';base64,' + imageBuffer }
        })
      }),
    })
    messages = result.messages || []
    if (messages.length) {
      console.debug('[docxDiff] mammoth messages:', messages)
    }

    const html = (result.value || '').trim()
    if (html) {
      return { html, messages, empty: false }
    }
  } catch (e) {
    console.error('[docxDiff] mammoth convertToHtml failed, trying raw text:', e)
  }

  // Fallback: mammoth produced no HTML (or threw). Pull whatever raw text we
  // can so the version still renders and the diff still has something to work
  // with, instead of leaving a blank panel with no explanation.
  try {
    const raw = await mammoth.extractRawText({ arrayBuffer })
    const text = (raw.value || '').trim()
    if (!text) {
      return { html: '', messages, empty: true }
    }
    const html = text
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean)
      .map((line) => `<p>${escapeHtml(line)}</p>`)
      .join('')
    return { html, messages, empty: false }
  } catch (e) {
    throw new Error('Failed to convert document')
  }
}

/** Collapse insignificant whitespace so trivial differences don't read as changes. */
function normalizeHtml(html) {
  return (html || '').replace(/\s+/g, ' ').trim().toLowerCase()
}

/**
 * Split rendered HTML into per-paragraph entries that preserve inline markup.
 *
 * @param {string} html
 * @returns {Array<{text: string, html: string}>}
 */
export function extractParagraphs(html) {
  const parser = new DOMParser()
  const doc = parser.parseFromString(`<div>${html}</div>`, 'text/html')
  const container = doc.body.firstChild

  const paragraphs = []

  function inlineText(node) {
    if (node.nodeType === Node.TEXT_NODE) return node.textContent
    if (node.nodeType === Node.ELEMENT_NODE) {
      return Array.from(node.childNodes).map(inlineText).join('')
    }
    return ''
  }

  function walk(node) {
    if (node.nodeType === Node.TEXT_NODE) return
    if (node.nodeType !== Node.ELEMENT_NODE) return

    const tag = node.tagName.toLowerCase()
    if (BLOCK_TAGS.includes(tag)) {
      // A block element that holds direct inline content becomes one paragraph.
      // Blocks that only wrap other blocks (e.g. a layout <div>) are skipped
      // here and their block children are captured on their own.
      const directText = Array.from(node.childNodes)
        .filter((child) => {
          if (child.nodeType === Node.TEXT_NODE) return true
          if (child.nodeType !== Node.ELEMENT_NODE) return false
          return !BLOCK_TAGS.includes(child.tagName.toLowerCase())
        })
        .map(inlineText)
        .join('')

      if (directText.trim()) {
        paragraphs.push({ text: inlineText(node), html: node.innerHTML.trim() })
        return
      }
    }

    Array.from(node.childNodes).forEach(walk)
  }

  walk(container)

  if (paragraphs.length === 0) {
    const text = container.textContent || ''
    if (text.trim()) paragraphs.push({ text, html: escapeHtml(text) })
  }

  return paragraphs
}

/** Crude positional character similarity in [0,1]; 1 means identical. */
export function similarity(s1, s2) {
  if (!s1 || !s2) return 0
  if (s1 === s2) return 1

  const longer = s1.length > s2.length ? s1 : s2
  const shorter = s1.length > s2.length ? s2 : s1
  if (longer.length === 0) return 1

  let matches = 0
  const shorterChars = shorter.toLowerCase().split('')
  const longerChars = longer.toLowerCase().split('')
  shorterChars.forEach((char, i) => {
    if (longerChars[i] === char) matches++
  })
  return matches / longer.length
}

/** Word-level diff parts between two strings (see jsdiff). */
export function getWordDiff(oldText, newText) {
  return Diff.diffWords(oldText, newText)
}

function row(type, left, right, extra = {}) {
  return {
    type,
    leftText: left ? left.text : null,
    rightText: right ? right.text : null,
    leftHtml: left ? left.html : null,
    rightHtml: right ? right.html : null,
    wordDiff: null,
    formattingChanged: false,
    ...extra,
  }
}

/**
 * Align two HTML documents paragraph-by-paragraph and classify each row.
 *
 * Unlike the previous text-only diff, this:
 *  - never collapses to an empty result when one side is empty (the other
 *    side's paragraphs surface as added/removed), and
 *  - flags paragraphs whose text is identical but whose inline formatting
 *    differs as `formattingChanged` so changes like strikethrough show up.
 *
 * @returns {{rows: Array, stats: {changed: number, added: number, removed: number}}}
 */
export function computeDocxDiff(leftHtml, rightHtml) {
  const leftParas = extractParagraphs(leftHtml || '')
  const rightParas = extractParagraphs(rightHtml || '')

  const rows = []
  let changed = 0
  let added = 0
  let removed = 0

  let i = 0
  let j = 0

  while (i < leftParas.length || j < rightParas.length) {
    if (i >= leftParas.length) {
      added++
      rows.push(row('added', null, rightParas[j]))
      j++
    } else if (j >= rightParas.length) {
      removed++
      rows.push(row('removed', leftParas[i], null))
      i++
    } else {
      const L = leftParas[i]
      const R = rightParas[j]
      const sim = similarity(L.text, R.text)

      if (sim > 0.5) {
        if (L.text === R.text) {
          if (normalizeHtml(L.html) === normalizeHtml(R.html)) {
            rows.push(row('unchanged', L, R))
          } else {
            changed++
            rows.push(row('changed', L, R, { formattingChanged: true }))
          }
        } else {
          changed++
          rows.push(row('changed', L, R, { wordDiff: getWordDiff(L.text, R.text) }))
        }
        i++
        j++
      } else {
        const nextLeftSim =
          i + 1 < leftParas.length ? similarity(leftParas[i + 1].text, R.text) : 0
        const nextRightSim =
          j + 1 < rightParas.length ? similarity(L.text, rightParas[j + 1].text) : 0

        if (nextLeftSim > sim && nextLeftSim > nextRightSim) {
          removed++
          rows.push(row('removed', L, null))
          i++
        } else if (nextRightSim > sim) {
          added++
          rows.push(row('added', null, R))
          j++
        } else {
          changed++
          rows.push(row('changed', L, R, { wordDiff: getWordDiff(L.text, R.text) }))
          i++
          j++
        }
      }
    }
  }

  return { rows, stats: { changed, added, removed } }
}
