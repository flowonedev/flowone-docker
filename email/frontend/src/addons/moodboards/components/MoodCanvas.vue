<template>
  <div
    ref="canvasContainer"
    tabindex="-1"
    :class="['relative w-full h-full overflow-hidden select-none outline-none', store.isDragging && 'is-dragging']"
    :style="{ backgroundColor: board?.background_color || '#f5f5f5', cursor: cursorStyle }"
    @mousedown="onCanvasMouseDown"
    @mousemove="onCanvasMouseMove"
    @mouseup="onCanvasMouseUp"
    @wheel.prevent="onWheel"
    @touchstart.prevent="onCanvasTouchStart"
    @touchmove.prevent="onCanvasTouchMove"
    @touchend="onCanvasTouchEnd"
    @auxclick.prevent
    @dblclick="onDoubleClick"
    @contextmenu.prevent="onContextMenu"
    @dragover.prevent
    @drop.prevent="onDrop"
    style="touch-action: none;"
  >
    <!-- Board background image (below everything, covers the entire canvas viewport) -->
    <div
      v-if="board?.background_image"
      class="absolute inset-0 pointer-events-none"
      style="z-index: 0;"
      :style="bgImageStyle"
    />

    <!-- Dot grid — static viewport layer, stays behind everything, scales with zoom -->
    <div
      v-if="showDotGrid"
      class="absolute inset-0 pointer-events-none"
      style="z-index: 0;"
      :style="dotGridStyle"
    />

    <!-- Sketch import: blocking overlay (only during parse/render phase) -->
    <Transition name="fade">
      <div v-if="sketchImporting" class="absolute inset-0 z-[9999] flex items-center justify-center bg-black/30 pointer-events-auto">
        <div class="bg-white dark:bg-surface-800 rounded-xl shadow-2xl px-6 py-4 flex items-center gap-3">
          <span class="material-symbols-rounded text-primary-500 animate-spin text-[20px]">progress_activity</span>
          <span class="text-sm text-surface-700 dark:text-surface-200">{{ sketchProgress || 'Importing...' }}</span>
        </div>
      </div>
    </Transition>

    <!-- Sketch import: subtle background save indicator (non-blocking) -->
    <Transition name="fade">
      <div v-if="sketchSaveProgress" class="absolute bottom-4 left-1/2 -translate-x-1/2 z-[9998] pointer-events-none">
        <div class="bg-surface-800/80 backdrop-blur rounded-full px-4 py-1.5 flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-400 animate-spin text-[14px]">progress_activity</span>
          <span class="text-xs text-surface-300">{{ sketchSaveProgress }}</span>
        </div>
      </div>
    </Transition>

    <!-- Background effects overlay (grain, gradient, blur, vignette) — z-1 keeps them above bg image but below canvas items -->
    <div v-if="bgEffectStyles" class="absolute inset-0 pointer-events-none" style="z-index: 1;" :style="bgEffectStyles" />
    <canvas v-if="grainEnabled" ref="grainCanvas" class="absolute inset-0 pointer-events-none" style="z-index: 1;" :style="grainCanvasStyle" />

    <!-- Smart guide lines (shown during drag when snap-to-center is enabled) -->
    <svg v-if="smartGuides.length" class="absolute inset-0 pointer-events-none z-[9990]" style="overflow: visible">
      <line
        v-for="(g, idx) in smartGuides"
        :key="idx"
        :x1="g.x1" :y1="g.y1" :x2="g.x2" :y2="g.y2"
        :stroke="g.type === 'center' ? '#06b6d4' : g.type === 'third' ? '#8b5cf6' : '#f43f5e'"
        :stroke-width="g.type === 'center' ? 2 : 1"
        :stroke-dasharray="g.type === 'center' ? '8,4' : g.type === 'third' ? '3,5' : '4,3'"
        :opacity="g.type === 'center' ? 1 : 0.7"
      />
    </svg>

    <!-- Distance measurement overlays (shown during drag) -->
    <svg v-if="distanceMeasurements.length" class="absolute inset-0 pointer-events-none z-[9991]" style="overflow: visible">
      <g v-for="(m, idx) in distanceMeasurements" :key="'dm-' + idx">
        <line :x1="m.x1" :y1="m.y1" :x2="m.x2" :y2="m.y2" stroke="#f43f5e" stroke-width="1" />
        <!-- End caps -->
        <template v-if="m.axis === 'x'">
          <line :x1="m.x1" :y1="m.y1 - 4" :x2="m.x1" :y2="m.y1 + 4" stroke="#f43f5e" stroke-width="1" />
          <line :x1="m.x2" :y1="m.y2 - 4" :x2="m.x2" :y2="m.y2 + 4" stroke="#f43f5e" stroke-width="1" />
        </template>
        <template v-else>
          <line :x1="m.x1 - 4" :y1="m.y1" :x2="m.x1 + 4" :y2="m.y1" stroke="#f43f5e" stroke-width="1" />
          <line :x1="m.x2 - 4" :y1="m.y2" :x2="m.x2 + 4" :y2="m.y2" stroke="#f43f5e" stroke-width="1" />
        </template>
        <!-- Label background -->
        <rect
          :x="(m.x1 + m.x2) / 2 - (String(m.label).length * 3.5 + 6)"
          :y="(m.y1 + m.y2) / 2 - 8"
          :width="String(m.label).length * 7 + 12" height="16" rx="4"
          fill="#f43f5e"
        />
        <!-- Label text -->
        <text
          :x="(m.x1 + m.x2) / 2"
          :y="(m.y1 + m.y2) / 2 + 4"
          text-anchor="middle"
          fill="white"
          font-size="10"
          font-family="Inter, system-ui, sans-serif"
          font-weight="600"
        >{{ m.label }}</text>
      </g>
    </svg>

    <!-- Measure tool overlay (visible whenever measurements exist and visibility is on, OR actively drawing) -->
    <svg v-if="(measure.visible.value && measure.measurements.value.length) || (measureMode && measure.currentLine.value)" class="absolute inset-0 pointer-events-none z-[9992]" style="overflow: visible">
      <!-- Persisted measurements (shown when visibility is on) -->
      <g v-for="m in (measure.visible.value ? measure.measurements.value : [])" :key="'meas-' + m.id" class="measure-line-group pointer-events-auto">
        <line
          :x1="m.x1 * store.zoom + store.panX" :y1="m.y1 * store.zoom + store.panY"
          :x2="m.x2 * store.zoom + store.panX" :y2="m.y2 * store.zoom + store.panY"
          :stroke="measure.lineColor.value" :stroke-width="measure.lineWidth.value" stroke-dasharray="6,3"
        />
        <!-- Invisible fat hit area for hover -->
        <line
          :x1="m.x1 * store.zoom + store.panX" :y1="m.y1 * store.zoom + store.panY"
          :x2="m.x2 * store.zoom + store.panX" :y2="m.y2 * store.zoom + store.panY"
          stroke="transparent" stroke-width="16"
        />
        <!-- Start dot -->
        <circle :cx="m.x1 * store.zoom + store.panX" :cy="m.y1 * store.zoom + store.panY" :r="measure.lineWidth.value + 2" :fill="measure.lineColor.value" />
        <!-- End dot -->
        <circle :cx="m.x2 * store.zoom + store.panX" :cy="m.y2 * store.zoom + store.panY" :r="measure.lineWidth.value + 2" :fill="measure.lineColor.value" />
        <!-- Label pill -->
        <rect
          :x="(m.x1 + m.x2) / 2 * store.zoom + store.panX - (measureLabelWidth(m) / 2)"
          :y="(m.y1 + m.y2) / 2 * store.zoom + store.panY - 22"
          :width="measureLabelWidth(m)" height="20" rx="10"
          :fill="measure.lineColor.value"
        />
        <text
          :x="(m.x1 + m.x2) / 2 * store.zoom + store.panX"
          :y="(m.y1 + m.y2) / 2 * store.zoom + store.panY - 8"
          text-anchor="middle" fill="white" font-size="11" font-family="Inter, system-ui, sans-serif" font-weight="600"
        >{{ m.distance }}px</text>
        <!-- W x H secondary label (only when non-axis-aligned) -->
        <text
          v-if="m.width > 0 && m.height > 0"
          :x="(m.x1 + m.x2) / 2 * store.zoom + store.panX"
          :y="(m.y1 + m.y2) / 2 * store.zoom + store.panY + 14"
          text-anchor="middle" :fill="measure.lineColor.value" font-size="10" font-family="Inter, system-ui, sans-serif" font-weight="500"
        >{{ m.width }} x {{ m.height }}</text>
        <!-- Angle -->
        <text
          :x="(m.x1 + m.x2) / 2 * store.zoom + store.panX"
          :y="(m.y1 + m.y2) / 2 * store.zoom + store.panY + ((m.width > 0 && m.height > 0) ? 26 : 14)"
          text-anchor="middle" :fill="measure.lineColor.value" font-size="9" font-family="Inter, system-ui, sans-serif" font-weight="500" opacity="0.7"
        >{{ m.angle }}&#176;</text>
        <!-- Close button (hidden until hover on the group) -->
        <g class="measure-close-btn cursor-pointer" @click="measure.removeMeasurement(m.id)">
          <circle
            :cx="m.x2 * store.zoom + store.panX + 8" :cy="m.y2 * store.zoom + store.panY - 8"
            r="7" fill="#ef4444"
          />
          <text
            :x="m.x2 * store.zoom + store.panX + 8" :y="m.y2 * store.zoom + store.panY - 4.5"
            text-anchor="middle" fill="white" font-size="10" font-weight="700"
          >x</text>
        </g>
      </g>
      <!-- Active measurement being drawn -->
      <g v-if="measure.currentLine.value">
        <line
          :x1="measure.currentLine.value.x1 * store.zoom + store.panX"
          :y1="measure.currentLine.value.y1 * store.zoom + store.panY"
          :x2="measure.currentLine.value.x2 * store.zoom + store.panX"
          :y2="measure.currentLine.value.y2 * store.zoom + store.panY"
          :stroke="measure.lineColor.value" :stroke-width="measure.lineWidth.value" stroke-dasharray="6,3"
        />
        <circle :cx="measure.currentLine.value.x1 * store.zoom + store.panX" :cy="measure.currentLine.value.y1 * store.zoom + store.panY" :r="measure.lineWidth.value + 2" :fill="measure.lineColor.value" />
        <circle :cx="measure.currentLine.value.x2 * store.zoom + store.panX" :cy="measure.currentLine.value.y2 * store.zoom + store.panY" :r="measure.lineWidth.value + 2" :fill="measure.lineColor.value" />
        <!-- Distance pill -->
        <rect
          :x="(measure.currentLine.value.x1 + measure.currentLine.value.x2) / 2 * store.zoom + store.panX - (measureLabelWidth(measure.currentLine.value) / 2)"
          :y="(measure.currentLine.value.y1 + measure.currentLine.value.y2) / 2 * store.zoom + store.panY - 22"
          :width="measureLabelWidth(measure.currentLine.value)" height="20" rx="10"
          :fill="measure.lineColor.value"
        />
        <text
          :x="(measure.currentLine.value.x1 + measure.currentLine.value.x2) / 2 * store.zoom + store.panX"
          :y="(measure.currentLine.value.y1 + measure.currentLine.value.y2) / 2 * store.zoom + store.panY - 8"
          text-anchor="middle" fill="white" font-size="11" font-family="Inter, system-ui, sans-serif" font-weight="600"
        >{{ measure.currentLine.value.distance }}px</text>
        <!-- W x H -->
        <text
          v-if="measure.currentLine.value.width > 0 && measure.currentLine.value.height > 0"
          :x="(measure.currentLine.value.x1 + measure.currentLine.value.x2) / 2 * store.zoom + store.panX"
          :y="(measure.currentLine.value.y1 + measure.currentLine.value.y2) / 2 * store.zoom + store.panY + 14"
          text-anchor="middle" :fill="measure.lineColor.value" font-size="10" font-family="Inter, system-ui, sans-serif" font-weight="500"
        >{{ measure.currentLine.value.width }} x {{ measure.currentLine.value.height }}</text>
        <!-- Angle -->
        <text
          :x="(measure.currentLine.value.x1 + measure.currentLine.value.x2) / 2 * store.zoom + store.panX"
          :y="(measure.currentLine.value.y1 + measure.currentLine.value.y2) / 2 * store.zoom + store.panY + ((measure.currentLine.value.width > 0 && measure.currentLine.value.height > 0) ? 26 : 14)"
          text-anchor="middle" :fill="measure.lineColor.value" font-size="9" font-family="Inter, system-ui, sans-serif" font-weight="500" opacity="0.7"
        >{{ measure.currentLine.value.angle }}&#176;</text>
      </g>
    </svg>

    <!-- Canvas layer (transforms with pan/zoom) — z-2 keeps it above background effects at z-1 -->
    <div
      ref="canvasLayer"
      class="absolute top-0 left-0 origin-top-left z-[2]"
      :style="canvasTransform"
      :class="{ 'pointer-events-none': measureMode }"
    >
      
      <!-- SVG layer for connections rendered BELOW items (default).
           Defs for ALL connections live here (document-wide url() refs work cross-SVG).
           Uses a large viewport (10000x10000) to prevent browser compositor clipping. -->
      <svg
        v-if="showConnections && (belowConnections.length || aboveConnections.length || (store.connectingFrom && store.connectingFrom !== -1))"
        class="absolute pointer-events-none"
        style="overflow: visible; left: 0; top: 0; width: 10000px; height: 10000px;"
        :shape-rendering="store.zoom < 0.2 ? 'auto' : 'geometricPrecision'"
      >
        <defs>
          <!-- Arrowhead markers per connection color (gradient-aware) — ALL connections -->
          <marker
            v-for="conn in connections"
            :key="'marker-end-' + conn.id"
            :id="'arrowhead-' + conn.id"
            :markerWidth="10 + (conn.line_width || 2)" :markerHeight="6 + (conn.line_width || 2)"
            :refX="6 + (conn.line_width || 2) * 0.35" :refY="(6 + (conn.line_width || 2)) / 2"
            orient="auto"
            markerUnits="userSpaceOnUse"
          >
            <polygon :points="`0 0, ${10 + (conn.line_width || 2)} ${(6 + (conn.line_width || 2)) / 2}, 0 ${6 + (conn.line_width || 2)}`" :fill="conn.gradient_enabled ? (conn.gradient_color_end || '#8b5cf6') : connColor(conn)" />
          </marker>
          <marker
            v-for="conn in connections"
            :key="'marker-start-' + conn.id"
            :id="'arrowhead-start-' + conn.id"
            :markerWidth="10 + (conn.line_width || 2)" :markerHeight="6 + (conn.line_width || 2)"
            :refX="4 + (conn.line_width || 2) * 0.35" :refY="(6 + (conn.line_width || 2)) / 2"
            orient="auto-start-reverse"
            markerUnits="userSpaceOnUse"
          >
            <polygon :points="`${10 + (conn.line_width || 2)} 0, 0 ${(6 + (conn.line_width || 2)) / 2}, ${10 + (conn.line_width || 2)} ${6 + (conn.line_width || 2)}`" :fill="conn.gradient_enabled ? (conn.gradient_color_start || connColor(conn)) : connColor(conn)" />
          </marker>
          <!-- Temp line arrowhead -->
          <marker id="arrowhead-temp" markerWidth="10" markerHeight="6" refX="6.5" refY="3" orient="auto" markerUnits="strokeWidth">
            <polygon points="0 0, 10 3, 0 6" :fill="accentColor" />
          </marker>
          <!-- Wave distortion filter -->
          <filter v-if="showWaveFilter" id="line-wave-filter" x="-50%" y="-50%" width="200%" height="200%">
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
          <!-- SVG glow filters per connection — ALL connections -->
          <filter
            v-for="conn in glowConnections"
            :key="'glow-filter-' + conn.id"
            :id="'conn-glow-' + conn.id"
            x="-50%" y="-50%" width="200%" height="200%"
          >
            <feGaussianBlur in="SourceGraphic" :stdDeviation="conn.glow_blur || 6" />
          </filter>
          <!-- Gradient definitions per connection — ALL connections -->
          <linearGradient
            v-for="conn in gradientConnections"
            :key="'grad-' + conn.id"
            :id="'conn-gradient-' + conn.id"
            gradientUnits="userSpaceOnUse"
            :x1="connEndpointMap[conn.id]?.from?.x ?? 0"
            :y1="connEndpointMap[conn.id]?.from?.y ?? 0"
            :x2="connEndpointMap[conn.id]?.to?.x ?? 0"
            :y2="connEndpointMap[conn.id]?.to?.y ?? 0"
          >
            <stop offset="0%" :stop-color="conn.gradient_color_start || connColor(conn)" />
            <stop offset="100%" :stop-color="conn.gradient_color_end || '#8b5cf6'" />
          </linearGradient>
        </defs>
        
        <!-- Below-items connections -->
        <g
          v-for="conn in belowConnections"
          :key="'conn-' + conn.id"
          :opacity="store.focusedItemId && conn.from_item_id !== store.focusedItemId && conn.to_item_id !== store.focusedItemId ? 0.12 : 1"
          :style="store.focusedItemId ? { transition: 'opacity 0.3s ease' } : {}"
        >
          <path v-if="conn.glow_enabled && connectionPathMap[conn.id]" :d="connectionPathMap[conn.id]" :stroke="conn.gradient_enabled ? `url(#conn-gradient-${conn.id})` : (conn.glow_color || connColor(conn))" :stroke-width="(conn.line_width || 2) + (conn.glow_blur || 6) * 2" fill="none" :stroke-opacity="(conn.glow_opacity ?? 60) / 100" stroke-linecap="round" class="pointer-events-none" :filter="`url(#conn-glow-${conn.id})`" />
          <path :d="connectionPathMap[conn.id]" :stroke="connColor(conn)" :stroke-width="Math.max(8, (conn.line_width || 2) + 6)" stroke-opacity="0" fill="none" :class="props.readonly ? 'pointer-events-none' : 'pointer-events-auto cursor-pointer'" @click.stop="!props.readonly && (onSelectConnectionInternal(conn), $emit('select-connection', conn))" @contextmenu.prevent.stop="!props.readonly && $emit('connection-context', $event, conn)" />
          <path :d="connectionPathMap[conn.id]" :stroke="conn.gradient_enabled ? `url(#conn-gradient-${conn.id})` : connColor(conn)" :stroke-width="conn.line_width || 2" fill="none" :stroke-dasharray="isConnDrawOnActive(conn) ? estimateSvgPathLength(connectionPathMap[conn.id]) : (conn.line_style === 'dashed' ? `${(conn.line_width || 2) * 4},${(conn.line_width || 2) * 2}` : conn.line_style === 'dotted' ? `${conn.line_width || 2},${(conn.line_width || 2) * 2}` : 'none')" :stroke-dashoffset="isConnDrawOnActive(conn) ? estimateSvgPathLength(connectionPathMap[conn.id]) : 0" :marker-end="conn.arrow_end ? `url(#arrowhead-${conn.id})` : ''" :marker-start="conn.arrow_start ? `url(#arrowhead-start-${conn.id})` : ''" stroke-linecap="round" :filter="showWaveFilter ? 'url(#line-wave-filter)' : ''" :class="['pointer-events-none', store.isDragging ? '' : 'transition-[stroke,stroke-width] duration-150', isConnDrawOnActive(conn) ? 'conn-draw-on' : '']" :style="isConnDrawOnActive(conn) ? { '--conn-draw-len': estimateSvgPathLength(connectionPathMap[conn.id]), '--conn-draw-dur': getConnDrawOnDuration(conn) + 's' } : {}" @animationend="onConnDrawOnEnd(conn)" />
          <circle v-for="dotIdx in getConnDotCount(conn)" :key="'dot-' + conn.id + '-' + dotIdx" v-if="showConnectionAnimations && !isConnDrawOnActive(conn)" :r="(conn.line_width || 2) * 1.5" :fill="conn.gradient_enabled ? (conn.gradient_color_end || '#8b5cf6') : connColor(conn)" :opacity="0.7 + 0.15 * Math.sin(dotIdx * 1.8)"><animateMotion :dur="getAnimDuration(conn) + 's'" repeatCount="indefinite" :path="connectionPathMap[conn.id]" :begin="'-' + ((dotIdx - 1) / getConnDotCount(conn) * getAnimDuration(conn)).toFixed(2) + 's'" /></circle>
          <foreignObject v-if="conn.label && zoomTier !== 'low'" :x="getConnectionMidpoint(conn).x - 60" :y="getConnectionMidpoint(conn).y - 14" width="120" height="28" class="pointer-events-none overflow-visible"><div xmlns="http://www.w3.org/1999/xhtml" class="flex items-center justify-center w-full h-full"><span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-semibold whitespace-nowrap shadow-lg max-w-[116px] truncate text-center" :style="{ backgroundColor: connColor(conn), color: '#ffffff', border: '1.5px solid rgba(255,255,255,0.25)' }">{{ conn.label }}</span></div></foreignObject>
        </g>
        
        <!-- Active connection drawing line (dashed + animated) -->
        <g v-if="store.connectingFrom && store.connectingFrom !== -1 && tempConnectionEnd">
          <path
            :d="getTempConnectionPath()"
            :stroke="accentColor"
            stroke-width="2"
            fill="none"
            stroke-dasharray="6,3"
            stroke-linecap="round"
            marker-end="url(#arrowhead-temp)"
          />
          <circle r="4" :fill="accentColor" opacity="0.6">
            <animateMotion
              dur="1s"
              repeatCount="indefinite"
              :path="getTempConnectionPath()"
            />
          </circle>
        </g>
      </svg>
      
      <!-- Line tool drawing preview -->
      <svg
        v-if="lineMode && lineDrawStart && lineDrawEnd"
        class="absolute pointer-events-none"
        style="overflow: visible; left: 0; top: 0; width: 10000px; height: 10000px; z-index: 9999;"
      >
        <line
          :x1="lineDrawStart.x" :y1="lineDrawStart.y"
          :x2="lineDrawEnd.x" :y2="lineDrawEnd.y"
          :stroke="lineDrawColor"
          :stroke-width="Math.max(lineDrawWidth, 2) / store.zoom"
          stroke-linecap="round"
          opacity="0.85"
        />
        <!-- Start point indicator -->
        <circle :cx="lineDrawStart.x" :cy="lineDrawStart.y" :r="5 / store.zoom" :fill="lineDrawColor" stroke="white" :stroke-width="2 / store.zoom" />
        <!-- End point indicator -->
        <circle :cx="lineDrawEnd.x" :cy="lineDrawEnd.y" :r="5 / store.zoom" :fill="lineDrawColor" stroke="white" :stroke-width="2 / store.zoom" />
      </svg>

      <!-- Legacy canvas strokes SVG layer (old drawings, read-only) -->
      <svg
        v-if="canvasStrokes.length"
        class="absolute pointer-events-none"
        style="overflow: visible; left: 0; top: 0; width: 10000px; height: 10000px;"
      >
        <path
          v-for="stroke in canvasStrokes"
          :key="'stroke-' + stroke.id"
          :d="stroke.svgPath"
          :fill="stroke.eraser ? 'none' : (stroke.color || '#000000')"
          :stroke="stroke.eraser ? (board?.background_color || '#f5f5f5') : 'none'"
          :stroke-width="stroke.eraser ? stroke.width : 0"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
      
      <!-- In-progress drawing SVG (only while actively drawing a stroke) -->
      <svg
        v-if="activeStrokePath || drawSessionStrokes.length"
        class="absolute pointer-events-none"
        style="overflow: visible; left: 0; top: 0; width: 10000px; height: 10000px; z-index: 9999;"
      >
        <!-- Strokes drawn in this session (not yet saved as items) -->
        <path
          v-for="stroke in drawSessionStrokes"
          :key="'dsess-' + stroke.id"
          :d="stroke.svgPath"
          :fill="stroke.color || '#000000'"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
        <!-- In-progress stroke (while actively drawing) -->
        <path
          v-if="activeStrokePath"
          :d="activeStrokePath"
          :fill="drawEraser ? 'none' : drawColor"
          :stroke="drawEraser ? (board?.background_color || '#f5f5f5') : 'none'"
          :stroke-width="drawEraser ? drawStrokeWidth : 0"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
      
      <!-- Selection rectangle -->
      <div
        v-if="selectionRect"
        class="absolute border-2 border-primary-500/50 bg-primary-500/10 rounded pointer-events-none"
        :style="{
          left: selectionRect.x + 'px',
          top: selectionRect.y + 'px',
          width: selectionRect.w + 'px',
          height: selectionRect.h + 'px'
        }"
      />
      
      <!-- Group bounding box overlay (Figma-like) -->
      <div
        v-if="groupBBox"
        class="absolute pointer-events-none z-40"
        :style="{
          left: groupBBox.x + 'px',
          top: groupBBox.y + 'px',
          width: groupBBox.w + 'px',
          height: groupBBox.h + 'px',
          border: store.editingGroupId ? '1.5px dashed rgba(99, 102, 241, 0.4)' : '2px dashed rgba(99, 102, 241, 0.7)',
          borderRadius: '4px'
        }"
      />

      <!-- Items (viewport-culled + LOD) -->
      <MoodCanvasItem
        v-for="item in visibleItems"
        :key="'item-' + item.id"
        :item="item"
        :selected="!props.readonly && store.selectedItemIds.has(item.id)"
        :multi-selected="store.selectedItemIds.size > 1"
        :connecting="!!store.connectingFrom"
        :zoom-tier="zoomTier"
        :dimmed="!!store.focusedItemId && store.focusedItemId !== item.id"
        :readonly="props.readonly"
        @mousedown.stop="onItemMouseDown($event, item)"
        @connection-start="onConnectionStart(item)"
        @connection-end="onConnectionEnd(item)"
        @update="(data, opts) => store.updateItem(item.id, data, opts || {})"
        @delete="store.deleteItem(item.id)"
        @contextmenu.prevent.stop="$emit('item-context', $event, item)"
        @open-color-picker="(item) => $emit('open-color-picker', item)"
        @edit-drawing="(item) => $emit('edit-drawing', item)"
        @preview-file="(item) => $emit('preview-file', item)"
        @edit-file-collab="(item) => $emit('edit-file-collab', item)"
        @browse-folder="(item) => $emit('browse-folder', item)"
        @add-images-to-set="(item) => onAddImagesToSet(item)"
        @drop-files-to-set="({ item, files }) => onDropFilesToSet(item, files)"
        @remove-image-from-set="(imgId) => onRemoveImageFromSet(imgId)"
        @replace-image="(item) => onReplaceImage(item)"
      >
        <!-- Render children inside frames and groups -->
        <template v-if="item.type === 'frame' || item.type === 'group' || item.type === 'repeat_grid'" #frame-children>
          <MoodCanvasItem
            v-for="child in getFrameChildren(item.id)"
            :key="'frame-child-' + child.id"
            :item="child"
            :selected="!props.readonly && store.selectedItemIds.has(child.id)"
            :multi-selected="store.selectedItemIds.size > 1"
            :connecting="!!store.connectingFrom"
            :zoom-tier="zoomTier"
            :dimmed="!!store.focusedItemId && store.focusedItemId !== child.id"
            :readonly="props.readonly"
            :ancestor-scale="item.style_data?.item_scale || 1"
            :parent-pos="getChildParentPos(item, child)"
            :in-auto-layout="isChildInAutoLayout(item, child)"
            :style="getChildLayoutStyle(item, child)"
            @mousedown.stop="onItemMouseDown($event, child)"
            @connection-start="onConnectionStart(child)"
            @connection-end="onConnectionEnd(child)"
            @update="(data, opts) => store.updateItem(child.id, data, opts || {})"
            @delete="store.deleteItem(child.id)"
            @contextmenu.prevent.stop="$emit('item-context', $event, child)"
            @open-color-picker="(c) => $emit('open-color-picker', c)"
            @edit-drawing="(c) => $emit('edit-drawing', c)"
            @preview-file="(c) => $emit('preview-file', c)"
            @edit-file-collab="(c) => $emit('edit-file-collab', c)"
            @browse-folder="(c) => $emit('browse-folder', c)"
            @add-images-to-set="(c) => onAddImagesToSet(c)"
            @drop-files-to-set="({ item: c, files }) => onDropFilesToSet(c, files)"
            @remove-image-from-set="(imgId) => onRemoveImageFromSet(imgId)"
            @replace-image="(c) => onReplaceImage(c)"
          />
        </template>

        <!-- Render child items inside columns -->
        <template v-if="item.type === 'column'">
          <div
            v-for="child in getColumnChildren(item.id)"
            :key="'child-' + child.id"
            class="relative rounded-xl shadow-sm bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 overflow-hidden"
            :class="[
              store.selectedItemIds.has(child.id) ? 'ring-2 ring-primary-500' : '',
              child.locked ? 'opacity-90' : ''
            ]"
            @mousedown.stop="onColumnChildMouseDown($event, child, item)"
            @contextmenu.prevent.stop="$emit('item-context', $event, child)"
          >
            <!-- Mini card renderer for column children -->
            <div v-if="child.type === 'note'" class="p-3" :style="{ backgroundColor: child.color || '#fef3c7' }">
              <p v-if="child.title" class="text-xs font-semibold mb-0.5 truncate">{{ child.title }}</p>
              <p class="text-xs opacity-70 line-clamp-2">{{ child.content || '' }}</p>
            </div>
            <div v-else-if="child.type === 'image'" class="h-32">
              <img v-if="child.image_url" :src="child.image_url" class="w-full h-full object-contain" draggable="false" decoding="async" />
              <div v-else class="w-full h-full flex items-center justify-center text-surface-400"><span class="material-symbols-rounded">image</span></div>
              <div v-if="child.title" class="absolute bottom-0 inset-x-0 bg-black/50 text-white text-[10px] px-2 py-1 truncate opacity-0 group-hover:opacity-100 transition-opacity duration-200">{{ child.title }}</div>
            </div>
            <div v-else-if="child.type === 'color_swatch'" class="h-24" :style="{ backgroundColor: child.color || '#6366f1' }">
              <div class="flex items-end justify-center h-full pb-2">
                <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-black/20 text-white/90">{{ child.color || '#6366f1' }}</span>
              </div>
              <div v-if="child.title" class="absolute bottom-0 inset-x-0 bg-white/90 dark:bg-surface-800/90 text-[10px] px-2 py-1 text-primary-600 truncate">{{ child.title }}</div>
            </div>
            <div v-else-if="child.type === 'text'" class="p-3">
              <p class="text-xs text-surface-700 dark:text-surface-300 line-clamp-3">{{ child.content || 'Text' }}</p>
            </div>
            <div v-else-if="child.type === 'todo_list'" class="p-3">
              <p v-if="child.title" class="text-xs font-semibold mb-1 truncate">{{ child.title }}</p>
              <div class="space-y-1">
                <div v-for="todo in (child.todos || []).slice(0, 3)" :key="todo.id" class="flex items-center gap-1.5 text-[10px]">
                  <span class="material-symbols-rounded text-xs" :class="todo.completed ? 'text-primary-500' : 'text-surface-300'">{{ todo.completed ? 'check_box' : 'check_box_outline_blank' }}</span>
                  <span :class="todo.completed ? 'line-through text-surface-400' : 'text-surface-700 dark:text-surface-300'">{{ todo.text }}</span>
                </div>
                <p v-if="(child.todos || []).length > 3" class="text-[10px] text-surface-400">+{{ child.todos.length - 3 }} more</p>
              </div>
            </div>
            <div v-else-if="child.type === 'link'" class="p-3">
              <div class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-sm text-primary-500">link</span>
                <p class="text-xs font-medium truncate">{{ child.title || child.url || 'Link' }}</p>
              </div>
            </div>
            <div v-else class="p-3">
              <div class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-sm text-surface-400">{{ child.type === 'file' ? 'insert_drive_file' : child.type === 'drawing' ? 'draw' : child.type === 'table' ? 'table_chart' : 'article' }}</span>
                <p class="text-xs font-medium truncate">{{ child.title || child.type }}</p>
              </div>
            </div>
            <!-- Remove from column button -->
            <button
              @click.stop="removeFromColumn(child)"
              class="absolute top-1 right-1 p-0.5 rounded bg-white/80 dark:bg-surface-800/80 opacity-0 group-hover:opacity-100 hover:!opacity-100 text-surface-400 hover:text-red-500 transition-opacity"
              title="Remove from column"
            >
              <span class="material-symbols-rounded text-xs">close</span>
            </button>
          </div>
        </template>
      </MoodCanvasItem>

      <!-- Comment indicators on items -->
      <div
        v-if="props.showCommentPins"
        v-for="item in itemsWithComments"
        :key="'comment-badge-' + item.id"
        class="absolute pointer-events-auto"
        :style="{
          left: (item.pos_x + (item.width || 240) - 12) + 'px',
          top: (item.pos_y - 4) + 'px',
          zIndex: 9980,
        }"
      >
        <MoodCommentBubble
          :item-id="item.id"
          :count="props.commentCounts[item.id]?.comments || 0"
          :has-unresolved="true"
          @click="onCommentBubbleClick(item)"
        />
      </div>

      <!-- Canvas-level comment pins (Figma-style) -->
      <div
        v-if="props.showCommentPins"
        v-for="thread in canvasCommentThreads"
        :key="'cpin-' + thread.thread_id"
        class="absolute pointer-events-auto"
        :style="{
          left: thread.pin_x + 'px',
          top: thread.pin_y + 'px',
          zIndex: 9985,
        }"
        @click.stop="emit('select-comment-thread', thread)"
        @contextmenu.prevent.stop="onCommentPinContext($event, thread)"
      >
        <div
          class="flex items-center gap-0.5 rounded-full shadow-lg cursor-pointer transition-all duration-150 hover:scale-110 select-none"
          :style="{ transform: `scale(${1 / store.zoom})`, transformOrigin: '0 100%' }"
          :class="thread.resolved
            ? 'bg-surface-400/70 text-white ring-1 ring-surface-300/50'
            : props.activeCommentThreadId === thread.thread_id
              ? 'bg-primary-600 text-white ring-2 ring-primary-300'
              : 'bg-primary-500 text-white ring-1 ring-primary-300/50 hover:bg-primary-600'"
        >
          <div class="w-7 h-7 flex items-center justify-center">
            <span class="material-symbols-rounded" style="font-size: 16px;">
              {{ thread.resolved ? 'check_circle' : 'chat_bubble' }}
            </span>
          </div>
          <span
            v-if="thread.comments.length > 1"
            class="pr-2 text-[10px] font-bold"
          >{{ thread.comments.length }}</span>
        </div>
      </div>

      <!-- SVG layer for connections rendered ABOVE items (per-connection toggle).
           Has its own defs since cross-SVG url() refs are unreliable in browsers.
           z-index: 9990 places these above items but below anchor handles (99998). -->
      <svg
        v-if="showConnections && aboveConnections.length"
        class="absolute pointer-events-none"
        style="overflow: visible; left: 0; top: 0; width: 10000px; height: 10000px; z-index: 9990;"
        :shape-rendering="store.zoom < 0.2 ? 'auto' : 'geometricPrecision'"
      >
        <defs>
          <marker v-for="conn in aboveConnections" :key="'above-marker-end-' + conn.id" :id="'above-arrowhead-' + conn.id" :markerWidth="10 + (conn.line_width || 2)" :markerHeight="6 + (conn.line_width || 2)" :refX="6 + (conn.line_width || 2) * 0.35" :refY="(6 + (conn.line_width || 2)) / 2" orient="auto" markerUnits="userSpaceOnUse"><polygon :points="`0 0, ${10 + (conn.line_width || 2)} ${(6 + (conn.line_width || 2)) / 2}, 0 ${6 + (conn.line_width || 2)}`" :fill="conn.gradient_enabled ? (conn.gradient_color_end || '#8b5cf6') : connColor(conn)" /></marker>
          <marker v-for="conn in aboveConnections" :key="'above-marker-start-' + conn.id" :id="'above-arrowhead-start-' + conn.id" :markerWidth="10 + (conn.line_width || 2)" :markerHeight="6 + (conn.line_width || 2)" :refX="4 + (conn.line_width || 2) * 0.35" :refY="(6 + (conn.line_width || 2)) / 2" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><polygon :points="`${10 + (conn.line_width || 2)} 0, 0 ${(6 + (conn.line_width || 2)) / 2}, ${10 + (conn.line_width || 2)} ${6 + (conn.line_width || 2)}`" :fill="conn.gradient_enabled ? (conn.gradient_color_start || connColor(conn)) : connColor(conn)" /></marker>
          <filter v-if="showWaveFilter" id="above-line-wave-filter" x="-50%" y="-50%" width="200%" height="200%"><feTurbulence type="fractalNoise" :baseFrequency="lineWaveDensity" numOctaves="2" seed="3" result="noise"><animate attributeName="baseFrequency" :dur="lineWaveAnimDur + 's'" :values="lineWaveDensityAnim" repeatCount="indefinite" calcMode="linear" /></feTurbulence><feDisplacementMap in="SourceGraphic" in2="noise" :scale="lineWaveScale" xChannelSelector="R" yChannelSelector="G" /></filter>
          <filter v-for="conn in aboveConnections.filter(c => c.glow_enabled)" :key="'above-glow-' + conn.id" :id="'above-conn-glow-' + conn.id" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur in="SourceGraphic" :stdDeviation="conn.glow_blur || 6" /></filter>
          <linearGradient v-for="conn in aboveConnections.filter(c => c.gradient_enabled)" :key="'above-grad-' + conn.id" :id="'above-conn-gradient-' + conn.id" gradientUnits="userSpaceOnUse" :x1="connEndpointMap[conn.id]?.from?.x ?? 0" :y1="connEndpointMap[conn.id]?.from?.y ?? 0" :x2="connEndpointMap[conn.id]?.to?.x ?? 0" :y2="connEndpointMap[conn.id]?.to?.y ?? 0"><stop offset="0%" :stop-color="conn.gradient_color_start || connColor(conn)" /><stop offset="100%" :stop-color="conn.gradient_color_end || '#8b5cf6'" /></linearGradient>
        </defs>
        <g
          v-for="conn in aboveConnections"
          :key="'conn-above-' + conn.id"
          :opacity="store.focusedItemId && conn.from_item_id !== store.focusedItemId && conn.to_item_id !== store.focusedItemId ? 0.12 : 1"
          :style="store.focusedItemId ? { transition: 'opacity 0.3s ease' } : {}"
        >
          <path v-if="conn.glow_enabled && connectionPathMap[conn.id]" :d="connectionPathMap[conn.id]" :stroke="conn.gradient_enabled ? `url(#above-conn-gradient-${conn.id})` : (conn.glow_color || connColor(conn))" :stroke-width="(conn.line_width || 2) + (conn.glow_blur || 6) * 2" fill="none" :stroke-opacity="(conn.glow_opacity ?? 60) / 100" stroke-linecap="round" class="pointer-events-none" :filter="`url(#above-conn-glow-${conn.id})`" />
          <path :d="connectionPathMap[conn.id]" :stroke="connColor(conn)" :stroke-width="Math.max(8, (conn.line_width || 2) + 6)" stroke-opacity="0" fill="none" :class="props.readonly ? 'pointer-events-none' : 'pointer-events-auto cursor-pointer'" @click.stop="!props.readonly && (onSelectConnectionInternal(conn), $emit('select-connection', conn))" @contextmenu.prevent.stop="!props.readonly && $emit('connection-context', $event, conn)" />
          <path :d="connectionPathMap[conn.id]" :stroke="conn.gradient_enabled ? `url(#above-conn-gradient-${conn.id})` : connColor(conn)" :stroke-width="conn.line_width || 2" fill="none" :stroke-dasharray="isConnDrawOnActive(conn) ? estimateSvgPathLength(connectionPathMap[conn.id]) : (conn.line_style === 'dashed' ? `${(conn.line_width || 2) * 4},${(conn.line_width || 2) * 2}` : conn.line_style === 'dotted' ? `${conn.line_width || 2},${(conn.line_width || 2) * 2}` : 'none')" :stroke-dashoffset="isConnDrawOnActive(conn) ? estimateSvgPathLength(connectionPathMap[conn.id]) : 0" :marker-end="conn.arrow_end ? `url(#above-arrowhead-${conn.id})` : ''" :marker-start="conn.arrow_start ? `url(#above-arrowhead-start-${conn.id})` : ''" stroke-linecap="round" :filter="showWaveFilter ? 'url(#above-line-wave-filter)' : ''" :class="['pointer-events-none', store.isDragging ? '' : 'transition-[stroke,stroke-width] duration-150', isConnDrawOnActive(conn) ? 'conn-draw-on' : '']" :style="isConnDrawOnActive(conn) ? { '--conn-draw-len': estimateSvgPathLength(connectionPathMap[conn.id]), '--conn-draw-dur': getConnDrawOnDuration(conn) + 's' } : {}" @animationend="onConnDrawOnEnd(conn)" />
          <circle v-for="dotIdx in getConnDotCount(conn)" :key="'dot-above-' + conn.id + '-' + dotIdx" v-if="showConnectionAnimations && !isConnDrawOnActive(conn)" :r="(conn.line_width || 2) * 1.5" :fill="conn.gradient_enabled ? (conn.gradient_color_end || '#8b5cf6') : connColor(conn)" :opacity="0.7 + 0.15 * Math.sin(dotIdx * 1.8)"><animateMotion :dur="getAnimDuration(conn) + 's'" repeatCount="indefinite" :path="connectionPathMap[conn.id]" :begin="'-' + ((dotIdx - 1) / getConnDotCount(conn) * getAnimDuration(conn)).toFixed(2) + 's'" /></circle>
          <foreignObject v-if="conn.label && zoomTier !== 'low'" :x="getConnectionMidpoint(conn).x - 60" :y="getConnectionMidpoint(conn).y - 14" width="120" height="28" class="pointer-events-none overflow-visible"><div xmlns="http://www.w3.org/1999/xhtml" class="flex items-center justify-center w-full h-full"><span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-semibold whitespace-nowrap shadow-lg max-w-[116px] truncate text-center" :style="{ backgroundColor: connColor(conn), color: '#ffffff', border: '1.5px solid rgba(255,255,255,0.25)' }">{{ conn.label }}</span></div></foreignObject>
        </g>
      </svg>
      
      <!-- Connection anchor handles — rendered AFTER items so they're always on top (hidden in readonly) -->
      <svg
        v-if="selectedConnEndpoints && !store.connectingFrom && !props.readonly"
        class="absolute pointer-events-none"
        style="overflow: visible; left: 0; top: 0; width: 10000px; height: 10000px; z-index: 99998;"
      >
        <!-- Selected connection highlight (thicker glow behind the line) -->
        <path
          v-if="connectionPathMap[selectedConnEndpoints.conn.id]"
          :d="connectionPathMap[selectedConnEndpoints.conn.id]"
          :stroke="connColor(selectedConnEndpoints.conn)"
          :stroke-width="(selectedConnEndpoints.conn.line_width || 2) + 6"
          fill="none"
          stroke-linecap="round"
          opacity="0.25"
          class="pointer-events-none"
        />
        <!-- From anchor -->
        <circle
          :cx="selectedConnEndpoints.from.x"
          :cy="selectedConnEndpoints.from.y"
          :r="anchorHandleRadius"
          :fill="connColor(selectedConnEndpoints.conn)"
          stroke="white"
          :stroke-width="anchorHandleStroke"
          class="pointer-events-auto cursor-grab"
          style="filter: drop-shadow(0 1px 3px rgba(0,0,0,0.3))"
          @pointerdown="onAnchorPointerDown($event, selectedConnEndpoints.conn, 'from')"
        />
        <circle
          :cx="selectedConnEndpoints.from.x"
          :cy="selectedConnEndpoints.from.y"
          :r="anchorHandleRadius * 0.4"
          fill="white"
          class="pointer-events-none"
        />
        <!-- To anchor -->
        <circle
          :cx="selectedConnEndpoints.to.x"
          :cy="selectedConnEndpoints.to.y"
          :r="anchorHandleRadius"
          :fill="connColor(selectedConnEndpoints.conn)"
          stroke="white"
          :stroke-width="anchorHandleStroke"
          class="pointer-events-auto cursor-grab"
          style="filter: drop-shadow(0 1px 3px rgba(0,0,0,0.3))"
          @pointerdown="onAnchorPointerDown($event, selectedConnEndpoints.conn, 'to')"
        />
        <circle
          :cx="selectedConnEndpoints.to.x"
          :cy="selectedConnEndpoints.to.y"
          :r="anchorHandleRadius * 0.4"
          fill="white"
          class="pointer-events-none"
        />
        <!-- Bend control point handles (2 diamond shapes for cubic bezier) -->
        <template v-if="selectedConnBendPoints">
          <!-- Control point 1 (near start) -->
          <g>
            <!-- Guide line from start to CP1 -->
            <line
              v-if="selectedConnBendPoints.cp1.isCustom || selectedConnBendPoints.cp2.isCustom"
              :x1="selectedConnEndpoints.from.x" :y1="selectedConnEndpoints.from.y"
              :x2="selectedConnBendPoints.cp1.x" :y2="selectedConnBendPoints.cp1.y"
              stroke="#f59e0b" :stroke-width="1 / store.zoom" stroke-dasharray="4,4" opacity="0.4" class="pointer-events-none"
            />
            <rect
              :x="selectedConnBendPoints.cp1.x - anchorHandleRadius * 0.85"
              :y="selectedConnBendPoints.cp1.y - anchorHandleRadius * 0.85"
              :width="anchorHandleRadius * 1.7"
              :height="anchorHandleRadius * 1.7"
              :rx="2 / store.zoom"
              :fill="selectedConnBendPoints.cp1.isCustom ? '#f59e0b' : connColor(selectedConnEndpoints.conn)"
              stroke="white"
              :stroke-width="anchorHandleStroke"
              class="pointer-events-auto cursor-grab"
              :transform="`rotate(45 ${selectedConnBendPoints.cp1.x} ${selectedConnBendPoints.cp1.y})`"
              style="filter: drop-shadow(0 1px 3px rgba(0,0,0,0.3))"
              @pointerdown="onBendPointerDown($event, selectedConnEndpoints.conn, 1)"
              @dblclick.stop.prevent="resetBendPoint(selectedConnEndpoints.conn, 1)"
            />
            <circle
              :cx="selectedConnBendPoints.cp1.x"
              :cy="selectedConnBendPoints.cp1.y"
              :r="anchorHandleRadius * 0.25"
              fill="white"
              class="pointer-events-none"
            />
          </g>
          <!-- Control point 2 (near end) -->
          <g>
            <!-- Guide line from CP2 to end -->
            <line
              v-if="selectedConnBendPoints.cp1.isCustom || selectedConnBendPoints.cp2.isCustom"
              :x1="selectedConnBendPoints.cp2.x" :y1="selectedConnBendPoints.cp2.y"
              :x2="selectedConnEndpoints.to.x" :y2="selectedConnEndpoints.to.y"
              stroke="#f59e0b" :stroke-width="1 / store.zoom" stroke-dasharray="4,4" opacity="0.4" class="pointer-events-none"
            />
            <rect
              :x="selectedConnBendPoints.cp2.x - anchorHandleRadius * 0.85"
              :y="selectedConnBendPoints.cp2.y - anchorHandleRadius * 0.85"
              :width="anchorHandleRadius * 1.7"
              :height="anchorHandleRadius * 1.7"
              :rx="2 / store.zoom"
              :fill="selectedConnBendPoints.cp2.isCustom ? '#f59e0b' : connColor(selectedConnEndpoints.conn)"
              stroke="white"
              :stroke-width="anchorHandleStroke"
              class="pointer-events-auto cursor-grab"
              :transform="`rotate(45 ${selectedConnBendPoints.cp2.x} ${selectedConnBendPoints.cp2.y})`"
              style="filter: drop-shadow(0 1px 3px rgba(0,0,0,0.3))"
              @pointerdown="onBendPointerDown($event, selectedConnEndpoints.conn, 2)"
              @dblclick.stop.prevent="resetBendPoint(selectedConnEndpoints.conn, 2)"
            />
            <circle
              :cx="selectedConnBendPoints.cp2.x"
              :cy="selectedConnBendPoints.cp2.y"
              :r="anchorHandleRadius * 0.25"
              fill="white"
              class="pointer-events-none"
            />
          </g>
        </template>
      </svg>

      <!-- Collaborator cursors (counter-scaled so they don't grow/shrink with zoom) -->
      <div
        v-for="collab in store.collaborators"
        :key="'cursor-' + collab.email"
        class="absolute pointer-events-none"
        :style="{
          transform: `translate(${collab.cursor_x || 0}px, ${collab.cursor_y || 0}px) scale(${1 / store.zoom})`,
          transformOrigin: '0 0',
          transition: 'transform 80ms ease-out, opacity 200ms ease',
          zIndex: 99999,
          opacity: collab.cursor_x != null ? 1 : 0
        }"
      >
        <!-- Cursor SVG -->
        <svg width="24" height="24" viewBox="0 0 24 24" class="drop-shadow-lg" style="margin-left: -2px; margin-top: -2px;">
          <path
            d="M5.65 3.15L17.47 12.5H10.85L7.1 20.2L5.65 3.15Z"
            :fill="collab.color || '#6366f1'"
            stroke="white"
            stroke-width="1.5"
            stroke-linejoin="round"
          />
        </svg>
        <!-- User name tag -->
        <div
          class="absolute left-5 top-5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold shadow-lg"
          :style="{
            backgroundColor: collab.color || '#6366f1',
            color: 'white',
            border: '1.5px solid rgba(255,255,255,0.3)'
          }"
        >
          {{ collab.name || collab.email }}
        </div>
      </div>
    </div>
    
    <!-- Draw mode overlay — captures pointer events when drawing -->
    <div
      v-if="drawMode"
      class="absolute inset-0 z-20"
      :style="{ cursor: drawEraser ? 'none' : 'crosshair' }"
      @pointerdown="onDrawPointerDown"
      @pointermove="onDrawPointerMove"
      @pointerup="onDrawPointerUp"
      @pointerleave="onDrawPointerUp"
      @wheel.prevent="onWheel"
      @contextmenu.prevent
      style="touch-action: none;"
    >
      <!-- Eraser cursor preview -->
      <div
        v-if="drawEraser && drawCursorPos"
        class="absolute pointer-events-none rounded-full border-2 border-red-400/60"
        :style="{
          left: (drawCursorPos.x - drawStrokeWidth / 2) + 'px',
          top: (drawCursorPos.y - drawStrokeWidth / 2) + 'px',
          width: drawStrokeWidth + 'px',
          height: drawStrokeWidth + 'px'
        }"
      />
      <!-- Pen cursor preview -->
      <div
        v-if="!drawEraser && drawCursorPos"
        class="absolute pointer-events-none rounded-full"
        :style="{
          left: (drawCursorPos.x - Math.max(4, drawStrokeWidth * store.zoom) / 2) + 'px',
          top: (drawCursorPos.y - Math.max(4, drawStrokeWidth * store.zoom) / 2) + 'px',
          width: Math.max(4, drawStrokeWidth * store.zoom) + 'px',
          height: Math.max(4, drawStrokeWidth * store.zoom) + 'px',
          backgroundColor: drawColor + '50',
          border: '1px solid ' + drawColor
        }"
      />
    </div>
    
    <!-- Floating draw toolbar (visible when drawMode is on) — positioned above bottom toolbar -->
    <transition name="draw-bar">
      <div
        v-if="drawMode"
        class="absolute bottom-20 left-1/2 -translate-x-1/2 z-[10002] flex items-center gap-2 px-3 py-2 bg-white dark:bg-surface-800 rounded-2xl shadow-lg border border-primary-200 dark:border-primary-700/50"
      >
        <!-- Pen / Eraser toggle -->
        <div class="flex items-center gap-0.5 bg-surface-100 dark:bg-surface-700 rounded-xl p-0.5">
          <button
            @click="drawEraser = false"
            :class="[
              'p-1.5 rounded-lg transition-colors',
              !drawEraser ? 'bg-primary-500 text-white shadow-sm' : 'text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600'
            ]"
            title="Pen"
          >
            <span class="material-symbols-rounded text-base">draw</span>
          </button>
          <button
            @click="drawEraser = true"
            :class="[
              'p-1.5 rounded-lg transition-colors',
              drawEraser ? 'bg-red-500 text-white shadow-sm' : 'text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600'
            ]"
            title="Eraser"
          >
            <span class="material-symbols-rounded text-base">ink_eraser</span>
          </button>
        </div>
        
        <!-- Divider -->
        <div class="w-px h-6 bg-surface-200 dark:bg-surface-600"></div>
        
        <!-- Color picker -->
        <MoodColorPicker
          :model-value="drawColor"
          @update:model-value="drawColor = $event"
          :palette="store.getColorPalette()"
          label="Drawing color"
          :show-caret="false"
          dropdown-position="bottom-full left-0 mb-2"
        />
        <!-- Eyedropper -->
        <button
          v-if="hasEyeDropper"
          @click="pickDrawColorFromScreen"
          class="p-1.5 rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-primary-500 transition-colors"
          title="Pick color from screen"
        >
          <span class="material-symbols-rounded text-lg">colorize</span>
        </button>
        <!-- Quick colors -->
        <div class="flex gap-1">
          <button
            v-for="c in drawQuickColors"
            :key="c"
            @click="drawColor = c; drawEraser = false"
            class="w-5 h-5 rounded-full border border-surface-300 dark:border-surface-600 hover:scale-110 transition-transform"
            :style="{ backgroundColor: c }"
            :class="{ 'ring-2 ring-primary-500 ring-offset-1': drawColor === c && !drawEraser }"
          />
        </div>
        
        <!-- Divider -->
        <div class="w-px h-6 bg-surface-200 dark:bg-surface-600"></div>
        
        <!-- Stroke width -->
        <div class="flex items-center gap-1.5" title="Stroke width">
          <span class="material-symbols-rounded text-sm text-surface-400">line_weight</span>
          <input
            type="range"
            v-model.number="drawStrokeWidth"
            min="1"
            max="30"
            class="w-20 accent-primary-500"
          />
          <span class="text-[10px] font-mono text-surface-400 w-4 text-right">{{ drawStrokeWidth }}</span>
        </div>
        
        <!-- Brush Options popup -->
        <div class="relative" ref="brushOptionsRef">
          <button
            @click.stop="showBrushOptions = !showBrushOptions"
            :class="[
              'p-1.5 rounded-lg transition-colors',
              showBrushOptions
                ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
            title="Brush options"
          >
            <span class="material-symbols-rounded text-base">tune</span>
          </button>
          <!-- Brush options dropdown -->
          <transition name="draw-bar">
            <div
              v-if="showBrushOptions"
              class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-72 bg-white dark:bg-surface-800 rounded-2xl shadow-2xl border border-surface-200 dark:border-surface-700 p-4 space-y-3"
              @mousedown.stop
            >
              <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-surface-400">Brush Settings</span>
                <button
                  @click="startSavePreset"
                  class="flex items-center gap-0.5 text-[10px] font-medium text-primary-500 hover:text-primary-400 transition-colors"
                  title="Save current settings as preset"
                >
                  <span class="material-symbols-rounded text-sm">bookmark_add</span>
                  Save Preset
                </button>
              </div>

              <!-- Save preset name input -->
              <div v-if="showPresetNameInput" class="flex items-center gap-1.5 mb-2">
                <input
                  ref="presetNameInputRef"
                  v-model="newPresetName"
                  @keydown.enter="saveBrushPreset"
                  @keydown.escape="showPresetNameInput = false"
                  type="text"
                  placeholder="Preset name..."
                  class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300 placeholder-surface-400 focus:outline-none focus:ring-1 focus:ring-primary-500"
                />
                <button
                  @click="saveBrushPreset"
                  :disabled="!newPresetName.trim()"
                  class="p-1 rounded-lg text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                  title="Save"
                >
                  <span class="material-symbols-rounded text-base">check</span>
                </button>
                <button
                  @click="showPresetNameInput = false"
                  class="p-1 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                  title="Cancel"
                >
                  <span class="material-symbols-rounded text-base">close</span>
                </button>
              </div>

              <!-- Saved presets list -->
              <div v-if="brushPresets.length" class="mb-2 space-y-1">
                <div
                  v-for="preset in brushPresets"
                  :key="preset.id"
                  class="group flex items-center gap-1.5 px-2 py-1 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors cursor-pointer"
                  @click="loadBrushPreset(preset)"
                  :title="'Load preset: ' + preset.name"
                >
                  <span class="material-symbols-rounded text-sm text-surface-400 group-hover:text-primary-500 transition-colors">brush</span>
                  <span class="flex-1 text-xs text-surface-600 dark:text-surface-300 truncate">{{ preset.name }}</span>
                  <span class="text-[9px] text-surface-400 font-mono">{{ preset.settings.size }}px</span>
                  <button
                    @click.stop="deleteBrushPreset(preset.id)"
                    class="p-0.5 rounded text-surface-300 dark:text-surface-600 opacity-0 group-hover:opacity-100 hover:text-red-500 dark:hover:text-red-400 transition-all"
                    title="Delete preset"
                  >
                    <span class="material-symbols-rounded text-sm">delete</span>
                  </button>
                </div>
              </div>

              <!-- Divider (if presets exist or save input shown) -->
              <div v-if="brushPresets.length || showPresetNameInput" class="border-t border-surface-200 dark:border-surface-700 mb-1"></div>

              <!-- Size -->
              <div class="flex items-center justify-between gap-3 group/setting" title="Brush diameter in pixels">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Size</label>
                <input type="range" v-model.number="drawStrokeWidth" min="1" max="50" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ drawStrokeWidth }}</span>
              </div>

              <!-- Thinning -->
              <div class="flex items-center justify-between gap-3" title="How much the stroke thins at speed. Negative values thicken at speed.">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Thinning</label>
                <input type="range" v-model.number="drawThinning" min="-1" max="1" step="0.05" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ drawThinning.toFixed(2) }}</span>
              </div>

              <!-- Smoothing -->
              <div class="flex items-center justify-between gap-3" title="How much to smooth the path. Higher values create softer curves.">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Smoothing</label>
                <input type="range" v-model.number="drawSmoothing" min="0" max="1" step="0.05" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ drawSmoothing.toFixed(2) }}</span>
              </div>

              <!-- Streamline -->
              <div class="flex items-center justify-between gap-3" title="How much to streamline the stroke. Higher values straighten the line.">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Streamline</label>
                <input type="range" v-model.number="drawStreamline" min="0" max="1" step="0.05" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ drawStreamline.toFixed(2) }}</span>
              </div>

              <!-- Easing -->
              <div class="flex items-center justify-between gap-3" title="Easing function applied to the entire stroke width variation.">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Easing</label>
                <select
                  v-model="drawEasing"
                  class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300"
                >
                  <option value="linear">Linear</option>
                  <option value="easeIn">Ease In</option>
                  <option value="easeOut">Ease Out</option>
                  <option value="easeInOut">Ease In-Out</option>
                </select>
              </div>

              <!-- Divider -->
              <div class="border-t border-surface-200 dark:border-surface-700"></div>

              <!-- Taper Start -->
              <div class="flex items-center justify-between gap-3" title="Length of taper at the start of the stroke (0 = no taper).">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Taper Start</label>
                <input type="range" v-model.number="drawTaperStart" min="0" max="200" step="1" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ drawTaperStart }}</span>
              </div>

              <!-- Easing Start -->
              <div class="flex items-center justify-between gap-3" title="Easing curve for the start taper. Controls how quickly it narrows.">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Easing Start</label>
                <select
                  v-model="drawEasingStart"
                  class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300"
                >
                  <option value="linear">Linear</option>
                  <option value="easeIn">Ease In</option>
                  <option value="easeOut">Ease Out</option>
                  <option value="easeInOut">Ease In-Out</option>
                </select>
              </div>

              <!-- Divider -->
              <div class="border-t border-surface-200 dark:border-surface-700"></div>

              <!-- Taper End -->
              <div class="flex items-center justify-between gap-3" title="Length of taper at the end of the stroke (0 = no taper).">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Taper End</label>
                <input type="range" v-model.number="drawTaperEnd" min="0" max="200" step="1" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ drawTaperEnd }}</span>
              </div>

              <!-- Easing End -->
              <div class="flex items-center justify-between gap-3" title="Easing curve for the end taper. Controls how quickly it narrows.">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Easing End</label>
                <select
                  v-model="drawEasingEnd"
                  class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300"
                >
                  <option value="linear">Linear</option>
                  <option value="easeIn">Ease In</option>
                  <option value="easeOut">Ease Out</option>
                  <option value="easeInOut">Ease In-Out</option>
                </select>
              </div>

              <!-- Divider -->
              <div class="border-t border-surface-200 dark:border-surface-700"></div>

              <!-- Cap Start / Cap End toggles -->
              <div class="grid grid-cols-2 gap-3">
                <div class="flex items-center justify-between" title="Round cap at the start of the stroke.">
                  <label class="text-xs text-surface-600 dark:text-surface-400">Cap Start</label>
                  <button
                    @click="drawCapStart = !drawCapStart"
                    class="w-9 h-5 rounded-full transition-colors relative flex-shrink-0"
                    :class="drawCapStart ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                  >
                    <span
                      class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform duration-200"
                      :class="drawCapStart ? 'translate-x-4' : 'translate-x-0'"
                    />
                  </button>
                </div>
                <div class="flex items-center justify-between" title="Round cap at the end of the stroke.">
                  <label class="text-xs text-surface-600 dark:text-surface-400">Cap End</label>
                  <button
                    @click="drawCapEnd = !drawCapEnd"
                    class="w-9 h-5 rounded-full transition-colors relative flex-shrink-0"
                    :class="drawCapEnd ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                  >
                    <span
                      class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform duration-200"
                      :class="drawCapEnd ? 'translate-x-4' : 'translate-x-0'"
                    />
                  </button>
                </div>
              </div>

              <!-- Simulate Pressure -->
              <div class="flex items-center justify-between" title="Simulates pen pressure when using a mouse. Creates natural width variation.">
                <label class="text-xs text-surface-600 dark:text-surface-400">Simulate Pressure</label>
                <button
                  @click="drawSimulatePressure = !drawSimulatePressure"
                  class="w-9 h-5 rounded-full transition-colors relative flex-shrink-0"
                  :class="drawSimulatePressure ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                >
                  <span
                    class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform duration-200"
                    :class="drawSimulatePressure ? 'translate-x-4' : 'translate-x-0'"
                  />
                </button>
              </div>

              <!-- Divider -->
              <div class="border-t border-surface-200 dark:border-surface-700"></div>

              <!-- Reset -->
              <button
                @click="resetBrushOptions"
                class="w-full text-center text-xs font-medium text-surface-500 dark:text-surface-400 hover:text-primary-500 transition-colors py-1"
                title="Reset all brush settings to defaults"
              >
                Reset Options
              </button>
            </div>
          </transition>
        </div>
        
        <!-- Divider -->
        <div class="w-px h-6 bg-surface-200 dark:bg-surface-600"></div>
        
        <!-- Undo / Clear -->
        <button
          @click="undoSessionStroke"
          :disabled="drawSessionStrokes.length === 0"
          :class="[
            'p-1.5 rounded-lg transition-colors',
            drawSessionStrokes.length === 0
              ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
              : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
          title="Undo last stroke (Ctrl+Z)"
        >
          <span class="material-symbols-rounded text-base">undo</span>
        </button>
        <button
          @click="clearSessionStrokes"
          :disabled="drawSessionStrokes.length === 0"
          :class="[
            'p-1.5 rounded-lg transition-colors',
            drawSessionStrokes.length === 0
              ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
              : 'text-surface-600 dark:text-surface-400 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-500'
          ]"
          title="Clear all strokes"
        >
          <span class="material-symbols-rounded text-base">delete_sweep</span>
        </button>
        
        <!-- Divider -->
        <div class="w-px h-6 bg-surface-200 dark:bg-surface-600"></div>
        
        <!-- Done button (saves drawn strokes as movable items) -->
        <button
          @click="saveSessionAndExit"
          class="px-3 py-1 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
        >
          Done
        </button>
      </div>
    </transition>

    <!-- Pen tool overlay -->
    <MoodPenTool
      v-if="penMode"
      :zoom="store.zoom"
      :panX="store.panX"
      :panY="store.panY"
      @create-shape="onPenShapeCreated"
      @cancel="penMode = false"
    />
    
    <!-- Rulers & draggable guide lines -->
    <MoodRulers
      :container-width="containerWidth"
      :container-height="containerHeight"
      :top-offset="store.presentationMode ? 0 : 48"
    />

    <!-- Canvas context menu -->
    <div
      v-if="canvasContextMenu.show"
      class="fixed z-50 bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 py-0.5 min-w-[180px]"
      :style="canvasCtxStyle"
      @click.stop
      @mousedown.stop
    >
      <!-- Clipboard -->
      <button @click="store.copySelectedItems(); closeCanvasContext()" :disabled="store.selectedItemIds.size === 0" class="ctx-btn" :class="store.selectedItemIds.size === 0 ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">content_copy</span>
        <span class="flex-1 text-left">Copy</span>
        <span class="ctx-shortcut">Ctrl+C</span>
      </button>
      <button @click="pasteAtViewportCenter(); closeCanvasContext()" class="ctx-btn ctx-normal">
        <span class="material-symbols-rounded text-[16px]">content_paste</span>
        <span class="flex-1 text-left">Paste</span>
        <span class="ctx-shortcut">Ctrl+V</span>
      </button>
      <button @click="store.duplicateSelectedItems(30, 30); closeCanvasContext()" :disabled="store.selectedItemIds.size === 0" class="ctx-btn" :class="store.selectedItemIds.size === 0 ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">content_copy</span>
        <span class="flex-1 text-left">Duplicate</span>
        <span class="ctx-shortcut">Ctrl+D</span>
      </button>

      <div class="ctx-divider"></div>

      <!-- Grouping -->
      <button @click="store.groupSelectedItems(); closeCanvasContext()" :disabled="store.selectedItemIds.size < 2" class="ctx-btn" :class="store.selectedItemIds.size < 2 ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">group_work</span>
        <span class="flex-1 text-left">Group</span>
        <span class="ctx-shortcut">Ctrl+G</span>
      </button>
      <button @click="store.ungroupSelectedItems(); closeCanvasContext()" :disabled="!store.selectionHasGroups()" class="ctx-btn" :class="!store.selectionHasGroups() ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">workspaces</span>
        <span class="flex-1 text-left">Ungroup</span>
        <span class="ctx-shortcut">Ctrl+Shift+G</span>
      </button>
      <button @click="store.createRepeatGrid(); closeCanvasContext()" :disabled="store.selectedItemIds.size < 1" class="ctx-btn" :class="store.selectedItemIds.size < 1 ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">grid_view</span>
        <span class="flex-1 text-left">Repeat Grid</span>
      </button>

      <!-- Boolean operations (only when 2+ shapes selected) -->
      <template v-if="store.canBooleanOp()">
        <div class="ctx-divider"></div>
        <button @click="store.booleanOp('union'); closeCanvasContext()" class="ctx-btn ctx-normal">
          <span class="material-symbols-rounded text-[16px]">join_full</span>
          <span class="flex-1 text-left">Union</span>
          <span class="ctx-shortcut">Alt+Shift+U</span>
        </button>
        <button @click="store.booleanOp('subtract'); closeCanvasContext()" class="ctx-btn ctx-normal">
          <span class="material-symbols-rounded text-[16px]">join_left</span>
          <span class="flex-1 text-left">Subtract</span>
          <span class="ctx-shortcut">Alt+Shift+S</span>
        </button>
        <button @click="store.booleanOp('intersect'); closeCanvasContext()" class="ctx-btn ctx-normal">
          <span class="material-symbols-rounded text-[16px]">join_inner</span>
          <span class="flex-1 text-left">Intersect</span>
          <span class="ctx-shortcut">Alt+Shift+I</span>
        </button>
        <button @click="store.booleanOp('exclude'); closeCanvasContext()" class="ctx-btn ctx-normal">
          <span class="material-symbols-rounded text-[16px]">join_right</span>
          <span class="flex-1 text-left">Exclude</span>
          <span class="ctx-shortcut">Alt+Shift+E</span>
        </button>
        <button @click="store.booleanOp('flatten'); closeCanvasContext()" class="ctx-btn ctx-normal">
          <span class="material-symbols-rounded text-[16px]">layers_clear</span>
          <span class="flex-1 text-left">Flatten</span>
          <span class="ctx-shortcut">Alt+Shift+F</span>
        </button>
      </template>

      <div class="ctx-divider"></div>

      <!-- Z-order -->
      <button @click="ctxBringToFront(); closeCanvasContext()" :disabled="store.selectedItemIds.size === 0" class="ctx-btn" :class="store.selectedItemIds.size === 0 ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">flip_to_front</span>
        <span class="flex-1 text-left">Bring to Front</span>
        <span class="ctx-shortcut">]</span>
      </button>
      <button @click="ctxSendToBack(); closeCanvasContext()" :disabled="store.selectedItemIds.size === 0" class="ctx-btn" :class="store.selectedItemIds.size === 0 ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">flip_to_back</span>
        <span class="flex-1 text-left">Send to Back</span>
        <span class="ctx-shortcut">[</span>
      </button>

      <div class="ctx-divider"></div>

      <!-- Flip -->
      <button @click="ctxFlipHorizontal(); closeCanvasContext()" :disabled="store.selectedItemIds.size === 0" class="ctx-btn" :class="store.selectedItemIds.size === 0 ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">swap_horiz</span>
        <span class="flex-1 text-left">Flip Horizontal</span>
      </button>
      <button @click="ctxFlipVertical(); closeCanvasContext()" :disabled="store.selectedItemIds.size === 0" class="ctx-btn" :class="store.selectedItemIds.size === 0 ? 'ctx-disabled' : 'ctx-normal'">
        <span class="material-symbols-rounded text-[16px]">swap_vert</span>
        <span class="flex-1 text-left">Flip Vertical</span>
      </button>

      <div class="ctx-divider"></div>

      <!-- Select all -->
      <button @click="store.selectAll(); closeCanvasContext()" class="ctx-btn ctx-normal">
        <span class="material-symbols-rounded text-[16px]">select_all</span>
        <span class="flex-1 text-left">Select All</span>
        <span class="ctx-shortcut">Ctrl+A</span>
      </button>

      <div class="ctx-divider"></div>

      <!-- Import -->
      <button @click="triggerSketchImport(); closeCanvasContext()" class="ctx-btn ctx-normal">
        <span class="material-symbols-rounded text-[16px]">upload_file</span>
        <span class="flex-1 text-left">Import .sketch</span>
      </button>

      <div class="ctx-divider"></div>

      <!-- Delete -->
      <button @click="store.deleteSelectedItems(); closeCanvasContext()" :disabled="store.selectedItemIds.size === 0" class="ctx-btn" :class="store.selectedItemIds.size === 0 ? 'ctx-disabled' : 'ctx-danger'">
        <span class="material-symbols-rounded text-[16px]">delete</span>
        <span class="flex-1 text-left">Delete</span>
        <span class="ctx-shortcut">Del</span>
      </button>
    </div>

    <!-- Comment pin context menu -->
    <div
      v-if="commentContextMenu.show"
      class="fixed z-[99999] bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 py-0.5 min-w-[180px]"
      :style="{ left: commentContextMenu.x + 'px', top: commentContextMenu.y + 'px' }"
      @click.stop
      @mousedown.stop
    >
      <button @click="ctxOpenCommentThread" class="ctx-btn ctx-normal">
        <span class="material-symbols-rounded text-[16px]">chat_bubble</span>
        <span class="flex-1 text-left">Open Thread</span>
      </button>
      <div class="ctx-divider"></div>
      <button @click="ctxDeleteCommentThread" class="ctx-btn ctx-danger">
        <span class="material-symbols-rounded text-[16px]">delete</span>
        <span class="flex-1 text-left">Delete Thread</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted, provide } from 'vue'
