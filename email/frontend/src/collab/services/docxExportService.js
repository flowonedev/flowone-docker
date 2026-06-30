/**
 * DOCX Export Service
 * 
 * Converts TipTap/HTML content to proper DOCX format with full formatting support.
 * Uses the 'docx' library for reliable Word document generation.
 * Supports exporting comments/annotations.
 */

import {
  Document,
  Packer,
  Paragraph,
  TextRun,
  HeadingLevel,
  AlignmentType,
  Table as DocxTable,
  TableRow as DocxTableRow,
  TableCell as DocxTableCell,
  WidthType,
  BorderStyle,
  ExternalHyperlink,
  ImageRun,
  convertInchesToTwip,
  LevelFormat,
  NumberFormat,
  CommentRangeStart,
  CommentRangeEnd,
  CommentReference,
  PageBreak,
} from 'docx'

/**
 * Parse HTML content and convert to DOCX with optional comments
 * 
 * @param {string} html - HTML content to convert
 * @param {string} title - Document title
 * @param {Array} commentThreads - Array of comment threads from Y.js
 * @param {Object} options - Export options
 * @param {boolean} options.insertSoftBreaks - Whether to insert calculated soft page breaks (default: false)
 * @param {Object} options.layoutResult - LayoutCalculator result for soft breaks
 */
export async function exportToDocx(html, title = 'Document', commentThreads = [], options = {}) {
  const { insertSoftBreaks = false, layoutResult = null } = options
  
  // Parse HTML
  const parser = new DOMParser()
  const doc = parser.parseFromString(html, 'text/html')
  
  // Get plain text for comment matching
  const plainText = doc.body.textContent || ''
  
  // Build comment data for DOCX
  const { commentChildren, commentMap } = buildCommentsForDocx(commentThreads, plainText)
  
  // Set comment map for inline content parsing
  setCommentMap(commentMap)
  
  // Convert HTML nodes to DOCX elements (with comment markers)
  let children = await parseNodes(doc.body.childNodes, commentMap, plainText)
  
  // Optionally insert soft page breaks at calculated positions
  if (insertSoftBreaks && layoutResult && layoutResult.pageBreaks) {
    children = insertSoftPageBreaks(children, layoutResult.pageBreaks)
  }
  
  // Build document config
  const docConfig = {
    title: title,
    creator: 'FlowOne Editor',
    description: 'Document exported from FlowOne',
    styles: {
      default: {
        document: {
          run: {
            font: 'Outfit',
            size: 27, // 13.5pt (matches editor 18px)
          },
          paragraph: {
            spacing: {
              after: 160, // ~10px paragraph spacing
              line: 276, // ~1.15x line spacing - closer match to browser rendering
              lineRule: 'auto',
            },
          },
        },
        heading1: {
          run: {
            size: 48, // 24pt (matches editor 32px)
            bold: true,
            font: 'Outfit',
          },
          paragraph: {
            spacing: { before: 400, after: 240 },
          },
        },
        heading2: {
          run: {
            size: 39, // 19.5pt (matches editor 26px)
            bold: true,
            font: 'Outfit',
          },
          paragraph: {
            spacing: { before: 300, after: 200 },
          },
        },
        heading3: {
          run: {
            size: 32, // 16pt (matches editor 21px)
            bold: true,
            font: 'Outfit',
          },
          paragraph: {
            spacing: { before: 240, after: 160 },
          },
        },
      },
    },
    numbering: {
      config: [
        {
          reference: 'bullet-list',
          levels: [
            { level: 0, format: LevelFormat.BULLET, text: '\u2022', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 720, hanging: 360 } } } },
            { level: 1, format: LevelFormat.BULLET, text: '\u25E6', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 1440, hanging: 360 } } } },
            { level: 2, format: LevelFormat.BULLET, text: '\u25AA', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 2160, hanging: 360 } } } },
          ],
        },
        {
          reference: 'number-list',
          levels: [
            { level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 720, hanging: 360 } } } },
            { level: 1, format: LevelFormat.LOWER_LETTER, text: '%2)', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 1440, hanging: 360 } } } },
            { level: 2, format: LevelFormat.LOWER_ROMAN, text: '%3.', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 2160, hanging: 360 } } } },
          ],
        },
      ],
    },
    sections: [{
      properties: {
        page: {
          // A4 size
          size: {
            width: convertInchesToTwip(8.27),  // 210mm = ~8.27 inches
            height: convertInchesToTwip(11.69), // 297mm = ~11.69 inches
          },
          // Word-standard 1-inch margins on all sides (matches editor)
          margin: {
            top: convertInchesToTwip(1),       // 1 inch (25.4mm)
            right: convertInchesToTwip(1),     // 1 inch (25.4mm)
            bottom: convertInchesToTwip(1),    // 1 inch (25.4mm)
            left: convertInchesToTwip(1),      // 1 inch (25.4mm)
          },
        },
      },
      children: children,
    }],
  }
  
  // Add comments section if there are comments
  if (commentChildren.length > 0) {
    docConfig.comments = {
      children: commentChildren,
    }
  }
  
  // Create document
  const document = new Document(docConfig)

  // Generate and download
  const blob = await Packer.toBlob(document)
  downloadBlob(blob, `${title}.docx`)
}

