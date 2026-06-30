/**
 * Collab Export Service
 * 
 * Handles export of documents and presentations to Office formats.
 */

import { Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType, Table, TableRow, TableCell, WidthType, ImageRun } from 'docx'
import PptxGenJS from 'pptxgenjs'
import { isDebugEnabled } from '@/utils/debug'

// ============================================================
// DOCX EXPORT (Documents)
// ============================================================

/**
 * Export TipTap content to DOCX
 * 
 * @param {Object} json - TipTap JSON content
 * @param {string} title - Document title
 * @returns {Blob} - DOCX file blob
 */
export async function exportToDocx(json, title = 'Document') {
  const children = []
  
  // Parse TipTap JSON to docx elements
  if (json.content) {
    for (const node of json.content) {
      const elements = await parseNode(node)
      children.push(...elements)
    }
  }
  
  const doc = new Document({
    title,
    creator: 'Collab Editor',
    description: 'Document exported from Collab Editor',
    sections: [
      {
        properties: {},
        children,
      },
    ],
  })
  
  const blob = await Packer.toBlob(doc)
  return blob
}

/**
 * Parse TipTap node to docx elements
 */
async function parseNode(node) {
  const elements = []
  
  switch (node.type) {
    case 'heading':
      elements.push(new Paragraph({
        text: getTextContent(node),
        heading: getHeadingLevel(node.attrs?.level),
        spacing: { after: 200 },
      }))
      break
      
    case 'paragraph':
      const textRuns = parseTextRuns(node.content || [])
      elements.push(new Paragraph({
        children: textRuns,
        alignment: getAlignment(node.attrs?.textAlign),
        spacing: { after: 200 },
      }))
      break
      
    case 'bulletList':
    case 'orderedList':
      for (const item of node.content || []) {
        if (item.type === 'listItem') {
          for (const child of item.content || []) {
            const textRuns = parseTextRuns(child.content || [])
            elements.push(new Paragraph({
              children: textRuns,
              bullet: node.type === 'bulletList' ? { level: 0 } : undefined,
              numbering: node.type === 'orderedList' ? { reference: 'default-numbering', level: 0 } : undefined,
            }))
          }
        }
      }
      break
      
    case 'blockquote':
      for (const child of node.content || []) {
        const childElements = await parseNode(child)
        for (const el of childElements) {
          if (el instanceof Paragraph) {
            el.indent = { left: 720 }
            el.style = 'Intense Quote'
          }
        }
        elements.push(...childElements)
      }
      break
      
    case 'codeBlock':
      elements.push(new Paragraph({
        text: getTextContent(node),
        style: 'Code',
        shading: { fill: 'f4f4f4' },
      }))
      break
      
    case 'horizontalRule':
      elements.push(new Paragraph({
        border: {
          bottom: { color: 'auto', space: 1, style: 'single', size: 6 },
        },
      }))
      break
      
    case 'table':
      const rows = []
      for (const row of node.content || []) {
        if (row.type === 'tableRow') {
          const cells = []
          for (const cell of row.content || []) {
            const cellContent = []
            for (const child of cell.content || []) {
              const childElements = await parseNode(child)
              cellContent.push(...childElements)
            }
            cells.push(new TableCell({
              children: cellContent.length > 0 ? cellContent : [new Paragraph('')],
              width: { size: 100 / (row.content?.length || 1), type: WidthType.PERCENTAGE },
            }))
          }
          rows.push(new TableRow({ children: cells }))
        }
      }
      if (rows.length > 0) {
        elements.push(new Table({
          rows,
          width: { size: 100, type: WidthType.PERCENTAGE },
        }))
      }
      break
      
    case 'image':
      // Images would need to be fetched and converted
      // For now, add a placeholder
      elements.push(new Paragraph({
        text: `[Image: ${node.attrs?.src || 'unnamed'}]`,
        style: 'Caption',
      }))
      break
  }
  
  return elements
}

/**
 * Parse text content with marks to TextRuns
 */
