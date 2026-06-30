<template>
  <div
    ref="containerRef"
    class="relative w-full h-full overflow-hidden"
    :style="{ touchAction: 'none', userSelect: interacting ? 'none' : undefined, backgroundColor: props.board?.background_color || '#f5f5f5' }"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="onPointerUp"
    @dblclick="onDoubleClick"
    @contextmenu.prevent="onContextMenu"
  >
    <!-- Background image (lowest layer) -->
    <div
      v-if="bgImageStyle && Object.keys(bgImageStyle).length"
      class="absolute inset-0 pointer-events-none"
      style="z-index: 0;"
      :style="bgImageStyle"
    />

    <!-- Dot grid background -->
    <div
      v-if="!store.presentationMode || store.presShowBackground"
      class="absolute inset-0 pointer-events-none"
      style="z-index: 1;"
      :style="dotGridStyle"
    />

    <!-- Background effects overlay (gradient, vignette, blur) -->
    <div
      v-if="bgEffectStyles"
      class="absolute inset-0 pointer-events-none"
      style="z-index: 2;"
      :style="bgEffectStyles"
    />
    <!-- Grain noise canvas -->
    <canvas
      v-if="grainEnabled"
      ref="grainCanvas"
      class="absolute inset-0 pointer-events-none"
      style="z-index: 2;"
      :style="grainCanvasStyle"
    />

    <!-- PixiJS canvas renders here (ABOVE bg effects, below DOM overlays) -->
    <div
      ref="pixiMount"
      class="absolute inset-0 pointer-events-none"
      :style="{ zIndex: 3, willChange: (store.presentationMode || canvasAnimating) ? 'transform, contents' : 'auto' }"
    />

    <!-- Canvas error state (WebGL context loss / render failure) -->
    <div
      v-if="canvasError"
      class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-surface-900/80 backdrop-blur-sm"
      style="z-index: 50;"
    >
      <div class="flex flex-col items-center gap-3 text-center px-6 py-5 rounded-2xl bg-white dark:bg-surface-800 shadow-lg border border-surface-200 dark:border-surface-700 max-w-sm">
        <span class="material-symbols-outlined text-4xl text-amber-500">warning</span>
        <p class="text-sm font-medium text-surface-800 dark:text-surface-100">
          {{ canvasError === 'context-lost' ? 'The graphics context was lost.' : 'The canvas failed to render this board.' }}
        </p>
        <div class="flex items-center gap-2">
          <button
            class="px-4 py-2 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors"
            @click="recoverCanvas"
          >
            Reload canvas
          </button>
          <button
            class="px-4 py-2 rounded-xl bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-200 text-sm font-medium transition-colors"
            @click="emit('renderer-fallback')"
          >
            Use DOM renderer
          </button>
        </div>
      </div>
    </div>

    <!-- Legacy canvas strokes SVG layer -->
    <svg
      v-if="canvasStrokes.length && !canvasAnimating"
      class="absolute inset-0 pointer-events-none"
      style="z-index: 4; overflow: visible;"
    >
      <g :transform="`translate(${store.panX}, ${store.panY}) scale(${store.zoom})`">
        <path
          v-for="stroke in canvasStrokes"
          :key="'cs-' + stroke.id"
          :d="stroke.svgPath"
          :fill="stroke.eraser ? 'none' : (stroke.color || '#000000')"
          :stroke="stroke.eraser ? (props.board?.background_color || '#f5f5f5') : 'none'"
          :stroke-width="stroke.eraser ? stroke.width : 0"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </g>
    </svg>

    <!-- DOM backdrop-blur overlay (CSS backdrop-filter has no WebGL equivalent) -->
    <BackdropBlurOverlay
      :items="store.currentBoard?.items || []"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :animating="canvasAnimating"
    />

    <!-- DOM text overlay (crisp vector text at any zoom) — kept visible during presentation transitions -->
    <TextOverlay
      :items="store.currentBoard?.items || []"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :editing-item-id="editingItem?.id"
      :animating="canvasAnimating"
    />

    <!-- DOM drawing overlay (vector SVG strokes — avoids Pixi GPU triangulation artifacts on self-intersecting paths) -->
    <DrawingOverlay
      :items="store.currentBoard?.items || []"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :animating="canvasAnimating"
    />

    <!-- DOM media overlay (real video/YouTube/audio playback over the Pixi canvas) -->
    <MediaOverlay
      :items="store.currentBoard?.items || []"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :selected-ids="store.selectedItemIds"
      :editing-item-id="editingItem?.id"
      :presentation-mode="store.presentationMode"
      :readonly="props.readonly"
      :animating="canvasAnimating"
    />

    <!-- Overlays -->
    <AlignGuides
      :guides="dragGuides"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :container-width="containerWidth"
      :container-height="containerHeight"
    />

    <CornerRadiusHandles
      v-if="singleSelectedItem && !store.presentationMode && !props.readonly"
      :item="singleSelectedItem"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
    />

    <BoxModelOverlay
      v-if="singleSelectedItem && !store.presentationMode"
      :item="singleSelectedItem"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :selected="true"
      :presentation-mode="store.presentationMode"
    />

    <ComponentBadge
      v-if="!canvasAnimating"
      :items="store.currentBoard?.items || []"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :presentation-mode="store.presentationMode"
    />

    <!-- Distance measurement overlays (shown during drag) -->
    <svg v-if="dragDistanceGuides.length" class="absolute inset-0 pointer-events-none z-[9991]" style="overflow: visible">
      <g v-for="(m, idx) in dragDistanceGuides" :key="'dm-' + idx">
        <line :x1="m.x1" :y1="m.y1" :x2="m.x2" :y2="m.y2" stroke="#f43f5e" stroke-width="1" />
        <template v-if="m.axis === 'x'">
          <line :x1="m.x1" :y1="m.y1 - 4" :x2="m.x1" :y2="m.y1 + 4" stroke="#f43f5e" stroke-width="1" />
          <line :x1="m.x2" :y1="m.y2 - 4" :x2="m.x2" :y2="m.y2 + 4" stroke="#f43f5e" stroke-width="1" />
        </template>
        <template v-else>
          <line :x1="m.x1 - 4" :y1="m.y1" :x2="m.x1 + 4" :y2="m.y1" stroke="#f43f5e" stroke-width="1" />
          <line :x1="m.x2 - 4" :y1="m.y2" :x2="m.x2 + 4" :y2="m.y2" stroke="#f43f5e" stroke-width="1" />
        </template>
        <rect
          :x="(m.x1 + m.x2) / 2 - (String(m.label).length * 3.5 + 6)"
          :y="(m.y1 + m.y2) / 2 - 8"
          :width="String(m.label).length * 7 + 12" height="16" rx="4"
          fill="#f43f5e"
        />
        <text
          :x="(m.x1 + m.x2) / 2"
          :y="(m.y1 + m.y2) / 2 + 4"
          text-anchor="middle"
          fill="white" font-size="11" font-family="Inter, system-ui, sans-serif" font-weight="600"
        >{{ m.label }}</text>
      </g>
    </svg>

    <SelectionChrome
      :selected-bounds="selectedBounds"
      :per-item-bounds="perItemBounds"
      :editing-group-bounds="editingGroupBounds"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :multi-selected="store.selectedItemIds.size > 1"
      :item-locked="selectedItemLocked"
      :item-scale="singleSelectedItemScale"
      :item-rotation="selectedItemsRotation"
      :rubber-band="rubberBandRect"
      :readonly="readonly"
      :is-dragging="store.isDragging"
      :is-resizing="pixiIsResizing"
      @resize-start="onResizeStart"
      @rotation-start="onRotationStart"
      @action="onActionBarAction"
    />

    <EditOverlay
      v-if="!canvasAnimating"
      :editing-item="editingItem"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      @commit="onEditCommit"
      @cancel="onEditCancel"
    />

    <CollaboratorCursors
      v-if="!canvasAnimating"
      :collaborators="collaborators"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
    />

    <CommentPins
      v-if="showCommentPins && !canvasAnimating"
      :threads="commentThreads"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      @select-thread="$emit('select-comment-thread', $event)"
      @thread-context="(t, e) => $emit('delete-comment-thread', t)"
    />

    <!-- Pen tool overlay -->
    <MoodPenTool
      v-if="penMode && !props.readonly"
      :zoom="store.zoom"
      :panX="store.panX"
      :panY="store.panY"
      @create-shape="onPenShapeCreated"
      @cancel="penMode = false"
    />

    <!-- Freehand draw overlay -->
    <DrawOverlay
      v-if="drawMode && !props.readonly"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      :background-color="props.board?.background_color || '#f5f5f5'"
      @save-drawing="onDrawingSaved"
      @cancel="drawMode = false"
    />

    <MeasureOverlay
      :visible="!canvasAnimating && ((measure.visible.value && measure.measurements.value.length > 0) || (measureMode && measure.currentLine.value))"
      :measurements="measure.visible.value ? measure.measurements.value : []"
      :active-line="measureMode ? measure.currentLine.value : null"
      :measure-color="measure.lineColor.value"
      :measure-width="measure.lineWidth.value"
      :zoom="store.zoom"
      :pan-x="store.panX"
      :pan-y="store.panY"
      @remove="measure.removeMeasurement($event)"
    />

    <!-- Rulers & draggable guide lines -->
    <MoodRulers
      :container-width="containerWidth"
      :container-height="containerHeight"
      :top-offset="store.presentationMode ? 0 : 48"
    />

    <!-- Line tool preview -->
    <svg
      v-if="lineToolActive"
      class="absolute inset-0 z-10 pointer-events-none"
      :style="{ width: '100%', height: '100%' }"
    >
      <g :transform="`translate(${store.panX}, ${store.panY}) scale(${store.zoom})`">
        <line
          v-if="linePreview"
          :x1="linePreview.x1" :y1="linePreview.y1"
          :x2="linePreview.x2" :y2="linePreview.y2"
          stroke="#333" stroke-width="2" stroke-dasharray="6"
        />
      </g>
    </svg>

    <!-- Connection drag preview + target handles -->
    <svg
      v-if="connDragActive"
      class="absolute inset-0 z-10"
      :style="{ width: '100%', height: '100%', pointerEvents: 'none', overflow: 'visible' }"
    >
      <defs>
        <marker id="arrowhead-conn-preview" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto">
          <polygon points="0 0, 8 3, 0 6" :fill="accentColorHex" />
        </marker>
      </defs>
      <g :transform="`translate(${store.panX}, ${store.panY}) scale(${store.zoom})`">
        <!-- Curved dashed preview line from source edge to cursor -->
        <path
          v-if="connDragEndpoint && connPreviewPath"
          :d="connPreviewPath"
          :stroke="accentColorHex"
          :stroke-width="2 / store.zoom"
          fill="none"
          :stroke-dasharray="`${6 / store.zoom},${3 / store.zoom}`"
          stroke-linecap="round"
          marker-end="url(#arrowhead-conn-preview)"
        />
        <!-- Animated dot along the path -->
        <circle
          v-if="connDragEndpoint && connPreviewPath"
          :r="4 / store.zoom" :fill="accentColorHex" opacity="0.6"
        >
          <animateMotion dur="1s" repeatCount="indefinite" :path="connPreviewPath" />
        </circle>
        <!-- Target handles: pulsing circles on every item (except source) -->
        <template v-for="handle in connTargetHandles" :key="'ch-' + handle.id">
          <circle
            :cx="handle.cx" :cy="handle.cy" :r="10 / store.zoom"
            class="cursor-crosshair"
            :fill="accentColorHex + '33'"
            :stroke="accentColorHex"
            :stroke-width="2 / store.zoom"
            style="pointer-events: auto;"
            @pointerup.stop="completeConnectionTo(handle.id)"
          >
            <animate attributeName="r" :values="`${8/store.zoom};${12/store.zoom};${8/store.zoom}`" dur="1.5s" repeatCount="indefinite" />
          </circle>
        </template>
      </g>
    </svg>

    <!-- Connection wave + draw-on SVG overlay (suppressed during slide animation) -->
    <svg
      v-if="!canvasAnimating && (showWaveFilter || (store.motionEnabled && store.motionDrawOn))"
      class="absolute inset-0 pointer-events-none"
      style="z-index: 5; overflow: visible;"
    >
      <defs>
        <filter v-if="showWaveFilter" id="pixi-wave-filter" x="-50%" y="-50%" width="200%" height="200%">
          <feTurbulence type="fractalNoise"
            :baseFrequency="lineWaveDensity"
            numOctaves="2" seed="3" result="noise">
            <animate attributeName="baseFrequency"
              :dur="lineWaveAnimDur + 's'"
              :values="lineWaveDensityAnim"
              repeatCount="indefinite" calcMode="linear" />
          </feTurbulence>
          <feDisplacementMap in="SourceGraphic" in2="noise" :scale="lineWaveScale" xChannelSelector="R" yChannelSelector="G" />
        </filter>
      </defs>
      <g :transform="`translate(${store.panX}, ${store.panY}) scale(${store.zoom})`">
        <template v-for="conn in (store.currentBoard?.connections || [])" :key="'cwave-' + conn.id">
          <path
            v-if="showWaveFilter || isConnDrawOnActive(conn)"
            :d="getConnectionSvgPath(conn)"
            :stroke="conn.line_color || '#6366f1'"
            :stroke-width="conn.line_width || 2"
            fill="none"
            stroke-linecap="round"
            :stroke-dasharray="isConnDrawOnActive(conn) ? estimateSvgPathLength(getConnectionSvgPath(conn)) : 'none'"
            :stroke-dashoffset="isConnDrawOnActive(conn) ? estimateSvgPathLength(getConnectionSvgPath(conn)) : 0"
            :filter="showWaveFilter ? 'url(#pixi-wave-filter)' : ''"
            :class="isConnDrawOnActive(conn) ? 'conn-draw-on' : ''"
            :style="isConnDrawOnActive(conn) ? { '--conn-draw-len': estimateSvgPathLength(getConnectionSvgPath(conn)), '--conn-draw-dur': getConnDrawOnDuration(conn) + 's' } : {}"
            @animationend="onConnDrawOnEnd(conn)"
          />
        </template>
      </g>
    </svg>

    <svg
      v-if="selectedConnEndpoints && !connDragActive && !props.readonly"
      class="absolute inset-0 z-10"
      :style="{ width: '100%', height: '100%', pointerEvents: 'none', overflow: 'visible' }"
    >
      <g :transform="`translate(${store.panX}, ${store.panY}) scale(${store.zoom})`">
        <path
          v-if="selectedConnPath"
          :d="selectedConnPath"
          :stroke="connColorCss(selectedConnEndpoints.conn)"
          :stroke-width="(selectedConnEndpoints.conn.line_width || 2) + 6"
          fill="none"
          stroke-linecap="round"
          opacity="0.25"
        />
        <circle
          :cx="selectedConnEndpoints.from.x"
          :cy="selectedConnEndpoints.from.y"
          :r="anchorHandleRadius"
          :fill="connColorCss(selectedConnEndpoints.conn)"
          stroke="white"
          :stroke-width="anchorHandleStroke"
          class="cursor-grab"
          style="pointer-events: auto;"
          @pointerdown="onAnchorPointerDown($event, selectedConnEndpoints.conn, 'from')"
        />
        <circle
          :cx="selectedConnEndpoints.from.x"
          :cy="selectedConnEndpoints.from.y"
          :r="anchorHandleRadius * 0.4"
          fill="white"
        />
        <circle
          :cx="selectedConnEndpoints.to.x"
          :cy="selectedConnEndpoints.to.y"
          :r="anchorHandleRadius"
          :fill="connColorCss(selectedConnEndpoints.conn)"
          stroke="white"
          :stroke-width="anchorHandleStroke"
          class="cursor-grab"
          style="pointer-events: auto;"
          @pointerdown="onAnchorPointerDown($event, selectedConnEndpoints.conn, 'to')"
        />
        <circle
          :cx="selectedConnEndpoints.to.x"
          :cy="selectedConnEndpoints.to.y"
          :r="anchorHandleRadius * 0.4"
          fill="white"
        />
        <template v-if="selectedConnBendPoints">
          <line
            v-if="selectedConnBendPoints.cp1.isCustom || selectedConnBendPoints.cp2.isCustom"
            :x1="selectedConnEndpoints.from.x"
            :y1="selectedConnEndpoints.from.y"
            :x2="selectedConnBendPoints.cp1.x"
            :y2="selectedConnBendPoints.cp1.y"
            stroke="#f59e0b"
            :stroke-width="1 / (store.zoom || 1)"
            stroke-dasharray="4,4"
            opacity="0.4"
          />
          <rect
            :x="selectedConnBendPoints.cp1.x - anchorHandleRadius * 0.85"
            :y="selectedConnBendPoints.cp1.y - anchorHandleRadius * 0.85"
            :width="anchorHandleRadius * 1.7"
            :height="anchorHandleRadius * 1.7"
            :rx="2 / (store.zoom || 1)"
            :fill="selectedConnBendPoints.cp1.isCustom ? '#f59e0b' : connColorCss(selectedConnEndpoints.conn)"
            stroke="white"
            :stroke-width="anchorHandleStroke"
            class="cursor-grab"
            :transform="`rotate(45 ${selectedConnBendPoints.cp1.x} ${selectedConnBendPoints.cp1.y})`"
            style="pointer-events: auto;"
            @pointerdown="onBendPointerDown($event, selectedConnEndpoints.conn, 1)"
            @dblclick.stop.prevent="resetBendPoint(selectedConnEndpoints.conn, 1)"
          />
          <circle
            :cx="selectedConnBendPoints.cp1.x"
            :cy="selectedConnBendPoints.cp1.y"
            :r="anchorHandleRadius * 0.25"
            fill="white"
          />
          <line
            v-if="selectedConnBendPoints.cp1.isCustom || selectedConnBendPoints.cp2.isCustom"
            :x1="selectedConnBendPoints.cp2.x"
            :y1="selectedConnBendPoints.cp2.y"
            :x2="selectedConnEndpoints.to.x"
            :y2="selectedConnEndpoints.to.y"
            stroke="#f59e0b"
            :stroke-width="1 / (store.zoom || 1)"
            stroke-dasharray="4,4"
            opacity="0.4"
          />
          <rect
            :x="selectedConnBendPoints.cp2.x - anchorHandleRadius * 0.85"
            :y="selectedConnBendPoints.cp2.y - anchorHandleRadius * 0.85"
            :width="anchorHandleRadius * 1.7"
            :height="anchorHandleRadius * 1.7"
            :rx="2 / (store.zoom || 1)"
            :fill="selectedConnBendPoints.cp2.isCustom ? '#f59e0b' : connColorCss(selectedConnEndpoints.conn)"
            stroke="white"
            :stroke-width="anchorHandleStroke"
            class="cursor-grab"
            :transform="`rotate(45 ${selectedConnBendPoints.cp2.x} ${selectedConnBendPoints.cp2.y})`"
            style="pointer-events: auto;"
            @pointerdown="onBendPointerDown($event, selectedConnEndpoints.conn, 2)"
            @dblclick.stop.prevent="resetBendPoint(selectedConnEndpoints.conn, 2)"
          />
          <circle
            :cx="selectedConnBendPoints.cp2.x"
            :cy="selectedConnBendPoints.cp2.y"
            :r="anchorHandleRadius * 0.25"
            fill="white"
          />
        </template>
      </g>
    </svg>

    <!-- Sketch import progress -->
    <div
      v-if="sketchImporting"
      class="absolute inset-0 z-50 flex items-center justify-center bg-black/30"
    >
      <div class="bg-white dark:bg-surface-800 rounded-2xl px-8 py-5 shadow-xl text-center">
        <span class="material-symbols-rounded text-2xl text-primary-500 animate-spin">progress_activity</span>
        <p class="mt-2 text-sm text-surface-600 dark:text-surface-300">
          Importing Sketch file{{ sketchSaveProgress > 0 ? `... ${sketchSaveProgress}%` : '...' }}
        </p>
        <div v-if="sketchSaveProgress > 0" class="mt-2 w-48 bg-surface-200 dark:bg-surface-700 rounded-full h-1.5">
          <div class="bg-primary-500 h-1.5 rounded-full transition-all" :style="{ width: `${sketchSaveProgress}%` }" />
        </div>
      </div>
    </div>

    <!-- Hidden file input for .sketch import -->
    <input
      ref="sketchInput"
      type="file"
      accept=".sketch"
      class="hidden"
      @change="onSketchFileSelected"
    />
  </div>