/**
 * Build DOCX comment objects from comment threads
 * 
 * @param {Array} commentThreads - Comment threads from Y.js
 * @param {string} plainText - Document plain text for matching
 * @returns {Object} { commentChildren: Comment[], commentMap: Map }
 */
function buildCommentsForDocx(commentThreads, plainText) {
  const commentChildren = []
  const commentMap = new Map() // Maps text ranges to comment IDs
  
  if (!commentThreads || commentThreads.length === 0) {
    return { commentChildren, commentMap }
  }
  
  commentThreads.forEach((thread, index) => {
    if (!thread.comments || thread.comments.length === 0) return
    
    const commentId = index
    const mainComment = thread.comments[0]
    const authorName = mainComment?.author?.name || mainComment?.author?.email?.split('@')[0] || 'Unknown'
    
    // Build comment text with all replies
    const commentParagraphs = []
    
    thread.comments.forEach((comment, replyIndex) => {
      const replyAuthor = comment.author?.name || comment.author?.email?.split('@')[0] || 'Unknown'
      const timestamp = comment.createdAt ? new Date(comment.createdAt).toLocaleString() : ''
      
      // Add author header for replies
      if (replyIndex > 0) {
        commentParagraphs.push(
          new Paragraph({
            children: [
              new TextRun({
                text: `Reply from ${replyAuthor}`,
                bold: true,
                size: 18,
              }),
              new TextRun({
                text: timestamp ? ` (${timestamp})` : '',
                size: 16,
                italics: true,
              }),
            ],
            spacing: { before: 120, after: 60 },
          })
        )
      }
      
      // Add comment content
      commentParagraphs.push(
        new Paragraph({
          children: [
            new TextRun({
              text: comment.content || '',
              size: 20,
            }),
          ],
          spacing: { after: 60 },
        })
      )
    })
    
    // Add resolved status if applicable
    if (thread.resolved) {
      const resolvedBy = thread.resolvedBy?.name || thread.resolvedBy?.email?.split('@')[0] || 'Unknown'
      commentParagraphs.push(
        new Paragraph({
          children: [
            new TextRun({
              text: `[Resolved by ${resolvedBy}]`,
              italics: true,
              color: '666666',
              size: 18,
            }),
          ],
          spacing: { before: 60 },
        })
      )
    }
    
    // Create the comment object for docx
    // Note: docx library uses a specific comment format
    commentChildren.push({
      id: commentId,
      author: authorName,
      date: mainComment?.createdAt ? new Date(mainComment.createdAt) : new Date(),
      children: commentParagraphs,
    })
    
    // Map quoted text to comment ID for later matching
    if (thread.quotedText) {
      const textPosition = plainText.indexOf(thread.quotedText)
      if (textPosition !== -1) {
        commentMap.set(thread.quotedText, {
          id: commentId,
          start: textPosition,
          end: textPosition + thread.quotedText.length,
          text: thread.quotedText,
        })
      }
    }
  })
  
  return { commentChildren, commentMap }
}

/**
 * Parse child nodes recursively
 */