import api from '@/services/api'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { useCanvasMeasure } from '@/addons/moodboards/composables/useCanvasMeasure'
import { nextZIndexInScope, layerScope } from '@/addons/moodboards/utils/layerOrderUtils'
import { computeDistanceGuides } from '@/addons/moodboards/utils/distanceGuides'
import { getStroke } from 'perfect-freehand'
import { getAutoLayoutChildStyle, getGridChildStyle } from '@/composables/useFrameLayout'
import MoodCanvasItem from './MoodCanvasItem.vue'
import MoodCommentBubble from './MoodCommentBubble.vue'
import MoodColorPicker from './MoodColorPicker.vue'
import MoodPenTool from './MoodPenTool.vue'
import MoodRulers from './MoodRulers.vue'
import { parseSketchFile, sketchToMoodItems, getSketchPageNames } from '../utils/sketchParser'

const props = defineProps({
  board: { type: Object, default: null },
  beforeUpload: { type: Function, default: null },
  readonly: { type: Boolean, default: false },
  commentCounts: { type: Object, default: () => ({}) },
  commentThreads: { type: Array, default: () => [] },
  activeCommentThreadId: { type: String, default: null },
  showCommentPins: { type: Boolean, default: true },
})

const drawMode = defineModel('drawMode', { type: Boolean, default: false })
const penMode = defineModel('penMode', { type: Boolean, default: false })
const lineMode = defineModel('lineMode', { type: Boolean, default: false })
const snapGrid = defineModel('snapGrid', { type: Boolean, default: false })
const snapCenter = defineModel('snapCenter', { type: Boolean, default: true })
const commentMode = defineModel('commentMode', { type: Boolean, default: false })
const measureMode = defineModel('measureMode', { type: Boolean, default: false })
const measureColor = defineModel('measureColor', { type: String, default: '#0ea5e9' })
const measureWidth = defineModel('measureWidth', { type: Number, default: 1.5 })
const measureVisible = defineModel('measureVisible', { type: Boolean, default: true })
const measureCount = defineModel('measureCount', { type: Number, default: 0 })

