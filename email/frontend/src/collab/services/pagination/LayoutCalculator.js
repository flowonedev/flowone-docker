/**
 * LayoutCalculator Service
 * 
 * Efficient pagination calculation service that:
 * 1. Calculates soft page breaks from document content
 * 2. Respects hard page break nodes
 * 3. Caches results until content changes significantly
 * 4. Uses content-based heuristics instead of DOM measurements
 * 5. Full recalculation only on idle or explicit request
 * 
 * This replaces the DOM-measurement-on-every-keystroke approach
 * with a more efficient content-based estimation system.
 */

import {
  PAGE_CONTENT_HEIGHT,
  ESTIMATED_HEIGHTS,
  LINE_HEIGHT_MULTIPLIER,
  CHARS_PER_LINE,
  LAYOUT_DEBOUNCE_MIN,
  LAYOUT_DEBOUNCE_FULL,
  MAX_PAGES_PER_BATCH,
} from './constants.js'

/**
 * @typedef {Object} PageBreak
 * @property {number} pos - Document position of the break
 * @property {number} pageNumber - Page number that ends at this break
 * @property {'soft'|'hard'} type - Whether this is a calculated or user-inserted break
 */

/**
 * @typedef {Object} LayoutResult
 * @property {PageBreak[]} pageBreaks - Array of page break positions
 * @property {number} totalPages - Total number of pages
 * @property {number} totalHeight - Estimated total document height
 * @property {number} timestamp - When this layout was calculated
 */

/**
 * @typedef {Object} LayoutOptions
 * @property {number} pageContentHeight - Available height per page
 * @property {boolean} respectHardBreaks - Whether to respect hard page breaks
 */

class LayoutCalculator {
  constructor() {
    // Cache for layout results
    this.cache = null
    this.cacheKey = null
    
    // Debounce timers
    this.debounceTimer = null
    this.idleCallbackId = null
    
    // Subscribers for layout updates
    this.subscribers = new Set()
    
    // Options
    this.options = {
      pageContentHeight: PAGE_CONTENT_HEIGHT,
      respectHardBreaks: true,
    }
    
    // DOM measurement cache for complex nodes
    this.domMeasurementCache = new Map()
  }

  /**
   * Configure layout options
   * @param {Partial<LayoutOptions>} options 
   */
  configure(options) {
    Object.assign(this.options, options)
    this.invalidateCache()
  }

  /**
   * Subscribe to layout updates
   * @param {Function} callback - Called with LayoutResult when layout changes
   * @returns {Function} Unsubscribe function
   */
  subscribe(callback) {
    this.subscribers.add(callback)
    return () => this.subscribers.delete(callback)
  }

  /**
   * Notify subscribers of layout update
   * @param {LayoutResult} result 
   */
  notifySubscribers(result) {
    this.subscribers.forEach(callback => {
      try {
        callback(result)
      } catch (e) {
        console.error('[LayoutCalculator] Subscriber error:', e)
      }
    })
  }

  /**
   * Invalidate the cache, forcing recalculation on next request
   */
  invalidateCache() {
    this.cache = null
    this.cacheKey = null
  }

  /**
   * Generate a cache key from document content
   * Uses a fast hash based on structure, not full content
   * @param {Object} doc - ProseMirror document
   * @returns {string}
   */
  generateCacheKey(doc) {
    if (!doc) return ''
    
    // Fast structural hash: node count + types + approximate size
    let key = `${doc.nodeSize}:`
    
    doc.forEach((node, offset, index) => {
      if (index < 100) { // Only check first 100 nodes for performance
        key += `${node.type.name[0]}${node.nodeSize},`
      }
    })
    
    return key
  }

  /**
   * Check if cache is valid for the given document
   * @param {Object} doc - ProseMirror document
   * @returns {boolean}
   */
  isCacheValid(doc) {
    if (!this.cache || !this.cacheKey) return false
    
    const currentKey = this.generateCacheKey(doc)
    return currentKey === this.cacheKey
  }

