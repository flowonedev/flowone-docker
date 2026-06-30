<template>
  <div v-if="visible" class="pointer-events-none absolute inset-0 z-20">
    <!-- Editing group indicator (dashed border while inside a group) -->
    <div
      v-if="editingGroupBounds"
      class="absolute border-2 border-dashed border-blue-400/50 rounded-sm pointer-events-none"
      :style="editingGroupStyle"
    />

    <div
      class="absolute border border-primary-500 pointer-events-none"
      :style="boundsStyle"
    >
      <!-- 4-corner resize handles -->
      <div
        v-if="!readonly && !itemLocked"
        class="absolute -top-[5px] -left-[5px] w-[10px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-nw-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors pointer-events-auto"
        :style="{ transform: `scale(${uiScale})` }"
        @pointerdown.stop="$emit('resize-start', 'nw', $event)"
      />
      <div
        v-if="!readonly && !itemLocked"
        class="absolute -top-[5px] -right-[5px] w-[10px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-ne-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors pointer-events-auto"
        :style="{ transform: `scale(${uiScale})` }"
        @pointerdown.stop="$emit('resize-start', 'ne', $event)"
      />
      <div
        v-if="!readonly && !itemLocked"
        class="absolute -bottom-[5px] -left-[5px] w-[10px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-sw-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors pointer-events-auto"
        :style="{ transform: `scale(${uiScale})` }"
        @pointerdown.stop="$emit('resize-start', 'sw', $event)"
      />
      <div
        v-if="!readonly && !itemLocked"
        class="absolute -bottom-[5px] -right-[5px] w-[10px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-se-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors pointer-events-auto"
        :style="{ transform: `scale(${uiScale})` }"
        @pointerdown.stop="$emit('resize-start', 'se', $event)"
      />

      <!-- 4 midpoint edge resize handles (pill-shaped) -->
      <template v-if="!readonly && !itemLocked">
        <div
          class="absolute -top-[5px] left-1/2 w-[28px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-n-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors pointer-events-auto"
          :style="{ transform: `translateX(-50%) scale(${uiScale})` }"
          @pointerdown.stop="$emit('resize-start', 'n', $event)"
        />
        <div
          class="absolute -right-[5px] top-1/2 w-[10px] h-[28px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-e-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors pointer-events-auto"
          :style="{ transform: `translateY(-50%) scale(${uiScale})` }"
          @pointerdown.stop="$emit('resize-start', 'e', $event)"
        />
        <div
          class="absolute -bottom-[5px] left-1/2 w-[28px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-s-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors pointer-events-auto"
          :style="{ transform: `translateX(-50%) scale(${uiScale})` }"
          @pointerdown.stop="$emit('resize-start', 's', $event)"
        />
        <div
          class="absolute -left-[5px] top-1/2 w-[10px] h-[28px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-w-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors pointer-events-auto"
          :style="{ transform: `translateY(-50%) scale(${uiScale})` }"
          @pointerdown.stop="$emit('resize-start', 'w', $event)"
        />
      </template>

      <!-- Rotation handle (bottom-center with stem) -->
      <div
        v-if="!readonly && !itemLocked && !multiSelected"
        class="absolute -bottom-[22px] left-1/2 flex flex-col items-center pointer-events-auto cursor-alias z-30"
        :style="{ transform: `translateX(-50%) scale(${uiScale})` }"
        @pointerdown.stop="$emit('rotation-start', $event)"
      >
        <div class="w-px h-[8px] bg-primary-500"></div>
        <div class="w-[8px] h-[8px] rounded-full bg-white border-[1.5px] border-primary-500 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors" />
      </div>

      <!-- Dimensions overlay (shown while resizing or dragging) -->
      <div
        v-if="showDimensions"
        class="absolute -bottom-7 right-0 bg-surface-800/90 text-white text-[10px] font-mono px-2 py-0.5 rounded-md z-40 whitespace-nowrap pointer-events-none shadow-md"
        :style="{ transform: `scale(${uiScale})`, transformOrigin: 'top right' }"
      >
        <template v-if="isResizing">{{ Math.round(selectedBounds.width) }} x {{ Math.round(selectedBounds.height) }} px</template>
        <template v-else>{{ Math.round(selectedBounds.x) }}, {{ Math.round(selectedBounds.y) }} | {{ Math.round(selectedBounds.width) }} x {{ Math.round(selectedBounds.height) }}</template>
      </div>

      <!-- Action bar -->
      <div
        v-if="showActionBar && actionBarReady"
        class="absolute -top-10 left-1/2 flex items-center gap-1
               bg-white dark:bg-surface-800 rounded-xl shadow-lg border border-surface-200
               dark:border-surface-600 px-2 py-1.5 pointer-events-auto"
        :style="{ transform: `translateX(-50%) scale(${uiScale})`, transformOrigin: 'bottom center' }"
      >
        <button
          v-for="action in actions"
          :key="action.id"
          class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          :title="action.label"
          @pointerdown.stop
          @click.stop="$emit('action', action.id)"
        >
          <span class="material-symbols-rounded text-sm">{{ action.icon }}</span>
        </button>
      </div>
    </div>

    <!-- Per-item outlines (multi-select) -->
    <div
      v-for="b in perItemStyles"
      :key="'pib-' + b.id"
      class="absolute border border-primary-400/70 pointer-events-none"
      :style="b.style"
    />

    <!-- Rubber band -->
    <div
      v-if="rubberBand"
      class="absolute border border-primary-400 bg-primary-400/10 pointer-events-none"
      :style="rubberBandStyle"
    />
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  selectedBounds: Object,
  perItemBounds: { type: Array, default: () => [] },
  editingGroupBounds: Object,
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
  multiSelected: Boolean,
  itemLocked: Boolean,
  itemScale: { type: Number, default: 1 },
  itemRotation: { type: Number, default: 0 },
  rubberBand: Object,
  readonly: Boolean,
  isDragging: Boolean,
  isResizing: Boolean,
})

