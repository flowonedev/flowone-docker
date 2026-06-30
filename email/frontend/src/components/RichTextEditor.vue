<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import Link from '@tiptap/extension-link'
import Placeholder from '@tiptap/extension-placeholder'
import Superscript from '@tiptap/extension-superscript'
import Subscript from '@tiptap/extension-subscript'
import TextAlign from '@tiptap/extension-text-align'
import Table from '@tiptap/extension-table'
import TableRow from '@tiptap/extension-table-row'
import TableCell from '@tiptap/extension-table-cell'
import TableHeader from '@tiptap/extension-table-header'
import { TextSelection } from '@tiptap/pm/state'
import { isDebugEnabled } from '@/utils/debug'
import { ResizableImageExtension } from '@/extensions/ResizableImageExtension'
import { buildMentionExtension } from '@/extensions/MentionExtension'
import { SignatureExtension } from '@/extensions/SignatureExtension'
import { EmailTextStyle, EmailColor, EmailFontFamily, EmailHighlight } from '@/extensions/EmailColorExtensions'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import { useToastStore } from '@/stores/toast'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useAddons } from '@/composables/useAddons'
import EmailBlockPicker from '@/components/EmailBlockPicker.vue'
import DriveFilePicker from '@/components/DriveFilePicker.vue'
import ColorPickerButton from '@/components/editor/ColorPickerButton.vue'
import EditorSelectionBubble from '@/components/editor/EditorSelectionBubble.vue'
import { TEXT_COLOR_PRESETS, HIGHLIGHT_PRESETS } from '@/components/editor/colorPalettes'
import EmojiPicker from 'vue3-emoji-picker'
import 'vue3-emoji-picker/css'
import api from '@/services/api'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Write your message...',
  },
  compact: {
    type: Boolean,
    default: false,
  },
  showAI: {
    type: Boolean,
    default: true,
  },
  // Gate the toolbar "AI Tools" dropdown (set false when the AI addon is disabled)
  aiEnabled: {
    type: Boolean,
    default: true,
  },
  zenMode: {
    type: Boolean,
    default: false,
  },
  hideToolbar: {
    type: Boolean,
    default: false,
  },
  // Render the formatting toolbar below the content (Gmail-style) instead of on top
  toolbarBottom: {
    type: Boolean,
    default: false,
  },
  // Show only the essential formatting buttons (compose). Hides strikethrough,
  // super/subscript, alignment, quote, code, table and content blocks.
  minimalToolbar: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue'])

const aiStore = useAIStore()
const toast = useToastStore()
const todosStore = useTodosStore()
const { tasksEnabled } = useAddons()

// Show the toolbar "AI Tools" dropdown only when AI is enabled for this editor
const showAITools = computed(() => props.showAI && props.aiEnabled)

const showLinkInput = ref(false)
const linkUrl = ref('')
const imageInput = ref(null)
const uploading = ref(false)
const showImageReplacePicker = ref(false)
const showDrivePickerForImage = ref(false)
const imageReplaceCallback = ref(null)
const imageReplaceInput = ref(null)

const showAIMenu = ref(false)
const aiButtonRef = ref(null)
const aiMenuPosition = ref({ top: '0px', left: '0px' })

// Table menu state
const showTableMenu = ref(false)
const tableButtonRef = ref(null)
const tableMenuPosition = ref({ top: '0px', left: '0px' })

// Block picker state
const showBlockPicker = ref(false)
const blockButtonRef = ref(null)
const blockPickerPosition = ref({ top: '0px', left: '0px' })

// Emoji picker state
const showEmojiPicker = ref(false)
const emojiButtonRef = ref(null)
const emojiPickerPosition = ref({ top: '0px', left: '0px' })
const isDark = ref(document.documentElement.classList.contains('dark'))

// Table floating controls state
const tableFloatingVisible = ref(false)
const tableToolbarPos = ref({ top: '0px', left: '0px' })
const contextInTable = ref(false)
const editorWrapperRef = ref(null)
let scrollableParent = null

// Table visual badges (column widths, table info)
const tableColumnBadges = ref([]) // [{ label, centerX, topY }]
const tableInfoBadge = ref({ width: '100%', layout: 'auto', align: 'middle', top: 0, left: 0 })

// MutationObserver for live badge updates during column resize
let tableResizeObserver = null
const showLayoutInfo = ref(false)
// Top boundary of the editor content area (prevents badges from overlapping form fields)
const badgeEditorTop = ref(null)

// Saved cursor + scroll position - restored before inserting content from menus/pickers
let savedSelectionAnchor = null
let savedSelectionHead = null
let savedScrollTop = null

// Track the last value emitted by onUpdate to prevent the modelValue watch
// from calling setContent (which resets cursor position) when the change
// originated from the editor itself
let lastEmittedHtml = ''

function saveSelection() {
  if (!editor.value) return
  const { anchor, head } = editor.value.state.selection
  savedSelectionAnchor = anchor
  savedSelectionHead = head
  // Save scroll position of the editor's scrollable container
  const scroller = editorWrapperRef.value?.querySelector('.overflow-y-auto')
  savedScrollTop = scroller ? scroller.scrollTop : null
}

function restoreSelection() {
  if (!editor.value || savedSelectionAnchor === null) return
  const docSize = editor.value.state.doc.content.size
  const anchor = Math.min(savedSelectionAnchor, docSize)
  const head = Math.min(savedSelectionHead, docSize)
  
  // Restore selection without scrollIntoView by dispatching transaction directly
  const { state, view } = editor.value
  const tr = state.tr.setSelection(TextSelection.create(state.doc, anchor, head))
  tr.setMeta('addToHistory', false)
  tr.setMeta('preventAutoFocus', true)
  view.dispatch(tr)
  view.focus()
  
  savedSelectionAnchor = null
  savedSelectionHead = null
  // Note: savedScrollTop is NOT cleared here - callers read it for preventScrollJump
}

function preventScrollJump(scrollPos) {
  if (scrollPos === null || scrollPos === undefined) return
  const scroller = editorWrapperRef.value?.querySelector('.overflow-y-auto')
  if (!scroller) { savedScrollTop = null; return }
  // Restore scroll immediately + after next paint (ProseMirror may scroll async)
  scroller.scrollTop = scrollPos
  requestAnimationFrame(() => { scroller.scrollTop = scrollPos })
  // One more tick for good measure (table insertion triggers multiple repaints)
  setTimeout(() => { scroller.scrollTop = scrollPos }, 50)
  savedScrollTop = null
}

// --- Custom TipTap extensions with style attribute support ---
// Extend Table to persist inline styles (width, table-layout, etc.)
const CustomTable = Table.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      style: {
        default: null,
        parseHTML: element => element.getAttribute('style') || null,
        renderHTML: attributes => {
          if (!attributes.style) return {}
          return { style: attributes.style }
        },
      },
    }
  },
})

// Extend TableCell to persist inline styles (vertical-align, etc.)
const CustomTableCell = TableCell.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      style: {
        default: null,
        parseHTML: element => element.getAttribute('style') || null,
        renderHTML: attributes => {
          if (!attributes.style) return {}
          return { style: attributes.style }
        },
      },
    }
  },
})

// Extend TableHeader to persist inline styles
const CustomTableHeader = TableHeader.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      style: {
        default: null,
        parseHTML: element => element.getAttribute('style') || null,
        renderHTML: attributes => {
          if (!attributes.style) return {}
          return { style: attributes.style }
        },
      },
    }
  },
})

// Table settings popout state
const showTableSettings = ref(false)
const tableSettingsPos = ref({ top: '0px', left: '0px' })
const currentTableWidth = ref('100%')
const currentTableLayout = ref('auto')
const currentCellAlign = ref('middle')

// Context menu state
const showContextMenu = ref(false)
const contextMenuPosition = ref({ top: '0px', left: '0px' })
const showContextAIStyles = ref(false)
const hasSelection = ref(false)

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit.configure({
      heading: {
        levels: [1, 2, 3],
      },
    }),
    // TextStyle/Color/FontFamily preserve inline colors and fonts (including
    // legacy <font color>) when an original email is loaded for forward/reply.
    // EmailTextStyle must register before EmailColor (Color depends on textStyle).
    EmailTextStyle,
    EmailColor,
    EmailFontFamily,
    // Highlight (text background color), rendered as a styled <span>.
    EmailHighlight,
    Underline,
    Superscript,
    Subscript,
    TextAlign.configure({
      types: ['heading', 'paragraph'],
    }),
    CustomTable.configure({
      resizable: true,
      HTMLAttributes: {
        class: 'editor-table',
      },
    }),
    TableRow,
    CustomTableCell,
    CustomTableHeader,
    Link.configure({
      openOnClick: false,
      HTMLAttributes: {
        target: '_blank',
        rel: 'noopener noreferrer nofollow',
      },
    }),
    ResizableImageExtension.configure({
      inline: true,
      allowBase64: true,
    }),
    Placeholder.configure({
      placeholder: props.placeholder,
    }),
    // @mention extension — backed by /mentions/suggest. Dispatches a
    // 'mention:committed' DOM event after insert so the surrounding
    // ComposeModal can auto-add the chosen mailbox to To: (if the user
    // setting is on).
    buildMentionExtension(),
    // Preserves the <div data-signature> wrapper so the signature can be
    // visually collapsed in compose.
    SignatureExtension,
  ],
  onSelectionUpdate: () => {
    updateTableFloating()
  },
  onUpdate: ({ editor }) => {
    let html = editor.getHTML()
    html = html.replace(/<p><\/p>/g, '<p><br></p>')
    html = html.replace(
      /(<t[dh][^>]*>[\s\S]*?<img\b)(?![^>]*max-width)([^>]*)(>)/g,
      (match, before, attrs, close) => {
        if (attrs.includes('style="')) {
          return before + attrs.replace('style="', 'style="max-width:100%;height:auto;') + close
        }
        return before + attrs + ' style="max-width:100%;height:auto;"' + close
      }
    )
    lastEmittedHtml = html
    emit('update:modelValue', html)
  },
  editorProps: {
    handlePaste: (view, event, slice) => {
      const items = event.clipboardData?.items
      if (!items) return false
      
      for (const item of items) {
        if (item.type.startsWith('image/')) {
          event.preventDefault()
          const file = item.getAsFile()
          if (file) {
            handleImageFile(file)
          }
          return true
        }
      }
      return false
    },
    handleDrop: (view, event, slice, moved) => {
      if (moved) return false
      
      const files = event.dataTransfer?.files
      if (!files || files.length === 0) return false
      
      for (const file of files) {
        if (file.type.startsWith('image/')) {
          event.preventDefault()
          handleImageFile(file)
          return true
        }
      }
      return false
    },
  },
})

