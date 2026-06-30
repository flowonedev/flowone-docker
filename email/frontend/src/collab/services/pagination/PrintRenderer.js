/**
 * Print Renderer
 * 
 * Handles rendering document content for print and export.
 * Supports splitting content into pages based on layout calculations.
 */

import {
  PAGE_WIDTH,
  PAGE_HEIGHT,
  PAGE_MARGIN_TOP,
  PAGE_MARGIN_BOTTOM,
  PAGE_MARGIN_LEFT,
  PAGE_MARGIN_RIGHT,
  PAGE_CONTENT_HEIGHT,
  ESTIMATED_HEIGHTS,
  CHARS_PER_LINE,
  LINE_HEIGHT_MULTIPLIER,
} from './constants.js'

export class PrintRenderer {
  constructor(options = {}) {
    this.pageWidth = options.pageWidth || PAGE_WIDTH
    this.pageHeight = options.pageHeight || PAGE_HEIGHT
    this.marginTop = options.marginTop || PAGE_MARGIN_TOP
    this.marginBottom = options.marginBottom || PAGE_MARGIN_BOTTOM
    this.marginLeft = options.marginLeft || PAGE_MARGIN_LEFT
    this.marginRight = options.marginRight || PAGE_MARGIN_RIGHT
  }

  /**
   * Prepare document for printing
   * @param {HTMLElement} editorElement - The editor's DOM element
   * @returns {Function} Cleanup function to call after printing
   */
  preparePrint(editorElement) {
    if (!editorElement) {
      console.warn('[PrintRenderer] No editor element provided')
      return () => {}
    }

    // Add print class to editor
    editorElement.classList.add('print-mode')

    // Hide page break decorations for print
    const pageBreaks = editorElement.querySelectorAll('.tiptap-page-break')
    pageBreaks.forEach(pb => {
      pb.dataset.printHidden = 'true'
      pb.style.display = 'none'
    })

    // Return cleanup function
    return () => {
      editorElement.classList.remove('print-mode')
      pageBreaks.forEach(pb => {
        pb.style.display = ''
        delete pb.dataset.printHidden
      })
    }
  }

  /**
   * Create print-specific CSS styles
   * @returns {string} CSS styles for printing
   */
  getPrintStyles() {
    return `
      @page {
        size: A4;
        margin: ${this.marginTop}px ${this.marginRight}px ${this.marginBottom}px ${this.marginLeft}px;
      }

      @media print {
        /* Hide page break widgets during print */
        .tiptap-page-break {
          display: none !important;
        }

        /* Remove visual styling from pages wrapper */
        .tiptap-pages-wrapper {
          background: white !important;
          padding: 0 !important;
        }

        .tiptap-pages {
          box-shadow: none !important;
          border: none !important;
          width: 100% !important;
        }

        .tiptap-pages .ProseMirror {
          padding: 0 !important;
        }

        /* Ensure content flows properly */
        .ProseMirror {
          min-height: auto !important;
        }

        /* Keep headings with following content */
        h1, h2, h3, h4, h5, h6 {
          page-break-after: avoid;
        }

        /* Avoid breaks inside certain elements */
        table, figure, blockquote, pre {
          page-break-inside: avoid;
        }

        /* Hide UI elements */
        .collab-document-toolbar,
        .document-header,
        .comments-panel,
        .version-history-panel,
        .floating-comment-btn,
        .remote-cursors-container,
        .table-controller {
          display: none !important;
        }
      }
    `
  }

  /**
   * Apply print styles to document
   * @returns {HTMLStyleElement} The style element (for cleanup)
   */
  injectPrintStyles() {
    const styleEl = document.createElement('style')
    styleEl.setAttribute('data-print-renderer', 'true')
    styleEl.textContent = this.getPrintStyles()
    document.head.appendChild(styleEl)
    return styleEl
  }

  /**
   * Remove injected print styles
   * @param {HTMLStyleElement} styleEl - The style element to remove
   */
  removePrintStyles(styleEl) {
    if (styleEl && styleEl.parentNode) {
      styleEl.parentNode.removeChild(styleEl)
    }
  }

