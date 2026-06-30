<template>
  <Teleport to="body">
    <div 
      v-if="show"
      class="context-menu"
      :style="menuStyle"
      @click.stop
      @contextmenu.prevent
    >
      <div class="context-menu-content">
        <!-- Common options -->
        <button 
          class="context-menu-item"
          @click="handleAction('duplicate')"
        >
          <span class="material-symbols-rounded">content_copy</span>
          <span>Duplicate</span>
          <span class="shortcut">Ctrl+D</span>
        </button>
        
        <button 
          class="context-menu-item"
          @click="handleAction('copy')"
        >
          <span class="material-symbols-rounded">file_copy</span>
          <span>Copy</span>
          <span class="shortcut">Ctrl+C</span>
        </button>
        
        <button 
          class="context-menu-item"
          @click="handleAction('cut')"
        >
          <span class="material-symbols-rounded">content_cut</span>
          <span>Cut</span>
          <span class="shortcut">Ctrl+X</span>
        </button>
        
        <div class="context-menu-divider"></div>
        
        <!-- Layer options -->
        <button 
          class="context-menu-item"
          @click="handleAction('bringToFront')"
        >
          <span class="material-symbols-rounded">flip_to_front</span>
          <span>Bring to Front</span>
        </button>
        
        <button 
          class="context-menu-item"
          @click="handleAction('sendToBack')"
        >
          <span class="material-symbols-rounded">flip_to_back</span>
          <span>Send to Back</span>
        </button>
        
        <div class="context-menu-divider"></div>
        
        <!-- Delete -->
        <button 
          class="context-menu-item danger"
          @click="handleAction('delete')"
        >
          <span class="material-symbols-rounded">delete</span>
          <span>Delete</span>
          <span class="shortcut">Del</span>
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  x: {
    type: Number,
    default: 0,
  },
  y: {
    type: Number,
    default: 0,
  },
  objectType: {
    type: String,
    default: null,
  },
})

const emit = defineEmits(['close', 'action'])

const menuStyle = computed(() => {
  // Position menu, keeping it within viewport
  const menuWidth = 200
  const menuHeight = 300 // Approximate
  
  let x = props.x
  let y = props.y
  
  // Keep within right edge
  if (x + menuWidth > window.innerWidth) {
    x = window.innerWidth - menuWidth - 10
  }
  
  // Keep within bottom edge
  if (y + menuHeight > window.innerHeight) {
    y = window.innerHeight - menuHeight - 10
  }
  
  return {
    left: `${x}px`,
    top: `${y}px`,
  }
})

function handleAction(action) {
  emit('action', action)
  emit('close')
}

function handleClickOutside(e) {
  if (props.show) {
    emit('close')
  }
}

function handleKeydown(e) {
  if (e.key === 'Escape' && props.show) {
    emit('close')
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
.context-menu {
  position: fixed;
  z-index: 10000;
  min-width: 180px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15), 0 2px 10px rgba(0, 0, 0, 0.05);
  overflow: hidden;
  animation: contextMenuIn 0.1s ease-out;
}

:root.dark .context-menu {
  background: #26262e;
  border-color: #3d3d47;
}

@keyframes contextMenuIn {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.context-menu-content {
  padding: 6px;
}

.context-menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 12px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: #374151;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
  transition: background 0.1s;
}

:root.dark .context-menu-item {
  color: #e0e0e0;
}

.context-menu-item:hover {
  background: #f3f4f6;
}

:root.dark .context-menu-item:hover {
  background: #32323c;
}

.context-menu-item .material-symbols-rounded {
  font-size: 18px;
  color: #6b7280;
}

:root.dark .context-menu-item .material-symbols-rounded {
  color: #9ca3af;
}

.context-menu-item span:nth-child(2) {
  flex: 1;
}

.context-menu-item .shortcut {
  font-size: 11px;
  color: #9ca3af;
  font-family: system-ui, -apple-system, sans-serif;
}

:root.dark .context-menu-item .shortcut {
  color: #6b7280;
}

.context-menu-item.danger {
  color: #dc2626;
}

.context-menu-item.danger .material-symbols-rounded {
  color: #dc2626;
}

.context-menu-item.danger:hover {
  background: #fef2f2;
}

:root.dark .context-menu-item.danger:hover {
  background: rgba(220, 38, 38, 0.15);
}

.context-menu-divider {
  height: 1px;
  margin: 4px 0;
  background: #e5e7eb;
}

:root.dark .context-menu-divider {
  background: #3d3d47;
}
</style>