// Handle image file - upload to server and insert URL
async function handleImageFile(file) {
  uploading.value = true
  
  try {
    // First try to upload to server
    const formData = new FormData()
    formData.append('image', file)
    
    const response = await api.post('/message/upload-inline-image', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    
    if (response.data.success) {
      // Insert the uploaded image URL
      editor.value?.chain().focus().setImage({ src: response.data.data.url }).run()
    } else {
      // Fallback to base64 if upload fails
      insertImageAsBase64(file)
    }
  } catch (e) {
    // Fallback to base64 on error
    console.warn('Image upload failed, using base64:', e.message)
    insertImageAsBase64(file)
  } finally {
    uploading.value = false
  }
}

// Fallback: insert image as base64 data URL
function insertImageAsBase64(file) {
  const reader = new FileReader()
  reader.onload = (e) => {
    editor.value?.chain().focus().setImage({ src: e.target.result }).run()
  }
  reader.readAsDataURL(file)
}

// Handle image file input change
function handleImageSelect(e) {
  const file = e.target.files?.[0]
  if (file && file.type.startsWith('image/')) {
    handleImageFile(file)
  }
  e.target.value = ''
}

function onImageReplace(e) {
  imageReplaceCallback.value = e.detail?.updateSrc
  if (imageReplaceCallback.value) {
    showImageReplacePicker.value = true
  }
}

async function handleImageReplaceUpload(e) {
  const file = e.target.files?.[0]
  if (!file || !file.type.startsWith('image/')) return
  e.target.value = ''
  showImageReplacePicker.value = false

  try {
    const formData = new FormData()
    formData.append('image', file)
    const response = await api.post('/message/upload-inline-image', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    if (response.data.success && imageReplaceCallback.value) {
      imageReplaceCallback.value(response.data.data.url)
    }
  } catch (err) {
    if (imageReplaceCallback.value) {
      const reader = new FileReader()
      reader.onload = (ev) => imageReplaceCallback.value(ev.target.result)
      reader.readAsDataURL(file)
    }
  }
}

async function handleDriveImageSelect(file) {
  showDrivePickerForImage.value = false
  showImageReplacePicker.value = false
  if (!file || !imageReplaceCallback.value) return

  try {
    // Download the drive file as a blob, then re-upload as inline image
    const dlResponse = await api.get(`/drive/files/${file.id}/preview`, { responseType: 'blob' })
    const blob = dlResponse.data
    const formData = new FormData()
    formData.append('image', blob, file.original_name || 'drive-image.jpg')
    const uploadResponse = await api.post('/message/upload-inline-image', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    if (uploadResponse.data.success) {
      imageReplaceCallback.value(uploadResponse.data.data.url)
      return
    }
  } catch (err) {
    console.warn('Drive image upload failed, falling back to preview URL:', err.message)
  }
  // Fallback: use preview endpoint directly (works in-app but not in emails)
  imageReplaceCallback.value(`/api/drive/files/${file.id}/preview`)
}

function promptImageUrl() {
  showImageReplacePicker.value = false
  const url = prompt('Paste image URL:')
  if (url && imageReplaceCallback.value) {
    imageReplaceCallback.value(url)
  }
}

watch(() => props.modelValue, (value) => {
  // If this is the same value we just emitted from onUpdate, skip —
  // calling setContent here would reset the cursor position
  if (value === lastEmittedHtml) return

  // External change (AI rewrite, template insert, etc.) — update editor content
  if (editor.value && value !== (editor.value.getHTML() || '')) {
    editor.value.commands.setContent(value, false)
  }
})

defineExpose({ editor })

onBeforeUnmount(() => {
  editor.value?.destroy()
  document.removeEventListener('keydown', handleEscapeKey)
  if (editorWrapperRef.value) {
    editorWrapperRef.value.removeEventListener('image-replace', onImageReplace)
  }
  if (scrollableParent) {
    scrollableParent.removeEventListener('scroll', updateTableFloating)
    scrollableParent = null
  }
  if (tableResizeObserver) {
    tableResizeObserver.disconnect()
    tableResizeObserver = null
  }
})

// Close context menu on Escape
function handleEscapeKey(e) {
  if (e.key === 'Escape' && showContextMenu.value) {
    closeContextMenu()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleEscapeKey)
  nextTick(() => {
    if (editorWrapperRef.value) {
      scrollableParent = editorWrapperRef.value.querySelector('.overflow-y-auto')
      if (scrollableParent) {
        scrollableParent.addEventListener('scroll', updateTableFloating, { passive: true })
      }
      editorWrapperRef.value.addEventListener('image-replace', onImageReplace)
    }
  })
})

function setLink() {
  if (linkUrl.value) {
    editor.value?.chain().focus().extendMarkRange('link').setLink({ href: linkUrl.value }).run()
  } else {
    editor.value?.chain().focus().unsetLink().run()
  }
  showLinkInput.value = false
  linkUrl.value = ''
}

function openLinkInput() {
  const previousUrl = editor.value?.getAttributes('link').href
  linkUrl.value = previousUrl || ''
  showLinkInput.value = true
}

// --- Text color / highlight ---
function applyTextColor(color) {
  const chain = editor.value?.chain().focus()
  if (!chain) return
  if (color) chain.setColor(color).run()
  else chain.unsetColor().run()
}

function applyHighlight(color) {
  const chain = editor.value?.chain().focus()
  if (!chain) return
  if (color) chain.setHighlight({ color }).run()
  else chain.unsetHighlight().run()
}

// Combined selection bubble -> create a quick to-do from the selected text.
async function handleAddToTodo() {
  if (!tasksEnabled.value) return
  const sel = editor.value?.state.selection
  if (!sel || sel.empty) return
  const text = editor.value.state.doc.textBetween(sel.from, sel.to, ' ').trim()
  if (!text) return
  const title = text.length > 200 ? text.slice(0, 200) : text
  const todo = await todosStore.createTodo({ title })
  if (todo) {
    toast.success('Added to To-do')
    todosStore.openPanel()
  }
}

const toolbarButtons = [
  { icon: 'format_bold', action: () => editor.value?.chain().focus().toggleBold().run(), isActive: () => editor.value?.isActive('bold'), title: 'Bold' },
  { icon: 'format_italic', action: () => editor.value?.chain().focus().toggleItalic().run(), isActive: () => editor.value?.isActive('italic'), title: 'Italic' },
  { icon: 'format_underlined', action: () => editor.value?.chain().focus().toggleUnderline().run(), isActive: () => editor.value?.isActive('underline'), title: 'Underline' },
  { icon: 'strikethrough_s', action: () => editor.value?.chain().focus().toggleStrike().run(), isActive: () => editor.value?.isActive('strike'), title: 'Strikethrough' },
  { icon: 'superscript', action: () => editor.value?.chain().focus().toggleSuperscript().run(), isActive: () => editor.value?.isActive('superscript'), title: 'Superscript' },
  { icon: 'subscript', action: () => editor.value?.chain().focus().toggleSubscript().run(), isActive: () => editor.value?.isActive('subscript'), title: 'Subscript' },
  { divider: true },
  { icon: 'format_list_bulleted', action: () => editor.value?.chain().focus().toggleBulletList().run(), isActive: () => editor.value?.isActive('bulletList'), title: 'Bullet list' },
  { icon: 'format_list_numbered', action: () => editor.value?.chain().focus().toggleOrderedList().run(), isActive: () => editor.value?.isActive('orderedList'), title: 'Numbered list' },
  { divider: true },
  { icon: 'format_align_left', action: () => editor.value?.chain().focus().setTextAlign('left').run(), isActive: () => editor.value?.isActive({ textAlign: 'left' }), title: 'Align left' },
  { icon: 'format_align_center', action: () => editor.value?.chain().focus().setTextAlign('center').run(), isActive: () => editor.value?.isActive({ textAlign: 'center' }), title: 'Align center' },
  { icon: 'format_align_right', action: () => editor.value?.chain().focus().setTextAlign('right').run(), isActive: () => editor.value?.isActive({ textAlign: 'right' }), title: 'Align right' },
  { icon: 'format_align_justify', action: () => editor.value?.chain().focus().setTextAlign('justify').run(), isActive: () => editor.value?.isActive({ textAlign: 'justify' }), title: 'Justify' },
  { divider: true },
  { icon: 'format_quote', action: () => editor.value?.chain().focus().toggleBlockquote().run(), isActive: () => editor.value?.isActive('blockquote'), title: 'Block quote' },
  { icon: 'code', action: () => editor.value?.chain().focus().toggleCode().run(), isActive: () => editor.value?.isActive('code'), title: 'Inline code' },
  { divider: true },
  { icon: 'link', action: openLinkInput, isActive: () => editor.value?.isActive('link'), title: 'Insert link' },
  { icon: 'image', action: () => imageInput.value?.click(), title: 'Insert image' },
  { divider: true },
  { icon: 'format_clear', action: () => editor.value?.chain().focus().unsetAllMarks().clearNodes().run(), title: 'Clear formatting' },
  { divider: true },
  { icon: 'undo', action: () => editor.value?.chain().focus().undo().run(), title: 'Undo' },
  { icon: 'redo', action: () => editor.value?.chain().focus().redo().run(), title: 'Redo' },
]

// Essential-only set for compose (matches the slim mockup toolbar).
// Link + image stay here; emoji / clear / undo / redo are rendered after the
// emoji button so the grouping matches the design.
const minimalToolbarButtons = [
  { icon: 'format_bold', action: () => editor.value?.chain().focus().toggleBold().run(), isActive: () => editor.value?.isActive('bold'), title: 'Bold' },
  { icon: 'format_italic', action: () => editor.value?.chain().focus().toggleItalic().run(), isActive: () => editor.value?.isActive('italic'), title: 'Italic' },
  { icon: 'format_underlined', action: () => editor.value?.chain().focus().toggleUnderline().run(), isActive: () => editor.value?.isActive('underline'), title: 'Underline' },
  { divider: true },
  { icon: 'format_list_bulleted', action: () => editor.value?.chain().focus().toggleBulletList().run(), isActive: () => editor.value?.isActive('bulletList'), title: 'Bullet list' },
  { icon: 'format_list_numbered', action: () => editor.value?.chain().focus().toggleOrderedList().run(), isActive: () => editor.value?.isActive('orderedList'), title: 'Numbered list' },
  { divider: true },
  { icon: 'link', action: openLinkInput, isActive: () => editor.value?.isActive('link'), title: 'Insert link' },
  { icon: 'image', action: () => imageInput.value?.click(), title: 'Insert image' },
]

// Table menu toggle with position calculation
function toggleTableMenu() {
  if (showTableMenu.value) {
    showTableMenu.value = false
    return
  }
  
  saveSelection()
  
  if (tableButtonRef.value) {
    const rect = tableButtonRef.value.getBoundingClientRect()
    tableMenuPosition.value = {
      top: `${rect.bottom + 4}px`,
      left: `${Math.max(8, rect.left)}px`
    }
  }
  showTableMenu.value = true
}

function insertTable(rows = 3, cols = 3) {
  const scrollPos = savedScrollTop
  restoreSelection()
  editor.value?.chain().focus().insertTable({ rows, cols, withHeaderRow: true }).run()
  showTableMenu.value = false
  // Prevent scroll jump after insertion
  preventScrollJump(scrollPos)
}

function addColumnBefore() {
  editor.value?.chain().focus().addColumnBefore().run()
  showTableMenu.value = false
}

function addColumnAfter() {
  editor.value?.chain().focus().addColumnAfter().run()
  showTableMenu.value = false
}

function deleteColumn() {
  editor.value?.chain().focus().deleteColumn().run()
  showTableMenu.value = false
}

function addRowBefore() {
  editor.value?.chain().focus().addRowBefore().run()
  showTableMenu.value = false
}

function addRowAfter() {
  editor.value?.chain().focus().addRowAfter().run()
  showTableMenu.value = false
}

function deleteRow() {
  editor.value?.chain().focus().deleteRow().run()
  showTableMenu.value = false
}

function deleteTable() {
  editor.value?.chain().focus().deleteTable().run()
  showTableMenu.value = false
}

function mergeCells() {
  editor.value?.chain().focus().mergeCells().run()
  showTableMenu.value = false
}

function splitCell() {
  editor.value?.chain().focus().splitCell().run()
  showTableMenu.value = false
}

// Check if cursor is currently inside a table
function isInTable() {
  return editor.value?.isActive('table') || false
}

// --- Table floating controls ---
function findTableDOM() {
  if (!editor.value || !editor.value.isActive('table')) return null
  try {
    const { $from } = editor.value.state.selection
    let depth = $from.depth
    while (depth > 0) {
      if ($from.node(depth).type.name === 'table') break
      depth--
    }
    if (depth === 0) return null
    const dom = editor.value.view.nodeDOM($from.before(depth))
    return dom?.nodeType === 1 ? dom : null
  } catch { return null }
}

function setupTableResizeObserver(tableEl) {
  if (tableResizeObserver) tableResizeObserver.disconnect()
  if (!tableEl) return

  tableResizeObserver = new MutationObserver(() => {
    // Re-compute badges when colgroup widths change during drag-resize
    updateTableBadgesOnly()
  })
  tableResizeObserver.observe(tableEl, {
    attributes: true,
    attributeFilter: ['style'],
    subtree: true,
    childList: true,
  })
}

// Lightweight badge-only update (no position recalc) for live resize
function updateTableBadgesOnly() {
  const tableEl = findTableDOM()
  if (!tableEl) return

  const rect = tableEl.getBoundingClientRect()
  const firstRow = tableEl.querySelector('tr')
  if (!firstRow) return

  const cells = firstRow.querySelectorAll('td, th')
  const colgroup = tableEl.querySelector('colgroup')
  const colElements = colgroup ? colgroup.querySelectorAll('col') : []

  const foundNode = findTableNodeAndPos()
  const isFixed = foundNode
    ? (parseStyleString(foundNode.node.attrs.style || '')['table-layout'] === 'fixed')
    : false

  const badges = []
  cells.forEach((cell, i) => {
    const cellRect = cell.getBoundingClientRect()
    let widthLabel = 'auto'

    if (isFixed) {
      // Fixed layout: show equal % (cell widths are set explicitly for email compat)
      const equalPct = Math.round(100 / cells.length)
      widthLabel = `${equalPct}%`
    } else if (colElements[i] && colElements[i].style.width) {
      const raw = colElements[i].style.width
      if (raw.endsWith('px')) {
        const pct = Math.round((parseFloat(raw) / rect.width) * 100)
        widthLabel = `${pct}%`
      } else {
        widthLabel = raw
      }
    } else {
      const pct = Math.round((cellRect.width / rect.width) * 100)
      widthLabel = `~${pct}%`
    }

    badges.push({
      label: widthLabel,
      centerX: cellRect.left + cellRect.width / 2,
      topY: rect.top,
    })
  })
  tableColumnBadges.value = badges
}

let lastObservedTable = null

function updateTableFloating() {
  const tableEl = findTableDOM()
  if (!tableEl) {
    tableFloatingVisible.value = false
    tableColumnBadges.value = []
    if (tableResizeObserver) { tableResizeObserver.disconnect(); lastObservedTable = null }
    return
  }

  // Setup observer when entering a new table
  if (tableEl !== lastObservedTable) {
    setupTableResizeObserver(tableEl)
    lastObservedTable = tableEl
  }

  const rect = tableEl.getBoundingClientRect()
  const viewportHeight = window.innerHeight
  const toolbarHeight = 40
  const toolbarWidth = 320

  // Compute editor content area top for badge clamping
  const editorContentEl = editorWrapperRef.value?.querySelector('.overflow-y-auto')
  if (editorContentEl) {
    badgeEditorTop.value = editorContentEl.getBoundingClientRect().top
  }

  // Hide if table is scrolled out of view
  if (rect.bottom < 0 || rect.top > viewportHeight) {
    tableFloatingVisible.value = false
    tableColumnBadges.value = []
    return
  }

  // Prefer bottom, fallback to top if bottom is out of viewport
  let top
  if (rect.bottom + toolbarHeight + 8 < viewportHeight) {
    top = rect.bottom + 6
  } else {
    top = rect.top - toolbarHeight - 6
  }

  // Center horizontally, clamp to viewport
  let left = rect.left + (rect.width - toolbarWidth) / 2
  left = Math.max(8, Math.min(left, window.innerWidth - toolbarWidth - 8))

  tableToolbarPos.value = { top: `${top}px`, left: `${left}px` }
  tableFloatingVisible.value = true

  // --- Compute column width badges ---
  const firstRow = tableEl.querySelector('tr')
  if (firstRow) {
    const cells = firstRow.querySelectorAll('td, th')
    const colgroup = tableEl.querySelector('colgroup')
    const colElements = colgroup ? colgroup.querySelectorAll('col') : []
    const badges = []

    // Check if table is in fixed layout mode (from ProseMirror attrs)
    const foundNode = findTableNodeAndPos()
    const isFixed = foundNode 
      ? (parseStyleString(foundNode.node.attrs.style || '')['table-layout'] === 'fixed') 
      : false

    cells.forEach((cell, i) => {
      const cellRect = cell.getBoundingClientRect()
      let widthLabel = 'auto'

      if (isFixed) {
        // Fixed layout: equal columns (explicit widths are on cells for email compat)
        const equalPct = Math.round(100 / cells.length)
        widthLabel = `${equalPct}%`
      } else if (colElements[i] && colElements[i].style.width) {
        // Explicit width from TipTap drag-resize
        const raw = colElements[i].style.width
        if (raw.endsWith('px')) {
          const pct = Math.round((parseFloat(raw) / rect.width) * 100)
          widthLabel = `${pct}%`
        } else {
          widthLabel = raw
        }
      } else {
        // Compute from rendered width
        const pct = Math.round((cellRect.width / rect.width) * 100)
        widthLabel = `~${pct}%`
      }

      badges.push({
        label: widthLabel,
        centerX: cellRect.left + cellRect.width / 2,
        topY: rect.top,
      })
    })
    tableColumnBadges.value = badges
  }

  // --- Table info badge (read from ProseMirror source of truth) ---
  const found = findTableNodeAndPos()
  if (found) {
    const tableStyle = parseStyleString(found.node.attrs.style || '')
    let cellAlign = 'middle'
    let foundAlign = false
    found.node.descendants((node) => {
      if (!foundAlign && (node.type.name === 'tableCell' || node.type.name === 'tableHeader')) {
        const cs = parseStyleString(node.attrs.style || '')
        cellAlign = cs['vertical-align'] || 'middle'
        foundAlign = true
        return false
      }
    })
    tableInfoBadge.value = {
      width: tableStyle['width'] || '100%',
      layout: tableStyle['table-layout'] || 'auto',
      align: cellAlign,
      top: rect.top,
      left: rect.left,
    }
  }
}

// Quick table operations (keep floating toolbar open)
function quickAddRow() {
  editor.value?.chain().focus().addRowAfter().run()
  requestAnimationFrame(() => updateTableFloating())
}
function quickDeleteRow() {
  editor.value?.chain().focus().deleteRow().run()
  requestAnimationFrame(() => updateTableFloating())
}
function quickAddCol() {
  editor.value?.chain().focus().addColumnAfter().run()
  requestAnimationFrame(() => updateTableFloating())
}
function quickDeleteCol() {
  editor.value?.chain().focus().deleteColumn().run()
  requestAnimationFrame(() => updateTableFloating())
}
function quickMergeCells() {
  editor.value?.chain().focus().mergeCells().run()
}
function quickSplitCell() {
  editor.value?.chain().focus().splitCell().run()
}
function quickDeleteTable() {
  editor.value?.chain().focus().deleteTable().run()
  tableFloatingVisible.value = false
  showTableSettings.value = false
}

// --- Table settings (width, layout, vertical align) ---
function parseStyleString(styleStr) {
  const styles = {}
  if (!styleStr) return styles
  styleStr.split(';').filter(s => s.trim()).forEach(s => {
    const colonIdx = s.indexOf(':')
    if (colonIdx !== -1) {
      const key = s.substring(0, colonIdx).trim()
      const val = s.substring(colonIdx + 1).trim()
      if (key) styles[key] = val
    }
  })
  return styles
}

function buildStyleString(styles) {
  return Object.entries(styles)
    .filter(([_, v]) => v !== null && v !== undefined && v !== '')
    .map(([k, v]) => `${k}: ${v}`)
    .join('; ')
}

function findTableNodeAndPos() {
  if (!editor.value) return null
  const { state } = editor.value
  const { $from } = state.selection
  let depth = $from.depth
  while (depth > 0) {
    if ($from.node(depth).type.name === 'table') break
    depth--
  }
  if (depth === 0) return null
  return { node: $from.node(depth), pos: $from.before(depth) }
}

function readCurrentTableProps() {
  // Read from ProseMirror node attrs (source of truth), not DOM
  const found = findTableNodeAndPos()
  if (!found) return

  const { node: tableNode } = found
  const tableStyle = parseStyleString(tableNode.attrs.style || '')

  currentTableWidth.value = tableStyle['width'] || '100%'
  currentTableLayout.value = tableStyle['table-layout'] || 'auto'

  // Read cell alignment from first cell in ProseMirror model
  let foundAlign = false
  tableNode.descendants((node) => {
    if (!foundAlign && (node.type.name === 'tableCell' || node.type.name === 'tableHeader')) {
      const cellStyle = parseStyleString(node.attrs.style || '')
      currentCellAlign.value = cellStyle['vertical-align'] || 'middle'
      foundAlign = true
      return false // stop
    }
  })
  if (!foundAlign) currentCellAlign.value = 'middle'
}

function toggleTableSettings() {
  if (showTableSettings.value) {
    showTableSettings.value = false
    return
  }

  readCurrentTableProps()

  // Position above the floating toolbar
  const tableEl = findTableDOM()
  if (tableEl) {
    const rect = tableEl.getBoundingClientRect()
    const popupWidth = 260
    const popupHeight = 200
    let left = rect.left + (rect.width - popupWidth) / 2
    left = Math.max(8, Math.min(left, window.innerWidth - popupWidth - 8))
    let top = rect.top - popupHeight - 8
    if (top < 8) top = rect.bottom + 50
    tableSettingsPos.value = { top: `${top}px`, left: `${left}px` }
  }
  showTableSettings.value = true
}

function setTableStyleProp(prop, value) {
  const found = findTableNodeAndPos()
  if (!found || !editor.value) return
  const { node, pos } = found
  const { state, view } = editor.value

  const styles = parseStyleString(node.attrs.style || '')
  if (value === null || value === undefined || value === '') {
    delete styles[prop]
  } else {
    styles[prop] = value
  }
  const newStyle = buildStyleString(styles) || null

  const tr = state.tr.setNodeMarkup(pos, undefined, {
    ...node.attrs,
    style: newStyle,
  })
  view.dispatch(tr)
}

function setTableWidth(width) {
  setTableStyleProp('width', width)
  currentTableWidth.value = width
}

function setTableLayout(layout) {
  setTableStyleProp('table-layout', layout)
  currentTableLayout.value = layout

  if (layout === 'fixed') {
    // Clear all colwidth attributes so table-layout:fixed actually makes equal columns
    clearAllColwidths()
    // Set explicit equal-width percentages on each cell for email client compatibility
    setEqualCellWidths()
  } else {
    // Clear explicit cell widths when switching to auto
    clearCellWidthStyles()
  }
  requestAnimationFrame(() => updateTableFloating())
}

function clearAllColwidths() {
  const found = findTableNodeAndPos()
  if (!found || !editor.value) return
  const { node: tableNode, pos: tablePos } = found
  const { state, view } = editor.value

  const cells = []
  tableNode.descendants((node, pos) => {
    if (node.type.name === 'tableCell' || node.type.name === 'tableHeader') {
      if (node.attrs.colwidth) {
        cells.push({ node, pos: tablePos + 1 + pos })
      }
    }
  })

  if (cells.length === 0) return

  let tr = state.tr
  for (const cell of cells) {
    tr = tr.setNodeMarkup(cell.pos, undefined, {
      ...cell.node.attrs,
      colwidth: null,
    })
  }
  view.dispatch(tr)
}

function setEqualCellWidths() {
  const found = findTableNodeAndPos()
  if (!found || !editor.value) return
  const { node: tableNode, pos: tablePos } = found
  const { state, view } = editor.value

  // Count columns from first row
  let colCount = 0
  tableNode.forEach((row, _, rowIndex) => {
    if (rowIndex === 0) {
      row.forEach(() => { colCount++ })
    }
  })
  if (colCount === 0) return

  const equalPct = `${(100 / colCount).toFixed(2)}%`

  // Apply width to every cell
  const cells = []
  tableNode.descendants((node, pos) => {
    if (node.type.name === 'tableCell' || node.type.name === 'tableHeader') {
      cells.push({ node, pos: tablePos + 1 + pos })
    }
  })

  let tr = state.tr
  for (const cell of cells) {
    const styles = parseStyleString(cell.node.attrs.style || '')
    styles['width'] = equalPct
    const newStyle = buildStyleString(styles)
    tr = tr.setNodeMarkup(cell.pos, undefined, {
      ...cell.node.attrs,
      style: newStyle,
    })
  }
  view.dispatch(tr)
}

function clearCellWidthStyles() {
  const found = findTableNodeAndPos()
  if (!found || !editor.value) return
  const { node: tableNode, pos: tablePos } = found
  const { state, view } = editor.value

  const cells = []
  tableNode.descendants((node, pos) => {
    if (node.type.name === 'tableCell' || node.type.name === 'tableHeader') {
      const styles = parseStyleString(node.attrs.style || '')
      if (styles['width']) {
        cells.push({ node, pos: tablePos + 1 + pos })
      }
    }
  })

  if (cells.length === 0) return

  let tr = state.tr
  for (const cell of cells) {
    const styles = parseStyleString(cell.node.attrs.style || '')
    delete styles['width']
    const newStyle = buildStyleString(styles) || null
    tr = tr.setNodeMarkup(cell.pos, undefined, {
      ...cell.node.attrs,
      style: newStyle,
    })
  }
  view.dispatch(tr)
}

function setCellVerticalAlign(align) {
  const found = findTableNodeAndPos()
  if (!found || !editor.value) return
  const { node: tableNode, pos: tablePos } = found
  const { state, view } = editor.value

  // Collect all cell positions
  const cells = []
  tableNode.descendants((node, pos) => {
    if (node.type.name === 'tableCell' || node.type.name === 'tableHeader') {
      cells.push({ node, pos: tablePos + 1 + pos })
    }
  })

  // Apply vertical-align to all cells in one transaction
  let tr = state.tr
  for (const cell of cells) {
    const styles = parseStyleString(cell.node.attrs.style || '')
    styles['vertical-align'] = align
    const newStyle = buildStyleString(styles)
    tr = tr.setNodeMarkup(cell.pos, undefined, {
      ...cell.node.attrs,
      style: newStyle,
    })
  }

  view.dispatch(tr)
  currentCellAlign.value = align
}

// Block picker toggle with position calculation
function toggleBlockPicker() {
  if (showBlockPicker.value) {
    showBlockPicker.value = false
    return
  }
  
  saveSelection()
  
  if (blockButtonRef.value) {
    const rect = blockButtonRef.value.getBoundingClientRect()
    const viewportWidth = window.innerWidth
    const pickerWidth = 416 // 26rem
    // Position picker so it doesn't overflow viewport
    let left = rect.left
    if (left + pickerWidth > viewportWidth - 16) {
      left = viewportWidth - pickerWidth - 16
    }
    blockPickerPosition.value = {
      top: `${rect.bottom + 4}px`,
      left: `${Math.max(8, left)}px`
    }
  }
  showBlockPicker.value = true
}

function handleBlockInsert(block) {
  if (!editor.value) return
  const scrollPos = savedScrollTop
  restoreSelection()

  let html = typeof block === 'string' ? block : block.html_content
  if (typeof block === 'object' && block.id) {
    const blockId = 'blk-' + crypto.randomUUID().split('-')[0]
    const bType = encodeURIComponent((block.category || 'custom').replace(/"/g, ''))
    const bName = encodeURIComponent((block.name || 'Block').replace(/"/g, ''))
    const fragment = `__blk=${blockId},${bType},${bName}`

    // Append block tracking fragment to every href in this block
    html = html.replace(/href=(["'])([^"']+)\1/gi, (m, q, url) => {
      const sep = url.includes('#') ? '&' : '#'
      return `href=${q}${url}${sep}${fragment}${q}`
    })
  }

  editor.value.chain().focus().insertContent(html).run()
  showBlockPicker.value = false
  preventScrollJump(scrollPos)
}

// Emoji picker toggle with position calculation
function toggleEmojiPicker() {
  if (showEmojiPicker.value) {
    showEmojiPicker.value = false
    return
  }
  
  saveSelection()
  
  // Update dark mode state
  isDark.value = document.documentElement.classList.contains('dark')
  
  if (emojiButtonRef.value) {
    const rect = emojiButtonRef.value.getBoundingClientRect()
    const viewportWidth = window.innerWidth
    const pickerWidth = 352
    let left = rect.left
    if (left + pickerWidth > viewportWidth - 16) {
      left = viewportWidth - pickerWidth - 16
    }
    emojiPickerPosition.value = {
      top: `${rect.bottom + 4}px`,
      left: `${Math.max(8, left)}px`
    }
  }
  showEmojiPicker.value = true
}

function onSelectEmoji(emoji) {
  if (!editor.value) return
  const scrollPos = savedScrollTop
  restoreSelection()
  editor.value.chain().focus().insertContent(emoji.i).run()
  preventScrollJump(scrollPos)
}

// AI Menu toggle with position calculation
function toggleAIMenu() {
  if (showAIMenu.value) {
    showAIMenu.value = false
    return
  }
  
  // Calculate position based on button location (right-aligned, clamped to viewport).
  // The toolbar usually sits near the bottom of the screen in compose, so flip the
  // menu upward when there isn't enough room below it.
  if (aiButtonRef.value) {
    const rect = aiButtonRef.value.getBoundingClientRect()
    const menuWidth = 208 // w-52
    const styleCount = Object.keys(aiStore.styles || {}).length
    const estHeight = styleCount * 40 + 52 // rows + header
    let left = rect.right - menuWidth
    left = Math.max(8, Math.min(left, window.innerWidth - menuWidth - 8))
    const openUp = rect.bottom + estHeight + 8 > window.innerHeight
    const top = openUp ? Math.max(8, rect.top - estHeight - 4) : rect.bottom + 4
    aiMenuPosition.value = {
      top: `${top}px`,
      left: `${left}px`
    }
  }
  showAIMenu.value = true
}

// Context menu handlers
function handleContextMenu(event) {
  const selection = editor.value?.state.selection
  const inTable = editor.value?.isActive('table') || false
  contextInTable.value = inTable

  const hasText = selection && !selection.empty
  hasSelection.value = hasText

  // Show custom context menu if text is selected OR cursor is in a table
  if (!hasText && !inTable) {
    return // Let default context menu show
  }
  
  event.preventDefault()
  showContextAIStyles.value = false
  
  contextMenuPosition.value = {
    top: `${event.clientY}px`,
    left: `${event.clientX}px`
  }
  showContextMenu.value = true
}

function closeContextMenu() {
  showContextMenu.value = false
  showContextAIStyles.value = false
}

function handleCopy() {
  document.execCommand('copy')
  closeContextMenu()
  toast.success('Copied to clipboard')
}

async function handlePaste() {
  try {
    const text = await navigator.clipboard.readText()
    editor.value?.commands.insertContent(text)
    closeContextMenu()
  } catch (e) {
    // Fallback
    document.execCommand('paste')
    closeContextMenu()
  }
}

async function rewriteSelection(style) {
  const { from, to } = editor.value?.state.selection || {}
  if (from === undefined || to === undefined || from === to) {
    toast.warning('Please select some text first')
    return
  }
  
  // Get selected text
  const selectedText = editor.value?.state.doc.textBetween(from, to, ' ')
  if (!selectedText?.trim()) {
    toast.warning('Please select some text first')
    return
  }
  
  closeContextMenu()
  
  const result = await aiStore.rewrite(selectedText, style)
  
  if (result.success) {
    // Replace only the selected text
    editor.value?.chain()
      .focus()
      .deleteRange({ from, to })
      .insertContent(result.rewritten)
      .run()
    
    emit('update:modelValue', editor.value?.getHTML())
    toast.success('Selection rewritten')
  } else {
    toast.error(result.error || 'Failed to rewrite text')
  }
}

// AI Rewrite functions
function stripHtml(html) {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html, 'text/html')
  return doc.body.textContent || ''
}

async function rewriteWithAI(style) {
  const html = editor.value?.getHTML()
  isDebugEnabled() && console.log('Rewrite - HTML:', html)
  const text = stripHtml(html)
  isDebugEnabled() && console.log('Rewrite - Text:', text)
  
  if (!text.trim()) {
    toast.warning('Please write some text first')
    return
  }
  
  showAIMenu.value = false
  
  // Detect and preserve signature (text after -- or --)
  let bodyText = text
  let signature = ''
  
  // Look for signature separator (-- followed by newline, or -- at end)
  const signatureSeparators = ['\n-- \n', '\n--\n', '\n-- ', '\n--']
  for (const sep of signatureSeparators) {
    const sepIndex = text.indexOf(sep)
    if (sepIndex !== -1) {
      bodyText = text.substring(0, sepIndex).trim()
      signature = text.substring(sepIndex) // Keep the separator and signature
      break
    }
  }
  
  if (!bodyText.trim()) {
    toast.warning('Please write some text before the signature')
    return
  }
  
  const result = await aiStore.rewrite(bodyText, style)
  
  if (result.success) {
    // Combine rewritten text with preserved signature
    let finalText = result.rewritten
    if (signature) {
      finalText = result.rewritten.trim() + signature
    }
    
    // Convert plain text back to HTML paragraphs
    const paragraphs = finalText.split('\n\n').filter(p => p.trim())
    const htmlContent = paragraphs.map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('')
    editor.value?.commands.setContent(htmlContent)
    emit('update:modelValue', htmlContent)
    toast.success('Text rewritten (signature preserved)')
  } else {
    toast.error(result.error || 'Failed to rewrite text')
  }
}
</script>

<template>
  <div ref="editorWrapperRef" :class="['border border-surface-200 dark:border-[rgb(var(--color-border-strong))] rounded-xl overflow-hidden bg-white dark:bg-[rgb(var(--color-surface))] relative', zenMode ? 'flex flex-col h-full' : (toolbarBottom ? 'flex flex-col' : '')]">
    <!-- Toolbar (hidden in minimal/mobile mode) -->
    <div v-if="!hideToolbar" :class="['flex items-center gap-1 px-2 py-1.5 bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] flex-wrap', toolbarBottom ? 'order-2 border-t border-surface-200 dark:border-[rgb(var(--color-border))]' : 'border-b border-surface-200 dark:border-[rgb(var(--color-border))]']">
      <template v-for="(button, i) in (minimalToolbar ? minimalToolbarButtons : toolbarButtons)" :key="i">
        <div v-if="button.divider" class="w-px h-5 bg-surface-200 dark:bg-[rgb(var(--color-border))] mx-0.5"></div>
        <button
          v-else
          @click="button.action"
          :class="[
            'p-1.5 rounded-lg transition-colors',
            button.isActive?.() 
              ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400' 
              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-700 dark:hover:text-surface-200'
          ]"
          :title="button.title || button.icon"
        >
          <span class="material-symbols-rounded text-lg">{{ button.icon }}</span>
        </button>
      </template>

      <!-- Text color + Highlight -->
      <div class="w-px h-5 bg-surface-200 dark:bg-[rgb(var(--color-border))] mx-0.5"></div>
      <ColorPickerButton
        icon="format_color_text"
        title="Text color"
        panel-title="Text color"
        remove-label="Auto"
        :presets="TEXT_COLOR_PRESETS"
        :indicator-color="editor?.getAttributes('textStyle')?.color || null"
        @select="applyTextColor"
      />
      <ColorPickerButton
        icon="format_color_fill"
        title="Highlight color"
        panel-title="Highlight"
        remove-label="None"
        :presets="HIGHLIGHT_PRESETS"
        :indicator-color="editor?.getAttributes('highlight')?.color || null"
        @select="applyHighlight"
      />
      
      <!-- Table button with dropdown -->
      <div v-if="!minimalToolbar" class="w-px h-5 bg-surface-200 dark:bg-[rgb(var(--color-border))] mx-0.5"></div>
        <div v-if="!minimalToolbar" class="relative">
          <button
          ref="tableButtonRef"
          @click.stop="toggleTableMenu"
            :class="[
              'p-1.5 rounded-lg transition-colors',
            isInTable()
              ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400' 
              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-700 dark:hover:text-surface-200'
            ]"
          title="Table"
          >
          <span class="material-symbols-rounded text-lg">table_chart</span>
          </button>
          
        <!-- Table Menu - teleported to body -->
          <Teleport to="body">
          <div v-if="showTableMenu">
              <!-- Backdrop -->
              <div 
                class="fixed inset-0 z-[10000]"
              @click="showTableMenu = false"
              ></div>
              
            <!-- Table Menu -->
            <div 
              class="fixed w-52 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-[10001]"
              :style="tableMenuPosition"
            >
              <!-- Insert new table -->
              <template v-if="!isInTable()">
                <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase">Insert table</p>
                <button @click="insertTable(2, 2)" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">grid_on</span>
                  2 × 2
                </button>
                <button @click="insertTable(3, 3)" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">grid_on</span>
                  3 × 3
                </button>
                <button @click="insertTable(4, 4)" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">grid_on</span>
                  4 × 4
                </button>
                <button @click="insertTable(2, 3)" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">view_column</span>
                  2 rows × 3 cols
                </button>
              </template>
              
              <!-- Edit existing table -->
              <template v-else>
                <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase">Columns</p>
                <button @click="addColumnBefore" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">arrow_back</span>
                  Add column before
                </button>
                <button @click="addColumnAfter" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">arrow_forward</span>
                  Add column after
                </button>
                <button @click="deleteColumn" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">remove_circle_outline</span>
                  Delete column
                </button>
                
                <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
                
                <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase">Rows</p>
                <button @click="addRowBefore" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">arrow_upward</span>
                  Add row before
                </button>
                <button @click="addRowAfter" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">arrow_downward</span>
                  Add row after
                </button>
                <button @click="deleteRow" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">remove_circle_outline</span>
                  Delete row
                </button>
                
                <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
                
                <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase">Cells</p>
                <button @click="mergeCells" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">merge</span>
                  Merge cells
                </button>
                <button @click="splitCell" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">splitscreen</span>
                  Split cell
                </button>
                
                <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
                
                <button @click="deleteTable" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">delete</span>
                  Delete table
                </button>
              </template>
            </div>
          </div>
        </Teleport>
      </div>
      
      <!-- Block Picker button -->
      <div v-if="!minimalToolbar" class="w-px h-5 bg-surface-200 dark:bg-[rgb(var(--color-border))] mx-0.5"></div>
      <div v-if="!minimalToolbar" class="relative">
                <button
          ref="blockButtonRef"
          @click.stop="toggleBlockPicker"
          :class="[
            'p-1.5 rounded-lg transition-colors',
            showBlockPicker
              ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400' 
              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-700 dark:hover:text-surface-200'
          ]"
          title="Insert content block"
        >
          <span class="material-symbols-rounded text-lg">dashboard_customize</span>
                </button>
        
        <!-- Block Picker - teleported to body -->
        <Teleport to="body">
          <div v-if="showBlockPicker">
            <!-- Backdrop -->
            <div 
              class="fixed inset-0 z-[10000]"
              @click="showBlockPicker = false"
            ></div>
            
            <!-- Block Picker -->
            <div 
              class="fixed z-[10001]"
              :style="blockPickerPosition"
            >
              <EmailBlockPicker
                @insert="handleBlockInsert"
                @close="showBlockPicker = false"
              />
              </div>
            </div>
          </Teleport>
        </div>
      
      <!-- Emoji Picker button -->
      <div class="relative">
        <button
          ref="emojiButtonRef"
          @click.stop="toggleEmojiPicker"
          :class="[
            'p-1.5 rounded-lg transition-colors',
            showEmojiPicker
              ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400' 
              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-700 dark:hover:text-surface-200'
          ]"
          title="Insert emoji"
        >
          <span class="material-symbols-rounded text-lg">sentiment_satisfied</span>
        </button>
        
        <!-- Emoji Picker - teleported to body -->
        <Teleport to="body">
          <div v-if="showEmojiPicker">
            <!-- Backdrop -->
            <div 
              class="fixed inset-0 z-[10000]"
              @click="showEmojiPicker = false"
            ></div>
            
            <!-- Emoji Picker -->
            <div 
              class="fixed z-[10001]"
              :style="emojiPickerPosition"
            >
              <EmojiPicker
                :native="true"
                :theme="isDark ? 'dark' : 'light'"
                :display-recent="true"
                :disable-skin-tones="true"
                :hide-group-names="false"
                :hide-search="false"
                @select="onSelectEmoji"
              />
            </div>
          </div>
        </Teleport>
      </div>
      
      <!-- Clear / Undo / Redo (minimal toolbar only - keeps mockup grouping) -->
      <template v-if="minimalToolbar">
        <button
          @click="editor?.chain().focus().unsetAllMarks().clearNodes().run()"
          class="p-1.5 rounded-lg transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-700 dark:hover:text-surface-200"
          title="Clear formatting"
        >
          <span class="material-symbols-rounded text-lg">format_clear</span>
        </button>
        <div class="w-px h-5 bg-surface-200 dark:bg-[rgb(var(--color-border))] mx-0.5"></div>
        <button
          @click="editor?.chain().focus().undo().run()"
          class="p-1.5 rounded-lg transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-700 dark:hover:text-surface-200"
          title="Undo"
        >
          <span class="material-symbols-rounded text-lg">undo</span>
        </button>
        <button
          @click="editor?.chain().focus().redo().run()"
          class="p-1.5 rounded-lg transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] hover:text-surface-700 dark:hover:text-surface-200"
          title="Redo"
        >
          <span class="material-symbols-rounded text-lg">redo</span>
        </button>
      </template>

      <!-- Upload indicator -->
      <span v-if="uploading" class="ml-2 text-xs text-surface-500 flex items-center gap-1">
        <span class="spinner w-3 h-3"></span>
        Uploading...
      </span>

      <!-- AI Tools dropdown (pushed to the far right) -->
      <div v-if="showAITools" class="relative ml-auto">
        <button
          ref="aiButtonRef"
          @click.stop="toggleAIMenu"
          :disabled="aiStore.rewriting"
          :class="[
            'flex items-center gap-1 px-2.5 py-1 rounded-lg text-sm font-medium transition-colors border',
            showAIMenu
              ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 border-primary-200 dark:border-primary-500/40'
              : 'text-primary-600 dark:text-primary-400 border-primary-200 dark:border-primary-500/30 hover:bg-primary-50 dark:hover:bg-primary-500/10'
          ]"
          title="AI Tools"
        >
          <span v-if="aiStore.rewriting" class="material-symbols-rounded animate-spin text-lg">progress_activity</span>
          <span v-else class="material-symbols-rounded text-lg">auto_awesome</span>
          <span class="hidden sm:inline">AI Tools</span>
          <span class="material-symbols-rounded text-base transition-transform" :class="showAIMenu ? 'rotate-180' : ''">arrow_drop_down</span>
        </button>

        <!-- AI Tools Menu - teleported to body -->
        <Teleport to="body">
          <div v-if="showAIMenu">
            <div class="fixed inset-0 z-[10000]" @click="showAIMenu = false"></div>
            <div
              class="fixed w-52 max-h-[60vh] overflow-y-auto bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-[10001]"
              :style="aiMenuPosition"
            >
              <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase tracking-wide">Rewrite as...</p>
              <button
                v-for="(label, id) in aiStore.styles"
                :key="id"
                @click="rewriteWithAI(id)"
                :disabled="aiStore.rewriting"
                class="w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2 disabled:opacity-50"
              >
                <span class="material-symbols-rounded text-base text-primary-500">auto_fix_high</span>
                {{ label }}
              </button>
            </div>
          </div>
        </Teleport>
      </div>
    </div>
    
    <!-- Table Info Strip + Column Width Badges (stacked, inside table) -->
    <Teleport to="body">
      <div 
        v-if="tableFloatingVisible && badgeEditorTop !== null"
        class="fixed z-[9998] pointer-events-none"
        :style="{ top: `${Math.max(tableInfoBadge.top + 4, badgeEditorTop + 2)}px`, left: `${tableInfoBadge.left + 4}px` }"
      >
        <!-- Row 1: Table info badges -->
        <div class="flex items-center gap-1 mb-1">
          <span class="inline-flex items-center gap-0.5 bg-surface-700/85 dark:bg-surface-600/90 text-white/90 text-[9px] px-1.5 py-[3px] rounded font-medium backdrop-blur-sm">
            <span class="material-symbols-rounded text-[10px]">width</span>
            {{ tableInfoBadge.width }}
          </span>
          <span class="inline-flex items-center gap-0.5 bg-surface-700/85 dark:bg-surface-600/90 text-white/90 text-[9px] px-1.5 py-[3px] rounded font-medium backdrop-blur-sm">
            <span class="material-symbols-rounded text-[10px]">view_column</span>
            {{ tableInfoBadge.layout === 'fixed' ? 'Fixed cols' : 'Auto cols' }}
          </span>
          <span class="inline-flex items-center gap-0.5 bg-surface-700/85 dark:bg-surface-600/90 text-white/90 text-[9px] px-1.5 py-[3px] rounded font-medium backdrop-blur-sm">
            <span class="material-symbols-rounded text-[10px]">vertical_align_center</span>
            {{ tableInfoBadge.align }}
          </span>
        </div>
        <!-- Row 2: Column width badges -->
        <div v-if="tableColumnBadges.length" class="flex items-center gap-1">
          <span 
            v-for="(col, i) in tableColumnBadges" 
            :key="'colbadge-' + i"
            class="inline-block bg-primary-600/80 dark:bg-primary-500/70 text-white text-[9px] px-1.5 py-[2px] rounded font-mono font-medium backdrop-blur-sm whitespace-nowrap"
          >
            Col {{ i + 1 }}: {{ col.label }}
          </span>
        </div>
      </div>
    </Teleport>
    
    <!-- Floating Table Controls (+ / - buttons) -->
    <Teleport to="body">
      <div 
        v-if="tableFloatingVisible"
        class="fixed z-[9999] flex items-center gap-1 bg-white/95 dark:bg-surface-800/95 backdrop-blur-sm border border-surface-200 dark:border-surface-600 rounded-full shadow-xl px-2.5 py-1"
        :style="tableToolbarPos"
        @mousedown.prevent
      >
        <span class="text-[10px] font-medium text-surface-400 uppercase tracking-wide mr-0.5">Row</span>
        <button 
          @mousedown.prevent="quickAddRow" 
          class="w-6 h-6 rounded-full bg-primary-500/10 hover:bg-primary-500 hover:text-white text-primary-600 dark:text-primary-400 flex items-center justify-center transition-colors" 
          title="Add row below"
        >
          <span class="material-symbols-rounded text-sm">add</span>
        </button>
        <button 
          @mousedown.prevent="quickDeleteRow" 
          class="w-6 h-6 rounded-full bg-red-500/10 hover:bg-red-500 hover:text-white text-red-500 flex items-center justify-center transition-colors" 
          title="Delete current row"
        >
          <span class="material-symbols-rounded text-sm">remove</span>
        </button>
        
        <div class="w-px h-4 bg-surface-300 dark:bg-surface-600 mx-0.5"></div>
        
        <span class="text-[10px] font-medium text-surface-400 uppercase tracking-wide mr-0.5">Col</span>
        <button 
          @mousedown.prevent="quickAddCol" 
          class="w-6 h-6 rounded-full bg-primary-500/10 hover:bg-primary-500 hover:text-white text-primary-600 dark:text-primary-400 flex items-center justify-center transition-colors" 
          title="Add column after"
        >
          <span class="material-symbols-rounded text-sm">add</span>
        </button>
        <button 
          @mousedown.prevent="quickDeleteCol" 
          class="w-6 h-6 rounded-full bg-red-500/10 hover:bg-red-500 hover:text-white text-red-500 flex items-center justify-center transition-colors" 
          title="Delete current column"
        >
          <span class="material-symbols-rounded text-sm">remove</span>
        </button>
        
        <div class="w-px h-4 bg-surface-300 dark:bg-surface-600 mx-0.5"></div>
        
        <button 
          @mousedown.prevent="quickMergeCells" 
          class="w-6 h-6 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 flex items-center justify-center transition-colors" 
          title="Merge cells"
        >
          <span class="material-symbols-rounded text-sm">merge</span>
        </button>
        <button 
          @mousedown.prevent="quickSplitCell" 
          class="w-6 h-6 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 flex items-center justify-center transition-colors" 
          title="Split cell"
        >
          <span class="material-symbols-rounded text-sm">splitscreen</span>
        </button>
        <button 
          @mousedown.prevent="quickDeleteTable" 
          class="w-6 h-6 rounded-full hover:bg-red-50 dark:hover:bg-red-500/10 text-red-400 hover:text-red-600 dark:hover:text-red-400 flex items-center justify-center transition-colors" 
          title="Delete table"
        >
          <span class="material-symbols-rounded text-sm">delete</span>
        </button>
        
        <div class="w-px h-4 bg-surface-300 dark:bg-surface-600 mx-0.5"></div>
        
        <button 
          @mousedown.prevent="toggleTableSettings" 
          :class="[
            'w-6 h-6 rounded-full flex items-center justify-center transition-colors',
            showTableSettings 
              ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400' 
              : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          title="Table settings"
        >
          <span class="material-symbols-rounded text-sm">tune</span>
        </button>
      </div>
    </Teleport>
    
    <!-- Table Settings Popout -->
    <Teleport to="body">
      <div v-if="showTableSettings">
        <!-- Backdrop -->
        <div 
          class="fixed inset-0 z-[10000]"
          @click="showTableSettings = false"
        ></div>
        
        <!-- Settings Panel -->
        <div 
          class="fixed z-[10001] w-[260px] bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 p-3 space-y-3"
          :style="tableSettingsPos"
          @mousedown.stop
        >
          <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Table Settings</p>
          
          <!-- Table Width -->
          <div>
            <label class="text-[11px] font-medium text-surface-400 uppercase tracking-wide mb-1 block">Width</label>
            <div class="flex gap-1">
              <button 
                @click="setTableWidth('100%')"
                :class="['px-2.5 py-1 text-xs rounded-lg border transition-colors', currentTableWidth === '100%' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >100%</button>
              <button 
                @click="setTableWidth('auto')"
                :class="['px-2.5 py-1 text-xs rounded-lg border transition-colors', currentTableWidth === 'auto' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >Auto</button>
              <button 
                @click="setTableWidth('75%')"
                :class="['px-2.5 py-1 text-xs rounded-lg border transition-colors', currentTableWidth === '75%' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >75%</button>
              <button 
                @click="setTableWidth('50%')"
                :class="['px-2.5 py-1 text-xs rounded-lg border transition-colors', currentTableWidth === '50%' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >50%</button>
            </div>
          </div>
          
          <!-- Column Layout -->
          <div>
            <div class="flex items-center gap-1 mb-1">
              <label class="text-[11px] font-medium text-surface-400 uppercase tracking-wide">Column Sizing</label>
              <button 
                @click.stop="showLayoutInfo = !showLayoutInfo"
                class="w-4 h-4 rounded-full bg-surface-200 dark:bg-surface-600 text-surface-500 dark:text-surface-300 hover:bg-primary-500/20 hover:text-primary-500 flex items-center justify-center transition-colors"
                title="What is this?"
              >
                <span class="material-symbols-rounded text-[11px]">question_mark</span>
              </button>
            </div>
            
            <!-- Info explainer -->
            <div v-if="showLayoutInfo" class="mb-2 p-2 rounded-lg bg-surface-100 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600 text-[11px] text-surface-500 dark:text-surface-400 leading-relaxed space-y-1.5">
              <div class="flex gap-1.5">
                <span class="material-symbols-rounded text-primary-500 text-sm mt-px shrink-0">width</span>
                <div>
                  <span class="font-semibold text-surface-700 dark:text-surface-200">Auto (%)</span> — 
                  Columns adapt to their content. A column with more text gets more space. 
                  You can drag column borders to adjust manually.
                </div>
              </div>
              <div class="flex gap-1.5">
                <span class="material-symbols-rounded text-primary-500 text-sm mt-px shrink-0">view_column</span>
                <div>
                  <span class="font-semibold text-surface-700 dark:text-surface-200">Fixed (equal)</span> — 
                  All columns get exactly equal width regardless of content. 
                  3 columns = 33% each, 4 = 25% each. Clean grid look.
                </div>
              </div>
            </div>
            
            <div class="flex gap-1">
              <button 
                @click="setTableLayout('auto')"
                :class="['flex-1 px-2.5 py-1 text-xs rounded-lg border transition-colors flex items-center justify-center gap-1', currentTableLayout === 'auto' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >
                <span class="material-symbols-rounded text-sm">width</span>
                Auto (%)
              </button>
              <button 
                @click="setTableLayout('fixed')"
                :class="['flex-1 px-2.5 py-1 text-xs rounded-lg border transition-colors flex items-center justify-center gap-1', currentTableLayout === 'fixed' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >
                <span class="material-symbols-rounded text-sm">view_column</span>
                Fixed (equal)
              </button>
            </div>
          </div>
          
          <!-- Cell Vertical Alignment -->
          <div>
            <label class="text-[11px] font-medium text-surface-400 uppercase tracking-wide mb-1 block">Cell Vertical Align</label>
            <div class="flex gap-1">
              <button 
                @click="setCellVerticalAlign('top')"
                :class="['flex-1 px-2.5 py-1 text-xs rounded-lg border transition-colors flex items-center justify-center gap-1', currentCellAlign === 'top' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >
                <span class="material-symbols-rounded text-sm">vertical_align_top</span>
                Top
              </button>
              <button 
                @click="setCellVerticalAlign('middle')"
                :class="['flex-1 px-2.5 py-1 text-xs rounded-lg border transition-colors flex items-center justify-center gap-1', currentCellAlign === 'middle' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >
                <span class="material-symbols-rounded text-sm">vertical_align_center</span>
                Middle
              </button>
              <button 
                @click="setCellVerticalAlign('bottom')"
                :class="['flex-1 px-2.5 py-1 text-xs rounded-lg border transition-colors flex items-center justify-center gap-1', currentCellAlign === 'bottom' ? 'bg-primary-500/10 border-primary-500/30 text-primary-600 dark:text-primary-400 font-medium' : 'border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-700']"
              >
                <span class="material-symbols-rounded text-sm">vertical_align_bottom</span>
                Bottom
              </button>
            </div>
          </div>
          
          <!-- Info text -->
          <p class="text-[10px] text-surface-400 leading-relaxed">
            <span class="material-symbols-rounded text-[10px] align-middle">info</span>
            Drag the green column handles to resize manually. Badge values update live.
          </p>
        </div>
      </div>
    </Teleport>
    
    <!-- Link input popup -->
    <div v-if="showLinkInput" class="flex items-center gap-2 px-3 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]">
      <span class="material-symbols-rounded text-surface-400">link</span>
      <input
        v-model="linkUrl"
        type="url"
        class="flex-1 bg-transparent outline-none text-sm"
        placeholder="Enter URL..."
        @keydown.enter="setLink"
        autofocus
      />
      <button @click="setLink" class="btn-primary btn-sm">
        Apply
      </button>
      <button @click="showLinkInput = false" class="btn-ghost btn-sm">
        Cancel
      </button>
    </div>
    
    <!-- Hidden image input -->
    <input
      ref="imageInput"
      type="file"
      accept="image/*"
      class="hidden"
      @change="handleImageSelect"
    />
    
    <!-- Editor -->
    <EditorContent 
      :editor="editor" 
      :class="[
        'overflow-y-auto',
        toolbarBottom ? 'order-1' : '',
        compact ? 'min-h-[120px] max-h-[200px]' : zenMode ? 'min-h-[50vh] flex-1' : 'min-h-[300px] max-h-[400px]'
      ]" 
      @contextmenu="handleContextMenu"
    />

    <!-- Combined selection popout: text color, highlight, and Add to To-do -->
    <EditorSelectionBubble
      v-if="editor"
      :editor="editor"
      :tasks-enabled="tasksEnabled"
      @add-todo="handleAddToTodo"
    />
    
    <!-- Right-click Context Menu (teleported to body) -->
    <Teleport to="body">
      <div v-if="showContextMenu">
        <!-- Backdrop -->
        <div 
          class="fixed inset-0 z-[10000]"
          @click="closeContextMenu"
        ></div>
        
        <!-- Context Menu -->
        <div 
          class="fixed bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-[10001] min-w-[200px] max-h-[80vh] overflow-y-auto"
          :style="contextMenuPosition"
        >
          <!-- Table operations (when right-clicking in a table) -->
          <template v-if="contextInTable">
            <p class="px-3 py-1 text-[10px] font-medium text-surface-400 uppercase tracking-wide">Rows</p>
            <button
              @click="addRowBefore(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">arrow_upward</span>
              Add row above
            </button>
            <button
              @click="addRowAfter(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">arrow_downward</span>
              Add row below
            </button>
            <button
              @click="deleteRow(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">remove_circle_outline</span>
              Delete row
            </button>
            
            <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
            
            <p class="px-3 py-1 text-[10px] font-medium text-surface-400 uppercase tracking-wide">Columns</p>
            <button
              @click="addColumnBefore(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">arrow_back</span>
              Add column before
            </button>
            <button
              @click="addColumnAfter(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">arrow_forward</span>
              Add column after
            </button>
            <button
              @click="deleteColumn(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">remove_circle_outline</span>
              Delete column
            </button>
            
            <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
            
            <p class="px-3 py-1 text-[10px] font-medium text-surface-400 uppercase tracking-wide">Cells</p>
            <button
              @click="mergeCells(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">merge</span>
              Merge cells
            </button>
            <button
              @click="splitCell(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">splitscreen</span>
              Split cell
            </button>
            
            <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
            
            <button
              @click="deleteTable(); closeContextMenu()"
              class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-red-500 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">delete</span>
              Delete table
            </button>
            
            <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
          </template>
          
          <!-- Copy -->
          <button
            @click="handleCopy"
            class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">content_copy</span>
            Copy
          </button>
          
          <!-- Paste -->
          <button
            @click="handlePaste"
            class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">content_paste</span>
            Paste
          </button>
          
          <!-- AI Rewrite (only when text is selected) -->
          <template v-if="hasSelection">
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
          
          <!-- Rewrite with AI (submenu) -->
          <div class="relative">
            <button
              @click.stop="showContextAIStyles = !showContextAIStyles"
              :disabled="aiStore.rewriting"
                class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200 flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">auto_awesome</span>
              <span class="flex-1">Rewrite with AI</span>
              <span v-if="aiStore.rewriting" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded text-sm">chevron_right</span>
            </button>
            
            <!-- AI Styles Submenu -->
            <div 
              v-if="showContextAIStyles && !aiStore.rewriting"
              class="absolute left-full top-0 ml-1 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[140px]"
            >
              <p class="px-3 py-1.5 text-xs font-medium text-surface-500 uppercase">Style</p>
              <button
                v-for="(label, id) in aiStore.styles"
                :key="id"
                @click="rewriteSelection(id)"
                  class="w-full px-3 py-1.5 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-surface-700 dark:text-surface-200"
              >
                {{ label }}
              </button>
            </div>
          </div>
          </template>
        </div>
      </div>
    </Teleport>

    <!-- Image Replace Picker (on double-click) -->
    <Teleport to="body">
      <div v-if="showImageReplacePicker" class="fixed inset-0 z-[200] flex items-center justify-center">
        <div class="absolute inset-0 bg-black/40" @click="showImageReplacePicker = false"></div>
        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl border border-surface-200 dark:border-surface-700 p-6 w-[380px] max-w-[90vw]">
          <div class="flex items-center justify-between mb-5">
            <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Replace Image</h3>
            <button @click="showImageReplacePicker = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
              <span class="material-symbols-rounded text-lg text-surface-400">close</span>
            </button>
          </div>
          <div class="flex flex-col gap-3">
            <button
              @click="imageReplaceInput?.click()"
              class="flex items-center gap-3 w-full px-4 py-3.5 rounded-xl border border-surface-200 dark:border-surface-600 hover:border-primary-400 dark:hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-all group"
            >
              <span class="material-symbols-rounded text-2xl text-surface-400 group-hover:text-primary-500 transition-colors">upload_file</span>
              <div class="text-left">
                <div class="text-sm font-medium text-surface-800 dark:text-surface-200">Upload from computer</div>
                <div class="text-xs text-surface-400">Select an image file from your device</div>
              </div>
            </button>
            <button
              @click="showDrivePickerForImage = true; showImageReplacePicker = false"
              class="flex items-center gap-3 w-full px-4 py-3.5 rounded-xl border border-surface-200 dark:border-surface-600 hover:border-primary-400 dark:hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-all group"
            >
              <span class="material-symbols-rounded text-2xl text-surface-400 group-hover:text-primary-500 transition-colors">cloud_upload</span>
              <div class="text-left">
                <div class="text-sm font-medium text-surface-800 dark:text-surface-200">Choose from Drive</div>
                <div class="text-xs text-surface-400">Pick an image from your cloud storage</div>
              </div>
            </button>
            <button
              @click="promptImageUrl"
              class="flex items-center gap-3 w-full px-4 py-3.5 rounded-xl border border-surface-200 dark:border-surface-600 hover:border-primary-400 dark:hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-all group"
            >
              <span class="material-symbols-rounded text-2xl text-surface-400 group-hover:text-primary-500 transition-colors">link</span>
              <div class="text-left">
                <div class="text-sm font-medium text-surface-800 dark:text-surface-200">Paste image URL</div>
                <div class="text-xs text-surface-400">Use a direct link to an image</div>
              </div>
            </button>
          </div>
          <input
            ref="imageReplaceInput"
            type="file"
            accept="image/*"
            class="hidden"
            @change="handleImageReplaceUpload"
          />
        </div>
      </div>
    </Teleport>

    <!-- Drive File Picker for image replacement -->
    <DriveFilePicker
      :show="showDrivePickerForImage"
      title="Choose Image from Drive"
      :accept-types="['image/*']"
      @select="handleDriveImageSelect"
      @cancel="showDrivePickerForImage = false"
    />
  </div>