  /**
   * Clean cloned content - remove edit-mode artifacts
   */
  cleanClonedContent(element) {
    if (!element) return

    // Remove contenteditable
    element.removeAttribute('contenteditable')
    element.removeAttribute('translate')
    element.removeAttribute('tabindex')

    // Remove ProseMirror-specific classes
    element.classList.remove('ProseMirror', 'ProseMirror-focused')

    // Remove any selection-related elements
    element.querySelectorAll('.ProseMirror-gapcursor, .ProseMirror-selectednode, .ProseMirror-widget').forEach(el => {
      el.remove()
    })

    // Remove page break widgets
    element.querySelectorAll('.tiptap-page-break').forEach(el => {
      el.remove()
    })

    // Recursively clean children
    Array.from(element.children).forEach(child => {
      this.cleanClonedContent(child)
    })
  }

  /**
   * Apply print-optimized styles to content
   */
  applyPrintStylesToContent(element) {
    if (!element) return

    // Base print typography
    const baseStyles = {
      fontFamily: 'Georgia, "Times New Roman", Times, serif',
      fontSize: '11pt',
      lineHeight: '1.5',
      color: '#000000'
    }

    // Apply base styles to container
    Object.assign(element.style, baseStyles)

    // Style headings
    element.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(h => {
      h.style.fontFamily = 'Arial, Helvetica, sans-serif'
      h.style.fontWeight = 'bold'
      h.style.color = '#1a365d'
      h.style.pageBreakAfter = 'avoid'
    })

    // Style paragraphs
    element.querySelectorAll('p').forEach(p => {
      p.style.marginBottom = '6pt'
      p.style.textAlign = 'justify'
    })

    // Style blockquotes
    element.querySelectorAll('blockquote').forEach(bq => {
      bq.style.fontStyle = 'italic'
      bq.style.color = '#4a5568'
      bq.style.borderLeft = '2pt solid #aaa'
      bq.style.paddingLeft = '12pt'
      bq.style.marginLeft = '0'
    })

    // Style lists
    element.querySelectorAll('ul, ol').forEach(list => {
      list.style.marginLeft = '20pt'
      list.style.marginBottom = '6pt'
    })

    // Style tables
    element.querySelectorAll('table').forEach(table => {
      table.style.borderCollapse = 'collapse'
      table.style.width = '100%'
      table.style.marginBottom = '12pt'
    })

    element.querySelectorAll('th, td').forEach(cell => {
      cell.style.border = '0.5pt solid #999'
      cell.style.padding = '4pt 6pt'
      cell.style.textAlign = 'left'
    })

    element.querySelectorAll('th').forEach(th => {
      th.style.background = '#f5f5f5'
      th.style.fontWeight = 'bold'
    })

    // Style code blocks
    element.querySelectorAll('pre, code').forEach(code => {
      code.style.fontFamily = '"Courier New", Courier, monospace'
      code.style.fontSize = '9pt'
    })

    element.querySelectorAll('pre').forEach(pre => {
      pre.style.background = '#f5f5f5'
      pre.style.padding = '6pt'
      pre.style.borderRadius = '2pt'
      pre.style.whiteSpace = 'pre-wrap'
      pre.style.wordBreak = 'break-word'
    })
  }

  /**
   * Render content into paginated array
   * This is the core method for print preview and PDF export
   * 
   * @param {string} htmlContent - HTML content to paginate
   * @param {Object} options - Rendering options
   * @param {string} options.headerText - Text for page headers
   * @param {string} options.footerText - Text for page footers
   * @param {boolean} options.showPageNumbers - Whether to show page numbers
   * @returns {Array<{html: string, pageNumber: number}>} Array of page objects
   */
  renderToPages(htmlContent, options = {}) {
    const {
      headerText = '',
      footerText = '',
      showPageNumbers = true,
    } = options
    
    if (!htmlContent) {
      return []
    }
    
    // Parse HTML content
    const parser = new DOMParser()
    const doc = parser.parseFromString(htmlContent, 'text/html')
    const body = doc.body
    
    if (!body.children.length) {
      return [{ html: htmlContent, pageNumber: 1 }]
    }
    
    // Calculate available content height per page
    const contentHeight = PAGE_CONTENT_HEIGHT
    
    // Split content into pages
    const pages = []
    let currentPageContent = []
    let currentPageHeight = 0
    let pageNumber = 1
    
    // Process each element
    for (const element of Array.from(body.children)) {
      // Check for hard page break
      if (this.isHardPageBreak(element)) {
        // Finish current page
        if (currentPageContent.length > 0) {
          pages.push({
            html: this.wrapPageContent(currentPageContent, pageNumber, {
              headerText,
              footerText,
              showPageNumbers,
              totalPages: 0, // Will be updated after
            }),
            pageNumber,
          })
          pageNumber++
          currentPageContent = []
          currentPageHeight = 0
        }
        continue
      }
      
      // Estimate element height
      const elementHeight = this.estimateElementHeight(element)
      
      // Check if element would overflow page
      if (currentPageHeight + elementHeight > contentHeight && currentPageContent.length > 0) {
        // Finish current page
        pages.push({
          html: this.wrapPageContent(currentPageContent, pageNumber, {
            headerText,
            footerText,
            showPageNumbers,
            totalPages: 0,
          }),
          pageNumber,
        })
        pageNumber++
        currentPageContent = []
        currentPageHeight = 0
      }
      
      // Add element to current page
      currentPageContent.push(element.outerHTML)
      currentPageHeight += elementHeight
    }
    
    // Add final page
    if (currentPageContent.length > 0) {
      pages.push({
        html: this.wrapPageContent(currentPageContent, pageNumber, {
          headerText,
          footerText,
          showPageNumbers,
          totalPages: 0,
        }),
        pageNumber,
      })
    }
    
    // Update total pages count in all pages
    const totalPages = pages.length
    return pages.map(page => ({
      ...page,
      html: page.html.replace(/__TOTAL_PAGES__/g, String(totalPages)),
      totalPages,
    }))
  }