</template>

<script setup>
import { ref, shallowRef, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Application } from 'pixi.js'
import { useMoodBoardsStore } from '../stores/moodBoards.js'

import SpatialIndex from './spatial/SpatialIndex.js'
import PixiTextureCache from './utils/pixiTextureCache.js'
import CursorManager from './utils/cursorManager.js'
import ItemRenderer from './renderer/ItemRenderer.js'
import { setDimensionFixCallback, resetImageRendererState } from './renderer/types/ImageRenderer.js'
import PanZoomManager from './interaction/PanZoomManager.js'
import SelectionManager from './interaction/SelectionManager.js'
import DragManager from './interaction/DragManager.js'
import ResizeManager from './interaction/ResizeManager.js'
import RotationManager from './interaction/RotationManager.js'
import ConnectionDragManager from './interaction/ConnectionDragManager.js'
import KeyboardManager from './interaction/KeyboardManager.js'
import LineToolManager from './tools/LineToolManager.js'
import CanvasDropHandler from './dnd/CanvasDropHandler.js'
import ClipboardHandler from './dnd/ClipboardHandler.js'
import { guardAction } from './interaction/ReadonlyGuard.js'
import { screenToCanvas } from './utils/coordTransform.js'
import { createItemData } from './utils/itemDefaults.js'
import { CONTAINER_TYPES } from './utils/containerTypes.js'
import { preloadBoardFonts } from '../utils/fontLoader.js'
import { useCanvasMeasure } from '../composables/useCanvasMeasure.js'
import { useConnectionEffects } from '../composables/useConnectionEffects.js'
import { useConnectionInteraction } from '../composables/useConnectionInteraction.js'
import { useCanvasUpload } from '../composables/useCanvasUpload.js'
import { useCanvasBackground } from '../composables/useCanvasBackground.js'
import { importSketchFile } from '../services/sketchImportService.js'
import { createPenShapeFromTool } from './tools/PenToolBridge.js'
import { getConnectionCurve } from './renderer/types/ConnectionRenderer.js'
import { usePixiSelectionState } from './composables/usePixiSelectionState.js'
import { usePixiConnectionUI } from './composables/usePixiConnectionUI.js'
import { usePixiPointerHandlers } from './composables/usePixiPointerHandlers.js'

