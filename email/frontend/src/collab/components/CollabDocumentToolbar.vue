<template>
  <div class="toolbar-container">
    <!-- Show toolbar only when editor is ready -->
    <template v-if="isEditorReady">
      <!-- Single row toolbar with labeled groups -->
      <div class="toolbar-content">
        <!-- File Actions -->
        <div class="toolbar-section">
          <div class="section-label">File</div>
          <div class="section-buttons">
            <button @click="$emit('save-docx')" title="Download as DOCX" class="toolbar-btn accent">
              <span class="material-symbols-rounded">download</span>
            </button>
            <button @click="handlePrint" title="Print" class="toolbar-btn">
              <span class="material-symbols-rounded">print</span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- History -->
        <div class="toolbar-section">
          <div class="section-label">History</div>
          <div class="section-buttons">
            <button @click="handleUndo" :disabled="!canUndo" title="Undo (Ctrl+Z)" class="toolbar-btn" :class="{ 'disabled': !canUndo }">
              <span class="material-symbols-rounded">undo</span>
            </button>
            <button @click="handleRedo" :disabled="!canRedo" title="Redo (Ctrl+Y)" class="toolbar-btn" :class="{ 'disabled': !canRedo }">
              <span class="material-symbols-rounded">redo</span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- Font -->
        <div class="toolbar-section">
          <div class="section-label">Font</div>
          <div class="section-buttons">
            <button @click="openDropdown('fontFamily', $event)" class="dropdown-btn font-family-btn" title="Font Family">
              <span class="btn-text">{{ currentFontFamily }}</span>
              <span class="material-symbols-rounded dropdown-arrow">expand_more</span>
            </button>
            <button @click="openDropdown('fontSize', $event)" class="dropdown-btn font-size-btn" title="Font Size">
              <span class="btn-text">{{ currentFontSize }}</span>
              <span class="material-symbols-rounded dropdown-arrow">expand_more</span>
            </button>
            <button @click="decreaseFontSize" title="Decrease Size" class="toolbar-btn">
              <span class="material-symbols-rounded">text_decrease</span>
            </button>
            <button @click="increaseFontSize" title="Increase Size" class="toolbar-btn">
              <span class="material-symbols-rounded">text_increase</span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- Style -->
        <div class="toolbar-section">
          <div class="section-label">Style</div>
          <div class="section-buttons">
            <button @click="openDropdown('textStyle', $event)" class="dropdown-btn style-btn" title="Paragraph Style">
              <span class="btn-text">{{ currentTextStyle }}</span>
              <span class="material-symbols-rounded dropdown-arrow">expand_more</span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- Format -->
        <div class="toolbar-section">
          <div class="section-label">Format</div>
          <div class="section-buttons">
            <button @click="toggleBold" title="Bold (Ctrl+B)" class="toolbar-btn" :class="{ 'active': isBold }">
              <span class="material-symbols-rounded">format_bold</span>
            </button>
            <button @click="toggleItalic" title="Italic (Ctrl+I)" class="toolbar-btn" :class="{ 'active': isItalic }">
              <span class="material-symbols-rounded">format_italic</span>
            </button>
            <button @click="toggleUnderline" title="Underline (Ctrl+U)" class="toolbar-btn" :class="{ 'active': isUnderlined }">
              <span class="material-symbols-rounded">format_underlined</span>
            </button>
            <button @click="toggleStrike" title="Strikethrough" class="toolbar-btn" :class="{ 'active': isStrike }">
              <span class="material-symbols-rounded">strikethrough_s</span>
            </button>
            <button @click="toggleSubscript" title="Subscript" class="toolbar-btn" :class="{ 'active': isSubscript }">
              <span class="material-symbols-rounded">subscript</span>
            </button>
            <button @click="toggleSuperscript" title="Superscript" class="toolbar-btn" :class="{ 'active': isSuperscript }">
              <span class="material-symbols-rounded">superscript</span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- Colors -->
        <div class="toolbar-section">
          <div class="section-label">Colors</div>
          <div class="section-buttons">
            <button @click="openDropdown('textColor', $event)" title="Text Color" class="toolbar-btn color-btn">
              <span class="text-color-icon">A</span>
              <span class="color-indicator" :style="{ backgroundColor: currentTextColor }"></span>
            </button>
            <button @click="openDropdown('highlight', $event)" title="Highlight Color" class="toolbar-btn color-btn">
              <span class="material-symbols-rounded" style="font-variation-settings: 'FILL' 1;">ink_highlighter</span>
              <span class="color-indicator" :style="{ backgroundColor: currentHighlight }"></span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- Lists -->
        <div class="toolbar-section">
          <div class="section-label">Lists</div>
          <div class="section-buttons">
            <button @click="toggleBulletList" title="Bullet List" class="toolbar-btn" :class="{ 'active': isBulletList }">
              <span class="material-symbols-rounded">format_list_bulleted</span>
            </button>
            <button @click="toggleOrderedList" title="Numbered List" class="toolbar-btn" :class="{ 'active': isOrderedList }">
              <span class="material-symbols-rounded">format_list_numbered</span>
            </button>
            <button @click="decreaseIndent" title="Decrease Indent" class="toolbar-btn">
              <span class="material-symbols-rounded">format_indent_decrease</span>
            </button>
            <button @click="increaseIndent" title="Increase Indent" class="toolbar-btn">
              <span class="material-symbols-rounded">format_indent_increase</span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- Alignment -->
        <div class="toolbar-section">
          <div class="section-label">Align</div>
          <div class="section-buttons">
            <button @click="setAlign('left')" title="Align Left" class="toolbar-btn" :class="{ 'active': isAlignLeft }">
              <span class="material-symbols-rounded">format_align_left</span>
            </button>
            <button @click="setAlign('center')" title="Align Center" class="toolbar-btn" :class="{ 'active': isAlignCenter }">
              <span class="material-symbols-rounded">format_align_center</span>
            </button>
            <button @click="setAlign('right')" title="Align Right" class="toolbar-btn" :class="{ 'active': isAlignRight }">
              <span class="material-symbols-rounded">format_align_right</span>
            </button>
            <button @click="setAlign('justify')" title="Justify" class="toolbar-btn" :class="{ 'active': isAlignJustify }">
              <span class="material-symbols-rounded">format_align_justify</span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- Insert -->
        <div class="toolbar-section">
          <div class="section-label">Insert</div>
          <div class="section-buttons">
            <button @click="openLinkDialog" title="Insert Link" class="toolbar-btn" :class="{ 'active': isLink }">
              <span class="material-symbols-rounded">link</span>
            </button>
            <button @click="triggerImageUpload" title="Insert Image" class="toolbar-btn">
              <span class="material-symbols-rounded">image</span>
            </button>
            <button @click="openDropdown('table', $event)" title="Insert Table" class="toolbar-btn">
              <span class="material-symbols-rounded">table_chart</span>
            </button>
            <button @click="insertHorizontalRule" title="Insert Divider" class="toolbar-btn">
              <span class="material-symbols-rounded">horizontal_rule</span>
            </button>
            <button @click="insertPageBreak" title="Insert Page Break (Ctrl+Enter)" class="toolbar-btn">
              <span class="material-symbols-rounded">insert_page_break</span>
            </button>
            <button @click="toggleBlockquote" title="Quote" class="toolbar-btn" :class="{ 'active': isBlockquote }">
              <span class="material-symbols-rounded">format_quote</span>
            </button>
            <button @click="toggleCodeBlock" title="Code Block" class="toolbar-btn" :class="{ 'active': isCodeBlock }">
              <span class="material-symbols-rounded">code</span>
            </button>
          </div>
        </div>

        <div class="toolbar-divider"></div>

        <!-- Tools -->
        <div class="toolbar-section">
          <div class="section-label">Tools</div>
          <div class="section-buttons">
            <button @click="clearFormatting" title="Clear Formatting" class="toolbar-btn">
              <span class="material-symbols-rounded">format_clear</span>
            </button>
            <button @click="$emit('add-comment')" title="Add Comment" class="toolbar-btn">
              <span class="material-symbols-rounded">add_comment</span>
            </button>
          </div>
        </div>
      </div>
    </template>
    
    <div v-else class="toolbar-loading">
      <span class="material-symbols-rounded spin">progress_activity</span>
      Loading toolbar...
    </div>
    
    <!-- Dropdown menus (teleported to body to avoid overflow issues) -->
    <Teleport to="body">
      <!-- Font Family Dropdown -->
      <div 
        v-if="activeDropdown === 'fontFamily'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="dropdown-content" style="width: 200px; max-height: 300px;">
          <button 
            v-for="font in fontFamilies"
            :key="font.value"
            @click="setFontFamily(font.value)"
            class="dropdown-item"
            :style="{ fontFamily: font.value }"
            :class="{ 'selected': currentFontFamily === font.label }"
          >
            {{ font.label }}
          </button>
        </div>
      </div>
      
      <!-- Font Size Dropdown -->
      <div 
        v-if="activeDropdown === 'fontSize'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="dropdown-content" style="width: 80px; max-height: 260px;">
          <button 
            v-for="size in fontSizes"
            :key="size"
            @click="setFontSize(size)"
            class="dropdown-item text-center"
            :class="{ 'selected': currentFontSize === size.toString() }"
          >
            {{ size }}
          </button>
        </div>
      </div>
      
      <!-- Text Style Dropdown -->
      <div 
        v-if="activeDropdown === 'textStyle'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="dropdown-content" style="width: 160px;">
          <button 
            v-for="style in textStyles"
            :key="style.name"
            @click="applyTextStyle(style)"
            class="dropdown-item"
            :class="{ 'selected': isStyleActive(style) }"
          >
            <span :style="style.css">{{ style.label }}</span>
          </button>
        </div>
      </div>
      
      <!-- Text Color Picker -->
      <div 
        v-if="activeDropdown === 'textColor'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="color-picker-content">
          <div class="color-picker-label">Text Color</div>
          <div class="color-grid">
            <button
              v-for="color in textColors"
              :key="color"
              @click="setTextColor(color)"
              class="color-swatch"
              :style="{ backgroundColor: color }"
              :class="{ 'selected': currentTextColor === color }"
            ></button>
          </div>
          <button @click="removeTextColor" class="color-action">Remove color</button>
        </div>
      </div>
      
      <!-- Highlight Color Picker -->
      <div 
        v-if="activeDropdown === 'highlight'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="color-picker-content">
          <div class="color-picker-label">Highlight</div>
          <div class="color-grid highlight-grid">
            <button
              v-for="color in highlightColors"
              :key="color"
              @click="setHighlight(color)"
              class="color-swatch"
              :style="{ backgroundColor: color }"
            ></button>
          </div>
          <button @click="removeHighlight" class="color-action">Remove highlight</button>
        </div>
      </div>
      
      <!-- Table Picker -->
      <div 
        v-if="activeDropdown === 'table'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="table-picker-content">
          <div class="table-picker-label">Insert Table</div>
          <div class="table-grid">
            <div v-for="row in 6" :key="'row-' + row" class="table-row">
              <button
                v-for="col in 6"
                :key="'cell-' + row + '-' + col"
                @click="insertTable(row, col)"
                @mouseenter="tableHover = { rows: row, cols: col }"
                class="table-cell"
                :class="{ 'active': tableHover.rows >= row && tableHover.cols >= col }"
              ></button>
            </div>
          </div>
          <div class="table-size-label">{{ tableHover.rows }} x {{ tableHover.cols }}</div>
        </div>
      </div>
      
      <!-- Link Dialog -->
      <div 
        v-if="showLinkDialog"
        class="modal-overlay"
        @click.self="closeLinkDialog"
      >
        <div class="link-dialog" @click.stop>
          <div class="link-dialog-header">
            <h3>Insert Link</h3>
            <button @click="closeLinkDialog" class="close-btn">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          <div class="link-dialog-body">
            <label>Display text</label>
            <input v-model="linkText" type="text" placeholder="Link text" />
            <label>URL</label>
            <input 
              ref="linkInputRef"
              v-model="linkUrl" 
              type="url" 
              placeholder="https://example.com"
              @keydown.enter.prevent="applyLink"
            />
          </div>
          <div class="link-dialog-footer">
            <button @click="closeLinkDialog" class="dialog-btn dialog-btn-secondary">Cancel</button>
            <button v-if="isLink" @click="removeLink" class="dialog-btn dialog-btn-danger">Remove</button>
            <button @click="applyLink" class="dialog-btn dialog-btn-primary" :disabled="!linkUrl">Apply</button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Hidden file input -->
    <input 
      ref="imageInputRef"
      type="file" 
      accept="image/*" 
      style="display: none;"
      @change="handleImageSelect"
    />
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  editor: { type: Object, required: true },
  canUndo: { type: Boolean, default: false },
  canRedo: { type: Boolean, default: false },
  documentTitle: { type: String, default: 'Document' },
})