async function parseNodes(nodes, commentMap = new Map(), plainText = '') {
  const elements = []
  let currentListType = null
  let listItems = []
  
  for (const node of nodes) {
    if (node.nodeType === Node.TEXT_NODE) {
      const text = node.textContent.trim()
      if (text) {
        elements.push(new Paragraph({ children: [new TextRun(text)] }))
      }
      continue
    }
    
    if (node.nodeType !== Node.ELEMENT_NODE) continue
    
    const tagName = node.tagName.toLowerCase()
    
    // Handle list items - collect them
    if (tagName === 'ul' || tagName === 'ol') {
      const listType = tagName === 'ul' ? 'bullet-list' : 'number-list'
      const items = await parseListItems(node, listType, 0)
      elements.push(...items)
      continue
    }
    
    const element = await parseElement(node)
    if (element) {
      if (Array.isArray(element)) {
        elements.push(...element)
      } else {
        elements.push(element)
      }
    }
  }
  
  return elements
}

/**
 * Parse list items recursively
 */
async function parseListItems(listNode, listType, level) {
  const items = []
  
  for (const child of listNode.children) {
    if (child.tagName.toLowerCase() === 'li') {
      // Get text content and inline elements
      const runs = []
      for (const liChild of child.childNodes) {
        if (liChild.nodeType === Node.TEXT_NODE) {
          const text = liChild.textContent
          if (text.trim()) {
            runs.push(new TextRun(text))
          }
        } else if (liChild.nodeType === Node.ELEMENT_NODE) {
          const tag = liChild.tagName.toLowerCase()
          if (tag === 'ul' || tag === 'ol') {
            // Nested list - add current item first, then process nested
            if (runs.length > 0) {
              items.push(new Paragraph({
                children: runs.splice(0, runs.length),
                numbering: { reference: listType, level: level },
              }))
            }
            const nestedType = tag === 'ul' ? 'bullet-list' : 'number-list'
            const nestedItems = await parseListItems(liChild, nestedType, level + 1)
            items.push(...nestedItems)
          } else {
            const inlineRuns = parseInlineElement(liChild)
            runs.push(...inlineRuns)
          }
        }
      }
      
      if (runs.length > 0) {
        items.push(new Paragraph({
          children: runs,
          numbering: { reference: listType, level: level },
        }))
      }
    }
  }
  
  return items
}

/**
 * Check if a node or its children have custom colors
 */
function hasCustomColor(node) {
  // Check node's own style
  const style = node.style || {}
  const styleAttr = node.getAttribute?.('style') || ''
  if (style.color || getStyleValue(styleAttr, 'color') || node.getAttribute?.('data-color')) {
    return true
  }
  // Check children
  for (const child of node.childNodes || []) {
    if (child.nodeType === Node.ELEMENT_NODE && hasCustomColor(child)) {
      return true
    }
  }
  return false
}

/**
 * Check if a node is a hard page break
 * Handles multiple formats:
 * - <div class="hard-page-break">
 * - <div data-page-break="true">
 * - <hr class="hard-page-break">
 */
function isHardPageBreak(node) {
  if (!node || !node.tagName) {
    return false
  }
  
  // Check for hard-page-break class
  if (node.classList?.contains('hard-page-break')) {
    return true
  }
  
  // Check for data-page-break attribute
  if (node.getAttribute?.('data-page-break') === 'true') {
    return true
  }
  
  return false
}

/**
 * Create a DOCX page break paragraph
 */
function createDocxPageBreak() {
  return new Paragraph({
    children: [new PageBreak()],
    spacing: { before: 0, after: 0 },
  })
}

/**
 * Insert soft page breaks into DOCX children array based on layout calculation
 * 
 * Note: This is an approximation. Word's layout engine may paginate differently
 * than the web editor. For most accurate results, rely on hard page breaks
 * that the user explicitly inserts.
 * 
 * @param {Array} children - Array of DOCX elements (Paragraphs, Tables, etc.)
 * @param {Array} pageBreaks - Page breaks from LayoutCalculator
 * @returns {Array} Modified children array with page breaks inserted
 */