function parseTextRuns(content) {
  const runs = []
  
  for (const item of content) {
    if (item.type === 'text') {
      const options = {
        text: item.text,
      }
      
      // Apply marks
      for (const mark of item.marks || []) {
        switch (mark.type) {
          case 'bold':
            options.bold = true
            break
          case 'italic':
            options.italics = true
            break
          case 'underline':
            options.underline = {}
            break
          case 'strike':
            options.strike = true
            break
          case 'link':
            options.link = mark.attrs?.href
            break
        }
      }
      
      runs.push(new TextRun(options))
    } else if (item.type === 'hardBreak') {
      runs.push(new TextRun({ break: 1 }))
    }
  }
  
  return runs
}

/**
 * Get text content from a node
 */
function getTextContent(node) {
  if (!node.content) return ''
  return node.content
    .filter(c => c.type === 'text')
    .map(c => c.text)
    .join('')
}

/**
 * Get docx heading level
 */
function getHeadingLevel(level) {
  switch (level) {
    case 1: return HeadingLevel.HEADING_1
    case 2: return HeadingLevel.HEADING_2
    case 3: return HeadingLevel.HEADING_3
    case 4: return HeadingLevel.HEADING_4
    case 5: return HeadingLevel.HEADING_5
    case 6: return HeadingLevel.HEADING_6
    default: return HeadingLevel.HEADING_1
  }
}

/**
 * Get docx alignment
 */
function getAlignment(align) {
  switch (align) {
    case 'center': return AlignmentType.CENTER
    case 'right': return AlignmentType.RIGHT
    case 'justify': return AlignmentType.JUSTIFIED
    default: return AlignmentType.LEFT
  }
}

// ============================================================
// PPTX EXPORT (Presentations)
// ============================================================

/**
 * Fonts that MUST be replaced (icon fonts that won't render correctly)
 */
const fontReplacements = {
  'Material Symbols Rounded': 'Arial',
  'Material Symbols Outlined': 'Arial',
  'Material Icons': 'Arial',
}

/**
 * Common Google Fonts - pass these through as-is
 * Users often have these installed, and PowerPoint will use them if available.
 * If not installed, PowerPoint will substitute a similar font automatically.
 */
const googleFonts = new Set([
  // Sans Serif
  'Outfit', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Inter',
  'Nunito', 'Raleway', 'Work Sans', 'Source Sans Pro', 'DM Sans', 'Rubik',
  'Quicksand', 'Manrope', 'Plus Jakarta Sans', 'Space Grotesk', 'Sora',
  // Serif
  'Playfair Display', 'Merriweather', 'Lora', 'Source Serif Pro',
  'Libre Baskerville', 'PT Serif', 'EB Garamond', 'Crimson Text',
  // Display
  'Oswald', 'Bebas Neue', 'Anton', 'Archivo Black', 'Abril Fatface',
  'Lobster', 'Pacifico', 'Righteous',
  // Monospace
  'JetBrains Mono', 'Roboto Mono', 'Source Code Pro', 'Fira Code',
  'Space Mono', 'IBM Plex Mono', 'Inconsolata',
])

/**
 * System fonts that are always available
 */
const systemFonts = new Set([
  'Arial', 'Calibri', 'Georgia', 'Times New Roman', 'Verdana', 'Tahoma',
  'Impact', 'Consolas', 'Courier New', 'Comic Sans MS', 'Garamond', 'Arial Black',
  'Segoe UI', 'Trebuchet MS', 'Palatino Linotype', 'Book Antiqua',
])

/**
 * Get font for PowerPoint export
 * 
 * Strategy: 
 * 1. Replace icon fonts with Arial
 * 2. Pass through system fonts as-is
 * 3. Pass through Google Fonts as-is (user may have them installed)
 * 4. For unknown fonts, pass them through (PowerPoint will substitute if needed)
 */