const emit = defineEmits(['item-context', 'select-connection', 'connection-context', 'add-item-at', 'open-color-picker', 'pick-color', 'edit-drawing', 'before-upload', 'preview-file', 'edit-file-collab', 'browse-folder', 'comment-item', 'comment-canvas', 'select-comment-thread', 'delete-comment-thread'])

const store = useMoodBoardsStore()
const measure = useCanvasMeasure()

// Load measurements from board data (fetched from backend)
watch(() => props.board?.id, (id, oldId) => {
  if (oldId) measure.unloadBoard()
  if (id) {
    measure.loadBoard(id, props.board)
    measureColor.value = measure.lineColor.value
    measureWidth.value = measure.lineWidth.value
    measureVisible.value = measure.visible.value
    measureCount.value = measure.measurements.value.length
  }
}, { immediate: true })

watch(measureColor, (v) => { measure.lineColor.value = v })
watch(measureWidth, (v) => { measure.lineWidth.value = v })
watch(measureVisible, (v) => { measure.visible.value = v })
watch(() => measure.lineColor.value, (v) => { measureColor.value = v })
watch(() => measure.lineWidth.value, (v) => { measureWidth.value = v })
watch(() => measure.visible.value, (v) => { measureVisible.value = v })
watch(() => measure.measurements.value.length, (v) => { measureCount.value = v })

function measureLabelWidth(m) {
  const text = m.distance + 'px'
  return Math.max(text.length * 7 + 16, 44)
}

// Provide handler functions to nested frame children (MoodCanvasItem renders its own children recursively)
provide('frameChildHandlers', {
  onItemMouseDown: (e, item) => onItemMouseDown(e, item),
  onConnectionStart: (item) => onConnectionStart(item),
  onConnectionEnd: (item) => onConnectionEnd(item),
  emitItemContext: (e, item) => emit('item-context', e, item),
  emitOpenColorPicker: (c) => emit('open-color-picker', c),
  emitEditDrawing: (c) => emit('edit-drawing', c),
  emitPreviewFile: (c) => emit('preview-file', c),
  emitEditFileCollab: (c) => emit('edit-file-collab', c),
  emitBrowseFolder: (c) => emit('browse-folder', c),
  onAddImagesToSet: (c) => onAddImagesToSet(c),
  onDropFilesToSet: (c, files) => onDropFilesToSet(c, files),
  onRemoveImageFromSet: (imgId) => onRemoveImageFromSet(imgId),
  onReplaceImage: (c) => onReplaceImage(c),
})

// Invisible snap grid size (items snap to this grid when released)
const GRID_SIZE = 10

const canvasContainer = ref(null)
const containerWidth = ref(1000)
const containerHeight = ref(700)
const canvasLayer = ref(null)

// Robust locked check: handles boolean true, number 1, string "1", string "0" (falsy)
function isItemLocked(item) {
  if (!item) return false
  const v = item.locked
  if (v === true || v === 1 || v === '1') return true
  return false
}

// Drag state
const dragStart = ref(null)
// _dragState is intentionally NOT reactive — bypasses Vue's proxy system during drag
// to avoid triggering 3,600+ computed re-evaluations per animation frame.
// Instead, items are moved via direct DOM manipulation during drag, and the final
// positions are written back to the reactive store on mouseUp (one batch update).
let _dragState = null
const selectionRect = ref(null)
const selectionStart = ref(null)
const tempConnectionEnd = ref(null)
const selectedConnectionId = ref(null)
let _anchorDragState = null // { connId, endpoint: 'from'|'to' }
let _bendDragState = null // { connId, pointIndex } — active while dragging a bend handle

// RAF throttle for smooth 60fps drag/resize
let rafId = null

// Space key state for pan mode
const spaceHeld = ref(false)

// Smart guide snapping state
const smartGuides = ref([])
const distanceMeasurements = ref([])
const SNAP_THRESHOLD = 8 // px threshold for snapping to item edges/centers
const CENTER_SNAP_THRESHOLD = 24 // wider magnetic zone for container center lines (in canvas px)

// Grain canvas
const grainCanvas = ref(null)

// Context menu
const canvasContextMenu = ref({ show: false, x: 0, y: 0, canvasX: 0, canvasY: 0 })
const CANVAS_CTX_HEIGHT = 480
const canvasCtxStyle = computed(() => {
  const style = { left: canvasContextMenu.value.x + 'px' }
  const spaceBelow = window.innerHeight - canvasContextMenu.value.y
  if (spaceBelow < CANVAS_CTX_HEIGHT) {
    style.bottom = (window.innerHeight - canvasContextMenu.value.y) + 'px'
  } else {
    style.top = canvasContextMenu.value.y + 'px'
  }
  return style
})
const commentContextMenu = ref({ show: false, x: 0, y: 0, thread: null })

// ── Canvas animation state ──────────────────────────────────
// will-change: transform creates a GPU compositor layer — great for smooth pan/zoom
// BUT the browser rasterizes at the pre-transform size, so scaled SVG/text becomes
// pixelated.  We enable will-change only while actively animating, then disable it
// to force re-rasterization at the final resolution.  This is why lines "refresh"
// when you click the canvas — the click causes a repaint.
const canvasAnimating = ref(false)
let _animSettleTimer = null

function markCanvasAnimating() {
  canvasAnimating.value = true
  clearTimeout(_animSettleTimer)
  // 350ms settle — long enough to cover gaps between consecutive wheel events,
  // preventing the GPU layer from being torn down and rebuilt mid-scroll which
  // causes SVG connection lines to flicker.
  _animSettleTimer = setTimeout(() => { canvasAnimating.value = false }, 350)
}

// Canvas transform — GPU-accelerated during animation, crisp at rest.
// In presentation mode, keep will-change: transform permanently ON to prevent
// the browser from re-rasterizing the entire canvas layer between every slide
// transition, which causes visible flicker/element disappearance.
const canvasTransform = computed(() => ({
  transform: `translate(${store.panX}px, ${store.panY}px) scale(${store.zoom})`,
  transformOrigin: '0 0',
  willChange: (store.presentationMode || canvasAnimating.value) ? 'transform' : 'auto',
  // Improve text rendering consistency across fractional zoom levels
  textRendering: 'geometricPrecision',
}))

// ========================================
// BACKGROUND EFFECTS
// ========================================
const bgEffect = computed(() => store.getBackgroundEffect())

const bgEffectStyles = computed(() => {
  const fx = bgEffect.value
  if (!fx) return null

  const layers = []

  // Gradient overlay
  if (fx.gradient?.enabled) {
    const angle = fx.gradient.angle ?? 135
    const from = fx.gradient.from || '#000000'
    const to = fx.gradient.to || '#ffffff'
    const op = (fx.gradient.opacity ?? 30) / 100
    layers.push(`linear-gradient(${angle}deg, ${hexToRgba(from, op)}, ${hexToRgba(to, op)})`)
  }

  // Vignette
  if (fx.vignette?.enabled) {
    const intensity = (fx.vignette.intensity ?? 40) / 100
    const spread = (fx.vignette.spread ?? 60)
    layers.push(`radial-gradient(ellipse at center, transparent ${spread}%, rgba(0,0,0,${intensity}) 100%)`)
  }

  if (!layers.length && !fx.blur?.enabled) return null

  const style = {}
  if (layers.length) style.background = layers.join(', ')
  if (fx.blur?.enabled) style.backdropFilter = `blur(${fx.blur.amount ?? 4}px)`
  return style
})

// ── Board background image ──
const bgImageStyle = computed(() => {
  const url = props.board?.background_image
  if (!url) return {}
  const size = props.board?.background_image_size || 'cover'
  const style = {
    backgroundImage: `url(${url})`,
    backgroundPosition: 'center',
  }
  if (size === 'repeat') {
    style.backgroundSize = 'auto'
    style.backgroundRepeat = 'repeat'
  } else {
    style.backgroundSize = size // 'cover' or 'contain'
    style.backgroundRepeat = 'no-repeat'
  }
  return style
})

const grainEnabled = computed(() => bgEffect.value?.grain?.enabled)
const grainCanvasStyle = computed(() => ({
  opacity: ((bgEffect.value?.grain?.intensity ?? 20) / 100),
  mixBlendMode: 'overlay',
}))

function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1, 3), 16)
  const g = parseInt(hex.slice(3, 5), 16)
  const b = parseInt(hex.slice(5, 7), 16)
  return `rgba(${r},${g},${b},${alpha})`
}

// Render grain noise onto the canvas
function renderGrainNoise() {
  const canvas = grainCanvas.value
  if (!canvas) return
  const container = canvasContainer.value
  if (!container) return

  canvas.width = container.clientWidth
  canvas.height = container.clientHeight
  const ctx = canvas.getContext('2d')
  const imgData = ctx.createImageData(canvas.width, canvas.height)
  const data = imgData.data
  const isColor = bgEffect.value?.grain?.mode === 'color'

  for (let i = 0; i < data.length; i += 4) {
    if (isColor) {
      data[i] = Math.random() * 255
      data[i + 1] = Math.random() * 255
      data[i + 2] = Math.random() * 255
    } else {
      const v = Math.random() * 255
      data[i] = v
      data[i + 1] = v
      data[i + 2] = v
    }
    data[i + 3] = 255
  }
  ctx.putImageData(imgData, 0, 0)
}

watch(grainEnabled, (v) => {
  if (v) nextTick(renderGrainNoise)
})

watch(() => bgEffect.value?.grain?.mode, () => {
  if (grainEnabled.value) nextTick(renderGrainNoise)
})

// Re-render grain when container resizes (e.g. entering/exiting presentation mode changes viewport)
let grainResizeObserver = null
function setupGrainResizeObserver() {
  if (grainResizeObserver) { grainResizeObserver.disconnect(); grainResizeObserver = null }
  if (grainEnabled.value && canvasContainer.value) {
    grainResizeObserver = new ResizeObserver(() => {
      if (grainEnabled.value) renderGrainNoise()
    })
    grainResizeObserver.observe(canvasContainer.value)
  }
}
watch(grainEnabled, () => nextTick(setupGrainResizeObserver))

// Items sorted by z-index — top-level only (not parented inside a column/auto-layout frame, not masked inside a shape)
// Column children and auto-layout frame children are rendered inside their parent's slot.
const sortedItems = computed(() => {
  if (!props.board?.items) return []
  const filtered = [...props.board.items]
    .filter(i => {
      if (!i.parent_id) return true
      const parent = store.getItemById(i.parent_id)
      if (parent?.type === 'column') return false
      if (parent?.type === 'frame') return false
      if (parent?.type === 'group') return false
      if (parent?.type === 'repeat_grid') return false
      return true
    })
    .filter(i => !i.style_data?.mask_parent_id)
    .filter(i => {
      if (i.type === 'slide') {
        if (store.presentationMode) return false
        return store.slidesVisible
      }
      return true
    })
  // Slides are always rendered first (behind everything) in the DOM.
  // Non-slides follow in z-index order. Combined with the CSS z-index
  // offset in MoodCanvasItem, this ensures clicks hit content elements
  // on top of slides, not the slide frame itself.
  // Secondary sort by id guarantees deterministic DOM order for items with
  // equal z_index — prevents visual stacking jumps during drag/re-render.
  const stableSort = (a, b) => (a.z_index || 0) - (b.z_index || 0) || a.id - b.id
  const slides = filtered.filter(i => i.type === 'slide').sort(stableSort)
  const nonSlides = filtered.filter(i => i.type !== 'slide').sort(stableSort)
  return [...slides, ...nonSlides]
})

// ========================================
// VIEWPORT CULLING & LEVEL OF DETAIL (LOD)
// ========================================
// Compute the visible viewport bounds in canvas coordinates
const viewportBounds = computed(() => {
  const zoom = store.zoom || 1
  const container = canvasContainer.value
  const vpW = container ? container.clientWidth : 1920
  const vpH = container ? container.clientHeight : 1080
  // Convert screen coords to canvas coords
  const left = -store.panX / zoom
  const top = -store.panY / zoom
  const right = left + vpW / zoom
  const bottom = top + vpH / zoom
  // In presentation mode, use much larger padding so elements around adjacent slides
  // are pre-rendered and ready for smooth transitions. Edit mode uses a smaller pad.
  const basePad = store.presentationMode ? 2000 : 400
  const pad = basePad / zoom
  return { left: left - pad, top: top - pad, right: right + pad, bottom: bottom + pad }
})

provide('canvasViewportBounds', viewportBounds)

// Only render items that overlap the viewport (viewport culling)
// In presentation mode, still cull but with generous bounds (set above).
// Group bounding box for Figma-like group overlay
const groupBBox = computed(() => {
  if (!store.currentBoard?.items) return null

  // Real group (type='group'): show bbox when the group item is selected
  if (!store.editingGroupId && store.selectedItemIds.size === 1) {
    const selId = [...store.selectedItemIds][0]
    const selItem = store.getItemById(selId)
    if (selItem?.type === 'group') {
      const children = store.currentItems.filter(i => i.parent_id === selId)
      if (children.length === 0) return null
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
      for (const c of children) {
        minX = Math.min(minX, c.pos_x || 0)
        minY = Math.min(minY, c.pos_y || 0)
        maxX = Math.max(maxX, (c.pos_x || 0) + (c.width || 240))
        maxY = Math.max(maxY, (c.pos_y || 0) + (c.height || 120))
      }
      return { x: minX - 4, y: minY - 4, w: maxX - minX + 8, h: maxY - minY + 8 }
    }
  }

  // Editing inside a real group: show dashed bbox around the group
  if (store.editingGroupId) {
    const groupItem = store.getItemById(store.editingGroupId)
    if (groupItem?.type === 'group') {
      const children = store.currentItems.filter(i => i.parent_id === groupItem.id)
      if (children.length === 0) return null
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
      for (const c of children) {
        minX = Math.min(minX, c.pos_x || 0)
        minY = Math.min(minY, c.pos_y || 0)
        maxX = Math.max(maxX, (c.pos_x || 0) + (c.width || 240))
        maxY = Math.max(maxY, (c.pos_y || 0) + (c.height || 120))
      }
      return { x: minX - 4, y: minY - 4, w: maxX - minX + 8, h: maxY - minY + 8 }
    }
  }

  // Legacy groups (style_data.group_id)
  let gid = null
  if (!store.editingGroupId && store.selectedItemIds.size > 1) {
    const firstItem = store.currentItems.find(i => store.selectedItemIds.has(i.id))
    gid = firstItem?.style_data?.group_id
    if (!gid) return null
    const groupMembers = store.currentItems.filter(i => i.style_data?.group_id === gid)
    const groupIds = new Set(groupMembers.map(i => i.id))
    for (const id of store.selectedItemIds) {
      if (!groupIds.has(id)) return null
    }
  } else if (store.editingGroupId) {
    gid = store.editingGroupId
  }
  if (!gid) return null
  const members = store.currentItems.filter(i => i.style_data?.group_id === gid)
  if (members.length < 2) return null
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const m of members) {
    const x = m.pos_x || 0
    const y = m.pos_y || 0
    const w = m.width || 240
    const h = m.height || 120
    minX = Math.min(minX, x)
    minY = Math.min(minY, y)
    maxX = Math.max(maxX, x + w)
    maxY = Math.max(maxY, y + h)
  }
  return { x: minX - 4, y: minY - 4, w: maxX - minX + 8, h: maxY - minY + 8 }
})

// If culling would produce an empty list (stale container dimensions after fullscreen
// transition), fall back to rendering all items as a safety net.
// Frozen during drag so DOM order never shifts mid-drag (prevents z-index visual jumps).
let _frozenVisibleItems = null
const visibleItems = computed(() => {
  if (store.isDragging && _frozenVisibleItems) return _frozenVisibleItems
  const vp = viewportBounds.value
  const all = sortedItems.value
  const culled = all.filter(item => {
    const x = item.pos_x || 0
    const y = item.pos_y || 0
    const w = item.width || 240
    const h = item.height || 200
    return x + w >= vp.left && x <= vp.right && y + h >= vp.top && y <= vp.bottom
  })
  const result = (culled.length === 0 && all.length > 0) ? all : culled
  _frozenVisibleItems = result
  return result
})

// Items that have at least one comment thread (for rendering badges)
const itemsWithComments = computed(() => {
  if (!props.commentCounts || Object.keys(props.commentCounts).length === 0) return []
  return visibleItems.value.filter(item => props.commentCounts[item.id])
})

// Canvas-level comment threads (have pin_x/pin_y, no item_id or with item_id)
const canvasCommentThreads = computed(() => {
  return props.commentThreads.filter(t => t.pin_x != null && t.pin_y != null)
})

function onCommentBubbleClick(item) {
  const rect = canvasContainer.value?.getBoundingClientRect()
  if (!rect) return
  const screenX = rect.left + (item.pos_x + (item.width || 240)) * store.zoom + store.panX
  const screenY = rect.top + item.pos_y * store.zoom + store.panY
  emit('comment-item', { itemId: item.id, screenX: Math.round(screenX), screenY: Math.round(screenY) })
}

// LOD zoom tier: 'full' (>40%), 'medium' (15-40%), 'low' (<15%)
// Lowered thresholds — items kept readable longer before going to placeholders.
// In presentation mode, always render at full detail — the viewer expects crisp
// content regardless of how far the camera zooms out to fit a large slide.
const zoomTier = computed(() => {
  if (store.presentationMode) return 'full'
  const z = store.zoom || 1
  if (z >= 0.4) return 'full'
  if (z >= 0.15) return 'medium'
  return 'low'
})

// Should animated connection dots be shown? Disabled when motion off, at low zoom, or with many connections
const showConnectionAnimations = computed(() => {
  if (!store.motionEnabled || !store.motionLines) return false
  if (store.isDragging) return false
  if (store.zoom < 0.3) return false
  // If there are many connections, skip animations at medium zoom for perf
  if (connections.value.length > 20 && store.zoom < 0.5) return false
  return true
})

// Should SVG wave filter be applied? Expensive — disable at low zoom or many connections
const showWaveFilter = computed(() => {
  if (!store.motionEnabled || !store.motionLines) return false
  if (store.zoom < 0.3) return false
  if (connections.value.length > 30) return false
  return true
})

// Child items grouped by parent column id
function getColumnChildren(columnId) {
  if (!props.board?.items) return []
  return props.board.items
    .filter(i => i.parent_id === columnId)
    .sort((a, b) => (a.z_index || 0) - (b.z_index || 0))
}

// O(1) lookup via store index. Frozen during drag via store.getChildrenOf internal cache.
const _frameChildrenCache = new Map()
function getFrameChildren(frameId) {
  if (store.isDragging && _frameChildrenCache.has(frameId)) {
    return _frameChildrenCache.get(frameId)
  }
  const result = store.getChildrenOf(frameId)
  _frameChildrenCache.set(frameId, result)
  return result
}

function _isGroupWithLayout(item) {
  return item.type === 'group' && item.style_data?.layout_mode && item.style_data.layout_mode !== 'none'
}

function _isBgChild(child) {
  return !!child.style_data?.is_background
}

function getChildParentPos(item, child) {
  if ((item.type === 'group' && (!item.style_data?.layout_mode || item.style_data.layout_mode === 'none')) || item.type === 'repeat_grid') {
    return { x: item.pos_x || 0, y: item.pos_y || 0 }
  }
  if (item.style_data?.auto_layout || _isGroupWithLayout(item)) {
    if (_isGroupWithLayout(item) && _isBgChild(child)) {
      return { x: item.pos_x || 0, y: item.pos_y || 0 }
    }
    return { x: child.pos_x || 0, y: child.pos_y || 0 }
  }
  return { x: item.pos_x || 0, y: item.pos_y || 0 }
}

function isChildInAutoLayout(item, child) {
  if (_isGroupWithLayout(item) && _isBgChild(child)) return false
  return (item.type === 'frame' && !!item.style_data?.auto_layout) || _isGroupWithLayout(item)
}

function getChildLayoutStyle(item, child) {
  if (_isGroupWithLayout(item) && _isBgChild(child)) {
    return { position: 'absolute', inset: '0', zIndex: 0, width: '100%', height: '100%', pointerEvents: 'auto' }
  }
  if ((item.type === 'frame' && item.style_data?.auto_layout) || (item.type === 'group' && item.style_data?.layout_mode === 'flex')) {
    return getAutoLayoutChildStyle(child, item.style_data?.layout_direction || 'column')
  }
  if (item.type === 'group' && item.style_data?.layout_mode === 'grid') {
    return getGridChildStyle(child)
  }
  return { position: 'absolute' }
}

const connections = computed(() => props.board?.connections || [])
const belowConnections = computed(() => connections.value.filter(c => !c.render_above))
const aboveConnections = computed(() => connections.value.filter(c => c.render_above))

// ========================================
// DRAW-ON ANIMATION FOR CONNECTION LINES
// ========================================
// Track which connections have completed their draw-on entrance animation
const connDrawOnPlayed = ref(new Set())

// Estimate length of a cubic bezier SVG path from the path string
// For M x1 y1 C cx1 cy1, cx2 cy2, x2 y2 — approximate with chord + control polygon
function estimateSvgPathLength(pathStr) {
  if (!pathStr) return 200
  const nums = pathStr.match(/-?[\d.]+/g)
  if (!nums || nums.length < 8) return 200
  const [x1, y1, cx1, cy1, cx2, cy2, x2, y2] = nums.map(Number)
  // Rough approx: average of chord and control polygon length
  const chord = Math.sqrt((x2 - x1) ** 2 + (y2 - y1) ** 2)
  const poly = Math.sqrt((cx1 - x1) ** 2 + (cy1 - y1) ** 2)
    + Math.sqrt((cx2 - cx1) ** 2 + (cy2 - cy1) ** 2)
    + Math.sqrt((x2 - cx2) ** 2 + (y2 - cy2) ** 2)
  return Math.round((chord + poly) / 2) || 200
}

// Get draw-on duration for a connection line based on its length
function getConnDrawOnDuration(conn) {
  const len = estimateSvgPathLength(connectionPathMap.value[conn.id])
  const baseDur = Math.max(0.4, Math.min(2.5, len / 400))
  return baseDur / store.motionDrawOnSpeed
}

// Check if a connection should show draw-on animation (active right now)
function isConnDrawOnActive(conn) {
  return store.motionEnabled && store.motionDrawOn && !connDrawOnPlayed.value.has(conn.id)
}

// Mark connection draw-on as complete
function onConnDrawOnEnd(conn) {
  const next = new Set(connDrawOnPlayed.value)
  next.add(conn.id)
  connDrawOnPlayed.value = next
}

// Reset draw-on when motion settings change
watch(() => store.motionDrawOn, (val) => {
  if (val) connDrawOnPlayed.value = new Set()
})