const emit = defineEmits(['undo', 'redo', 'add-comment', 'save-docx'])

// State
const activeDropdown = ref(null)
const dropdownPosition = ref({})
const showLinkDialog = ref(false)
const linkUrl = ref('')
const linkText = ref('')
const imageInputRef = ref(null)
const linkInputRef = ref(null)
const tableHover = ref({ rows: 1, cols: 1 })
// Configuration
const fontFamilies = [
  { label: 'Outfit', value: '"Outfit", system-ui, sans-serif' },
  { label: 'Calibri', value: 'Calibri, sans-serif' },
  { label: 'Arial', value: 'Arial, sans-serif' },
  { label: 'Times New Roman', value: '"Times New Roman", serif' },
  { label: 'Georgia', value: 'Georgia, serif' },
  { label: 'Verdana', value: 'Verdana, sans-serif' },
  { label: 'Trebuchet MS', value: '"Trebuchet MS", sans-serif' },
  { label: 'Courier New', value: '"Courier New", monospace' },
  { label: 'Comic Sans MS', value: '"Comic Sans MS", cursive' },
  { label: 'Impact', value: 'Impact, sans-serif' },
]

const fontSizes = [8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32, 36, 48, 72]

const textStyles = [
  { name: 'heading1', label: 'Heading 1', level: 1, css: { fontSize: '28px', fontWeight: '600' } },
  { name: 'heading2', label: 'Heading 2', level: 2, css: { fontSize: '22px', fontWeight: '600' } },
  { name: 'heading3', label: 'Heading 3', level: 3, css: { fontSize: '18px', fontWeight: '500' } },
  { name: 'paragraph', label: 'Normal', level: null, css: { fontSize: '16px' } },
]