function getSystemFont(fontFamily) {
  if (!fontFamily) return 'Arial'
  
  const font = fontFamily.trim()
  
  // Always replace icon fonts
  if (fontReplacements[font]) {
    return fontReplacements[font]
  }
  
  // System fonts - use as-is
  if (systemFonts.has(font)) {
    return font
  }
  
  // Google Fonts - pass through (user may have them installed)
  if (googleFonts.has(font)) {
    return font
  }
  
  // Unknown fonts - pass through, PowerPoint will handle substitution
  return font
}

/**
 * Parse CSS gradient to PptxGenJS gradient format
 * Supports linear-gradient CSS syntax
 */
function parseGradientForPptx(gradientValue) {
  if (!gradientValue) return null
  
  // Match linear-gradient pattern
  const linearMatch = gradientValue.match(/linear-gradient\(([^)]+)\)/)
  if (!linearMatch) return null
  
  const gradientContent = linearMatch[1]
  
  // Parse angle/direction
  let rotation = 90 // Default to horizontal (left to right)
  const angleMatch = gradientContent.match(/^(\d+)deg/)
  if (angleMatch) {
    rotation = parseInt(angleMatch[1], 10)
  } else if (gradientContent.includes('to right')) {
    rotation = 90
  } else if (gradientContent.includes('to left')) {
    rotation = 270
  } else if (gradientContent.includes('to bottom')) {
    rotation = 180
  } else if (gradientContent.includes('to top')) {
    rotation = 0
  } else if (gradientContent.includes('to bottom right')) {
    rotation = 135
  } else if (gradientContent.includes('to top right')) {
    rotation = 45
  } else if (gradientContent.includes('to bottom left')) {
    rotation = 225
  } else if (gradientContent.includes('to top left')) {
    rotation = 315
  }
  
  // Extract colors
  const colorRegex = /#([0-9A-Fa-f]{6}|[0-9A-Fa-f]{3})|rgb\([^)]+\)|rgba\([^)]+\)|[a-z]+(?=\s|,|$)/gi
  const colors = []
  let match
  while ((match = colorRegex.exec(gradientContent)) !== null) {
    let color = match[0]
    
    // Convert hex to proper format
    if (color.startsWith('#')) {
      color = color.slice(1)
      // Expand 3-char hex
      if (color.length === 3) {
        color = color[0] + color[0] + color[1] + color[1] + color[2] + color[2]
      }
    } else if (color.startsWith('rgb')) {
      // Convert rgb/rgba to hex
      const rgbMatch = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
      if (rgbMatch) {
        const r = parseInt(rgbMatch[1], 10).toString(16).padStart(2, '0')
        const g = parseInt(rgbMatch[2], 10).toString(16).padStart(2, '0')
        const b = parseInt(rgbMatch[3], 10).toString(16).padStart(2, '0')
        color = r + g + b
      } else {
        continue
      }
    } else {
      // Named colors - map common ones
      const namedColors = {
        'black': '000000', 'white': 'FFFFFF', 'red': 'FF0000',
        'green': '00FF00', 'blue': '0000FF', 'yellow': 'FFFF00',
        'cyan': '00FFFF', 'magenta': 'FF00FF', 'gray': '808080',
        'grey': '808080', 'orange': 'FFA500', 'purple': '800080',
        'pink': 'FFC0CB', 'navy': '000080', 'teal': '008080',
      }
      if (namedColors[color.toLowerCase()]) {
        color = namedColors[color.toLowerCase()]
      } else {
        continue
      }
    }
    
    colors.push(color.toUpperCase())
  }
  
  if (colors.length < 2) return null
  
  // Build PptxGenJS gradient stops
  const gradStops = colors.map((color, index) => ({
    position: Math.round((index / (colors.length - 1)) * 100),
    color: color,
  }))
  
  return {
    gradFill: {
      type: 'linear',
      direction: rotation,
      colors: gradStops,
    }
  }
}

/**
 * Export presentation to PPTX
 * 
 * @param {Object} meta - Presentation metadata
 * @param {Array} slides - Array of slide objects
 * @param {string} title - Presentation title
 * @returns {Blob} - PPTX file blob
 */