</template>

<style>
.ProseMirror {
  @apply outline-none p-4 min-h-[300px];
  cursor: text;
}

.ProseMirror p {
  @apply my-2;
}

.ProseMirror:first-child {
  @apply mt-0;
}

.ProseMirror ul {
  @apply list-disc ml-6 my-2;
}

.ProseMirror ol {
  @apply list-decimal ml-6 my-2;
}

.ProseMirror li {
  @apply my-1;
}

.ProseMirror blockquote {
  @apply border-l-4 border-surface-300 dark:border-surface-600 pl-4 my-4 text-surface-600 dark:text-surface-400;
}

.ProseMirror code {
  @apply bg-surface-100 dark:bg-surface-800 px-1.5 py-0.5 rounded text-sm font-mono;
}

.ProseMirror pre {
  @apply bg-surface-100 dark:bg-surface-800 rounded-lg p-4 my-4 font-mono text-sm overflow-x-auto;
}

.ProseMirror a {
  @apply text-primary-500 hover:underline cursor-pointer;
}

/* Resizable images are handled by the ResizableImage component */
.ProseMirror img:not(.resizable-image-wrapper img) {
  @apply max-w-full h-auto rounded my-4;
}

.ProseMirror p.is-editor-empty:first-child::before {
  @apply text-surface-400 dark:text-surface-500 float-left h-0 pointer-events-none;
  content: attr(data-placeholder);
}