function insertSoftPageBreaks(children, pageBreaks) {
  if (!pageBreaks || pageBreaks.length === 0) {
    return children
  }
  
  // Filter to only soft breaks (hard breaks are already in the content)
  const softBreaks = pageBreaks.filter(pb => pb.type === 'soft')
  
  if (softBreaks.length === 0) {
    return children
  }
  
  // We can't map document positions to DOCX children perfectly,
  // so we use a heuristic: estimate positions based on child index
  // and insert breaks at approximately the right locations
  
  // Calculate approximate positions per child
  const avgPositionPerChild = 100 // Rough estimate: 100 characters per block element
  const result = []
  let nextBreakIndex = 0
  let cumulativePosition = 0
  
  for (let i = 0; i < children.length; i++) {
    const child = children[i]
    
    // Skip null/undefined children
    if (!child) continue
    
    // Check if we need to insert a page break before this child
    while (
      nextBreakIndex < softBreaks.length &&
      softBreaks[nextBreakIndex].pos <= cumulativePosition
    ) {
      result.push(createDocxPageBreak())
      nextBreakIndex++
    }
    
    // Add the child
    if (Array.isArray(child)) {
      result.push(...child)
    } else {
      result.push(child)
    }
    
    cumulativePosition += avgPositionPerChild
  }
  
  return result
}

/**
 * Parse inline content with additional formatting options (for headings with custom colors)
 */
function parseInlineContentWithFormat(node, extraFormat = {}) {
  const runs = []
  
  for (const child of node.childNodes) {
    if (child.nodeType === Node.TEXT_NODE) {
      const text = child.textContent
      if (text) {
        runs.push(new TextRun({
          text,
          ...extraFormat,
        }))
      }
    } else if (child.nodeType === Node.ELEMENT_NODE) {
      const inlineRuns = parseInlineElementWithFormat(child, extraFormat)
      runs.push(...inlineRuns)
    }
  }
  
  return runs
}

/**
 * Parse inline element with extra formatting applied
 */
function parseInlineElementWithFormat(node, extraFormat = {}) {
  const tagName = node.tagName.toLowerCase()
  const runs = []
  
  const style = node.style || {}
  const styleAttr = node.getAttribute('style') || ''
  
  const textColor = style.color || getStyleValue(styleAttr, 'color') || node.getAttribute('data-color')
  const bgColor = style.backgroundColor || getStyleValue(styleAttr, 'background-color') || node.getAttribute('data-highlight')
  
  const formatting = {
    ...extraFormat,
    bold: extraFormat.bold || tagName === 'strong' || tagName === 'b',
    italics: extraFormat.italics || tagName === 'em' || tagName === 'i',
    underline: tagName === 'u' ? {} : extraFormat.underline,
    strike: tagName === 's' || tagName === 'del' || tagName === 'strike',
  }
  
  if (textColor) {
    const hexColor = colorToHex(textColor)
    if (hexColor) {
      formatting.color = hexColor
    }
  }
  
  if (bgColor && bgColor !== 'transparent') {
    formatting.highlight = 'yellow'
    const hexBg = colorToHex(bgColor)
    if (hexBg) {
      formatting.shading = { fill: hexBg }
    }
  }
  
  for (const child of node.childNodes) {
    if (child.nodeType === Node.TEXT_NODE) {
      const text = child.textContent
      if (text) {
        runs.push(new TextRun({
          text,
          ...formatting,
        }))
      }
    } else if (child.nodeType === Node.ELEMENT_NODE) {
      const nestedRuns = parseInlineElementWithFormat(child, formatting)
      runs.push(...nestedRuns)
    }
  }
  
  return runs
}

/**
 * Parse a single HTML element to DOCX
 */