import SelectionChrome from './overlay/SelectionChrome.vue'
import EditOverlay from './overlay/EditOverlay.vue'
import CollaboratorCursors from './overlay/CollaboratorCursors.vue'
import CommentPins from './overlay/CommentPins.vue'
import MeasureOverlay from './overlay/MeasureOverlay.vue'
import AlignGuides from './overlay/AlignGuides.vue'
import TextOverlay from './overlay/TextOverlay.vue'
import DrawingOverlay from './overlay/DrawingOverlay.vue'
import MediaOverlay from './overlay/MediaOverlay.vue'
import BackdropBlurOverlay from './overlay/BackdropBlurOverlay.vue'
import MoodPenTool from '../components/MoodPenTool.vue'
import DrawOverlay from './overlay/DrawOverlay.vue'
import CornerRadiusHandles from './overlay/CornerRadiusHandles.vue'
import BoxModelOverlay from './overlay/BoxModelOverlay.vue'
import ComponentBadge from './overlay/ComponentBadge.vue'
import MoodRulers from '../components/MoodRulers.vue'

const props = defineProps({
  board: Object,
  readonly: Boolean,
  beforeUpload: Function,
  commentCounts: Object,
  commentThreads: { type: Array, default: () => [] },
  activeCommentThreadId: [String, Number],
  showCommentPins: Boolean,
})