const showDimensions = computed(() =>
  props.selectedBounds && (props.isResizing || props.isDragging)
)

const uiScale = computed(() => Math.min(1, Math.max(0.4, props.zoom)))

defineEmits(['resize-start', 'rotation-start', 'action'])

const actionBarReady = ref(false)
let actionBarTimer = null

watch(() => props.selectedBounds, (val) => {
  clearTimeout(actionBarTimer)
  actionBarReady.value = false
  if (val) {
    actionBarTimer = setTimeout(() => { actionBarReady.value = true }, 200)
  }
})

const visible = computed(() => props.selectedBounds || props.rubberBand || props.editingGroupBounds)

const editingGroupStyle = computed(() => {
  if (!props.editingGroupBounds) return { display: 'none' }
  const b = props.editingGroupBounds
  return {
    left: `${b.x * props.zoom + props.panX}px`,
    top: `${b.y * props.zoom + props.panY}px`,
    width: `${b.width * props.zoom}px`,
    height: `${b.height * props.zoom}px`,
  }
})

const showActionBar = computed(() =>
  props.selectedBounds && !props.multiSelected && !props.readonly
)

const boundsStyle = computed(() => {
  if (!props.selectedBounds) return { display: 'none' }
  const b = props.selectedBounds
  const s = props.itemScale
  const rot = props.itemRotation || 0
  const style = {
    left: `${b.x * props.zoom + props.panX}px`,
    top: `${b.y * props.zoom + props.panY}px`,
    width: `${b.width * props.zoom}px`,
    height: `${b.height * props.zoom}px`,
  }
  const transforms = []
  if (rot !== 0) transforms.push(`rotate(${rot}deg)`)
  if (s !== 1) transforms.push(`scale(${s})`)
  if (transforms.length) {
    style.transform = transforms.join(' ')
    style.transformOrigin = 'center center'
  }
  return style
})

const perItemStyles = computed(() =>
  (props.perItemBounds || []).map(b => {
    const style = {
      left: `${b.x * props.zoom + props.panX}px`,
      top: `${b.y * props.zoom + props.panY}px`,
      width: `${b.width * props.zoom}px`,
      height: `${b.height * props.zoom}px`,
    }
    if (b.rotation) {
      style.transform = `rotate(${b.rotation}deg)`
      style.transformOrigin = 'center center'
    }
    return { id: b.id, style }
  })
)

const rubberBandStyle = computed(() => {
  if (!props.rubberBand) return { display: 'none' }
  const r = props.rubberBand
  return {
    left: `${r.x * props.zoom + props.panX}px`,
    top: `${r.y * props.zoom + props.panY}px`,
    width: `${r.width * props.zoom}px`,
    height: `${r.height * props.zoom}px`,
  }
})

const actions = computed(() => {
  const list = []
  if (!props.itemLocked) {
    list.push({ id: 'connect', icon: 'trending_flat', label: 'Connect to...' })
  }
  list.push({
    id: props.itemLocked ? 'unlock' : 'lock',
    icon: props.itemLocked ? 'lock_open' : 'lock',
    label: props.itemLocked ? 'Unlock' : 'Lock',
  })
  if (!props.itemLocked) {
    list.push({ id: 'duplicate', icon: 'content_copy', label: 'Duplicate' })
    list.push({ id: 'delete', icon: 'delete', label: 'Delete' })
  }
  return list
})
</script>