// Pre-computed connection paths — reactive to item position changes.
// Using a computed map instead of template function calls ensures Vue properly
// tracks item.pos_x / pos_y as dependencies and re-renders connections during drag.
const connectionPathMap = computed(() => {
  const map = {}
  for (const conn of connections.value) {
    map[conn.id] = getConnectionPath(conn)
  }
  return map
})

// Connections with glow enabled — used to generate SVG <filter> defs
const glowConnections = computed(() => connections.value.filter(c => c.glow_enabled))

// Connections with gradient enabled — used to generate SVG <linearGradient> defs
const gradientConnections = computed(() => connections.value.filter(c => c.gradient_enabled))

// Endpoint coordinates per connection — used for gradient linearGradient x1/y1/x2/y2
const connEndpointMap = computed(() => {
  const map = {}
  for (const conn of connections.value) {
    if (!conn.gradient_enabled) continue
    const fromRect = getItemRect(conn.from_item_id)
    const toRect = getItemRect(conn.to_item_id)
    if (!fromRect || !toRect) continue
    const from = resolveConnEndpoint(conn.from_item_id, conn.from_anchor_x, conn.from_anchor_y, toRect.cx, toRect.cy)
    const to = resolveConnEndpoint(conn.to_item_id, conn.to_anchor_x, conn.to_anchor_y, fromRect.cx, fromRect.cy)
    if (!from || !to) continue
    map[conn.id] = { from, to }
  }
  return map
})

// Anchor handle size — inversely scaled to zoom so handles stay a consistent screen size
const anchorHandleRadius = computed(() => Math.min(12, Math.max(6, 7 / store.zoom)))
const anchorHandleStroke = computed(() => Math.min(3, Math.max(1.5, 2 / store.zoom)))

// Computed endpoints for the selected connection — used to render draggable anchor handles
const selectedConnEndpoints = computed(() => {
  if (!selectedConnectionId.value) return null
  const conn = connections.value.find(c => c.id === selectedConnectionId.value)
  if (!conn) return null
  const fromRect = getItemRect(conn.from_item_id)
  const toRect = getItemRect(conn.to_item_id)
  if (!fromRect || !toRect) return null
  const from = resolveConnEndpoint(conn.from_item_id, conn.from_anchor_x, conn.from_anchor_y, toRect.cx, toRect.cy)
  const to = resolveConnEndpoint(conn.to_item_id, conn.to_anchor_x, conn.to_anchor_y, fromRect.cx, fromRect.cy)
  if (!from || !to) return null
  return { from, to, conn }
})

// Computed bend handle positions for the selected connection (2 control points for cubic bezier)
// If bend_x/y or bend2_x/y is set, show at that position; otherwise default to 1/3 and 2/3
const selectedConnBendPoints = computed(() => {
  if (!selectedConnEndpoints.value) return null
  const conn = selectedConnEndpoints.value.conn
  const from = selectedConnEndpoints.value.from
  const to = selectedConnEndpoints.value.to
  const hasBend1 = conn.bend_x != null && conn.bend_y != null
  const hasBend2 = conn.bend2_x != null && conn.bend2_y != null
  return {
    cp1: {
      x: hasBend1 ? conn.bend_x : (from.x * 2 / 3 + to.x / 3),
      y: hasBend1 ? conn.bend_y : (from.y * 2 / 3 + to.y / 3),
      isCustom: hasBend1
    },
    cp2: {
      x: hasBend2 ? conn.bend2_x : (from.x / 3 + to.x * 2 / 3),
      y: hasBend2 ? conn.bend2_y : (from.y / 3 + to.y * 2 / 3),
      isCustom: hasBend2
    }
  }
})

// Resolve connection color - 'accent' maps to theme accentColor, fallback to accentColor
function connColor(conn) {
  if (!conn.line_color || conn.line_color === 'accent') return accentColor.value
  return conn.line_color
}

// Read the accent color from CSS custom properties so connection lines match theme
const accentColor = ref('#22c55e')
function updateAccentColor() {
  const style = getComputedStyle(document.documentElement)
  const rgb = style.getPropertyValue('--color-primary-500').trim()
  if (rgb) {
    const parts = rgb.split(/\s+/)
    if (parts.length === 3) {
      accentColor.value = `rgb(${parts[0]}, ${parts[1]}, ${parts[2]})`
    }
  }
}

const isDarkBg = computed(() => {
  const bg = props.board?.background_color || '#f5f5f5'
  const hex = bg.replace('#', '')
  if (hex.length >= 6) {
    const r = parseInt(hex.substr(0, 2), 16)
    const g = parseInt(hex.substr(2, 2), 16)
    const b = parseInt(hex.substr(4, 2), 16)
    return (r * 299 + g * 587 + b * 114) / 1000 < 128
  }
  return false
})

// In presentation mode, respect the presenter HUD toggles for lines & background grid
const showConnections = computed(() => {
  if (store.presentationMode) return store.presShowLines
  return true
})
const showDotGrid = computed(() => {
  if (store.presentationMode) return store.presShowBackground
  return true
})

// Dot grid style — dot size and spacing scale with zoom level
const dotGridStyle = computed(() => {
  const z = store.zoom || 1
  // Base spacing is 20px, scale with zoom so dots get bigger/smaller
  const spacing = Math.max(6, 20 * z)
  // Dot radius scales with zoom (clamp between 0.5px and 2.5px)
  const dotSize = Math.max(0.5, Math.min(2.5, 1 * z))
  // Offset the grid position based on pan so dots move with the canvas
  const offsetX = (store.panX || 0) % spacing
  const offsetY = (store.panY || 0) % spacing
  const color = isDarkBg.value
    ? `rgba(255,255,255,${0.08 + 0.05 * Math.min(z, 2)})`
    : `rgba(160,170,180,${0.3 + 0.15 * Math.min(z, 2)})`
  return {
    backgroundImage: `radial-gradient(circle, ${color} ${dotSize}px, transparent ${dotSize}px)`,
    backgroundSize: `${spacing}px ${spacing}px`,
    backgroundPosition: `${offsetX}px ${offsetY}px`
  }
})

const cursorStyle = computed(() => {
  if (store.presentationMode) return 'none'
  if (store.isPanning) return 'grabbing'
  if (spaceHeld.value) return 'grab'
  if (measureMode.value) return `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'%3E%3Cline x1='12' y1='0' x2='12' y2='24' stroke='%230ea5e9' stroke-width='1'/%3E%3Cline x1='0' y1='12' x2='24' y2='12' stroke='%230ea5e9' stroke-width='1'/%3E%3Ccircle cx='12' cy='12' r='3' fill='none' stroke='%230ea5e9' stroke-width='1'/%3E%3Cline x1='2' y1='2' x2='7' y2='2' stroke='%230ea5e9' stroke-width='1.5'/%3E%3Cline x1='2' y1='2' x2='2' y2='7' stroke='%230ea5e9' stroke-width='1.5'/%3E%3C/svg%3E") 12 12, crosshair`
  if (commentMode.value) return 'crosshair'
  if (drawMode.value) return 'crosshair'
  if (penMode.value) return 'crosshair'
  if (lineMode.value) return 'crosshair'
  if (store.connectingFrom) return 'crosshair'
  return 'default'
})

// ========================================
// PAN & ZOOM
// ========================================

function onWheel(e) {
  if (store.presentationMode) return
  if (store.followingUser) store.stopFollowing()
  markCanvasAnimating()

  // Zoom: Alt+scroll (PC), Mac pinch (ctrlKey set by browser), or Ctrl+scroll
  if (e.ctrlKey || e.metaKey || e.altKey) {
    let newZoom
    if (e.altKey) {
      // PC Alt+scroll: fixed step per tick for smooth, predictable zoom
      const step = Math.max(0.005, store.zoom * 0.08)
      newZoom = Math.max(0.005, store.zoom + (e.deltaY > 0 ? -step : step))
    } else {
      // Mac trackpad pinch / Ctrl+scroll: proportional zoom from delta magnitude
      const delta = -e.deltaY * 0.01
      newZoom = Math.max(0.005, store.zoom * (1 + delta))
    }

    const rect = canvasContainer.value.getBoundingClientRect()
    const mouseX = e.clientX - rect.left
    const mouseY = e.clientY - rect.top

    const factor = newZoom / store.zoom
    const rawPX = mouseX - factor * (mouseX - store.panX)
    const rawPY = mouseY - factor * (mouseY - store.panY)
    store.zoom = newZoom
    const clamped = clampPan(rawPX, rawPY)
    store.panX = clamped.x
    store.panY = clamped.y
  } else {
    // Two-finger scroll (trackpad) or regular scroll wheel → PAN
    const multiplier = e.deltaMode === 1 ? 16 : 1
    const rawPX = store.panX - e.deltaX * multiplier
    const rawPY = store.panY - e.deltaY * multiplier
    const clamped = clampPan(rawPX, rawPY)
    store.panX = clamped.x
    store.panY = clamped.y
  }
}

// ========================================
// TOUCH HANDLING (mobile pan + pinch-to-zoom)
// ========================================

let _touchState = null // { mode: 'pan'|'pinch', startX, startY, startPanX, startPanY, startDist, startZoom, midX, midY }

function getTouchDist(t1, t2) {
  const dx = t1.clientX - t2.clientX
  const dy = t1.clientY - t2.clientY
  return Math.sqrt(dx * dx + dy * dy)
}

function getTouchMid(t1, t2) {
  return {
    x: (t1.clientX + t2.clientX) / 2,
    y: (t1.clientY + t2.clientY) / 2,
  }
}

function onCanvasTouchStart(e) {
  if (store.presentationMode) return

  if (e.touches.length === 1) {
    // Single finger: pan
    if (store.followingUser) store.stopFollowing()
    const t = e.touches[0]
    _touchState = {
      mode: 'pan',
      startX: t.clientX,
      startY: t.clientY,
      startPanX: store.panX,
      startPanY: store.panY,
    }
  } else if (e.touches.length === 2) {
    // Two fingers: pinch-to-zoom
    if (store.followingUser) store.stopFollowing()
    const t0 = e.touches[0], t1 = e.touches[1]
    const mid = getTouchMid(t0, t1)
    const rect = canvasContainer.value.getBoundingClientRect()
    _touchState = {
      mode: 'pinch',
      startDist: getTouchDist(t0, t1),
      startZoom: store.zoom,
      startPanX: store.panX,
      startPanY: store.panY,
      midX: mid.x - rect.left,
      midY: mid.y - rect.top,
    }
    markCanvasAnimating()
  }
}

function onCanvasTouchMove(e) {
  if (store.presentationMode || !_touchState) return

  if (_touchState.mode === 'pan' && e.touches.length === 1) {
    // If a second finger appeared, switch to pinch
    markCanvasAnimating()
    const t = e.touches[0]
    const rawX = _touchState.startPanX + (t.clientX - _touchState.startX)
    const rawY = _touchState.startPanY + (t.clientY - _touchState.startY)
    const clamped = clampPan(rawX, rawY)
    store.panX = clamped.x
    store.panY = clamped.y
  } else if (e.touches.length === 2) {
    // Upgrade from pan to pinch if needed
    if (_touchState.mode === 'pan') {
      const t0 = e.touches[0], t1 = e.touches[1]
      const mid = getTouchMid(t0, t1)
      const rect = canvasContainer.value.getBoundingClientRect()
      _touchState = {
        mode: 'pinch',
        startDist: getTouchDist(t0, t1),
        startZoom: store.zoom,
        startPanX: store.panX,
        startPanY: store.panY,
        midX: mid.x - rect.left,
        midY: mid.y - rect.top,
      }
    }

    markCanvasAnimating()
    const t0 = e.touches[0], t1 = e.touches[1]
    const dist = getTouchDist(t0, t1)
    const scale = dist / _touchState.startDist
    const newZoom = Math.max(0.005, _touchState.startZoom * scale)

    // Zoom toward pinch midpoint
    const factor = newZoom / _touchState.startZoom
    const rawPX = _touchState.midX - factor * (_touchState.midX - _touchState.startPanX)
    const rawPY = _touchState.midY - factor * (_touchState.midY - _touchState.startPanY)
    store.zoom = newZoom
    const clampedPinch = clampPan(rawPX, rawPY)
    store.panX = clampedPinch.x
    store.panY = clampedPinch.y
  }
}

function onCanvasTouchEnd(e) {
  if (e.touches.length === 0) {
    if (_touchState?.mode === 'pan' || _touchState?.mode === 'pinch') {
      store.saveViewport()
    }
    _touchState = null
  } else if (e.touches.length === 1 && _touchState?.mode === 'pinch') {
    // Went from 2 fingers to 1: restart as pan from current position
    const t = e.touches[0]
    _touchState = {
      mode: 'pan',
      startX: t.clientX,
      startY: t.clientY,
      startPanX: store.panX,
      startPanY: store.panY,
    }
  }
}

// ========================================
// PAN CLAMPING — keep content visible
// ========================================

function getContentBounds() {
  const items = props.board?.items
  if (!items?.length) return null
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const item of items) {
    minX = Math.min(minX, item.pos_x ?? 0)
    minY = Math.min(minY, item.pos_y ?? 0)
    maxX = Math.max(maxX, (item.pos_x ?? 0) + (item.width || 240))
    maxY = Math.max(maxY, (item.pos_y ?? 0) + (item.height || 120))
  }
  return { minX, minY, maxX, maxY }
}

function clampPan(px, py) {
  const rect = canvasContainer.value?.getBoundingClientRect()
  if (!rect) return { x: px, y: py }
  const bounds = getContentBounds()
  if (!bounds) return { x: px, y: py }

  const zoom = store.zoom || 1
  const margin = 200

  const contentLeft = bounds.minX * zoom
  const contentRight = bounds.maxX * zoom
  const contentTop = bounds.minY * zoom
  const contentBottom = bounds.maxY * zoom

  const maxPanX = -contentLeft + rect.width - margin
  const minPanX = -contentRight + margin
  const maxPanY = -contentTop + rect.height - margin
  const minPanY = -contentBottom + margin

  return {
    x: Math.min(maxPanX, Math.max(minPanX, px)),
    y: Math.min(maxPanY, Math.max(minPanY, py)),
  }
}

// ========================================
// MOUSE HANDLING
// ========================================

function onCanvasMouseDown(e) {
  // Disable all canvas interactions during presentation (presenter handles everything)
  if (store.presentationMode) return

  // Claim focus so keyboard shortcuts (Ctrl+Z, Delete, etc.) reach onKeyDown
  // instead of being consumed by a previously focused sidebar input.
  const active = document.activeElement
  if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) {
    active.blur()
  }
  canvasContainer.value?.focus({ preventScroll: true })

  // Close context menus
  closeCanvasContext()
  closeCommentContext()
  
  // Middle button, alt+click, or space+click = pan (allowed in readonly)
  if (e.button === 1 || (e.button === 0 && (e.altKey || spaceHeld.value))) {
    // Cancel follow-user on manual pan
    if (store.followingUser) store.stopFollowing()
    store.isPanning = true
    dragStart.value = { x: e.clientX - store.panX, y: e.clientY - store.panY }
    return
  }
  
  // In readonly mode: allow panning (above) but nothing else
  if (props.readonly && !commentMode.value && !measureMode.value) return

  // Measure tool: click to start measuring
  if (measureMode.value && e.button === 0) {
    const pt = screenToCanvas(e)
    measure.beginMeasure(Math.round(pt.x), Math.round(pt.y))
    return
  }

  // Comment mode: click on canvas to drop a pin
  if (commentMode.value && e.button === 0) {
    const rect = canvasContainer.value.getBoundingClientRect()
    const cx = Math.round((e.clientX - rect.left - store.panX) / store.zoom)
    const cy = Math.round((e.clientY - rect.top - store.panY) / store.zoom)
    const screenX = e.clientX
    const screenY = e.clientY
    emit('comment-canvas', { canvasX: cx, canvasY: cy, screenX, screenY })
    return
  }

  // Line tool interaction
  if (lineMode.value && e.button === 0) {
    const rect = canvasContainer.value.getBoundingClientRect()
    const cx = Math.round((e.clientX - rect.left - store.panX) / store.zoom)
    const cy = Math.round((e.clientY - rect.top - store.panY) / store.zoom)

    // If we already placed the start (click-click mode), this second click commits the line
    if (lineDrawPlaced.value && lineDrawStart.value) {
      lineDrawEnd.value = { x: cx, y: cy }
      commitLine()
      return
    }

    // First click: set start point, enter drag mode
    lineDrawStart.value = { x: cx, y: cy }
    lineDrawEnd.value = { x: cx, y: cy }
    lineDrawDragging.value = true
    lineDrawPlaced.value = false
    return
  }
  
  // Left click on empty canvas
  if (e.button === 0 && (e.target === canvasContainer.value || e.target === canvasLayer.value || e.target.closest('[data-canvas-bg]'))) {
    // If connecting, cancel
    if (store.connectingFrom) {
      store.connectingFrom = null
      tempConnectionEnd.value = null
      return
    }
    
    // Deselect connection
    selectedConnectionId.value = null
    
    // Clear focus mode when clicking on empty canvas
    if (store.focusedItemId) {
      store.clearFocusItem()
      return
    }
    
    // Start selection rectangle
    store.clearSelection()
    const rect = canvasContainer.value.getBoundingClientRect()
    const canvasX = (e.clientX - rect.left - store.panX) / store.zoom
    const canvasY = (e.clientY - rect.top - store.panY) / store.zoom
    selectionStart.value = { x: canvasX, y: canvasY }
  }
}

function onCanvasMouseMove(e) {
  if (store.presentationMode) return
  // Broadcast cursor position to collaborators
  if (props.board?.id && canvasContainer.value) {
    const rect = canvasContainer.value.getBoundingClientRect()
    const cursorX = (e.clientX - rect.left - store.panX) / store.zoom
    const cursorY = (e.clientY - rect.top - store.panY) / store.zoom
    store.sendCursorPosition(props.board.id, cursorX, cursorY)
  }
  
  // Panning — direct, no RAF needed (lightweight)
  if (store.isPanning && dragStart.value) {
    markCanvasAnimating()
    const rawX = e.clientX - dragStart.value.x
    const rawY = e.clientY - dragStart.value.y
    const clamped = clampPan(rawX, rawY)
    store.panX = clamped.x
    store.panY = clamped.y
    return
  }

  // Measure tool drag — update endpoint (Shift constrains to 5-degree angle steps)
  if (measureMode.value && measure.dragging.value) {
    const pt = screenToCanvas(e)
    measure.updateMeasure(Math.round(pt.x), Math.round(pt.y), e.shiftKey)
    return
  }

  // Line tool preview — update endpoint while dragging or in click-click mode
  if (lineMode.value && lineDrawStart.value && (lineDrawDragging.value || lineDrawPlaced.value)) {
    const rect = canvasContainer.value.getBoundingClientRect()
    const cx = (e.clientX - rect.left - store.panX) / store.zoom
    const cy = (e.clientY - rect.top - store.panY) / store.zoom
    // Hold Shift for constrained angles (0, 45, 90)
    if (e.shiftKey) {
      const dx = cx - lineDrawStart.value.x
      const dy = cy - lineDrawStart.value.y
      const angle = Math.atan2(dy, dx)
      const dist = Math.sqrt(dx * dx + dy * dy)
      const snap = Math.round(angle / (Math.PI / 4)) * (Math.PI / 4)
      lineDrawEnd.value = {
        x: Math.round(lineDrawStart.value.x + dist * Math.cos(snap)),
        y: Math.round(lineDrawStart.value.y + dist * Math.sin(snap))
      }
    } else {
      lineDrawEnd.value = { x: Math.round(cx), y: Math.round(cy) }
    }
    return
  }

  // For heavier operations, throttle to one update per animation frame
  if (rafId) return
  rafId = requestAnimationFrame(() => {
    rafId = null
    processMoveFrame(e)
  })
}

function processMoveFrame(e) {
  // Item dragging — DIRECT DOM manipulation (bypasses Vue reactivity entirely)
  // Positions are written back to the reactive store on mouseUp (one batch update).
  if (store.isDragging && _dragState) {
    let dx = (e.clientX - _dragState.startX) / store.zoom
    let dy = (e.clientY - _dragState.startY) / store.zoom

    if (e.shiftKey) {
      if (Math.abs(dx) >= Math.abs(dy)) dy = 0
      else dx = 0
    }

    const entries = _dragState.entries

    let snapDX = 0, snapDY = 0

    // Smart guide snapping — gated by snapCenter toggle
    if (snapCenter.value && _dragState.snapXTargets) {
      // Compute bounding box of all dragged items (raw position before snap)
      let dMinX = Infinity, dMinY = Infinity, dMaxX = -Infinity, dMaxY = -Infinity
      for (const { startX, startY, item } of entries) {
        const nx = startX + dx
        const ny = startY + dy
        const nw = item.width || 240
        const nh = item.height || 120
        dMinX = Math.min(dMinX, nx)
        dMinY = Math.min(dMinY, ny)
        dMaxX = Math.max(dMaxX, nx + nw)
        dMaxY = Math.max(dMaxY, ny + nh)
      }
      const dCX = (dMinX + dMaxX) / 2
      const dCY = (dMinY + dMaxY) / 2

      // Use pre-computed snap targets (not rebuilt every frame)
      const snapXTargets = _dragState.snapXTargets
      const snapYTargets = _dragState.snapYTargets

      // Dragged edges
      const dragEdgesX = [
        { val: dMinX, label: 'left' },
        { val: dMaxX, label: 'right' },
        { val: dCX,   label: 'center' },
      ]
      const dragEdgesY = [
        { val: dMinY, label: 'top' },
        { val: dMaxY, label: 'bottom' },
        { val: dCY,   label: 'center' },
      ]

      const guides = []

      // Find closest X snap (container centers get a wider magnetic zone)
      let bestXDist = CENTER_SNAP_THRESHOLD + 1
      let bestXSnap = null
      let bestXLabel = ''
      let bestContainerXDist = CENTER_SNAP_THRESHOLD + 1
      let bestContainerXSnap = null
      for (const de of dragEdgesX) {
        for (const st of snapXTargets) {
          const isContainerSnap = st.label === 'container-center' || st.label === 'container-third'
          // Container center/third targets only match the dragged item's CENTER edge
          if (isContainerSnap && de.label !== 'center') continue
          const threshold = isContainerSnap ? CENTER_SNAP_THRESHOLD : SNAP_THRESHOLD
          const dist = Math.abs(de.val - st.val)
          if (dist <= threshold && dist < bestXDist) {
            bestXDist = dist
            bestXSnap = { offset: st.val - de.val, val: st.val, container: st.container }
            bestXLabel = st.label
          }
          if (st.label === 'container-center' && de.label === 'center' && dist <= CENTER_SNAP_THRESHOLD && dist < bestContainerXDist) {
            bestContainerXDist = dist
            bestContainerXSnap = { val: st.val, container: st.container }
          }
        }
      }
      if (bestXSnap) {
        snapDX = bestXSnap.offset
        const screenX = bestXSnap.val * store.zoom + store.panX
        const isCenter = bestXLabel === 'container-center'
        const isThird = bestXLabel === 'container-third'
        if (isCenter && bestXSnap.container) {
          const c = bestXSnap.container
          const sy = c.pos_y * store.zoom + store.panY
          const ey = (c.pos_y + (c.height || 540)) * store.zoom + store.panY
          guides.push({ x1: screenX, y1: sy, x2: screenX, y2: ey, type: 'center' })
        } else if (isThird) {
          guides.push({ x1: screenX, y1: 0, x2: screenX, y2: 4000, type: 'third' })
        } else {
          guides.push({ x1: screenX, y1: 0, x2: screenX, y2: 4000, type: 'edge' })
        }
      }
      // Always show container-center guide when near center, even if a closer edge snap won position
      if (bestContainerXSnap && bestXLabel !== 'container-center') {
        const c = bestContainerXSnap.container
        if (c) {
          const screenX = bestContainerXSnap.val * store.zoom + store.panX
          const sy = c.pos_y * store.zoom + store.panY
          const ey = (c.pos_y + (c.height || 540)) * store.zoom + store.panY
          guides.push({ x1: screenX, y1: sy, x2: screenX, y2: ey, type: 'center' })
          // Prefer container-center for position when distance is close enough
          if (bestContainerXDist <= CENTER_SNAP_THRESHOLD) {
            snapDX = bestContainerXSnap.val - dCX
          }
        }
      }

      // Find closest Y snap
      let bestYDist = CENTER_SNAP_THRESHOLD + 1
      let bestYSnap = null
      let bestYLabel = ''
      let bestContainerYDist = CENTER_SNAP_THRESHOLD + 1
      let bestContainerYSnap = null
      for (const de of dragEdgesY) {
        for (const st of snapYTargets) {
          const isContainerSnap = st.label === 'container-center' || st.label === 'container-third'
          // Container center/third targets only match the dragged item's CENTER edge
          if (isContainerSnap && de.label !== 'center') continue
          const threshold = isContainerSnap ? CENTER_SNAP_THRESHOLD : SNAP_THRESHOLD
          const dist = Math.abs(de.val - st.val)
          if (dist <= threshold && dist < bestYDist) {
            bestYDist = dist
            bestYSnap = { offset: st.val - de.val, val: st.val, container: st.container }
            bestYLabel = st.label
          }
          if (st.label === 'container-center' && de.label === 'center' && dist <= CENTER_SNAP_THRESHOLD && dist < bestContainerYDist) {
            bestContainerYDist = dist
            bestContainerYSnap = { val: st.val, container: st.container }
          }
        }
      }
      if (bestYSnap) {
        snapDY = bestYSnap.offset
        const screenY = bestYSnap.val * store.zoom + store.panY
        const isCenter = bestYLabel === 'container-center'
        const isThird = bestYLabel === 'container-third'
        if (isCenter && bestYSnap.container) {
          const c = bestYSnap.container
          const sx = c.pos_x * store.zoom + store.panX
          const ex = (c.pos_x + (c.width || 960)) * store.zoom + store.panX
          guides.push({ x1: sx, y1: screenY, x2: ex, y2: screenY, type: 'center' })
        } else if (isThird) {
          guides.push({ x1: 0, y1: screenY, x2: 4000, y2: screenY, type: 'third' })
        } else {
          guides.push({ x1: 0, y1: screenY, x2: 4000, y2: screenY, type: 'edge' })
        }
      }
      // Always show container-center guide when near center, even if a closer edge snap won position
      if (bestContainerYSnap && bestYLabel !== 'container-center') {
        const c = bestContainerYSnap.container
        if (c) {
          const screenY = bestContainerYSnap.val * store.zoom + store.panY
          const sx = c.pos_x * store.zoom + store.panX
          const ex = (c.pos_x + (c.width || 960)) * store.zoom + store.panX
          guides.push({ x1: sx, y1: screenY, x2: ex, y2: screenY, type: 'center' })
          if (bestContainerYDist <= CENTER_SNAP_THRESHOLD) {
            snapDY = bestContainerYSnap.val - dCY
          }
        }
      }

      smartGuides.value = guides
    } else {
      smartGuides.value = []
    }

    // Distance measurements are always computed (independent of snapCenter toggle)
    // because they show gap info even without snapping.
    // Moved after final positions are applied below.

    // Apply positions — update reactive properties so Vue's itemStyle computed
    // naturally re-renders the transform. Sidebar & layer panel are frozen during drag,
    // undo only records on mouseUp, so the per-frame cost is just itemStyle recompute
    // for dragged items + visibleItems O(n) filter (both trivial).
    const finalDx = dx + snapDX
    const finalDy = dy + snapDY
    for (let i = 0; i < entries.length; i++) {
      const entry = entries[i]
      const newX = Math.round(entry.startX + finalDx)
      const newY = Math.round(entry.startY + finalDy)
      entry.currentX = newX
      entry.currentY = newY
      // Update reactive item properties — Vue handles the DOM via itemStyle computed
      entry.item.pos_x = newX
      entry.item.pos_y = newY
    }

    // Distance measurements to nearest items
    let dmMinX = Infinity, dmMinY = Infinity, dmMaxX = -Infinity, dmMaxY = -Infinity
    for (const { item } of entries) {
      dmMinX = Math.min(dmMinX, item.pos_x)
      dmMinY = Math.min(dmMinY, item.pos_y)
      dmMaxX = Math.max(dmMaxX, item.pos_x + (item.width || 240))
      dmMaxY = Math.max(dmMaxY, item.pos_y + (item.height || 120))
    }
    const others = []
    for (const o of store.currentItems) {
      if (_dragState.draggedIds.has(o.id)) continue
      others.push(o)
    }
    distanceMeasurements.value = computeDistanceGuides(
      { minX: dmMinX, minY: dmMinY, maxX: dmMaxX, maxY: dmMaxY },
      others, store.zoom, store.panX, store.panY
    )

    // Shift bend control points of internal connections in real-time
    if (_dragState.bendConns) {
      for (const bc of _dragState.bendConns) {
        if (bc.startBend1) {
          bc.conn.bend_x = bc.startBend1.x + finalDx
          bc.conn.bend_y = bc.startBend1.y + finalDy
        }
        if (bc.startBend2) {
          bc.conn.bend2_x = bc.startBend2.x + finalDx
          bc.conn.bend2_y = bc.startBend2.y + finalDy
        }
      }
    }
    return
  }

  // Connection drawing
  if (store.connectingFrom) {
    const rect = canvasContainer.value.getBoundingClientRect()
    tempConnectionEnd.value = {
      x: (e.clientX - rect.left - store.panX) / store.zoom,
      y: (e.clientY - rect.top - store.panY) / store.zoom
    }
    return
  }

  // Selection rectangle
  if (selectionStart.value) {
    const rect = canvasContainer.value.getBoundingClientRect()
    const currentX = (e.clientX - rect.left - store.panX) / store.zoom
    const currentY = (e.clientY - rect.top - store.panY) / store.zoom

    const x = Math.min(selectionStart.value.x, currentX)
    const y = Math.min(selectionStart.value.y, currentY)
    const w = Math.abs(currentX - selectionStart.value.x)
    const h = Math.abs(currentY - selectionStart.value.y)

    selectionRect.value = { x, y, w, h }

    // Select items within rectangle (locked items ARE included so the user
    // can inspect properties / unlock them — modification is blocked elsewhere)
    if (w > 5 || h > 5) {
      const newSelection = new Set()
      for (const item of store.currentItems) {
        const ix = item.pos_x
        const iy = item.pos_y
        const iw = item.width || 240
        const ih = item.height || 120

        if (ix + iw > x && ix < x + w && iy + ih > y && iy < y + h) {
          newSelection.add(item.id)
        }
      }
      store.selectedItemIds = newSelection
    }
  }
}