  /**
   * Calculate layout with caching and debouncing
   * This is the main entry point for layout calculation
   * 
   * @param {Object} doc - ProseMirror document
   * @param {Object} options - Calculation options
   * @param {boolean} options.immediate - Skip debounce for immediate result
   * @param {boolean} options.force - Force recalculation even if cached
   * @returns {LayoutResult|null} - Returns cached result immediately, or null if calculating
   */
  calculate(doc, options = {}) {
    const { immediate = false, force = false } = options
    
    // Return cached result if valid
    if (!force && this.isCacheValid(doc)) {
      return this.cache
    }
    
    if (immediate) {
      // Synchronous calculation
      return this.performCalculation(doc)
    }
    
    // Debounced calculation
    this.scheduleCalculation(doc)
    
    // Return stale cache while calculating, or estimate
    return this.cache || this.quickEstimate(doc)
  }

  /**
   * Schedule a debounced calculation
   * @param {Object} doc - ProseMirror document
   */
  scheduleCalculation(doc) {
    // Clear existing timers
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer)
    }
    if (this.idleCallbackId && typeof cancelIdleCallback !== 'undefined') {
      cancelIdleCallback(this.idleCallbackId)
    }
    
    // Short debounce for quick feedback
    this.debounceTimer = setTimeout(() => {
      // Use requestIdleCallback for full calculation if available
      if (typeof requestIdleCallback !== 'undefined') {
        this.idleCallbackId = requestIdleCallback(
          () => {
            const result = this.performCalculation(doc)
            this.notifySubscribers(result)
          },
          { timeout: LAYOUT_DEBOUNCE_FULL }
        )
      } else {
        // Fallback for browsers without requestIdleCallback
        setTimeout(() => {
          const result = this.performCalculation(doc)
          this.notifySubscribers(result)
        }, LAYOUT_DEBOUNCE_MIN)
      }
    }, LAYOUT_DEBOUNCE_MIN)
  }

  /**
   * Quick estimate without full calculation
   * Used when cache is invalid but we need immediate response
   * @param {Object} doc - ProseMirror document
   * @returns {LayoutResult}
   */
  quickEstimate(doc) {
    if (!doc) {
      return {
        pageBreaks: [],
        totalPages: 1,
        totalHeight: this.options.pageContentHeight,
        timestamp: Date.now(),
        isEstimate: true,
      }
    }
    
    // Rough estimate based on node count and average height
    let totalHeight = 0
    let nodeCount = 0
    
    doc.forEach(node => {
      totalHeight += this.estimateNodeHeight(node)
      nodeCount++
    })
    
    const pageContentHeight = this.options.pageContentHeight
    const estimatedPages = Math.max(1, Math.ceil(totalHeight / pageContentHeight))
    
    return {
      pageBreaks: [], // No precise breaks in estimate
      totalPages: estimatedPages,
      totalHeight,
      timestamp: Date.now(),
      isEstimate: true,
    }
  }

  /**
   * Perform full layout calculation
   * @param {Object} doc - ProseMirror document
   * @returns {LayoutResult}
   */
  performCalculation(doc) {
    if (!doc) {
      const result = {
        pageBreaks: [],
        totalPages: 1,
        totalHeight: 0,
        timestamp: Date.now(),
      }
      this.cache = result
      this.cacheKey = ''
      return result
    }
    
    const pageBreaks = []
    const pageContentHeight = this.options.pageContentHeight
    
    let currentPageHeight = 0
    let currentPageNumber = 1
    let cumulativePos = 0
    
    // Iterate through all top-level nodes
    doc.forEach((node, offset) => {
      const nodePos = offset + 1 // +1 because offset is before the node
      const nodeHeight = this.estimateNodeHeight(node)
      
      // Check for hard page break node
      if (node.type.name === 'hardPageBreak') {
        // Hard page break always creates a new page
        if (currentPageHeight > 0) {
          pageBreaks.push({
            pos: nodePos,
            pageNumber: currentPageNumber,
            type: 'hard',
          })
          currentPageNumber++
          currentPageHeight = 0
        }
        return // Don't add height for the break itself
      }
      
      // Check if this node would overflow the page
      if (currentPageHeight + nodeHeight > pageContentHeight && currentPageHeight > 0) {
        // Need a soft page break before this node
        pageBreaks.push({
          pos: nodePos,
          pageNumber: currentPageNumber,
          type: 'soft',
        })
        currentPageNumber++
        currentPageHeight = nodeHeight
      } else {
        currentPageHeight += nodeHeight
      }
      
      // Handle oversized nodes (larger than a page)
      if (nodeHeight > pageContentHeight) {
        // This node spans multiple pages - calculate how many
        const additionalPages = Math.floor(nodeHeight / pageContentHeight)
        for (let i = 0; i < additionalPages; i++) {
          currentPageNumber++
        }
        currentPageHeight = nodeHeight % pageContentHeight
      }
      
      cumulativePos = nodePos + node.nodeSize
    })
    
    // Calculate total height
    let totalHeight = 0
    doc.forEach(node => {
      totalHeight += this.estimateNodeHeight(node)
    })
    
    const result = {
      pageBreaks,
      totalPages: currentPageNumber,
      totalHeight,
      timestamp: Date.now(),
    }
    
    // Update cache
    this.cache = result
    this.cacheKey = this.generateCacheKey(doc)
    
    return result
  }

  /**
   * Estimate the height of a node based on its type and content
   * @param {Object} node - ProseMirror node
   * @returns {number} Estimated height in pixels
   */
  estimateNodeHeight(node) {
    const typeName = node.type.name
    
    // Get base height for node type
    let baseHeight = ESTIMATED_HEIGHTS[typeName] || ESTIMATED_HEIGHTS.default
    
    switch (typeName) {
      case 'paragraph':
        return this.estimateParagraphHeight(node)
      
      case 'heading':
        const level = node.attrs.level || 1
        return ESTIMATED_HEIGHTS[`heading${level}`] || baseHeight
      
      case 'bulletList':
      case 'orderedList':
        return this.estimateListHeight(node)
      
      case 'blockquote':
        return this.estimateBlockquoteHeight(node)
      
      case 'codeBlock':
        return this.estimateCodeBlockHeight(node)
      
      case 'table':
        return this.estimateTableHeight(node)
      
      case 'image':
        return this.estimateImageHeight(node)
      
      case 'hardPageBreak':
        return 0 // Hard breaks don't contribute to content height
      
      case 'horizontalRule':
        return ESTIMATED_HEIGHTS.horizontalRule
      
      default:
        return baseHeight
    }
  }

  /**
   * Estimate paragraph height based on text length
   * @param {Object} node - Paragraph node
   * @returns {number}
   */
  estimateParagraphHeight(node) {
    const text = node.textContent || ''
    
    if (text.length === 0) {
      return ESTIMATED_HEIGHTS.emptyParagraph
    }
    
    // Estimate number of lines based on character count
    const lines = Math.ceil(text.length / CHARS_PER_LINE)
    const lineHeight = 16 * LINE_HEIGHT_MULTIPLIER // 16px base font
    const marginBottom = 12
    
    return Math.max(ESTIMATED_HEIGHTS.paragraph, lines * lineHeight + marginBottom)
  }

  /**
   * Estimate list height based on item count
   * @param {Object} node - List node
   * @returns {number}
   */
  estimateListHeight(node) {
    let totalHeight = 16 // List margin
    
    node.forEach(item => {
      if (item.type.name === 'listItem') {
        // Estimate each list item
        let itemHeight = ESTIMATED_HEIGHTS.listItem
        
        item.forEach(child => {
          if (child.type.name === 'paragraph') {
            itemHeight = this.estimateParagraphHeight(child)
          } else if (child.type.name === 'bulletList' || child.type.name === 'orderedList') {
            // Nested list
            itemHeight += this.estimateListHeight(child)
          }
        })
        
        totalHeight += itemHeight
      }
    })
    
    return totalHeight
  }

  /**
   * Estimate blockquote height
   * @param {Object} node - Blockquote node
   * @returns {number}
   */
  estimateBlockquoteHeight(node) {
    let contentHeight = 0
    
    node.forEach(child => {
      contentHeight += this.estimateNodeHeight(child)
    })
    
    // Add padding and border space
    return contentHeight + 32 // 16px padding top + 16px padding bottom
  }

  /**
   * Estimate code block height based on line count
   * @param {Object} node - Code block node
   * @returns {number}
   */
  estimateCodeBlockHeight(node) {
    const text = node.textContent || ''
    const lines = (text.match(/\n/g) || []).length + 1
    const lineHeight = 20 // Monospace line height
    const padding = 32 // 16px top + 16px bottom
    
    return Math.max(ESTIMATED_HEIGHTS.codeBlock, lines * lineHeight + padding)
  }

  /**
   * Estimate table height based on row count
   * @param {Object} node - Table node
   * @returns {number}
   */
  estimateTableHeight(node) {
    let rowCount = 0
    
    node.forEach(child => {
      if (child.type.name === 'tableRow') {
        rowCount++
      }
    })
    
    const rowHeight = ESTIMATED_HEIGHTS.tableRow
    const headerHeight = rowHeight + 8 // Extra for header
    const borderSpace = 2
    
    return headerHeight + (Math.max(0, rowCount - 1) * rowHeight) + borderSpace
  }

  /**
   * Estimate image height
   * Uses cached DOM measurement if available
   * @param {Object} node - Image node
   * @returns {number}
   */
  estimateImageHeight(node) {
    const src = node.attrs.src
    
    // Check cache for known image dimensions
    if (src && this.domMeasurementCache.has(src)) {
      return this.domMeasurementCache.get(src).height + 32 // Add margin
    }
    
    // Use explicit height if provided in attrs
    if (node.attrs.height) {
      return parseInt(node.attrs.height, 10) + 32
    }
    
    // Default estimate
    return ESTIMATED_HEIGHTS.image
  }

  /**
   * Cache a DOM measurement for a specific element
   * @param {string} key - Cache key (e.g., image src)
   * @param {Object} measurement - { width, height }
   */
  cacheDOMMeasurement(key, measurement) {
    this.domMeasurementCache.set(key, measurement)
    // Limit cache size
    if (this.domMeasurementCache.size > 1000) {
      const firstKey = this.domMeasurementCache.keys().next().value
      this.domMeasurementCache.delete(firstKey)
    }
  }

  /**
   * Get page number for a given document position
   * @param {number} pos - Document position
   * @returns {number} Page number (1-indexed)
   */
  getPageForPosition(pos) {
    if (!this.cache || this.cache.pageBreaks.length === 0) {
      return 1
    }
    
    let pageNumber = 1
    for (const pageBreak of this.cache.pageBreaks) {
      if (pos >= pageBreak.pos) {
        pageNumber = pageBreak.pageNumber + 1
      } else {
        break
      }
    }
    
    return pageNumber
  }

  /**
   * Get the position range for a given page
   * @param {number} pageNumber - Page number (1-indexed)
   * @returns {{ start: number, end: number }|null}
   */
  getPageRange(pageNumber) {
    if (!this.cache) return null
    
    const { pageBreaks, totalPages } = this.cache
    
    if (pageNumber < 1 || pageNumber > totalPages) {
      return null
    }
    
    let start = 0
    let end = Infinity
    
    if (pageNumber > 1) {
      // Find the break that ends the previous page
      const prevBreak = pageBreaks.find(pb => pb.pageNumber === pageNumber - 1)
      if (prevBreak) {
        start = prevBreak.pos
      }
    }
    
    // Find the break that ends this page
    const thisBreak = pageBreaks.find(pb => pb.pageNumber === pageNumber)
    if (thisBreak) {
      end = thisBreak.pos
    }
    
    return { start, end }
  }

  /**
   * Clean up resources
   */
  destroy() {
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer)
    }
    if (this.idleCallbackId && typeof cancelIdleCallback !== 'undefined') {
      cancelIdleCallback(this.idleCallbackId)
    }
    this.subscribers.clear()
    this.domMeasurementCache.clear()
    this.cache = null
    this.cacheKey = null
  }
}

// Singleton instance
let instance = null

/**
 * Get the singleton LayoutCalculator instance
 * @returns {LayoutCalculator}
 */
export function getLayoutCalculator() {
  if (!instance) {
    instance = new LayoutCalculator()
  }
  return instance
}

/**
 * Create a new LayoutCalculator instance (for testing or isolation)
 * @returns {LayoutCalculator}
 */
export function createLayoutCalculator() {
  return new LayoutCalculator()
}

export { LayoutCalculator }
export default getLayoutCalculator

