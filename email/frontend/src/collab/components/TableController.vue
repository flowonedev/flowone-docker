<template>
  <div 
    v-if="showControls && editor && tableElement"
    class="table-controller"
    :class="{ 'table-hovered': isHovered }"
    :style="controllerStyle"
  >
    <!-- Column controls -->
    <div class="table-column-controls">
      <button
        v-for="(col, index) in columnCount"
        :key="`col-${index}`"
        class="table-control-btn column-btn"
        :class="{ 'active': selectedColumn === index }"
        :style="{ left: getColumnPosition(index) + 'px' }"
        @click="selectColumn(index)"
        @mouseenter="hoverColumn = index"
        @mouseleave="hoverColumn = null"
      >
        <div class="column-handle"></div>
        <!-- Add column button (appears on hover) -->
        <button
          v-if="hoverColumn === index && canEdit"
          class="table-add-btn column-add"
          @click.stop="addColumnAfter(index)"
          title="Add column"
        >
          <span class="material-symbols-rounded">add</span>
        </button>
        <!-- Remove column button (appears when column selected) -->
        <button
          v-if="selectedColumn === index && canEdit && columnCount > 1"
          class="table-remove-btn column-remove"
          @click.stop="removeColumn(index)"
          title="Remove column"
        >
          <span class="material-symbols-rounded">close</span>
        </button>
      </button>
      <!-- Add column at end -->
      <button
        v-if="canEdit"
        class="table-control-btn column-btn column-add-end"
        :style="{ left: getColumnPosition(columnCount) + 'px' }"
        @click="addColumnAfter(columnCount - 1)"
        title="Add column"
      >
        <span class="material-symbols-rounded">add</span>
      </button>
    </div>
    
    <!-- Row controls -->
    <div class="table-row-controls">
      <button
        v-for="(row, index) in rowCount"
        :key="`row-${index}`"
        class="table-control-btn row-btn"
        :class="{ 'active': selectedRow === index }"
        :style="{ top: getRowPosition(index) + 'px' }"
        @click="selectRow(index)"
        @mouseenter="hoverRow = index"
        @mouseleave="hoverRow = null"
      >
        <div class="row-handle"></div>
        <!-- Add row button (appears on hover) -->
        <button
          v-if="hoverRow === index && canEdit"
          class="table-add-btn row-add"
          @click.stop="addRowAfter(index)"
          title="Add row"
        >
          <span class="material-symbols-rounded">add</span>
        </button>
        <!-- Remove row button (appears when row selected) -->
        <button
          v-if="selectedRow === index && canEdit && rowCount > 1"
          class="table-remove-btn row-remove"
          @click.stop="removeRow(index)"
          title="Remove row"
        >
          <span class="material-symbols-rounded">close</span>
        </button>
      </button>
      <!-- Add row at end -->
      <button
        v-if="canEdit"
        class="table-control-btn row-btn row-add-end"
        :style="{ top: getRowPosition(rowCount) + 'px' }"
        @click="addRowAfter(rowCount - 1)"
        title="Add row"
      >
        <span class="material-symbols-rounded">add</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  editor: {
    type: Object,
    default: null
  },
  tableElement: {
    type: HTMLElement,
    default: null
  },
  visible: {
    type: Boolean,
    default: false
  },
  canEdit: {
    type: Boolean,
    default: true
  }
})

const selectedColumn = ref(null)
const selectedRow = ref(null)
const hoverColumn = ref(null)
const hoverRow = ref(null)
const isHovered = ref(false)

// Watch for table hover
let hoverCleanup = null
watch(() => props.tableElement, (table) => {
  if (hoverCleanup) {
    hoverCleanup()
    hoverCleanup = null
  }
  
  if (!table) return
  
  const handleMouseEnter = () => {
    isHovered.value = true
  }
  
  const handleMouseLeave = (e) => {
    // Don't hide if mouse is moving to controller
    if (!e.relatedTarget || !e.relatedTarget.closest('.table-controller')) {
      isHovered.value = false
    }
  }
  
  table.addEventListener('mouseenter', handleMouseEnter)
  table.addEventListener('mouseleave', handleMouseLeave)
  
  hoverCleanup = () => {
    table.removeEventListener('mouseenter', handleMouseEnter)
    table.removeEventListener('mouseleave', handleMouseLeave)
  }
}, { immediate: true })