function onCanvasMouseUp(e) {
  // Measure tool: finish measurement
  if (measureMode.value && measure.dragging.value) {
    measure.finishMeasure()
    return
  }

  // Line tool: handle mouse release
  if (lineMode.value && lineDrawDragging.value && lineDrawStart.value) {
    lineDrawDragging.value = false
    const sx = lineDrawStart.value.x, sy = lineDrawStart.value.y
    const ex = lineDrawEnd.value?.x ?? sx, ey = lineDrawEnd.value?.y ?? sy
    const dist = Math.sqrt((ex - sx) ** 2 + (ey - sy) ** 2)

    if (dist >= 8) {
      // Dragged far enough -> commit the line immediately
      commitLine()
    } else {
      // Barely moved -> switch to click-click mode (wait for second click)
      lineDrawPlaced.value = true
    }
    return
  }

  // End panning
  if (store.isPanning) {
    store.isPanning = false
    dragStart.value = null
    store.saveViewport()
    return
  }
  
  // End item dragging — write final positions from _dragState back to reactive store
  if (store.isDragging && _dragState) {
    store.isDragging = false
    store.draggedItemIds = new Set()
    enableIframeAfterDrag()
    smartGuides.value = []
    distanceMeasurements.value = []

    const entries = _dragState.entries
    const draggedItemId = _dragState.draggedItemId

    // Determine whether items were actually moved (> 2px) or this was just a click
    let wasActuallyDragged = false
    for (const entry of entries) {
      if (entry.currentX !== undefined &&
          (Math.abs(entry.currentX - entry.startX) > 2 || Math.abs(entry.currentY - entry.startY) > 2)) {
        wasActuallyDragged = true
        break
      }
    }

    // Click without drag: skip all position processing, just clean up
    if (!wasActuallyDragged) {
      _dragState = null
      return
    }

    // Fill in final positions for entries that didn't move individually
    for (const entry of entries) {
      if (entry.currentX === undefined) {
        entry.currentX = entry.startX
        entry.currentY = entry.startY
      }
    }

    // Snap to grid on release (if enabled)
    if (snapGrid.value) {
      for (const entry of entries) {
        entry.currentX = Math.round(entry.currentX / GRID_SIZE) * GRID_SIZE
        entry.currentY = Math.round(entry.currentY / GRID_SIZE) * GRID_SIZE
      }
    }

    // Write final positions to reactive store (triggers ONE render cycle for all items)
    for (const entry of entries) {
      entry.item.pos_x = entry.currentX
      entry.item.pos_y = entry.currentY
    }

    const updates = entries.map(entry => ({
      id: entry.item.id,
      pos_x: entry.currentX,
      pos_y: entry.currentY
    }))

    // Build the "before" snapshot from the original startX/startY captured at drag start
    const previousPositions = entries.map(entry => ({
      id: entry.item.id,
      pos_x: entry.startX,
      pos_y: entry.startY
    }))

    // Column drop detection: if a single non-column item is dropped on top of a column, parent it.
    // Skip items already inside a frame — those are handled by frame containment below.
    if (entries.length === 1) {
      const draggedItem = entries[0].item
      const existingParent = draggedItem.parent_id ? store.getItemById(draggedItem.parent_id) : null
      const isInFrame = existingParent?.type === 'frame'
      if (draggedItem.type !== 'column' && !isInFrame) {
        const targetColumn = findColumnAtPoint(draggedItem)
        const currentParent = draggedItem.parent_id || null
        const newParent = targetColumn ? targetColumn.id : null

        if (newParent !== currentParent) {
          const upd = updates.find(u => u.id === draggedItem.id)
          if (upd) upd.parent_id = newParent
          draggedItem.parent_id = newParent
        }
      }
    }

    // Frame containment: if items are dropped inside a layout frame, set parent_id.
    // Only re-evaluate frame nesting when there was actual drag movement; a mere
    // click (select) must never change an item's parent.
    if (wasActuallyDragged) {
      const reparentQueue = []

      for (const entry of entries) {
        const item = entry.item
        if (item.type === 'slide' || item.type === 'artboard') continue
        if (entries.length === 1 && item.parent_id) {
          const existingParent = store.getItemById(item.parent_id)
          if (existingParent?.type === 'column') continue
          if (existingParent?.type === 'group') continue
          if (existingParent?.type === 'repeat_grid') continue
        }

        const currentParent = item.parent_id || null
        const currentParentItem = currentParent ? store.getItemById(currentParent) : null
        const isCurrentlyInFrame = currentParentItem?.type === 'frame'
        const isCurrentlyInGroup = currentParentItem?.type === 'group' || currentParentItem?.type === 'repeat_grid'
        if (isCurrentlyInGroup) continue

        const cx = item.pos_x + (item.width || 240) / 2
        const cy = item.pos_y + (item.height || 120) / 2

        // If item is already in a frame, check if it's still within parent bounds.
        // If yes, keep it — no z_index change, no reparent.
        if (isCurrentlyInFrame) {
          const pf = currentParentItem
          const inParent = cx >= pf.pos_x && cx <= pf.pos_x + (pf.width || 400) &&
                           cy >= pf.pos_y && cy <= pf.pos_y + (pf.height || 300)
          if (inParent) continue
        }

        // Find the innermost (smallest area) frame containing the item center
        let foundFrame = null
        let foundArea = Infinity
        for (const fr of store.currentItems) {
          if (fr.type !== 'frame' || fr.id === item.id) continue
          if (entries.some(en => en.item.id === fr.id)) continue
          if (item.type === 'frame') {
            let ancestor = fr
            let isCycle = false
            while (ancestor) {
              if (ancestor.parent_id === item.id) { isCycle = true; break }
              ancestor = ancestor.parent_id ? store.getItemById(ancestor.parent_id) : null
            }
            if (isCycle) continue
          }
          const fw = fr.width || 400
          const fh = fr.height || 300
          if (cx >= fr.pos_x && cx <= fr.pos_x + fw && cy >= fr.pos_y && cy <= fr.pos_y + fh) {
            const area = fw * fh
            if (area < foundArea) {
              foundFrame = fr
              foundArea = area
            }
          }
        }
        const newParentId = foundFrame ? foundFrame.id : (isCurrentlyInFrame ? null : currentParent)
        if (currentParent !== newParentId) {
          reparentQueue.push({ item, newParentId, oldZ: item.z_index || 0 })
        }
      }

      // Sort by original z_index so relative stacking order is preserved
      reparentQueue.sort((a, b) => a.oldZ - b.oldZ)
      for (const rp of reparentQueue) {
        rp.item.parent_id = rp.newParentId
        const newScope = layerScope({ parent_id: rp.newParentId, type: rp.item.type })
        const newZ = nextZIndexInScope(store.currentBoard.items, newScope)
        rp.item.z_index = newZ
        const upd = updates.find(u => u.id === rp.item.id)
        if (upd) { upd.parent_id = rp.newParentId; upd.z_index = newZ }
      }
    }

    // NOTE: Image-to-shape masking (dropping an image onto a shape to clip it) is handled
    // exclusively by external file drops (onDrop / onShapeDrop). Internal canvas drags
    // must NEVER absorb or delete items — moving items around should just move them.

    if (updates.length > 0) {
      store.pushUndo({ type: 'batch-update', previousUpdates: previousPositions, newUpdates: updates.map(u => ({ ...u })) })
      store.batchUpdateItems(updates, { skipUndo: true })
    }

    // Update group bounds: after children move, recalculate parent group bounding boxes
    if (wasActuallyDragged) {
      const groupsToUpdate = new Set()
      for (const entry of entries) {
        const p = entry.item.parent_id ? store.getItemById(entry.item.parent_id) : null
        if ((p?.type === 'group' || p?.type === 'repeat_grid') && !entries.some(e => e.item.id === p.id)) {
          groupsToUpdate.add(p.id)
        }
      }
      for (const gid of groupsToUpdate) {
        const grp = store.getItemById(gid)
        if (!grp) continue
        const children = store.currentItems.filter(i => i.parent_id === gid)
        if (!children.length) continue
        let gMinX = Infinity, gMinY = Infinity, gMaxX = -Infinity, gMaxY = -Infinity
        for (const c of children) {
          gMinX = Math.min(gMinX, c.pos_x || 0)
          gMinY = Math.min(gMinY, c.pos_y || 0)
          gMaxX = Math.max(gMaxX, (c.pos_x || 0) + (c.width || 100))
          gMaxY = Math.max(gMaxY, (c.pos_y || 0) + (c.height || 100))
        }
        store.updateItem(gid, {
          pos_x: Math.round(gMinX),
          pos_y: Math.round(gMinY),
          width: Math.round(gMaxX - gMinX) || 100,
          height: Math.round(gMaxY - gMinY) || 100,
        })
      }
    }

    // Persist shifted bend points to backend (real-time shifting already happened during drag)
    if (wasActuallyDragged && _dragState.bendConns?.length) {
      for (const bc of _dragState.bendConns) {
        const bendUpdate = {}
        if (bc.startBend1) {
          bendUpdate.bend_x = bc.conn.bend_x
          bendUpdate.bend_y = bc.conn.bend_y
        }
        if (bc.startBend2) {
          bendUpdate.bend2_x = bc.conn.bend2_x
          bendUpdate.bend2_y = bc.conn.bend2_y
        }
        store.updateConnection(bc.conn.id, bendUpdate)
      }
    }

    _dragState = null
    return
  }
  
  // End selection
  selectionStart.value = null
  selectionRect.value = null
}

// ========================================
// ITEM INTERACTIONS
// ========================================

function onItemMouseDown(e, item) {
  // Claim focus for keyboard shortcuts (Ctrl+Z, Delete, etc.)
  const active = document.activeElement
  if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) {
    active.blur()
  }
  canvasContainer.value?.focus({ preventScroll: true })

  // Middle mouse button = pan (even on items)
  if (e.button === 1) {
    e.preventDefault()
    if (store.followingUser) store.stopFollowing()
    store.isPanning = true
    dragStart.value = { x: e.clientX - store.panX, y: e.clientY - store.panY }
    return
  }
  if (e.button !== 0) return
  
  // Space held = pan mode — override item drag, start panning instead
  if (spaceHeld.value) {
    if (store.followingUser) store.stopFollowing()
    store.isPanning = true
    dragStart.value = { x: e.clientX - store.panX, y: e.clientY - store.panY }
    return
  }
  
  if (props.readonly) return
  
  // Deselect connection when clicking on an item
  selectedConnectionId.value = null
  
  // Connection mode
  if (store.connectingFrom) {
    if (store.connectingFrom === -1) {
      // First click in connection mode - set the source item
      store.connectingFrom = item.id
      tempConnectionEnd.value = null
    } else {
      // Second click - complete the connection
      onConnectionEnd(item)
    }
    return
  }

  // Locked items: pass through to canvas for marquee selection
  // (shift+click still selects the locked item for inspection/unlock)
  if (isItemLocked(item) && !e.shiftKey) {
    const rect = canvasContainer.value.getBoundingClientRect()
    const canvasX = (e.clientX - rect.left - store.panX) / store.zoom
    const canvasY = (e.clientY - rect.top - store.panY) / store.zoom
    store.clearSelection()
    selectionStart.value = { x: canvasX, y: canvasY }
    return
  }

  // Alt+drag = duplicate selected items and drag the copies
  if (e.altKey && !isItemLocked(item)) {
    if (!store.selectedItemIds.has(item.id)) {
      store.selectItem(item.id, false)
    }
    altDragDuplicate(e, item)
    return
  }
  
  // Select item (shift-click toggles in/out of selection, plain click replaces selection)
  if (e.shiftKey) {
    store.selectItem(item.id, true)
  } else if (!store.selectedItemIds.has(item.id)) {
    store.selectItem(item.id, false)
  }
  
  // Start dragging
  if (!isItemLocked(item)) {
    store.isDragging = true
    disableIframeDuringDrag()

    // Pre-cache direct item references + starting positions (avoids find() per frame)
    const entries = []
    const addedIds = new Set()
    for (const id of store.selectedItemIds) {
      const i = store.currentItems.find(x => x.id === id)
      if (i && !addedIds.has(i.id)) {
        entries.push({ item: i, startX: i.pos_x, startY: i.pos_y })
        addedIds.add(i.id)
      }
    }

    // If any dragged item is a child of a real group/repeat_grid, pull in the
    // container + all siblings so the whole group moves as a unit.
    for (const { item: it } of [...entries]) {
      if (it.parent_id) {
        const parent = store.getItemById(it.parent_id)
        if ((parent?.type === 'group' || parent?.type === 'repeat_grid') && !addedIds.has(parent.id)) {
          entries.push({ item: parent, startX: parent.pos_x, startY: parent.pos_y })
          addedIds.add(parent.id)
          for (const sibling of store.currentItems) {
            if (sibling.parent_id === parent.id && !addedIds.has(sibling.id)) {
              entries.push({ item: sibling, startX: sibling.pos_x, startY: sibling.pos_y })
              addedIds.add(sibling.id)
            }
          }
        }
      }
    }

    // If any dragged item belongs to a legacy group, include all group siblings
    const seenGroupIds = new Set()
    for (const { item: it } of [...entries]) {
      const gid = it.style_data?.group_id
      if (gid && !seenGroupIds.has(gid)) {
        seenGroupIds.add(gid)
        for (const sibling of store.currentItems) {
          if (sibling.style_data?.group_id === gid && !addedIds.has(sibling.id)) {
            entries.push({ item: sibling, startX: sibling.pos_x, startY: sibling.pos_y })
            addedIds.add(sibling.id)
          }
        }
      }
    }

    // If dragging a container (frame, group, etc.), include all child items recursively
    let addedMore = true
    while (addedMore) {
      addedMore = false
      for (const { item: it } of [...entries]) {
        if (it.type === 'frame' || it.type === 'group' || it.type === 'repeat_grid' || it.type === 'slide' || it.type === 'artboard') {
          for (const child of store.currentItems) {
            if (child.parent_id === it.id && !addedIds.has(child.id)) {
              entries.push({ item: child, startX: child.pos_x, startY: child.pos_y })
              addedIds.add(child.id)
              addedMore = true
            }
          }
        }
      }
    }

    // Flush pending debounced updates for ALL items about to be dragged
    store.flushPendingUpdates(addedIds)
    store.draggedItemIds = addedIds

    _dragState = _buildDragState(e, item.id, entries)
  }
}

/**
 * Alt+drag: duplicate the selected items and immediately start dragging the
 * newly created copies so the originals stay in place.
 *
 * pasteItems now selects the optimistic (temp-ID) items synchronously, so we
 * can start dragging them right away without waiting for the server round-trip.
 */
async function altDragDuplicate(e, clickedItem) {
  // Fire duplicate — pasteItems pushes optimistic items & selects them instantly
  const duplicatePromise = store.duplicateSelectedItems(0, 0) // offset 0 — we'll move via drag

  // Wait one tick so Vue renders the new temp items in the DOM
  await nextTick()

  // If no items were selected (shouldn't happen), bail
  if (!store.selectedItemIds.size) return

  // Start dragging the temp items immediately
  store.isDragging = true
  disableIframeDuringDrag()

  const entries = []
  const addedIds = new Set()
  for (const id of store.selectedItemIds) {
    const i = store.currentItems.find(x => x.id === id)
    if (i && !addedIds.has(i.id)) {
      entries.push({ item: i, startX: i.pos_x, startY: i.pos_y })
      addedIds.add(i.id)
    }
  }

  // Include children of containers so the whole group moves as a unit
  let addedMore = true
  while (addedMore) {
    addedMore = false
    for (const { item: it } of [...entries]) {
      if (it.type === 'frame' || it.type === 'group' || it.type === 'repeat_grid' || it.type === 'slide' || it.type === 'artboard') {
        for (const child of store.currentItems) {
          if (child.parent_id === it.id && !addedIds.has(child.id)) {
            entries.push({ item: child, startX: child.pos_x, startY: child.pos_y })
            addedIds.add(child.id)
            addedMore = true
          }
        }
      }
    }
  }

  store.draggedItemIds = addedIds

  const newPrimary = entries[0]?.item
  if (newPrimary) {
    _dragState = _buildDragState(e, newPrimary.id, entries)
  }

  // Let server confirm in background — addItem will swap temp→real IDs
  // in selectedItemIds automatically when each response arrives.
  await duplicatePromise
}

// Disable pointer events on all iframes during drag so they can't steal mouseup
function disableIframeDuringDrag() {
  const el = canvasContainer.value
  if (!el) return
  for (const iframe of el.querySelectorAll('iframe')) {
    iframe.style.pointerEvents = 'none'
  }
}
function enableIframeAfterDrag() {
  const el = canvasContainer.value
  if (!el) return
  for (const iframe of el.querySelectorAll('iframe')) {
    iframe.style.pointerEvents = ''
  }
}

function snapToGrid(val) {
  if (!snapGrid.value) return Math.round(val)
  return Math.round(val / GRID_SIZE) * GRID_SIZE
}

/**
 * Build the non-reactive _dragState object for a drag operation.
 * Pre-computes snap targets from non-dragged items (so we don't iterate
 * all items on every animation frame).
 */
function _buildDragState(e, draggedItemId, entries) {
  const draggedIds = new Set(entries.map(en => en.item.id))

  // Pre-compute snap targets from non-dragged items (these don't move during drag)
  const snapXTargets = []
  const snapYTargets = []
  const isContainer = (t) => t === 'frame' || t === 'slide' || t === 'artboard' || t === 'column'
  for (const other of store.currentItems) {
    if (draggedIds.has(other.id)) continue
    const ox = other.pos_x
    const oy = other.pos_y
    const ow = other.width || 240
    const oh = other.height || 120
    snapXTargets.push({ val: ox, label: 'left' })
    snapXTargets.push({ val: ox + ow, label: 'right' })
    snapXTargets.push({ val: ox + ow / 2, label: 'center' })
    snapYTargets.push({ val: oy, label: 'top' })
    snapYTargets.push({ val: oy + oh, label: 'bottom' })
    snapYTargets.push({ val: oy + oh / 2, label: 'center' })
    // Container center snapping — slides/frames get wider magnetic zone
    if (isContainer(other.type)) {
      snapXTargets.push({ val: ox + ow / 2, label: 'container-center', container: other })
      snapYTargets.push({ val: oy + oh / 2, label: 'container-center', container: other })
      // Third-lines (for rule-of-thirds alignment)
      snapXTargets.push({ val: ox + ow / 3, label: 'container-third' })
      snapXTargets.push({ val: ox + ow * 2 / 3, label: 'container-third' })
      snapYTargets.push({ val: oy + oh / 3, label: 'container-third' })
      snapYTargets.push({ val: oy + oh * 2 / 3, label: 'container-third' })
      const pad = other.style_data?.padding || 0
      if (pad > 0) {
        snapXTargets.push({ val: ox + pad, label: 'frame-pad' })
        snapXTargets.push({ val: ox + ow - pad, label: 'frame-pad' })
        snapYTargets.push({ val: oy + pad, label: 'frame-pad' })
        snapYTargets.push({ val: oy + oh - pad, label: 'frame-pad' })
      }
    }
  }
  // Add draggable guide lines as snap targets
  if (store.showGuides) {
    for (const guide of store.guides) {
      if (guide.axis === 'x') {
        snapXTargets.push({ val: guide.position, label: 'guide' })
      } else {
        snapYTargets.push({ val: guide.position, label: 'guide' })
      }
    }
  }

  // Pre-compute connections whose BOTH endpoints are within the dragged set
  // and have custom bend points — these must shift in real-time during drag.
  const bendConns = []
  if (entries.length > 1) {
    for (const conn of connections.value) {
      if (!draggedIds.has(conn.from_item_id) || !draggedIds.has(conn.to_item_id)) continue
      const hasBend1 = conn.bend_x != null && conn.bend_y != null
      const hasBend2 = conn.bend2_x != null && conn.bend2_y != null
      if (!hasBend1 && !hasBend2) continue
      bendConns.push({
        conn,
        startBend1: hasBend1 ? { x: conn.bend_x, y: conn.bend_y } : null,
        startBend2: hasBend2 ? { x: conn.bend2_x, y: conn.bend2_y } : null,
      })
    }
  }

  return {
    startX: e.clientX,
    startY: e.clientY,
    draggedItemId,
    entries,
    snapXTargets,   // pre-computed, not rebuilt every frame
    snapYTargets,
    draggedIds,
    bendConns,      // connections with bend points to shift during drag
  }
}

/**
 * Find a column that the given item's center overlaps with.
 * Returns the column item or null.
 */
function findColumnAtPoint(item) {
  const cx = item.pos_x + (item.width || 240) / 2
  const cy = item.pos_y + (item.height || 120) / 2
  
  for (const col of store.currentItems) {
    if (col.type !== 'column' || col.id === item.id) continue
    if (isItemLocked(col)) continue // Locked columns cannot accept drops
    const x1 = col.pos_x
    const y1 = col.pos_y
    const x2 = x1 + (col.width || 360)
    const y2 = y1 + (col.height || 400)
    
    if (cx >= x1 && cx <= x2 && cy >= y1 && cy <= y2) {
      return col
    }
  }
  return null
}

/**
 * Find a shape or pen_shape at a raw canvas point (used for external file drops only).
 */
function findShapeAtDropPoint(cx, cy) {
  for (const item of store.currentItems) {
    if (item.type !== 'shape' && item.type !== 'pen_shape' && item.type !== 'text') continue
    const x1 = item.pos_x
    const y1 = item.pos_y
    const x2 = x1 + (item.width || 200)
    const y2 = y1 + (item.height || 200)

    if (cx >= x1 && cx <= x2 && cy >= y1 && cy <= y2) {
      if (isItemLocked(item)) continue
      return item
    }
  }
  return null
}

/**
 * Remove an item from its parent column (set parent_id to null)
 */
function removeFromColumn(child) {
  child.parent_id = null
  store.updateItem(child.id, { parent_id: null })
}

/**
 * Handle mousedown on a child item inside a column — select it and start drag out
 */
function onColumnChildMouseDown(e, child, parentColumn) {
  if (e.button !== 0) return
  
  // Space held = pan mode — override item drag
  if (spaceHeld.value || e.altKey) {
    if (store.followingUser) store.stopFollowing()
    store.isPanning = true
    dragStart.value = { x: e.clientX - store.panX, y: e.clientY - store.panY }
    return
  }
  
  // Connection mode
  if (store.connectingFrom) {
    if (store.connectingFrom === -1) {
      store.connectingFrom = child.id
    } else {
      onConnectionEnd(child)
    }
    return
  }
  
  // Select the child item
  store.selectItem(child.id, e.shiftKey)
  
  // Start dragging: remove from column and let it free-float
  if (!child.locked) {
    // Set initial position relative to canvas (from column position)
    // The child needs absolute canvas coordinates to be dragged
    if (child.parent_id) {
      // Give the child a position based on the column's position + offset
      const offsetX = parentColumn.pos_x + 20
      const offsetY = parentColumn.pos_y + 60
      child.pos_x = offsetX
      child.pos_y = offsetY
      child.parent_id = null
      // Set default dimensions if missing
      if (!child.width) child.width = (parentColumn.width || 360) - 40
      if (!child.height) child.height = 120
    }
    
    store.isDragging = true
    store.draggedItemIds = new Set([child.id])
    disableIframeDuringDrag()
    
    if (store.currentBoard?.items) {
      const rootScope = layerScope({ parent_id: null, type: child.type })
      child.z_index = nextZIndexInScope(store.currentBoard.items, rootScope)
    }
    
    // Column child rip-out: item was just assigned canvas-absolute coords.
    // After Vue re-renders (nextTick), build drag state with DOM refs.
    nextTick(() => {
      _dragState = _buildDragState(e, child.id, [{ item: child, startX: child.pos_x, startY: child.pos_y }])
    })
  }
}

function onDoubleClick(e) {
  if (store.presentationMode || props.readonly) return
  // Double-click on empty canvas = add note
  if (e.target === canvasContainer.value || e.target === canvasLayer.value || e.target.closest('[data-canvas-bg]')) {
    const rect = canvasContainer.value.getBoundingClientRect()
    const x = snapToGrid(Math.round((e.clientX - rect.left - store.panX) / store.zoom) - 120)
    const y = snapToGrid(Math.round((e.clientY - rect.top - store.panY) / store.zoom) - 60)
    
    store.addItem({
      type: 'note',
      pos_x: x,
      pos_y: y,
      width: 240,
      color: '#fef3c7',
      title: '',
      content: ''
    })
  }
}

// ========================================
// CONNECTIONS
// ========================================

function onConnectionStart(item) {
  store.connectingFrom = item.id
  tempConnectionEnd.value = null
}

function onConnectionEnd(item) {
  if (store.connectingFrom && store.connectingFrom !== item.id) {
    store.addConnection({
      from_item_id: store.connectingFrom,
      to_item_id: item.id,
      line_color: 'accent'
    })
  }
  store.connectingFrom = null
  tempConnectionEnd.value = null
}

// ========================================
// CONNECTION ANCHOR DRAGGING
// ========================================

function onSelectConnectionInternal(conn) {
  if (props.readonly) return
  selectedConnectionId.value = conn.id
}

function onAnchorPointerDown(e, conn, endpoint) {
  if (props.readonly) return
  e.stopPropagation()
  e.preventDefault()
  _anchorDragState = { connId: conn.id, endpoint, itemId: endpoint === 'from' ? conn.from_item_id : conn.to_item_id }
  window.addEventListener('pointermove', onAnchorPointerMove)
  window.addEventListener('pointerup', onAnchorPointerUp)
}

function onAnchorPointerMove(e) {
  if (!_anchorDragState || !canvasContainer.value) return
  const rect = canvasContainer.value.getBoundingClientRect()
  // Convert screen coords to canvas coords
  const canvasX = (e.clientX - rect.left - store.panX) / store.zoom
  const canvasY = (e.clientY - rect.top - store.panY) / store.zoom
  const r = getItemRect(_anchorDragState.itemId)
  if (!r) return
  const relX = r.w > 0 ? (canvasX - r.x) / r.w : 0.5
  const relY = r.h > 0 ? (canvasY - r.y) / r.h : 0.5

  // Update the connection object locally for instant visual feedback
  const conn = connections.value.find(c => c.id === _anchorDragState.connId)
  if (!conn) return
  if (_anchorDragState.endpoint === 'from') {
    conn.from_anchor_x = relX
    conn.from_anchor_y = relY
  } else {
    conn.to_anchor_x = relX
    conn.to_anchor_y = relY
  }
}

function onAnchorPointerUp() {
  window.removeEventListener('pointermove', onAnchorPointerMove)
  window.removeEventListener('pointerup', onAnchorPointerUp)
  if (!_anchorDragState) return

  const conn = connections.value.find(c => c.id === _anchorDragState.connId)
  if (conn) {
    // Persist to backend
    store.updateConnection(conn.id, {
      from_anchor_x: conn.from_anchor_x,
      from_anchor_y: conn.from_anchor_y,
      to_anchor_x: conn.to_anchor_x,
      to_anchor_y: conn.to_anchor_y
    })
  }
  _anchorDragState = null
}

function resetConnectionAnchors(conn) {
  conn.from_anchor_x = null
  conn.from_anchor_y = null
  conn.to_anchor_x = null
  conn.to_anchor_y = null
  store.updateConnection(conn.id, {
    from_anchor_x: null,
    from_anchor_y: null,
    to_anchor_x: null,
    to_anchor_y: null
  })
}

// ─── Bend point drag handlers (supports 2 control points) ───
function onBendPointerDown(e, conn, pointIndex) {
  if (props.readonly) return
  e.stopPropagation()
  e.preventDefault()
  _bendDragState = { connId: conn.id, pointIndex }
  window.addEventListener('pointermove', onBendPointerMove)
  window.addEventListener('pointerup', onBendPointerUp)
}

function onBendPointerMove(e) {
  if (!_bendDragState || !canvasContainer.value) return
  const rect = canvasContainer.value.getBoundingClientRect()
  const canvasX = (e.clientX - rect.left - store.panX) / store.zoom
  const canvasY = (e.clientY - rect.top - store.panY) / store.zoom
  const conn = connections.value.find(c => c.id === _bendDragState.connId)
  if (!conn) return
  if (_bendDragState.pointIndex === 1) {
    conn.bend_x = canvasX
    conn.bend_y = canvasY
  } else {
    conn.bend2_x = canvasX
    conn.bend2_y = canvasY
  }
}

function onBendPointerUp() {
  window.removeEventListener('pointermove', onBendPointerMove)
  window.removeEventListener('pointerup', onBendPointerUp)
  if (!_bendDragState) return
  const conn = connections.value.find(c => c.id === _bendDragState.connId)
  if (conn) {
    const update = {}
    if (_bendDragState.pointIndex === 1) {
      update.bend_x = conn.bend_x
      update.bend_y = conn.bend_y
    } else {
      update.bend2_x = conn.bend2_x
      update.bend2_y = conn.bend2_y
    }
    store.updateConnection(conn.id, update)
  }
  _bendDragState = null
}

function resetBendPoint(conn, pointIndex) {
  if (pointIndex === 1) {
    conn.bend_x = null
    conn.bend_y = null
    store.updateConnection(conn.id, { bend_x: null, bend_y: null })
  } else {
    conn.bend2_x = null
    conn.bend2_y = null
    store.updateConnection(conn.id, { bend2_x: null, bend2_y: null })
  }
}

function getItemRect(itemId) {
  const item = store.getItemById(itemId)
  if (!item) return null
  const sd = item.style_data || {}
  const scaleVal = (sd.item_scale != null && sd.item_scale !== 1) ? sd.item_scale : 1
  const rawW = item.width || 240
  const rawH = item.height || 120
  const w = rawW * scaleVal
  const h = rawH * scaleVal
  const x = (item.pos_x || 0) + rawW * (1 - scaleVal) / 2
  const y = (item.pos_y || 0) + rawH * (1 - scaleVal) / 2
  return {
    x,
    y,
    w,
    h,
    cx: x + w / 2,
    cy: y + h / 2
  }
}

/**
 * Get the point on the edge of the item's bounding box that is closest
 * to a target point. This makes connection lines attach to the nearest
 * edge (top/bottom/left/right) instead of always the center.
 */
function getEdgePoint(itemId, targetX, targetY) {
  const r = getItemRect(itemId)
  if (!r) return null
  const dx = targetX - r.cx
  const dy = targetY - r.cy
  if (dx === 0 && dy === 0) return { x: r.cx, y: r.y } // default top

  // Calculate intersection with each edge
  const halfW = r.w / 2
  const halfH = r.h / 2
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)

  // Determine which edge to use based on angle
  if (absDx / halfW > absDy / halfH) {
    // Left or right edge
    const sign = Math.sign(dx)
    const edgeX = r.cx + sign * halfW
    const edgeY = r.cy + dy * (halfW / absDx)
    return { x: edgeX, y: Math.max(r.y, Math.min(r.y + r.h, edgeY)) }
  } else {
    // Top or bottom edge
    const sign = Math.sign(dy)
    const edgeY = r.cy + sign * halfH
    const edgeX = r.cx + dx * (halfH / absDy)
    return { x: Math.max(r.x, Math.min(r.x + r.w, edgeX)), y: edgeY }
  }
}

/**
 * Resolve a connection endpoint to canvas coordinates.
 * If custom anchors (0-1 relative) are set, use them. Otherwise auto edge-snap.
 */
function resolveConnEndpoint(itemId, anchorX, anchorY, fallbackTargetX, fallbackTargetY) {
  if (anchorX != null && anchorY != null) {
    const r = getItemRect(itemId)
    if (!r) return null
    return { x: r.x + anchorX * r.w, y: r.y + anchorY * r.h }
  }
  return getEdgePoint(itemId, fallbackTargetX, fallbackTargetY)
}

/**
 * Build an SVG cubic bezier path between two items.
 * Uses custom anchor positions when set, otherwise auto edge-snap.
 */
function getConnectionPath(conn) {
  const fromRect = getItemRect(conn.from_item_id)
  const toRect = getItemRect(conn.to_item_id)
  if (!fromRect || !toRect) return ''

  const from = resolveConnEndpoint(conn.from_item_id, conn.from_anchor_x, conn.from_anchor_y, toRect.cx, toRect.cy)
  const to = resolveConnEndpoint(conn.to_item_id, conn.to_anchor_x, conn.to_anchor_y, fromRect.cx, fromRect.cy)
  if (!from || !to) return ''

  // SVG is at canvas origin (0,0) — use item coordinates directly
  const x1 = from.x, y1 = from.y
  const x2 = to.x, y2 = to.y

  // Check if user has custom bend control points
  const hasBend1 = conn.bend_x != null && conn.bend_y != null
  const hasBend2 = conn.bend2_x != null && conn.bend2_y != null

  if (hasBend1 || hasBend2) {
    // Cubic bezier with 2 control points — use custom positions or default 1/3 and 2/3
    const cp1x = hasBend1 ? conn.bend_x : (x1 * 2 / 3 + x2 / 3)
    const cp1y = hasBend1 ? conn.bend_y : (y1 * 2 / 3 + y2 / 3)
    const cp2x = hasBend2 ? conn.bend2_x : (x1 / 3 + x2 * 2 / 3)
    const cp2y = hasBend2 ? conn.bend2_y : (y1 / 3 + y2 * 2 / 3)
    return `M ${x1} ${y1} C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${x2} ${y2}`
  }

  // Auto mode: cubic bezier with direction-biased control points
  const dx = x2 - x1
  const dy = y2 - y1
  const dist = Math.sqrt(dx * dx + dy * dy)
  const curvature = Math.min(dist * 0.25, 80)
  // Curve control points: bias toward the dominant direction
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)
  let cx1, cy1, cx2, cy2
  if (absDx >= absDy) {
    // Horizontal bias
    cx1 = x1 + curvature * Math.sign(dx || 1); cy1 = y1
    cx2 = x2 - curvature * Math.sign(dx || 1); cy2 = y2
  } else {
    // Vertical bias
    cx1 = x1; cy1 = y1 + curvature * Math.sign(dy || 1)
    cx2 = x2; cy2 = y2 - curvature * Math.sign(dy || 1)
  }
  return `M ${x1} ${y1} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${x2} ${y2}`
}

