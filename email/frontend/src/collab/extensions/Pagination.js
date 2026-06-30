/**
 * Pagination Extension for Tiptap
 * 
 * ProseMirror plugin that renders page break decorations based on
 * content-based layout calculation (not DOM measurements).
 * 
 * Uses the LayoutCalculator service for efficient, cached pagination.
 * Supports both soft (calculated) and hard (user-inserted) page breaks.
 */

import { Extension } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import {
  PAGE_WIDTH,
  PAGE_HEIGHT,
  PAGE_MARGIN_TOP,
  PAGE_MARGIN_BOTTOM,
  PAGE_MARGIN_LEFT,
  PAGE_MARGIN_RIGHT,
  PAGE_CONTENT_HEIGHT,
  PAGE_FOOTER_CHROME_HEIGHT,
  PAGE_HEADER_CHROME_HEIGHT,
  PAGE_BREAK_GAP_HEIGHT,
  LAYOUT_DEBOUNCE_FULL,
} from '../services/pagination/constants.js'
import { getLayoutCalculator } from '../services/pagination/LayoutCalculator.js'

export const PaginationPluginKey = new PluginKey('pagination')

/**
 * Create a page break widget element
 * @param {number} pageNumber - The page number that just ended
 * @param {'soft'|'hard'} type - Type of page break
 * @param {Object} options - Extension options
 * @returns {HTMLElement}
 */
function createPageBreakWidget(pageNumber, type = 'soft', options = {}) {
  const widget = document.createElement('div')
  widget.className = `tiptap-page-break tiptap-page-break--${type}`
  widget.setAttribute('data-page', String(pageNumber))
  widget.setAttribute('data-break-type', type)
  widget.setAttribute('contenteditable', 'false')
  
  // Page footer chrome (shows page number of ending page)
  const footer = document.createElement('div')
  footer.className = 'tiptap-page-footer'
  
  if (options.showPageNumbers !== false) {
    const pageNum = document.createElement('span')
    pageNum.className = 'tiptap-page-number'
    // Add tilde for soft breaks to indicate they're estimated
    pageNum.textContent = type === 'soft' ? `~${pageNumber}` : String(pageNumber)
    footer.appendChild(pageNum)
  }
  
  // Gap between pages
  const gap = document.createElement('div')
  gap.className = 'tiptap-pagination-gap'
  
  // Show "PAGE BREAK" label for hard breaks
  if (type === 'hard') {
    const label = document.createElement('span')
    label.className = 'tiptap-page-break-label'
    label.textContent = 'PAGE BREAK'
    gap.appendChild(label)
  }
  
  // Page header chrome (for next page)
  const header = document.createElement('div')
  header.className = 'tiptap-page-header'
  
  widget.appendChild(footer)
  widget.appendChild(gap)
  widget.appendChild(header)
  
  return widget
}

/**
 * Create decorations for page breaks using LayoutCalculator results
 * @param {EditorState} state - ProseMirror state
 * @param {Object} layoutResult - Result from LayoutCalculator
 * @param {Object} options - Extension options
 * @returns {DecorationSet}
 */
function createDecorations(state, layoutResult, options) {
  if (!layoutResult || !layoutResult.pageBreaks || layoutResult.pageBreaks.length === 0) {
    return DecorationSet.empty
  }
  
  const decorations = []
  
  for (const pageBreak of layoutResult.pageBreaks) {
    const { pos, pageNumber, type } = pageBreak
    
    // Validate position is within document
    if (pos <= 0 || pos > state.doc.content.size) {
      continue
    }
    
    const widget = createPageBreakWidget(pageNumber, type || 'soft', options)
    
    decorations.push(
      Decoration.widget(pos, widget, {
        side: -1, // Insert before the position
        key: `page-break-${type}-${pageNumber}`,
        ignoreSelection: true,
        stopEvent: () => true,
      })
    )
  }
  
  if (decorations.length === 0) {
    return DecorationSet.empty
  }
  
  return DecorationSet.create(state.doc, decorations)
}

/**
 * Update container heights based on page count
 * @param {HTMLElement} editorDom - Editor DOM element
 * @param {number} pageCount - Number of pages
 * @param {number} breakCount - Number of page breaks
 */
function updateContainerHeights(editorDom, pageCount, breakCount) {
  const pagesContainer = editorDom?.closest('.tiptap-pages')
  
  if (!editorDom || !pagesContainer) {
    return
  }
  
  const pageBreakWidgetHeight = PAGE_FOOTER_CHROME_HEIGHT + PAGE_BREAK_GAP_HEIGHT + PAGE_HEADER_CHROME_HEIGHT
  
  // Calculate total height:
  // marginTop + (N pages * contentHeight) + marginBottom + (N-1 breaks * widgetHeight)
  const totalHeight = PAGE_MARGIN_TOP + 
                     (pageCount * PAGE_CONTENT_HEIGHT) + 
                     PAGE_MARGIN_BOTTOM + 
                     (breakCount * pageBreakWidgetHeight)
  
  // Only update if height changed significantly
  const currentHeight = parseInt(pagesContainer.style.minHeight) || 0
  if (Math.abs(totalHeight - currentHeight) > 10) {
    pagesContainer.style.minHeight = `${totalHeight}px`
    editorDom.style.minHeight = `${totalHeight}px`
  }
}