  /**
   * Check if element is a hard page break
   * @param {HTMLElement} element
   * @returns {boolean}
   */
  isHardPageBreak(element) {
    return (
      element.classList?.contains('hard-page-break') ||
      element.getAttribute?.('data-page-break') === 'true'
    )
  }

  /**
   * Estimate element height for pagination
   * @param {HTMLElement} element
   * @returns {number} Estimated height in pixels
   */
  estimateElementHeight(element) {
    const tag = element.tagName?.toLowerCase() || ''
    const text = element.textContent || ''
    
    // Use constants for base heights
    const heights = {
      h1: ESTIMATED_HEIGHTS.heading1,
      h2: ESTIMATED_HEIGHTS.heading2,
      h3: ESTIMATED_HEIGHTS.heading3,
      h4: ESTIMATED_HEIGHTS.heading4,
      h5: ESTIMATED_HEIGHTS.heading5,
      h6: ESTIMATED_HEIGHTS.heading6,
      p: this.estimateParagraphHeight(text),
      ul: ESTIMATED_HEIGHTS.bulletList * Math.max(1, element.children?.length || 1),
      ol: ESTIMATED_HEIGHTS.orderedList * Math.max(1, element.children?.length || 1),
      blockquote: ESTIMATED_HEIGHTS.blockquote + this.estimateParagraphHeight(text),
      pre: ESTIMATED_HEIGHTS.codeBlock + ((text.split('\n').length - 3) * 20),
      table: ESTIMATED_HEIGHTS.table + ((element.querySelectorAll?.('tr')?.length || 0) * ESTIMATED_HEIGHTS.tableRow),
      hr: ESTIMATED_HEIGHTS.horizontalRule,
      img: ESTIMATED_HEIGHTS.image,
      div: this.estimateContainerHeight(element),
    }
    
    return heights[tag] || ESTIMATED_HEIGHTS.default
  }

  /**
   * Estimate paragraph height based on text length
   * @param {string} text
   * @returns {number}
   */
  estimateParagraphHeight(text) {
    if (!text || text.length === 0) {
      return ESTIMATED_HEIGHTS.emptyParagraph
    }
    
    const lines = Math.ceil(text.length / CHARS_PER_LINE)
    const lineHeight = 16 * LINE_HEIGHT_MULTIPLIER
    const marginBottom = 12
    
    return Math.max(ESTIMATED_HEIGHTS.paragraph, lines * lineHeight + marginBottom)
  }

  /**
   * Estimate container (div) height by summing children
   * @param {HTMLElement} element
   * @returns {number}
   */
  estimateContainerHeight(element) {
    if (!element.children?.length) {
      return this.estimateParagraphHeight(element.textContent || '')
    }
    
    let totalHeight = 0
    for (const child of element.children) {
      totalHeight += this.estimateElementHeight(child)
    }
    
    return totalHeight || ESTIMATED_HEIGHTS.default
  }