async function parseElement(node) {
  const tagName = node.tagName.toLowerCase()
  
  switch (tagName) {
    case 'h1': {
      // If heading has custom colors, don't use built-in heading style (it would override colors)
      // Instead, manually apply heading formatting to preserve colors
      const hasColor = hasCustomColor(node)
      if (hasColor) {
        return new Paragraph({
          children: parseInlineContentWithFormat(node, { size: 42, bold: true, font: 'Outfit' }),
          alignment: getAlignment(node),
          spacing: { before: 400, after: 240 },
        })
      }
      return new Paragraph({
        children: parseInlineContent(node),
        heading: HeadingLevel.HEADING_1,
        alignment: getAlignment(node),
      })
    }
    
    case 'h2': {
      const hasColor = hasCustomColor(node)
      if (hasColor) {
        return new Paragraph({
          children: parseInlineContentWithFormat(node, { size: 33, bold: true, font: 'Outfit' }),
          alignment: getAlignment(node),
          spacing: { before: 300, after: 200 },
        })
      }
      return new Paragraph({
        children: parseInlineContent(node),
        heading: HeadingLevel.HEADING_2,
        alignment: getAlignment(node),
      })
    }
    
    case 'h3': {
      const hasColor = hasCustomColor(node)
      if (hasColor) {
        return new Paragraph({
          children: parseInlineContentWithFormat(node, { size: 27, bold: true, font: 'Outfit' }),
          alignment: getAlignment(node),
          spacing: { before: 240, after: 160 },
        })
      }
      return new Paragraph({
        children: parseInlineContent(node),
        heading: HeadingLevel.HEADING_3,
        alignment: getAlignment(node),
      })
    }
    
    case 'p':
      return new Paragraph({
        children: parseInlineContent(node),
        alignment: getAlignment(node),
      })
    
    case 'blockquote':
      return new Paragraph({
        children: parseInlineContent(node),
        indent: { left: 720 },
        border: {
          left: { style: BorderStyle.SINGLE, size: 24, color: 'CCCCCC' },
        },
        shading: { fill: 'F5F5F5' },
      })
    
    case 'pre':
    case 'code':
      return new Paragraph({
        children: [new TextRun({
          text: node.textContent,
          font: 'Consolas',
          size: 20,
        })],
        shading: { fill: 'F4F4F5' },
        spacing: { before: 200, after: 200 },
      })
    
    case 'hr':
      return new Paragraph({
        children: [],
        border: {
          bottom: { style: BorderStyle.SINGLE, size: 6, color: 'CCCCCC' },
        },
        spacing: { before: 200, after: 200 },
      })
    
    case 'table':
      return parseTable(node)
    
    case 'img':
      return await parseImage(node)
    
    case 'br':
      return new Paragraph({ children: [] })
    
    case 'div':
    case 'span':
      // Check for hard page break
      if (isHardPageBreak(node)) {
        return createDocxPageBreak()
      }
      // Recurse into divs and spans
      const children = await parseNodes(node.childNodes)
      return children
    
    case 'hr':
      // Check if this is a hard page break
      if (node.classList?.contains('hard-page-break')) {
        return createDocxPageBreak()
      }
      // Regular horizontal rule - just a thin line paragraph
      return new Paragraph({
        border: {
          bottom: {
            style: BorderStyle.SINGLE,
            size: 6,
            color: 'CCCCCC',
          },
        },
        spacing: { before: 200, after: 200 },
      })
    
    default:
      // Try to parse as paragraph with inline content
      if (node.childNodes.length > 0) {
        return new Paragraph({
          children: parseInlineContent(node),
          alignment: getAlignment(node),
        })
      }
      return null
  }
}

/**
 * Parse inline content (text with formatting)
 */
// Global comment map for current export (set during export)
let currentCommentMap = new Map()

/**
 * Set the comment map for the current export
 */
function setCommentMap(commentMap) {
  currentCommentMap = commentMap || new Map()
}

/**
 * Parse inline content with comment support
 */