function getTempConnectionPath() {
  if (!store.connectingFrom || store.connectingFrom === -1 || !tempConnectionEnd.value) return ''
  const from = getEdgePoint(store.connectingFrom, tempConnectionEnd.value.x, tempConnectionEnd.value.y)
  if (!from) return ''
  const x1 = from.x, y1 = from.y
  const x2 = tempConnectionEnd.value.x, y2 = tempConnectionEnd.value.y
  const dx = x2 - x1
  const dy = y2 - y1
  const dist = Math.sqrt(dx * dx + dy * dy)
  const curvature = Math.min(dist * 0.25, 80)
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)
  let cx1, cy1, cx2, cy2
  if (absDx >= absDy) {
    cx1 = x1 + curvature * Math.sign(dx || 1); cy1 = y1
    cx2 = x2 - curvature * Math.sign(dx || 1); cy2 = y2
  } else {
    cx1 = x1; cy1 = y1 + curvature * Math.sign(dy || 1)
    cx2 = x2; cy2 = y2 - curvature * Math.sign(dy || 1)
  }
  return `M ${x1} ${y1} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${x2} ${y2}`
}

function getConnectionMidpoint(conn) {
  const fromRect = getItemRect(conn.from_item_id)
  const toRect = getItemRect(conn.to_item_id)
  if (!fromRect || !toRect) return null
  const from = resolveConnEndpoint(conn.from_item_id, conn.from_anchor_x, conn.from_anchor_y, toRect.cx, toRect.cy)
  const to = resolveConnEndpoint(conn.to_item_id, conn.to_anchor_x, conn.to_anchor_y, fromRect.cx, fromRect.cy)
  if (!from || !to) return null

  const hasBend1 = conn.bend_x != null && conn.bend_y != null
  const hasBend2 = conn.bend2_x != null && conn.bend2_y != null

  if (hasBend1 || hasBend2) {
    // Cubic bezier at t=0.5: B(0.5) = 0.125*P0 + 0.375*CP1 + 0.375*CP2 + 0.125*P3
    const cp1x = hasBend1 ? conn.bend_x : (from.x * 2 / 3 + to.x / 3)
    const cp1y = hasBend1 ? conn.bend_y : (from.y * 2 / 3 + to.y / 3)
    const cp2x = hasBend2 ? conn.bend2_x : (from.x / 3 + to.x * 2 / 3)
    const cp2y = hasBend2 ? conn.bend2_y : (from.y / 3 + to.y * 2 / 3)
    return {
      x: 0.125 * from.x + 0.375 * cp1x + 0.375 * cp2x + 0.125 * to.x,
      y: 0.125 * from.y + 0.375 * cp1y + 0.375 * cp2y + 0.125 * to.y
    }
  }
  return {
    x: (from.x + to.x) / 2,
    y: (from.y + to.y) / 2
  }
}

function getAnimDuration(conn) {
  const from = getItemRect(conn.from_item_id)
  const to = getItemRect(conn.to_item_id)
  if (!from || !to) return 3
  const dist = Math.sqrt(Math.pow(to.cx - from.cx, 2) + Math.pow(to.cy - from.cy, 2))
  return Math.max(1.5, Math.min(6, dist / 150))
}

function getConnDotCount(conn) {
  const from = getItemRect(conn.from_item_id)
  const to = getItemRect(conn.to_item_id)
  if (!from || !to) return 2
  const dist = Math.sqrt(Math.pow(to.cx - from.cx, 2) + Math.pow(to.cy - from.cy, 2))
  if (dist < 400) return 2
  return 3
}

// Ambient wave helpers -- per-connection randomised timing so waves look organic
function connSeed(conn) {
  let h = 0
  const s = String(conn.id || '')
  for (let i = 0; i < s.length; i++) { h = ((h << 5) - h) + s.charCodeAt(i); h |= 0 }
  return Math.abs(h)
}

// --- Line wave computed values (fed into SVG filter) ---
// Amount = displacement pixels (how far lines bend)
const lineWaveScale = computed(() => Math.round(store.motionLineWave * 4))
// Density = baseFrequency (low=big flowing curtain waves, high=tight ripples)
const lineWaveDensity = computed(() => {
  const d = store.motionLineDensity
  // Map 0.1–1 to baseFrequency "X Y" pair; X=horizontal, Y=vertical
  const bx = (0.003 + d * 0.025).toFixed(4)
  const by = (0.008 + d * 0.05).toFixed(4)
  return `${bx} ${by}`
})
// Animated density variation for organic movement
const lineWaveDensityAnim = computed(() => {
  const d = store.motionLineDensity
  const bx = 0.003 + d * 0.025
  const by = 0.008 + d * 0.05
  const v1 = `${bx.toFixed(4)} ${by.toFixed(4)}`
  const v2 = `${(bx * 1.3).toFixed(4)} ${(by * 1.4).toFixed(4)}`
  const v3 = `${(bx * 0.8).toFixed(4)} ${(by * 0.85).toFixed(4)}`
  return `${v1};${v2};${v3};${v1}`
})
// Speed = animation duration for the turbulence morphing (slower = bigger, flowing motion)
const lineWaveAnimDur = computed(() => {
  // Map 0.1–2 → 30s–3s (inverse: low speed = slow = long dur)
  return Math.max(3, Math.round(20 / Math.max(0.1, store.motionLineSpeed)))
})

// ========================================
// CONTEXT MENU
// ========================================

function onContextMenu(e) {
  if (store.presentationMode || props.readonly) return
  closeCommentContext()
  const rect = canvasContainer.value.getBoundingClientRect()
  canvasContextMenu.value = {
    show: true,
    x: e.clientX,
    y: e.clientY,
    canvasX: Math.round((e.clientX - rect.left - store.panX) / store.zoom),
    canvasY: Math.round((e.clientY - rect.top - store.panY) / store.zoom)
  }
}

function closeCanvasContext() {
  canvasContextMenu.value.show = false
}

function onCommentPinContext(e, thread) {
  e.preventDefault()
  e.stopPropagation()
  commentContextMenu.value = { show: true, x: e.clientX, y: e.clientY, thread }
}

function closeCommentContext() {
  commentContextMenu.value.show = false
}

function ctxDeleteCommentThread() {
  if (commentContextMenu.value.thread) {
    emit('delete-comment-thread', commentContextMenu.value.thread)
  }
  closeCommentContext()
}

function ctxOpenCommentThread() {
  if (commentContextMenu.value.thread) {
    emit('select-comment-thread', commentContextMenu.value.thread)
  }
  closeCommentContext()
}

// ── Paste at viewport center ──
async function pasteAtViewportCenter() {
  if (!canvasContainer.value) return

  // Try loading cross-tab data from system clipboard (async API for click-triggered paste)
  try {
    const text = await navigator.clipboard.readText()
    if (text) store.loadClipboardFromText(text)
  } catch { /* permission denied or unavailable -- use internal clipboard */ }

  if (!store.clipboard.length) return
  const rect = canvasContainer.value.getBoundingClientRect()
  const viewCenterX = Math.round((rect.width / 2 - store.panX) / store.zoom)
  const viewCenterY = Math.round((rect.height / 2 - store.panY) / store.zoom)

  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const item of store.clipboard) {
    const ix = item.pos_x || 0
    const iy = item.pos_y || 0
    minX = Math.min(minX, ix)
    minY = Math.min(minY, iy)
    maxX = Math.max(maxX, ix + (item.width || 240))
    maxY = Math.max(maxY, iy + (item.height || 120))
  }
  const offsetX = viewCenterX - (minX + maxX) / 2
  const offsetY = viewCenterY - (minY + maxY) / 2
  store.pasteItems(offsetX, offsetY)
}

// ── Context menu helpers ──
function ctxBringToFront() {
  for (const id of store.selectedItemIds) {
    store.bringToFront(id)
  }
}
function ctxSendToBack() {
  for (const id of store.selectedItemIds) {
    store.sendToBack(id)
  }
}
function ctxFlipHorizontal() {
  for (const id of store.selectedItemIds) {
    const item = store.currentItems.find(i => i.id === id)
    if (!item) continue
    const sd = item.style_data || {}
    store.updateItem(id, { style_data: { ...sd, flip_x: !sd.flip_x } })
  }
}
function ctxFlipVertical() {
  for (const id of store.selectedItemIds) {
    const item = store.currentItems.find(i => i.id === id)
    if (!item) continue
    const sd = item.style_data || {}
    store.updateItem(id, { style_data: { ...sd, flip_y: !sd.flip_y } })
  }
}

function addItemAtContext(type, extraStyleData) {
  const defaults = {
    note: { width: 240, color: '#fef3c7', title: '', content: '' },
    text: { width: 300, height: 120, title: '', content: '', style_data: { font_family: 'Inter', font_size: 16 } },
    shape: { width: 200, height: 200, style_data: { shape_fill: '#6366f1', shape_border_color: '#4f46e5', shape_border_width: 2, shape_opacity: 100, radius_all: 8, radius_tl: 8, radius_tr: 8, radius_br: 8, radius_bl: 8 } },
    todo_list: { width: 260, title: 'Checklist', todos: [] },
    image: { width: 300, title: '', image_url: '' },
    image_set: { width: 320, height: 240, title: 'Image Set', image_set_items: [] },
    column: { width: 360, height: 400, title: 'New Column' },
    slide: { width: 480, height: 270, title: 'Slide', color: accentColor.value },  // 16:9 presentation camera view
    frame: { width: 1920, height: 1080, title: 'Desktop', style_data: { fill_color: '#ffffff', frame_device: 'Desktop' } },  // Figma-style layout container with device viewport
    table: { width: 400, height: 200, title: 'Table', content: JSON.stringify({ columns: ['Column 1', 'Column 2', 'Column 3'], rows: [['', '', ''], ['', '', '']] }) },
    color_swatch: { width: 100, height: 100, color: '#6366f1', color_data: { hex: '#6366f1', rgb: { r: 99, g: 102, b: 241 }, cmyk: { c: 59, m: 58, y: 0, k: 5 } } },
    calendar_event: { width: 260, title: 'Event', color: '#6366f1' },
    link: { width: 280, title: '', url: '' },
    file: { width: 220, height: 180, title: '' },
    folder: { width: 240, height: 200, title: 'Folder' },
    video: { width: 480, height: 270, title: '', url: '' },
    youtube: { width: 480, height: 270, title: '', url: '' },
    audio: { width: 280, height: 100, title: '', url: '', style_data: { audio_volume: 80, audio_loop: false, audio_autoplay: false, audio_accent: '#6366f1', audio_bg: '#1e1b2e', audio_text: '#e2e8f0' } },
    line: { width: 200, height: 20, style_data: { line_x1: 10, line_y1: 10, line_x2: 190, line_y2: 10, line_color: '#1e293b', line_width: 2, line_dash: 'solid', line_dash_gap: 0, line_arrow_start: false, line_arrow_end: false } },
  }
  
  const itemData = {
    type,
    pos_x: canvasContextMenu.value.canvasX,
    pos_y: canvasContextMenu.value.canvasY,
    ...defaults[type]
  }

  // Merge extra style_data (e.g. shape_type: 'circle' from toolbar dropdown)
  if (extraStyleData && typeof extraStyleData === 'object') {
    itemData.style_data = { ...(itemData.style_data || {}), ...extraStyleData }
  }

  // Slides are presentation camera views — auto-assign next slide_order
  if (type === 'slide') {
    const existingSlides = store.currentItems.filter(i => i.type === 'slide')
    itemData.slide_order = existingSlides.length
  }

  store.addItem(itemData)
  
  closeCanvasContext()
}

// ========================================
// SKETCH IMPORT (parse .sketch file → canvas items)
// ========================================

const sketchImporting = ref(false)
const sketchProgress = ref('')

function triggerSketchImport() {
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = '.sketch'
  input.onchange = async (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    const cx = canvasContextMenu.value.canvasX || 200
    const cy = canvasContextMenu.value.canvasY || 200
    await importSketchFile(file, cx, cy)
  }
  input.click()
}

async function importSketchFile(file, posX, posY) {
  if (sketchImporting.value) return
  sketchImporting.value = true
  sketchProgress.value = 'Parsing .sketch file...'

  try {
    const parsed = await parseSketchFile(file)
    const pageNames = getSketchPageNames(parsed)

    if (pageNames.length === 0) {
      console.warn('[MoodCanvas] No pages found in .sketch file')
      return
    }

    sketchProgress.value = `Found ${pageNames.length} page(s), uploading ${parsed.images.size} images...`

    let imgUploaded = 0
    const imgTotal = parsed.images.size
    async function uploadImageFn(blob, sha) {
      imgUploaded++
      if (imgUploaded % 5 === 0 || imgUploaded === imgTotal) {
        sketchProgress.value = `Uploading images ${imgUploaded}/${imgTotal}...`
      }
      const ext = sha.split('.').pop() || 'png'
      const imageFile = new File([blob], `sketch-${sha}`, { type: `image/${ext}` })
      const uploaded = await store.uploadFiles([imageFile])
      return uploaded?.[0]?.url || null
    }

    sketchProgress.value = `Converting ${pageNames.length} page(s) to items...`

    const items = await sketchToMoodItems(parsed, {
      startX: posX,
      startY: posY,
      uploadImageFn,
      pageIndex: 'all',
      maxItems: Infinity,
      maxDepth: 50,
      minSize: 1,
    })

    const validItems = items.filter(Boolean)

    if (validItems.length > 0) {
      const boardId = store.currentBoard.id
      const tempIdToIndex = new Map()
      const parentPairs = []
      const cleanItems = validItems.map((item, i) => {
        const { _tempId, _tempParentId, ...clean } = item
        if (_tempId) tempIdToIndex.set(_tempId, i)
        if (_tempParentId) parentPairs.push({ itemIndex: i, parentTempId: _tempParentId })
        return clean
      })

      sketchProgress.value = `Preparing ${cleanItems.length} items...`
      await new Promise(r => setTimeout(r, 0))

      // Build ALL optimistic items locally with temp IDs, including parent_id
      // hierarchy already set. ONE reactive write instead of N chunk writes.
      let tempCounter = -Date.now()
      const tempIds = []
      const optimisticItems = cleanItems.map((data, i) => {
        const tempId = --tempCounter
        tempIds.push(tempId)
        return {
          id: tempId,
          board_id: boardId,
          type: data.type || 'text',
          pos_x: data.pos_x || 0,
          pos_y: data.pos_y || 0,
          width: data.width || null,
          height: data.height || null,
          rotation: data.rotation || 0,
          z_index: data.z_index || 0,
          title: data.title || null,
          content: data.content || null,
          color: data.color || null,
          color_data: data.color_data || null,
          url: data.url || null,
          image_url: data.image_url || null,
          thumbnail_url: data.thumbnail_url || null,
          style_data: data.style_data || {},
          locked: 0,
          parent_id: null,
          _tempId: tempId,
        }
      })

      // Pre-set parent_id on optimistic items using temp IDs
      for (const { itemIndex, parentTempId } of parentPairs) {
        const parentIndex = tempIdToIndex.get(parentTempId)
        if (parentIndex != null) {
          optimisticItems[itemIndex].parent_id = tempIds[parentIndex]
        }
      }

      sketchProgress.value = `Rendering ${cleanItems.length} items on canvas...`
      await new Promise(r => setTimeout(r, 0))

      // SINGLE reactive write — adds all items at once, canvas becomes interactive
      store.currentBoard.items = [...store.currentBoard.items, ...optimisticItems]

      // Close the blocking overlay — canvas is usable now
      sketchProgress.value = ''
      sketchImporting.value = false

      // Persist to server entirely in background — user can interact with canvas
      _persistSketchImport(boardId, cleanItems, optimisticItems, tempIds, parentPairs, tempIdToIndex)
    }

    sketchProgress.value = ''
  } catch (err) {
    console.error('[MoodCanvas] Sketch import failed:', err)
    sketchProgress.value = ''
  } finally {
    sketchImporting.value = false
  }
}

// Background server persistence for Sketch imports — runs after canvas is interactive
const sketchSaveProgress = ref('')

async function _persistSketchImport(boardId, cleanItems, optimisticItems, tempIds, parentPairs, tempIdToIndex) {
  const CHUNK = 500
  const PARALLEL = 3
  const allRealIds = new Array(cleanItems.length).fill(null)
  const total = cleanItems.length

  try {
    // Send chunks in parallel batches for speed
    for (let batchStart = 0; batchStart < total; batchStart += CHUNK * PARALLEL) {
      const promises = []
      for (let p = 0; p < PARALLEL; p++) {
        const start = batchStart + p * CHUNK
        if (start >= total) break
        const end = Math.min(start + CHUNK, total)
        const chunk = cleanItems.slice(start, end)
        promises.push(
          api.post(`/mood-boards/${boardId}/items/batch-add`, { items: chunk })
            .then(res => {
              if (res.data.success && res.data.data?.items) {
                const serverItems = res.data.data.items
                for (let j = 0; j < serverItems.length; j++) {
                  allRealIds[start + j] = serverItems[j].id
                }
              }
            })
            .catch(e => console.error(`[SketchImport] Chunk ${start} failed:`, e))
        )
      }
      await Promise.all(promises)
      const saved = Math.min(batchStart + CHUNK * PARALLEL, total)
      sketchSaveProgress.value = `Saving ${saved}/${total}...`
    }

    // Reconcile temp IDs → real IDs in one pass using a Map
    const tempToItem = new Map()
    for (const it of store.currentBoard.items) {
      if (it._tempId != null) tempToItem.set(it.id, it)
    }

    for (let i = 0; i < optimisticItems.length; i++) {
      const realId = allRealIds[i]
      if (!realId) continue
      const item = tempToItem.get(tempIds[i])
      if (item) {
        item.id = realId
        delete item._tempId
      }
    }

    // Remap parent_id from temp to real
    for (const { itemIndex, parentTempId } of parentPairs) {
      const parentIndex = tempIdToIndex.get(parentTempId)
      if (parentIndex == null) continue
      const realParentId = allRealIds[parentIndex]
      const realItemId = allRealIds[itemIndex]
      if (!realParentId || !realItemId) continue
      const item = store.currentBoard.items.find(it => it.id === realItemId)
      if (item) item.parent_id = realParentId
    }

    // Send parent_id updates to server in parallel chunks
    const parentUpdates = []
    for (const { itemIndex, parentTempId } of parentPairs) {
      const parentIndex = tempIdToIndex.get(parentTempId)
      if (parentIndex != null && allRealIds[parentIndex] && allRealIds[itemIndex]) {
        parentUpdates.push({ id: allRealIds[itemIndex], parent_id: allRealIds[parentIndex] })
      }
    }
    for (let batchStart = 0; batchStart < parentUpdates.length; batchStart += CHUNK * PARALLEL) {
      const promises = []
      for (let p = 0; p < PARALLEL; p++) {
        const start = batchStart + p * CHUNK
        if (start >= parentUpdates.length) break
        const end = Math.min(start + CHUNK, parentUpdates.length)
        promises.push(
          store.batchUpdateItems(parentUpdates.slice(start, end), { skipUndo: true })
        )
      }
      await Promise.all(promises)
    }

    sketchSaveProgress.value = ''
    console.log(`[SketchImport] ${total} items persisted to server`)
  } catch (e) {
    console.error('[SketchImport] Background persist failed:', e)
    sketchSaveProgress.value = ''
  }
}

// ========================================
// SVG IMPORT (parse SVG file → drawing item)
// ========================================

async function importSvgFile(file, posX, posY) {
  try {
    const text = await file.text()
    const parser = new DOMParser()
    const doc = parser.parseFromString(text, 'image/svg+xml')
    const svgEl = doc.querySelector('svg')
    if (!svgEl) {
      console.warn('SVG import: no <svg> root element found')
      return
    }

    // Determine dimensions from viewBox or width/height attributes
    let svgW = 400, svgH = 300
    const viewBox = svgEl.getAttribute('viewBox')
    if (viewBox) {
      const parts = viewBox.split(/[\s,]+/).map(Number)
      if (parts.length >= 4) {
        svgW = parts[2] || 400
        svgH = parts[3] || 300
      }
    } else {
      svgW = parseFloat(svgEl.getAttribute('width')) || 400
      svgH = parseFloat(svgEl.getAttribute('height')) || 300
    }

    // Collect all <path> elements (including nested in <g>)
    const pathEls = doc.querySelectorAll('path')
    const strokes = []

    for (const pathEl of pathEls) {
      const d = pathEl.getAttribute('d')
      if (!d) continue

      // Determine color from fill, stroke, or style attribute
      let color = pathEl.getAttribute('fill') || pathEl.getAttribute('stroke') || '#000000'
      const style = pathEl.getAttribute('style') || ''
      const fillMatch = style.match(/fill\s*:\s*([^;]+)/)
      if (fillMatch) color = fillMatch[1].trim()
      if (color === 'none') {
        color = pathEl.getAttribute('stroke') || '#000000'
        const strokeMatch = style.match(/stroke\s*:\s*([^;]+)/)
        if (strokeMatch) color = strokeMatch[1].trim()
      }
      if (color === 'none') color = '#000000'

      strokes.push({
        svgPath: d,
        color: color,
        points: [], // Imported SVG doesn't have point data
        width: parseFloat(pathEl.getAttribute('stroke-width')) || 2
      })
    }

    // Also collect <circle>, <rect>, <ellipse>, <polygon>, <polyline>, <line> as path approximations
    const circles = doc.querySelectorAll('circle')
    for (const el of circles) {
      const cx = parseFloat(el.getAttribute('cx')) || 0
      const cy = parseFloat(el.getAttribute('cy')) || 0
      const r = parseFloat(el.getAttribute('r')) || 10
      const d = `M${cx-r},${cy} A${r},${r} 0 1,0 ${cx+r},${cy} A${r},${r} 0 1,0 ${cx-r},${cy}Z`
      const color = el.getAttribute('fill') || el.getAttribute('stroke') || '#000000'
      strokes.push({ svgPath: d, color: color === 'none' ? '#000000' : color, points: [], width: 2 })
    }

    const rects = doc.querySelectorAll('rect')
    for (const el of rects) {
      const rx = parseFloat(el.getAttribute('x')) || 0
      const ry = parseFloat(el.getAttribute('y')) || 0
      const rw = parseFloat(el.getAttribute('width')) || 0
      const rh = parseFloat(el.getAttribute('height')) || 0
      if (rw && rh) {
        const d = `M${rx},${ry} L${rx+rw},${ry} L${rx+rw},${ry+rh} L${rx},${ry+rh} Z`
        const color = el.getAttribute('fill') || el.getAttribute('stroke') || '#000000'
        strokes.push({ svgPath: d, color: color === 'none' ? '#000000' : color, points: [], width: 2 })
      }
    }

    const ellipses = doc.querySelectorAll('ellipse')
    for (const el of ellipses) {
      const cx = parseFloat(el.getAttribute('cx')) || 0
      const cy = parseFloat(el.getAttribute('cy')) || 0
      const rx = parseFloat(el.getAttribute('rx')) || 10
      const ry = parseFloat(el.getAttribute('ry')) || 10
      const d = `M${cx-rx},${cy} A${rx},${ry} 0 1,0 ${cx+rx},${cy} A${rx},${ry} 0 1,0 ${cx-rx},${cy}Z`
      const color = el.getAttribute('fill') || el.getAttribute('stroke') || '#000000'
      strokes.push({ svgPath: d, color: color === 'none' ? '#000000' : color, points: [], width: 2 })
    }

    const polygons = doc.querySelectorAll('polygon')
    for (const el of polygons) {
      const points = el.getAttribute('points')
      if (points) {
        const pts = points.trim().split(/[\s,]+/).map(Number)
        let d = ''
        for (let i = 0; i < pts.length; i += 2) {
          d += (i === 0 ? 'M' : 'L') + pts[i] + ',' + pts[i+1] + ' '
        }
        d += 'Z'
        const color = el.getAttribute('fill') || el.getAttribute('stroke') || '#000000'
        strokes.push({ svgPath: d, color: color === 'none' ? '#000000' : color, points: [], width: 2 })
      }
    }

    if (strokes.length === 0) {
      // No parseable paths — fall back to uploading as an image
      const uploaded = await store.uploadFiles([file])
      if (uploaded[0]) {
        await store.addItem(buildImageItemData(uploaded[0], {
          posX,
          posY,
          width: Math.min(svgW, 600),
          title: file.name,
        }))
      }
      return
    }

    // Also upload the original SVG as a fallback image
    const uploaded = await store.uploadFiles([file])
    const imageUrl = uploaded?.[0]?.url || null

    const drawingData = JSON.stringify({
      strokes,
      width: svgW,
      height: svgH,
      engine: 'svg-import'
    })

    // Scale to fit ~400px wide while preserving aspect ratio
    const displayW = Math.min(svgW, 600)
    const displayH = Math.round(displayW * (svgH / svgW))

    await store.addItem({
      type: 'drawing',
      pos_x: posX,
      pos_y: posY,
      width: displayW,
      height: displayH,
      title: file.name?.replace(/\.svg$/i, '') || 'SVG Import',
      content: drawingData,
      image_url: imageUrl,
    })
  } catch (err) {
    console.error('SVG import failed:', err)
    // Fallback: upload as regular image
    try {
      const uploaded = await store.uploadFiles([file])
      if (uploaded[0]) {
        await store.addItem(buildImageItemData(uploaded[0], {
          posX,
          posY,
          title: file.name,
        }))
      }
    } catch (e2) {
      console.error('SVG fallback upload failed:', e2)
    }
  }
}

// ========================================
// DROP (images, files from desktop)
// ========================================

async function onDrop(e) {
  if (props.readonly) return
  const rect = canvasContainer.value.getBoundingClientRect()
  const x = Math.round((e.clientX - rect.left - store.panX) / store.zoom)
  const y = Math.round((e.clientY - rect.top - store.panY) / store.zoom)
  
  // Check if the drop lands on a shape — if so, mask the shape with the image
  const shapeTarget = findShapeAtDropPoint(x, y)

  // Handle drag from MoodDriveBrowser (split panel)
  const driveData = e.dataTransfer.getData('application/x-mood-drive-file')
  if (driveData) {
    try {
      const info = JSON.parse(driveData)
      if (info.file_id) {
        if (props.beforeUpload) await props.beforeUpload()
        const isImage = info.mime_type?.startsWith('image/')

        // Import the drive file server-side to get a proper moodboard URL
        const upload = await store.importDriveFile(info.file_id)
        const imageUrl = upload?.url || upload?.image_url || null
        if (!upload || !imageUrl) return

        if (isImage) {
          await store.addItem(buildImageItemData(upload, {
            posX: x,
            posY: y,
            title: info.file_name || 'Drive File',
            driveFileId: info.file_id,
          }))
        } else {
          await store.addItem({
            type: 'file',
            pos_x: x,
            pos_y: y,
            width: 240,
            title: info.file_name || 'Drive File',
            url: imageUrl,
            drive_file_id: info.file_id,
          })
        }
        return
      }
    } catch (err) {
      console.error('[MoodCanvas] Failed to handle drive file drop:', err)
    }
  }

  // Handle dropped files — run client check first
  if (e.dataTransfer.files.length > 0) {
    if (props.beforeUpload) await props.beforeUpload()
    const files = Array.from(e.dataTransfer.files)

    // Intercept .sketch files → import as native canvas items
    const sketchFiles = files.filter(f => f.name?.toLowerCase().endsWith('.sketch'))
    if (sketchFiles.length > 0) {
      for (const sf of sketchFiles) {
        await importSketchFile(sf, x, y)
      }
      const remaining = files.filter(f => !sketchFiles.includes(f))
      if (remaining.length === 0) return
    }

    // Intercept SVG files → import as native drawing items
    const svgFiles = files.filter(f => f.type === 'image/svg+xml' || f.name?.toLowerCase().endsWith('.svg'))
    if (svgFiles.length > 0) {
      for (let i = 0; i < svgFiles.length; i++) {
        await importSvgFile(svgFiles[i], x + i * 30, y + i * 30)
      }
      const remaining = files.filter(f => !svgFiles.includes(f) && !sketchFiles.includes(f))
      if (remaining.length === 0) return
    }

    // Intercept EPS files → upload and add as image items (browsers render via <img>)
    const epsFiles = files.filter(f =>
      f.type === 'application/postscript' || f.name?.toLowerCase().endsWith('.eps') || f.name?.toLowerCase().endsWith('.ai')
    )
    if (epsFiles.length > 0) {
      for (let i = 0; i < epsFiles.length; i++) {
        try {
          const uploaded = await store.uploadFiles([epsFiles[i]])
          if (uploaded[0]) {
            await store.addItem({
              type: 'file',
              pos_x: x + i * 30,
              pos_y: y + i * 30,
              width: 280,
              height: 200,
              title: epsFiles[i].name,
              url: uploaded[0].url,
              image_url: uploaded[0].url,
              style_data: { mime_type: epsFiles[i].type || 'application/postscript', file_size: epsFiles[i].size }
            })
          }
        } catch (err) {
          console.error('EPS upload failed:', err)
        }
      }
      const remaining = files.filter(f => !svgFiles.includes(f) && !epsFiles.includes(f))
      if (remaining.length === 0) return
    }

    const imageFiles = files.filter(f => f.type.startsWith('image/') && !svgFiles.includes(f))

    // Single image dropped onto a shape or text? Use as mask/clip
    if (shapeTarget && !isItemLocked(shapeTarget) && imageFiles.length === 1) {
      try {
        const uploaded = await store.uploadFiles(imageFiles)
        if (uploaded[0]) {
          const existingSD = shapeTarget.style_data || {}
          if (shapeTarget.type === 'text') {
            store.updateItem(shapeTarget.id, {
              style_data: { ...existingSD, text_clip_image: uploaded[0].url, text_clip_image_size: 'cover' }
            })
          } else {
            store.updateItem(shapeTarget.id, {
              style_data: { ...existingSD, mask_image_url: uploaded[0].url, mask_image_fit: 'cover' }
            })
          }
        }
      } catch (err) {
        console.error('Image clip/mask upload failed:', err)
      }
      return
    }
    const videoFiles = files.filter(f => f.type.startsWith('video/'))
    const audioFiles = files.filter(f => f.type.startsWith('audio/'))
    const otherFiles = files.filter(f => !f.type.startsWith('image/') && !f.type.startsWith('video/') && !f.type.startsWith('audio/') && !svgFiles.includes(f) && !epsFiles.includes(f))
    
    // Multiple images → create an image_set automatically
    if (imageFiles.length > 1) {
      try {
        // Upload all images
        const uploaded = await store.uploadFiles(imageFiles)
        // Create image_set item with all uploaded images
        const imageSetItems = uploaded.map((u, i) => ({
          image_url: u.url,
          thumbnail_url: u.thumbnail_url || u.url,
          original_filename: imageFiles[i]?.name || u.original_filename || null,
          width_px: u.width_px || null,
          height_px: u.height_px || null,
          position: i
        }))
        await store.addItem({
          type: 'image_set',
          pos_x: x,
          pos_y: y,
          width: 320,
          height: 240,
          title: `Image Set (${imageFiles.length})`,
          image_set_items: imageSetItems
        })
      } catch (err) {
        console.error('Image set upload failed:', err)
      }
    } else if (imageFiles.length === 1) {
      // Single image → upload and add as image
      try {
        const uploaded = await store.uploadFiles(imageFiles)
        if (uploaded[0]) {
          await store.addItem(buildImageItemData(uploaded[0], {
            posX: x,
            posY: y,
            title: imageFiles[0].name,
          }))
        }
      } catch (err) {
        console.error('Image upload failed:', err)
      }
    }
    
    // Video files → upload and add as video items
    for (let i = 0; i < videoFiles.length; i++) {
      try {
        const uploaded = await store.uploadFiles([videoFiles[i]])
        if (uploaded[0]) {
          await store.addItem({
            type: 'video',
            pos_x: x + i * 30,
            pos_y: y + i * 30,
            width: 480,
            height: 270,
            title: videoFiles[i].name,
            url: uploaded[0].url,
            image_url: uploaded[0].url
          })
        }
      } catch (err) {
        console.error('Video upload failed:', err)
      }
    }

    // Audio files → upload and add as audio items
    for (let i = 0; i < audioFiles.length; i++) {
      try {
        const uploaded = await store.uploadFiles([audioFiles[i]])
        if (uploaded[0]) {
          await store.addItem({
            type: 'audio',
            pos_x: x + i * 30,
            pos_y: y + i * 30,
            width: 280,
            height: 100,
            title: audioFiles[i].name,
            url: uploaded[0].url,
            style_data: { audio_volume: 80, audio_loop: false, audio_autoplay: false, audio_accent: '#6366f1', audio_bg: '#1e1b2e', audio_text: '#e2e8f0' }
          })
        }
      } catch (err) {
        console.error('Audio upload failed:', err)
      }
    }

    // Non-image files → upload and add as file items
    for (let i = 0; i < otherFiles.length; i++) {
      try {
        const uploaded = await store.uploadFiles([otherFiles[i]])
        if (uploaded[0]) {
          await store.addItem({
            type: 'file',
            pos_x: x + i * 30,
            pos_y: y + i * 30,
            width: 240,
            height: 180,
            title: otherFiles[i].name,
            url: uploaded[0].url,
            style_data: {
              mime_type: otherFiles[i].type || null,
              file_size: otherFiles[i].size || null
            }
          })
        }
      } catch (err) {
        console.error('File upload failed:', err)
      }
    }
    return
  }
  
  // Handle dropped text/URL
  const text = e.dataTransfer.getData('text/plain')
  if (text && text.startsWith('http')) {
    // Detect YouTube URLs
    const isYouTube = /(?:youtube\.com\/(?:watch|embed|shorts)|youtu\.be\/)/.test(text)
    if (isYouTube) {
      store.addItem({
        type: 'youtube',
        pos_x: x,
        pos_y: y,
        width: 480,
        height: 270,
        url: text,
        title: ''
      })
    } else {
      store.addItem({
        type: 'link',
        pos_x: x,
        pos_y: y,
        width: 280,
        url: text,
        title: text
      })
    }
  }
}