const drawMode = defineModel('drawMode', { type: Boolean, default: false })
const penMode = defineModel('penMode', { type: Boolean, default: false })
const lineMode = defineModel('lineMode', { type: Boolean, default: false })
const measureMode = defineModel('measureMode', { type: Boolean, default: false })
const measureColor = defineModel('measureColor', { type: String, default: '#FF5722' })
const measureWidth = defineModel('measureWidth', { type: Number, default: 2 })
const measureVisible = defineModel('measureVisible', { type: Boolean, default: true })
const measureCount = defineModel('measureCount', { type: Number, default: 0 })
const snapGrid = defineModel('snapGrid', { type: Boolean, default: false })
const snapCenter = defineModel('snapCenter', { type: Boolean, default: true })
const commentMode = defineModel('commentMode', { type: Boolean, default: false })

const emit = defineEmits([
  'item-context', 'select-connection', 'connection-context',
  'open-color-picker', 'pick-color', 'edit-drawing', 'preview-file',
  'edit-file-collab', 'browse-folder', 'comment-item', 'comment-canvas',
  'select-comment-thread', 'delete-comment-thread', 'renderer-fallback',
])

const store = useMoodBoardsStore()

const containerRef = ref(null)
const pixiMount = ref(null)
const sketchInput = ref(null)
const containerWidth = ref(0)
const containerHeight = ref(0)
const editingItem = ref(null)
const sketchImporting = ref(false)
const sketchSaveProgress = ref(0)
const measure = useCanvasMeasure()