function parseInlineContent(node) {
  const runs = []
  const nodeText = node.textContent || ''
  
  // Check if this node's text contains any commented text
  let hasComment = false
  let commentInfo = null
  
  for (const [quotedText, info] of currentCommentMap.entries()) {
    if (nodeText.includes(quotedText)) {
      hasComment = true
      commentInfo = info
      break
    }
  }
  
  // If we have a comment that matches this node, wrap with comment markers
  if (hasComment && commentInfo) {
    const quotedText = commentInfo.text
    const beforeText = nodeText.substring(0, nodeText.indexOf(quotedText))
    const afterText = nodeText.substring(nodeText.indexOf(quotedText) + quotedText.length)
    
    // Add text before the comment
    if (beforeText) {
      runs.push(...parseChildNodes(node, beforeText))
    }
    
    // Add comment range start
    runs.push(new CommentRangeStart(commentInfo.id))
    
    // Add the commented text
    for (const child of node.childNodes) {
      if (child.nodeType === Node.TEXT_NODE) {
        const childText = child.textContent
        if (childText && childText.includes(quotedText)) {
          // This text node contains the commented text
          const idx = childText.indexOf(quotedText)
          if (idx > 0) {
            runs.push(new TextRun(childText.substring(0, idx)))
          }
          runs.push(new TextRun({
            text: quotedText,
            highlight: 'yellow', // Highlight commented text
          }))
          if (idx + quotedText.length < childText.length) {
            runs.push(new TextRun(childText.substring(idx + quotedText.length)))
          }
        } else if (childText) {
          runs.push(new TextRun(childText))
        }
      } else if (child.nodeType === Node.ELEMENT_NODE) {
        const inlineRuns = parseInlineElement(child)
        runs.push(...inlineRuns)
      }
    }
    
    // Add comment range end and reference
    runs.push(new CommentRangeEnd(commentInfo.id))
    runs.push(new CommentReference(commentInfo.id))
    
    // Mark this comment as processed so we don't add it again
    currentCommentMap.delete(commentInfo.text)
    
    // Add text after the comment
    if (afterText) {
      // The after text is already included in the node parsing above
    }
    
    return runs
  }
  
  // Standard parsing without comments
  for (const child of node.childNodes) {
    if (child.nodeType === Node.TEXT_NODE) {
      const text = child.textContent
      if (text) {
        runs.push(new TextRun(text))
      }
    } else if (child.nodeType === Node.ELEMENT_NODE) {
      const inlineRuns = parseInlineElement(child)
      runs.push(...inlineRuns)
    }
  }
  
  return runs
}

/**
 * Helper to parse child nodes for a specific text segment
 */
function parseChildNodes(node, targetText) {
  const runs = []
  for (const child of node.childNodes) {
    if (child.nodeType === Node.TEXT_NODE) {
      const text = child.textContent
      if (text && text.includes(targetText)) {
        runs.push(new TextRun(targetText))
        break
      }
    }
  }
  return runs
}

/**
 * Convert any color format (rgb, rgba, hex, named) to 6-digit hex
 */