onUnmounted(() => {
  if (hoverCleanup) {
    hoverCleanup()
  }
})

const showControls = computed(() => {
  return props.visible || isHovered.value
})

const columnCount = computed(() => {
  if (!props.tableElement) return 0
  const firstRow = props.tableElement.querySelector('tr')
  if (!firstRow) return 0
  return firstRow.querySelectorAll('th, td').length
})

const rowCount = computed(() => {
  if (!props.tableElement) return 0
  return props.tableElement.querySelectorAll('tr').length
})

const controllerStyle = computed(() => {
  if (!props.tableElement) return {}
  const rect = props.tableElement.getBoundingClientRect()
  const container = props.tableElement.closest('.collab-editor-content')
  if (!container) return {}
  
  const containerRect = container.getBoundingClientRect()
  
  return {
    position: 'absolute',
    top: `${rect.top - containerRect.top + container.scrollTop}px`,
    left: `${rect.left - containerRect.left + container.scrollLeft}px`,
    width: `${rect.width}px`,
    height: `${rect.height}px`,
    pointerEvents: 'none',
    zIndex: 10
  }
})

function getColumnPosition(index) {
  if (!props.tableElement) return 0
  const firstRow = props.tableElement.querySelector('tr')
  if (!firstRow) return 0
  
  const cells = firstRow.querySelectorAll('th, td')
  if (index >= cells.length) {
    // Position after last column
    const lastCell = cells[cells.length - 1]
    if (lastCell) {
      const rect = lastCell.getBoundingClientRect()
      const tableRect = props.tableElement.getBoundingClientRect()
      return rect.right - tableRect.left
    }
    return props.tableElement.offsetWidth
  }
  
  const cell = cells[index]
  if (cell) {
    const rect = cell.getBoundingClientRect()
    const tableRect = props.tableElement.getBoundingClientRect()
    return rect.left - tableRect.left
  }
  
  return 0
}

function getRowPosition(index) {
  if (!props.tableElement) return 0
  const rows = props.tableElement.querySelectorAll('tr')
  if (index >= rows.length) {
    // Position after last row
    const lastRow = rows[rows.length - 1]
    if (lastRow) {
      const rect = lastRow.getBoundingClientRect()
      const tableRect = props.tableElement.getBoundingClientRect()
      return rect.bottom - tableRect.top
    }
    return props.tableElement.offsetHeight
  }
  
  const row = rows[index]
  if (row) {
    const rect = row.getBoundingClientRect()
    const tableRect = props.tableElement.getBoundingClientRect()
    return rect.top - tableRect.top
  }
  
  return 0
}

function selectColumn(index) {
  selectedColumn.value = selectedColumn.value === index ? null : index
  selectedRow.value = null
}

function selectRow(index) {
  selectedRow.value = selectedRow.value === index ? null : index
  selectedColumn.value = null
}

function addColumnAfter(index) {
  if (!props.editor || !props.canEdit) return
  
  // Move cursor to the cell in the first row at the specified column
  const firstRow = props.tableElement?.querySelector('tr')
  if (!firstRow) return
  
  const cells = firstRow.querySelectorAll('th, td')
  if (cells[index]) {
    try {
      // Click the cell to focus it, then add column
      cells[index].click()
      setTimeout(() => {
        props.editor.chain().focus().addColumnAfter().run()
      }, 10)
    } catch (e) {
      console.warn('Failed to add column:', e)
      props.editor.chain().focus().addColumnAfter().run()
    }
  }
}

function removeColumn(index) {
  if (!props.editor || !props.canEdit || columnCount.value <= 1) return
  
  const firstRow = props.tableElement?.querySelector('tr')
  if (!firstRow) return
  
  const cells = firstRow.querySelectorAll('th, td')
  if (cells[index]) {
    try {
      cells[index].click()
      setTimeout(() => {
        props.editor.chain().focus().deleteColumn().run()
      }, 10)
    } catch (e) {
      console.warn('Failed to remove column:', e)
      props.editor.chain().focus().deleteColumn().run()
    }
  }
  
  selectedColumn.value = null
}

function addRowAfter(index) {
  if (!props.editor || !props.canEdit) return
  
  const rows = props.tableElement?.querySelectorAll('tr')
  if (!rows || !rows[index]) return
  
  const firstCell = rows[index].querySelector('th, td')
  if (firstCell) {
    try {
      firstCell.click()
      setTimeout(() => {
        props.editor.chain().focus().addRowAfter().run()
      }, 10)
    } catch (e) {
      console.warn('Failed to add row:', e)
      props.editor.chain().focus().addRowAfter().run()
    }
  }
}