const beforeUploadRef = computed(() => props.beforeUpload)
const upload = useCanvasUpload(store, {
  containerRef,
  screenToCanvasFn: screenToCanvas,
  beforeUploadHook: beforeUploadRef,
})

const zoomTier = computed(() => {
  if (store.presentationMode) return 'full'
  const z = store.zoom || 1
  if (z >= 0.4) return 'full'
  if (z >= 0.15) return 'medium'
  return 'low'
})

const connEffects = useConnectionEffects(store, getConnectionCurve, buildItemMap)
const {
  showWaveFilter, lineWaveScale, lineWaveDensity, lineWaveDensityAnim, lineWaveAnimDur,
  isConnDrawOnActive, getConnDrawOnDuration, onConnDrawOnEnd,
  getConnectionSvgPath, estimateSvgPathLength,
} = connEffects

const collaborators = ref([])
const canvasStrokes = ref([])
const pixiIsResizing = ref(false)
const canvasAnimating = ref(false)
// 'render' = syncBoard threw; 'context-lost' = WebGL context lost
const canvasError = ref(null)
let _animSettleTimer = null

function markCanvasAnimating() {
  canvasAnimating.value = true
  clearTimeout(_animSettleTimer)
  _animSettleTimer = setTimeout(() => { canvasAnimating.value = false }, 300)
  kickTicker()
}

// --- Idle ticker -------------------------------------------------------
// A static board doesn't need 60fps GPU re-renders. The ticker stops after
// TICKER_IDLE_MS without activity and is restarted (kicked) by anything
// that changes the scene: item/connection mutations, pan/zoom, pointer
// interaction, texture loads, motion settings.
const TICKER_IDLE_MS = 2000
let _lastTickerActivity = 0

function motionAnimationsActive() {
  return !!(store.motionEnabled && (store.motionLines || store.motionCards || store.motionElements))
}

function kickTicker() {
  _lastTickerActivity = performance.now()
  if (app?.ticker && !app.ticker.started) app.ticker.start()
}

function maybeIdleStopTicker() {
  if (!app?.ticker?.started) return
  if (motionAnimationsActive() || canvasAnimating.value) return
  if (store.isDragging || pixiIsResizing.value || pixiIsRotating.value) return
  if (performance.now() - _lastTickerActivity > TICKER_IDLE_MS) {
    app.ticker.stop()
  }
}

const overlaysActive = computed(() => !canvasAnimating.value && !store.presentationMode)

let app = null
let spatialIndex = null
let textureCache = null
let cursorMgr = null
let itemRenderer = null
let panZoom = null
let selectionMgr = null
let dragMgr = null
let resizeMgr = null
let rotationMgr = null
let connectionDrag = null
let keyboardMgr = null
let lineToolMgr = null
let dropHandler = null
let clipboardHandler = null
let pixiElapsedMs = 0
let resizeObserver = null

const connDragActive = ref(false)
const connDragEndpoint = ref(null)
const interacting = computed(() => store.isDragging || pixiIsResizing.value || rotationMgr?.isRotating)

const readonlyRef = computed(() => props.readonly)
const connInteraction = useConnectionInteraction(store, {
  toCanvasCoordsFn: (sx, sy) => {
    const rect = containerRef.value.getBoundingClientRect()
    return screenToCanvas(sx - rect.left, sy - rect.top, store.panX, store.panY, store.zoom)
  },
  getCanvasItemRectFn: (itemId) => {
    const item = store.itemMap.get(itemId)
    if (!item) return { x: 0, y: 0, w: 240, h: 120, cx: 120, cy: 60 }
    const sd = item.style_data || {}
    const scaleVal = (sd.item_scale != null && sd.item_scale !== 1) ? sd.item_scale : 1
    const rawW = item.width || 240
    const rawH = item.height || 120
    const w = rawW * scaleVal
    const h = rawH * scaleVal
    const x = (item.pos_x || 0) + rawW * (1 - scaleVal) / 2
    const y = (item.pos_y || 0) + rawH * (1 - scaleVal) / 2
    return { x, y, w, h, cx: x + w / 2, cy: y + h / 2 }
  },
  readonly: readonlyRef,
})
const {
  selectedConnectionId,
  onAnchorPointerDown, onBendPointerDown,
  resetBendPoint, resetConnectionAnchors, connColorCss,
} = connInteraction

const lineToolActive = computed(() => lineMode.value && lineToolMgr?.isDrawing)
const linePreview = computed(() => {
  if (!lineToolMgr?.isDrawing) return null
  const s = lineToolMgr.startPoint
  const e = lineToolMgr.endPoint
  if (!s || !e) return null
  return { x1: s.x, y1: s.y, x2: e.x, y2: e.y }
})

const {
  selectedBounds, perItemBounds, editingGroupBounds,
  singleSelectedItem, singleSelectedItemScale,
  selectedItemsRotation, selectedItemLocked,
} = usePixiSelectionState(store)

const rubberBandRect = shallowRef(null)
const dragGuides = ref([])
const dragDistanceGuides = ref([])
const pixiIsRotating = ref(false)

const {
  connDragFrom, accentColorHex, connPreviewPath, connTargetHandles,
  completeConnectionTo,
  anchorHandleRadius, anchorHandleStroke,
  selectedConnCurve, selectedConnEndpoints, selectedConnPath, selectedConnBendPoints,
} = usePixiConnectionUI(store, {
  getConnectionDrag: () => connectionDrag,
  connDragActive, connDragEndpoint, selectedConnectionId,
})

const boardPropsRef = computed(() => props.board)
const bg = useCanvasBackground(store, { boardProps: boardPropsRef, containerRef })
const {
  dotGridStyle, bgImageStyle, bgEffectStyles,
  grainEnabled, grainCanvasStyle, grainCanvas,
  renderGrainNoise, setupGrainResizeObserver,
} = bg