// Upload files triggered from toolbar
async function handleFileUpload(files) {
  if (!files?.length || !canvasContainer.value) return
  
  const rect = canvasContainer.value.getBoundingClientRect()
  const x = Math.round((rect.width / 2 - store.panX) / store.zoom)
  const y = Math.round((rect.height / 2 - store.panY) / store.zoom)
  
  const allFiles = Array.from(files)

  // Intercept SVG files → import as native drawing items
  const svgFiles = allFiles.filter(f => f.type === 'image/svg+xml' || f.name?.toLowerCase().endsWith('.svg'))
  for (let i = 0; i < svgFiles.length; i++) {
    await importSvgFile(svgFiles[i], x + i * 30, y + i * 30)
  }

  // Intercept EPS files → upload as file items
  const epsFiles = allFiles.filter(f =>
    f.type === 'application/postscript' || f.name?.toLowerCase().endsWith('.eps') || f.name?.toLowerCase().endsWith('.ai')
  )
  for (let i = 0; i < epsFiles.length; i++) {
    try {
      const uploaded = await store.uploadFiles([epsFiles[i]])
      if (uploaded[0]) {
        await store.addItem({
          type: 'file',
          pos_x: x + i * 30,
          pos_y: y + i * 30,
          width: 280,
          height: 200,
          title: epsFiles[i].name,
          url: uploaded[0].url,
          image_url: uploaded[0].url,
          style_data: { mime_type: epsFiles[i].type || 'application/postscript', file_size: epsFiles[i].size }
        })
      }
    } catch (err) {
      console.error('EPS upload failed:', err)
    }
  }

  const imageFiles = allFiles.filter(f => f.type.startsWith('image/') && !svgFiles.includes(f))
  const videoFiles = allFiles.filter(f => f.type.startsWith('video/'))
  const audioFiles = allFiles.filter(f => f.type.startsWith('audio/'))
  const otherFiles = allFiles.filter(f => !f.type.startsWith('image/') && !f.type.startsWith('video/') && !f.type.startsWith('audio/') && !svgFiles.includes(f) && !epsFiles.includes(f))
  
  if (imageFiles.length > 1) {
    try {
      const uploaded = await store.uploadFiles(imageFiles)
      const imageSetItems = uploaded.map((u, i) => ({
        image_url: u.url,
        thumbnail_url: u.thumbnail_url || u.url,
        position: i
      }))
      await store.addItem({
        type: 'image_set',
        pos_x: x,
        pos_y: y,
        width: 320,
        height: 240,
        title: `Image Set (${imageFiles.length})`,
        image_set_items: imageSetItems
      })
    } catch (err) {
      console.error('Image set upload failed:', err)
    }
  } else if (imageFiles.length === 1) {
    try {
      const uploaded = await store.uploadFiles(imageFiles)
      if (uploaded[0]) {
        await store.addItem(buildImageItemData(uploaded[0], {
          posX: x,
          posY: y,
          title: imageFiles[0].name,
        }))
      }
    } catch (err) {
      console.error('Image upload failed:', err)
    }
  }
  
  // Video files → add as video items
  for (let i = 0; i < videoFiles.length; i++) {
    try {
      const uploaded = await store.uploadFiles([videoFiles[i]])
      if (uploaded[0]) {
        await store.addItem({
          type: 'video',
          pos_x: x + i * 30,
          pos_y: y + i * 30,
          width: 480,
          height: 270,
          title: videoFiles[i].name,
          url: uploaded[0].url,
          image_url: uploaded[0].url
        })
      }
    } catch (err) {
      console.error('Video upload failed:', err)
    }
  }

  // Audio files → add as audio items
  for (let i = 0; i < audioFiles.length; i++) {
    try {
      const uploaded = await store.uploadFiles([audioFiles[i]])
      if (uploaded[0]) {
        await store.addItem({
          type: 'audio',
          pos_x: x + i * 30,
          pos_y: y + i * 30,
          width: 280,
          height: 100,
          title: audioFiles[i].name,
          url: uploaded[0].url,
          style_data: { audio_volume: 80, audio_loop: false, audio_autoplay: false, audio_accent: '#6366f1', audio_bg: '#1e1b2e', audio_text: '#e2e8f0' }
        })
      }
    } catch (err) {
      console.error('Audio upload failed:', err)
    }
  }

  for (let i = 0; i < otherFiles.length; i++) {
    try {
      const uploaded = await store.uploadFiles([otherFiles[i]])
      if (uploaded[0]) {
        await store.addItem({
          type: 'file',
          pos_x: x + i * 30,
          pos_y: y + i * 30,
          width: 220,
          height: 180,
          title: otherFiles[i].name,
          url: uploaded[0].url,
          style_data: {
            mime_type: otherFiles[i].type || null,
            file_size: otherFiles[i].size || null
          }
        })
      }
    } catch (err) {
      console.error('File upload failed:', err)
    }
  }
}

// Add Drive file/doc to canvas at center
async function addDriveItem(file) {
  if (!canvasContainer.value) return
  const rect = canvasContainer.value.getBoundingClientRect()
  const x = Math.round((rect.width / 2 - store.panX) / store.zoom)
  const y = Math.round((rect.height / 2 - store.panY) / store.zoom)
  
  const fileName = file.original_name || file.name || file.filename || 'Unnamed file'
  const isImage = file.mime_type?.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)$/i.test(fileName || '')
  
  // Import drive file to mood board storage (copies file server-side, returns public URL)
  const upload = await store.importDriveFile(file.id)
  if (!upload) {
    console.error('Failed to import drive file:', file.id)
    return
  }
  
  if (isImage) {
    store.addItem(buildImageItemData(upload, {
      posX: x,
      posY: y,
      title: fileName,
      driveFileId: file.id,
    }))
    return
  }

  store.addItem({
    type: 'file',
    pos_x: x,
    pos_y: y,
    width: 240,
    height: 180,
    title: fileName,
    url: upload.url,
    drive_file_id: file.id,
    style_data: {
      mime_type: file.mime_type || null,
      file_size: file.size || null
    }
  })
}

const DEFAULT_IMAGE_ITEM_WIDTH = 300

function getUploadImageDimensions(upload) {
  const width = Number(upload?.width_px ?? upload?.width ?? upload?.original_width ?? 0)
  const height = Number(upload?.height_px ?? upload?.height ?? upload?.original_height ?? 0)
  if (!(width > 0) || !(height > 0)) return null
  return { width, height }
}

function buildImageStyleDataFromUpload(upload, existingStyleData = {}) {
  const dims = getUploadImageDimensions(upload)
  const base = { ...(existingStyleData || {}) }
  if (!dims) {
    return Object.keys(base).length ? base : undefined
  }
  return {
    ...base,
    original_width: dims.width,
    original_height: dims.height,
  }
}

function buildImageItemData(upload, { posX, posY, title, driveFileId = null, width = DEFAULT_IMAGE_ITEM_WIDTH } = {}) {
  const dims = getUploadImageDimensions(upload)
  const displayWidth = Math.max(1, Math.round(width || DEFAULT_IMAGE_ITEM_WIDTH))
  const imageUrl = upload?.url || upload?.image_url || null
  const thumbnailUrl = upload?.thumbnail_url || imageUrl
  const MIN_IMAGE_HEIGHT = 40
  const DEFAULT_ASPECT = 3 / 4
  let height
  if (dims) {
    height = Math.max(MIN_IMAGE_HEIGHT, Math.round(displayWidth * (dims.height / dims.width)))
  } else {
    height = Math.max(MIN_IMAGE_HEIGHT, Math.round(displayWidth * DEFAULT_ASPECT))
  }
  return {
    type: 'image',
    pos_x: posX,
    pos_y: posY,
    width: displayWidth,
    height,
    image_url: imageUrl,
    thumbnail_url: thumbnailUrl,
    title: title || upload?.original_filename || 'Image',
    drive_file_id: driveFileId,
    style_data: buildImageStyleDataFromUpload(upload),
  }
}

function buildImageReplacementData(item, upload, fallbackTitle) {
  return {
    image_url: upload?.url || upload?.image_url || null,
    thumbnail_url: upload?.thumbnail_url || upload?.url || upload?.image_url || null,
    title: item.title || fallbackTitle,
    style_data: buildImageStyleDataFromUpload(upload, item.style_data || {}),
  }
}

// Add Drive folder to canvas at center
function addDriveFolder(folder) {
  if (!canvasContainer.value) return
  const rect = canvasContainer.value.getBoundingClientRect()
  const x = Math.round((rect.width / 2 - store.panX) / store.zoom)
  const y = Math.round((rect.height / 2 - store.panY) / store.zoom)
  
  store.addItem({
    type: 'folder',
    pos_x: x - 120,
    pos_y: y - 100,
    width: 240,
    height: 200,
    title: folder.name,
    style_data: {
      drive_folder_id: folder.id,
      path: folder.path || folder.name
    }
  })
}

// Add Calendar event to canvas at center
function addCalendarEvent(event) {
  if (!canvasContainer.value) return
  const rect = canvasContainer.value.getBoundingClientRect()
  const x = Math.round((rect.width / 2 - store.panX) / store.zoom)
  const y = Math.round((rect.height / 2 - store.panY) / store.zoom)
  
  store.addItem({
    type: 'calendar_event',
    pos_x: x,
    pos_y: y,
    width: 260,
    title: event.title || event.subject,
    content: event.description || '',
    color: event.color || '#6366f1',
    calendar_event_id: event.id
  })
}

function addBoardItem(data) {
  if (!canvasContainer.value) return
  const rect = canvasContainer.value.getBoundingClientRect()
  const x = Math.round((rect.width / 2 - store.panX) / store.zoom)
  const y = Math.round((rect.height / 2 - store.panY) / store.zoom)
  
  // Determine size based on whether it's a board or card
  const isCard = !!data.linked_card_id
  const width = isCard ? 300 : 360
  const height = isCard ? 320 : 280
  
  // Always store structured JSON in content with board_data or card_data
  const contentPayload = {}
  if (data.card_data) {
    contentPayload.card_data = data.card_data
  }
  if (data.board_data) {
    contentPayload.board_data = data.board_data
  }
  
  store.addItem({
    type: 'board_link',
    pos_x: x - width / 2,
    pos_y: y - height / 2,
    width,
    height,
    title: data.title,
    content: JSON.stringify(contentPayload),
    color: data.color,
    linked_board_id: data.linked_board_id,
    linked_card_id: data.linked_card_id || null
  })
}

// ========================================
// PUBLIC API (for parent)
// ========================================

function addItemFromToolbar(type, extraStyleData) {
  // Add at center of current viewport
  const rect = canvasContainer.value?.getBoundingClientRect()
  if (!rect) return
  
  const centerX = Math.round((rect.width / 2 - store.panX) / store.zoom)
  const centerY = Math.round((rect.height / 2 - store.panY) / store.zoom)
  
  canvasContextMenu.value.canvasX = centerX
  canvasContextMenu.value.canvasY = centerY
  addItemAtContext(type, extraStyleData)
}

function zoomIn() {
  markCanvasAnimating()
  store.zoom = store.zoom + Math.max(0.01, store.zoom * 0.15)
}

function zoomOut() {
  markCanvasAnimating()
  store.zoom = Math.max(0.005, store.zoom - Math.max(0.01, store.zoom * 0.15))
}

function zoomReset() {
  markCanvasAnimating()
  store.zoom = 1
  store.panX = 0
  store.panY = 0
}

function fitScreen() {
  if (!props.board?.items?.length || !canvasContainer.value) return
  markCanvasAnimating()
  
  // Find bounding box of all items
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const item of props.board.items) {
    minX = Math.min(minX, item.pos_x)
    minY = Math.min(minY, item.pos_y)
    maxX = Math.max(maxX, item.pos_x + (item.width || 240))
    maxY = Math.max(maxY, item.pos_y + (item.height || 120))
  }
  
  const rect = canvasContainer.value.getBoundingClientRect()
  const padding = 80
  const contentW = maxX - minX + padding * 2
  const contentH = maxY - minY + padding * 2
  
  const scaleX = rect.width / contentW
  const scaleY = rect.height / contentH
  store.zoom = Math.max(0.005, Math.min(scaleX, scaleY))
  store.panX = (rect.width - contentW * store.zoom) / 2 - minX * store.zoom + padding * store.zoom
  store.panY = (rect.height - contentH * store.zoom) / 2 - minY * store.zoom + padding * store.zoom
}

// ========================================
// ANIMATE TO FRAME (presentation engine)
// ========================================

let animationRafId = null

/**
 * Smoothly animate the viewport to perfectly frame the given item.
 * Used by presentation mode and filmstrip navigation.
 * @param {Object} frameItem - { pos_x, pos_y, width, height }
 * @param {number} duration - animation duration in ms (default 600)
 * @param {string} transition - 'fly' | 'fade' | 'instant'
 * @param {number} padding - viewport padding in px (default 40, use 0 for fullscreen presenting)
 * @param {{ width: number, height: number }} viewportOverride - explicit viewport size (for fullscreen presenter)
 * @returns {Promise} resolves when animation completes
 */
function animateToFrame(frameItem, duration = 600, transition = 'fly', padding = 40, viewportOverride = null) {
  return new Promise((resolve) => {
    if (!frameItem || !canvasContainer.value) { resolve(); return }
    
    const rect = viewportOverride
      ? { width: viewportOverride.width, height: viewportOverride.height }
      : canvasContainer.value.getBoundingClientRect()
    
    const frameW = frameItem.width || 240
    const frameH = frameItem.height || 135
    
    const scaleX = (rect.width - padding * 2) / frameW
    const scaleY = (rect.height - padding * 2) / frameH
    const targetZoom = Math.max(0.005, Math.min(scaleX, scaleY))
    
    // Target center in world (canvas) coordinates
    const targetCX = frameItem.pos_x + frameW / 2
    const targetCY = frameItem.pos_y + frameH / 2
    
    const targetPanX = (rect.width / 2) - targetCX * targetZoom
    const targetPanY = (rect.height / 2) - targetCY * targetZoom
    
    if (transition === 'instant') {
      store.zoom = targetZoom
      store.panX = targetPanX
      store.panY = targetPanY
      markCanvasAnimating()
      resolve()
      return
    }
    
    if (animationRafId) cancelAnimationFrame(animationRafId)
    
    canvasAnimating.value = true
    clearTimeout(_animSettleTimer)
    
    // Convert current screen-space pan to world-space center so we can
    // interpolate the camera in world coordinates. This prevents the
    // "pan first, zoom jumps later" artefact that happens when
    // interpolating screen-space panX/panY and zoom independently.
    const startZoom = store.zoom
    const startCX = (rect.width / 2 - store.panX) / startZoom
    const startCY = (rect.height / 2 - store.panY) / startZoom
    
    // Use logarithmic zoom interpolation for perceptually uniform speed
    const startLogZ = Math.log(startZoom)
    const targetLogZ = Math.log(targetZoom)
    
    // Adapt duration to camera travel distance so large zoom changes
    // don't feel like a jump. zoomRatio measures how many "doublings"
    // the zoom traverses; panDist is screen-normalized center travel.
    const zoomRatio = Math.abs(targetLogZ - startLogZ) / Math.LN2 // in octaves
    const worldDist = Math.sqrt((targetCX - startCX) ** 2 + (targetCY - startCY) ** 2)
    const avgZoom = (startZoom + targetZoom) / 2
    const screenDist = (worldDist * avgZoom) / Math.max(rect.width, rect.height)
    const travelFactor = Math.max(1, zoomRatio * 0.8, screenDist * 0.5)
    const effectiveDuration = Math.round(Math.min(duration * travelFactor, 2500))
    
    const startTime = performance.now()
    
    function easeInOutCubic(t) {
      return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2
    }
    
    function step(now) {
      const elapsed = now - startTime
      const progress = Math.min(1, elapsed / effectiveDuration)
      const eased = easeInOutCubic(progress)
      
      // Interpolate world-space center + logarithmic zoom
      const cx = startCX + (targetCX - startCX) * eased
      const cy = startCY + (targetCY - startCY) * eased
      const z = Math.exp(startLogZ + (targetLogZ - startLogZ) * eased)
      
      // Derive screen-space pan from world center + zoom
      store.zoom = z
      store.panX = (rect.width / 2) - cx * z
      store.panY = (rect.height / 2) - cy * z
      
      if (progress < 1) {
        animationRafId = requestAnimationFrame(step)
      } else {
        animationRafId = null
        _animSettleTimer = setTimeout(() => { canvasAnimating.value = false }, 80)
        resolve()
      }
    }
    
    animationRafId = requestAnimationFrame(step)
  })
}

/**
 * Smoothly pan to center on a canvas coordinate (for follow-user)
 */
let followRafId = null
function panToCanvasPoint(cx, cy, duration = 200) {
  if (!canvasContainer.value) return
  const rect = canvasContainer.value.getBoundingClientRect()
  
  const targetPanX = (rect.width / 2) - cx * store.zoom
  const targetPanY = (rect.height / 2) - cy * store.zoom
  
  if (followRafId) cancelAnimationFrame(followRafId)
  
  // GPU-accelerate during follow-pan
  canvasAnimating.value = true
  clearTimeout(_animSettleTimer)
  
  const startPanX = store.panX
  const startPanY = store.panY
  const startTime = performance.now()
  
  function step(now) {
    const elapsed = now - startTime
    const progress = Math.min(1, elapsed / duration)
    const eased = progress < 0.5 ? 2 * progress * progress : 1 - Math.pow(-2 * progress + 2, 2) / 2
    
    store.panX = startPanX + (targetPanX - startPanX) * eased
    store.panY = startPanY + (targetPanY - startPanY) * eased
    
    if (progress < 1) {
      followRafId = requestAnimationFrame(step)
    } else {
      followRafId = null
      _animSettleTimer = setTimeout(() => { canvasAnimating.value = false }, 80)
    }
  }
  
  followRafId = requestAnimationFrame(step)
}

// Watch for followed user's cursor/viewport updates
watch(() => {
  if (!store.followingUser) return null
  const collab = store.collaborators.find(c => c.email === store.followingUser)
  if (!collab) return null

  if (store.followMode === 'viewport' && collab.view_panX != null) {
    return { mode: 'viewport', panX: collab.view_panX, panY: collab.view_panY, zoom: collab.view_zoom }
  }
  if (collab.cursor_x == null) return null
  return { mode: 'cursor', x: collab.cursor_x, y: collab.cursor_y }
}, (data) => {
  if (!data) return
  if (data.mode === 'viewport') {
    // Smoothly transition to the followed user's exact viewport
    if (followRafId) cancelAnimationFrame(followRafId)
    canvasAnimating.value = true
    clearTimeout(_animSettleTimer)
    const startPanX = store.panX, startPanY = store.panY, startZoom = store.zoom
    const targetPanX = data.panX, targetPanY = data.panY, targetZoom = data.zoom
    const duration = 200, startTime = performance.now()
    function step(now) {
      const elapsed = now - startTime
      const progress = Math.min(1, elapsed / duration)
      const eased = progress < 0.5 ? 2 * progress * progress : 1 - Math.pow(-2 * progress + 2, 2) / 2
      store.panX = startPanX + (targetPanX - startPanX) * eased
      store.panY = startPanY + (targetPanY - startPanY) * eased
      store.zoom = startZoom + (targetZoom - startZoom) * eased
      if (progress < 1) followRafId = requestAnimationFrame(step)
      else { followRafId = null; _animSettleTimer = setTimeout(() => { canvasAnimating.value = false }, 80) }
    }
    followRafId = requestAnimationFrame(step)
  } else {
    panToCanvasPoint(data.x, data.y, 150)
  }
}, { deep: true })

// ========================================
// IMAGE SET HANDLERS
// ========================================

async function onReplaceImage(item) {
  if (isItemLocked(item)) return // Locked items cannot be modified
  // Trigger a file input for setting / replacing an image item
  const input = document.createElement('input')
  input.type = 'file'
  input.multiple = false
  input.accept = 'image/*'
  input.onchange = async (e) => {
    const files = Array.from(e.target.files)
    if (!files.length) return
    try {
      const uploaded = await store.uploadFiles(files)
      if (uploaded[0]) {
        await store.updateItem(item.id, buildImageReplacementData(item, uploaded[0], files[0].name))
      }
    } catch (err) {
      console.error('Failed to set image:', err)
    }
  }
  input.click()
}

async function onAddImagesToSet(item) {
  // Trigger a file input for adding images to an existing set
  const input = document.createElement('input')
  input.type = 'file'
  input.multiple = true
  input.accept = 'image/*'
  input.onchange = async (e) => {
    const files = Array.from(e.target.files)
    if (!files.length) return
    try {
      const uploaded = await store.uploadFiles(files)
      // ONE HTTP call instead of N.
      const payload = uploaded.map(u => ({
        image_url: u.url,
        thumbnail_url: u.thumbnail_url || u.url,
        original_filename: u.original_filename || null,
        width_px: u.width_px || null,
        height_px: u.height_px || null,
      }))
      await store.addImagesToSetBatch(item.id, payload)
      // Refresh the board to get updated image_set_items
      await store.fetchBoard(store.currentBoard.id)
    } catch (err) {
      console.error('Failed to add images to set:', err)
    }
  }
  input.click()
}

async function onDropFilesToSet(item, files) {
  const imageFiles = Array.from(files).filter(f => f.type.startsWith('image/'))
  if (!imageFiles.length) return
  try {
    const uploaded = await store.uploadFiles(imageFiles)
    const payload = uploaded.map(u => ({
      image_url: u.url,
      thumbnail_url: u.thumbnail_url || u.url,
      original_filename: u.original_filename || null,
      width_px: u.width_px || null,
      height_px: u.height_px || null,
    }))
    await store.addImagesToSetBatch(item.id, payload)
    await store.fetchBoard(store.currentBoard.id)
  } catch (err) {
    console.error('Failed to drop files to set:', err)
  }
}

async function onRemoveImageFromSet(imageId) {
  try {
    // We need the parent item ID; find it from current items
    // Backend returns images as "images", frontend may also use "image_set_items"
    const parentItem = store.currentItems.find(i =>
      i.type === 'image_set' && (
        (i.images || i.image_set_items || []).some(img => img.id === imageId)
      )
    )
    if (parentItem) {
      await store.removeImageFromSet(parentItem.id, imageId)
      await store.fetchBoard(store.currentBoard.id)
    }
  } catch (err) {
    console.error('Failed to remove image from set:', err)
  }
}

// ========================================
// INLINE CANVAS DRAWING (perfect-freehand)
// ========================================

// Drawing state
const drawColor = ref('#1e293b')
const drawStrokeWidth = ref((() => {
  // Try DB settings first
  const dbSettings = store.getBrushSettings()
  if (dbSettings?.size) return dbSettings.size
  // Fallback to legacy localStorage
  try { const r = localStorage.getItem('moodboard_brush_last'); if (r) { const p = JSON.parse(r); if (p.size) return p.size } } catch {}
  return 4
})())
const drawEraser = ref(false)
const drawCursorPos = ref(null)

// Brush options (perfect-freehand full API)
const BRUSH_DEFAULTS = {
  size: 4,
  thinning: 0.5,
  smoothing: 0.6,
  streamline: 0.7,
  easing: 'linear',
  taperStart: 0,
  taperEnd: 0,
  easingStart: 'linear',
  easingEnd: 'linear',
  capStart: true,
  capEnd: true,
  simulatePressure: true,
}
// Restore last-used settings from DB (via store) or use defaults
function loadLastBrushSettings() {
  const dbSettings = store.getBrushSettings()
  if (dbSettings) return { ...BRUSH_DEFAULTS, ...dbSettings }
  // Fallback: try legacy localStorage
  try {
    const raw = localStorage.getItem('moodboard_brush_last')
    if (raw) return { ...BRUSH_DEFAULTS, ...JSON.parse(raw) }
  } catch { /* ignore */ }
  return { ...BRUSH_DEFAULTS }
}

const lastBrush = loadLastBrushSettings()

const drawThinning = ref(lastBrush.thinning)
const drawSmoothing = ref(lastBrush.smoothing)
const drawStreamline = ref(lastBrush.streamline)
const drawEasing = ref(lastBrush.easing)
const drawTaperStart = ref(lastBrush.taperStart)
const drawTaperEnd = ref(lastBrush.taperEnd)
const drawEasingStart = ref(lastBrush.easingStart)
const drawEasingEnd = ref(lastBrush.easingEnd)
const drawCapStart = ref(lastBrush.capStart)
const drawCapEnd = ref(lastBrush.capEnd)
const drawSimulatePressure = ref(lastBrush.simulatePressure)
const showBrushOptions = ref(false)
const brushOptionsRef = ref(null)

// Brush presets (from DB via store)
const brushPresets = computed(() => store.getBrushPresets())
const showPresetNameInput = ref(false)
const newPresetName = ref('')
const presetNameInputRef = ref(null)

function getCurrentBrushSettings() {
  return {
    size: drawStrokeWidth.value,
    thinning: drawThinning.value,
    smoothing: drawSmoothing.value,
    streamline: drawStreamline.value,
    easing: drawEasing.value,
    taperStart: drawTaperStart.value,
    taperEnd: drawTaperEnd.value,
    easingStart: drawEasingStart.value,
    easingEnd: drawEasingEnd.value,
    capStart: drawCapStart.value,
    capEnd: drawCapEnd.value,
    simulatePressure: drawSimulatePressure.value,
  }
}

function saveBrushSettingsToStorage() {
  store.saveBrushSettings(getCurrentBrushSettings())
}

// Watch all brush settings and auto-save on change
watch(
  [drawStrokeWidth, drawThinning, drawSmoothing, drawStreamline, drawEasing,
   drawTaperStart, drawTaperEnd, drawEasingStart, drawEasingEnd,
   drawCapStart, drawCapEnd, drawSimulatePressure],
  saveBrushSettingsToStorage
)

// Reload brush settings when switching boards
watch(() => store.currentBoard?.id, () => {
  const s = loadLastBrushSettings()
  drawStrokeWidth.value = s.size
  drawThinning.value = s.thinning
  drawSmoothing.value = s.smoothing
  drawStreamline.value = s.streamline
  drawEasing.value = s.easing
  drawTaperStart.value = s.taperStart
  drawTaperEnd.value = s.taperEnd
  drawEasingStart.value = s.easingStart
  drawEasingEnd.value = s.easingEnd
  drawCapStart.value = s.capStart
  drawCapEnd.value = s.capEnd
  drawSimulatePressure.value = s.simulatePressure
})

function startSavePreset() {
  showPresetNameInput.value = true
  newPresetName.value = ''
  nextTick(() => presetNameInputRef.value?.focus())
}

function saveBrushPreset() {
  const name = newPresetName.value.trim()
  if (!name) return
  const preset = {
    id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
    name,
    settings: getCurrentBrushSettings(),
  }
  store.addBrushPreset(preset)
  showPresetNameInput.value = false
  newPresetName.value = ''
}

function loadBrushPreset(preset) {
  const s = preset.settings
  drawStrokeWidth.value = s.size ?? BRUSH_DEFAULTS.size
  drawThinning.value = s.thinning ?? BRUSH_DEFAULTS.thinning
  drawSmoothing.value = s.smoothing ?? BRUSH_DEFAULTS.smoothing
  drawStreamline.value = s.streamline ?? BRUSH_DEFAULTS.streamline
  drawEasing.value = s.easing ?? BRUSH_DEFAULTS.easing
  drawTaperStart.value = s.taperStart ?? BRUSH_DEFAULTS.taperStart
  drawTaperEnd.value = s.taperEnd ?? BRUSH_DEFAULTS.taperEnd
  drawEasingStart.value = s.easingStart ?? BRUSH_DEFAULTS.easingStart
  drawEasingEnd.value = s.easingEnd ?? BRUSH_DEFAULTS.easingEnd
  drawCapStart.value = s.capStart ?? BRUSH_DEFAULTS.capStart
  drawCapEnd.value = s.capEnd ?? BRUSH_DEFAULTS.capEnd
  drawSimulatePressure.value = s.simulatePressure ?? BRUSH_DEFAULTS.simulatePressure
}

function deleteBrushPreset(presetId) {
  store.removeBrushPreset(presetId)
}
const isDrawingStroke = ref(false)
const activeStrokePoints = ref([])
const activeStrokePath = ref('')

// Session strokes: strokes drawn in the current session, not yet saved as items
const drawSessionStrokes = ref([])

// Legacy canvas strokes (from old canvas_strokes column, read-only rendering)
const canvasStrokes = ref([])

const drawQuickColors = [
  '#ffffff', '#1e293b', '#ef4444', '#f97316', '#eab308',
  '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899'
]

// EyeDropper API support
const hasEyeDropper = typeof window !== 'undefined' && 'EyeDropper' in window

async function pickDrawColorFromScreen() {
  if (!hasEyeDropper) return
  try {
    const eyeDropper = new window.EyeDropper()
    const result = await eyeDropper.open()
    if (result?.sRGBHex) {
      drawColor.value = result.sRGBHex
      drawEraser.value = false
    }
  } catch (e) {
    // User cancelled
  }
}

// Load legacy strokes from board data (old drawings stored on the board itself)
watch(() => props.board?.canvas_strokes, (raw) => {
  if (!raw) { canvasStrokes.value = []; return }
  try {
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw
    if (Array.isArray(parsed)) canvasStrokes.value = parsed
  } catch { canvasStrokes.value = [] }
}, { immediate: true })

/**
 * Convert perfect-freehand outline points to an SVG fill path string.
 */
function outlineToSvgPath(outline) {
  if (!outline || outline.length < 2) return ''
  let d = `M ${outline[0][0].toFixed(2)} ${outline[0][1].toFixed(2)}`
  for (let i = 1; i < outline.length; i++) {
    const [x0, y0] = outline[i - 1]
    const [x1, y1] = outline[i]
    const mx = ((x0 + x1) / 2).toFixed(2)
    const my = ((y0 + y1) / 2).toFixed(2)
    d += ` Q ${x0.toFixed(2)} ${y0.toFixed(2)} ${mx} ${my}`
  }
  d += ' Z'
  return d
}

// Easing function lookup
const EASING_FUNCTIONS = {
  linear: (t) => t,
  easeIn: (t) => t * t,
  easeOut: (t) => 1 - (1 - t) * (1 - t),
  easeInOut: (t) => t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2,
}

/**
 * Get freehand options for the current draw settings (full perfect-freehand API)
 */
