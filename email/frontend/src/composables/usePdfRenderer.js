/**
 * usePdfRenderer - Composable for rendering PDF pages to canvases via pdfjs-dist.
 * Shared by PdfZoneEditor (admin) and PdfSignatureViewer (portal).
 */
import { ref, shallowRef } from 'vue'
import * as pdfjsLib from 'pdfjs-dist'

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
  'pdfjs-dist/build/pdf.worker.min.mjs',
  import.meta.url
).href

export function usePdfRenderer() {
  const pdfDoc = shallowRef(null)
  const pageCount = ref(0)
  const pageDimensions = ref([])
  const loading = ref(false)
  const error = ref(null)

  /**
   * Load a PDF from a URL.
   * @param {string} url
   */
  async function loadPdf(url) {
    return loadPdfSource({ url })
  }

  /**
   * Load a PDF from raw bytes (e.g. decoded from a base64 data URL).
   * @param {ArrayBuffer|Uint8Array} data
   */
  async function loadPdfFromData(data) {
    return loadPdfSource({ data })
  }

  async function loadPdfSource(source) {
    loading.value = true
    error.value = null
    try {
      const doc = await pdfjsLib.getDocument({ ...source, useSystemFonts: true }).promise
      pdfDoc.value = doc
      pageCount.value = doc.numPages

      const dims = []
      for (let i = 1; i <= doc.numPages; i++) {
        const page = await doc.getPage(i)
        const vp = page.getViewport({ scale: 1.0 })
        dims.push({ width: vp.width, height: vp.height, pageNumber: i })
      }
      pageDimensions.value = dims
    } catch (e) {
      error.value = e.message || 'Failed to load PDF'
      pdfDoc.value = null
      pageCount.value = 0
      pageDimensions.value = []
    } finally {
      loading.value = false
    }
  }

  /**
   * Render a specific page to a canvas element.
   * @param {number} pageNumber - 1-based
   * @param {HTMLCanvasElement} canvas
   * @param {number} containerWidth - target width in CSS pixels
   * @returns {Promise<{scale: number, width: number, height: number}>}
   */
  async function renderPage(pageNumber, canvas, containerWidth) {
    if (!pdfDoc.value) throw new Error('PDF not loaded')
    const page = await pdfDoc.value.getPage(pageNumber)
    const baseViewport = page.getViewport({ scale: 1.0 })
    const dpr = window.devicePixelRatio || 1
    const scale = containerWidth / baseViewport.width
    const viewport = page.getViewport({ scale: scale * dpr })

    canvas.width = viewport.width
    canvas.height = viewport.height
    canvas.style.width = `${containerWidth}px`
    canvas.style.height = `${(baseViewport.height * scale)}px`

    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)

    await page.render({ canvasContext: ctx, viewport }).promise

    return {
      scale,
      width: containerWidth,
      height: baseViewport.height * scale,
      pdfWidth: baseViewport.width,
      pdfHeight: baseViewport.height,
    }
  }

  function destroy() {
    if (pdfDoc.value) {
      pdfDoc.value.destroy()
      pdfDoc.value = null
    }
    pageCount.value = 0
    pageDimensions.value = []
  }

  return {
    pdfDoc,
    pageCount,
    pageDimensions,
    loading,
    error,
    loadPdf,
    loadPdfFromData,
    renderPage,
    destroy,
  }
}