async function initPixi() {
  if (!pixiMount.value || !containerRef.value) return
  const localApp = new Application()
  app = localApp
  await localApp.init({
    backgroundAlpha: 0,
    antialias: true,
    resolution: Math.min(window.devicePixelRatio || 1, 2),
    autoDensity: true,
    powerPreference: 'high-performance',
    width: pixiMount.value.clientWidth || 800,
    height: pixiMount.value.clientHeight || 600,
  })
  if (app !== localApp) return
  if (!pixiMount.value) { app.destroy(true); app = null; return }
  pixiMount.value.appendChild(app.canvas)
  app.canvas.style.position = 'absolute'
  app.canvas.style.inset = '0'
  app.canvas.style.pointerEvents = 'none'
  app.stage.eventMode = 'none'

  // GPU resets (driver crash, tab backgrounding on low-VRAM devices) fire
  // webglcontextlost; preventDefault allows the browser to restore it.
  app.canvas.addEventListener('webglcontextlost', (e) => {
    e.preventDefault()
    canvasError.value = 'context-lost'
  })
  app.canvas.addEventListener('webglcontextrestored', () => {
    recoverCanvas()
  })

  spatialIndex = new SpatialIndex()
  textureCache = new PixiTextureCache(500)
  cursorMgr = new CursorManager(containerRef.value)
  itemRenderer = new ItemRenderer(app.stage, spatialIndex, textureCache, () => ({
    motionEnabled: store.motionEnabled,
    motionLines: store.motionLines,
    motionCards: store.motionCards,
    motionElements: store.motionElements,
    motionSpeed: store.motionSpeed,
    motionIntensity: store.motionIntensity,
    motionCardIntensity: store.motionCardIntensity,
    isDragging: store.isDragging,
    zoom: store.zoom,
  }))
  setDimensionFixCallback((itemId, w, h) => {
    store.updateItem(itemId, { width: w, height: h }, { skipUndo: true })
  })
  app.ticker.add((ticker) => {
    pixiElapsedMs += ticker.deltaMS
    if (!canvasAnimating.value) {
      itemRenderer?.tick(pixiElapsedMs)
    }
    maybeIdleStopTicker()
  })
  textureCache.onTextureLoaded = kickTicker
  kickTicker()

  panZoom = new PanZoomManager(store, app, containerRef.value, {
    onActivity: markCanvasAnimating,
    onTransform: kickTicker,
  })
  panZoom.attach()

  selectionMgr = new SelectionManager(store, spatialIndex, containerRef.value)
  dragMgr = new DragManager(store, containerRef.value)
  resizeMgr = new ResizeManager(store)
  rotationMgr = new RotationManager(store, containerRef.value)
  connectionDrag = new ConnectionDragManager(store, spatialIndex, containerRef.value)
  lineToolMgr = new LineToolManager(store, containerRef.value)

  keyboardMgr = new KeyboardManager({
    store, panZoom, selection: selectionMgr, drag: dragMgr, rotation: rotationMgr,
    emit, readonly: computed(() => props.readonly),
    drawMode, penMode, lineMode, measureMode,
    connectionDrag, connDragActive, connDragEndpoint,
  })
  keyboardMgr.attach()

  dropHandler = new CanvasDropHandler(store, containerRef.value, {
    onAddItem: (data) => addItemFromData(data),
    onSketchImport: (file) => importSketchFile(file),
    onSvgImport: (file, pos) => emit('edit-drawing', { file, pos }),
    onImageDrop: (file, pos) => handleImageUpload([file], pos),
    onImageSetDrop: (files, pos) => handleImageUpload(files, pos, true),
    onVideoDrop: (file, pos) => handleFileUpload(file, 'video', pos),
    onAudioDrop: (file, pos) => handleFileUpload(file, 'audio', pos),
    onFileDrop: (file, pos) => handleFileUpload(file, 'file', pos),
    onDriveFileDrop: (data, pos) => store.importDriveFile?.(data.file_id),
    onShapeMaskDrop: (file, targetItem) => applyMaskToShape(file, targetItem),
  }, spatialIndex)
  if (!props.readonly) dropHandler.attach()

  clipboardHandler = new ClipboardHandler(store, containerRef.value, {
    onImagePaste: (file, center) => handleImageUpload([file], center),
    onImageSetPaste: (files, center) => handleImageUpload(files, center, true),
    onInternalPaste: (center) => pasteAtCenter(center),
  })
  clipboardHandler.attach()

  updateContainerSize()
  syncBoard()
}

function buildChildrenMap(items) {
  const itemById = new Map(items.map(i => [i.id, i]))
  const map = new Map()
  for (const item of items) {
    if (item.parent_id == null) continue
    const parent = itemById.get(item.parent_id)
    if (!parent || !CONTAINER_TYPES.has(parent.type)) continue
    let bucket = map.get(item.parent_id)
    if (!bucket) { bucket = []; map.set(item.parent_id, bucket) }
    bucket.push(item)
  }
  for (const bucket of map.values()) {
    bucket.sort((a, b) => (a.z_index || 0) - (b.z_index || 0) || a.id - b.id)
  }
  return map
}

function buildItemMap(items) {
  const map = new Map()
  for (const item of items) map.set(item.id, item)
  return map
}

function filterVisibleItems(items) {
  return items.filter(i => {
    if (i.type === 'slide') {
      if (store.presentationMode) return false
      return store.slidesVisible
    }
    return true
  })
}

function syncBoard() {
  if (!itemRenderer || !store.currentBoard) return
  try {
    const allItems = store.currentBoard.items || []
    preloadBoardFonts(allItems)
    const items = filterVisibleItems(allItems)
    const connections = store.currentBoard.connections || []
    itemRenderer.setLOD(zoomTier.value)
    itemRenderer.syncItems(items, buildChildrenMap(items))
    itemRenderer.syncConnections(connections, buildItemMap(items))
    panZoom._updateStage()
  } catch (e) {
    console.error('[PixiCanvas] syncBoard failed:', e)
    canvasError.value = 'render'
  }
}

async function recoverCanvas() {
  canvasError.value = null
  destroyPixi()
  await nextTick()
  await initPixi()
}

watch(zoomTier, (tier) => {
  if (itemRenderer) itemRenderer.setLOD(tier)
  kickTicker()
})

// Restart the render loop when motion animations get switched on
watch(
  () => [store.motionEnabled, store.motionLines, store.motionCards, store.motionElements],
  () => kickTicker(),
)

function onItemsMutated() {
  if (!itemRenderer || canvasAnimating.value) return
  const allItems = store.currentBoard?.items || []
  const items = filterVisibleItems(allItems)
  const connections = store.currentBoard?.connections || []
  itemRenderer.syncItems(items, buildChildrenMap(items))
  itemRenderer.syncConnections(connections, buildItemMap(items))
  kickTicker()
}