const textColors = [
  '#000000', '#434343', '#666666', '#999999', '#B7B7B7', '#CCCCCC', '#D9D9D9', '#FFFFFF',
  '#980000', '#FF0000', '#FF9900', '#FFFF00', '#00FF00', '#00FFFF', '#4A86E8', '#0000FF',
  '#9900FF', '#FF00FF', '#E6B8AF', '#F4CCCC', '#FCE5CD', '#FFF2CC', '#D9EAD3', '#D0E0E3',
  '#C9DAF8', '#CFE2F3', '#D9D2E9', '#EAD1DC', '#CC4125', '#E06666', '#F6B26B', '#FFD966',
  '#93C47D', '#76A5AF', '#6D9EEB', '#6FA8DC', '#8E7CC3', '#C27BA0',
]

const highlightColors = [
  '#FFFF00', '#00FF00', '#00FFFF', '#FF00FF', '#FF0000', '#0000FF',
  '#FFFFC0', '#C0FFC0', '#C0FFFF', '#FFC0FF', '#FFC0C0', '#C0C0FF',
]

// Computed
const isEditorReady = computed(() => props.editor && typeof props.editor.isActive === 'function')

const currentFontFamily = computed(() => {
  if (!isEditorReady.value) return 'Outfit'
  const attrs = props.editor.getAttributes('textStyle')
  if (attrs.fontFamily) {
    const found = fontFamilies.find(f => attrs.fontFamily.includes(f.value.split(',')[0].replace(/"/g, '')))
    return found?.label || 'Outfit'
  }
  return 'Outfit'
})

const currentFontSize = computed(() => {
  if (!isEditorReady.value) return '16'
  
  // Check if we're in a heading - return the heading's actual size
  if (props.editor.isActive('heading', { level: 1 })) return '28' // H1 is 28px
  if (props.editor.isActive('heading', { level: 2 })) return '22' // H2 is 22px
  if (props.editor.isActive('heading', { level: 3 })) return '18' // H3 is 18px
  
  // Check for explicit fontSize in textStyle
  const attrs = props.editor.getAttributes('textStyle')
  if (attrs.fontSize) {
    // fontSize is stored as "16px", extract the number
    const match = attrs.fontSize.match(/(\d+)/)
    if (match) return match[1]
  }
  
  // Default font size
  return '16'
})

const currentTextStyle = computed(() => {
  if (!isEditorReady.value) return 'Normal'
  if (props.editor.isActive('heading', { level: 1 })) return 'Heading 1'
  if (props.editor.isActive('heading', { level: 2 })) return 'Heading 2'
  if (props.editor.isActive('heading', { level: 3 })) return 'Heading 3'
  return 'Normal'
})

const currentTextColor = computed(() => {
  if (!isEditorReady.value) return '#000000'
  return props.editor.getAttributes('textStyle').color || '#000000'
})

const currentHighlight = computed(() => {
  if (!isEditorReady.value) return '#FFFF00'
  return props.editor.getAttributes('highlight').color || '#FFFF00'
})

const isBold = computed(() => isEditorReady.value && props.editor.isActive('bold'))
const isItalic = computed(() => isEditorReady.value && props.editor.isActive('italic'))
const isUnderlined = computed(() => isEditorReady.value && props.editor.isActive('underline'))
const isStrike = computed(() => isEditorReady.value && props.editor.isActive('strike'))
const isSubscript = computed(() => isEditorReady.value && props.editor.isActive('subscript'))
const isSuperscript = computed(() => isEditorReady.value && props.editor.isActive('superscript'))
const isLink = computed(() => isEditorReady.value && props.editor.isActive('link'))
const isBulletList = computed(() => isEditorReady.value && props.editor.isActive('bulletList'))
const isOrderedList = computed(() => isEditorReady.value && props.editor.isActive('orderedList'))
const isBlockquote = computed(() => isEditorReady.value && props.editor.isActive('blockquote'))
const isCodeBlock = computed(() => isEditorReady.value && props.editor.isActive('codeBlock'))
const isAlignLeft = computed(() => isEditorReady.value && props.editor.isActive({ textAlign: 'left' }))
const isAlignCenter = computed(() => isEditorReady.value && props.editor.isActive({ textAlign: 'center' }))
const isAlignRight = computed(() => isEditorReady.value && props.editor.isActive({ textAlign: 'right' }))
const isAlignJustify = computed(() => isEditorReady.value && props.editor.isActive({ textAlign: 'justify' }))

// Dropdown handling
function openDropdown(name, event) {
  if (activeDropdown.value === name) {
    activeDropdown.value = null
    return
  }
  
  const rect = event.target.getBoundingClientRect()
  dropdownPosition.value = {
    position: 'fixed',
    top: `${rect.bottom + 6}px`,
    left: `${rect.left}px`,
    zIndex: 9999,
  }
  activeDropdown.value = name
}

function closeDropdown() {
  activeDropdown.value = null
}

// Actions
function handleUndo() { emit('undo') }
function handleRedo() { emit('redo') }

function handlePrint() {
  window.print()
}

function setFontFamily(value) {
  if (!isEditorReady.value) return
  props.editor.chain().focus().setFontFamily(value).run()
  closeDropdown()
}

function setFontSize(size) {
  if (!isEditorReady.value) return
  // If in a heading, convert to paragraph first then apply font size
  if (props.editor.isActive('heading')) {
    props.editor.chain().focus().setParagraph().setMark('textStyle', { fontSize: `${size}px` }).run()
  } else {
    props.editor.chain().focus().setMark('textStyle', { fontSize: `${size}px` }).run()
  }
  closeDropdown()
}

function increaseFontSize() {
  const currentSize = parseInt(currentFontSize.value) || 16
  const idx = fontSizes.findIndex(s => s >= currentSize)
  if (idx < fontSizes.length - 1) {
    setFontSize(fontSizes[idx + 1])
  } else if (idx === -1 && fontSizes.length > 0) {
    // Current size is larger than all preset sizes, go to largest
    setFontSize(fontSizes[fontSizes.length - 1])
  }
}

function decreaseFontSize() {
  const currentSize = parseInt(currentFontSize.value) || 16
  const idx = fontSizes.findIndex(s => s >= currentSize)
  if (idx > 0) {
    setFontSize(fontSizes[idx - 1])
  } else if (idx === 0) {
    // Already at smallest
  } else if (idx === -1) {
    // Current size is larger than all preset sizes, go to largest
    setFontSize(fontSizes[fontSizes.length - 1])
  }
}

function isStyleActive(style) {
  if (!isEditorReady.value) return false
  return style.level ? props.editor.isActive('heading', { level: style.level }) : !props.editor.isActive('heading')
}

function applyTextStyle(style) {
  if (!isEditorReady.value) return
  if (style.level) {
    props.editor.chain().focus().toggleHeading({ level: style.level }).run()
  } else {
    props.editor.chain().focus().setParagraph().run()
  }
  closeDropdown()
}

function toggleBold() { isEditorReady.value && props.editor.chain().focus().toggleBold().run() }
function toggleItalic() { isEditorReady.value && props.editor.chain().focus().toggleItalic().run() }
function toggleUnderline() { isEditorReady.value && props.editor.chain().focus().toggleUnderline().run() }
function toggleStrike() { isEditorReady.value && props.editor.chain().focus().toggleStrike().run() }
function toggleSubscript() { isEditorReady.value && props.editor.chain().focus().toggleSubscript().run() }
function toggleSuperscript() { isEditorReady.value && props.editor.chain().focus().toggleSuperscript().run() }

function setTextColor(color) {
  if (!isEditorReady.value) return
  props.editor.chain().focus().setColor(color).run()
  closeDropdown()
}

function removeTextColor() {
  if (!isEditorReady.value) return
  props.editor.chain().focus().unsetColor().run()
  closeDropdown()
}

function setHighlight(color) {
  if (!isEditorReady.value) return
  props.editor.chain().focus().toggleHighlight({ color }).run()
  closeDropdown()
}

function removeHighlight() {
  if (!isEditorReady.value) return
  props.editor.chain().focus().unsetHighlight().run()
  closeDropdown()
}

function setAlign(alignment) {
  isEditorReady.value && props.editor.chain().focus().setTextAlign(alignment).run()
}

function toggleBulletList() { isEditorReady.value && props.editor.chain().focus().toggleBulletList().run() }
function toggleOrderedList() { isEditorReady.value && props.editor.chain().focus().toggleOrderedList().run() }

function increaseIndent() {
  if (!isEditorReady.value) return
  if (props.editor.isActive('listItem')) {
    props.editor.chain().focus().sinkListItem('listItem').run()
  }
}

function decreaseIndent() {
  if (!isEditorReady.value) return
  if (props.editor.isActive('listItem')) {
    props.editor.chain().focus().liftListItem('listItem').run()
  }
}

function toggleBlockquote() { isEditorReady.value && props.editor.chain().focus().toggleBlockquote().run() }
function toggleCodeBlock() { isEditorReady.value && props.editor.chain().focus().toggleCodeBlock().run() }
function insertHorizontalRule() { isEditorReady.value && props.editor.chain().focus().setHorizontalRule().run() }
function insertPageBreak() { isEditorReady.value && props.editor.chain().focus().insertHardPageBreak().run() }
function clearFormatting() { isEditorReady.value && props.editor.chain().focus().clearNodes().unsetAllMarks().run() }

function openLinkDialog() {
  if (!isEditorReady.value) return
  const attrs = props.editor.getAttributes('link')
  linkUrl.value = attrs.href || ''
  const { from, to } = props.editor.state.selection
  linkText.value = props.editor.state.doc.textBetween(from, to, ' ')
  showLinkDialog.value = true
  nextTick(() => linkInputRef.value?.focus())
}

function closeLinkDialog() {
  showLinkDialog.value = false
  linkUrl.value = ''
  linkText.value = ''
}

function applyLink() {
  if (!linkUrl.value || !isEditorReady.value) return
  let url = linkUrl.value.trim()
  if (!/^https?:\/\//i.test(url) && !url.startsWith('mailto:')) {
    url = 'https://' + url
  }
  props.editor.chain().focus().setLink({ href: url }).run()
  closeLinkDialog()
}

function removeLink() {
  isEditorReady.value && props.editor.chain().focus().unsetLink().run()
  closeLinkDialog()
}

function triggerImageUpload() { imageInputRef.value?.click() }

function handleImageSelect(e) {
  const file = e.target.files?.[0]
  if (!file || !isEditorReady.value) return
  const reader = new FileReader()
  reader.onload = (event) => {
    props.editor.chain().focus().setImage({ src: event.target.result }).run()
  }
  reader.readAsDataURL(file)
  e.target.value = ''
}

function insertTable(rows, cols) {
  if (!isEditorReady.value) return
  props.editor.chain().focus().insertTable({ rows, cols, withHeaderRow: true }).run()
  closeDropdown()
  tableHover.value = { rows: 1, cols: 1 }
}

// Click outside handler
function handleClickOutside(e) {
  if (activeDropdown.value && !e.target.closest('.dropdown-panel') && !e.target.closest('.dropdown-btn') && !e.target.closest('.toolbar-btn')) {
    closeDropdown()
  }
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onUnmounted(() => document.removeEventListener('click', handleClickOutside))
</script>

<style scoped>
.toolbar-container {
  background: linear-gradient(to bottom, #fafafa 0%, #f5f5f5 100%);
  border-bottom: 1px solid #e0e0e0;
  padding: 8px 12px;
  overflow-x: auto;
}

.toolbar-content {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 8px;
  flex-wrap: wrap;
  max-width: 100%;
  margin: 0 auto;
}

.toolbar-section {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.section-label {
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #9ca3af;
  padding-left: 2px;
}

.section-buttons {
  display: flex;
  align-items: center;
  gap: 2px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 3px;
}

.toolbar-divider {
  width: 1px;
  height: 52px;
  background: linear-gradient(to bottom, transparent 0%, #d1d5db 20%, #d1d5db 80%, transparent 100%);
  margin: 0 4px;
  align-self: stretch;
}

.toolbar-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: #4b5563;
  cursor: pointer;
  transition: all 0.15s ease;
}

.toolbar-btn .material-symbols-rounded {
  font-size: 22px;
}

.toolbar-btn:hover {
  background: #f3f4f6;
  color: #1f2937;
}

.toolbar-btn.active {
  background: rgb(var(--color-primary-100));
  color: rgb(var(--color-primary-600));
}

.toolbar-btn.disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.toolbar-btn.disabled:hover {
  background: transparent;
  color: #4b5563;
}

.toolbar-btn.accent {
  background: rgb(var(--color-primary-500));
  color: white;
}

.toolbar-btn.accent:hover {
  background: rgb(var(--color-primary-600));
}

.dropdown-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  height: 32px;
  padding: 0 8px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: #374151;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
}

.dropdown-btn:hover {
  background: #f3f4f6;
}

.dropdown-btn .btn-text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.dropdown-btn .dropdown-arrow {
  font-size: 18px;
  color: #9ca3af;
}

.font-family-btn {
  min-width: 100px;
  max-width: 120px;
}

.font-size-btn {
  min-width: 50px;
}

.style-btn {
  min-width: 90px;
}

.color-btn {
  position: relative;
  padding-bottom: 5px;
}

.text-color-icon {
  font-size: 18px;
  font-weight: 700;
  color: #374151;
  font-family: 'Outfit', Arial, sans-serif;
}

.color-indicator {
  position: absolute;
  bottom: 3px;
  left: 50%;
  transform: translateX(-50%);
  width: 16px;
  height: 3px;
  border-radius: 2px;
}

.toolbar-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 16px;
  color: #9ca3af;
  font-size: 13px;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* Print styles - hide toolbar when printing */
@media print {
  .toolbar-container {
    display: none !important;
  }
}

/* Responsive - Medium screens (laptops) */
@media (max-width: 1400px) {
  .toolbar-container {
    padding: 4px 8px;
  }
  
  .toolbar-content {
    gap: 4px;
  }
  
  .section-label {
    display: none; /* Hide labels on smaller screens */
  }
  
  .toolbar-section {
    gap: 0;
  }
  
  .section-buttons {
    padding: 2px;
  }
  
  .toolbar-btn {
    width: 28px;
    height: 28px;
  }
  
  .toolbar-btn .material-symbols-rounded {
    font-size: 20px;
  }
  
  .toolbar-divider {
    height: 32px;
    margin: 0 2px;
  }
  
  .dropdown-btn {
    height: 28px;
    padding: 0 6px;
    font-size: 12px;
  }
  
  .font-family-btn {
    min-width: 80px;
    max-width: 100px;
  }
  
  .font-size-btn {
    min-width: 40px;
  }
  
  .style-btn {
    min-width: 70px;
  }
}

/* Responsive - Small screens (small laptops/tablets) */
@media (max-width: 1100px) {
  .toolbar-container {
    padding: 3px 6px;
  }
  
  .toolbar-content {
    gap: 2px;
    justify-content: center;
  }
  
  .toolbar-btn {
    width: 26px;
    height: 26px;
  }
  
  .toolbar-btn .material-symbols-rounded {
    font-size: 18px;
  }
  
  .toolbar-divider {
    height: 28px;
    margin: 0 1px;
  }
  
  .dropdown-btn {
    height: 26px;
    padding: 0 4px;
    font-size: 11px;
    gap: 2px;
  }
  
  .font-family-btn {
    min-width: 60px;
    max-width: 80px;
  }
  
  .font-size-btn {
    min-width: 36px;
  }
  
  .style-btn {
    min-width: 50px;
  }
  
  .dropdown-btn .dropdown-arrow {
    font-size: 14px;
  }
}
</style>

<style>
/* Global styles for teleported dropdowns */
.dropdown-panel {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15), 0 2px 10px rgba(0, 0, 0, 0.05);
  overflow: hidden;
}

.dropdown-content {
  overflow-y: auto;
}

.dropdown-item {
  display: block;
  width: 100%;
  padding: 10px 14px;
  text-align: left;
  font-size: 14px;
  color: #374151;
  border: none;
  background: #ffffff;
  cursor: pointer;
  transition: background 0.1s;
}

.dropdown-item:hover {
  background: #f3f4f6;
}

.dropdown-item.selected {
  background: rgb(var(--color-primary-50));
  color: rgb(var(--color-primary-600));
  font-weight: 500;
}

.color-picker-content {
  padding: 14px;
  background: #ffffff;
}

.color-picker-label,
.table-picker-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #9ca3af;
  margin-bottom: 10px;
}

.color-grid {
  display: grid;
  grid-template-columns: repeat(8, 1fr);
  gap: 4px;
}

.highlight-grid {
  grid-template-columns: repeat(6, 1fr);
}

.color-swatch {
  width: 26px;
  height: 26px;
  border: 2px solid #ffffff;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s;
  box-shadow: inset 0 0 0 1px rgba(0,0,0,0.1);
}

.color-swatch:hover {
  transform: scale(1.15);
  z-index: 1;
  box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.color-swatch.selected {
  border: 2px solid rgb(var(--color-primary-500));
  box-shadow: 0 0 0 2px rgb(var(--color-primary-200));
}

.color-action {
  display: block;
  width: 100%;
  padding: 10px;
  margin-top: 10px;
  text-align: center;
  font-size: 12px;
  font-weight: 500;
  color: #6b7280;
  background: #f3f4f6;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s;
}

.color-action:hover {
  background: #e5e7eb;
  color: #374151;
}

.table-picker-content {
  padding: 14px;
  background: #ffffff;
}

.table-grid {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.table-row {
  display: flex;
  gap: 3px;
}

.table-cell {
  width: 22px;
  height: 22px;
  border: 1px solid #d1d5db;
  border-radius: 3px;
  background: #ffffff;
  cursor: pointer;
  transition: all 0.1s;
}

.table-cell:hover {
  border-color: rgb(var(--color-primary-400));
}

.table-cell.active {
  background: rgb(var(--color-primary-500));
  border-color: rgb(var(--color-primary-600));
}

.table-size-label {
  text-align: center;
  font-size: 12px;
  font-weight: 500;
  color: #6b7280;
  margin-top: 10px;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
}

.link-dialog {
  background: #ffffff;
  border-radius: 16px;
  box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
  width: 420px;
  max-width: 90vw;
}

.link-dialog-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 22px;
  border-bottom: 1px solid #e5e7eb;
}

.link-dialog-header h3 {
  font-size: 17px;
  font-weight: 600;
  color: #111827;
  margin: 0;
}

.close-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border: none;
  background: transparent;
  border-radius: 8px;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.15s;
}

.close-btn:hover {
  background: #f3f4f6;
  color: #374151;
}

.link-dialog-body {
  padding: 22px;
}

.link-dialog-body label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  margin-bottom: 6px;
}

.link-dialog-body input {
  display: block;
  width: 100%;
  padding: 12px 14px;
  font-size: 14px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #ffffff;
  color: #111827;
  margin-bottom: 18px;
  outline: none;
  transition: all 0.15s;
}

.link-dialog-body input:focus {
  border-color: rgb(var(--color-primary-500));
  box-shadow: 0 0 0 3px rgba(var(--color-primary-500), 0.15);
}

.link-dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 18px 22px;
  border-top: 1px solid #e5e7eb;
  background: #fafafa;
  border-radius: 0 0 16px 16px;
}

.link-dialog .dialog-btn {
  padding: 10px 20px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  transition: all 0.15s;
}

.link-dialog .dialog-btn-primary {
  background: rgb(var(--color-primary-500));
  color: white;
}

.link-dialog .dialog-btn-primary:hover {
  background: rgb(var(--color-primary-600));
}

.link-dialog .dialog-btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.link-dialog .dialog-btn-secondary {
  background: #ffffff;
  color: #374151;
  border: 1px solid #e5e7eb;
}

.link-dialog .dialog-btn-secondary:hover {
  background: #f3f4f6;
}

.link-dialog .dialog-btn-danger {
  background: #ef4444;
  color: white;
}

.link-dialog .dialog-btn-danger:hover {
  background: #dc2626;
}
</style>