/**
 * Pagination Extension
 */
export const Pagination = Extension.create({
  name: 'pagination',
  
  addOptions() {
    return {
      pageWidth: PAGE_WIDTH,
      pageHeight: PAGE_HEIGHT,
      pageContentHeight: PAGE_CONTENT_HEIGHT,
      marginTop: PAGE_MARGIN_TOP,
      marginBottom: PAGE_MARGIN_BOTTOM,
      marginLeft: PAGE_MARGIN_LEFT,
      marginRight: PAGE_MARGIN_RIGHT,
      showPageNumbers: true,
      enabled: true,
    }
  },
  
  addStorage() {
    return {
      totalPages: 1,
      pageBreaks: [],
      layoutCalculator: null,
    }
  },
  
  onCreate() {
    // Initialize layout calculator with options
    this.storage.layoutCalculator = getLayoutCalculator()
    this.storage.layoutCalculator.configure({
      pageContentHeight: this.options.pageContentHeight,
      respectHardBreaks: true,
    })
  },
  
  onDestroy() {
    // Don't destroy the singleton, just unsubscribe
    this.storage.layoutCalculator = null
  },
  
  addProseMirrorPlugins() {
    const extension = this
    
    return [
      new Plugin({
        key: PaginationPluginKey,
        
        state: {
          init: () => ({
            decorations: DecorationSet.empty,
            totalPages: 1,
            pageBreaks: [],
          }),
          
          apply: (tr, pluginState, oldState, newState) => {
            // Check if decorations were updated via meta
            const meta = tr.getMeta(PaginationPluginKey)
            if (meta?.decorations) {
              return {
                decorations: meta.decorations,
                totalPages: meta.totalPages || 1,
                pageBreaks: meta.pageBreaks || [],
              }
            }
            
            // Map existing decorations through document changes
            if (tr.docChanged && pluginState.decorations) {
              return {
                ...pluginState,
                decorations: pluginState.decorations.map(tr.mapping, tr.doc),
                needsRecalculation: true,
              }
            }
            
            // Mark for recalculation if requested
            if (tr.getMeta('pagination-recalculate')) {
              return {
                ...pluginState,
                needsRecalculation: true,
              }
            }
            
            return pluginState
          },
        },
        
        props: {
          decorations(state) {
            const pluginState = this.getState(state)
            return pluginState?.decorations || DecorationSet.empty
          },
        },
        
        view(editorView) {
          let updateTimeout = null
          let idleCallbackId = null
          let isFirstRender = true
          let isUpdating = false
          let lastTotalPages = 1
          let unsubscribe = null
          
          // Get calculator with null check
          const calculator = extension.storage?.layoutCalculator
          
          // If no calculator available, return minimal view handler
          if (!calculator) {
            console.warn('[Pagination] LayoutCalculator not available')
            return {
              update() {},
              destroy() {},
            }
          }
          
          /**
           * Apply layout result to the editor
           */
          const applyLayout = (layoutResult) => {
            if (!extension.options.enabled || isUpdating) {
              return
            }
            
            if (!layoutResult) {
              return
            }
            
            isUpdating = true
            
            try {
              const { pageBreaks, totalPages } = layoutResult
              
              // Check if we need to update
              const pagesChanged = totalPages !== lastTotalPages
              const breaksChanged = pageBreaks.length !== extension.storage.pageBreaks.length
              const isFirstUpdate = extension.storage.pageBreaks.length === 0 && lastTotalPages === 1
              
              // Always render on first update to ensure decorations are applied
              if (!pagesChanged && !breaksChanged && !layoutResult.isEstimate && !isFirstUpdate) {
                isUpdating = false
                return
              }
              
              lastTotalPages = totalPages
              
              // Update storage
              extension.storage.totalPages = totalPages
              extension.storage.pageBreaks = pageBreaks
              
              // Update container heights
              updateContainerHeights(editorView.dom, totalPages, pageBreaks.length)
              
              // Create decorations
              const state = editorView.state
              const decorations = createDecorations(state, layoutResult, extension.options)
              
              // Dispatch update
              const tr = state.tr.setMeta(PaginationPluginKey, {
                decorations,
                totalPages,
                pageBreaks,
              })
              editorView.dispatch(tr)
            } finally {
              // Reset flag after a short delay to prevent rapid updates
              setTimeout(() => {
                isUpdating = false
              }, 100)
            }
          }
          
          /**
           * Schedule a layout calculation
           */
          const scheduleUpdate = (immediate = false) => {
            if (!extension.options.enabled) {
              return
            }
            
            // Clear existing timers
            if (updateTimeout) {
              clearTimeout(updateTimeout)
              updateTimeout = null
            }
            if (idleCallbackId && typeof cancelIdleCallback !== 'undefined') {
              cancelIdleCallback(idleCallbackId)
              idleCallbackId = null
            }
            
            const doc = editorView.state.doc
            
            if (immediate) {
              // Immediate calculation (for initial render or explicit request)
              const result = calculator.calculate(doc, { immediate: true, force: true })
              if (result) {
                applyLayout(result)
              }
            } else {
              // Debounced calculation - use longer delay for stability
              updateTimeout = setTimeout(() => {
                // First, apply a quick estimate for immediate feedback
                const estimate = calculator.calculate(doc, { immediate: false })
                if (estimate?.isEstimate) {
                  applyLayout(estimate)
                }
                
                // Then schedule full calculation in idle time
                if (typeof requestIdleCallback !== 'undefined') {
                  idleCallbackId = requestIdleCallback(
                    () => {
                      const result = calculator.calculate(doc, { immediate: true })
                      if (result && !result.isEstimate) {
                        applyLayout(result)
                      }
                    },
                    { timeout: LAYOUT_DEBOUNCE_FULL }
                  )
                } else {
                  // Fallback for browsers without requestIdleCallback
                  setTimeout(() => {
                    const result = calculator.calculate(doc, { immediate: true })
                    if (result && !result.isEstimate) {
                      applyLayout(result)
                    }
                  }, LAYOUT_DEBOUNCE_FULL)
                }
              }, 200) // 200ms debounce for stability
            }
          }
          
          // Subscribe to layout calculator updates
          if (calculator) {
            unsubscribe = calculator.subscribe((result) => {
              applyLayout(result)
            })
          }
          
          // Initial calculation after first render - use multiple attempts
          const attemptInitialRender = () => {
            if (isFirstRender) {
              isFirstRender = false
              scheduleUpdate(true)
              // Retry if decorations didn't apply (e.g., DOM wasn't ready)
              setTimeout(() => {
                if (extension.storage.totalPages === 1) {
                  scheduleUpdate(true)
                }
              }, 500)
            }
          }
          setTimeout(attemptInitialRender, 300)
          setTimeout(attemptInitialRender, 800)
          
          return {
            update(view, prevState) {
              // Only recalculate if document content changed
              const docChanged = !prevState.doc.eq(view.state.doc)
              
              if (docChanged) {
                // Invalidate cache since content changed
                if (calculator) {
                  calculator.invalidateCache()
                }
                scheduleUpdate(false)
              }
            },
            
            destroy() {
              if (updateTimeout) {
                clearTimeout(updateTimeout)
              }
              if (idleCallbackId && typeof cancelIdleCallback !== 'undefined') {
                cancelIdleCallback(idleCallbackId)
              }
              if (unsubscribe) {
                unsubscribe()
              }
            },
          }
        },
      }),
    ]
  },
  
  addCommands() {
    return {
      /**
       * Force recalculation of pagination
       */
      recalculatePagination: () => ({ tr, dispatch, editor }) => {
        if (this.storage.layoutCalculator) {
          this.storage.layoutCalculator.invalidateCache()
          const result = this.storage.layoutCalculator.calculate(
            editor.state.doc,
            { immediate: true, force: true }
          )
          if (result && dispatch) {
            const decorations = createDecorations(editor.state, result, this.options)
            tr.setMeta(PaginationPluginKey, {
              decorations,
              totalPages: result.totalPages,
              pageBreaks: result.pageBreaks,
            })
          }
        }
        return true
      },
      
      /**
       * Update pagination options
       */
      setPaginationOptions: (options) => ({ editor }) => {
        Object.assign(this.options, options)
        if (this.storage.layoutCalculator) {
          this.storage.layoutCalculator.configure({
            pageContentHeight: this.options.pageContentHeight,
          })
        }
        editor.commands.recalculatePagination()
        return true
      },
      
      /**
       * Enable/disable pagination
       */
      togglePagination: (enabled) => ({ editor }) => {
        this.options.enabled = enabled !== undefined ? enabled : !this.options.enabled
        if (this.options.enabled) {
          editor.commands.recalculatePagination()
        }
        return true
      },
      
      /**
       * Get current page number for cursor position
       */
      getCurrentPage: () => ({ editor }) => {
        if (!this.storage.layoutCalculator) {
          return 1
        }
        const { from } = editor.state.selection
        return this.storage.layoutCalculator.getPageForPosition(from)
      },
    }
  },
})

export default Pagination