.ProseMirror h1 {
  @apply text-2xl font-bold my-4;
}

.ProseMirror h2 {
  @apply text-xl font-bold my-3;
}

.ProseMirror h3 {
  @apply text-lg font-bold my-2;
}

/* =============================================
   TABLE EDITOR STYLES
   Always show editing guides regardless of 
   inline styles (border:none etc.)
   ============================================= */

/* All tables in the editor get visible guides */
.ProseMirror table {
  border-collapse: collapse !important;
  width: 100%;
  margin: 1rem 0;
  position: relative;
  border: 1px dashed rgba(148, 163, 184, 0.5) !important;
  border-radius: 0.5rem;
  overflow: visible;
}

.ProseMirror table th,
.ProseMirror table td {
  border: 1px dashed rgba(148, 163, 184, 0.35) !important;
  padding: 0.5rem 0.75rem !important;
  min-width: 80px;
  vertical-align: middle;
  position: relative;
}

.ProseMirror table th {
  @apply bg-surface-100/60 dark:bg-surface-800/60 font-semibold text-left;
}

/* Hover highlight on cells for better visibility */
.ProseMirror table td:hover,
.ProseMirror table th:hover {
  background-color: rgba(var(--color-primary-500, 34 197 94), 0.04);
}

/* Dark mode: brighter dashed guides */
.dark .ProseMirror table {
  border-color: rgba(148, 163, 184, 0.25) !important;
}
.dark .ProseMirror table th,
.dark .ProseMirror table td {
  border-color: rgba(148, 163, 184, 0.18) !important;
}
.dark .ProseMirror table td:hover,
.dark .ProseMirror table th:hover {
  background-color: rgba(var(--color-primary-500, 52 211 116), 0.06);
}