// The deep items watcher is needed for out-of-band mutations (sidebar style
// edits, WS pushes, undo), but Vue's deep dependency traversal on every
// trigger is the single biggest drag-perf cost. So it is STOPPED during
// drag/resize/rotation and the pointermove handlers call onItemsMutated()
// directly for each frame instead.
let _itemsWatchStop = null
function startItemsWatcher() {
  if (_itemsWatchStop) return
  _itemsWatchStop = watch(() => store.currentBoard?.items, onItemsMutated, { deep: true })
}
function stopItemsWatcher() {
  if (_itemsWatchStop) {
    _itemsWatchStop()
    _itemsWatchStop = null
  }
}
startItemsWatcher()

watch(
  () => store.isDragging || pixiIsResizing.value || pixiIsRotating.value,
  (active) => {
    if (active) {
      stopItemsWatcher()
    } else {
      startItemsWatcher()
      onItemsMutated()
    }
  },
)

watch(() => store.currentBoard?.connections, () => {
  if (!itemRenderer || canvasAnimating.value) return
  const allItems = store.currentBoard?.items || []
  const items = filterVisibleItems(allItems)
  itemRenderer.syncConnections(store.currentBoard?.connections || [], buildItemMap(items))
  kickTicker()
}, { deep: true })

watch(() => store.focusedItemId, (newId) => {
  itemRenderer?.applyFocusDimming(newId)
})


watch(() => store.slidesVisible, () => { nextTick(syncBoard) })
watch(measureColor, (v) => { measure.lineColor.value = v })
watch(measureWidth, (v) => { measure.lineWidth.value = v })
watch(measureVisible, (v) => { measure.visible.value = v })
watch(() => measure.lineColor.value, (v) => { measureColor.value = v })
watch(() => measure.lineWidth.value, (v) => { measureWidth.value = v })
watch(() => measure.visible.value, (v) => { measureVisible.value = v })
watch(() => measure.measurements.value.length, (v) => { measureCount.value = v })

watch([() => store.zoom, () => store.panX, () => store.panY], () => {
  if (panZoom?.isAnimating) return
  panZoom?._updateStage()
})

watch(() => store.currentBoard?.id, (newId, oldId) => {
  if (oldId) measure.unloadBoard()
  nextTick(syncBoard)
  if (newId) {
    measure.loadBoard(newId, props.board)
    measureColor.value = measure.lineColor.value
    measureWidth.value = measure.lineWidth.value
    measureVisible.value = measure.visible.value
    measureCount.value = measure.measurements.value.length
  }
})

watch(() => props.board?.canvas_strokes, (raw) => {
  if (!raw) { canvasStrokes.value = []; return }
  try {
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw
    canvasStrokes.value = Array.isArray(parsed) ? parsed : []
  } catch { canvasStrokes.value = [] }
}, { immediate: true })

// Pointer/mouse interaction handlers (extracted to a composable; managers are
// created in initPixi() so they're exposed through lazy getters)
const _managerAccess = {
  get panZoom() { return panZoom },
  get selectionMgr() { return selectionMgr },
  get dragMgr() { return dragMgr },
  get resizeMgr() { return resizeMgr },
  get rotationMgr() { return rotationMgr },
  get connectionDrag() { return connectionDrag },
  get lineToolMgr() { return lineToolMgr },
  get cursorMgr() { return cursorMgr },
  get itemRenderer() { return itemRenderer },
}

const {
  onPointerDown, onPointerMove, onPointerUp,
  onDoubleClick, onContextMenu,
  onResizeStart, onRotationStart,
} = usePixiPointerHandlers({
  store, props, emit, measure,
  modes: { drawMode, penMode, lineMode, measureMode, commentMode, snapGrid, snapCenter },
  state: {
    connDragActive, connDragEndpoint, rubberBandRect,
    dragGuides, dragDistanceGuides, pixiIsResizing, pixiIsRotating,
  },
  mgr: _managerAccess,
  selectedConnectionId,
  fns: {
    toCanvasCoords: (x, y) => toCanvasCoords(x, y),
    completeConnectionTo,
    kickTicker: () => kickTicker(),
    onItemsMutated: () => onItemsMutated(),
    startEditing: (item) => startEditing(item),
    onReplaceImage: (item) => onReplaceImage(item),
    onAddImagesToSet: (item) => onAddImagesToSet(item),
  },
})

function onActionBarAction(actionId) {
  if (!guardAction(actionId, props.readonly)) return
  const id = [...store.selectedItemIds][0]
  switch (actionId) {
    case 'connect':
      if (connectionDrag?.isActive) {
        connectionDrag.cancel()
        connDragActive.value = false
        connDragEndpoint.value = null
      } else {
        connectionDrag.startConnection(id)
        connDragActive.value = true
      }
      break
    case 'lock': store.batchUpdateItems([{ id, locked: 1 }]); break
    case 'unlock': store.batchUpdateItems([{ id, locked: 0 }]); break
    case 'duplicate': store.duplicateSelectedItems(30, 30); break
    case 'delete': store.deleteSelectedItems?.(); break
  }
}

function startEditing(item) {
  editingItem.value = item
  itemRenderer?.hideItem(item.id)
}

function onEditCommit(data) {
  if (data?.id) {
    store.batchUpdateItems([data])
  }
  if (editingItem.value) itemRenderer?.showItem(editingItem.value.id)
  editingItem.value = null
}

function onEditCancel() {
  if (editingItem.value) itemRenderer?.showItem(editingItem.value.id)
  editingItem.value = null
}

function addItemFromData(data) {
  store.addItem?.(data)
}

async function applyMaskToShape(file, targetItem) {
  try {
    const uploaded = await store.uploadFiles([file])
    if (!uploaded?.[0]) return
    const existingSD = targetItem.style_data || {}
    if (targetItem.type === 'text') {
      store.updateItem(targetItem.id, {
        style_data: { ...existingSD, text_clip_image: uploaded[0].url, text_clip_image_size: 'cover' },
      })
    } else {
      store.updateItem(targetItem.id, {
        style_data: { ...existingSD, mask_image_url: uploaded[0].url, mask_image_fit: 'cover' },
      })
    }
  } catch (err) {
    console.error('Shape mask upload failed:', err)
  }
}

const { handleImageUpload, handleFileUpload, onReplaceImage, onAddImagesToSet } = upload

const doSketchImport = importSketchFile(store, { sketchImporting, sketchSaveProgress })

function onSketchFileSelected(e) {
  const file = e.target.files?.[0]
  if (file) doSketchImport(file)
  e.target.value = ''
}

function onPenShapeCreated(pathData) {
  const item = createPenShapeFromTool(pathData)
  store.addItem?.(item)
  penMode.value = false
}