export async function exportToPptx(meta, slides, title = 'Presentation') {
  isDebugEnabled() && console.log('[PPTX Export] Starting export...', { meta, slideCount: slides.length })
  
  const pptx = new PptxGenJS()
  
  // Set presentation properties
  pptx.author = 'Collab Editor'
  pptx.title = title
  pptx.subject = 'Presentation exported from Collab Editor'
  
  // Set layout based on aspect ratio
  const isWidescreen = meta.aspectRatio === '16:9'
  if (isWidescreen) {
    pptx.defineLayout({ name: 'LAYOUT_16x9', width: 10, height: 5.625 })
    pptx.layout = 'LAYOUT_16x9'
  } else {
    pptx.defineLayout({ name: 'LAYOUT_4x3', width: 10, height: 7.5 })
    pptx.layout = 'LAYOUT_4x3'
  }
  
  // Scale factors (slide coordinates to inches)
  const scaleX = 10 / (meta.slideWidth || 1920)
  const scaleY = (isWidescreen ? 5.625 : 7.5) / (meta.slideHeight || 1080)
  
  // Process each slide
  for (const slideData of slides) {
    const slide = pptx.addSlide()
    
    // Set slide background
    if (slideData.background) {
      if (slideData.background.type === 'solid') {
        slide.background = { color: slideData.background.value.replace('#', '') }
      } else if (slideData.background.type === 'gradient') {
        // Parse and apply gradient
        const gradientBg = parseGradientForPptx(slideData.background.value)
        if (gradientBg) {
          slide.background = gradientBg
        } else {
          // Fallback: try to extract first color from gradient
          const colorMatch = slideData.background.value.match(/#([0-9A-Fa-f]{6}|[0-9A-Fa-f]{3})/)
          if (colorMatch) {
            slide.background = { color: colorMatch[1] }
          } else {
            slide.background = { color: 'FFFFFF' }
          }
        }
      } else if (slideData.background.type === 'image') {
        // Handle image backgrounds
        const imgUrl = slideData.background.value
        if (imgUrl.startsWith('data:')) {
          slide.background = { data: imgUrl }
        } else {
          slide.background = { path: imgUrl }
        }
      }
    }
    
    // Process slide objects - sort by zIndex for proper layering
    const sortedObjects = [...(slideData.objects || [])].sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0))
    
    for (const obj of sortedObjects) {
      try {
        // Skip invalid objects
        if (!obj || !obj.type) continue
        
        // Calculate position and size in inches with validation
        const x = Math.max(0, Number(obj.x) * scaleX || 0)
        const y = Math.max(0, Number(obj.y) * scaleY || 0)
        const w = Math.max(0.1, Number(obj.width) * scaleX || 0.5)
        const h = Math.max(0.1, Number(obj.height) * scaleY || 0.5)
        const rotate = Number(obj.rotation) || 0
        
        switch (obj.type) {
          case 'text': {
            // Strip HTML tags for plain text
            let plainText = stripHtml(obj.content)
            if (!plainText || plainText.trim() === '') break
            
            // Apply text transform (uppercase, lowercase, capitalize)
            if (obj.textTransform === 'uppercase') {
              plainText = plainText.toUpperCase()
            } else if (obj.textTransform === 'lowercase') {
              plainText = plainText.toLowerCase()
            } else if (obj.textTransform === 'capitalize') {
              plainText = plainText.replace(/\b\w/g, c => c.toUpperCase())
            }
            
            // Get system-compatible font
            const fontFace = getSystemFont(obj.fontFamily)
            
            // Calculate font size - scale proportionally to slide dimensions
            // Original: px on 1920px canvas -> points on 10" slide (72 points per inch)
            // Formula: fontSize_pt = fontSize_px * (10 / 1920) * 72 = fontSize_px * 0.375
            const fontScaleFactor = scaleX * 72 // scaleX = 10/1920 for 16:9
            const fontSize = obj.fontSize ? Math.max(8, Math.round(obj.fontSize * fontScaleFactor)) : 18
            
            // Calculate adjusted width - add buffer for font metric differences
            // Also account for letter spacing which makes text wider
            const letterSpacingExtra = obj.letterSpacing ? (obj.letterSpacing * plainText.length * scaleX / 2) : 0
            const adjustedW = (w * 1.15) + letterSpacingExtra // 15% wider + letter spacing adjustment
            
            isDebugEnabled() && console.log('[PPTX Export] Text object:', {
              text: plainText.substring(0, 50),
              font: fontFace,
              originalFontSize: obj.fontSize,
              scaledFontSize: fontSize,
              textTransform: obj.textTransform,
              lineHeight: obj.lineHeight,
              letterSpacing: obj.letterSpacing,
              x, y, w, adjustedW
            })
            
            const textOptions = {
              x,
              y,
              w: adjustedW,
              h,
              fontSize,
              fontFace,
              color: obj.color?.replace('#', '') || '000000',
              align: obj.textAlign || 'left',
              valign: 'top',
              rotate,
            }
            
            // Add letter spacing (convert px to points)
            // PptxGenJS charSpacing is in points (1/100 of font size)
            if (obj.letterSpacing && obj.letterSpacing !== 0) {
              // letterSpacing in px -> convert to percentage of font size
              const charSpacingPts = obj.letterSpacing * fontScaleFactor
              textOptions.charSpacing = charSpacingPts
            }
            
            // Add line spacing (lineHeight is a multiplier like 1.2, 1.4, etc.)
            if (obj.lineHeight && obj.lineHeight !== 1) {
              // PptxGenJS uses lineSpacingMultiple (percentage: 100 = single, 150 = 1.5x)
              textOptions.lineSpacingMultiple = obj.lineHeight
            }
            
            // Add font weight (bold)
            if (obj.fontWeight && (obj.fontWeight === 'bold' || parseInt(obj.fontWeight) >= 600)) {
              textOptions.bold = true
            }
            
            // Add italic
            if (obj.fontStyle === 'italic') {
              textOptions.italic = true
            }
            
            // Add text decoration (underline, strikethrough)
            if (obj.textDecoration) {
              if (obj.textDecoration.includes('underline')) {
                textOptions.underline = { style: 'single' }
              }
              if (obj.textDecoration.includes('line-through')) {
                textOptions.strike = 'sngStrike'
              }
            }
            
            slide.addText(plainText, textOptions)
            break
          }
          
          case 'shape': {
            const shapeType = String(obj.shapeType || '').trim()
            const isIconShape = shapeType.startsWith('icon_') || shapeType.includes('icon')
            
            isDebugEnabled() && console.log('[PPTX Export] Shape object:', {
              shapeType,
              isIcon: isIconShape,
              x, y, w, h,
              fill: obj.fill
            })
            
            // Check if this is an icon shape (Material Symbol)
            if (isIconShape) {
              // Icons are exported as colored circles (PowerPoint doesn't have Material Symbols)
              const fillColor = obj.fill?.replace('#', '') || '2196F3'
              slide.addShape('ellipse', {
                x,
                y,
                w,
                h,
                fill: { color: fillColor },
                line: { color: fillColor, width: 0 },
                rotate,
              })
            } else if (shapeType === 'line' || shapeType === 'arrow') {
              // Lines need special handling with endpoints
              const strokeColor = obj.stroke?.replace('#', '') || obj.fill?.replace('#', '') || '000000'
              const lineWidth = obj.strokeWidth || 2
              
              // Calculate line endpoints considering rotation
              const x1 = x
              const y1 = y + h / 2
              const x2 = x + w
              const y2 = y + h / 2
              
              slide.addShape('line', {
                x: x1,
                y: y1,
                w: w,
                h: 0,
                line: { color: strokeColor, width: lineWidth },
                rotate,
              })
            } else {
              // Regular shapes
              const pptxShapeType = mapShapeType(shapeType)
              const fillColor = obj.fill?.replace('#', '') || '2196F3'
              const strokeColor = obj.stroke?.replace('#', '') || '1976D2'
              
              slide.addShape(pptxShapeType, {
                x,
                y,
                w,
                h,
                fill: fillColor === 'transparent' ? null : { color: fillColor },
                line: {
                  color: strokeColor,
                  width: obj.strokeWidth || 1,
                },
                rotate,
              })
            }
            break
          }
          
          case 'image': {
            if (obj.imageUrl) {
              const imageOptions = {
                x,
                y,
                w,
                h,
                rotate,
              }
              
              // Handle base64 images
              if (obj.imageUrl.startsWith('data:')) {
                imageOptions.data = obj.imageUrl
              } else {
                imageOptions.path = obj.imageUrl
              }
              
              slide.addImage(imageOptions)
            }
            break
          }
        }
      } catch (err) {
        console.warn('[PPTX Export] Error processing object:', obj.type, err)
        // Continue with next object
      }
    }
  }
  
  // Generate blob
  const blob = await pptx.write({ outputType: 'blob' })
  return blob
}