/* Reset inner element margins inside cells */
.ProseMirror table td > *,
.ProseMirror table th > * {
  margin: 0;
}

/* Constrain images inside table cells */
.ProseMirror table td img,
.ProseMirror table th img {
  max-width: 100% !important;
  height: auto !important;
  display: block;
}

/* Selected cell highlight */
.ProseMirror table .selectedCell {
  background-color: rgba(var(--color-primary-500, 34 197 94), 0.08) !important;
  border-style: solid !important;
  border-color: rgba(var(--color-primary-500, 34 197 94), 0.4) !important;
}
.dark .ProseMirror table .selectedCell {
  background-color: rgba(var(--color-primary-500, 52 211 116), 0.1) !important;
  border-color: rgba(var(--color-primary-500, 52 211 116), 0.35) !important;
}

/* Column resize handle - always visible on hover */
.ProseMirror .column-resize-handle {
  width: 3px;
  position: absolute;
  right: -2px;
  top: 0;
  bottom: 0;
  background: rgb(var(--color-primary-500, 34 197 94));
  opacity: 0.6;
  pointer-events: none;
  z-index: 20;
  border-radius: 2px;
}

.ProseMirror.resize-cursor {
  cursor: col-resize;
}

/* Table wrapper margin for blocks with border:none */
.ProseMirror table[style*="border:none"],
.ProseMirror table[style*="border: none"] {
  border: 1px dashed rgba(148, 163, 184, 0.4) !important;
}
.dark .ProseMirror table[style*="border:none"],
.dark .ProseMirror table[style*="border: none"] {
  border: 1px dashed rgba(148, 163, 184, 0.2) !important;
}

/* Text alignment */
.ProseMirror [style*="text-align: center"] {
  text-align: center;
}
.ProseMirror [style*="text-align: right"] {
  text-align: right;
}
.ProseMirror [style*="text-align: justify"] {
  text-align: justify;
}
</style>

