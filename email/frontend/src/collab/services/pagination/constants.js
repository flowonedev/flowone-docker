/**
 * Pagination Constants
 * 
 * Shared A4 page dimensions at 96 DPI.
 * Uses Word-standard 1-inch margins on all sides for DOCX compatibility.
 * Used by Pagination extension, PrintRenderer, and CollabDocumentEditor.
 */

// A4 page dimensions at 96 DPI
export const PAGE_WIDTH = 794       // 210mm
export const PAGE_HEIGHT = 1123     // 297mm

// Page margins (in pixels) - Word default: 1 inch on all sides for DOCX compatibility
export const PAGE_MARGIN_TOP = 96      // 1 inch (25.4mm)
export const PAGE_MARGIN_BOTTOM = 96   // 1 inch (25.4mm)
export const PAGE_MARGIN_LEFT = 96     // 1 inch (25.4mm)
export const PAGE_MARGIN_RIGHT = 96    // 1 inch (25.4mm)

// Derived content area dimensions
export const PAGE_CONTENT_HEIGHT = PAGE_HEIGHT - PAGE_MARGIN_TOP - PAGE_MARGIN_BOTTOM  // 931px
export const PAGE_CONTENT_WIDTH = PAGE_WIDTH - PAGE_MARGIN_LEFT - PAGE_MARGIN_RIGHT    // 602px

// Page chrome (header/footer) - visual decoration, not document content
// These are subtracted from available page body height
export const PAGE_HEADER_CHROME_HEIGHT = 48  // ~0.5 inch header space
export const PAGE_FOOTER_CHROME_HEIGHT = 48  // ~0.5 inch footer space

// Actual usable content height after chrome
export const PAGE_BODY_HEIGHT = PAGE_CONTENT_HEIGHT - PAGE_HEADER_CHROME_HEIGHT - PAGE_FOOTER_CHROME_HEIGHT  // 835px

// Page break gap dimensions for visual separation
export const PAGE_BREAK_GAP_HEIGHT = 48      // Gap between pages
export const PAGE_BREAK_TOTAL_HEIGHT = 96    // Footer chrome + gap + header chrome

// MM to PX conversion at 96 DPI
export const MM_TO_PX = 96 / 25.4  // ~3.78

// A4 dimensions in mm (for reference)
export const A4_WIDTH_MM = 210
export const A4_HEIGHT_MM = 297

// ============================================
// Estimated Node Heights (for layout calculation)
// ============================================
// These are approximate heights used for initial layout estimation
// to avoid expensive DOM measurements on every keystroke.
// Heights include typical margins.

export const ESTIMATED_HEIGHTS = {
  // Block elements
  paragraph: 28,        // ~16px font + 12px margin
  heading1: 52,         // ~28px font + 24px margin
  heading2: 44,         // ~22px font + 22px margin
  heading3: 38,         // ~18px font + 20px margin
  heading4: 34,         // ~16px font + 18px margin
  heading5: 32,         // ~14px font + 18px margin
  heading6: 30,         // ~12px font + 18px margin
  bulletList: 28,       // Per item estimate
  orderedList: 28,      // Per item estimate
  listItem: 28,         // Single list item
  blockquote: 40,       // With padding and border
  codeBlock: 80,        // Minimum code block height
  horizontalRule: 24,   // Divider with margins
  hardPageBreak: 60,    // Hard page break visual height
  table: 100,           // Minimum table height (rows add more)
  tableRow: 36,         // Per row estimate
  image: 200,           // Default image placeholder
  
  // Special
  emptyParagraph: 28,   // Empty paragraph still takes space
  default: 28,          // Fallback for unknown nodes
}

// Line height multiplier for text content
export const LINE_HEIGHT_MULTIPLIER = 1.6

// Average characters per line at default font size
export const CHARS_PER_LINE = 80

// Minimum debounce time for layout calculation (ms)
export const LAYOUT_DEBOUNCE_MIN = 150

// Full recalculation debounce (ms)
export const LAYOUT_DEBOUNCE_FULL = 500

// Maximum pages to calculate in one batch
export const MAX_PAGES_PER_BATCH = 50

