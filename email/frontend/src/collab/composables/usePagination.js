/**
 * Pagination Composable
 * 
 * Simplified Vue composable for document pagination.
 * Pagination calculations are now handled by the Pagination Tiptap extension.
 * This composable provides print utilities and configuration management.
 */

import { ref, onUnmounted } from 'vue'
import { PrintRenderer } from '../services/pagination/PrintRenderer.js'
import {
  PAGE_WIDTH,
  PAGE_HEIGHT,
  PAGE_MARGIN_TOP,
  PAGE_MARGIN_BOTTOM,
  PAGE_MARGIN_LEFT,
  PAGE_MARGIN_RIGHT
} from '../services/pagination/constants.js'

export function usePagination(options = {}) {
  // Print renderer
  const printRenderer = ref(null)
  
  // Page configuration - can be used to configure both extension and print
  const pageConfig = ref({
    pageWidth: options.pageWidth || PAGE_WIDTH,
    pageHeight: options.pageHeight || PAGE_HEIGHT,
    marginTop: options.pageMarginTop || PAGE_MARGIN_TOP,
    marginBottom: options.pageMarginBottom || PAGE_MARGIN_BOTTOM,
    marginLeft: options.pageMarginLeft || PAGE_MARGIN_LEFT,
    marginRight: options.pageMarginRight || PAGE_MARGIN_RIGHT
  })

  /**
   * Initialize print renderer
   */
  function init() {
    if (!printRenderer.value) {
      printRenderer.value = new PrintRenderer(pageConfig.value)
    }
  }

  /**
   * Update page configuration
   * @param {Object} config - New configuration
   */
  function updatePageConfig(config) {
    Object.assign(pageConfig.value, config)
    
    // Re-initialize print renderer with new config
    if (printRenderer.value) {
      printRenderer.value.destroy()
    }
    printRenderer.value = new PrintRenderer(pageConfig.value)
  }

  /**
   * Get print CSS styles
   * @returns {string} CSS styles for printing
   */
  function getPrintStyles() {
    init()
    return printRenderer.value.getPrintStyles()
  }

  /**
   * Inject print styles into document
   * @returns {HTMLStyleElement} Style element for cleanup
   */
  function injectPrintStyles() {
    init()
    return printRenderer.value.injectPrintStyles()
  }

  /**
   * Remove print styles from document
   * @param {HTMLStyleElement} styleEl - Style element to remove
   */
  function removePrintStyles(styleEl) {
    if (printRenderer.value) {
      printRenderer.value.removePrintStyles(styleEl)
    }
  }

  /**
   * Prepare an editor element for printing
   * @param {HTMLElement} editorElement - The editor DOM element
   * @returns {Function} Cleanup function
   */
  function preparePrint(editorElement) {
    init()
    return printRenderer.value.preparePrint(editorElement)
  }

  /**
   * Clean cloned content for print (removes edit-mode artifacts)
   * @param {HTMLElement} element - Element to clean
   */
  function cleanClonedContent(element) {
    init()
    printRenderer.value.cleanClonedContent(element)
  }

  /**
   * Apply print-optimized styles to content
   * @param {HTMLElement} element - Element to style
   */
  function applyPrintStyles(element) {
    init()
    printRenderer.value.applyPrintStylesToContent(element)
  }

  /**
   * Cleanup
   */
  function destroy() {
    if (printRenderer.value) {
      printRenderer.value.destroy()
      printRenderer.value = null
    }
  }

  // Auto-cleanup on unmount
  onUnmounted(() => {
    destroy()
  })

  return {
    // State
    pageConfig,

    // Methods
    init,
    updatePageConfig,
    getPrintStyles,
    injectPrintStyles,
    removePrintStyles,
    preparePrint,
    cleanClonedContent,
    applyPrintStyles,
    destroy
  }
}

export default usePagination