/**
 * Map our shape types to PptxGenJS shape types
 */
function mapShapeType(shapeType) {
  const mapping = {
    // Basic shapes
    rectangle: 'rect',
    roundedRectangle: 'roundRect',
    ellipse: 'ellipse',
    triangle: 'triangle',
    diamond: 'diamond',
    pentagon: 'pentagon',
    hexagon: 'hexagon',
    // Lines
    line: 'line',
    arrow: 'rightArrow',
    doubleArrow: 'leftRightArrow',
    curvedArrow: 'curvedRightArrow',
    // Block arrows
    arrowRight: 'rightArrow',
    arrowLeft: 'leftArrow',
    arrowUp: 'upArrow',
    arrowDown: 'downArrow',
    arrowLeftRight: 'leftRightArrow',
    arrowUpDown: 'upDownArrow',
    chevronRight: 'chevron',
    // Stars
    star4: 'star4',
    star5: 'star5',
    star6: 'star6',
    star8: 'star8',
    burst: 'star16',
    ribbon: 'ribbon',
    // Callouts
    calloutRectangle: 'wedgeRectCallout',
    calloutRounded: 'wedgeRoundRectCallout',
    calloutCloud: 'cloudCallout',
    // Flowchart
    process: 'rect',
    decision: 'diamond',
    terminator: 'roundRect',
    document: 'flowChartDocument',
    data: 'parallelogram',
  }
  return mapping[shapeType] || 'rect'
}