function removeRow(index) {
  if (!props.editor || !props.canEdit || rowCount.value <= 1) return
  
  const rows = props.tableElement?.querySelectorAll('tr')
  if (!rows || !rows[index]) return
  
  const firstCell = rows[index].querySelector('th, td')
  if (firstCell) {
    try {
      firstCell.click()
      setTimeout(() => {
        props.editor.chain().focus().deleteRow().run()
      }, 10)
    } catch (e) {
      console.warn('Failed to remove row:', e)
      props.editor.chain().focus().deleteRow().run()
    }
  }
  
  selectedRow.value = null
}

// Update positions on scroll/resize
let updateTimer = null
function updatePositions() {
  if (updateTimer) clearTimeout(updateTimer)
  updateTimer = setTimeout(() => {
    // Force reactivity update
    selectedColumn.value = selectedColumn.value
    selectedRow.value = selectedRow.value
  }, 100)
}

onMounted(() => {
  if (props.tableElement) {
    const container = props.tableElement.closest('.collab-editor-content')
    if (container) {
      container.addEventListener('scroll', updatePositions)
      window.addEventListener('resize', updatePositions)
    }
  }
})

onUnmounted(() => {
  if (updateTimer) clearTimeout(updateTimer)
  if (props.tableElement) {
    const container = props.tableElement.closest('.collab-editor-content')
    if (container) {
      container.removeEventListener('scroll', updatePositions)
      window.removeEventListener('resize', updatePositions)
    }
  }
})
</script>

<style scoped>
.table-controller {
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.2s;
}

.table-controller.table-hovered {
  opacity: 1;
}

.table-controller:hover {
  opacity: 1;
}

.table-control-btn {
  position: absolute;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 0;
  pointer-events: auto;
  z-index: 11;
}

.column-btn {
  top: -20px;
  width: 2px;
  height: 20px;
  transform: translateX(-50%);
}

.column-handle {
  width: 100%;
  height: 100%;
  background: #3b82f6;
  opacity: 0;
  transition: opacity 0.2s;
}

.column-btn:hover .column-handle,
.column-btn.active .column-handle {
  opacity: 1;
}

.row-btn {
  left: -20px;
  width: 20px;
  height: 2px;
  transform: translateY(-50%);
}

.row-handle {
  width: 100%;
  height: 100%;
  background: #3b82f6;
  opacity: 0;
  transition: opacity 0.2s;
}

.row-btn:hover .row-handle,
.row-btn.active .row-handle {
  opacity: 1;
}

.table-add-btn,
.table-remove-btn {
  position: absolute;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  pointer-events: auto;
  z-index: 12;
  transition: all 0.2s;
}

.table-add-btn {
  background: #3b82f6;
  color: white;
}

.table-add-btn:hover {
  background: #2563eb;
  transform: scale(1.1);
}

.table-remove-btn {
  background: #ef4444;
  color: white;
}

.table-remove-btn:hover {
  background: #dc2626;
  transform: scale(1.1);
}

.column-add {
  top: -30px;
  left: 50%;
  transform: translateX(-50%);
}

.column-remove {
  top: -30px;
  left: 50%;
  transform: translateX(-50%);
}

.row-add {
  left: -30px;
  top: 50%;
  transform: translateY(-50%);
}

.row-remove {
  left: -30px;
  top: 50%;
  transform: translateY(-50%);
}

.column-add-end {
  top: -20px;
  width: 20px;
  height: 20px;
  background: #3b82f6;
  border-radius: 50%;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  transform: translateX(-50%);
}

.column-add-end:hover {
  background: #2563eb;
  transform: translateX(-50%) scale(1.1);
}

.row-add-end {
  left: -20px;
  width: 20px;
  height: 20px;
  background: #3b82f6;
  border-radius: 50%;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  transform: translateY(-50%);
}

.row-add-end:hover {
  background: #2563eb;
  transform: translateY(-50%) scale(1.1);
}

.table-add-btn .material-symbols-rounded,
.table-remove-btn .material-symbols-rounded,
.column-add-end .material-symbols-rounded,
.row-add-end .material-symbols-rounded {
  font-size: 14px;
}
</style>