  /**
   * Wrap page content with header/footer structure
   * @param {string[]} contentParts - Array of HTML strings for this page
   * @param {number} pageNumber
   * @param {Object} options
   * @returns {string}
   */
  wrapPageContent(contentParts, pageNumber, options) {
    const { headerText, footerText, showPageNumbers } = options
    
    let html = ''
    
    // Header
    if (headerText) {
      html += `<div class="print-page-header">${headerText}</div>`
    }
    
    // Content
    html += `<div class="print-page-content">${contentParts.join('')}</div>`
    
    // Footer
    const footerParts = []
    if (footerText) {
      footerParts.push(footerText)
    }
    if (showPageNumbers) {
      footerParts.push(`Page ${pageNumber} of __TOTAL_PAGES__`)
    }
    
    if (footerParts.length > 0) {
      html += `<div class="print-page-footer">${footerParts.join(' | ')}</div>`
    }
    
    return html
  }

  /**
   * Create a printable document from pages
   * @param {Array<{html: string, pageNumber: number}>} pages
   * @param {Object} options
   * @returns {string} Complete HTML document for printing
   */
  createPrintDocument(pages, options = {}) {
    const {
      pageSize = 'A4',
      orientation = 'portrait',
    } = options
    
    const pageSizes = {
      A4: { width: 210, height: 297 },
      Letter: { width: 216, height: 279 },
      Legal: { width: 216, height: 356 },
    }
    
    const size = pageSizes[pageSize] || pageSizes.A4
    const width = orientation === 'landscape' ? size.height : size.width
    const height = orientation === 'landscape' ? size.width : size.height
    
    const pagesHtml = pages.map((page, index) => `
      <div class="print-page" data-page="${page.pageNumber}">
        ${page.html}
      </div>
    `).join('')
    
    return `
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset="utf-8">
        <title>Print Document</title>
        <style>
          @page {
            size: ${width}mm ${height}mm;
            margin: ${this.marginTop / 96 * 25.4}mm ${this.marginRight / 96 * 25.4}mm ${this.marginBottom / 96 * 25.4}mm ${this.marginLeft / 96 * 25.4}mm;
          }
          
          * {
            box-sizing: border-box;
          }
          
          body {
            margin: 0;
            padding: 0;
            font-family: 'Georgia', 'Times New Roman', serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #000;
          }
          
          .print-page {
            page-break-after: always;
            min-height: ${height - 50}mm;
            position: relative;
          }
          
          .print-page:last-child {
            page-break-after: auto;
          }
          
          .print-page-header {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10pt;
            color: #666;
            padding-bottom: 12pt;
          }
          
          .print-page-content {
            padding-top: 24pt;
          }
          
          .print-page-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10pt;
            color: #666;
            padding-top: 12pt;
          }
          
          h1, h2, h3, h4, h5, h6 {
            font-family: Arial, Helvetica, sans-serif;
            color: #1a365d;
            page-break-after: avoid;
          }
          
          h1 { font-size: 18pt; margin: 18pt 0 12pt; }
          h2 { font-size: 15pt; margin: 15pt 0 10pt; }
          h3 { font-size: 12pt; margin: 12pt 0 8pt; }
          
          p { margin: 0 0 8pt; }
          
          ul, ol { margin: 8pt 0; padding-left: 24pt; }
          
          blockquote {
            margin: 12pt 0;
            padding-left: 12pt;
            border-left: 2pt solid #ccc;
            font-style: italic;
            color: #555;
          }
          
          table {
            width: 100%;
            border-collapse: collapse;
            margin: 12pt 0;
            page-break-inside: avoid;
          }
          
          th, td {
            border: 0.5pt solid #999;
            padding: 4pt 6pt;
            text-align: left;
          }
          
          th { background: #f0f0f0; font-weight: bold; }
          
          pre, code {
            font-family: 'Courier New', monospace;
            font-size: 9pt;
          }
          
          pre {
            background: #f5f5f5;
            padding: 8pt;
            border-radius: 2pt;
            white-space: pre-wrap;
            word-break: break-word;
            page-break-inside: avoid;
          }
          
          img {
            max-width: 100%;
            height: auto;
          }
        </style>
      </head>
      <body>
        ${pagesHtml}
      </body>
      </html>
    `
  }

  /**
   * Cleanup
   */
  destroy() {
    // Remove any injected styles
    document.querySelectorAll('style[data-print-renderer]').forEach(el => {
      el.remove()
    })
  }
}

export default PrintRenderer