function onDrawingSaved(drawingData) {
  if (drawingData?.items) {
    for (const item of drawingData.items) {
      store.addItem?.(item)
    }
  }
  drawMode.value = false
}

function pasteAtCenter(center) {
  if (!store.clipboard.length) return
  const bbox = computeClipboardBbox()
  const offsetX = center.x - (bbox.x + bbox.width / 2)
  const offsetY = center.y - (bbox.y + bbox.height / 2)
  store.pasteItems(offsetX, offsetY)
}

function computeClipboardBbox() {
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const item of store.clipboard) {
    const x = item.pos_x || 0
    const y = item.pos_y || 0
    if (x < minX) minX = x
    if (y < minY) minY = y
    if (x + (item.width || 0) > maxX) maxX = x + (item.width || 0)
    if (y + (item.height || 0) > maxY) maxY = y + (item.height || 0)
  }
  return { x: minX, y: minY, width: maxX - minX, height: maxY - minY }
}

function toCanvasCoords(screenX, screenY) {
  const rect = containerRef.value.getBoundingClientRect()
  return screenToCanvas(screenX - rect.left, screenY - rect.top, store.panX, store.panY, store.zoom)
}

function updateContainerSize() {
  if (!containerRef.value) return
  const rect = containerRef.value.getBoundingClientRect()
  containerWidth.value = rect.width
  containerHeight.value = rect.height
  if (app?.renderer) {
    app.renderer.resize(rect.width, rect.height)
  }
}

// Exposed API matching MoodCanvas.vue
function zoomIn() { panZoom?.zoomIn() }
function zoomOut() { panZoom?.zoomOut() }
function zoomReset() { panZoom?.zoomReset() }
function fitScreen() {
  const items = store.currentBoard?.items || []
  panZoom?.fitScreen(items)
}
async function animateToFrame(frame, duration, transition, padding, viewportOverride) {
  canvasAnimating.value = true
  try {
    await panZoom?.animateToFrame(frame, duration, transition, padding, viewportOverride)
  } finally {
    canvasAnimating.value = false
  }
}
function panToCanvasPoint(x, y, dur) { panZoom?.panToCanvasPoint(x, y, dur) }
function triggerSketchImport() { sketchInput.value?.click() }
function toggleDrawMode() { drawMode.value = !drawMode.value }
function togglePenMode() { penMode.value = !penMode.value }
function toggleLineMode() { lineMode.value = !lineMode.value }
function toggleMeasureMode() {
  measureMode.value = !measureMode.value
  if (measureMode.value) measure.visible.value = true
}
function clearMeasurements() { measure.clearAll() }

function addItemFromToolbar(type, extraStyleData) {
  const rect = containerRef.value?.getBoundingClientRect()
  if (!rect) return
  const center = screenToCanvas(rect.width / 2, rect.height / 2, store.panX, store.panY, store.zoom)
  const data = createItemData(type, center, store, extraStyleData)
  store.addItem?.(data)
}

const handleFileUploadFromToolbar = upload.handleFileUploadFromToolbar

function addDriveItem(file) { store.importDriveFile?.(file.id) }
function addDriveFolder(folder) { /* drive folder add */ }
function addCalendarEvent(event) {
  const rect = containerRef.value?.getBoundingClientRect()
  if (!rect) return
  const center = screenToCanvas(rect.width / 2, rect.height / 2, store.panX, store.panY, store.zoom)
  store.addItem?.({
    type: 'calendar_event',
    pos_x: center.x - 100,
    pos_y: center.y - 40,
    width: 200,
    height: 80,
    title: event.title || 'Event',
    style_data: { event_id: event.id },
  })
}

function addBoardItem(type, extra) {
  addItemFromToolbar(type, extra)
}


defineExpose({
  zoomIn, zoomOut, zoomReset, fitScreen,
  animateToFrame, panToCanvasPoint,
  triggerSketchImport,
  toggleDrawMode, togglePenMode, toggleLineMode, toggleMeasureMode,
  clearMeasurements,
  addItemFromToolbar, handleFileUpload: handleFileUploadFromToolbar,
  addDriveItem, addDriveFolder, addCalendarEvent, addBoardItem,
  resetConnectionAnchors,
  canvasContainer: containerRef,
  selectedConnectionId,
})

onMounted(async () => {
  await nextTick()
  await initPixi()
  if (!containerRef.value) return
  resizeObserver = new ResizeObserver(updateContainerSize)
  resizeObserver.observe(containerRef.value)
  if (grainEnabled.value) {
    nextTick(renderGrainNoise)
    nextTick(setupGrainResizeObserver)
  }
})

// Full Pixi teardown — used on unmount and when recovering from a canvas
// error (WebGL context loss / render failure).
function destroyPixi() {
  panZoom?.detach()
  keyboardMgr?.detach()
  dropHandler?.detach()
  clipboardHandler?.detach()
  dragMgr?.destroy()
  resizeMgr?.destroy()
  rotationMgr?.destroy()
  selectionMgr?.destroy()
  connectionDrag?.destroy?.()
  resetImageRendererState()
  // Teardown order matters: stop rendering first, then remove display
  // objects from the scene, and only THEN destroy the textures they were
  // referencing. Destroying textures while sprites still hold them caused
  // double-destroy errors / WebGL warnings on unmount.
  try { app?.ticker?.stop() } catch {}
  itemRenderer?.clear()
  if (app) {
    try { app.stage?.removeChildren() } catch {}
  }
  textureCache?.clear()
  if (app) {
    try {
      if (app.canvas?.parentElement) {
        app.canvas.parentElement.removeChild(app.canvas)
      }
    } catch {}
    try { app.destroy(true, { children: true }) } catch {}
  }
  app = null
  pixiElapsedMs = 0
  panZoom = null
  selectionMgr = null
  dragMgr = null
  resizeMgr = null
  rotationMgr = null
  connectionDrag = null
  keyboardMgr = null
  lineToolMgr = null
  cursorMgr = null
  itemRenderer = null
  spatialIndex = null
  textureCache = null
  dropHandler = null
  clipboardHandler = null
}

onBeforeUnmount(() => {
  clearTimeout(_animSettleTimer)
  resizeObserver?.disconnect()
  bg.cleanup()
  destroyPixi()
})
</script>

<style scoped>
.conn-draw-on {
  animation: connDrawOnReveal var(--conn-draw-dur, 1.5s) ease-out forwards;
}
@keyframes connDrawOnReveal {
  from { stroke-dashoffset: var(--conn-draw-len, 200); }
  to   { stroke-dashoffset: 0; }
}
</style>