function getDrawFreehandOptions() {
  return {
    size: drawStrokeWidth.value,
    thinning: drawThinning.value,
    smoothing: drawSmoothing.value,
    streamline: drawStreamline.value,
    easing: EASING_FUNCTIONS[drawEasing.value] || EASING_FUNCTIONS.linear,
    start: {
      taper: drawTaperStart.value,
      cap: drawCapStart.value,
      easing: EASING_FUNCTIONS[drawEasingStart.value] || EASING_FUNCTIONS.linear,
    },
    end: {
      taper: drawTaperEnd.value,
      cap: drawCapEnd.value,
      easing: EASING_FUNCTIONS[drawEasingEnd.value] || EASING_FUNCTIONS.linear,
    },
    simulatePressure: drawSimulatePressure.value,
  }
}

function resetBrushOptions() {
  drawStrokeWidth.value = BRUSH_DEFAULTS.size
  drawThinning.value = BRUSH_DEFAULTS.thinning
  drawSmoothing.value = BRUSH_DEFAULTS.smoothing
  drawStreamline.value = BRUSH_DEFAULTS.streamline
  drawEasing.value = BRUSH_DEFAULTS.easing
  drawTaperStart.value = BRUSH_DEFAULTS.taperStart
  drawTaperEnd.value = BRUSH_DEFAULTS.taperEnd
  drawEasingStart.value = BRUSH_DEFAULTS.easingStart
  drawEasingEnd.value = BRUSH_DEFAULTS.easingEnd
  drawCapStart.value = BRUSH_DEFAULTS.capStart
  drawCapEnd.value = BRUSH_DEFAULTS.capEnd
  drawSimulatePressure.value = BRUSH_DEFAULTS.simulatePressure
}

// Close brush options when clicking outside
function onBrushOptionsClickOutside(e) {
  if (showBrushOptions.value && brushOptionsRef.value && !brushOptionsRef.value.contains(e.target)) {
    showBrushOptions.value = false
  }
}
watch(showBrushOptions, (v) => {
  if (v) {
    setTimeout(() => document.addEventListener('click', onBrushOptionsClickOutside), 0)
  } else {
    document.removeEventListener('click', onBrushOptionsClickOutside)
  }
})

function screenToCanvas(e) {
  const rect = canvasContainer.value.getBoundingClientRect()
  return {
    x: (e.clientX - rect.left - store.panX) / store.zoom,
    y: (e.clientY - rect.top - store.panY) / store.zoom
  }
}

function onDrawPointerDown(e) {
  if (e.button !== 0) return
  
  // Capture pointer for smooth drawing across boundaries
  e.target.setPointerCapture(e.pointerId)
  
  isDrawingStroke.value = true
  const pos = screenToCanvas(e)
  const pressure = e.pressure || 0.5
  activeStrokePoints.value = [[pos.x, pos.y, pressure]]
  activeStrokePath.value = ''
}

function onDrawPointerMove(e) {
  // Track cursor for preview
  drawCursorPos.value = { x: e.clientX - canvasContainer.value.getBoundingClientRect().left, y: e.clientY - canvasContainer.value.getBoundingClientRect().top }
  
  // Broadcast cursor position to collaborators
  if (props.board?.id && canvasContainer.value) {
    const pos = screenToCanvas(e)
    store.sendCursorPosition(props.board.id, pos.x, pos.y)
  }
  
  if (!isDrawingStroke.value) return
  
  const pos = screenToCanvas(e)
  const pressure = e.pressure || 0.5
  
  // Skip points that are too close together to avoid micro-jitter
  const pts = activeStrokePoints.value
  if (pts.length > 0) {
    const last = pts[pts.length - 1]
    const dx = pos.x - last[0]
    const dy = pos.y - last[1]
    if (dx * dx + dy * dy < 4) return // min ~2px distance
  }
  
  pts.push([pos.x, pos.y, pressure])
  
  // Render in-progress stroke
  if (!drawEraser.value) {
    const outline = getStroke(pts, getDrawFreehandOptions())
    activeStrokePath.value = outlineToSvgPath(outline)
  } else {
    activeStrokePath.value = ''
  }
}

function onDrawPointerUp(e) {
  if (!isDrawingStroke.value) return
  isDrawingStroke.value = false
  
  // Release pointer capture
  if (e && e.pointerId != null && e.target) {
    try { e.target.releasePointerCapture(e.pointerId) } catch {}
  }
  
  // Handle single-click dot (add a tiny offset)
  if (activeStrokePoints.value.length === 1) {
    const [x, y, p] = activeStrokePoints.value[0]
    activeStrokePoints.value.push([x + 0.1, y + 0.1, p])
  }
  
  if (drawEraser.value) {
    // Eraser: remove session strokes that overlap with the eraser path
    eraseSessionStrokes(activeStrokePoints.value)
  } else {
    // Finalize stroke into session
    const outline = getStroke(activeStrokePoints.value, getDrawFreehandOptions())
    const svgPath = outlineToSvgPath(outline)
    
    if (svgPath) {
      const stroke = {
        id: Date.now() + '-' + Math.random().toString(36).substr(2, 6),
        points: [...activeStrokePoints.value],
        color: drawColor.value,
        width: drawStrokeWidth.value,
        options: {
          thinning: drawThinning.value,
          smoothing: drawSmoothing.value,
          streamline: drawStreamline.value,
          taperStart: drawTaperStart.value,
          taperEnd: drawTaperEnd.value,
          capStart: drawCapStart.value,
          capEnd: drawCapEnd.value,
          simulatePressure: drawSimulatePressure.value,
        },
        svgPath
      }
      drawSessionStrokes.value.push(stroke)
    }
  }
  
  activeStrokePoints.value = []
  activeStrokePath.value = ''
}

function eraseSessionStrokes(eraserPoints) {
  if (eraserPoints.length < 2) return
  
  const radius = drawStrokeWidth.value * 1.5
  const toRemove = new Set()
  
  for (const stroke of drawSessionStrokes.value) {
    if (!stroke.points) continue
    for (const ep of eraserPoints) {
      for (const sp of stroke.points) {
        const dx = ep[0] - sp[0]
        const dy = ep[1] - sp[1]
        if (Math.sqrt(dx * dx + dy * dy) < radius + stroke.width) {
          toRemove.add(stroke.id)
          break
        }
      }
      if (toRemove.has(stroke.id)) break
    }
  }
  
  if (toRemove.size > 0) {
    drawSessionStrokes.value = drawSessionStrokes.value.filter(s => !toRemove.has(s.id))
  }
}

function undoSessionStroke() {
  if (drawSessionStrokes.value.length === 0) return
  drawSessionStrokes.value.pop()
}

function clearSessionStrokes() {
  drawSessionStrokes.value = []
}

/**
 * Compute bounding box for an array of point arrays [[x,y,p], ...]
 */
function getStrokesBounds(strokes) {
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const stroke of strokes) {
    for (const pt of stroke.points) {
      const pad = (stroke.width || 4) / 2
      if (pt[0] - pad < minX) minX = pt[0] - pad
      if (pt[1] - pad < minY) minY = pt[1] - pad
      if (pt[0] + pad > maxX) maxX = pt[0] + pad
      if (pt[1] + pad > maxY) maxY = pt[1] + pad
    }
  }
  return { minX, minY, maxX, maxY, width: maxX - minX, height: maxY - minY }
}

/**
 * When user clicks Done: save all session strokes as a single movable drawing item.
 * The item's pos_x/pos_y is the bounding box origin.
 * Stroke points are stored relative to the item origin.
 */
async function saveSessionAndExit() {
  if (drawSessionStrokes.value.length > 0) {
    // Split into chunks if there are many strokes to avoid exceeding DB TEXT limit (~64KB)
    const MAX_STROKES_PER_ITEM = 30
    const chunks = []
    for (let i = 0; i < drawSessionStrokes.value.length; i += MAX_STROKES_PER_ITEM) {
      chunks.push(drawSessionStrokes.value.slice(i, i + MAX_STROKES_PER_ITEM))
    }

    const savedItems = []
    for (const chunk of chunks) {
      const bounds = getStrokesBounds(chunk)
      const PADDING = 8
      
      // Offset all stroke points relative to bounding box origin
      // Simplify points: round to 1 decimal, skip redundant close points
      const relativeStrokes = chunk.map(s => {
        const simplified = simplifyPoints(
          s.points.map(([x, y, p]) => [x - bounds.minX + PADDING, y - bounds.minY + PADDING, p])
        )
        return {
          points: simplified,
          color: s.color,
          width: s.width,
          options: s.options || {}
        }
      })
      
      // Re-compute SVG paths for relative coordinates using stored brush options
      for (const s of relativeStrokes) {
        const opts = s.options || {}
        const outline = getStroke(s.points, {
          size: s.width,
          thinning: opts.thinning ?? 0.5,
          smoothing: opts.smoothing ?? 0.6,
          streamline: opts.streamline ?? 0.7,
          easing: (t) => t,
          start: {
            taper: opts.taperStart ?? 0,
            cap: opts.capStart ?? true,
            easing: (t) => t,
          },
          end: {
            taper: opts.taperEnd ?? 0,
            cap: opts.capEnd ?? true,
            easing: (t) => t,
          },
          simulatePressure: opts.simulatePressure ?? true
        })
        s.svgPath = outlineToSvgPath(outline)
      }
      
      const itemWidth = Math.max(60, Math.round(bounds.width + PADDING * 2))
      const itemHeight = Math.max(40, Math.round(bounds.height + PADDING * 2))
      
      const result = await store.addItem({
        type: 'drawing',
        pos_x: Math.round(bounds.minX - PADDING),
        pos_y: Math.round(bounds.minY - PADDING),
        width: itemWidth,
        height: itemHeight,
        title: '',
        content: JSON.stringify({ strokes: relativeStrokes, width: itemWidth, height: itemHeight, engine: 'perfect-freehand' }),
        color: chunk[0]?.color || '#1e293b'
      })
      
      if (result) {
        savedItems.push(result)
      } else {
        console.error('Failed to save drawing chunk, keeping strokes for retry')
      }
    }

    // Only clear strokes if ALL chunks saved successfully
    if (savedItems.length === chunks.length) {
      drawSessionStrokes.value = []
    } else {
      // Remove only the successfully saved strokes (keep failed ones for retry)
      const savedCount = savedItems.length * MAX_STROKES_PER_ITEM
      drawSessionStrokes.value = drawSessionStrokes.value.slice(savedCount)
      if (drawSessionStrokes.value.length > 0) {
        // Stay in draw mode so user can try saving again
        return
      }
    }
  }
  
  drawMode.value = false
}

/**
 * Simplify an array of points by reducing precision and removing
 * points that are very close together. Keeps the drawing looking
 * the same while drastically cutting JSON size.
 */
function simplifyPoints(pts) {
  if (pts.length <= 2) return pts.map(([x, y, p]) => [+x.toFixed(1), +y.toFixed(1), +(p || 0.5).toFixed(2)])
  
  const result = [[+pts[0][0].toFixed(1), +pts[0][1].toFixed(1), +(pts[0][2] || 0.5).toFixed(2)]]
  const minDist = 1.5 // Skip points closer than 1.5px apart
  
  for (let i = 1; i < pts.length - 1; i++) {
    const prev = result[result.length - 1]
    const dx = pts[i][0] - prev[0]
    const dy = pts[i][1] - prev[1]
    if (dx * dx + dy * dy >= minDist * minDist) {
      result.push([+pts[i][0].toFixed(1), +pts[i][1].toFixed(1), +(pts[i][2] || 0.5).toFixed(2)])
    }
  }
  
  // Always keep the last point
  const last = pts[pts.length - 1]
  result.push([+last[0].toFixed(1), +last[1].toFixed(1), +(last[2] || 0.5).toFixed(2)])
  
  return result
}

function toggleDrawMode() {
  if (drawMode.value) {
    if (drawSessionStrokes.value.length > 0) {
      saveSessionAndExit()
      return
    }
    showBrushOptions.value = false
  }
  lineMode.value = false
  measureMode.value = false
  drawMode.value = !drawMode.value
}

// Keyboard shortcuts
function onKeyDown(e) {
  // Track space key for pan mode (before input check)
  if (e.code === 'Space' && !e.target.tagName.match(/^(INPUT|TEXTAREA)$/) && !e.target.isContentEditable) {
    e.preventDefault()
    spaceHeld.value = true
    return
  }
  
  const isTextInput = e.target.tagName === 'TEXTAREA' || e.target.isContentEditable
    || (e.target.tagName === 'INPUT' && !['range', 'checkbox', 'radio', 'color'].includes(e.target.type))

  // Ctrl+Z / Ctrl+Y / Ctrl+Shift+Z must work even when a sidebar control has focus
  if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) {
    e.preventDefault()
    if (e.shiftKey) store.redo()
    else store.undo()
    return
  }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || e.key === 'Y')) {
    e.preventDefault()
    store.redo()
    return
  }

  if (isTextInput) return

  // Escape clears focus mode
  if (e.key === 'Escape' && store.focusedItemId) {
    store.clearFocusItem()
    return
  }

  // In readonly mode, only allow space (pan) — no editing shortcuts
  if (props.readonly) return
  
  // D key toggles draw mode (exits line mode if active)
  if ((e.key === 'd' || e.key === 'D') && !e.ctrlKey && !e.metaKey) {
    lineMode.value = false
    toggleDrawMode()
    return
  }

  // P key toggles pen tool mode
  if ((e.key === 'p' || e.key === 'P') && !e.ctrlKey && !e.metaKey) {
    togglePenMode()
    return
  }

  // L key toggles line tool mode
  if ((e.key === 'l' || e.key === 'L') && !e.ctrlKey && !e.metaKey) {
    toggleLineMode()
    return
  }

  // M key toggles measure tool
  if ((e.key === 'm' || e.key === 'M') && !e.ctrlKey && !e.metaKey) {
    toggleMeasureMode()
    return
  }

  // R key toggles rulers
  if ((e.key === 'r' || e.key === 'R') && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
    store.showRulers = !store.showRulers
    return
  }

  // I = eyedropper for fill, Shift+I = eyedropper for stroke/border
  if ((e.key === 'i' || e.key === 'I') && !e.ctrlKey && !e.metaKey && store.selectedItemIds.size === 1) {
    const itemId = [...store.selectedItemIds][0]
    const item = store.currentItems.find(i => i.id === itemId)
    if (item) {
      emit('pick-color', item, e.shiftKey ? 'stroke' : 'fill')
      return
    }
  }

  // Escape exits measure mode (measurements persist)
  if (e.key === 'Escape' && measureMode.value) {
    measureMode.value = false
    return
  }

  // Escape exits pen mode
  if (e.key === 'Escape' && penMode.value) {
    penMode.value = false
    return
  }

  // Escape exits line mode (or cancels current line if drawing)
  if (e.key === 'Escape' && lineMode.value) {
    if (lineDrawStart.value) {
      // Cancel current line drawing, stay in line mode
      resetLineState()
    } else {
      lineMode.value = false
    }
    return
  }
  
  // Draw mode shortcuts
  if (drawMode.value) {
    // Ctrl+Z undo stroke
    if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
      e.preventDefault()
      undoSessionStroke()
      return
    }
    // Escape exits draw mode
    if (e.key === 'Escape') {
      drawMode.value = false
      return
    }
    // E for eraser
    if (e.key === 'e' || e.key === 'E') {
      drawEraser.value = !drawEraser.value
      return
    }
    // [ and ] for stroke width
    if (e.key === '[') { drawStrokeWidth.value = Math.max(1, drawStrokeWidth.value - 1); return }
    if (e.key === ']') { drawStrokeWidth.value = Math.min(30, drawStrokeWidth.value + 1); return }
    return // Don't process other shortcuts while drawing
  }
  
  // Ctrl+Z / Ctrl+Y handled above (before input focus check)
  
  // Ctrl+[ / Ctrl+] — z-index reordering for selected items
  // Use e.code (BracketLeft/BracketRight) because e.key changes to {/} when Shift is held
  if ((e.ctrlKey || e.metaKey) && (e.code === 'BracketLeft' || e.code === 'BracketRight') && store.selectedItemIds.size > 0) {
    e.preventDefault()
    for (const id of store.selectedItemIds) {
      const item = store.currentBoard?.items?.find(i => i.id === id)
      if (!item || item.locked) continue
      if (e.code === 'BracketRight' && e.shiftKey) {
        store.bringToFront(id)
      } else if (e.code === 'BracketRight') {
        store.moveForward(id)
      } else if (e.code === 'BracketLeft' && e.shiftKey) {
        store.sendToBack(id)
      } else {
        store.moveBackward(id)
      }
    }
    return
  }

  // R key — Rotate selected items 90 degrees (Shift+R for counter-clockwise)
  if ((e.key === 'r' || e.key === 'R') && !e.ctrlKey && !e.metaKey && !e.repeat) {
    if (store.selectedItemIds.size > 0) {
      const delta = e.shiftKey ? -90 : 90
      for (const id of store.selectedItemIds) {
        const item = store.currentBoard?.items?.find(i => i.id === id)
        if (item && !item.locked) {
          const current = item.rotation || 0
          const newRotation = ((current + delta) % 360 + 360) % 360
          store.updateItem(id, { rotation: newRotation })
        }
      }
    }
    return
  }

  // Arrow keys — nudge selected items (1px, or 10px with Shift)
  if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key) && store.selectedItemIds.size > 0) {
    e.preventDefault()
    const step = e.shiftKey ? 10 : 1
    let dx = 0, dy = 0
    if (e.key === 'ArrowLeft')  dx = -step
    if (e.key === 'ArrowRight') dx = step
    if (e.key === 'ArrowUp')    dy = -step
    if (e.key === 'ArrowDown')  dy = step
    const updates = []
    for (const id of store.selectedItemIds) {
      const item = store.currentBoard?.items?.find(i => i.id === id)
      if (item && !item.locked) {
        updates.push({
          id: item.id,
          pos_x: (item.pos_x || 0) + dx,
          pos_y: (item.pos_y || 0) + dy,
        })
      }
    }
    if (updates.length) {
      store.batchUpdateItems(updates)
    }
    return
  }

  if (e.key === 'Delete' || e.key === 'Backspace') {
    store.deleteSelectedItems()
  }
  if (e.key === 'Escape') {
    if (store.connectingFrom) {
      store.connectingFrom = null
      tempConnectionEnd.value = null
    } else if (store.editingGroupId) {
      store.exitGroup()
    } else {
      store.clearSelection()
    }
  }
  // Ctrl+A — Select all
  if (e.key === 'a' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    store.selectAll()
  }
  // Ctrl+C — Copy selected items
  if (e.key === 'c' && (e.ctrlKey || e.metaKey)) {
    const count = store.copySelectedItems()
    if (count) {
      e.preventDefault()
    }
  }
  // Ctrl+V — handled entirely in the `paste` event listener (onPaste).
  // We must NOT preventDefault here because that would kill the paste event
  // and prevent screenshot/image pasting from the system clipboard.
  // Ctrl+D — Duplicate selected items
  if (e.key === 'd' && (e.ctrlKey || e.metaKey)) {
    if (store.selectedItems.length) {
      e.preventDefault()
      store.duplicateSelectedItems(30, 30)
    }
  }

  // Ctrl+G — Group selected items
  if (e.key === 'g' && (e.ctrlKey || e.metaKey) && !e.shiftKey) {
    if (store.selectedItemIds.size >= 2) {
      e.preventDefault()
      store.groupSelectedItems()
    }
  }

  // Ctrl+Shift+G — Ungroup selected items
  if (e.key === 'G' && (e.ctrlKey || e.metaKey) && e.shiftKey) {
    if (store.selectionHasGroups()) {
      e.preventDefault()
      store.ungroupSelectedItems()
    }
  }

  // Alt+Shift+U/S/I/E/F — Boolean shape operations
  if (e.altKey && e.shiftKey && store.canBooleanOp()) {
    const key = e.key.toUpperCase()
    const opMap = { U: 'union', S: 'subtract', I: 'intersect', E: 'exclude', F: 'flatten' }
    const op = opMap[key]
    if (op) {
      e.preventDefault()
      store.booleanOp(op)
    }
  }
}

function onKeyUp(e) {
  if (e.code === 'Space') {
    spaceHeld.value = false
    if (store.isPanning) {
      store.isPanning = false
      dragStart.value = null
      store.saveViewport()
    }
  }
}

// Watch for accent theme changes via MutationObserver
let accentObserver = null

// Unified paste handler — system clipboard images (screenshots) take priority,
// then fall back to the internal mood-board clipboard (copied items).
// Previously the Ctrl+V keydown handler would e.preventDefault() when the
// internal clipboard had items, which killed the paste event and prevented
// screenshot pasting until a page refresh cleared the internal clipboard.
async function onPaste(e) {
  if (!canvasContainer.value) return

  // Don't intercept paste when user is editing text (contenteditable, input, textarea)
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return

  // --- 1) Check system clipboard for images (screenshots, copied files) ---
  const clipItems = e.clipboardData?.items
  const imageFiles = []
  if (clipItems) {
    for (const item of clipItems) {
      if (item.type.startsWith('image/')) {
        const file = item.getAsFile()
        if (file) imageFiles.push(file)
      }
    }
  }

  if (imageFiles.length) {
    e.preventDefault()

    // Place in center of current viewport
    const rect = canvasContainer.value.getBoundingClientRect()
    const x = Math.round((rect.width / 2 - store.panX) / store.zoom)
    const y = Math.round((rect.height / 2 - store.panY) / store.zoom)

    try {
      const uploaded = await store.uploadFiles(imageFiles)
      if (imageFiles.length === 1 && uploaded[0]) {
        await store.addItem(buildImageItemData(uploaded[0], {
          posX: x,
          posY: y,
          title: 'Pasted image',
        }))
      } else if (imageFiles.length > 1) {
        const imageSetItems = uploaded.map((u, i) => ({
          image_url: u.url,
          thumbnail_url: u.thumbnail_url || u.url,
          original_filename: imageFiles[i]?.name || u.original_filename || null,
          width_px: u.width_px || null,
          height_px: u.height_px || null,
          position: i
        }))
        await store.addItem({
          type: 'image_set',
          pos_x: x,
          pos_y: y,
          width: 320,
          height: 240,
          title: `Pasted images (${imageFiles.length})`,
          image_set_items: imageSetItems
        })
      }
    } catch (err) {
      console.error('Clipboard image upload failed:', err)
    }
    return  // images handled — don't also paste internal items
  }

  // --- 2) Cross-tab: check system clipboard for serialized FlowOne item data ---
  // Prefer text/html (contains JSON in data attribute), fall back to text/plain
  const clipHtml = e.clipboardData?.getData('text/html')
  const clipText = e.clipboardData?.getData('text/plain')
  if (clipHtml) {
    store.loadClipboardFromText(clipHtml)
  }
  if (!store.clipboard.length && clipText) {
    store.loadClipboardFromText(clipText)
  }

  // --- 3) Paste from internal clipboard (hydrated from system clipboard above or same-tab copy) ---
  if (store.clipboard.length) {
    e.preventDefault()
    const rect = canvasContainer.value.getBoundingClientRect()
    const viewCenterX = Math.round((rect.width / 2 - store.panX) / store.zoom)
    const viewCenterY = Math.round((rect.height / 2 - store.panY) / store.zoom)

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const item of store.clipboard) {
      const ix = item.pos_x || 0
      const iy = item.pos_y || 0
      minX = Math.min(minX, ix)
      minY = Math.min(minY, iy)
      maxX = Math.max(maxX, ix + (item.width || 240))
      maxY = Math.max(maxY, iy + (item.height || 120))
    }
    const clipCenterX = (minX + maxX) / 2
    const clipCenterY = (minY + maxY) / 2

    const offsetX = viewCenterX - clipCenterX
    const offsetY = viewCenterY - clipCenterY
    store.pasteItems(offsetX, offsetY)
  }
}

// Global mouseup — catches releases outside the canvas to end pan/drag
function onGlobalMouseUp(e) {
  enableIframeAfterDrag()
  if (store.isPanning) {
    store.isPanning = false
    dragStart.value = null
    store.saveViewport()
  }
  if (store.isDragging) {
    onCanvasMouseUp(e)
  }
}

// Container size tracking for rulers
let containerResizeObserver = null
onMounted(() => {
  document.addEventListener('keydown', onKeyDown)
  document.addEventListener('keyup', onKeyUp)
  document.addEventListener('mouseup', onGlobalMouseUp)
  document.addEventListener('click', closeCanvasContext)
  document.addEventListener('paste', onPaste)
  updateAccentColor()
  // Render grain noise if enabled + attach resize observer so grain re-renders on viewport changes
  if (grainEnabled.value) nextTick(renderGrainNoise)
  setupGrainResizeObserver()
  // Watch for data-accent attribute changes on <html>
  accentObserver = new MutationObserver(() => updateAccentColor())
  accentObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-accent', 'class'] })
  // Track container size for rulers
  if (canvasContainer.value) {
    containerWidth.value = canvasContainer.value.offsetWidth
    containerHeight.value = canvasContainer.value.offsetHeight
    containerResizeObserver = new ResizeObserver(entries => {
      for (const entry of entries) {
        containerWidth.value = entry.contentRect.width
        containerHeight.value = entry.contentRect.height
      }
    })
    containerResizeObserver.observe(canvasContainer.value)
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeyDown)
  document.removeEventListener('keyup', onKeyUp)
  document.removeEventListener('mouseup', onGlobalMouseUp)
  document.removeEventListener('click', closeCanvasContext)
  document.removeEventListener('paste', onPaste)
  if (rafId) cancelAnimationFrame(rafId)
  if (accentObserver) accentObserver.disconnect()
  if (grainResizeObserver) grainResizeObserver.disconnect()
  if (containerResizeObserver) containerResizeObserver.disconnect()
  clearTimeout(_animSettleTimer)
})

// ========================================
// PEN TOOL
// ========================================

function togglePenMode() {
  if (penMode.value) {
    penMode.value = false
  } else {
    drawMode.value = false
    lineMode.value = false
    measureMode.value = false
    penMode.value = true
    store.clearSelection()
  }
}

function toggleMeasureMode() {
  if (measureMode.value) {
    measureMode.value = false
  } else {
    drawMode.value = false
    penMode.value = false
    lineMode.value = false
    commentMode.value = false
    measureMode.value = true
    measure.visible.value = true
    store.clearSelection()
  }
}

// ========================================
// LINE TOOL — click-drag to draw a straight line item
// ========================================
const lineDrawStart = ref(null)      // { x, y } in canvas coords — first click
const lineDrawEnd = ref(null)        // { x, y } in canvas coords — preview endpoint
const lineDrawColor = ref('#1e293b') // default line color
const lineDrawWidth = ref(2)         // default thickness
const lineDrawDragging = ref(false)  // true while mouse is held down (drag mode)
const lineDrawPlaced = ref(false)    // true after first click released (click-click mode)

function toggleLineMode() {
  if (lineMode.value) {
    lineMode.value = false
    resetLineState()
  } else {
    drawMode.value = false
    penMode.value = false
    measureMode.value = false
    lineMode.value = true
    store.clearSelection()
  }
}

function resetLineState() {
  lineDrawStart.value = null
  lineDrawEnd.value = null
  lineDrawDragging.value = false
  lineDrawPlaced.value = false
}

function commitLine() {
  const sx = lineDrawStart.value.x, sy = lineDrawStart.value.y
  const ex = lineDrawEnd.value.x, ey = lineDrawEnd.value.y
  const dist = Math.sqrt((ex - sx) ** 2 + (ey - sy) ** 2)
  if (dist >= 8) {
    const PAD = 10
    const minX = Math.min(sx, ex)
    const minY = Math.min(sy, ey)
    const maxX = Math.max(sx, ex)
    const maxY = Math.max(sy, ey)
    const w = Math.max(20, maxX - minX + PAD * 2)
    const h = Math.max(20, maxY - minY + PAD * 2)
    store.addItem({
      type: 'line',
      pos_x: Math.round(minX - PAD),
      pos_y: Math.round(minY - PAD),
      width: Math.round(w),
      height: Math.round(h),
      style_data: {
        line_x1: Math.round(sx - minX + PAD),
        line_y1: Math.round(sy - minY + PAD),
        line_x2: Math.round(ex - minX + PAD),
        line_y2: Math.round(ey - minY + PAD),
        line_color: lineDrawColor.value,
        line_width: lineDrawWidth.value,
        line_dash: 'solid',
        line_dash_gap: 0,
        line_arrow_start: false,
        line_arrow_end: false,
      }
    })
  }
  resetLineState()
}

async function onPenShapeCreated(shapeData) {
  penMode.value = false
  await store.addItem({
    type: 'pen_shape',
    pos_x: shapeData.pos_x,
    pos_y: shapeData.pos_y,
    width: shapeData.width,
    height: shapeData.height,
    style_data: {
      pen_path: shapeData.pathData,
      pen_svg_path: shapeData.svgPath,
      shape_fill: shapeData.fillColor,
      shape_border_color: shapeData.strokeColor,
      shape_border_width: shapeData.strokeWidth,
      shape_opacity: 100,
    }
  })
}

defineExpose({ addItemFromToolbar, zoomIn, zoomOut, zoomReset, fitScreen, animateToFrame, panToCanvasPoint, handleFileUpload, addDriveItem, addDriveFolder, addCalendarEvent, addBoardItem, toggleDrawMode, togglePenMode, toggleLineMode, toggleMeasureMode, clearMeasurements: () => measure.clearAll(), resetConnectionAnchors, selectedConnectionId })
</script>

<style scoped>
/* Kill ALL transitions & animations inside canvas during drag for maximum performance.
   Without this, the browser composites 122+ elements with transition timers every frame. */
.is-dragging :deep([data-item-id]) {
  transition: none !important;
}
.is-dragging :deep([data-item-id] *) {
  transition: none !important;
}

.draw-bar-enter-active,
.draw-bar-leave-active {
  transition: all 0.2s ease;
}
.draw-bar-enter-from,
.draw-bar-leave-to {
  opacity: 0;
  transform: translate(-50%, -10px);
}

/* Connection draw-on reveal: stroke-dashoffset animates from full length to 0 */
.measure-close-btn {
  opacity: 0;
  transition: opacity 0.15s ease;
  pointer-events: none;
}
.measure-line-group:hover .measure-close-btn {
  opacity: 1;
  pointer-events: auto;
}

.conn-draw-on {
  animation: connDrawOnReveal var(--conn-draw-dur, 1.5s) ease-out forwards;
}
@keyframes connDrawOnReveal {
  from { stroke-dashoffset: var(--conn-draw-len, 200); }
  to   { stroke-dashoffset: 0; }
}

/* ── Context menu utility classes ── */
.ctx-btn {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0.625rem;
  font-size: 0.75rem;
  text-align: left;
  transition: background-color 0.1s ease;
}
.ctx-normal {
  color: var(--color-surface-700, #374151);
}
.ctx-normal:hover {
  background-color: var(--color-surface-100, #f3f4f6);
}
:root.dark .ctx-normal,
.dark .ctx-normal {
  color: var(--color-surface-300, #d1d5db);
}
:root.dark .ctx-normal:hover,
.dark .ctx-normal:hover {
  background-color: var(--color-surface-700, #374151);
  color: #fff;
}
.ctx-danger {
  color: var(--color-red-600, #dc2626);
}
.ctx-danger:hover {
  background-color: #fef2f2;
}
:root.dark .ctx-danger,
.dark .ctx-danger {
  color: var(--color-red-400, #f87171);
}
:root.dark .ctx-danger:hover,
.dark .ctx-danger:hover {
  background-color: rgba(220, 38, 38, 0.12);
}
.ctx-disabled {
  color: var(--color-surface-400, #9ca3af);
  pointer-events: none;
  opacity: 0.5;
}
.ctx-shortcut {
  font-size: 0.625rem;
  font-family: ui-monospace, monospace;
  color: var(--color-surface-400, #9ca3af);
}
:root.dark .ctx-shortcut,
.dark .ctx-shortcut {
  color: var(--color-surface-500, #6b7280);
}
.ctx-divider {
  height: 1px;
  margin: 0.125rem 0.5rem;
  background-color: var(--color-surface-200, #e5e7eb);
}
:root.dark .ctx-divider,
.dark .ctx-divider {
  background-color: var(--color-surface-700, #374151);
}
</style>