/**
 * Strip HTML tags from content and clean up text
 */
function stripHtml(html) {
  if (!html) return ''
  
  try {
    // Use DOMParser to extract text
    const doc = new DOMParser().parseFromString(html, 'text/html')
    let text = doc.body.textContent || ''
    
    // Remove any zero-width characters that might cause issues
    text = text.replace(/[\u200B-\u200D\uFEFF\u00A0]/g, ' ')
    
    // Normalize line breaks - replace multiple newlines with single space for PowerPoint
    text = text.replace(/[\r\n]+/g, '\n')
    
    // Clean up excessive whitespace but preserve single line breaks
    text = text.replace(/[ \t]+/g, ' ').trim()
    
    return text
  } catch (e) {
    // Fallback: simple regex strip
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
  }
}

// ============================================================
// DOWNLOAD HELPERS
// ============================================================

/**
 * Download a blob as a file
 */
export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

/**
 * Export and download document as DOCX
 */
export async function downloadAsDocx(editor, title = 'Document') {
  const json = editor.getJSON()
  const blob = await exportToDocx(json, title)
  downloadBlob(blob, `${sanitizeFilename(title)}.docx`)
}

/**
 * Export and download presentation as PPTX
 */
export async function downloadAsPptx(meta, slides, title = 'Presentation') {
  const blob = await exportToPptx(meta, slides, title)
  downloadBlob(blob, `${sanitizeFilename(title)}.pptx`)
}

/**
 * Sanitize filename
 */
function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9\-_ ]/gi, '').trim() || 'untitled'
}