function colorToHex(color) {
  if (!color || color === 'transparent' || color === 'inherit' || color === 'currentColor') return null
  
  // Trim whitespace
  color = color.trim()
  
  // Already hex format
  if (color.startsWith('#')) {
    const hex = color.slice(1).toLowerCase()
    // Handle shorthand hex (#fff -> ffffff)
    if (hex.length === 3) {
      return hex.split('').map(c => c + c).join('')
    }
    // Handle 8-char hex with alpha (#rrggbbaa -> rrggbb)
    if (hex.length === 8) {
      return hex.substring(0, 6)
    }
    return hex.substring(0, 6)
  }
  
  // RGB/RGBA format with commas: rgb(255, 0, 0) or rgba(255, 0, 0, 0.5)
  const rgbCommaMatch = color.match(/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/)
  if (rgbCommaMatch) {
    const r = parseInt(rgbCommaMatch[1]).toString(16).padStart(2, '0')
    const g = parseInt(rgbCommaMatch[2]).toString(16).padStart(2, '0')
    const b = parseInt(rgbCommaMatch[3]).toString(16).padStart(2, '0')
    return `${r}${g}${b}`
  }
  
  // RGB/RGBA format with spaces (modern CSS): rgb(255 0 0) or rgb(255 0 0 / 50%)
  const rgbSpaceMatch = color.match(/rgba?\s*\(\s*(\d+)\s+(\d+)\s+(\d+)/)
  if (rgbSpaceMatch) {
    const r = parseInt(rgbSpaceMatch[1]).toString(16).padStart(2, '0')
    const g = parseInt(rgbSpaceMatch[2]).toString(16).padStart(2, '0')
    const b = parseInt(rgbSpaceMatch[3]).toString(16).padStart(2, '0')
    return `${r}${g}${b}`
  }
  
  // HSL format: hsl(120, 100%, 50%) - convert to RGB then hex
  const hslMatch = color.match(/hsla?\s*\(\s*(\d+)\s*,?\s*(\d+)%?\s*,?\s*(\d+)%?/)
  if (hslMatch) {
    const h = parseInt(hslMatch[1]) / 360
    const s = parseInt(hslMatch[2]) / 100
    const l = parseInt(hslMatch[3]) / 100
    
    let r, g, b
    if (s === 0) {
      r = g = b = l
    } else {
      const hue2rgb = (p, q, t) => {
        if (t < 0) t += 1
        if (t > 1) t -= 1
        if (t < 1/6) return p + (q - p) * 6 * t
        if (t < 1/2) return q
        if (t < 2/3) return p + (q - p) * (2/3 - t) * 6
        return p
      }
      const q = l < 0.5 ? l * (1 + s) : l + s - l * s
      const p = 2 * l - q
      r = hue2rgb(p, q, h + 1/3)
      g = hue2rgb(p, q, h)
      b = hue2rgb(p, q, h - 1/3)
    }
    
    return Math.round(r * 255).toString(16).padStart(2, '0') +
           Math.round(g * 255).toString(16).padStart(2, '0') +
           Math.round(b * 255).toString(16).padStart(2, '0')
  }
  
  // Named colors - extended list
  const namedColors = {
    black: '000000', white: 'ffffff', red: 'ff0000', green: '008000', blue: '0000ff',
    yellow: 'ffff00', cyan: '00ffff', aqua: '00ffff', magenta: 'ff00ff', fuchsia: 'ff00ff',
    gray: '808080', grey: '808080', silver: 'c0c0c0',
    orange: 'ffa500', purple: '800080', pink: 'ffc0cb', brown: 'a52a2a',
    lime: '00ff00', olive: '808000', navy: '000080', teal: '008080', maroon: '800000',
    coral: 'ff7f50', salmon: 'fa8072', tomato: 'ff6347', crimson: 'dc143c',
    gold: 'ffd700', khaki: 'f0e68c', plum: 'dda0dd', violet: 'ee82ee',
    indigo: '4b0082', turquoise: '40e0d0', skyblue: '87ceeb', steelblue: '4682b4',
  }
  const lowerColor = color.toLowerCase()
  if (namedColors[lowerColor]) {
    return namedColors[lowerColor]
  }
  
  console.warn('[DOCX Export] Could not parse color:', color)
  return null
}

/**
 * Extract inline style value from style attribute string
 */
function getStyleValue(styleAttr, property) {
  if (!styleAttr) return null
  const regex = new RegExp(`${property}\\s*:\\s*([^;]+)`, 'i')
  const match = styleAttr.match(regex)
  return match ? match[1].trim() : null
}

/**
 * Parse inline elements (bold, italic, links, etc.)
 */
function parseInlineElement(node) {
  const tagName = node.tagName.toLowerCase()
  const runs = []
  
  // Get computed styles - check both style object and style attribute
  const style = node.style || {}
  const styleAttr = node.getAttribute('style') || ''
  
  // Extract colors from inline styles or data attributes
  const textColor = style.color || getStyleValue(styleAttr, 'color') || node.getAttribute('data-color')
  const bgColor = style.backgroundColor || getStyleValue(styleAttr, 'background-color') || node.getAttribute('data-highlight')
  const fontFamily = style.fontFamily || getStyleValue(styleAttr, 'font-family')
  
  // Determine formatting
  const formatting = {
    bold: tagName === 'strong' || tagName === 'b',
    italics: tagName === 'em' || tagName === 'i',
    underline: tagName === 'u' ? {} : undefined,
    strike: tagName === 's' || tagName === 'del' || tagName === 'strike',
    subScript: tagName === 'sub',
    superScript: tagName === 'sup',
  }
  
  // Handle color - convert to proper hex format
  if (textColor) {
    const hexColor = colorToHex(textColor)
    if (hexColor) {
      formatting.color = hexColor
    }
  }
  
  // Handle highlight
  if (bgColor && bgColor !== 'transparent') {
    formatting.highlight = 'yellow' // DOCX has limited highlight colors
    const hexBg = colorToHex(bgColor)
    if (hexBg) {
      formatting.shading = { fill: hexBg }
    }
  }
  
  // Handle font family
  if (fontFamily) {
    formatting.font = fontFamily.split(',')[0].replace(/['"]/g, '').trim()
  }
  
  // Handle links - preserve original styling, don't force blue underline
  if (tagName === 'a') {
    const href = node.getAttribute('href')
    if (href) {
      const linkRuns = []
      for (const child of node.childNodes) {
        if (child.nodeType === Node.TEXT_NODE) {
          // Keep original text formatting, no forced color or underline
          linkRuns.push(new TextRun({
            text: child.textContent,
            ...formatting, // Use the parent element's formatting
          }))
        } else if (child.nodeType === Node.ELEMENT_NODE) {
          // Recursively parse nested elements to preserve their styling
          const nestedRuns = parseInlineElement(child)
          linkRuns.push(...nestedRuns)
        }
      }
      return [new ExternalHyperlink({
        children: linkRuns.length > 0 ? linkRuns : [new TextRun({ text: href })],
        link: href,
      })]
    }
  }
  
  // Handle mark (highlight)
  if (tagName === 'mark') {
    const bgColor = style.backgroundColor || 'yellow'
    formatting.highlight = 'yellow'
  }
  
  // Parse children
  for (const child of node.childNodes) {
    if (child.nodeType === Node.TEXT_NODE) {
      const text = child.textContent
      if (text) {
        runs.push(new TextRun({
          text,
          ...formatting,
        }))
      }
    } else if (child.nodeType === Node.ELEMENT_NODE) {
      // Recursively parse nested elements
      const nestedRuns = parseInlineElement(child)
      // Merge formatting
      for (const run of nestedRuns) {
        if (run instanceof TextRun) {
          // Apply parent formatting to nested runs
          if (formatting.bold) run.bold = true
          if (formatting.italics) run.italics = true
          if (formatting.underline) run.underline = formatting.underline
          if (formatting.strike) run.strike = true
        }
        runs.push(run)
      }
    }
  }
  
  return runs
}

/**
 * Parse HTML table to DOCX table
 */
function parseTable(tableNode) {
  const rows = []
  
  // Get all rows (from thead and tbody)
  const allRows = tableNode.querySelectorAll('tr')
  
  for (const tr of allRows) {
    const cells = []
    const cellNodes = tr.querySelectorAll('th, td')
    
    for (const cell of cellNodes) {
      const isHeader = cell.tagName.toLowerCase() === 'th'
      
      cells.push(new DocxTableCell({
        children: [new Paragraph({
          children: parseInlineContent(cell),
          alignment: getAlignment(cell),
        })],
        shading: isHeader ? { fill: 'F4F4F5' } : undefined,
        verticalAlign: 'center',
      }))
    }
    
    if (cells.length > 0) {
      rows.push(new DocxTableRow({ children: cells }))
    }
  }
  
  if (rows.length === 0) return null
  
  return new DocxTable({
    rows: rows,
    width: { size: 100, type: WidthType.PERCENTAGE },
    borders: {
      top: { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' },
      bottom: { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' },
      left: { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' },
      right: { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' },
      insideHorizontal: { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' },
      insideVertical: { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' },
    },
  })
}

/**
 * Parse image element
 */
async function parseImage(imgNode) {
  const src = imgNode.getAttribute('src')
  if (!src) return null
  
  try {
    // Handle base64 images
    if (src.startsWith('data:image')) {
      const base64Data = src.split(',')[1]
      const mimeType = src.split(';')[0].split(':')[1]
      
      return new Paragraph({
        children: [new ImageRun({
          data: Uint8Array.from(atob(base64Data), c => c.charCodeAt(0)),
          transformation: {
            width: 400,
            height: 300,
          },
          type: mimeType.includes('png') ? 'png' : 'jpg',
        })],
      })
    }
    
    // Handle URL images - fetch and embed
    const response = await fetch(src)
    const blob = await response.blob()
    const arrayBuffer = await blob.arrayBuffer()
    
    return new Paragraph({
      children: [new ImageRun({
        data: new Uint8Array(arrayBuffer),
        transformation: {
          width: 400,
          height: 300,
        },
        type: blob.type.includes('png') ? 'png' : 'jpg',
      })],
    })
  } catch (e) {
    console.warn('Failed to embed image:', e)
    return new Paragraph({
      children: [new TextRun({ text: '[Image]', italics: true })],
    })
  }
}

/**
 * Get text alignment from element style
 */
function getAlignment(node) {
  const style = node.style?.textAlign || node.getAttribute('style')?.match(/text-align:\s*(\w+)/)?.[1]
  
  switch (style) {
    case 'center': return AlignmentType.CENTER
    case 'right': return AlignmentType.RIGHT
    case 'justify': return AlignmentType.JUSTIFIED
    default: return AlignmentType.LEFT
  }
}

/**
 * Download blob as file
 */
function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

