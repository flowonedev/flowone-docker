<template>
  <div
    ref="itemEl"
    :data-item-id="item.id"
    :class="[
      'absolute',
      dimmed ? 'transition-[opacity,filter] duration-300' : '',
      store.presentationMode ? '' : 'group',
      store.presentationMode
        ? ''
        : item.type === 'line'
          ? (selected ? 'ring-0 shadow-none' : '')
          : (selected
              ? (multiSelected ? 'selection-ring-multi' : 'selection-ring')
              : 'hover:shadow-lg'),
      store.presentationMode ? '' : item.locked ? 'opacity-90' : 'cursor-move',
      !store.presentationMode && connecting ? 'cursor-crosshair' : '',
      dimmed ? 'focus-dimmed' : '',
      motionClass
    ]"
    :style="{
      ...itemStyle,
      '--m-delay': motionDelay, '--m-dur': motionDuration, '--m-amp': motionAmplitude, '--m-dir': motionDirection,
      ...(dimmed ? { opacity: 0.15, filter: 'grayscale(0.7) blur(1px)' } : {}),
      ...(item.style_data?._hidden ? { opacity: 0, pointerEvents: 'none' } : {}),
      ...(store.presentationMode && !(item.type === 'youtube' && item.style_data?._youtubeInteractive) ? { pointerEvents: 'none' } : {}),
      ...(store.presentationMode && item.type === 'audio' && item.style_data?.audio_hidden_in_pres ? { opacity: 0, pointerEvents: 'none' } : {}),
      ...(item.style_data?.blend_mode && item.style_data.blend_mode !== 'normal' ? { mixBlendMode: item.style_data.blend_mode } : {}),
      ...itemOpacityStyle,
      ...selectionRingStyle
    }"
    @mousedown="$emit('mousedown', $event)"
    @contextmenu.prevent.stop="$emit('contextmenu', $event)"
    @dblclick.stop="onDoubleClick"
  >
    <!-- Group indicator (top-left corner) — always visible when item is in a group -->
    <div
      v-if="item.style_data?.group_id && isFullDetail && (selected || store.editingGroupId === item.style_data?.group_id)"
      class="absolute -top-2 -left-2 w-5 h-5 rounded-full z-30 shadow-md border-2 flex items-center justify-center"
      :class="store.editingGroupId === item.style_data?.group_id
        ? 'bg-primary-500 border-white dark:border-surface-900'
        : 'bg-amber-500 border-white dark:border-surface-900'"
      :style="{ transform: `scale(${uiS})`, transformOrigin: 'center' }"
      :title="store.editingGroupId === item.style_data?.group_id ? 'Inside group (Escape to exit)' : 'Grouped (double-click to enter, Ctrl+Shift+G to ungroup)'"
    >
      <span class="material-symbols-rounded text-white text-[10px]">{{ store.editingGroupId === item.style_data?.group_id ? 'edit' : 'link' }}</span>
    </div>
    <!-- Subtle dashed border on unselected items when inside their group -->
    <div
      v-if="item.style_data?.group_id && store.editingGroupId === item.style_data?.group_id && !selected && isFullDetail"
      class="absolute inset-0 border border-dashed border-primary-400/50 rounded-lg pointer-events-none z-20"
    />
    <!-- 4-corner resize handles -->
    <template v-if="selected && !item.locked && !isLowDetail">
      <div
        class="absolute -top-[5px] -left-[5px] w-[10px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-nw-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors"
        :style="{ transform: `scale(${uiS})`, transformOrigin: 'bottom right' }"
        @mousedown.stop="startResize($event, 'tl')"
      />
      <div
        class="absolute -top-[5px] -right-[5px] w-[10px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-ne-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors"
        :style="{ transform: `scale(${uiS})`, transformOrigin: 'bottom left' }"
        @mousedown.stop="startResize($event, 'tr')"
      />
      <div
        class="absolute -bottom-[5px] -left-[5px] w-[10px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-sw-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors"
        :style="{ transform: `scale(${uiS})`, transformOrigin: 'top right' }"
        @mousedown.stop="startResize($event, 'bl')"
      />
      <div
        class="absolute -bottom-[5px] -right-[5px] w-[10px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full cursor-se-resize z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors"
        :style="{ transform: `scale(${uiS})`, transformOrigin: 'top left' }"
        @mousedown.stop="startResize($event, 'br')"
      />
    </template>

    <!-- 4 midpoint edge resize handles (pill-shaped, axis-aligned) — hidden in multi-select -->
    <template v-if="selected && !item.locked && !isLowDetail && !multiSelected">
      <div
        class="absolute -top-[5px] left-1/2 w-[28px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors"
        :style="{ transform: `translate(-50%, 0) scale(${uiS})`, transformOrigin: 'center bottom', cursor: edgeCursor('t') }"
        @mousedown.stop="startResize($event, 't')"
      />
      <div
        class="absolute -right-[5px] top-1/2 w-[10px] h-[28px] bg-white border-[1.5px] border-primary-500 rounded-full z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors"
        :style="{ transform: `translate(0, -50%) scale(${uiS})`, transformOrigin: 'left center', cursor: edgeCursor('r') }"
        @mousedown.stop="startResize($event, 'r')"
      />
      <div
        class="absolute -bottom-[5px] left-1/2 w-[28px] h-[10px] bg-white border-[1.5px] border-primary-500 rounded-full z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors"
        :style="{ transform: `translate(-50%, 0) scale(${uiS})`, transformOrigin: 'center top', cursor: edgeCursor('b') }"
        @mousedown.stop="startResize($event, 'b')"
      />
      <div
        class="absolute -left-[5px] top-1/2 w-[10px] h-[28px] bg-white border-[1.5px] border-primary-500 rounded-full z-30 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors"
        :style="{ transform: `translate(0, -50%) scale(${uiS})`, transformOrigin: 'right center', cursor: edgeCursor('l') }"
        @mousedown.stop="startResize($event, 'l')"
      />
    </template>

    <!-- Corner radius handles (shapes with rectangle type only, hidden in multi-select) -->
    <template v-if="selected && !multiSelected && !item.locked && isFullDetail && showCornerRadiusHandles">
      <div
        v-for="crh in cornerRadiusHandlePositions"
        :key="crh.corner"
        class="absolute w-[8px] h-[8px] rounded-full bg-primary-400 border border-white dark:border-surface-900 z-30 cursor-pointer hover:bg-primary-500 hover:scale-125 transition-all"
        :style="{ left: crh.x + 'px', top: crh.y + 'px', transform: `translate(-50%, -50%) scale(${uiS})` }"
        :title="'Drag to adjust corner radius (' + crh.corner.toUpperCase() + ')'"
        @mousedown.stop="startCornerRadiusDrag($event, crh.corner)"
      />
    </template>
    <!-- Dimensions overlay (shown while resizing or single-item dragging, hidden in multi-select) -->
    <div
      v-if="!multiSelected && (resizing || (selected && store.isDragging))"
      data-dim-overlay
      class="absolute -bottom-7 right-0 bg-surface-800/90 text-white text-[10px] font-mono px-2 py-0.5 rounded-md z-40 whitespace-nowrap pointer-events-none shadow-md"
      :style="{ transform: `scale(${uiS})`, transformOrigin: 'top right' }"
    >
      <template v-if="resizing">{{ Math.round(item.width || 0) }} x {{ Math.round(item.height || 0) }} px</template>
      <template v-else>{{ Math.round(item.pos_x) }}, {{ Math.round(item.pos_y) }} | {{ Math.round(item.width || 0) }} x {{ Math.round(item.height || 0) }}</template>
    </div>

    <!-- Rotate handle (top-center, above the item) — hidden in multi-select to reduce clutter -->
    <div
      v-if="selected && !multiSelected && !item.locked && isFullDetail"
      class="absolute left-1/2 flex flex-col items-center z-30"
      :style="{ transform: `translateX(-50%) scale(${uiS})`, transformOrigin: 'bottom center', bottom: '100%' }"
      @mousedown.stop
      @click.stop
    >
      <div
        v-if="rotating"
        class="bg-primary-500 text-white text-[11px] font-semibold px-2 py-0.5 rounded-md shadow-md whitespace-nowrap mb-1 pointer-events-none"
      >
        {{ formatRotationDegrees(item.rotation || 0) }}
      </div>
      <div
        class="w-[8px] h-[8px] rounded-full bg-white border-[1.5px] border-primary-500 hover:bg-primary-50 dark:bg-surface-900 dark:hover:bg-surface-700 transition-colors cursor-grab"
        @mousedown.stop="startRotate"
        title="Rotate"
      />
      <div class="w-px h-[8px] bg-primary-500"></div>
    </div>
    
    <!-- Lock indicator (hidden at low zoom and during presentation) -->
    <div
      v-if="item.locked && !isLowDetail && !store.presentationMode"
      class="absolute -top-2 -right-2 z-10"
      :style="{ transform: `scale(${uiS})`, transformOrigin: 'bottom left' }"
    >
      <span class="material-symbols-rounded text-sm text-surface-400 bg-white dark:bg-surface-800 rounded-full p-0.5 shadow">lock</span>
    </div>

    <!-- Component instance badge (shows this item is linked to a reusable component) -->
    <div
      v-if="item.component_id && !isLowDetail && !store.presentationMode"
      class="absolute -top-2 z-10"
      :class="item.locked ? '-right-7' : '-right-2'"
      :style="{ transform: `scale(${uiS})`, transformOrigin: 'bottom left' }"
    >
      <span
        class="material-symbols-rounded text-sm text-cyan-600 bg-cyan-50 dark:bg-cyan-900/40 dark:text-cyan-400 rounded-full p-0.5 shadow border border-cyan-200 dark:border-cyan-800"
        title="Linked to component"
      >widgets</span>
    </div>
    
    <!-- Connection anchor point (visible when connecting, hidden at low zoom) -->
    <div
      v-if="connecting && !isLowDetail"
      class="absolute inset-0 z-20 flex items-center justify-center"
      @click.stop="$emit('connection-end')"
    >
      <div class="w-6 h-6 rounded-full bg-primary-500/20 border-2 border-primary-500 animate-pulse" />
    </div>

    <!-- Quick action bar — positioned above the item, inverse-zoom scaled to stay constant screen size -->
    <div
      v-if="showActionBar"
      class="absolute left-1/2 z-[9999] flex items-center gap-0.5 bg-white dark:bg-surface-800 rounded-xl shadow-lg border border-surface-200 dark:border-surface-700 px-1 py-0.5 whitespace-nowrap"
      :class="actionBarReady ? 'pointer-events-auto' : 'pointer-events-none'"
      :style="{ top: '-8px', transform: `translateX(-50%) translateY(-100%) scale(${uiS})`, transformOrigin: 'bottom center' }"
      @mousedown.stop
    >
      <!-- Connect -->
      <button
        @click.stop="$emit('connection-start')"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-primary-500 transition-colors"
        title="Connect to..."
      >
        <span class="material-symbols-rounded text-[16px]">trending_flat</span>
      </button>
      <!-- Lock / Unlock -->
      <button
        @click.stop="$emit('update', { locked: !item.locked })"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        :class="item.locked ? 'text-amber-500' : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
        :title="item.locked ? 'Unlock' : 'Lock'"
      >
        <span class="material-symbols-rounded text-[16px]">{{ item.locked ? 'lock' : 'lock_open' }}</span>
      </button>
      <!-- Duplicate -->
      <button
        @click.stop="store.selectItem(item.id); store.duplicateSelectedItems(30, 30)"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
        title="Duplicate"
      >
        <span class="material-symbols-rounded text-[16px]">content_copy</span>
      </button>
      <!-- Delete -->
      <button
        @click.stop="$emit('delete')"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-red-500 transition-colors"
        title="Delete"
      >
        <span class="material-symbols-rounded text-[16px]">delete</span>
      </button>
    </div>

    <!-- Box Model Overlay — Chrome DevTools-style padding (green) + margin (orange) guides -->
    <template v-if="boxModelOverlay">
      <!-- Margin bands (orange, outside the element boundary) -->
      <template v-if="boxModelOverlay.hasMar">
        <!-- Top margin -->
        <div v-if="boxModelOverlay.mt || boxModelOverlay.mtAuto" class="absolute pointer-events-none z-20" :style="{
          top: boxModelOverlay.mtAuto ? '-18px' : (-boxModelOverlay.mt + 'px'),
          left: -boxModelOverlay.ml + 'px', right: -boxModelOverlay.mr + 'px',
          height: boxModelOverlay.mtAuto ? '18px' : (boxModelOverlay.mt + 'px'),
          background: boxModelOverlay.mtAuto ? 'repeating-linear-gradient(45deg, rgba(246,178,107,0.2), rgba(246,178,107,0.2) 4px, transparent 4px, transparent 8px)' : 'rgba(246,178,107,0.35)',
          borderTop: boxModelOverlay.mtAuto ? '1px dashed rgba(246,178,107,0.6)' : 'none',
        }">
          <span class="absolute inset-0 flex items-center justify-center text-orange-800 dark:text-orange-200 font-mono select-none" :style="{ fontSize: boxModelOverlay.mtAuto ? '9px' : Math.min(10, boxModelOverlay.mt - 2) + 'px', opacity: boxModelOverlay.mtAuto || boxModelOverlay.mt >= 8 ? 1 : 0 }">{{ boxModelOverlay.mtAuto ? 'auto' : boxModelOverlay.mt }}</span>
        </div>
        <!-- Bottom margin -->
        <div v-if="boxModelOverlay.mb || boxModelOverlay.mbAuto" class="absolute pointer-events-none z-20" :style="{
          bottom: boxModelOverlay.mbAuto ? '-18px' : (-boxModelOverlay.mb + 'px'),
          left: -boxModelOverlay.ml + 'px', right: -boxModelOverlay.mr + 'px',
          height: boxModelOverlay.mbAuto ? '18px' : (boxModelOverlay.mb + 'px'),
          background: boxModelOverlay.mbAuto ? 'repeating-linear-gradient(45deg, rgba(246,178,107,0.2), rgba(246,178,107,0.2) 4px, transparent 4px, transparent 8px)' : 'rgba(246,178,107,0.35)',
          borderBottom: boxModelOverlay.mbAuto ? '1px dashed rgba(246,178,107,0.6)' : 'none',
        }">
          <span class="absolute inset-0 flex items-center justify-center text-orange-800 dark:text-orange-200 font-mono select-none" :style="{ fontSize: boxModelOverlay.mbAuto ? '9px' : Math.min(10, boxModelOverlay.mb - 2) + 'px', opacity: boxModelOverlay.mbAuto || boxModelOverlay.mb >= 8 ? 1 : 0 }">{{ boxModelOverlay.mbAuto ? 'auto' : boxModelOverlay.mb }}</span>
        </div>
        <!-- Left margin -->
        <div v-if="boxModelOverlay.ml || boxModelOverlay.mlAuto" class="absolute pointer-events-none z-20" :style="{
          top: 0, bottom: 0,
          left: boxModelOverlay.mlAuto ? '-18px' : (-boxModelOverlay.ml + 'px'),
          width: boxModelOverlay.mlAuto ? '18px' : (boxModelOverlay.ml + 'px'),
          background: boxModelOverlay.mlAuto ? 'repeating-linear-gradient(45deg, rgba(246,178,107,0.2), rgba(246,178,107,0.2) 4px, transparent 4px, transparent 8px)' : 'rgba(246,178,107,0.35)',
          borderLeft: boxModelOverlay.mlAuto ? '1px dashed rgba(246,178,107,0.6)' : 'none',
        }">
          <span class="absolute inset-0 flex items-center justify-center text-orange-800 dark:text-orange-200 font-mono select-none" :style="{ fontSize: boxModelOverlay.mlAuto ? '9px' : Math.min(10, boxModelOverlay.ml - 2) + 'px', opacity: boxModelOverlay.mlAuto || boxModelOverlay.ml >= 8 ? 1 : 0, writingMode: 'vertical-rl' }">{{ boxModelOverlay.mlAuto ? 'auto' : boxModelOverlay.ml }}</span>
        </div>
        <!-- Right margin -->
        <div v-if="boxModelOverlay.mr || boxModelOverlay.mrAuto" class="absolute pointer-events-none z-20" :style="{
          top: 0, bottom: 0,
          right: boxModelOverlay.mrAuto ? '-18px' : (-boxModelOverlay.mr + 'px'),
          width: boxModelOverlay.mrAuto ? '18px' : (boxModelOverlay.mr + 'px'),
          background: boxModelOverlay.mrAuto ? 'repeating-linear-gradient(45deg, rgba(246,178,107,0.2), rgba(246,178,107,0.2) 4px, transparent 4px, transparent 8px)' : 'rgba(246,178,107,0.35)',
          borderRight: boxModelOverlay.mrAuto ? '1px dashed rgba(246,178,107,0.6)' : 'none',
        }">
          <span class="absolute inset-0 flex items-center justify-center text-orange-800 dark:text-orange-200 font-mono select-none" :style="{ fontSize: boxModelOverlay.mrAuto ? '9px' : Math.min(10, boxModelOverlay.mr - 2) + 'px', opacity: boxModelOverlay.mrAuto || boxModelOverlay.mr >= 8 ? 1 : 0, writingMode: 'vertical-rl' }">{{ boxModelOverlay.mrAuto ? 'auto' : boxModelOverlay.mr }}</span>
        </div>
      </template>
      <!-- Padding bands (green, inside the element boundary) -->
      <template v-if="boxModelOverlay.hasPad">
        <!-- Top padding -->
        <div v-if="boxModelOverlay.pt" class="absolute pointer-events-none z-20" :style="{
          top: 0, left: boxModelOverlay.pl + 'px', right: boxModelOverlay.pr + 'px',
          height: boxModelOverlay.pt + 'px',
          background: 'rgba(147,196,125,0.4)',
        }">
          <span class="absolute inset-0 flex items-center justify-center text-green-900 dark:text-green-200 font-mono select-none" :style="{ fontSize: Math.min(10, boxModelOverlay.pt - 2) + 'px', opacity: boxModelOverlay.pt >= 8 ? 1 : 0 }">{{ boxModelOverlay.pt }}</span>
        </div>
        <!-- Bottom padding -->
        <div v-if="boxModelOverlay.pb" class="absolute pointer-events-none z-20" :style="{
          bottom: 0, left: boxModelOverlay.pl + 'px', right: boxModelOverlay.pr + 'px',
          height: boxModelOverlay.pb + 'px',
          background: 'rgba(147,196,125,0.4)',
        }">
          <span class="absolute inset-0 flex items-center justify-center text-green-900 dark:text-green-200 font-mono select-none" :style="{ fontSize: Math.min(10, boxModelOverlay.pb - 2) + 'px', opacity: boxModelOverlay.pb >= 8 ? 1 : 0 }">{{ boxModelOverlay.pb }}</span>
        </div>
        <!-- Left padding -->
        <div v-if="boxModelOverlay.pl" class="absolute pointer-events-none z-20" :style="{
          top: 0, bottom: 0, left: 0,
          width: boxModelOverlay.pl + 'px',
          background: 'rgba(147,196,125,0.4)',
        }">
          <span class="absolute inset-0 flex items-center justify-center text-green-900 dark:text-green-200 font-mono select-none" :style="{ fontSize: Math.min(10, boxModelOverlay.pl - 2) + 'px', opacity: boxModelOverlay.pl >= 8 ? 1 : 0, writingMode: 'vertical-rl' }">{{ boxModelOverlay.pl }}</span>
        </div>
        <!-- Right padding -->
        <div v-if="boxModelOverlay.pr" class="absolute pointer-events-none z-20" :style="{
          top: 0, bottom: 0, right: 0,
          width: boxModelOverlay.pr + 'px',
          background: 'rgba(147,196,125,0.4)',
        }">
          <span class="absolute inset-0 flex items-center justify-center text-green-900 dark:text-green-200 font-mono select-none" :style="{ fontSize: Math.min(10, boxModelOverlay.pr - 2) + 'px', opacity: boxModelOverlay.pr >= 8 ? 1 : 0, writingMode: 'vertical-rl' }">{{ boxModelOverlay.pr }}</span>
        </div>
      </template>
    </template>

    <!-- Blur containment wrapper — clips filter: blur() output within object bounds.
         Without this, CSS filter blur visually bleeds past the element edges because
         the root item needs overflow:visible for handles/action-bar. -->
    <div :style="blurWrapperStyle">

    <!-- ========================== -->
    <!-- LOW-DETAIL PLACEHOLDER     -->
    <!-- At very low zoom (<20%), render a simplified version instead of full content -->
    <!-- ========================== -->
    <div
      v-if="isLowDetail && !selected"
      class="rounded-lg h-full w-full overflow-hidden"
      :style="{
        backgroundColor: lodColor,
        minHeight: '20px',
        opacity: 0.92,
        borderRadius: (item.type === 'shape' && (shapeType === 'circle' || shapeType === 'ellipse')) ? '50%' : undefined,
        clipPath: (item.type === 'shape' && SHAPE_CLIP_PATHS[shapeType]) ? SHAPE_CLIP_PATHS[shapeType] : undefined,
        WebkitClipPath: (item.type === 'shape' && SHAPE_CLIP_PATHS[shapeType]) ? SHAPE_CLIP_PATHS[shapeType] : undefined,
      }"
    >
      <!-- Slide: dashed border (presentation camera view) -->
      <div
        v-if="item.type === 'slide'"
        class="absolute top-0 left-0 right-0 bottom-0 rounded-lg border-2 border-dashed"
        :style="{ borderColor: slideColor || '#6366f1' }"
      />
      <!-- Frame: solid border (layout container) -->
      <div
        v-else-if="item.type === 'frame'"
        class="absolute top-0 left-0 right-0 bottom-0 rounded-lg border border-surface-300 dark:border-surface-600"
      />
      <!-- Legacy artboard (deprecated, kept for backward compat) -->
      <div
        v-else-if="item.type === 'artboard'"
        class="absolute top-0 left-0 right-0 bottom-0 bg-white shadow-md"
        :style="{ borderRadius: (item.style_data?.radius || 0) + 'px' }"
      />
      <!-- Image: show actual thumbnail at low zoom -->
      <img
        v-else-if="item.type === 'image' && item.image_url"
        :src="smartImageSrc || item.image_url"
        class="absolute inset-0 w-full h-full"
        :style="{ borderRadius: (item.style_data?.border_radius ?? 8) + 'px', objectFit: item.style_data?.image_fit || 'cover' }"
        draggable="false"
        decoding="async"
        loading="eager"
      />
      <!-- Image set: show first image as preview -->
      <img
        v-else-if="item.type === 'image_set' && lodSetImage"
        :src="lodSetImage"
        class="absolute inset-0 w-full h-full object-cover"
        draggable="false"
        decoding="async"
        loading="eager"
      />
      <!-- Shape/pen_shape with mask image: show the mask image -->
      <img
        v-else-if="(item.type === 'shape' || item.type === 'pen_shape') && item.style_data?.mask_image_url"
        :src="item.style_data.mask_image_url"
        class="absolute inset-0 w-full h-full object-cover"
        :style="{
          borderRadius: shapeType === 'circle' || shapeType === 'ellipse' ? '50%' : (item.type === 'shape' ? (item.style_data?.border_radius ?? 8) + 'px' : '0px'),
          clipPath: SHAPE_CLIP_PATHS[shapeType] || undefined,
          WebkitClipPath: SHAPE_CLIP_PATHS[shapeType] || undefined,
        }"
        draggable="false"
        decoding="async"
        loading="eager"
      />
      <!-- Note: show color with title snippet -->
      <div
        v-else-if="item.type === 'note' && item.title"
        class="absolute inset-0 flex items-start p-1 overflow-hidden"
      >
        <span class="text-[6px] font-semibold leading-tight truncate" :style="{ color: noteTextColor }">{{ item.title }}</span>
      </div>
    </div>
    
    <!-- ========================== -->
    <!-- FULL & MEDIUM DETAIL       -->
    <!-- ========================== -->
    <!-- STICKY NOTE -->
    <div v-else-if="item.type === 'note'" class="rounded-xl h-full shadow-sm overflow-hidden" :style="{ backgroundColor: item.color || '#fef3c7' }">
      <div
        class="p-4 h-full origin-top-left"
        :style="{
          transform: `scale(${contentScale})`,
          width: (100 / contentScale) + '%',
          height: (100 / contentScale) + '%'
        }"
      >
        <!-- @mousedown.stop prevents drag-move while selecting text -->
        <div v-if="editing" class="h-full" @mousedown.stop>
          <input
            v-if="editingTitle"
            v-model="editTitle"
            @blur="saveTitle"
            @keydown.enter="saveTitle"
            class="w-full font-semibold bg-transparent border-none focus:outline-none mb-1"
            :style="{ color: noteTextColor, fontSize: currentFontSize + 'px' }"
            placeholder="Title"
            ref="titleInput"
          />
          <textarea
            v-model="editContent"
            @blur="saveContent"
            class="w-full h-[calc(100%-24px)] bg-transparent border-none focus:outline-none resize-none"
            :style="{ color: noteTextColor, fontSize: currentFontSize + 'px' }"
            placeholder="Write something..."
            ref="contentInput"
          />
        </div>
        <div v-else>
          <p v-if="item.title" class="font-semibold mb-1 break-words" :style="{ color: noteTextColor, fontSize: currentFontSize + 'px' }">{{ item.title }}</p>
          <p v-if="isFullDetail" class="break-words whitespace-pre-wrap" :style="{ color: noteTextColor + 'cc', fontSize: currentFontSize + 'px' }">{{ item.content || 'Double-click to edit' }}</p>
          <p v-else class="break-words line-clamp-2" :style="{ color: noteTextColor + 'cc', fontSize: currentFontSize + 'px' }">{{ item.content || '' }}</p>
        </div>
      </div>
    </div>
    
    <!-- TEXT BLOCK -->
    <div
      v-else-if="item.type === 'text'"
      class="h-full overflow-visible"
      :class="{ 'draw-on-text-reveal': drawOnActive }"
      :style="{ padding: (item.style_data?.text_padding ?? 12) + 'px', ...(drawOnActive ? { '--text-draw-dur': textDrawOnDuration } : {}) }"
    >
      <!-- @mousedown.stop prevents drag-move while selecting text -->
      <div v-if="editing" class="h-full relative" @mousedown.stop>
        <div
          ref="contentInput"
          contenteditable="true"
          @blur="saveRichContent"
          @keydown.escape.stop="saveRichContent"
          @mouseup="onRichTextMouseUp"
          @keyup="onRichTextMouseUp"
          class="block w-full min-h-full h-auto bg-transparent border-none focus:outline-none text-surface-900 dark:text-surface-100 outline-none whitespace-pre-wrap break-words overflow-hidden"
          :style="textEditStyle"
          data-placeholder="Type text..."
        ></div>
        <!-- Inline format bar for selected text -->
        <Teleport to="body">
          <MoodInlineFormatBar
            v-if="showInlineFormatBar && inlineBarPosition"
            :style="{ position: 'fixed', top: inlineBarPosition.top + 'px', left: inlineBarPosition.left + 'px', zIndex: 999999 }"
            @format-color="applyInlineColor"
            @format-font="applyInlineFont"
            @format-bold="applyInlineCommand('bold')"
            @format-italic="applyInlineCommand('italic')"
            @format-underline="applyInlineCommand('underline')"
            @format-clear="clearInlineFormatting"
          />
        </Teleport>
      </div>
      <div
        v-else
        class="break-words h-full text-surface-900 dark:text-surface-100"
        :class="isMediumDetail ? 'line-clamp-4 overflow-hidden' : 'whitespace-pre-wrap overflow-visible'"
        :style="textDisplayStyle"
        v-html="richDisplayContent"
      ></div>
    </div>

    <!-- SHAPE (acts as clipping mask when an image is applied) -->
    <div
      v-else-if="item.type === 'shape'"
      class="w-full h-full relative"
      :style="shapeStyle"
      @dragover.prevent
      @drop.prevent.stop="onShapeDrop"
    >
      <!-- SVG border overlay for clip-path shapes (triangle, star) -->
      <svg
        v-if="shapeType === 'triangle' && shapeBorderWidth > 0"
        class="absolute inset-0 w-full h-full pointer-events-none z-20"
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
      >
        <polygon
          points="50,0 0,100 100,100"
          fill="none"
          :stroke="shapeBorderColor"
          :stroke-width="shapeBorderWidth * (100 / Math.min(item.width || 200, item.height || 200))"
          vector-effect="non-scaling-stroke"
        />
      </svg>
      <svg
        v-if="shapeType === 'star' && shapeBorderWidth > 0"
        class="absolute inset-0 w-full h-full pointer-events-none z-20"
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
      >
        <polygon
          points="50,0 61,35 98,35 68,57 79,91 50,70 21,91 32,57 2,35 39,35"
          fill="none"
          :stroke="shapeBorderColor"
          :stroke-width="shapeBorderWidth * (100 / Math.min(item.width || 200, item.height || 200))"
          vector-effect="non-scaling-stroke"
        />
      </svg>
      <!-- Masked image inside the shape -->
      <img
        v-if="shapeMaskImage"
        :src="shapeMaskImage"
        class="absolute inset-0 w-full h-full pointer-events-none"
        :style="{ objectFit: item.style_data?.mask_image_fit || 'cover' }"
        draggable="false"
        decoding="async"
        alt=""
      />
      <!-- Editable text inside shape — @mousedown.stop prevents drag-move while selecting text -->
      <div v-if="editing" class="absolute inset-0 flex justify-center p-2 z-10"
        :style="{ alignItems: item.style_data?.shape_text_valign || 'center' }"
        @mousedown.stop
      >
        <textarea
          v-model="editContent"
          @blur="saveContent"
          @keydown.escape.stop="saveContent"
          class="w-full bg-transparent border-none focus:outline-none resize-none text-center"
          :style="shapeTextStyle"
          placeholder="Type text..."
          ref="contentInput"
        />
      </div>
      <div
        v-else-if="item.content"
        class="absolute inset-0 flex justify-center p-2 pointer-events-none z-10 whitespace-pre-wrap break-words"
        :style="{ ...shapeTextStyle, alignItems: item.style_data?.shape_text_valign || 'center' }"
      >
        {{ item.content }}
      </div>
      <!-- Drop hint when no image and no text yet -->
      <div
        v-if="!shapeMaskImage && !item.content && !editing && !maskedChildren.length && selected && isFullDetail"
        class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-60 transition-opacity pointer-events-none"
      >
        <span class="material-symbols-rounded text-xl text-white drop-shadow">add_photo_alternate</span>
      </div>
      <!-- Clipping mask children (rendered inside shape with overflow:hidden) -->
      <template v-if="maskedChildren.length">
        <div
          v-for="child in maskedChildren"
          :key="'mask-' + child.id"
          class="absolute pointer-events-none"
          :style="{
            left: (child.style_data?.mask_offset_x || 0) + 'px',
            top: (child.style_data?.mask_offset_y || 0) + 'px',
            width: (child.width || 240) + 'px',
            height: child.height ? child.height + 'px' : 'auto',
            transform: child.rotation ? `rotate(${child.rotation}deg)` : undefined,
          }"
        >
          <!-- Image child -->
          <img
            v-if="child.type === 'image' && child.image_url"
            :src="child.image_url"
            class="w-full h-full object-cover"
            draggable="false"
            decoding="async"
          />
          <!-- Text child -->
          <div
            v-else-if="child.type === 'text'"
            class="w-full h-full"
            :class="ICON_FONTS.has(child.style_data?.font_family) ? '' : 'whitespace-pre-wrap break-words'"
            :style="childTextStyle(child)"
          >{{ child.content || '' }}</div>
          <!-- Video child -->
          <video
            v-else-if="child.type === 'video' && (child.url || child.image_url)"
            :src="child.url || child.image_url"
            class="w-full h-full object-cover"
            muted
            autoplay
            loop
            playsinline
          />
          <!-- Drawing child -->
          <div
            v-else-if="child.type === 'drawing'"
            class="w-full h-full"
            v-html="drawingChildSvg(child)"
          />
          <!-- Generic fallback -->
          <div v-else class="w-full h-full flex items-center justify-center text-white/50">
            <span class="material-symbols-rounded text-2xl">{{ child.type === 'note' ? 'sticky_note_2' : 'layers' }}</span>
          </div>
        </div>
      </template>
      <!-- Mask indicator badge -->
      <div
        v-if="maskedChildren.length && selected && isFullDetail"
        class="absolute top-1 left-1 bg-black/60 text-white text-[9px] px-1.5 py-0.5 rounded-full z-20 pointer-events-none flex items-center gap-1"
      >
        <span class="material-symbols-rounded text-[11px]">content_cut</span>
        {{ maskedChildren.length }} masked
      </div>
    </div>

    <!-- PEN SHAPE (custom vector path drawn with pen tool — acts as clipping mask like shapes) -->
    <div
      v-else-if="item.type === 'pen_shape'"
      class="w-full h-full overflow-hidden relative"
      :style="penShapeWrapperStyle"
      @dragover.prevent
      @drop.prevent.stop="onShapeDrop"
    >
      <svg
        class="absolute inset-0 w-full h-full"
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
      >
        <defs>
          <clipPath :id="'pen-clip-' + item.id">
            <path :d="item.style_data?.pen_svg_path || ''" />
          </clipPath>
        </defs>
        <!-- Shape fill (only when no mask image — otherwise the image covers everything) -->
        <path
          :d="item.style_data?.pen_svg_path || ''"
          :fill="item.style_data?.mask_image_url ? 'transparent' : (item.style_data?.shape_fill || '#6366f1')"
          :fill-opacity="(item.style_data?.shape_opacity ?? 100) / 100"
          :stroke="item.style_data?.shape_border_color || '#4f46e5'"
          :stroke-width="(item.style_data?.shape_border_width ?? 2) * (100 / Math.max(item.width || 100, 1))"
          stroke-linejoin="round"
          stroke-linecap="round"
        />
      </svg>
      <!-- Mask image rendered as HTML img for correct aspect ratio handling.
           Uses a separate SVG clipPath with objectBoundingBox units (0-1 range)
           so CSS object-fit works correctly without SVG coordinate distortion. -->
      <svg v-if="item.style_data?.mask_image_url" class="absolute" style="width: 0; height: 0; overflow: hidden;">
        <defs>
          <clipPath :id="'pen-clip-bb-' + item.id" clipPathUnits="objectBoundingBox">
            <path :d="item.style_data?.pen_svg_path || ''" transform="scale(0.01)" />
          </clipPath>
        </defs>
      </svg>
      <img
        v-if="item.style_data?.mask_image_url"
        :src="item.style_data.mask_image_url"
        class="absolute inset-0 w-full h-full pointer-events-none"
        :style="{ objectFit: item.style_data?.mask_image_fit || 'cover', clipPath: 'url(#pen-clip-bb-' + item.id + ')' }"
        draggable="false"
        decoding="async"
        alt=""
      />
      <!-- Drop hint when no mask image and no masked children yet -->
      <div
        v-if="!item.style_data?.mask_image_url && !maskedChildren.length && selected && isFullDetail"
        class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-60 transition-opacity pointer-events-none"
      >
        <span class="material-symbols-rounded text-xl text-white drop-shadow">add_photo_alternate</span>
      </div>
      <!-- Clipping mask children (rendered inside pen shape with overflow:hidden) -->
      <template v-if="maskedChildren.length">
        <div
          v-for="child in maskedChildren"
          :key="'pmask-' + child.id"
          class="absolute pointer-events-none"
          :style="{
            left: (child.style_data?.mask_offset_x || 0) + 'px',
            top: (child.style_data?.mask_offset_y || 0) + 'px',
            width: (child.width || 240) + 'px',
            height: child.height ? child.height + 'px' : 'auto',
          }"
        >
          <img v-if="child.type === 'image' && child.image_url" :src="child.image_url" class="w-full h-full object-cover" draggable="false" decoding="async" />
          <div v-else-if="child.type === 'text'" class="w-full h-full" :class="ICON_FONTS.has(child.style_data?.font_family) ? '' : 'whitespace-pre-wrap break-words'" :style="childTextStyle(child)">{{ child.content || '' }}</div>
          <div v-else class="w-full h-full flex items-center justify-center text-white/50"><span class="material-symbols-rounded text-2xl">layers</span></div>
        </div>
      </template>
      <!-- Mask indicator badge -->
      <div
        v-if="maskedChildren.length && selected && isFullDetail"
        class="absolute top-1 left-1 bg-black/60 text-white text-[9px] px-1.5 py-0.5 rounded-full z-20 pointer-events-none flex items-center gap-1"
      >
        <span class="material-symbols-rounded text-[11px]">content_cut</span>
        {{ maskedChildren.length }} masked
      </div>
    </div>
    
    <!-- IMAGE -->
    <div
      v-else-if="item.type === 'image'"
      class="relative overflow-hidden h-full flex items-center justify-center"
      :class="isTransparentFormat ? '' : 'bg-surface-100 dark:bg-surface-800'"
      :style="{ borderRadius: (item.style_data?.border_radius ?? 12) + 'px' }"
    >
      <img
        v-if="item.image_url"
        :src="smartImageSrc || item.image_url"
        :alt="item.title || 'Image'"
        class="w-full h-full"
        :style="{ objectFit: isSvgImage ? undefined : (item.style_data?.image_fit || 'cover') }"
        draggable="false"
        decoding="async"
        loading="eager"
      />
      <div v-else class="flex flex-col items-center justify-center h-full min-h-[120px] text-surface-400 gap-2 p-4">
        <span class="material-symbols-rounded text-3xl">image</span>
        <span class="text-xs">Double-click to add image</span>
      </div>
      <div v-if="item.title && isFullDetail" class="absolute bottom-0 left-0 right-0 bg-black/50 text-white text-xs p-2 truncate image-name-label">
        {{ item.title }}
      </div>
    </div>
    
    <!-- LINK -->
    <div v-else-if="item.type === 'link'" class="rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 p-3 h-full">
      <!-- Editing mode — @mousedown.stop prevents drag-move while interacting -->
      <div v-if="editing" class="flex flex-col gap-2" @click.stop @mousedown.stop>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-500 flex-shrink-0">link</span>
          <input
            ref="titleInput"
            v-model="editTitle"
            @keydown.enter="$refs.urlInput?.focus()"
            @click.stop
            class="flex-1 text-sm font-medium bg-transparent border-b border-primary-300 dark:border-primary-600 focus:border-primary-500 focus:outline-none text-surface-900 dark:text-surface-100 px-1 py-0.5"
            placeholder="Link title..."
          />
        </div>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-surface-300 flex-shrink-0">public</span>
          <input
            ref="urlInput"
            v-model="editUrl"
            @keydown.enter="saveLink"
            @click.stop
            class="flex-1 text-xs bg-transparent border-b border-surface-300 dark:border-surface-600 focus:border-primary-500 focus:outline-none text-primary-500 px-1 py-0.5"
            placeholder="https://..."
          />
        </div>
        <div class="flex justify-end gap-1.5 mt-1">
          <button @click.stop="editing = false" class="text-xs px-2 py-1 rounded-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-300">Cancel</button>
          <button @click.stop="saveLink" class="text-xs px-3 py-1 rounded-lg bg-primary-500 text-white hover:bg-primary-600">Save</button>
        </div>
      </div>
      <!-- Display mode -->
      <div v-else class="flex items-start gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500 mt-0.5 flex-shrink-0">link</span>
        <div class="min-w-0 flex-1">
          <p v-if="item.title" class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ item.title }}</p>
          <a
            v-if="isFullDetail && item.url"
            :href="item.url"
            target="_blank"
            @click.stop
            class="text-xs text-primary-500 hover:underline truncate block mt-0.5"
          >{{ item.url }}</a>
          <p v-if="!item.title && !item.url && isFullDetail" class="text-sm text-surface-400 italic">Double-click to add link</p>
        </div>
      </div>
    </div>
    
    <!-- CHECKLIST (Todo list) -->
    <div v-else-if="item.type === 'todo_list'" class="rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 p-3 h-full">
      <p v-if="item.title" class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-2">{{ item.title }}</p>
      <div class="space-y-1.5">
        <label
          v-for="todo in (item.todos || [])"
          :key="todo.id"
          class="flex items-center gap-2 text-sm cursor-pointer"
          @click.stop
        >
          <button
            @click="toggleTodo(todo)"
            :class="[
              'w-5 h-5 rounded-md border-2 flex items-center justify-center transition-colors flex-shrink-0',
              todo.completed
                ? 'bg-primary-500 border-primary-500 text-white'
                : 'border-surface-300 dark:border-surface-600 hover:border-primary-400'
            ]"
          >
            <span v-if="todo.completed" class="material-symbols-rounded text-xs">check</span>
          </button>
          <span :class="['flex-1', todo.completed ? 'line-through text-surface-400' : 'text-surface-700 dark:text-surface-300']">
            {{ todo.text }}
          </span>
        </label>
      </div>
      <div v-if="isFullDetail" class="mt-2">
        <input
          v-model="newTodoText"
          @keydown.enter="addTodo"
          @click.stop
          class="w-full text-xs px-2 py-1.5 rounded-lg bg-surface-50 dark:bg-surface-700 border-none text-surface-700 dark:text-surface-300 placeholder-surface-400"
          placeholder="Add item..."
        />
      </div>
    </div>
    
    <!-- COLOR SWATCH (clickable to open color picker) -->
    <div
      v-else-if="item.type === 'color_swatch'"
      class="rounded-xl h-full flex flex-col shadow-sm cursor-pointer"
      :style="{ backgroundColor: item.color || '#6366f1' }"
      @dblclick.stop="$emit('open-color-picker', item)"
    >
      <div class="mt-auto px-2 pb-2 space-y-0.5">
        <span class="block text-xs font-mono px-2 py-0.5 rounded bg-black/20 text-white/90 text-center">
          {{ item.color || '#6366f1' }}
        </span>
        <template v-if="isFullDetail">
          <div v-if="item.color_data" class="flex gap-1 justify-center">
            <span v-if="item.color_data.rgb" class="text-[9px] font-mono text-white/60">
              R{{ item.color_data.rgb.r }} G{{ item.color_data.rgb.g }} B{{ item.color_data.rgb.b }}
            </span>
          </div>
          <div v-if="item.color_data?.cmyk" class="text-center">
            <span class="text-[9px] font-mono text-white/60">
              C{{ item.color_data.cmyk.c }} M{{ item.color_data.cmyk.m }} Y{{ item.color_data.cmyk.y }} K{{ item.color_data.cmyk.k }}
            </span>
          </div>
        </template>
      </div>
    </div>
    
    <!-- FILE (from Drive or upload) -->
    <div v-else-if="item.type === 'file'" class="rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 h-full flex flex-col overflow-hidden group/file">
      <!-- File thumbnail / icon area (fills entire card) -->
      <div class="flex-1 flex items-center justify-center bg-surface-50 dark:bg-surface-700/30 relative min-h-0">
        <!-- Thumbnail for images (SVGs get no object-fit so they scale to fill) -->
        <img
          v-if="fileThumbnailUrl"
          :src="fileThumbnailUrl"
          :class="isSvgImage ? 'w-full h-full' : 'w-full h-full object-cover'"
          draggable="false"
          decoding="async"
        />
        <!-- Icon for non-image files -->
        <div v-else class="flex flex-col items-center gap-1">
          <span class="material-symbols-rounded text-5xl" :style="{ color: getFileIconColor(item) }">{{ getFileIcon(item) }}</span>
          <span class="text-[10px] text-surface-400 uppercase font-medium tracking-wider">{{ getFileExtension(item) }}</span>
        </div>
        <!-- Hover overlay: filename + actions (hidden at medium zoom for cleaner view) -->
        <div v-if="isFullDetail" class="absolute inset-0 bg-black/0 group-hover/file:bg-black/40 transition-colors flex flex-col items-center justify-center opacity-0 group-hover/file:opacity-100">
          <!-- Filename bar at top -->
          <div class="absolute top-0 left-0 right-0 px-3 py-2 flex items-center gap-2 bg-gradient-to-b from-black/60 to-transparent">
            <span class="material-symbols-rounded text-sm text-white/80 flex-shrink-0">{{ getFileIcon(item) }}</span>
            <p class="text-xs font-medium text-white truncate">{{ item.title || 'File' }}</p>
          </div>
          <!-- Action buttons centered -->
          <div class="flex items-center gap-3">
            <button
              @click.stop="$emit('preview-file', item)"
              class="w-9 h-9 rounded-full bg-white/90 hover:bg-white text-surface-800 shadow-lg transition-all hover:scale-110 flex items-center justify-center"
              title="Preview"
            >
              <span class="material-symbols-rounded text-[18px]">visibility</span>
            </button>
            <button
              v-if="canEditFileInCollab(item)"
              @click.stop="$emit('edit-file-collab', item)"
              class="w-9 h-9 rounded-full bg-primary-500/90 hover:bg-primary-500 text-white shadow-lg transition-all hover:scale-110 flex items-center justify-center"
              title="Edit"
            >
              <span class="material-symbols-rounded text-[18px]">edit_document</span>
            </button>
          </div>
          <!-- File extension badge at bottom -->
          <div class="absolute bottom-2 right-2">
            <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-white/20 text-white backdrop-blur-sm">{{ getFileExtension(item) }}</span>
          </div>
        </div>
      </div>
    </div>
    
    <!-- IMAGE SET (multi-image grid) -->
    <MoodImageSet
      v-else-if="item.type === 'image_set'"
      :item="item"
      @remove-image="(imgId) => $emit('remove-image-from-set', imgId)"
      @add-images="$emit('add-images-to-set', item)"
      @drop-files="(files) => $emit('drop-files-to-set', { item, files })"
    />
    
    <!-- CALENDAR EVENT -->
    <div v-else-if="item.type === 'calendar_event'" class="rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 p-3 h-full">
      <div class="flex items-start gap-2">
        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :style="{ backgroundColor: (item.style_data?.event_color || '#3b82f6') + '20' }">
          <span class="material-symbols-rounded text-lg" :style="{ color: item.style_data?.event_color || '#3b82f6' }">event</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate">{{ item.title || 'Event' }}</p>
          <p v-if="item.content" class="text-xs text-surface-500 mt-0.5">{{ item.content }}</p>
          <p v-if="item.style_data?.event_location" class="text-xs text-surface-400 mt-1 flex items-center gap-1">
            <span class="material-symbols-rounded text-xs">location_on</span>
            {{ item.style_data.event_location }}
          </p>
        </div>
      </div>
    </div>
    
    <!-- FOLDER (drive folder link) -->
    <div
      v-else-if="item.type === 'folder'"
      class="rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 h-full flex flex-col overflow-hidden cursor-pointer group/folder"
      @dblclick.stop="$emit('browse-folder', item)"
    >
      <!-- Header -->
      <div class="px-3 py-2 border-b border-surface-100 dark:border-surface-700 flex items-center gap-2 flex-shrink-0">
        <span class="material-symbols-rounded text-xl text-amber-500">folder</span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate">{{ item.title || 'Folder' }}</p>
          <p v-if="folderData?.path" class="text-[10px] text-surface-400 truncate">{{ folderData.path }}</p>
        </div>
      </div>
      <!-- Preview of folder contents (file count + thumbnails) -->
      <div class="flex-1 flex items-center justify-center bg-surface-50 dark:bg-surface-700/30 relative">
        <!-- Thumbnail grid preview (if we have cached previews) -->
        <div v-if="folderData?.preview_count" class="flex flex-col items-center gap-1 py-3">
          <span class="material-symbols-rounded text-4xl text-amber-400/60">folder_open</span>
          <span class="text-xs text-surface-500">{{ folderData.preview_count }} items</span>
        </div>
        <div v-else class="flex flex-col items-center gap-1 py-3">
          <span class="material-symbols-rounded text-4xl text-amber-400/60">folder_open</span>
          <span class="text-[10px] text-surface-400">Double-click to browse</span>
        </div>
        <!-- Hover overlay (full detail only) -->
        <div v-if="isFullDetail" class="absolute inset-0 bg-black/0 group-hover/folder:bg-black/30 transition-colors flex items-center justify-center opacity-0 group-hover/folder:opacity-100">
          <button
            @click.stop="$emit('browse-folder', item)"
            class="px-4 py-2 rounded-full bg-white/90 hover:bg-white text-surface-800 shadow-lg transition-all hover:scale-105 flex items-center gap-2 text-sm font-medium"
          >
            <span class="material-symbols-rounded text-lg">folder_open</span>
            Browse
          </button>
        </div>
      </div>
    </div>

    <!-- DRAWING (movable SVG strokes) -->
    <div
      v-else-if="item.type === 'drawing'"
      class="h-full w-full overflow-hidden rounded-lg transition-colors"
      :class="selected ? 'bg-surface-100/30 dark:bg-surface-700/30' : 'hover:bg-surface-100/20 dark:hover:bg-surface-700/20'"
      :style="{ outline: selected ? '' : '1px dashed transparent', outlineOffset: '-1px' }"
      @mouseenter="$el.style.outline = selected ? '' : '1px dashed rgba(148,163,184,0.4)'"
      @mouseleave="$el.style.outline = '1px dashed transparent'"
    >
      <!-- SVG stroke rendering when we have stroke data in content -->
      <svg
        v-if="drawingStrokeData?.strokes?.length"
        class="w-full h-full"
        :viewBox="`0 0 ${drawingViewBox.w} ${drawingViewBox.h}`"
        preserveAspectRatio="xMidYMid meet"
      >
        <!-- Invisible fill rect for hit area (so white drawings can be clicked) -->
        <rect x="0" y="0" :width="drawingViewBox.w" :height="drawingViewBox.h" fill="transparent" />

        <!-- Draw-on animation: show center-line strokes being "drawn", hide filled paths -->
        <template v-if="drawOnActive && drawOnStrokeAnimations.length">
          <path
            v-for="(anim, ai) in drawOnStrokeAnimations"
            :key="'drawon-' + ai"
            :d="anim.centerLinePath"
            fill="none"
            :stroke="anim.color"
            :stroke-width="anim.width"
            stroke-linecap="round"
            stroke-linejoin="round"
            :stroke-dasharray="anim.pathLength"
            :stroke-dashoffset="anim.pathLength"
            class="draw-on-stroke"
            :style="{
              '--draw-len': anim.pathLength,
              '--draw-dur': anim.duration + 's',
              '--draw-delay': anim.delay + 's'
            }"
          />
        </template>

        <!-- Normal filled strokes (hidden during draw-on, visible after) -->
        <template v-else>
          <path
            v-for="(stroke, si) in drawingStrokeData.strokes.filter(s => s.svgPath)"
            :key="'ds-' + si"
            :d="stroke.svgPath"
            :fill="stroke.color || '#000000'"
            stroke-linecap="round"
            stroke-linejoin="round"
            :class="{ 'draw-on-fade-in': drawOnPlayed && drawOnEligible }"
          />
        </template>
      </svg>
      <!-- Fallback: old image-based drawing or empty placeholder -->
      <img
        v-else-if="item.image_url"
        :src="item.image_url"
        :alt="item.title || 'Drawing'"
        class="w-full h-full object-contain"
        draggable="false"
        decoding="async"
      />
      <div v-else class="flex flex-col items-center justify-center h-full text-surface-400 gap-1 p-4">
        <span class="material-symbols-rounded text-3xl">draw</span>
        <span class="text-xs">Drawing</span>
      </div>
    </div>
    
    <!-- TABLE -->
    <div
      v-else-if="item.type === 'table'"
      class="rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 overflow-hidden h-full flex flex-col"
    >
      <!-- Title bar -->
      <div class="flex items-center gap-2 px-3 py-2 bg-surface-50 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700">
        <span class="material-symbols-rounded text-sm text-surface-400">table_chart</span>
        <span v-if="!editingTableTitle" @dblclick.stop="startEditTableTitle" class="text-xs font-semibold text-surface-700 dark:text-surface-300 truncate flex-1">{{ item.title || 'Table' }}</span>
        <input
          v-else
          v-model="editTitle"
          @blur="saveTitle"
          @keydown.enter="saveTitle"
          ref="titleInput"
          class="text-xs font-semibold bg-transparent border-none focus:outline-none text-surface-700 dark:text-surface-300 flex-1"
        />
        <div v-if="isFullDetail" class="flex items-center gap-0.5">
          <button @click.stop="addTableColumn" class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400" title="Add column">
            <span class="material-symbols-rounded text-xs">add</span>
          </button>
          <button @click.stop="addTableRow" class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400" title="Add row">
            <span class="material-symbols-rounded text-xs">playlist_add</span>
          </button>
        </div>
      </div>
      <!-- Table content -->
      <div class="flex-1 overflow-auto" @mousedown.stop @pointerdown.stop>
        <table class="w-full text-xs border-collapse">
          <thead>
            <tr>
              <th
                v-for="(col, ci) in tableData.columns"
                :key="'th-' + ci"
                class="px-2 py-1.5 text-left font-semibold text-surface-600 dark:text-surface-300 bg-surface-50 dark:bg-surface-700/30 border-b border-r border-surface-200 dark:border-surface-600"
              >
                <input
                  :value="col"
                  @input="updateTableHeader(ci, $event.target.value)"
                  @click.stop
                  class="w-full bg-transparent border-none focus:outline-none text-xs font-semibold"
                />
              </th>
              <th class="w-6 bg-surface-50 dark:bg-surface-700/30 border-b border-surface-200 dark:border-surface-600"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, ri) in tableData.rows" :key="'tr-' + ri" class="group/row hover:bg-surface-50 dark:hover:bg-surface-700/20">
              <td
                v-for="(cell, ci) in row"
                :key="'td-' + ri + '-' + ci"
                class="px-2 py-1 border-b border-r border-surface-100 dark:border-surface-700"
              >
                <input
                  :value="cell"
                  @input="updateTableCell(ri, ci, $event.target.value)"
                  @click.stop
                  class="w-full bg-transparent border-none focus:outline-none text-xs text-surface-700 dark:text-surface-300"
                  placeholder="..."
                />
              </td>
              <td class="w-6 border-b border-surface-100 dark:border-surface-700 text-center">
                <button
                  @click.stop="removeTableRow(ri)"
                  class="opacity-0 group-hover/row:opacity-100 text-surface-400 hover:text-red-500 transition-opacity"
                  title="Remove row"
                >
                  <span class="material-symbols-rounded text-xs">close</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- COLUMN -->
    <div
      v-else-if="item.type === 'column'"
      class="rounded-2xl flex flex-col"
      :style="{
        backgroundColor: item.color || undefined,
        minHeight: '200px',
        border: item.color ? '2px dashed ' + item.color + '80' : undefined
      }"
      :class="[
        !item.color ? 'bg-surface-100 dark:bg-surface-800 border-2 border-dashed border-surface-300 dark:border-surface-600' : ''
      ]"
      @dragover.prevent.stop="onColumnDragOver"
      @dragleave="onColumnDragLeave"
      @drop.prevent.stop="onColumnDrop"
    >
      <!-- Column header -->
      <div class="px-4 py-3 text-center border-b border-surface-200/60 dark:border-surface-600/60" :style="item.color ? { borderColor: item.color + '40' } : {}">
        <span v-if="!editingTitle" @dblclick.stop="onDoubleClick" class="text-sm font-bold text-surface-800 dark:text-surface-200">
          {{ item.title || 'New Column' }}
        </span>
        <input
          v-else
          v-model="editTitle"
          @blur="saveTitle"
          @keydown.enter="saveTitle"
          ref="titleInput"
          class="w-full text-sm font-bold text-center bg-transparent border-none focus:outline-none text-surface-800 dark:text-surface-200"
        />
        <p class="text-xs text-surface-400 mt-0.5">{{ columnChildCount }} {{ columnChildCount === 1 ? 'card' : 'cards' }}</p>
      </div>
      <!-- Column body — child items rendered here by MoodCanvas -->
      <div
        class="flex-1 p-3 space-y-3 column-body"
        :class="{ 'bg-primary-50/30 dark:bg-primary-900/10': columnDragHover }"
        :data-column-id="item.id"
      >
        <!-- Child items are rendered inside by MoodCanvas via slot/teleport -->
        <slot />
        <!-- Empty state -->
        <div v-if="columnChildCount === 0" class="flex flex-col items-center justify-center py-8 text-surface-400">
          <span class="material-symbols-rounded text-2xl mb-1">add_card</span>
          <span class="text-xs">Drag cards here</span>
        </div>
      </div>
    </div>

    <!-- BOARD LINK (Board or Card) -->
    <div
      v-else-if="item.type === 'board_link'"
      class="rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 h-full overflow-hidden"
    >
      <div
        class="flex flex-col origin-top-left"
        :style="{
          transform: `scale(${contentScale})`,
          width: (100 / contentScale) + '%',
          height: (100 / contentScale) + '%'
        }"
      >
      <!-- Header bar with board color -->
      <div class="px-3 py-2 flex items-center gap-2 flex-shrink-0" :style="{ backgroundColor: (item.color || '#6366f1') + '15' }">
        <span class="material-symbols-rounded text-lg" :style="{ color: item.color || '#6366f1' }">
          {{ boardLinkData?.card_data ? 'credit_card' : 'dashboard' }}
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate" :style="{ fontSize: currentFontSize + 'px' }">
            {{ item.title || 'Board' }}
          </p>
          <p class="text-[10px] text-surface-500 truncate">
            <template v-if="boardLinkData?.card_data">
              {{ boardLinkData.card_data.board_name }} · {{ boardLinkData.card_data.list_name }}
            </template>
            <template v-else-if="boardLinkData?.board_data">
              {{ boardLinkData.board_data.total_cards || 0 }} cards · {{ boardLinkData.board_data.lists?.length || 0 }} lists
            </template>
            <template v-else>Linked board</template>
          </p>
        </div>
      </div>

      <!-- ===== CARD VIEW ===== -->
      <template v-if="boardLinkData?.card_data">
        <!-- Card labels (hide at medium zoom) -->
        <div v-if="boardLinkData.card_data.labels?.length && isFullDetail" class="px-3 pt-2 flex flex-wrap gap-1 flex-shrink-0">
          <span
            v-for="label in boardLinkData.card_data.labels.slice(0, 5)"
            :key="label.id"
            class="px-1.5 py-0.5 rounded text-[10px] font-medium text-white"
            :style="{ backgroundColor: label.color }"
          >{{ label.name || '' }}</span>
        </div>

        <!-- Card description (truncated at medium zoom) -->
        <p v-if="boardLinkData.card_data.description" class="px-3 pt-2 text-xs text-surface-600 dark:text-surface-400" :class="isFullDetail ? 'line-clamp-3' : 'line-clamp-1'">
          {{ boardLinkData.card_data.description }}
        </p>

        <!-- Checklist with progress bar (full detail only) -->
        <div v-if="isFullDetail && boardLinkData.card_data.checklist_items?.length" class="px-3 pt-2 flex-1 overflow-auto min-h-0">
          <!-- Progress header -->
          <div class="flex items-center gap-2 mb-2">
            <span class="material-symbols-rounded text-sm text-surface-400">checklist</span>
            <span class="text-[10px] font-semibold text-surface-500 uppercase tracking-wider">TODO</span>
            <span class="text-[10px] text-surface-400 ml-auto">{{ checklistProgress.percent }}%</span>
          </div>
          <!-- Progress bar -->
          <div class="h-1.5 rounded-full bg-surface-200 dark:bg-surface-700 mb-2.5 overflow-hidden">
            <div
              class="h-full rounded-full transition-all duration-300"
              :style="{ width: checklistProgress.percent + '%', backgroundColor: checklistProgress.percent === 100 ? '#22c55e' : (item.color || '#6366f1') }"
            />
          </div>
          <!-- Checklist items -->
          <div class="space-y-0.5">
            <div
              v-for="(task, idx) in boardLinkData.card_data.checklist_items.slice(0, 15)"
              :key="idx"
              class="flex items-center gap-2 py-0.5 text-xs"
            >
              <div
                class="w-4 h-4 rounded flex items-center justify-center flex-shrink-0"
                :class="task.completed
                  ? 'bg-primary-500 text-white'
                  : 'border border-surface-300 dark:border-surface-600'"
              >
                <span v-if="task.completed" class="material-symbols-rounded text-[11px]">check</span>
              </div>
              <span
                :class="task.completed ? 'line-through text-surface-400 dark:text-surface-500' : 'text-surface-700 dark:text-surface-300'"
                class="truncate leading-snug"
              >{{ task.text }}</span>
            </div>
            <p v-if="boardLinkData.card_data.checklist_items.length > 15" class="text-[10px] text-surface-400 pl-6 pt-0.5">
              +{{ boardLinkData.card_data.checklist_items.length - 15 }} more
            </p>
          </div>
        </div>

        <!-- Footer meta (card) -->
        <div class="px-3 py-2 border-t border-surface-100 dark:border-surface-700 flex items-center gap-3 text-[10px] text-surface-400 mt-auto flex-shrink-0">
          <span v-if="boardLinkData.card_data.due_date" class="flex items-center gap-0.5" :class="boardLinkCardOverdue ? 'text-red-500' : ''">
            <span class="material-symbols-rounded text-xs">schedule</span>
            {{ formatBoardLinkDate(boardLinkData.card_data.due_date) }}
          </span>
          <span v-if="checklistProgress.total > 0" class="flex items-center gap-0.5">
            <span class="material-symbols-rounded text-xs">checklist</span>
            {{ checklistProgress.done }}/{{ checklistProgress.total }}
          </span>
          <span v-if="boardLinkData.card_data.assignees?.length" class="flex items-center gap-0.5">
            <span class="material-symbols-rounded text-xs">person</span>
            {{ boardLinkData.card_data.assignees.length }}
          </span>
          <span v-if="boardLinkData.card_data.attachment_count" class="flex items-center gap-0.5">
            <span class="material-symbols-rounded text-xs">attach_file</span>
            {{ boardLinkData.card_data.attachment_count }}
          </span>
        </div>
      </template>

      <!-- ===== BOARD VIEW (Kanban-style card list per list) ===== -->
      <template v-else-if="boardLinkData?.board_data">
        <div class="flex-1 overflow-auto min-h-0 px-2 pt-2 pb-1">
          <div
            v-for="list in boardLinkData.board_data.lists?.slice(0, isFullDetail ? 8 : 3)"
            :key="list.id"
            class="mb-2.5"
          >
            <!-- List header -->
            <div class="flex items-center gap-1.5 px-1 mb-1.5">
              <span class="text-[10px] font-bold text-surface-600 dark:text-surface-300 uppercase tracking-wider truncate">{{ list.name }}</span>
              <span class="text-[9px] text-surface-400 bg-surface-100 dark:bg-surface-700 rounded-full px-1.5 py-0.5 flex-shrink-0">{{ list.card_count || list.cards?.length || 0 }}</span>
            </div>
            <!-- Kanban-style cards -->
            <div v-if="list.cards?.length" class="space-y-1">
              <div
                v-for="card in list.cards.slice(0, 6)"
                :key="card.id"
                class="px-2.5 py-2 rounded-lg bg-surface-50 dark:bg-surface-900/60 border border-surface-100 dark:border-surface-700/50"
              >
                <!-- Card labels -->
                <div v-if="card.labels?.length" class="flex gap-1 mb-1.5">
                  <div
                    v-for="label in card.labels.slice(0, 3)"
                    :key="label.id"
                    class="h-1.5 w-6 rounded-full"
                    :style="{ backgroundColor: label.color }"
                    :title="label.name"
                  />
                </div>
                <!-- Card title -->
                <p class="text-xs font-medium text-surface-800 dark:text-surface-200 leading-snug truncate">{{ card.title }}</p>
                <!-- Card meta row -->
                <div v-if="card.due_date || card.checklist_total > 0 || card.assignees?.length || card.description" class="flex items-center gap-2 mt-1.5 text-[10px] text-surface-400">
                  <span v-if="card.description" class="material-symbols-rounded text-[11px]" title="Has description">subject</span>
                  <span v-if="card.due_date" class="flex items-center gap-0.5">
                    <span class="material-symbols-rounded text-[11px]">schedule</span>
                    {{ formatBoardLinkDate(card.due_date) }}
                  </span>
                  <span v-if="card.checklist_total > 0" class="flex items-center gap-0.5" :class="card.checklist_done === card.checklist_total ? 'text-emerald-500' : ''">
                    <span class="material-symbols-rounded text-[11px]">checklist</span>
                    {{ card.checklist_done || 0 }}/{{ card.checklist_total }}
                  </span>
                  <!-- Assignee avatars -->
                  <div v-if="card.assignees?.length" class="flex -space-x-1 ml-auto">
                    <div
                      v-for="(a, ai) in card.assignees.slice(0, 2)"
                      :key="ai"
                      class="w-4 h-4 rounded-full bg-primary-500 flex items-center justify-center text-white text-[8px] font-semibold border border-white dark:border-surface-900"
                    >{{ (a.name || a.email || '?').charAt(0).toUpperCase() }}</div>
                  </div>
                </div>
              </div>
              <p v-if="list.cards.length > 6" class="text-[9px] text-surface-400 px-1 pt-0.5">+{{ list.cards.length - 6 }} more cards</p>
            </div>
            <p v-else class="text-[10px] text-surface-400 px-1 italic">No cards</p>
          </div>
        </div>

        <!-- Footer summary -->
        <div class="px-3 py-1.5 border-t border-surface-100 dark:border-surface-700 flex items-center gap-3 text-[10px] text-surface-400 flex-shrink-0">
          <span class="flex items-center gap-0.5">
            <span class="material-symbols-rounded text-xs">view_list</span>
            {{ boardLinkData.board_data.lists?.length || 0 }} lists
          </span>
          <span class="flex items-center gap-0.5">
            <span class="material-symbols-rounded text-xs">credit_card</span>
            {{ boardLinkData.board_data.total_cards || 0 }} cards
          </span>
        </div>
      </template>

      <!-- Fallback for no data -->
      <div v-else class="flex-1 flex items-center justify-center p-4 text-surface-400">
        <div class="text-center">
          <span class="material-symbols-rounded text-2xl">dashboard</span>
          <p class="text-xs mt-1">Linked board</p>
        </div>
      </div>
      </div><!-- end scaling wrapper -->
    </div>
    
    <!-- VIDEO (uploaded or URL) -->
    <div
      v-else-if="item.type === 'video'"
      class="rounded-xl bg-black overflow-hidden h-full flex flex-col"
    >
      <!-- Editing mode — @mousedown.stop prevents drag-move while interacting -->
      <div v-if="editing" class="flex flex-col gap-2 p-3 bg-surface-900" @click.stop @mousedown.stop>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-400 flex-shrink-0">videocam</span>
          <input
            ref="titleInput"
            v-model="editTitle"
            @keydown.enter="$refs.videoUrlInput?.focus()"
            @click.stop
            class="flex-1 text-sm font-medium bg-transparent border-b border-surface-600 focus:border-primary-500 focus:outline-none text-white px-1 py-0.5"
            placeholder="Video title..."
          />
        </div>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-surface-500 flex-shrink-0">link</span>
          <input
            ref="videoUrlInput"
            v-model="editUrl"
            @keydown.enter="saveVideoUrl"
            @click.stop
            class="flex-1 text-xs bg-transparent border-b border-surface-600 focus:border-primary-500 focus:outline-none text-primary-400 px-1 py-0.5"
            placeholder="Video URL (mp4, webm, ogg)..."
          />
        </div>
        <div class="flex justify-between items-center mt-1">
          <label class="flex items-center gap-2 text-xs text-surface-400 cursor-pointer hover:text-surface-200 transition-colors">
            <span class="material-symbols-rounded text-base">upload_file</span>
            Upload file
            <input
              type="file"
              accept="video/*"
              class="hidden"
              @change="onVideoFileSelect"
              @click.stop
            />
          </label>
          <div class="flex gap-1.5">
            <button @click.stop="editing = false" class="text-xs px-2 py-1 rounded-lg text-surface-400 hover:text-surface-200">Cancel</button>
            <button @click.stop="saveVideoUrl" class="text-xs px-3 py-1 rounded-lg bg-primary-500 text-white hover:bg-primary-600">Save</button>
          </div>
        </div>
      </div>
      <!-- Display mode -->
      <template v-else>
        <video
          v-if="item.url || item.image_url"
          :src="item.url || item.image_url"
          class="w-full h-full object-contain bg-black"
          controls
          preload="metadata"
          @click.stop
          :poster="item.style_data?.poster || ''"
        />
        <div v-else class="flex flex-col items-center justify-center h-full min-h-[120px] text-surface-500 gap-2 p-4 bg-surface-900">
          <span class="material-symbols-rounded text-4xl">videocam</span>
          <span class="text-xs">Double-click to add video</span>
        </div>
        <div v-if="item.title && isFullDetail" class="absolute bottom-0 left-0 right-0 bg-black/70 text-white text-xs p-2 truncate image-name-label">
          {{ item.title }}
        </div>
      </template>
    </div>

    <!-- YOUTUBE EMBED -->
    <div
      v-else-if="item.type === 'youtube'"
      class="rounded-xl bg-black overflow-hidden h-full flex flex-col"
    >
      <!-- Editing mode — @mousedown.stop prevents drag-move while interacting -->
      <div v-if="editing" class="flex flex-col gap-2 p-3 bg-surface-900" @click.stop @mousedown.stop>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-red-500 flex-shrink-0">smart_display</span>
          <input
            ref="titleInput"
            v-model="editTitle"
            @keydown.enter="$refs.youtubeUrlInput?.focus()"
            @click.stop
            class="flex-1 text-sm font-medium bg-transparent border-b border-surface-600 focus:border-primary-500 focus:outline-none text-white px-1 py-0.5"
            placeholder="Video title..."
          />
        </div>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-surface-500 flex-shrink-0">link</span>
          <input
            ref="youtubeUrlInput"
            v-model="editUrl"
            @keydown.enter="saveVideoUrl"
            @click.stop
            class="flex-1 text-xs bg-transparent border-b border-surface-600 focus:border-primary-500 focus:outline-none text-primary-400 px-1 py-0.5"
            placeholder="YouTube URL (e.g. https://youtube.com/watch?v=...)..."
          />
        </div>
        <div class="flex justify-end gap-1.5 mt-1">
          <button @click.stop="editing = false" class="text-xs px-2 py-1 rounded-lg text-surface-400 hover:text-surface-200">Cancel</button>
          <button @click.stop="saveVideoUrl" class="text-xs px-3 py-1 rounded-lg bg-primary-500 text-white hover:bg-primary-600">Save</button>
        </div>
      </div>
      <!-- Display mode -->
      <template v-else>
        <template v-if="youtubeEmbedId">
          <div class="relative w-full h-full">
            <iframe
              :src="`https://www.youtube.com/embed/${youtubeEmbedId}?rel=0`"
              class="w-full h-full border-0"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowfullscreen
            />
            <!-- Transparent overlay to allow move / resize / select — iframe swallows pointer events otherwise.
                 Only removed when BOTH interactive mode is on AND item is already selected, so first click always selects. -->
            <div
              v-if="!(youtubeInteractive && selected)"
              class="absolute inset-0 z-10"
            />
          </div>
        </template>
        <div v-else class="flex flex-col items-center justify-center h-full min-h-[120px] text-surface-500 gap-2 p-4 bg-surface-900">
          <span class="material-symbols-rounded text-4xl text-red-500">smart_display</span>
          <span class="text-xs">Double-click to add YouTube link</span>
        </div>
        <div v-if="item.title && isFullDetail && !youtubeEmbedId" class="absolute bottom-0 left-0 right-0 bg-black/70 text-white text-xs p-2 truncate image-name-label">
          {{ item.title }}
        </div>
      </template>
    </div>

    <!-- AUDIO PLAYER -->
    <div
      v-else-if="item.type === 'audio'"
      class="rounded-2xl overflow-hidden h-full flex flex-col relative"
    >
      <!-- "Hidden in presentation" badge (edit mode only) -->
      <div
        v-if="item.style_data?.audio_hidden_in_pres && !store.presentationMode && isFullDetail"
        class="absolute top-1.5 right-1.5 z-10 flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-black/50 text-white/70 text-[9px] font-medium backdrop-blur-sm"
        title="Hidden during presentation"
      >
        <span class="material-symbols-rounded" style="font-size:11px">visibility_off</span>
        Hidden in pres.
      </div>
      <!-- Editing mode (double-click) — set URL / upload file -->
      <div v-if="editing" class="flex flex-col gap-2 p-3 h-full" :style="{ backgroundColor: item.style_data?.audio_bg || '#1e1b2e' }" @click.stop @mousedown.stop>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-400 flex-shrink-0">graphic_eq</span>
          <input
            ref="titleInput"
            v-model="editTitle"
            @keydown.enter="$refs.audioUrlInput?.focus()"
            @click.stop
            class="flex-1 text-sm font-medium bg-transparent border-b border-surface-600 focus:border-primary-500 focus:outline-none text-white px-1 py-0.5"
            placeholder="Audio title..."
          />
        </div>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-surface-500 flex-shrink-0">link</span>
          <input
            ref="audioUrlInput"
            v-model="editUrl"
            @keydown.enter="saveAudioUrl"
            @click.stop
            class="flex-1 text-xs bg-transparent border-b border-surface-600 focus:border-primary-500 focus:outline-none text-primary-400 px-1 py-0.5"
            placeholder="Audio URL (mp3, wav, ogg, m4a)..."
          />
        </div>
        <div class="flex justify-between items-center mt-1">
          <label class="flex items-center gap-2 text-xs text-surface-400 cursor-pointer hover:text-surface-200 transition-colors">
            <span class="material-symbols-rounded text-base">upload_file</span>
            Upload file
            <input
              type="file"
              accept="audio/*"
              class="hidden"
              @change="onAudioFileSelect"
              @click.stop
            />
          </label>
          <div class="flex gap-1.5">
            <button @click.stop="editing = false" class="text-xs px-2 py-1 rounded-lg text-surface-400 hover:text-surface-200">Cancel</button>
            <button @click.stop="saveAudioUrl" class="text-xs px-3 py-1 rounded-lg bg-primary-500 text-white hover:bg-primary-600">Save</button>
          </div>
        </div>
      </div>
      <!-- Display mode — player UI -->
      <template v-else>
        <MoodAudioPlayer
          v-if="item.url"
          ref="audioPlayerRef"
          :src="item.url"
          :title="item.title || ''"
          :volume="(item.style_data?.audio_volume ?? 80) / 100"
          :loop="!!item.style_data?.audio_loop"
          :autoplay="false"
          :compact="(item.height || 120) < 100"
          :accentColor="item.style_data?.audio_accent || '#6366f1'"
          :bgColor="item.style_data?.audio_bg || '#1e1b2e'"
          :textColor="item.style_data?.audio_text || '#e2e8f0'"
        />
        <div v-else class="flex flex-col items-center justify-center h-full min-h-[80px] text-surface-500 gap-2 p-4" :style="{ backgroundColor: item.style_data?.audio_bg || '#1e1b2e' }">
          <span class="material-symbols-rounded text-4xl" style="color: #6366f1;">graphic_eq</span>
          <span class="text-xs" style="color: #94a3b8;">Double-click to add audio</span>
        </div>
      </template>
    </div>

    <!-- FRAME / SECTION -->
    <!-- LINE (straight line with configurable style) -->
    <div
      v-else-if="item.type === 'line'"
      class="w-full h-full"
      style="background: transparent;"
    >
      <svg
        class="w-full h-full"
        :viewBox="`0 0 ${item.width || 200} ${item.height || 20}`"
        preserveAspectRatio="none"
        overflow="visible"
      >
        <!-- Arrow marker definitions -->
        <defs>
          <marker
            :id="'line-arrow-end-' + item.id"
            markerWidth="10" markerHeight="8"
            refX="9" refY="4"
            orient="auto"
          >
            <path d="M 0 0 L 10 4 L 0 8 Z" :fill="lineStyle.color" />
          </marker>
          <marker
            :id="'line-arrow-start-' + item.id"
            markerWidth="10" markerHeight="8"
            refX="1" refY="4"
            orient="auto"
          >
            <path d="M 10 0 L 0 4 L 10 8 Z" :fill="lineStyle.color" />
          </marker>
        </defs>
        <!-- Glow line (blurred duplicate behind the crisp line) -->
        <line
          v-if="lineGlow.enabled"
          :x1="lineStyle.x1" :y1="lineStyle.y1"
          :x2="lineStyle.x2" :y2="lineStyle.y2"
          :stroke="lineGlow.color"
          :stroke-width="lineStyle.width + lineGlow.blur * 2"
          :stroke-opacity="lineGlow.opacity"
          stroke-linecap="round"
          class="pointer-events-none"
          :style="{ filter: `blur(${lineGlow.blur}px)` }"
        />
        <!-- Invisible wide hit area for easy selection -->
        <line
          :x1="lineStyle.x1" :y1="lineStyle.y1"
          :x2="lineStyle.x2" :y2="lineStyle.y2"
          stroke="transparent"
          :stroke-width="Math.max(12, lineStyle.width + 8)"
          stroke-linecap="round"
        />
        <!-- Visible line -->
        <line
          :x1="lineStyle.x1" :y1="lineStyle.y1"
          :x2="lineStyle.x2" :y2="lineStyle.y2"
          :stroke="lineStyle.color"
          :stroke-width="lineStyle.width"
          :stroke-dasharray="lineStyle.dashArray"
          stroke-linecap="round"
          :marker-end="lineStyle.arrowEnd ? `url(#line-arrow-end-${item.id})` : ''"
          :marker-start="lineStyle.arrowStart ? `url(#line-arrow-start-${item.id})` : ''"
        />
        <!-- Endpoint handles (only when selected) -->
        <template v-if="selected">
          <circle :cx="lineStyle.x1" :cy="lineStyle.y1" r="5" :fill="lineStyle.color" stroke="white" stroke-width="2" class="cursor-move" />
          <circle :cx="lineStyle.x2" :cy="lineStyle.y2" r="5" :fill="lineStyle.color" stroke="white" stroke-width="2" class="cursor-move" />
        </template>
      </svg>
    </div>

    <!-- SLIDE: presentation camera view (dashed border, 16:9) -->
    <div
      v-else-if="item.type === 'slide'"
      class="h-full w-full relative"
      :style="slideStyle"
    >
      <!-- Center-mark indicators (midpoint lines on each edge) -->
      <template v-if="isFullDetail || isMediumDetail">
        <!-- Top center -->
        <div class="absolute pointer-events-none" :style="{ left: '50%', top: '0', transform: 'translateX(-0.5px)', width: '1px', height: '8px', backgroundColor: slideColor, opacity: 0.5 }" />
        <!-- Bottom center -->
        <div class="absolute pointer-events-none" :style="{ left: '50%', bottom: '0', transform: 'translateX(-0.5px)', width: '1px', height: '8px', backgroundColor: slideColor, opacity: 0.5 }" />
        <!-- Left center -->
        <div class="absolute pointer-events-none" :style="{ top: '50%', left: '0', transform: 'translateY(-0.5px)', height: '1px', width: '8px', backgroundColor: slideColor, opacity: 0.5 }" />
        <!-- Right center -->
        <div class="absolute pointer-events-none" :style="{ top: '50%', right: '0', transform: 'translateY(-0.5px)', height: '1px', width: '8px', backgroundColor: slideColor, opacity: 0.5 }" />
      </template>

      <!-- Slide title label (positioned above, outside the slide body) -->
      <div
        v-if="isFullDetail || isMediumDetail"
        class="absolute left-0 flex items-center gap-2 pointer-events-none select-none"
        :style="{ top: '-22px' }"
      >
        <span class="text-[11px] font-semibold whitespace-nowrap" :style="{ color: slideColor }">
          {{ item.title || 'Slide' }}
        </span>
        <span v-if="isFullDetail" class="text-[10px] text-surface-400 dark:text-surface-500 tabular-nums">
          {{ item.width }} x {{ item.height }}
        </span>
        <!-- Slide number badge -->
        <span
          v-if="item.slide_order != null"
          class="inline-flex items-center gap-0.5 px-1.5 py-0 rounded-full text-white text-[9px] font-bold"
          :style="{ backgroundColor: slideColor }"
        >
          <span class="material-symbols-rounded" style="font-size: 10px;">slideshow</span>
          {{ (item.slide_order ?? 0) + 1 }}
        </span>
      </div>
    </div>

    <!-- FRAME: Figma-style layout container (solid border, auto-layout) -->
    <div
      v-else-if="item.type === 'frame'"
      class="h-full w-full relative"
      :style="frameStyle"
    >
      <!-- Frame title label (positioned above, outside the frame body) -->
      <div
        v-if="(isFullDetail || isMediumDetail)"
        class="absolute left-0 flex items-center gap-2 pointer-events-none select-none"
        :style="{ top: '-22px' }"
      >
        <span class="text-[11px] font-semibold whitespace-nowrap text-surface-600 dark:text-surface-400">
          {{ item.title || item.style_data?.frame_device || 'Artboard' }}
        </span>
        <span v-if="isFullDetail" class="text-[10px] text-surface-400 dark:text-surface-500 tabular-nums">
          {{ item.width }} x {{ item.height }}
        </span>
      </div>

      <!-- LOD placeholder when frame too small on screen -->
      <div v-if="skipChildren" class="w-full h-full flex items-center justify-center overflow-hidden">
        <span v-if="item.title" class="text-[8px] text-surface-400 truncate px-1 select-none">{{ item.title }}</span>
      </div>

      <!-- Auto-layout container -->
      <div
        v-else-if="item.style_data?.auto_layout"
        class="w-full h-full"
        :style="autoLayoutStyle"
      >
        <!-- Slot from MoodCanvas for top-level frames; fallback: self-render for nested -->
        <slot name="frame-children">
          <MoodCanvasItemSelf
            v-for="child in frameChildren"
            :key="'fc-' + child.id"
            :item="child"
            :selected="!props.readonly && store.selectedItemIds.has(child.id)"
            :multi-selected="store.selectedItemIds.size > 1"
            :connecting="!!store.connectingFrom"
            :zoom-tier="zoomTier"
            :dimmed="!!store.focusedItemId && store.focusedItemId !== child.id"
            :readonly="props.readonly"
            :ancestor-scale="totalScale"
            :parent-pos="{ x: child.pos_x || 0, y: child.pos_y || 0 }"
            :in-auto-layout="true"
            :style="getAutoLayoutChildStyle(child, item.style_data?.layout_direction || 'column')"
            @mousedown.stop="frameChildHandlers?.onItemMouseDown($event, child)"
            @connection-start="frameChildHandlers?.onConnectionStart(child)"
            @connection-end="frameChildHandlers?.onConnectionEnd(child)"
            @update="(data, opts) => store.updateItem(child.id, data, opts || {})"
            @delete="store.deleteItem(child.id)"
            @contextmenu.prevent.stop="frameChildHandlers?.emitItemContext($event, child)"
            @open-color-picker="(c) => frameChildHandlers?.emitOpenColorPicker(c)"
            @edit-drawing="(c) => frameChildHandlers?.emitEditDrawing(c)"
            @preview-file="(c) => frameChildHandlers?.emitPreviewFile(c)"
            @edit-file-collab="(c) => frameChildHandlers?.emitEditFileCollab(c)"
            @browse-folder="(c) => frameChildHandlers?.emitBrowseFolder(c)"
            @add-images-to-set="(c) => frameChildHandlers?.onAddImagesToSet(c)"
            @drop-files-to-set="({ item: c, files }) => frameChildHandlers?.onDropFilesToSet(c, files)"
            @remove-image-from-set="(imgId) => frameChildHandlers?.onRemoveImageFromSet(imgId)"
            @replace-image="(c) => frameChildHandlers?.onReplaceImage(c)"
          />
        </slot>
      </div>

      <!-- Static frame content (no auto-layout: children positioned absolutely inside the frame) -->
      <div v-else class="w-full h-full relative">
        <!-- Slot from MoodCanvas for top-level frames; fallback: self-render for nested -->
        <slot name="frame-children">
          <MoodCanvasItemSelf
            v-for="child in frameChildren"
            :key="'fc-' + child.id"
            :item="child"
            :selected="!props.readonly && store.selectedItemIds.has(child.id)"
            :multi-selected="store.selectedItemIds.size > 1"
            :connecting="!!store.connectingFrom"
            :zoom-tier="zoomTier"
            :dimmed="!!store.focusedItemId && store.focusedItemId !== child.id"
            :readonly="props.readonly"
            :ancestor-scale="totalScale"
            :parent-pos="{ x: item.pos_x || 0, y: item.pos_y || 0 }"
            style="position: absolute"
            @mousedown.stop="frameChildHandlers?.onItemMouseDown($event, child)"
            @connection-start="frameChildHandlers?.onConnectionStart(child)"
            @connection-end="frameChildHandlers?.onConnectionEnd(child)"
            @update="(data, opts) => store.updateItem(child.id, data, opts || {})"
            @delete="store.deleteItem(child.id)"
            @contextmenu.prevent.stop="frameChildHandlers?.emitItemContext($event, child)"
            @open-color-picker="(c) => frameChildHandlers?.emitOpenColorPicker(c)"
            @edit-drawing="(c) => frameChildHandlers?.emitEditDrawing(c)"
            @preview-file="(c) => frameChildHandlers?.emitPreviewFile(c)"
            @edit-file-collab="(c) => frameChildHandlers?.emitEditFileCollab(c)"
            @browse-folder="(c) => frameChildHandlers?.emitBrowseFolder(c)"
            @add-images-to-set="(c) => frameChildHandlers?.onAddImagesToSet(c)"
            @drop-files-to-set="({ item: c, files }) => frameChildHandlers?.onDropFilesToSet(c, files)"
            @remove-image-from-set="(imgId) => frameChildHandlers?.onRemoveImageFromSet(imgId)"
            @replace-image="(c) => frameChildHandlers?.onReplaceImage(c)"
          />
        </slot>

        <!-- Padding guide (shown when selected and padding > 0) -->
        <div
          v-if="selected && isFullDetail && (item.style_data?.padding || 0) > 0"
          class="absolute pointer-events-none"
          :style="{
            top: (item.style_data.padding || 0) + 'px',
            left: (item.style_data.padding || 0) + 'px',
            right: (item.style_data.padding || 0) + 'px',
            bottom: (item.style_data.padding || 0) + 'px',
            border: '1px dashed rgba(99,102,241,0.25)',
          }"
        />
      </div>
    </div>

    <!-- GROUP: invisible container, children positioned absolutely or with flex/grid layout -->
    <div
      v-else-if="item.type === 'group'"
      class="h-full w-full relative"
      :style="groupStyle"
    >
      <!-- LOD placeholder when group too small on screen -->
      <template v-if="skipChildren" />

      <!-- Flex or Grid layout container -->
      <div
        v-else-if="groupLayoutMode !== 'none'"
        class="w-full h-full relative"
        :style="groupLayoutStyle"
      >
        <slot name="frame-children">
          <MoodCanvasItemSelf
            v-for="child in frameChildren"
            :key="'gc-' + child.id"
            :item="child"
            :selected="!props.readonly && store.selectedItemIds.has(child.id)"
            :multi-selected="store.selectedItemIds.size > 1"
            :connecting="!!store.connectingFrom"
            :zoom-tier="zoomTier"
            :dimmed="!!store.focusedItemId && store.focusedItemId !== child.id"
            :readonly="props.readonly"
            :ancestor-scale="totalScale"
            :parent-pos="child.style_data?.is_background ? { x: item.pos_x || 0, y: item.pos_y || 0 } : { x: child.pos_x || 0, y: child.pos_y || 0 }"
            :in-auto-layout="!child.style_data?.is_background"
            :style="child.style_data?.is_background
              ? { position: 'absolute', inset: '0', zIndex: 0, width: '100%', height: '100%', pointerEvents: 'auto' }
              : groupLayoutMode === 'flex'
                ? getAutoLayoutChildStyle(child, item.style_data?.layout_direction || 'column')
                : getGridChildStyle(child)"
            @mousedown.stop="frameChildHandlers?.onItemMouseDown($event, child)"
            @connection-start="frameChildHandlers?.onConnectionStart(child)"
            @connection-end="frameChildHandlers?.onConnectionEnd(child)"
            @update="(data, opts) => store.updateItem(child.id, data, opts || {})"
            @delete="store.deleteItem(child.id)"
            @contextmenu.prevent.stop="frameChildHandlers?.emitItemContext($event, child)"
            @open-color-picker="(c) => frameChildHandlers?.emitOpenColorPicker(c)"
            @edit-drawing="(c) => frameChildHandlers?.emitEditDrawing(c)"
            @preview-file="(c) => frameChildHandlers?.emitPreviewFile(c)"
            @edit-file-collab="(c) => frameChildHandlers?.emitEditFileCollab(c)"
            @browse-folder="(c) => frameChildHandlers?.emitBrowseFolder(c)"
            @add-images-to-set="(c) => frameChildHandlers?.onAddImagesToSet(c)"
            @drop-files-to-set="({ item: c, files }) => frameChildHandlers?.onDropFilesToSet(c, files)"
            @remove-image-from-set="(imgId) => frameChildHandlers?.onRemoveImageFromSet(imgId)"
            @replace-image="(c) => frameChildHandlers?.onReplaceImage(c)"
          />
        </slot>
      </div>
      <!-- Absolute positioning (default) -->
      <div v-else class="w-full h-full relative">
        <slot name="frame-children">
          <MoodCanvasItemSelf
            v-for="child in frameChildren"
            :key="'gc-' + child.id"
            :item="child"
            :selected="!props.readonly && store.selectedItemIds.has(child.id)"
            :multi-selected="store.selectedItemIds.size > 1"
            :connecting="!!store.connectingFrom"
            :zoom-tier="zoomTier"
            :dimmed="!!store.focusedItemId && store.focusedItemId !== child.id"
            :readonly="props.readonly"
            :ancestor-scale="totalScale"
            :parent-pos="{ x: item.pos_x || 0, y: item.pos_y || 0 }"
            style="position: absolute"
            @mousedown.stop="frameChildHandlers?.onItemMouseDown($event, child)"
            @connection-start="frameChildHandlers?.onConnectionStart(child)"
            @connection-end="frameChildHandlers?.onConnectionEnd(child)"
            @update="(data, opts) => store.updateItem(child.id, data, opts || {})"
            @delete="store.deleteItem(child.id)"
            @contextmenu.prevent.stop="frameChildHandlers?.emitItemContext($event, child)"
            @open-color-picker="(c) => frameChildHandlers?.emitOpenColorPicker(c)"
            @edit-drawing="(c) => frameChildHandlers?.emitEditDrawing(c)"
            @preview-file="(c) => frameChildHandlers?.emitPreviewFile(c)"
            @edit-file-collab="(c) => frameChildHandlers?.emitEditFileCollab(c)"
            @browse-folder="(c) => frameChildHandlers?.emitBrowseFolder(c)"
            @add-images-to-set="(c) => frameChildHandlers?.onAddImagesToSet(c)"
            @drop-files-to-set="({ item: c, files }) => frameChildHandlers?.onDropFilesToSet(c, files)"
            @remove-image-from-set="(imgId) => frameChildHandlers?.onRemoveImageFromSet(imgId)"
            @replace-image="(c) => frameChildHandlers?.onReplaceImage(c)"
          />
        </slot>
      </div>
    </div>

    <!-- REPEAT GRID: XD-style repeating pattern container -->
    <div
      v-else-if="item.type === 'repeat_grid'"
      class="h-full w-full relative"
      style="overflow: visible"
    >
      <!-- Template cell (0,0) — fully interactive -->
      <div v-if="!skipChildren" class="absolute inset-0" style="overflow: visible">
        <slot name="frame-children">
          <MoodCanvasItemSelf
            v-for="child in frameChildren"
            :key="'rgt-' + child.id"
            :item="child"
            :selected="!props.readonly && store.selectedItemIds.has(child.id)"
            :multi-selected="store.selectedItemIds.size > 1"
            :connecting="!!store.connectingFrom"
            :zoom-tier="zoomTier"
            :dimmed="!!store.focusedItemId && store.focusedItemId !== child.id"
            :readonly="props.readonly"
            :ancestor-scale="totalScale"
            :parent-pos="{ x: item.pos_x || 0, y: item.pos_y || 0 }"
            style="position: absolute"
            @mousedown.stop="frameChildHandlers?.onItemMouseDown($event, child)"
            @connection-start="frameChildHandlers?.onConnectionStart(child)"
            @connection-end="frameChildHandlers?.onConnectionEnd(child)"
            @update="(data, opts) => store.updateItem(child.id, data, opts || {})"
            @delete="store.deleteItem(child.id)"
            @contextmenu.prevent.stop="frameChildHandlers?.emitItemContext($event, child)"
            @open-color-picker="(c) => frameChildHandlers?.emitOpenColorPicker(c)"
            @edit-drawing="(c) => frameChildHandlers?.emitEditDrawing(c)"
            @preview-file="(c) => frameChildHandlers?.emitPreviewFile(c)"
            @edit-file-collab="(c) => frameChildHandlers?.emitEditFileCollab(c)"
            @browse-folder="(c) => frameChildHandlers?.emitBrowseFolder(c)"
            @add-images-to-set="(c) => frameChildHandlers?.onAddImagesToSet(c)"
            @drop-files-to-set="({ item: c, files }) => frameChildHandlers?.onDropFilesToSet(c, files)"
            @remove-image-from-set="(imgId) => frameChildHandlers?.onRemoveImageFromSet(imgId)"
            @replace-image="(c) => frameChildHandlers?.onReplaceImage(c)"
          />
        </slot>
      </div>
      <!-- Repeated cells — visual-only copies -->
      <template v-if="rgLayout && !skipChildren">
        <div
          v-for="cell in rgLayout.cells"
          :key="'rgc-' + cell.col + '-' + cell.row"
          class="absolute"
          style="pointer-events: none; overflow: visible"
          :style="{ transform: `translate(${cell.x}px, ${cell.y}px)` }"
        >
          <MoodCanvasItemSelf
            v-for="child in frameChildren"
            :key="'rgc-' + cell.col + '-' + cell.row + '-' + child.id"
            :item="child"
            :selected="false"
            :multi-selected="false"
            :connecting="false"
            :zoom-tier="zoomTier"
            :dimmed="false"
            :readonly="true"
            :ancestor-scale="totalScale"
            :parent-pos="{ x: item.pos_x || 0, y: item.pos_y || 0 }"
            style="position: absolute; pointer-events: none"
          />
        </div>
      </template>
      <!-- Green dashed border when selected -->
      <div
        v-if="selected"
        class="absolute border-2 border-dashed border-emerald-400 pointer-events-none"
        :style="{ left: '-1px', top: '-1px', width: (item.width || 100) + 2 + 'px', height: (item.height || 100) + 2 + 'px' }"
      ></div>
      <!-- Right handle — drag to add/remove columns -->
      <div
        v-if="selected && rgLayout"
        class="absolute cursor-ew-resize rounded-r-sm"
        :style="{ right: '-10px', top: '0', width: '8px', height: '100%', background: '#34d399', opacity: 0.7, zIndex: 999 }"
        @mousedown.stop.prevent="startGridResize('col', $event)"
      ></div>
      <!-- Bottom handle — drag to add/remove rows -->
      <div
        v-if="selected && rgLayout"
        class="absolute cursor-ns-resize rounded-b-sm"
        :style="{ left: '0', bottom: '-10px', width: '100%', height: '8px', background: '#34d399', opacity: 0.7, zIndex: 999 }"
        @mousedown.stop.prevent="startGridResize('row', $event)"
      ></div>
      <!-- Column gap handles (vertical strips between columns) -->
      <template v-if="selected && rgLayout && rgLayout.cols > 1">
        <div
          v-for="ci in (rgLayout.cols - 1)"
          :key="'hgap-' + ci"
          class="absolute cursor-col-resize z-[998] group/hgap"
          :style="{
            left: (ci * (rgLayout.cellW + rgLayout.hGap) - rgLayout.hGap - 4) + 'px',
            top: '0',
            width: Math.max(8, rgLayout.hGap + 8) + 'px',
            height: '100%'
          }"
          @mousedown.stop.prevent="startGapDrag('h', $event)"
        >
          <div
            class="absolute inset-y-0 left-1/2 -translate-x-1/2 rounded-full transition-opacity bg-pink-400"
            :class="draggingGapAxis === 'h' ? 'opacity-80' : 'opacity-0 group-hover/hgap:opacity-80'"
            :style="{ width: Math.max(2, Math.min(rgLayout.hGap, 6)) + 'px' }"
          ></div>
          <div
            class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 px-1.5 py-0.5 rounded bg-pink-500 text-white text-[10px] font-semibold leading-none whitespace-nowrap pointer-events-none transition-opacity"
            :class="draggingGapAxis === 'h' ? 'opacity-100' : 'opacity-0 group-hover/hgap:opacity-100'"
            style="z-index: 1000"
          >{{ Math.round(rgLayout.hGap) }}px</div>
        </div>
      </template>
      <!-- Row gap handles (horizontal strips between rows) -->
      <template v-if="selected && rgLayout && rgLayout.rows > 1">
        <div
          v-for="ri in (rgLayout.rows - 1)"
          :key="'vgap-' + ri"
          class="absolute cursor-row-resize z-[998] group/vgap"
          :style="{
            top: (ri * (rgLayout.cellH + rgLayout.vGap) - rgLayout.vGap - 4) + 'px',
            left: '0',
            height: Math.max(8, rgLayout.vGap + 8) + 'px',
            width: '100%'
          }"
          @mousedown.stop.prevent="startGapDrag('v', $event)"
        >
          <div
            class="absolute inset-x-0 top-1/2 -translate-y-1/2 rounded-full transition-opacity bg-pink-400"
            :class="draggingGapAxis === 'v' ? 'opacity-80' : 'opacity-0 group-hover/vgap:opacity-80'"
            :style="{ height: Math.max(2, Math.min(rgLayout.vGap, 6)) + 'px' }"
          ></div>
          <div
            class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 px-1.5 py-0.5 rounded bg-pink-500 text-white text-[10px] font-semibold leading-none whitespace-nowrap pointer-events-none transition-opacity"
            :class="draggingGapAxis === 'v' ? 'opacity-100' : 'opacity-0 group-hover/vgap:opacity-100'"
            style="z-index: 1000"
          >{{ Math.round(rgLayout.vGap) }}px</div>
        </div>
      </template>
    </div>

    <!-- LEGACY ARTBOARD (deprecated -- frames replace this, kept for backward compat) -->
    <div
      v-else-if="item.type === 'artboard'"
      class="h-full w-full relative"
      :style="{ minHeight: '200px' }"
    >
      <!-- Artboard title label (positioned above, outside the artboard body) -->
      <div
        v-if="isFullDetail || isMediumDetail"
        class="absolute left-0 flex items-center gap-2 pointer-events-none select-none"
        :style="{ top: '-24px' }"
      >
        <span class="text-xs font-semibold text-surface-500 dark:text-surface-400 whitespace-nowrap">
          {{ item.title || 'Artboard' }}
        </span>
        <span class="text-[10px] text-surface-400 dark:text-surface-500 tabular-nums">
          {{ item.width }} x {{ item.height }}
        </span>
        <span
          v-if="item.style_data?.preset_name"
          class="text-[9px] text-surface-400 dark:text-surface-500 px-1.5 py-0.5 bg-surface-100 dark:bg-surface-700 rounded-full"
        >
          {{ item.style_data.preset_name }}
        </span>
      </div>
      <!-- Artboard body — white canvas area -->
      <div
        class="w-full h-full shadow-lg relative"
        :style="{
          backgroundColor: item.style_data?.artboard_bg || '#ffffff',
          borderRadius: (item.style_data?.radius || 0) + 'px',
          border: selected ? '2px solid #6366f1' : '1px solid #cbd5e1',
          overflow: item.style_data?.clip_content !== false ? 'hidden' : 'visible',
        }"
      >
        <!-- Padding guide lines (dashed inner rectangle) -->
        <div
          v-if="selected && isFullDetail && (item.style_data?.padding || 0) > 0"
          class="absolute pointer-events-none"
          :style="{
            top: (item.style_data.padding || 0) + 'px',
            left: (item.style_data.padding || 0) + 'px',
            right: (item.style_data.padding || 0) + 'px',
            bottom: (item.style_data.padding || 0) + 'px',
            border: '1px dashed rgba(99,102,241,0.3)',
            borderRadius: Math.max(0, (item.style_data?.radius || 0) - (item.style_data?.padding || 0)) + 'px',
          }"
        />
        <!-- Center crosshair (shown when selected) -->
        <template v-if="selected && isFullDetail">
          <div class="absolute left-1/2 top-0 bottom-0 w-px bg-primary-300/20 pointer-events-none" />
          <div class="absolute top-1/2 left-0 right-0 h-px bg-primary-300/20 pointer-events-none" />
        </template>
      </div>
    </div>
    
    <!-- Item toolbars moved to MoodRightSidebar -->

    </div><!-- /blur wrapper -->
  </div>
</template>

<script setup>
import { ref, computed, nextTick, watch, onMounted, onBeforeUnmount, onUnmounted, inject, defineAsyncComponent } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { snapshotAllChildren, applyAllConstraints } from '@/composables/useFrameConstraints'
import { getFrameSizingStyle, getAutoLayoutChildStyle } from '@/composables/useFrameLayout'
import { getRotationAwareCursor } from '../composables/useItemResize'
import MoodImageSet from './MoodImageSet.vue'
import MoodInlineFormatBar from './MoodInlineFormatBar.vue'
import MoodAudioPlayer from './MoodAudioPlayer.vue'
import { getStroke } from 'perfect-freehand'
import { loadGoogleFont } from '../utils/fontLoader'
import { useMoodBoardGlobalStylesStore } from '@/addons/moodboards/stores/moodBoardGlobalStyles'
import { resolveClassStyleOverrides, mergeWithClassOverrides } from '../utils/cssClassResolver'
import { normalizeSd } from '../utils/styleAdapter'
import { figmaToCssRgba } from '../utils/colorConvert'
import { EffectType } from '../utils/figmaStyleSchema'

// Self-reference for recursive nested frame rendering
const MoodCanvasItemSelf = defineAsyncComponent(() => import('./MoodCanvasItem.vue'))

const props = defineProps({
  item: { type: Object, required: true },
  selected: { type: Boolean, default: false },
  multiSelected: { type: Boolean, default: false }, // true when 2+ items are selected — hides per-item toolbars
  connecting: { type: Boolean, default: false },
  zoomTier: { type: String, default: 'full' }, // 'full' | 'medium' | 'low'
  dimmed: { type: Boolean, default: false }, // focus mode — dims non-focused items
  parentPos: { type: Object, default: null }, // { x, y } — when rendering inside a frame, subtract parent position for frame-relative coords
  inAutoLayout: { type: Boolean, default: false }, // true when rendered as a flex child inside an auto-layout frame
  readonly: { type: Boolean, default: false }, // public shared view — disables all editing interactions
  ancestorScale: { type: Number, default: 1 }, // accumulated CSS scale of ancestor items (for nested scale compensation)
})

const gsStore = useMoodBoardGlobalStylesStore()

const classOverrides = computed(() =>
  resolveClassStyleOverrides(props.item, gsStore.globalCssClasses)
)

const resolvedSd = computed(() =>
  mergeWithClassOverrides(props.item.style_data, classOverrides.value)
)

const ns = computed(() => normalizeSd(props.item.type, resolvedSd.value))

const itemColor = computed(() => {
  if (classOverrides.value?._item_color) return classOverrides.value._item_color
  return props.item.color
})

// LOD helpers — at low zoom, simplify rendering to avoid visual clutter & perf issues
const isLowDetail = computed(() => props.zoomTier === 'low')
const isMediumDetail = computed(() => props.zoomTier === 'medium')
const isFullDetail = computed(() => props.zoomTier === 'full')

// Screen-size LOD: skip rendering frame/group children when the container
// is too small on screen AND has many children. Prevents mounting thousands
// of child components for artboards that appear as thumbnails.
// Only activates for containers with >12 children to avoid hiding small
// groups (which are invisible containers -- hiding their children = blank canvas).
const skipChildren = computed(() => {
  const t = props.item.type
  if (t !== 'frame' && t !== 'group' && t !== 'repeat_grid') return false
  const children = store.getChildrenOf(props.item.id)
  if (children.length <= 12) return false
  const z = store.zoom || 1
  const s = props.ancestorScale || 1
  const screenW = (props.item.width || 0) * z * s
  const screenH = (props.item.height || 0) * z * s
  return Math.max(screenW, screenH) < 120
})

const itemScaleVal = computed(() => props.item.style_data?.item_scale || 1)
const totalScale = computed(() => (props.ancestorScale || 1) * itemScaleVal.value)

// Zoom-compensation: keep selection chrome at constant screen size.
// Compensate for both canvas zoom AND any CSS scale() on this item or ancestors
// so UI handles remain constant screen size regardless of item scale.
const uiS = computed(() => {
  const base = Math.min(1, 1 / Math.max(store.zoom, 0.1))
  return totalScale.value !== 1 ? base / totalScale.value : base
})

function edgeCursor(handle) {
  return getRotationAwareCursor(handle, props.item.rotation || 0)
}
const selectionRingStyle = computed(() => {
  if (store.presentationMode || !props.selected || props.item.type === 'line') return {}
  const rw = 2 * uiS.value
  if (props.multiSelected) {
    return { boxShadow: `0 0 0 ${rw * 0.5}px rgb(var(--color-primary-400) / 0.6)` }
  }
  return { boxShadow: `0 0 0 ${rw}px rgb(var(--color-primary-500))` }
})

// Smart image source: use thumbnail at low/medium zoom or in public view for faster loading.
// Progressively upgrades to full-res when zoomed in or selected.
const smartImageSrc = computed(() => {
  const url = props.item.image_url
  if (!url) return null
  const thumb = props.item.thumbnail_url
  // No thumbnail available — use original
  if (!thumb || thumb === url) return url
  // Always use full-res when item is selected (user is interacting)
  if (props.selected) return url
  // At full zoom, use full-res
  if (isFullDetail.value && !store.isPublicView) return url
  // Otherwise use thumbnail (low/medium zoom, or public shared view)
  return thumb
})

const emit = defineEmits([
  'mousedown', 'connection-start', 'connection-end', 'update', 'delete', 'contextmenu',
  'open-color-picker', 'remove-image-from-set', 'add-images-to-set', 'drop-files-to-set',
  'edit-drawing', 'column-drop', 'preview-file', 'edit-file-collab', 'browse-folder', 'replace-image'
])

const store = useMoodBoardsStore()

// Action bar cooldown — prevents accidental clicks (e.g. lock) when the bar
// pops up right as the user rapidly clicks an item or the rotate handle.
// The bar becomes interactive only after 300ms of stable "idle-selected" state.
// Resets on selection change AND after rotate/resize operations end.
const actionBarReady = ref(false)
let _actionBarTimer = null
function resetActionBarCooldown() {
  if (_actionBarTimer) { clearTimeout(_actionBarTimer); _actionBarTimer = null }
  actionBarReady.value = false
  if (props.selected) {
    _actionBarTimer = setTimeout(() => { actionBarReady.value = true }, 300)
  }
}
watch(() => props.selected, (sel) => {
  if (_actionBarTimer) { clearTimeout(_actionBarTimer); _actionBarTimer = null }
  if (sel) {
    actionBarReady.value = false
    _actionBarTimer = setTimeout(() => { actionBarReady.value = true }, 300)
  } else {
    actionBarReady.value = false
  }
}, { immediate: true })
onUnmounted(() => { if (_actionBarTimer) clearTimeout(_actionBarTimer) })

// Injected handlers from MoodCanvas — used for self-rendered nested frame children
const frameChildHandlers = inject('frameChildHandlers', null)
const canvasViewportBounds = inject('canvasViewportBounds', null)

// Frame children — computed from store for self-rendering (supports nested frames).
// Frozen during drag so DOM order never shifts mid-drag (prevents z-index visual jumps).
let _frozenFrameChildren = null
const frameChildren = computed(() => {
  if (store.isDragging && _frozenFrameChildren) return _frozenFrameChildren
  if (props.item.type !== 'frame' && props.item.type !== 'group' && props.item.type !== 'repeat_grid') return []
  const all = store.getChildrenOf(props.item.id)

  // Cull children outside viewport. frameX/frameY is the frame's canvas position.
  const vp = canvasViewportBounds?.value
  if (vp && all.length > 8) {
    const fx = props.item.pos_x || 0
    const fy = props.item.pos_y || 0
    const culled = all.filter(c => {
      const cx = fx + (c.pos_x || 0)
      const cy = fy + (c.pos_y || 0)
      const cw = c.width || 100
      const ch = c.height || 100
      return cx + cw >= vp.left && cx <= vp.right && cy + ch >= vp.top && cy <= vp.bottom
    })
    const result = culled.length > 0 ? culled : all
    _frozenFrameChildren = result
    return result
  }

  _frozenFrameChildren = all
  return all
})

const bgChildren = computed(() => frameChildren.value.filter(c => c.style_data?.is_background))
const layoutChildren = computed(() => frameChildren.value.filter(c => !c.style_data?.is_background))

const draggingGapAxis = ref(null) // 'h' | 'v' | null — while actively dragging a gap handle

// Repeat Grid layout — computes cell offsets for virtual copies
const rgLayout = computed(() => {
  if (props.item.type !== 'repeat_grid') return null
  const sd = props.item.style_data || {}
  const cols = sd.grid_columns || 3
  const rows = sd.grid_rows || 3
  const hGap = sd.grid_h_gap ?? 20
  const vGap = sd.grid_v_gap ?? 20

  const children = frameChildren.value
  if (!children.length) return { cols, rows, hGap, vGap, cellW: 100, cellH: 100, cells: [] }

  const gx = props.item.pos_x || 0
  const gy = props.item.pos_y || 0
  let maxRX = 0, maxRY = 0
  for (const c of children) {
    maxRX = Math.max(maxRX, (c.pos_x || 0) - gx + (c.width || 100))
    maxRY = Math.max(maxRY, (c.pos_y || 0) - gy + (c.height || 100))
  }
  const cellW = maxRX || 100
  const cellH = maxRY || 100

  const cells = []
  for (let r = 0; r < rows; r++) {
    for (let col = 0; col < cols; col++) {
      if (r === 0 && col === 0) continue
      cells.push({ col, row: r, x: col * (cellW + hGap), y: r * (cellH + vGap) })
    }
  }
  return { cols, rows, hGap, vGap, cellW, cellH, cells }
})

function startGridResize(axis, e) {
  const layout = rgLayout.value
  if (!layout) return
  const startX = e.clientX
  const startY = e.clientY
  const startWidth = props.item.width || (layout.cols * layout.cellW + (layout.cols - 1) * layout.hGap)
  const startHeight = props.item.height || (layout.rows * layout.cellH + (layout.rows - 1) * layout.vGap)

  const onMove = (me) => {
    const z = store.zoom || 1
    const sd = { ...(props.item.style_data || {}) }
    if (axis === 'col') {
      const dx = (me.clientX - startX) / z
      const newWidth = Math.max(layout.cellW, startWidth + dx)
      const step = layout.cellW + layout.hGap
      const newCols = Math.max(1, Math.round((newWidth + layout.hGap) / step))
      sd.grid_columns = newCols
      store.updateItem(props.item.id, {
        style_data: sd,
        width: newCols * layout.cellW + Math.max(0, newCols - 1) * layout.hGap,
      })
    } else {
      const dy = (me.clientY - startY) / z
      const newHeight = Math.max(layout.cellH, startHeight + dy)
      const step = layout.cellH + layout.vGap
      const newRows = Math.max(1, Math.round((newHeight + layout.vGap) / step))
      sd.grid_rows = newRows
      store.updateItem(props.item.id, {
        style_data: sd,
        height: newRows * layout.cellH + Math.max(0, newRows - 1) * layout.vGap,
      })
    }
  }

  const onUp = () => {
    window.removeEventListener('mousemove', onMove)
    window.removeEventListener('mouseup', onUp)
  }
  window.addEventListener('mousemove', onMove)
  window.addEventListener('mouseup', onUp)
}

function startGapDrag(axis, e) {
  const layout = rgLayout.value
  if (!layout) return
  draggingGapAxis.value = axis
  const startPos = axis === 'h' ? e.clientX : e.clientY
  const startGap = axis === 'h' ? layout.hGap : layout.vGap
  const gapKey = axis === 'h' ? 'grid_h_gap' : 'grid_v_gap'

  const onMove = (me) => {
    const z = store.zoom || 1
    const delta = ((axis === 'h' ? me.clientX : me.clientY) - startPos) / z
    const newGap = Math.max(0, Math.round(startGap + delta))
    const sd = { ...(props.item.style_data || {}) }
    if (sd[gapKey] === newGap) return
    sd[gapKey] = newGap
    const cols = sd.grid_columns || layout.cols
    const rows = sd.grid_rows || layout.rows
    store.updateItem(props.item.id, {
      style_data: sd,
      width: cols * layout.cellW + Math.max(0, cols - 1) * (axis === 'h' ? newGap : layout.hGap),
      height: rows * layout.cellH + Math.max(0, rows - 1) * (axis === 'v' ? newGap : layout.vGap),
    })
  }

  const onUp = () => {
    draggingGapAxis.value = null
    window.removeEventListener('mousemove', onMove)
    window.removeEventListener('mouseup', onUp)
  }
  window.addEventListener('mousemove', onMove)
  window.addEventListener('mouseup', onUp)
}

// Ambient motion: compute a per-item random animation delay + duration so items don't all move in sync
const motionSeed = computed(() => {
  // Hash item id + position for better distribution — avoids sequential IDs producing similar hashes
  let h = 0
  const s = String(props.item.id || '') + '_' + Math.round(props.item.pos_x || 0) + '_' + Math.round(props.item.pos_y || 0)
  for (let i = 0; i < s.length; i++) { h = ((h << 5) - h) + s.charCodeAt(i); h |= 0 }
  return Math.abs(h)
})
// Use different prime multipliers for delay vs duration to avoid correlation
const motionDelay = computed(() => ((motionSeed.value * 7) % 10000) + 'ms')
const motionDuration = computed(() => {
  const base = 3500 + ((motionSeed.value * 13) % 4500)
  return Math.round(base * store.motionSpeed) + 'ms'
})
// Alternate animation direction for half the items — doubles visual variety
const motionDirection = computed(() => {
  const d = motionSeed.value % 3
  return d === 0 ? 'normal' : d === 1 ? 'reverse' : 'alternate'
})

// "Cards" motion = items that look like cards/papers: notes, todos, links, files, etc.
// image_set is handled internally -- each stacked card wobbles independently
const cardTypes = ['note', 'todo_list', 'link', 'file', 'calendar_event', 'table']
// "Elements" motion = visual assets: images, swatches, drawings, board links, etc.
const elementTypes = ['image', 'color_swatch', 'drawing', 'text', 'folder', 'board_link', 'shape']

const motionClass = computed(() => {
  if (!store.motionEnabled) return ''
  // Disable motion at low zoom for performance
  if (props.zoomTier === 'low') return ''
  const t = props.item.type
  // image_set: don't animate the container -- individual cards animate inside MoodImageSet
  if (t === 'image_set') return ''
  if (cardTypes.includes(t) && store.motionCards) return 'motion-wobble'
  if (elementTypes.includes(t) && store.motionElements) return 'motion-float'
  return ''
})

// Cards use their own intensity slider; elements use the general one
const motionAmplitude = computed(() => {
  const t = props.item.type
  if (cardTypes.includes(t)) return store.motionCardIntensity
  return store.motionIntensity
})

// ========================================
// DRAW-ON REVEAL ANIMATION
// ========================================
// Tracks whether item has played its draw-on entrance animation
const drawOnPlayed = ref(false)
const drawOnActive = ref(false)  // true while the animation is in progress
let drawOnObserver = null

// Types eligible for draw-on: drawings, text, and canvas strokes
const drawOnEligible = computed(() => {
  if (!store.motionEnabled || !store.motionDrawOn) return false
  // Disable draw-on at low zoom for performance
  if (props.zoomTier === 'low') return false
  return ['drawing', 'text'].includes(props.item.type)
})

// Compute center-line SVG path from stroke points (for drawing items)
function pointsToCenterLinePath(points) {
  if (!points || points.length < 2) return ''
  let d = `M ${points[0][0].toFixed(1)} ${points[0][1].toFixed(1)}`
  for (let i = 1; i < points.length; i++) {
    d += ` L ${points[i][0].toFixed(1)} ${points[i][1].toFixed(1)}`
  }
  return d
}

// Calculate approximate path length from points array
function estimatePathLength(points) {
  if (!points || points.length < 2) return 0
  let len = 0
  for (let i = 1; i < points.length; i++) {
    const dx = points[i][0] - points[i - 1][0]
    const dy = points[i][1] - points[i - 1][1]
    len += Math.sqrt(dx * dx + dy * dy)
  }
  return len
}

// Animation speed: base duration scaled by user's speed slider
// Lower motionDrawOnSpeed = slower animation, higher = faster
function getDrawOnDuration(pathLength) {
  const baseDuration = Math.max(0.3, Math.min(3, pathLength / 300))
  return baseDuration / store.motionDrawOnSpeed
}

function triggerDrawOn() {
  if (drawOnPlayed.value || drawOnActive.value) return
  drawOnActive.value = true

  if (props.item.type === 'drawing') {
    // For drawings: animate stroke-dashoffset on center-line paths
    // The duration per stroke is based on its path length, played sequentially
    const data = drawingStrokeData.value
    if (!data?.strokes?.length) {
      drawOnActive.value = false
      drawOnPlayed.value = true
      return
    }
    let totalDelay = 0
    data.strokes.forEach((stroke, i) => {
      const len = estimatePathLength(stroke.points)
      const dur = getDrawOnDuration(len)
      totalDelay += dur
    })
    // The CSS animation handles timing; we just set a timeout to mark completion
    setTimeout(() => {
      drawOnActive.value = false
      drawOnPlayed.value = true
    }, totalDelay * 1000 + 100)
  } else if (props.item.type === 'text') {
    // Text reveal via clip-path animation
    const dur = 0.8 / store.motionDrawOnSpeed
    setTimeout(() => {
      drawOnActive.value = false
      drawOnPlayed.value = true
    }, dur * 1000 + 50)
  } else {
    drawOnActive.value = false
    drawOnPlayed.value = true
  }
}

// Computed: per-stroke animation data for drawing draw-on
const drawOnStrokeAnimations = computed(() => {
  if (props.item.type !== 'drawing' || !drawingStrokeData.value?.strokes?.length) return []
  let cumulativeDelay = 0
  return drawingStrokeData.value.strokes.map((stroke, i) => {
    const len = estimatePathLength(stroke.points)
    const dur = getDrawOnDuration(len)
    const delay = cumulativeDelay
    cumulativeDelay += dur
    return {
      centerLinePath: pointsToCenterLinePath(stroke.points),
      pathLength: Math.ceil(len) || 100,
      duration: dur,
      delay,
      color: stroke.color || '#000000',
      width: (stroke.width || 4) + 1 // slightly thicker than center-line for visual fill
    }
  })
})

// Text draw-on duration
const textDrawOnDuration = computed(() => {
  return (0.8 / store.motionDrawOnSpeed) + 's'
})

onMounted(() => {
  if (!drawOnEligible.value) {
    drawOnPlayed.value = true // not eligible, skip
    return
  }

  // In presentation mode, IntersectionObserver is unreliable inside CSS-transformed
  // containers (scale + translate). Force immediate trigger instead.
  if (store.presentationMode || store.motionDrawOnTrigger === 'always') {
    // Play immediately
    nextTick(() => triggerDrawOn())
    return
  }

  // 'viewport' trigger — use IntersectionObserver
  if (typeof IntersectionObserver === 'undefined') {
    drawOnPlayed.value = true
    return
  }

  drawOnObserver = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting && !drawOnPlayed.value) {
          triggerDrawOn()
          // Once played, stop observing
          drawOnObserver?.disconnect()
        }
      }
    },
    { threshold: 0.1 }
  )

  if (itemEl.value) {
    drawOnObserver.observe(itemEl.value)
  }
})

// Watch for motionDrawOn re-enable: allow replaying
watch(() => store.motionDrawOn, (val) => {
  if (val) {
    drawOnPlayed.value = false
    drawOnActive.value = false
    if (store.motionDrawOnTrigger === 'always') {
      nextTick(() => triggerDrawOn())
    } else if (drawOnObserver && itemEl.value) {
      drawOnObserver.observe(itemEl.value)
    } else if (itemEl.value && typeof IntersectionObserver !== 'undefined') {
      drawOnObserver = new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (entry.isIntersecting && !drawOnPlayed.value) {
              triggerDrawOn()
              drawOnObserver?.disconnect()
            }
          }
        },
        { threshold: 0.1 }
      )
      drawOnObserver.observe(itemEl.value)
    }
  }
})

onBeforeUnmount(() => {
  drawOnObserver?.disconnect()
  drawOnObserver = null
})

const itemEl = ref(null)
const resizing = ref(false)
const rotating = ref(false)

// ── Action bar visibility ──────────────────────────────────────────
const showActionBar = computed(() => props.selected && !props.multiSelected && isFullDetail.value && !resizing.value && !rotating.value)

const editing = ref(false)
const editingTitle = ref(false)
const editTitle = ref('')
const editContent = ref('')
const editUrl = ref('')
const titleInput = ref(null)
const contentInput = ref(null)
const urlInput = ref(null)
const videoUrlInput = ref(null)
const youtubeUrlInput = ref(null)
const audioUrlInput = ref(null)
const audioPlayerRef = ref(null)
// YouTube interactive mode — controlled via style_data._youtubeInteractive from sidebar toggle
const youtubeInteractive = computed(() => !!props.item.style_data?._youtubeInteractive)
const newTodoText = ref('')

// Drawing quick recolor presets
const drawingQuickColors = [
  '#1e293b', '#ffffff', '#ef4444', '#f97316', '#eab308',
  '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899'
]

function recolorDrawingItem(newColor) {
  if (props.item.type !== 'drawing') return
  try {
    const data = typeof props.item.content === 'string' ? JSON.parse(props.item.content) : props.item.content
    if (data?.strokes) {
      for (const stroke of data.strokes) {
        stroke.color = newColor
      }
      emit('update', {
        content: JSON.stringify(data),
        color: newColor
      })
    }
  } catch (e) {
    console.error('Failed to recolor drawing:', e)
  }
}

// Font size constants
const FONT_SIZE_MIN = 1
const FONT_SIZE_MAX = 500
const FONT_SIZE_DEFAULT = 14

const ICON_FONTS = new Set(['Material Symbols Rounded', 'Material Symbols Outlined'])
const FONT_SIZE_STEP = 2

function ensureSvgPath(stroke) {
  if (stroke.svgPath) return stroke
  if (!stroke.points?.length || stroke.points.length < 2) return stroke
  const pts = stroke.points.map(p => Array.isArray(p) ? p : [p.x, p.y, 0.5])
  const sz = stroke.width || 8
  const opts = stroke.options || {}
  const outline = getStroke(pts, {
    size: sz,
    thinning: opts.thinning ?? 0.5,
    smoothing: opts.smoothing ?? 0.7,
    streamline: opts.streamline ?? 0.6,
    easing: (t) => Math.sin((t * Math.PI) / 2),
    start: { taper: opts.taperEnabled !== false ? sz * 0.5 : 0, cap: true, easing: (t) => t * t },
    end: { taper: opts.taperEnabled !== false ? sz * 0.5 : 0, cap: true, easing: (t) => 1 - (1 - t) * (1 - t) },
    simulatePressure: opts.simulatePressure ?? true,
  })
  if (!outline || outline.length < 2) return stroke
  const len = outline.length
  let d = `M ${outline[0][0].toFixed(2)} ${outline[0][1].toFixed(2)} Q`
  for (let i = 0; i < len; i++) {
    const [x0, y0] = outline[i]
    const [x1, y1] = outline[(i + 1) % len]
    d += ` ${x0.toFixed(2)} ${y0.toFixed(2)} ${((x0 + x1) / 2).toFixed(2)} ${((y0 + y1) / 2).toFixed(2)}`
  }
  d += ' Z'
  return { ...stroke, svgPath: d }
}

// Drawing stroke data (parsed from content JSON or style_data.strokes_data legacy)
const drawingStrokeData = computed(() => {
  if (props.item.type !== 'drawing') return null
  let raw = null
  if (props.item.content) {
    try {
      const data = typeof props.item.content === 'string' ? JSON.parse(props.item.content) : props.item.content
      if (data?.strokes?.length) raw = data
    } catch { /* fall through to legacy */ }
  }
  if (!raw) {
    let sd = props.item.style_data || {}
    if (typeof sd === 'string') { try { sd = JSON.parse(sd) } catch { sd = {} } }
    const legacy = sd.strokes_data || sd.drawing_strokes
    if (legacy?.length) raw = { strokes: legacy, width: sd.original_width || props.item.width, height: sd.original_height || props.item.height }
  }
  if (!raw?.strokes?.length) return null
  return { ...raw, strokes: raw.strokes.map(ensureSvgPath) }
})

// Original drawing canvas dimensions (for correct viewBox when scaling)
// LINE item computed style
const lineStyle = computed(() => {
  const sd = props.item.style_data || {}
  const w = props.item.width || 200
  const h = props.item.height || 20
  const color = sd.line_color || '#1e293b'
  const width = sd.line_width ?? 2
  const dash = sd.line_dash || 'solid'
  const gap = sd.line_dash_gap || 0

  let dashArray = 'none'
  if (dash === 'dashed') {
    const dashLen = gap > 0 ? gap * 2 : width * 4
    const gapLen = gap > 0 ? gap : width * 2
    dashArray = `${dashLen},${gapLen}`
  } else if (dash === 'dotted') {
    const dotGap = gap > 0 ? gap : width * 2
    dashArray = `${width},${dotGap}`
  }

  return {
    x1: sd.line_x1 ?? 10,
    y1: sd.line_y1 ?? (h / 2),
    x2: sd.line_x2 ?? (w - 10),
    y2: sd.line_y2 ?? (h / 2),
    color,
    width,
    dashArray,
    arrowStart: sd.line_arrow_start || false,
    arrowEnd: sd.line_arrow_end || false,
  }
})

// Line glow / shadow (SVG filter-based, since CSS box-shadow doesn't work on SVG)
const lineGlow = computed(() => {
  const sd = props.item.style_data || {}
  if (!sd.line_glow_enabled) return { enabled: false }
  const color = sd.line_glow_color || sd.line_color || '#6366f1'
  const opacity = (sd.line_glow_opacity ?? 60) / 100
  const blur = sd.line_glow_blur ?? 6
  return { enabled: true, color, opacity, blur }
})

// The viewBox must stay at the ORIGINAL size so scaling the container actually
// scales the visual strokes. Priority: content JSON > style_data snapshot > item size.
const drawingViewBox = computed(() => {
  const data = drawingStrokeData.value
  const sd = props.item.style_data || {}
  if (!data) {
    return {
      w: sd.original_width || props.item.width || 200,
      h: sd.original_height || props.item.height || 150
    }
  }
  // Content JSON stores original canvas dims (may be "600px" strings or numbers)
  const contentW = parseInt(data.width)
  const contentH = parseInt(data.height)
  // If content has valid dimensions, use them; otherwise fall back to the
  // original_width/height snapshot saved on first scale, never the current item size.
  const w = contentW || sd.original_width || props.item.width || 200
  const h = contentH || sd.original_height || props.item.height || 150
  return { w, h }
})

// Board link parsed data
const boardLinkData = computed(() => {
  if (props.item.type !== 'board_link' || !props.item.content) return null
  try {
    const data = typeof props.item.content === 'string' ? JSON.parse(props.item.content) : props.item.content
    return data
  } catch { return null }
})

const boardLinkCardOverdue = computed(() => {
  const due = boardLinkData.value?.card_data?.due_date
  if (!due) return false
  return new Date(due) < new Date()
})

// Folder parsed data
const folderData = computed(() => {
  if (props.item.type !== 'folder') return null
  try {
    const data = props.item.style_data || {}
    return typeof data === 'string' ? JSON.parse(data) : data
  } catch { return null }
})

const checklistProgress = computed(() => {
  const items = boardLinkData.value?.card_data?.checklist_items || []
  if (!items.length) return { done: 0, total: 0, percent: 0 }
  const done = items.filter(t => t.completed).length
  return { done, total: items.length, percent: Math.round((done / items.length) * 100) }
})

function formatBoardLinkDate(date) {
  if (!date) return ''
  const d = new Date(date)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

// Items that support text size changes (only free-text items, not structured cards)
const hasTextContent = computed(() => {
  return ['note', 'text', 'frame', 'slide', 'todo_list'].includes(props.item.type)
})

const currentFontSize = computed(() => {
  return resolvedSd.value?.font_size || FONT_SIZE_DEFAULT
})

function increaseFontSize() {
  const newSize = Math.min(FONT_SIZE_MAX, currentFontSize.value + FONT_SIZE_STEP)
  updateStyleData({ font_size: newSize })
}

function decreaseFontSize() {
  const newSize = Math.max(FONT_SIZE_MIN, currentFontSize.value - FONT_SIZE_STEP)
  updateStyleData({ font_size: newSize })
}

function setFontSize(size) {
  const clamped = Math.max(FONT_SIZE_MIN, Math.min(FONT_SIZE_MAX, size))
  updateStyleData({ font_size: clamped })
}

function updateStyleData(updates) {
  const current = props.item.style_data || {}
  emit('update', { style_data: { ...current, ...updates } })
}

// --- Text block computed styles (for 'text' items) ---
const textDisplayStyle = computed(() => {
  const sd = resolvedSd.value
  const fontFamily = sd.font_family || 'Inter'
  const iconFont = ICON_FONTS.has(fontFamily)
  const style = {
    fontSize: currentFontSize.value + 'px',
    fontFamily,
    fontWeight: sd.font_weight || '400',
    textAlign: sd.text_align || 'left',
    textTransform: sd.text_transform || 'none',
    letterSpacing: (sd.letter_spacing ?? 0) + 'px',
    lineHeight: sd.line_height ?? 1,
    fontFeatureSettings: "'liga'",
  }

  if (iconFont) {
    style.whiteSpace = 'nowrap'
    style.wordWrap = 'normal'
    style.overflow = 'hidden'
    style.direction = 'ltr'
    style.lineHeight = 1
    style.letterSpacing = 'normal'
    style.textTransform = 'none'
  }

  // Image clip for text (background-clip: text with an image)
  if (sd.text_clip_image) {
    style.backgroundImage = `url(${sd.text_clip_image})`
    style.backgroundSize = sd.text_clip_image_size || 'cover'
    style.backgroundPosition = 'center'
    style.WebkitBackgroundClip = 'text'
    style.backgroundClip = 'text'
    style.WebkitTextFillColor = 'transparent'
    style.color = 'transparent'
  } else {
    // Gradient fill for text (uses -webkit-background-clip: text)
    const gradientCSS = buildGradientCSS(sd.text_fill_type, sd.text_color, sd.text_fill_gradient)
    if (gradientCSS) {
      style.background = gradientCSS
      style.WebkitBackgroundClip = 'text'
      style.backgroundClip = 'text'
      style.WebkitTextFillColor = 'transparent'
      style.color = 'transparent'
    } else {
      style.color = sd.text_color || undefined
    }
  }

  // Text stroke (outline around text characters)
  // Uses -webkit-text-stroke with paint-order: stroke fill so the fill is painted
  // on top of the stroke. This gives a clean outer-only outline at any width and
  // avoids the sub-pixel artifacts the old shadow-based approach had at thick sizes.
  const strokeW = sd.text_stroke_width || 0
  const strokeC = sd.text_stroke_color || 'transparent'
  const shadows = []
  if (strokeW > 0 && strokeC && strokeC !== 'transparent') {
    style.WebkitTextStroke = `${strokeW}px ${strokeC}`
    style.paintOrder = 'stroke fill'
  }

  // Text shadow (follows the text shape, not the bounding box)
  if (sd.text_shadow_enabled) {
    const tsx = sd.text_shadow_x ?? 1
    const tsy = sd.text_shadow_y ?? 2
    const tsb = sd.text_shadow_blur ?? 4
    const tsc = sd.text_shadow_color || '#000000'
    const tso = (sd.text_shadow_opacity ?? 40) / 100
    shadows.push(`${tsx}px ${tsy}px ${tsb}px ${hexToRgba(tsc, tso)}`)
  }
  if (shadows.length) {
    style.textShadow = shadows.join(', ')
  }

  return style
})

const textEditStyle = computed(() => {
  const base = { ...textDisplayStyle.value, whiteSpace: 'pre-wrap' }
  // When text uses gradient fill, color is set to 'transparent' which makes the
  // caret (text cursor) invisible. Force a visible caret color in all cases.
  const sd = props.item.style_data || {}
  const textColor = sd.text_color || '#ffffff'
  base.caretColor = textColor === 'transparent' ? '#ffffff' : textColor
  base.display = 'block'
  base.height = 'auto'
  base.minHeight = '100%'
  base.overflow = 'hidden'
  return base
})

function onInsertLorem(text) {
  if (props.item.type === 'text') {
    // For text items with contenteditable, set HTML
    editing.value = true
    nextTick(() => {
      if (contentInput.value) {
        contentInput.value.innerHTML = contentToHtml(text)
        contentInput.value.focus()
      }
      emit('update', { content: text })
    })
  } else {
    editContent.value = text
    editing.value = true
    nextTick(() => {
      if (contentInput.value) {
        contentInput.value.focus()
      }
      emit('update', { content: text })
    })
  }
}

// --- Clipping mask children (items masked inside this shape) ---
const maskedChildren = computed(() => {
  if (props.item.type !== 'shape' && props.item.type !== 'pen_shape') return []
  if (!store.currentBoard?.items) return []
  return store.currentBoard.items
    .filter(i => i.style_data?.mask_parent_id === props.item.id)
    .sort((a, b) => (a.z_index || 0) - (b.z_index || 0) || a.id - b.id)
})

// --- Gradient utility (shared across shape, frame, etc.) ---
function buildGradientCSS(fillType, fillColor, gradient) {
  if (!fillType || fillType === 'solid') return null
  const stops = gradient?.stops
  if (!stops || stops.length < 2) return null
  const stopsStr = [...stops].sort((a, b) => a.position - b.position)
    .map(s => `${s.color} ${s.position}%`).join(', ')
  if (fillType === 'radial') return `radial-gradient(circle, ${stopsStr})`
  return `linear-gradient(${gradient?.angle ?? 180}deg, ${stopsStr})`
}

// --- Shape computed styles ---
const shapeMaskImage = computed(() => props.item.style_data?.mask_image_url || null)

const shapeFill = computed(() => resolvedSd.value?.shape_fill || '#6366f1')
const shapeBorderColor = computed(() => resolvedSd.value?.shape_border_color || '#4f46e5')
const shapeBorderWidth = computed(() => resolvedSd.value?.shape_border_width ?? 2)


const shapeCorners = computed(() => {
  const sd = props.item.style_data || {}
  const all = sd.shape_border_radius ?? 0
  return {
    tl: sd.shape_border_radius_tl ?? all,
    tr: sd.shape_border_radius_tr ?? all,
    br: sd.shape_border_radius_br ?? all,
    bl: sd.shape_border_radius_bl ?? all,
  }
})

const shapeRadiusAll = computed(() => {
  const c = shapeCorners.value
  // If all corners are the same, return that value; otherwise average
  if (c.tl === c.tr && c.tr === c.br && c.br === c.bl) return c.tl
  return Math.round((c.tl + c.tr + c.br + c.bl) / 4)
})

const shapeCornersLinked = ref(true)

// --- Corner radius handles (visible on shapes with rectangle type) ---
const showCornerRadiusHandles = computed(() => {
  if (props.item.type !== 'shape') return false
  const st = props.item.style_data?.shape_type || 'rectangle'
  // Only rectangles (and default) support corner radius handles
  return st === 'rectangle' || !st
})

const cornerRadiusHandlePositions = computed(() => {
  if (!showCornerRadiusHandles.value) return []
  const w = props.item.width || 200
  const h = props.item.height || 200
  const c = shapeCorners.value
  const maxR = Math.min(w, h) / 2
  // Offset each handle inward from its corner by the radius amount (clamped)
  const tl = Math.min(c.tl, maxR)
  const tr = Math.min(c.tr, maxR)
  const br = Math.min(c.br, maxR)
  const bl = Math.min(c.bl, maxR)
  // Minimum offset so handles aren't hidden at radius=0
  const minOff = 12
  return [
    { corner: 'tl', x: Math.max(tl, minOff), y: Math.max(tl, minOff) },
    { corner: 'tr', x: w - Math.max(tr, minOff), y: Math.max(tr, minOff) },
    { corner: 'br', x: w - Math.max(br, minOff), y: h - Math.max(br, minOff) },
    { corner: 'bl', x: Math.max(bl, minOff), y: h - Math.max(bl, minOff) },
  ]
})

function startCornerRadiusDrag(e, corner) {
  e.preventDefault()
  const startX = e.clientX
  const startY = e.clientY
  const w = props.item.width || 200
  const h = props.item.height || 200
  const maxR = Math.min(w, h) / 2
  const sd = props.item.style_data || {}
  const startR = sd[`shape_border_radius_${corner}`] ?? sd.shape_border_radius ?? 0

  const onMouseMove = (ev) => {
    // Dragging inward increases radius, outward decreases
    const dx = (ev.clientX - startX) / store.zoom
    const dy = (ev.clientY - startY) / store.zoom
    // Use the diagonal movement relative to corner direction
    let delta
    if (corner === 'tl') delta = (dx + dy) / 2
    else if (corner === 'tr') delta = (-dx + dy) / 2
    else if (corner === 'br') delta = (-dx - dy) / 2
    else delta = (dx - dy) / 2

    const newR = Math.round(Math.max(0, Math.min(maxR, startR + delta)))
    // Update all or individual corners
    const linked = shapeCornersLinked.value
    if (linked) {
      updateStyleData({
        shape_border_radius: newR,
        shape_border_radius_tl: newR,
        shape_border_radius_tr: newR,
        shape_border_radius_br: newR,
        shape_border_radius_bl: newR,
      })
    } else {
      updateStyleData({ [`shape_border_radius_${corner}`]: newR })
    }
  }

  const onMouseUp = () => {
    document.removeEventListener('mousemove', onMouseMove)
    document.removeEventListener('mouseup', onMouseUp)
  }

  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup', onMouseUp)
}

// Blend mode
const showBlendDropdown = ref(false)
const blendModes = [
  'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten',
  'color-dodge', 'color-burn', 'hard-light', 'soft-light',
  'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'
]
const currentBlendMode = computed(() => props.item.style_data?.blend_mode || 'normal')

const cornerDefs = [
  { key: 'tl', label: 'Top Left', rotate: 'rotate(0deg)' },
  { key: 'tr', label: 'Top Right', rotate: 'rotate(90deg)' },
  { key: 'br', label: 'Bottom Right', rotate: 'rotate(180deg)' },
  { key: 'bl', label: 'Bottom Left', rotate: 'rotate(270deg)' },
]

const shapeBackdropBlur = computed(() => props.item.style_data?.shape_backdrop_blur ?? 0)

// Convert hex color string to rgba with given alpha
function hexToRgba(hex, alpha) {
  hex = (hex || '#000000').replace('#', '')
  if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2]
  const r = parseInt(hex.substring(0, 2), 16) || 0
  const g = parseInt(hex.substring(2, 4), 16) || 0
  const b = parseInt(hex.substring(4, 6), 16) || 0
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

const shapeType = computed(() => props.item.style_data?.shape_type || 'rectangle')

// Clip-path definitions for non-rectangular shapes
const SHAPE_CLIP_PATHS = {
  triangle: 'polygon(50% 0%, 0% 100%, 100% 100%)',
  star: 'polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%)',
}

const shapeStyle = computed(() => {
  const sd = resolvedSd.value
  const c = shapeCorners.value
  const blur = shapeBackdropBlur.value
  const type = shapeType.value

  const style = {}

  // Shape-specific geometry
  if (type === 'circle' || type === 'ellipse') {
    style.borderRadius = '50%'
    style.border = shapeBorderWidth.value > 0 ? `${shapeBorderWidth.value}px solid ${shapeBorderColor.value}` : 'none'
  } else if (SHAPE_CLIP_PATHS[type]) {
    style.clipPath = SHAPE_CLIP_PATHS[type]
    style.WebkitClipPath = SHAPE_CLIP_PATHS[type]
    // Borders don't work with clip-path, handled by SVG overlay
    style.border = 'none'
  } else {
    // Rectangle (default)
    style.borderRadius = `${c.tl}px ${c.tr}px ${c.br}px ${c.bl}px`
    style.border = shapeBorderWidth.value > 0 ? `${shapeBorderWidth.value}px solid ${shapeBorderColor.value}` : 'none'
  }

  // Gradient or solid fill (opacity is handled at the wrapper level via itemOpacityStyle)
  const gradientCSS = buildGradientCSS(sd.shape_fill_type, shapeFill.value, sd.shape_fill_gradient)
  if (gradientCSS) {
    style.background = gradientCSS
  } else {
    style.backgroundColor = shapeFill.value
  }

  // Backdrop blur must live on the same element as border-radius so the blur
  // clips to the rounded shape.  The root element (itemStyle) has overflow:visible
  // and no border-radius, so applying it there causes the blur to render as a
  // full rectangle ignoring rounded corners.
  const bdBlur = sd.backdrop_blur_enabled && sd.backdrop_blur_amount > 0
    ? sd.backdrop_blur_amount
    : (sd.shape_backdrop_blur || 0)
  if (bdBlur > 0) {
    style.backdropFilter = `blur(${bdBlur}px)`
    style.WebkitBackdropFilter = `blur(${bdBlur}px)`
    // overflow:hidden is required for backdrop-filter to clip to border-radius
    style.overflow = 'hidden'
  }

  return style
})

// Text styling for text inside shapes
const shapeTextStyle = computed(() => {
  const sd = resolvedSd.value
  return {
    fontSize: (sd.shape_font_size || 16) + 'px',
    fontFamily: sd.shape_font_family || 'Inter',
    fontWeight: sd.shape_font_weight || '600',
    color: sd.shape_text_color || '#ffffff',
    textAlign: sd.shape_text_align || 'center',
    letterSpacing: (sd.shape_letter_spacing ?? 0) + 'px',
    lineHeight: sd.shape_line_height ?? 1,
    textTransform: sd.shape_text_transform || 'none',
  }
})

function childTextStyle(child) {
  const sd = child.style_data || {}
  const fontFamily = sd.font_family || 'Inter'
  const iconFont = ICON_FONTS.has(fontFamily)
  const style = {
    fontFamily,
    fontSize: (sd.font_size || 16) + 'px',
    fontWeight: sd.font_weight || '400',
    color: sd.text_color || '#ffffff',
    letterSpacing: (sd.letter_spacing || 0) + 'px',
    lineHeight: sd.line_height || 1,
    textAlign: sd.text_align || 'left',
    textTransform: sd.text_transform || 'none',
    fontFeatureSettings: "'liga'",
  }
  if (iconFont) {
    style.whiteSpace = 'nowrap'
    style.wordWrap = 'normal'
    style.overflow = 'hidden'
    style.direction = 'ltr'
    style.lineHeight = 1
    style.letterSpacing = 'normal'
    style.textTransform = 'none'
  }
  return style
}

// Pen shape styles
// Pen shape wrapper (blend mode + opacity now handled universally at outer wrapper)
const penShapeWrapperStyle = computed(() => ({}))

// Map CSS object-fit values to SVG preserveAspectRatio for pen shape mask images
const penMaskAspectRatio = computed(() => {
  const fit = props.item.style_data?.mask_image_fit || 'cover'
  if (fit === 'cover') return 'xMidYMid slice'   // Crop to fill
  if (fit === 'contain') return 'xMidYMid meet'  // Fit inside
  return 'none'                                    // Stretch to fill
})

// ── Universal opacity applied on outer wrapper for ALL item types ──
const itemOpacityStyle = computed(() => {
  const op = ns.value.opacity
  return op < 0.999 ? { opacity: op } : {}
})

function setAllCornerRadius(val) {
  updateStyleData({ shape_border_radius: val, shape_border_radius_tl: val, shape_border_radius_tr: val, shape_border_radius_br: val, shape_border_radius_bl: val })
}

function setCornerRadius(corner, val) {
  updateStyleData({ [`shape_border_radius_${corner}`]: val })
}

/**
 * Handle file drop directly onto a shape — use the image as a mask.
 */
function drawingChildSvg(child) {
  if (!child.content) return ''
  // Simple inline SVG rendering for drawing items inside masks
  return child.content
}

async function onShapeDrop(e) {
  if (props.item.type !== 'shape' && props.item.type !== 'pen_shape') return
  // Locked items cannot be modified by any drop interaction
  const lv = props.item.locked
  if (lv === true || lv === 1 || lv === '1') return
  const files = Array.from(e.dataTransfer?.files || [])
  const imageFile = files.find(f => f.type.startsWith('image/'))
  if (!imageFile) return

  try {
    const uploaded = await store.uploadFiles([imageFile])
    if (uploaded[0]) {
      emit('update', {
        style_data: {
          ...(props.item.style_data || {}),
          mask_image_url: uploaded[0].url,
          mask_image_fit: 'cover'
        }
      })
    }
  } catch (err) {
    console.error('Shape mask upload failed:', err)
  }
}

/**
 * Remove the mask image from a shape.
 */
function removeShapeMask() {
  const sd = { ...(props.item.style_data || {}) }
  delete sd.mask_image_url
  delete sd.mask_image_fit
  emit('update', { style_data: sd })
}

/**
 * Toggle mask image object-fit between cover / contain / fill.
 */
function cycleShapeTextTransform() {
  const transforms = ['none', 'uppercase', 'capitalize', 'lowercase']
  const current = props.item.style_data?.shape_text_transform || 'none'
  const idx = transforms.indexOf(current)
  const next = transforms[(idx + 1) % transforms.length]
  updateStyleData({ shape_text_transform: next })
}

function cycleShapeMaskFit() {
  const fits = ['cover', 'contain', 'fill']
  const current = props.item.style_data?.mask_image_fit || 'cover'
  const idx = fits.indexOf(current)
  const next = fits[(idx + 1) % fits.length]
  updateStyleData({ mask_image_fit: next })
}

/**
 * Replace the mask image via file input.
 */
async function onReplaceMaskImage(e) {
  const file = e.target?.files?.[0]
  if (!file || !file.type.startsWith('image/')) return
  try {
    const uploaded = await store.uploadFiles([file])
    if (uploaded[0]) {
      updateStyleData({ mask_image_url: uploaded[0].url })
    }
  } catch (err) {
    console.error('Mask image replace failed:', err)
  }
  e.target.value = '' // Reset input
}

/**
 * Add a mask image to a shape that doesn't have one yet.
 */
async function onAddMaskImage(e) {
  const file = e.target?.files?.[0]
  if (!file || !file.type.startsWith('image/')) return
  try {
    const uploaded = await store.uploadFiles([file])
    if (uploaded[0]) {
      updateStyleData({ mask_image_url: uploaded[0].url, mask_image_fit: 'cover' })
    }
  } catch (err) {
    console.error('Mask image add failed:', err)
  }
  e.target.value = '' // Reset input
}

// Accent color: reads from CSS primary-500 for slides/frames
const accentColorRef = ref('#6366f1')
function updateAccentColor() {
  const style = getComputedStyle(document.documentElement)
  const rgb = style.getPropertyValue('--color-primary-500').trim()
  if (rgb) {
    const parts = rgb.split(/\s+/)
    if (parts.length === 3) {
      accentColorRef.value = `rgb(${parts[0]}, ${parts[1]}, ${parts[2]})`
    }
  }
}
onMounted(() => {
  updateAccentColor()
  const sd = props.item?.style_data
  if (sd?.font_family) loadGoogleFont(sd.font_family)
  if (sd?.shape_font_family) loadGoogleFont(sd.shape_font_family)
})

// Slide accent color (presentation camera view — dashed border)
const slideColor = computed(() => {
  const c = props.item.color
  if (c && c !== '#e0e7ff') return c
  return accentColorRef.value
})

// Frame accent color (layout container — keep for backward compat of frameStyle)
const frameColor = computed(() => {
  const c = props.item.color
  if (c && c !== '#e0e7ff') return c
  return accentColorRef.value
})

// --- Slide appearance computed styles (dashed border presentation viewport) ---
const slideStyle = computed(() => {
  if (props.item.type !== 'slide') return {}
  const sd = props.item.style_data || {}
  const style = { minHeight: '60px' }

  // Semi-transparent accent fill
  const fillColor = sd.fill_color || (slideColor.value + '10')
  style.backgroundColor = fillColor

  // Dashed border
  const sw = sd.stroke_width ?? 2
  const sc = sd.stroke_color || slideColor.value
  style.border = `${sw}px dashed ${sc}`

  // Corner radius
  style.borderRadius = '8px'

  // Backdrop blur on the slide element so it clips to border-radius
  const bdBlur = sd.backdrop_blur_enabled && sd.backdrop_blur_amount > 0
    ? sd.backdrop_blur_amount
    : (sd.frame_backdrop_blur || 0)
  if (bdBlur > 0) {
    style.backdropFilter = `blur(${bdBlur}px)`
    style.WebkitBackdropFilter = `blur(${bdBlur}px)`
    style.overflow = 'hidden'
  }

  return style
})

// --- Frame auto-layout style (CSS flexbox) ---
const autoLayoutStyle = computed(() => {
  if (props.item.type !== 'frame') return {}
  const sd = props.item.style_data || {}
  if (!sd.auto_layout) return {}

  const pad = sd.padding || 0
  const pt = sd.padding_top ?? pad
  const pr = sd.padding_right ?? pad
  const pb = sd.padding_bottom ?? pad
  const pl = sd.padding_left ?? pad

  const alignMap = { start: 'flex-start', end: 'flex-end', center: 'center', stretch: 'stretch' }
  const justifyMap = { start: 'flex-start', end: 'flex-end', center: 'center', 'space-between': 'space-between' }

  return {
    display: 'flex',
    flexDirection: sd.layout_direction || 'column',
    gap: (sd.layout_gap || 0) + 'px',
    alignItems: alignMap[sd.layout_align] || 'stretch',
    justifyContent: justifyMap[sd.layout_justify] || 'flex-start',
    flexWrap: sd.layout_wrap ? 'wrap' : 'nowrap',
    padding: `${pt}px ${pr}px ${pb}px ${pl}px`,
  }
})

// --- Frame appearance computed styles ---
const frameStyle = computed(() => {
  if (props.item.type !== 'frame') return {}
  const sd = resolvedSd.value

  const style = { minHeight: '60px' }

  // Background color
  if (sd.fill_color) {
    style.backgroundColor = sd.fill_color
  } else {
    style.backgroundColor = '#ffffff'
  }

  // Corner radius — reuses the same shape_border_radius* keys as shapes
  const rtl = sd.shape_border_radius_tl
  const rtr = sd.shape_border_radius_tr
  const rbr = sd.shape_border_radius_br
  const rbl = sd.shape_border_radius_bl
  if (rtl != null || rtr != null || rbr != null || rbl != null) {
    style.borderRadius = `${rtl ?? 0}px ${rtr ?? 0}px ${rbr ?? 0}px ${rbl ?? 0}px`
  } else if (sd.shape_border_radius) {
    style.borderRadius = sd.shape_border_radius + 'px'
  }

  // Stroke / border
  if (sd.stroke_color && sd.stroke_width > 0) {
    const sw = sd.stroke_width || 1
    const dashStyle = sd.stroke_dash === 'dashed' ? 'dashed' : (sd.stroke_dash === 'dotted' ? 'dotted' : 'solid')
    style.border = `${sw}px ${dashStyle} ${sd.stroke_color}`
  }

  // Clip content (clip by default)
  style.overflow = sd.clip_content === false ? 'visible' : 'hidden'

  // Opacity (frames use frame_opacity key)
  const opac = sd.frame_opacity ?? sd.opacity
  if (opac != null && opac !== 100) {
    style.opacity = opac / 100
  }

  // Blend mode
  if (sd.blend_mode && sd.blend_mode !== 'normal') {
    style.mixBlendMode = sd.blend_mode
  }

  // Backdrop blur on the frame element (where border-radius lives) so it clips correctly
  const bdBlur = sd.backdrop_blur_enabled && sd.backdrop_blur_amount > 0
    ? sd.backdrop_blur_amount
    : (sd.frame_backdrop_blur || 0)
  if (bdBlur > 0) {
    style.backdropFilter = `blur(${bdBlur}px)`
    style.WebkitBackdropFilter = `blur(${bdBlur}px)`
    style.overflow = 'hidden'
  }

  // Drop shadow (reads from normalized effects)
  const frameShadow = ns.value.effects.find(e => e.visible && e.type === EffectType.DROP_SHADOW)
  if (frameShadow) {
    const sx = frameShadow.offset?.x ?? 0
    const sy = frameShadow.offset?.y ?? 4
    const sb = frameShadow.radius ?? 8
    const ss = frameShadow.spread ?? 0
    style.boxShadow = `${sx}px ${sy}px ${sb}px ${ss}px ${figmaToCssRgba(frameShadow.color)}`
  }

  return style
})

// --- Padding / Margin visual box model overlay (Chrome DevTools-style) ---
const boxModelOverlay = computed(() => {
  if (!props.selected || !isFullDetail.value || store.presentationMode) return null
  const sd = props.item.style_data || {}
  const pt = sd.padding_top || 0, pr = sd.padding_right || 0
  const pb = sd.padding_bottom || 0, pl = sd.padding_left || 0
  const n = v => (v === 'auto' || !v) ? 0 : v
  const mt = n(sd.margin_top), mr = n(sd.margin_right)
  const mb = n(sd.margin_bottom), ml = n(sd.margin_left)
  const mtAuto = sd.margin_top === 'auto', mrAuto = sd.margin_right === 'auto'
  const mbAuto = sd.margin_bottom === 'auto', mlAuto = sd.margin_left === 'auto'
  const hasPad = pt || pr || pb || pl
  const hasMar = mt || mr || mb || ml || mtAuto || mrAuto || mbAuto || mlAuto
  if (!hasPad && !hasMar) return null
  return { pt, pr, pb, pl, mt, mr, mb, ml, mtAuto, mrAuto, mbAuto, mlAuto, hasPad, hasMar }
})

// --- Group appearance: invisible container, only show outline when selected ---
const groupStyle = computed(() => {
  if (props.item.type !== 'group') return {}
  const sd = props.item.style_data || {}
  const clip = sd.clip_content !== undefined ? sd.clip_content : (sd.layout_mode && sd.layout_mode !== 'none')
  return { overflow: clip ? 'hidden' : 'visible' }
})

const groupLayoutMode = computed(() => {
  if (props.item.type !== 'group') return 'none'
  return props.item.style_data?.layout_mode || 'none'
})

const groupLayoutStyle = computed(() => {
  const sd = props.item.style_data || {}
  const mode = sd.layout_mode
  if (!mode || mode === 'none') return {}

  const pad = sd.padding || 0
  const pt = sd.padding_top ?? pad
  const pr = sd.padding_right ?? pad
  const pb = sd.padding_bottom ?? pad
  const pl = sd.padding_left ?? pad

  if (mode === 'flex') {
    const alignMap = { start: 'flex-start', end: 'flex-end', center: 'center', stretch: 'stretch' }
    const justifyMap = { start: 'flex-start', end: 'flex-end', center: 'center', 'space-between': 'space-between', 'space-around': 'space-around' }
    return {
      display: 'flex',
      flexDirection: sd.layout_direction || 'column',
      gap: (sd.layout_gap || 0) + 'px',
      alignItems: alignMap[sd.layout_align] || 'stretch',
      justifyContent: justifyMap[sd.layout_justify] || 'flex-start',
      flexWrap: sd.layout_wrap ? 'wrap' : 'nowrap',
      padding: `${pt}px ${pr}px ${pb}px ${pl}px`,
    }
  }

  if (mode === 'grid') {
    const cols = sd.grid_columns || 3
    const rows = sd.grid_rows
    const hGap = sd.grid_h_gap ?? 20
    const vGap = sd.grid_v_gap ?? 20
    const colTemplate = typeof cols === 'string' && cols.includes('fr') ? cols : `repeat(${cols}, 1fr)`
    const style = {
      display: 'grid',
      gridTemplateColumns: colTemplate,
      gap: hGap === vGap ? `${hGap}px` : `${vGap}px ${hGap}px`,
      padding: `${pt}px ${pr}px ${pb}px ${pl}px`,
    }
    if (rows) {
      style.gridTemplateRows = typeof rows === 'string' && rows.includes('fr') ? rows : `repeat(${rows}, 1fr)`
    }
    if (sd.grid_align_items) style.alignItems = sd.grid_align_items
    if (sd.grid_justify_items) style.justifyItems = sd.grid_justify_items
    return style
  }

  return {}
})

function getGridChildStyle(child) {
  const sd = child.style_data || {}
  const style = { position: 'relative' }
  if (child.width) style.width = child.width + 'px'
  if (child.height) style.height = child.height + 'px'
  if (sd.grid_column) style.gridColumn = sd.grid_column
  if (sd.grid_row) style.gridRow = sd.grid_row
  if (sd.align_self) style.alignSelf = sd.align_self
  if (sd.justify_self) style.justifySelf = sd.justify_self
  const mv = v => v === 'auto' ? 'auto' : (v || 0) + 'px'
  const mt = sd.margin_top, mr = sd.margin_right, mb = sd.margin_bottom, ml = sd.margin_left
  if (mt || mr || mb || ml) style.margin = `${mv(mt)} ${mv(mr)} ${mv(mb)} ${mv(ml)}`
  return style
}

// LOD color for low-detail placeholder — shows a representative color for the item type.
// Uses dark-mode-aware fallbacks so items don't render as white boxes on dark boards.
const isDarkMode = computed(() => document.documentElement.classList.contains('dark'))
const lodColor = computed(() => {
  const t = props.item.type
  const dark = isDarkMode.value
  if (t === 'note') return props.item.color || '#fef3c7'
  if (t === 'color_swatch') return props.item.color || '#6366f1'
  if (t === 'image' || t === 'image_set') return dark ? '#334155' : '#e2e8f0'
  if (t === 'shape' || t === 'pen_shape') return resolvedSd.value?.shape_fill || '#6366f1'
  if (t === 'drawing') return dark ? '#1e293b' : '#f1f5f9'
  if (t === 'slide') return (slideColor.value || '#6366f1') + '10'
  if (t === 'frame') return resolvedSd.value?.fill_color || (dark ? '#1e293b' : '#ffffff')
  if (t === 'group') return 'transparent'
  return dark ? '#1e293b' : '#f1f5f9'
})

// LOD image set preview — first image from the set for low-detail thumbnail
const lodSetImage = computed(() => {
  const imgs = props.item.images
  const legacy = props.item.image_set_items
  const list = (Array.isArray(imgs) && imgs.length > 0) ? imgs : (Array.isArray(legacy) ? legacy : [])
  const first = list[0]
  if (!first) return null
  // Prefer thumbnail for LOD preview (much smaller file)
  return first.thumbnail_url || first.image_url || first.url || null
})

// GPU-accelerated positioning via transform instead of left/top
const itemStyle = computed(() => {
  // When inside a frame (parentPos provided), use frame-relative coordinates
  const absX = props.item.pos_x || 0
  const absY = props.item.pos_y || 0
  const x = props.parentPos ? absX - props.parentPos.x : absX
  const y = props.parentPos ? absY - props.parentPos.y : absY
  const rot = props.item.rotation ? ` rotate(${props.item.rotation}deg)` : ''
  const isColumn = props.item.type === 'column'
  const sd = props.item.style_data || {}
  const scaleVal = sd.item_scale != null && sd.item_scale !== 1 ? sd.item_scale : 1
  const scalePart = scaleVal !== 1 ? ` scale(${scaleVal})` : ''
  const flipX = sd.flip_x ? ' scaleX(-1)' : ''
  const flipY = sd.flip_y ? ' scaleY(-1)' : ''
  // Frame hug sizing: use fit-content instead of explicit dimensions
  const isFrame = props.item.type === 'frame'
  const frameSizing = isFrame ? getFrameSizingStyle(sd, props.item.width, props.item.height) : null

  // Top-level items keep their natural z-index so selection doesn't break visual
  // stacking order. Auto-layout frame children get a scoped boost when selected
  // so they rise above siblings (flexbox controls their layout, not z-index).
  // Static frame children keep natural z-index to preserve explicit stacking.
  // Slides are camera viewports — always rendered behind all other content so
  // clicking an element on top of a slide selects the element, not the slide.
  const baseZ = props.item.z_index || 0
  const isSlide = props.item.type === 'slide'
  const effectiveZ = isSlide
    ? Math.min(baseZ, 0) - 100000
    : (props.inAutoLayout && props.selected ? baseZ + 1000 : baseZ)

  // CSS scale() handles visual sizing — scales everything inside (text, borders,
  // children) like a real CSS div. transform-origin defaults to center so the
  // visual center stays at (pos_x + w/2, pos_y + h/2) regardless of scale value.
  const transformParts = props.inAutoLayout
    ? `${scalePart}${rot}${flipX}${flipY}`.trim()
    : `translate(${x}px, ${y}px)${scalePart}${rot}${flipX}${flipY}`

  const style = {
    transform: transformParts || undefined,
    zIndex: effectiveZ,
    overflow: 'visible',
  }

  if (!props.inAutoLayout) {
    style.width = frameSizing?.width || (props.item.width ? props.item.width + 'px' : 'auto')
    if (props.item.type !== 'text') {
      style.contain = 'layout style'
    }

    if (isColumn) {
      style.minHeight = props.item.height ? props.item.height + 'px' : '200px'
    } else if (frameSizing?.height) {
      style.height = frameSizing.height
    } else {
      style.height = props.item.height ? props.item.height + 'px' : undefined
    }
  }

  // Apply min/max dimensions from style_data
  if (sd.min_w != null) style.minWidth = sd.min_w + 'px'
  if (sd.max_w != null) style.maxWidth = sd.max_w + 'px'
  if (sd.min_h != null) style.minHeight = sd.min_h + 'px'
  if (sd.max_h != null) style.maxHeight = sd.max_h + 'px'

  // Universal effects: drop shadow (reads from normalized effects array)
  const dropShadow = ns.value.effects.find(e => e.visible && e.type === EffectType.DROP_SHADOW)
  if (dropShadow) {
    const sx = dropShadow.offset?.x ?? 0
    const sy = dropShadow.offset?.y ?? 4
    const sb = dropShadow.radius ?? 8
    const ss = dropShadow.spread ?? 0
    style.boxShadow = `${sx}px ${sy}px ${sb}px ${ss}px ${figmaToCssRgba(dropShadow.color)}`
  }

  // NOTE: Element blur (filter: blur) is applied via blurWrapperStyle, NOT here,
  // because the root element has overflow:visible for handles/action-bar. Applying
  // filter:blur here would cause the blur to visually bleed outside the object bounds.

  // Universal backdrop blur (frosted glass effect — blurs what's behind this element).
  // For shapes, frames, and slides, backdrop-filter is applied on the inner styled
  // element (shapeStyle / frameStyle / slideStyle) where border-radius lives, so
  // the blur correctly clips to rounded corners.  Only apply here for other types.
  const isInnerBlurType = props.item.type === 'shape' || props.item.type === 'pen_shape'
    || props.item.type === 'frame' || props.item.type === 'slide'
  if (!isInnerBlurType) {
    const bdBlur = sd.backdrop_blur_enabled && sd.backdrop_blur_amount > 0
      ? sd.backdrop_blur_amount
      : 0
    if (bdBlur > 0) {
      style.backdropFilter = `blur(${bdBlur}px)`
      style.WebkitBackdropFilter = `blur(${bdBlur}px)`
    }
  }

  return style
})

// Blur wrapper style — wraps all item content. CSS filter:blur() naturally
// extends beyond element bounds, which is the desired behavior for soft
// color fills / glow effects.
const blurWrapperStyle = computed(() => {
  const style = { width: '100%', height: '100%' }
  const layerBlur = ns.value.effects.find(e => e.visible && e.type === EffectType.LAYER_BLUR)
  if (layerBlur && layerBlur.radius > 0) {
    style.filter = `blur(${layerBlur.radius}px)`
  }
  return style
})

// Detect if image is a format that supports transparency (PNG, WebP, SVG, GIF)
const isTransparentFormat = computed(() => {
  if (props.item.type !== 'image') return false
  const url = (props.item.image_url || props.item.title || '').toLowerCase()
  return /\.(png|webp|svg|gif)(\?|$)/i.test(url)
})

// Detect SVG images — they need different scaling (no object-fit, fill container directly)
const isSvgImage = computed(() => {
  const url = (props.item.image_url || props.item.title || '').toLowerCase()
  return /\.svg(\?|$)/i.test(url)
})

// Scale factor for content inside cards/board_links/notes
// Based on current width vs a reference base width so content scales when resized
const contentScale = computed(() => {
  const type = props.item.type
  const baseWidths = {
    board_link: 300,
    note: 240,
    todo_list: 240,
    calendar_event: 260,
    link: 280,
  }
  const base = baseWidths[type]
  if (!base) return 1
  const w = props.item.width || base
  return Math.max(0.5, w / base)
})

// Text color for sticky notes (dark text on light bg, light text on dark bg)
const noteTextColor = computed(() => {
  const bg = props.item.color || '#fef3c7'
  const hex = bg.replace('#', '')
  if (hex.length >= 6) {
    const r = parseInt(hex.substr(0, 2), 16)
    const g = parseInt(hex.substr(2, 2), 16)
    const b = parseInt(hex.substr(4, 2), 16)
    return (r * 299 + g * 587 + b * 114) / 1000 < 128 ? '#ffffff' : '#1e1e26'
  }
  return '#1e1e26'
})

const ENTERABLE_CONTAINER_TYPES = new Set(['frame', 'group', 'artboard', 'column', 'repeat_grid', 'slide'])

function onDoubleClick() {
  if (props.readonly) return
  if (props.item.locked) return
  if (editing.value) return

  // Figma-like: double-click a real group container → enter it
  if (ENTERABLE_CONTAINER_TYPES.has(props.item.type)) {
    store.enterGroup(props.item.id)
    return
  }

  // Figma-like: double-click a child of a real group → enter the parent group
  if (props.item.parent_id) {
    const parent = store.getItemById(props.item.parent_id)
    if (ENTERABLE_CONTAINER_TYPES.has(parent?.type) && store.editingGroupId !== parent.id) {
      store.enterGroup(parent.id)
      store.selectedItemIds = new Set([props.item.id])
      return
    }
  }

  // Legacy group_id behavior
  const gid = props.item.style_data?.group_id
  if (gid && !store.editingGroupId) {
    store.enterGroup(props.item.id)
    return
  }

  if (props.item.type === 'text') {
    // Rich text editing with contenteditable
    editing.value = true
    editTitle.value = props.item.title || ''
    editingTitle.value = false
    nextTick(() => {
      if (contentInput.value) {
        contentInput.value.innerHTML = contentToHtml(props.item.content || '')
        contentInput.value.focus()
        // Place cursor at end
        const sel = window.getSelection()
        sel.selectAllChildren(contentInput.value)
        sel.collapseToEnd()
      }
    })
  } else if (props.item.type === 'note') {
    editing.value = true
    editTitle.value = props.item.title || ''
    editContent.value = props.item.content || ''
    editingTitle.value = true
    
    nextTick(() => {
      if (editingTitle.value && titleInput.value) titleInput.value.focus()
      else if (contentInput.value) contentInput.value.focus()
    })
  } else if (props.item.type === 'shape') {
    editing.value = true
    editContent.value = props.item.content || ''
    nextTick(() => contentInput.value?.focus())
  } else if (props.item.type === 'column') {
    editTitle.value = props.item.title || 'New Column'
    editingTitle.value = true
    nextTick(() => titleInput.value?.focus())
  } else if (props.item.type === 'link') {
    editing.value = true
    editTitle.value = props.item.title || ''
    editUrl.value = props.item.url || ''
    nextTick(() => titleInput.value?.focus())
  } else if (props.item.type === 'image') {
    // Open file picker to set/replace image
    emit('replace-image', props.item)
  } else if (props.item.type === 'image_set') {
    // Open file browser to add images
    emit('add-images-to-set', props.item)
  } else if (props.item.type === 'file') {
    // Open file preview on double-click
    emit('preview-file', props.item)
  } else if (props.item.type === 'folder') {
    // Open folder browser on double-click
    emit('browse-folder', props.item)
  } else if (props.item.type === 'video' || props.item.type === 'youtube') {
    editing.value = true
    editTitle.value = props.item.title || ''
    editUrl.value = props.item.url || ''
    nextTick(() => {
      if (props.item.type === 'youtube') {
        youtubeUrlInput.value?.focus()
      } else {
        videoUrlInput.value?.focus()
      }
    })
  } else if (props.item.type === 'audio') {
    editing.value = true
    editTitle.value = props.item.title || ''
    editUrl.value = props.item.url || ''
    nextTick(() => audioUrlInput.value?.focus())
  }
}

function saveTitle() {
  editingTitle.value = false
  editingTableTitle.value = false
  if (editTitle.value !== props.item.title) {
    emit('update', { title: editTitle.value })
  }
}

function saveContent() {
  editing.value = false
  if (editContent.value !== props.item.content) {
    emit('update', { content: editContent.value })
  }
}

// ─── Rich text helpers (for 'text' items with contenteditable) ───

const showInlineFormatBar = ref(false)
const inlineBarPosition = ref(null)

/** Convert plain text content to HTML for contenteditable */
function contentToHtml(content) {
  if (!content) return ''
  // If content already contains HTML tags, return as-is (it's rich content)
  if (/<[a-z][\s\S]*>/i.test(content)) return content
  // Plain text: escape HTML entities and convert newlines to <br>
  // Handle Windows \r\n, old Mac \r, and Unix \n
  return content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\r\n/g, '<br>')
    .replace(/\r/g, '<br>')
    .replace(/\n/g, '<br>')
}

/** Sanitize HTML from contenteditable (strip dangerous tags/attrs) */
function sanitizeRichHtml(html) {
  if (!html) return ''
  return html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/\son\w+\s*=\s*"[^"]*"/gi, '')
    .replace(/\son\w+\s*=\s*'[^']*'/gi, '')
    .replace(/javascript\s*:/gi, '')
}

/** Computed display content for text items (supports rich HTML) */
const richDisplayContent = computed(() => {
  const content = props.item.content || ''
  if (!content) return isFullDetail.value ? '<span style="opacity:0.4">Double-click to edit</span>' : ''
  // If it contains HTML, sanitize and return
  if (/<[a-z][\s\S]*>/i.test(content)) return sanitizeRichHtml(content)
  // Plain text: escape and convert linebreaks (handle Windows \r\n, old Mac \r, Unix \n)
  return content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\r\n/g, '<br>')
    .replace(/\r/g, '<br>')
    .replace(/\n/g, '<br>')
})

/** Convert contenteditable innerHTML to plain text, preserving line breaks.
 *  `innerText` on DETACHED elements is unreliable (WHATWG spec says it falls
 *  back to `textContent` when not rendered, which strips block-level newlines).
 *  So we explicitly convert <div>, <p>, and <br> to \n first. */
function htmlToPlainText(html) {
  if (!html) return ''
  let text = html
    // <br> → newline
    .replace(/<br\s*\/?>/gi, '\n')
    // Closing </div> or </p> followed by opening <div>/<p> → single newline
    .replace(/<\/div>\s*<div[^>]*>/gi, '\n')
    .replace(/<\/p>\s*<p[^>]*>/gi, '\n')
    // Opening <div>/<p> (for first-child blocks) → newline
    .replace(/<div[^>]*>/gi, '\n')
    .replace(/<p[^>]*>/gi, '\n')
    // Strip all remaining tags
    .replace(/<[^>]+>/g, '')
    // Decode basic HTML entities
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    // Collapse multiple consecutive newlines into at most two (preserve intentional blank lines)
    .replace(/\n{3,}/g, '\n\n')
    // Remove leading newline (artifact from first <div>)
    .replace(/^\n/, '')
  return text
}

/** Save rich content from contenteditable on blur/escape */
function saveRichContent() {
  if (!editing.value) return
  showInlineFormatBar.value = false
  const el = contentInput.value
  if (el) {
    let html = el.innerHTML || ''
    // Normalize: if the content is just plain text with no formatting spans, strip HTML to plain text
    const temp = document.createElement('div')
    temp.innerHTML = html
    const hasFormatting = temp.querySelector('span[style], b, i, u, strong, em, font')
    if (!hasFormatting) {
      // Convert back to plain text — explicitly handle block elements to preserve line breaks
      html = htmlToPlainText(html)
    } else {
      html = sanitizeRichHtml(html)
    }
    if (html !== props.item.content) {
      emit('update', { content: html })
    }
  }
  editing.value = false
}

/** Show/hide inline format bar based on text selection */
function onRichTextMouseUp() {
  requestAnimationFrame(() => {
    const sel = window.getSelection()
    if (!sel || sel.isCollapsed || !sel.rangeCount) {
      showInlineFormatBar.value = false
      return
    }
    // Only show if selection is within our contenteditable
    const range = sel.getRangeAt(0)
    if (!contentInput.value?.contains(range.commonAncestorContainer)) {
      showInlineFormatBar.value = false
      return
    }
    const rect = range.getBoundingClientRect()
    if (rect.width < 2) {
      showInlineFormatBar.value = false
      return
    }
    inlineBarPosition.value = {
      top: rect.top - 44,
      left: rect.left + rect.width / 2,
    }
    showInlineFormatBar.value = true
  })
}

/** Apply execCommand for bold/italic/underline */
function applyInlineCommand(cmd) {
  document.execCommand(cmd, false, null)
  contentInput.value?.focus()
}

/** Apply color to selected text */
function applyInlineColor(color) {
  document.execCommand('foreColor', false, color)
  contentInput.value?.focus()
}

/** Apply font family to selected text */
function applyInlineFont(fontFamily) {
  loadGoogleFont(fontFamily)
  document.execCommand('fontName', false, fontFamily)
  contentInput.value?.focus()
}

/** Strip all inline formatting — replaces HTML with plain text */
function clearInlineFormatting() {
  const el = contentInput.value
  if (!el) return
  const sel = window.getSelection()
  if (sel && !sel.isCollapsed && sel.rangeCount) {
    document.execCommand('removeFormat', false, null)
  } else {
    const plain = el.innerText || el.textContent || ''
    el.textContent = plain
  }
  el.focus()
  showInlineFormatBar.value = false
}

function saveLink() {
  editing.value = false
  const updates = {}
  if (editTitle.value !== (props.item.title || '')) updates.title = editTitle.value
  if (editUrl.value !== (props.item.url || '')) updates.url = editUrl.value
  if (Object.keys(updates).length > 0) {
    emit('update', updates)
  }
}

// ========================================
// VIDEO / YOUTUBE
// ========================================

// Extract YouTube video ID from various URL formats
const youtubeEmbedId = computed(() => {
  const url = props.item.url || ''
  if (!url) return null
  // youtube.com/watch?v=ID
  let m = url.match(/[?&]v=([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  // youtu.be/ID
  m = url.match(/youtu\.be\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  // youtube.com/embed/ID
  m = url.match(/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  // youtube.com/shorts/ID
  m = url.match(/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  return null
})

function saveVideoUrl() {
  editing.value = false
  const updates = {}
  if (editTitle.value !== (props.item.title || '')) updates.title = editTitle.value
  if (editUrl.value !== (props.item.url || '')) updates.url = editUrl.value
  if (Object.keys(updates).length > 0) {
    emit('update', updates)
  }
}

async function onVideoFileSelect(e) {
  const file = e.target.files?.[0]
  if (!file) return
  editing.value = false
  try {
    const uploaded = await store.uploadFiles([file])
    if (uploaded?.[0]) {
      emit('update', {
        url: uploaded[0].url || uploaded[0].image_url,
        image_url: uploaded[0].url || uploaded[0].image_url,
        title: editTitle.value || file.name
      })
    }
  } catch (err) {
    console.error('Failed to upload video:', err)
  }
}

function saveAudioUrl() {
  editing.value = false
  const updates = {}
  if (editTitle.value !== (props.item.title || '')) updates.title = editTitle.value
  if (editUrl.value !== (props.item.url || '')) updates.url = editUrl.value
  if (Object.keys(updates).length > 0) {
    emit('update', updates)
  }
}

async function onAudioFileSelect(e) {
  const file = e.target.files?.[0]
  if (!file) return
  editing.value = false
  try {
    const uploaded = await store.uploadFiles([file])
    if (uploaded?.[0]) {
      emit('update', {
        url: uploaded[0].url || uploaded[0].image_url,
        title: editTitle.value || file.name
      })
    }
  } catch (err) {
    console.error('Failed to upload audio:', err)
  }
}

function toggleLock() {
  emit('update', { locked: props.item.locked ? 0 : 1 })
}

async function toggleTodo(todo) {
  await store.updateTodo(todo.id, { completed: todo.completed ? 0 : 1 })
  // Update local state
  todo.completed = todo.completed ? 0 : 1
}

async function addTodo() {
  if (!newTodoText.value.trim()) return
  await store.addTodo(props.item.id, newTodoText.value.trim())
  newTodoText.value = ''
}

// Table data parsing
const editingTableTitle = ref(false)

const tableData = computed(() => {
  if (props.item.type !== 'table') return { columns: [], rows: [] }
  try {
    const data = typeof props.item.content === 'string' ? JSON.parse(props.item.content) : props.item.content
    return {
      columns: data?.columns || ['Column 1', 'Column 2'],
      rows: data?.rows || [['', '']]
    }
  } catch {
    return { columns: ['Column 1', 'Column 2'], rows: [['', '']] }
  }
})

function startEditTableTitle() {
  editTitle.value = props.item.title || 'Table'
  editingTableTitle.value = true
  nextTick(() => titleInput.value?.focus())
}

function saveTableData(newData) {
  emit('update', { content: JSON.stringify(newData) })
}

function updateTableHeader(colIndex, value) {
  const data = { ...tableData.value, columns: [...tableData.value.columns] }
  data.columns[colIndex] = value
  saveTableData(data)
}

function updateTableCell(rowIndex, colIndex, value) {
  const data = {
    columns: [...tableData.value.columns],
    rows: tableData.value.rows.map(r => [...r])
  }
  data.rows[rowIndex][colIndex] = value
  saveTableData(data)
}

function addTableRow() {
  const data = {
    columns: [...tableData.value.columns],
    rows: [...tableData.value.rows.map(r => [...r]), new Array(tableData.value.columns.length).fill('')]
  }
  saveTableData(data)
}

function addTableColumn() {
  const data = {
    columns: [...tableData.value.columns, `Col ${tableData.value.columns.length + 1}`],
    rows: tableData.value.rows.map(r => [...r, ''])
  }
  saveTableData(data)
}

function removeTableRow(rowIndex) {
  if (tableData.value.rows.length <= 1) return
  const data = {
    columns: [...tableData.value.columns],
    rows: tableData.value.rows.filter((_, i) => i !== rowIndex).map(r => [...r])
  }
  saveTableData(data)
}

// ========================================
// COLUMN LOGIC
// ========================================
const columnDragHover = ref(false)

const columnChildCount = computed(() => {
  if (props.item.type !== 'column') return 0
  return (store.currentItems || []).filter(i => i.parent_id === props.item.id).length
})

function onColumnDragOver(e) {
  if (props.item.type !== 'column') return
  columnDragHover.value = true
}

function onColumnDragLeave() {
  columnDragHover.value = false
}

function onColumnDrop(e) {
  columnDragHover.value = false
  // The actual drop-into-column logic is handled via MoodCanvas mouseUp
  // This is mainly for visual feedback
}

// File icon helper
function getFileIcon(item) {
  const name = (item.title || '').toLowerCase()
  if (name.endsWith('.pdf')) return 'picture_as_pdf'
  if (/\.(doc|docx|odt|txt)$/i.test(name)) return 'description'
  if (/\.(xls|xlsx|csv|ods)$/i.test(name)) return 'table_chart'
  if (/\.(ppt|pptx|odp)$/i.test(name)) return 'slideshow'
  if (/\.(zip|rar|7z|tar|gz)$/i.test(name)) return 'folder_zip'
  if (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(name)) return 'image'
  if (/\.(mp4|webm|mov|avi|mkv)$/i.test(name)) return 'movie'
  if (/\.(mp3|ogg|wav|flac|aac)$/i.test(name)) return 'audio_file'
  return 'insert_drive_file'
}

function getFileIconColor(item) {
  const name = (item.title || '').toLowerCase()
  if (name.endsWith('.pdf')) return '#ef4444'
  if (/\.(doc|docx)$/i.test(name)) return '#3b82f6'
  if (/\.(xls|xlsx|csv)$/i.test(name)) return '#22c55e'
  if (/\.(ppt|pptx)$/i.test(name)) return '#f97316'
  if (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(name)) return '#8b5cf6'
  if (/\.(mp4|webm|mov)$/i.test(name)) return '#8b5cf6'
  if (/\.(mp3|ogg|wav)$/i.test(name)) return '#ec4899'
  if (/\.(zip|rar|7z)$/i.test(name)) return '#eab308'
  return '#9ca3af'
}

function getFileExtension(item) {
  const name = (item.title || '').toLowerCase()
  const parts = name.split('.')
  return parts.length > 1 ? parts.pop() : 'file'
}

function canEditFileInCollab(item) {
  const name = (item.title || '').toLowerCase()
  return name.endsWith('.docx') || name.endsWith('.pptx')
}

// Thumbnail URL for file items (only for image files or items with image_url)
const fileThumbnailUrl = computed(() => {
  if (props.item.type !== 'file') return null
  const name = (props.item.title || '').toLowerCase()
  // Only show thumbnail for image files
  if (/\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)$/i.test(name)) {
    return props.item.image_url || props.item.url || null
  }
  return null
})

// Minimum sizes per item type to prevent shrinking below content
function getMinSize(type) {
  const mins = {
    note:           { w: 60,  h: 20  },
    text:           { w: 20,  h: 12  },
    image:          { w: 20,  h: 20  },
    link:           { w: 60,  h: 16  },
    todo_list:      { w: 100, h: 40  },
    file:           { w: 100, h: 60  },
    color_swatch:   { w: 16,  h: 16  },
    board_link:     { w: 120, h: 80  },
    slide:          { w: 80,  h: 45  },
    frame:          { w: 20,  h: 20  },
    artboard:       { w: 20,  h: 20  },
    image_set:      { w: 60,  h: 40  },
    calendar_event: { w: 120, h: 40  },
    drawing:        { w: 40,  h: 40  },
    folder:         { w: 100, h: 60  },
    table:          { w: 120, h: 40  },
    column:         { w: 100, h: 60  },
    shape:          { w: 4,   h: 4   },
    pen_shape:      { w: 4,   h: 4   },
    video:          { w: 100, h: 60  },
    youtube:        { w: 100, h: 60  },
  }
  return mins[type] || { w: 100, h: 60 }
}

function formatRotationDegrees(deg) {
  const rounded = Math.round(deg * 10) / 10
  return rounded % 1 === 0 ? `${rounded.toFixed(0)}\u00B0` : `${rounded.toFixed(1)}\u00B0`
}

// Rotation handling — drag rotate from the top-center handle
function startRotate(e) {
  e.preventDefault()
  
  rotating.value = true
  
  // Disable action bar during rotation to prevent accidental lock clicks on mouseup
  actionBarReady.value = false
  if (_actionBarTimer) { clearTimeout(_actionBarTimer); _actionBarTimer = null }
  
  const el = itemEl.value
  if (!el) return
  
  const startRotation = props.item.rotation || 0
  let rotateRaf = null
  let currentRotation = startRotation
  
  function getItemCenter() {
    const rect = el.getBoundingClientRect()
    return {
      x: rect.left + rect.width / 2,
      y: rect.top + rect.height / 2
    }
  }
  
  const center = getItemCenter()
  const startAngle = Math.atan2(e.clientY - center.y, e.clientX - center.x) * (180 / Math.PI)
  
  const onMouseMove = (ev) => {
    if (rotateRaf) return
    rotateRaf = requestAnimationFrame(() => {
      rotateRaf = null
      const c = getItemCenter()
      const currentAngle = Math.atan2(ev.clientY - c.y, ev.clientX - c.x) * (180 / Math.PI)
      let delta = currentAngle - startAngle
      let newRotation = startRotation + delta
      
      if (ev.shiftKey) {
        newRotation = Math.round(newRotation / 15) * 15
      }
      
      newRotation = ((newRotation % 360) + 360) % 360
      
      if (newRotation < 3 || newRotation > 357) newRotation = 0
      
      currentRotation = newRotation
      props.item.rotation = newRotation
    })
  }
  
  const onMouseUp = () => {
    if (rotateRaf) cancelAnimationFrame(rotateRaf)
    document.removeEventListener('mousemove', onMouseMove)
    document.removeEventListener('mouseup', onMouseUp)
    document.body.style.cursor = ''
    rotating.value = false

    props.item.rotation = currentRotation

    store.pushUndo({
      type: 'update',
      itemId: props.item.id,
      previousData: { rotation: startRotation },
      newData: { rotation: currentRotation }
    })
    emit('update', { rotation: currentRotation }, { skipUndo: true })
    
    resetActionBarCooldown()
  }
  
  document.body.style.cursor = 'grabbing'
  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup', onMouseUp)
}

// Resize handling — 4-corner, aspect-ratio-locked (free for columns/frames), RAF-throttled, persist on mouseup only.
// `corner` = 'tl' | 'tr' | 'bl' | 'br' — determines which corner is anchored vs. dragged
function startResize(e, corner = 'br') {
  e.preventDefault()
  window.getSelection()?.removeAllRanges()
  resizing.value = true
  // Disable action bar during resize to prevent accidental clicks on release
  actionBarReady.value = false
  if (_actionBarTimer) { clearTimeout(_actionBarTimer); _actionBarTimer = null }
  const startMouseX = e.clientX
  const startMouseY = e.clientY

  // Determine axis sign based on handle:
  // Corner handles: signX/signY are -1 or 1
  // Edge handles: the locked axis is 0 (no change on that axis)
  const signMap = {
    tl: { signX: -1, signY: -1 },
    tr: { signX: 1, signY: -1 },
    bl: { signX: -1, signY: 1 },
    br: { signX: 1, signY: 1 },
    t:  { signX: 0, signY: -1 },
    b:  { signX: 0, signY: 1 },
    l:  { signX: -1, signY: 0 },
    r:  { signX: 1, signY: 0 }
  }
  const { signX, signY } = signMap[corner] || { signX: 1, signY: 1 }
  const isSingleAxis = signX === 0 || signY === 0

  // =====================================================================
  // MULTI-ITEM SCALING PATH — scale grouped OR multi-selected items as one unit
  // =====================================================================
  let allScaleItems = null

  const groupId = props.item.style_data?.group_id
  if (groupId && store.currentBoard?.items) {
    const grp = store.currentBoard.items.filter(i => i.style_data?.group_id === groupId)
    if (grp.length > 1) allScaleItems = grp
  }

  if (!allScaleItems && props.multiSelected && store.selectedItemIds.size > 1 && store.currentBoard?.items) {
    const sel = store.currentBoard.items.filter(i => store.selectedItemIds.has(i.id))
    if (sel.length > 1) allScaleItems = sel
  }

  if (allScaleItems && allScaleItems.length > 1) {
      let bMinX = Infinity, bMinY = Infinity, bMaxX = -Infinity, bMaxY = -Infinity
      for (const gi of allScaleItems) {
        const el = document.querySelector(`[data-item-id="${gi.id}"]`)
        const gx = gi.pos_x || 0
        const gy = gi.pos_y || 0
        const gw = gi.width || el?.offsetWidth || 240
        const gh = gi.height || el?.offsetHeight || 120
        bMinX = Math.min(bMinX, gx)
        bMinY = Math.min(bMinY, gy)
        bMaxX = Math.max(bMaxX, gx + gw)
        bMaxY = Math.max(bMaxY, gy + gh)
      }
      const gBBox = { x: bMinX, y: bMinY, w: bMaxX - bMinX, h: bMaxY - bMinY }
      const gAspect = gBBox.w / gBBox.h

      const anchorX = (signX === 1) ? gBBox.x : gBBox.x + gBBox.w
      const anchorY = (signY === 1) ? gBBox.y : gBBox.y + gBBox.h

      const gSnapshots = allScaleItems.map(gi => {
        const el = document.querySelector(`[data-item-id="${gi.id}"]`)
        return {
          item: gi,
          x: gi.pos_x || 0,
          y: gi.pos_y || 0,
          w: gi.width || el?.offsetWidth || 240,
          h: gi.height || el?.offsetHeight || 120,
          fontSize: gi.style_data?.font_size || null,
          shapeFontSize: gi.style_data?.shape_font_size || null,
          borderWidth: gi.style_data?.border_width || null,
          strokeWidth: gi.style_data?.text_stroke_width || null,
          textPadding: gi.type === 'text' ? (gi.style_data?.text_padding ?? 12) : null,
          letterSpacing: gi.style_data?.letter_spacing ?? null,
        }
      })

      let resizeRaf = null

      const onGroupMove = (ev) => {
        if (resizeRaf) return
        resizeRaf = requestAnimationFrame(() => {
          resizeRaf = null
          const rawDx = (ev.clientX - startMouseX) / (store.zoom * totalScale.value)
          const rawDy = (ev.clientY - startMouseY) / (store.zoom * totalScale.value)
          const dx = rawDx * signX
          const dy = rawDy * signY

          // Compute new group bbox dimensions
          const newGW = Math.max(40, gBBox.w + dx)
          const newGH = Math.max(40, gBBox.h + dy)

          // Default: proportional scaling (locked aspect ratio for groups)
          // Hold Shift for free (non-proportional) scaling
          let scaleX, scaleY
          if (ev.shiftKey) {
            scaleX = newGW / gBBox.w
            scaleY = newGH / gBBox.h
          } else {
            // Use the dominant axis to determine uniform scale
            const absDx = Math.abs(dx)
            const absDy = Math.abs(dy)
            if (absDx >= absDy) {
              scaleX = scaleY = newGW / gBBox.w
            } else {
              scaleX = scaleY = newGH / gBBox.h
            }
          }

          // Minimum scale to prevent items from collapsing
          scaleX = Math.max(0.05, scaleX)
          scaleY = Math.max(0.05, scaleY)

          // Apply scale to ALL items in the group
          for (const snap of gSnapshots) {
            const relX = snap.x - anchorX
            const relY = snap.y - anchorY
            snap.item.pos_x = Math.round(anchorX + relX * scaleX)
            snap.item.pos_y = Math.round(anchorY + relY * scaleY)
            snap.item.width = Math.max(20, Math.round(snap.w * scaleX))
            snap.item.height = Math.max(20, Math.round(snap.h * scaleY))

            // Scale font sizes proportionally (geometric mean for non-uniform)
            const fontScale = (scaleX === scaleY) ? scaleX : Math.sqrt(Math.abs(scaleX * scaleY))
            if (snap.fontSize && snap.item.style_data) {
              snap.item.style_data = { ...snap.item.style_data, font_size: Math.max(6, Math.round(snap.fontSize * fontScale)) }
            }
            if (snap.shapeFontSize && snap.item.style_data) {
              snap.item.style_data = { ...snap.item.style_data, shape_font_size: Math.max(6, Math.round(snap.shapeFontSize * fontScale)) }
            }
            if (snap.borderWidth && snap.item.style_data) {
              snap.item.style_data = { ...snap.item.style_data, border_width: Math.max(1, Math.round(snap.borderWidth * fontScale)) }
            }
            if (snap.strokeWidth && snap.item.style_data) {
              snap.item.style_data = { ...snap.item.style_data, text_stroke_width: Math.max(0, +(snap.strokeWidth * fontScale).toFixed(1)) }
            }
            if (snap.textPadding != null && snap.item.style_data) {
              snap.item.style_data = { ...snap.item.style_data, text_padding: Math.max(0, +(snap.textPadding * fontScale).toFixed(1)) }
            }
            if (snap.letterSpacing != null && snap.letterSpacing !== 0 && snap.item.style_data) {
              snap.item.style_data = { ...snap.item.style_data, letter_spacing: +(snap.letterSpacing * fontScale).toFixed(2) }
            }
          }
        })
      }

      const onGroupUp = () => {
        resizing.value = false
        if (resizeRaf) cancelAnimationFrame(resizeRaf)
        document.removeEventListener('mousemove', onGroupMove)
        document.removeEventListener('mouseup', onGroupUp)

        // Build undo data for ALL group items (uses existing batch-update undo type)
        const undoPrev = gSnapshots.map(snap => {
          const prevSd = { ...(snap.item.style_data || {}) }
          if (snap.fontSize != null) prevSd.font_size = snap.fontSize
          if (snap.shapeFontSize != null) prevSd.shape_font_size = snap.shapeFontSize
          if (snap.borderWidth != null) prevSd.border_width = snap.borderWidth
          if (snap.strokeWidth != null) prevSd.text_stroke_width = snap.strokeWidth
          if (snap.textPadding != null) prevSd.text_padding = snap.textPadding
          if (snap.letterSpacing != null) prevSd.letter_spacing = snap.letterSpacing
          return {
            id: snap.item.id,
            pos_x: snap.x,
            pos_y: snap.y,
            width: snap.w,
            height: snap.h,
            style_data: prevSd,
          }
        })
        const undoNew = gSnapshots.map(snap => ({
          id: snap.item.id,
          pos_x: snap.item.pos_x,
          pos_y: snap.item.pos_y,
          width: snap.item.width,
          height: snap.item.height,
          style_data: snap.item.style_data,
        }))
        store.pushUndo({ type: 'batch-update', previousUpdates: undoPrev, newUpdates: undoNew })

        // Persist via batch update
        const updates = gSnapshots.map(snap => ({
          id: snap.item.id,
          pos_x: snap.item.pos_x,
          pos_y: snap.item.pos_y,
          width: snap.item.width,
          height: snap.item.height,
          style_data: snap.item.style_data,
        }))
        store.batchUpdateItems(updates, { skipUndo: true })

        resetActionBarCooldown()
      }

      document.addEventListener('mousemove', onGroupMove)
      document.addEventListener('mouseup', onGroupUp)
      return
  }

  // =====================================================================
  // NORMAL (SINGLE ITEM) RESIZE PATH
  // =====================================================================
  const startW = props.item.width || 240
  const startH = props.item.height || itemEl.value?.offsetHeight || 120
  const startPosX = props.item.pos_x || 0
  const startPosY = props.item.pos_y || 0
  const aspectRatio = startW / startH
  // Slides enforce 16:9 aspect ratio (presentation camera views)
  const isPresentationSlide = props.item.type === 'slide'
  // Frames now support free resize (both W and H)
  const isFrame = props.item.type === 'frame'
  const freeResize = !isPresentationSlide && ['column', 'drawing', 'text', 'note', 'todo_list', 'shape', 'pen_shape', 'video', 'youtube', 'artboard', 'frame'].includes(props.item.type)
  const { w: typeMinW, h: typeMinH } = getMinSize(props.item.type)

  // Apply min/max from style_data (user-defined bounds)
  const sd = props.item.style_data || {}
  const minW = sd.min_w != null ? Math.max(typeMinW, sd.min_w) : typeMinW
  const minH = sd.min_h != null ? Math.max(typeMinH, sd.min_h) : typeMinH
  const maxW = sd.max_w ?? Infinity
  const maxH = sd.max_h ?? Infinity

  // Rotation-aware resize: project mouse deltas into item's local axes.
  // CSS applies: translate(pos_x, pos_y) rotate(deg) with transform-origin 50% 50%.
  const rotDeg = props.item.rotation || 0
  const rotRad = rotDeg * Math.PI / 180
  const cosR = Math.cos(rotRad)
  const sinR = Math.sin(rotRad)

  // --- Constraint engine: snapshot static-frame children before resize starts ---
  let frameChildren = []
  let childSnapshots = []
  if (isFrame && !sd.auto_layout && store.currentBoard?.items) {
    frameChildren = store.currentBoard.items.filter(i => i.parent_id === props.item.id)
    if (frameChildren.length > 0) {
      childSnapshots = snapshotAllChildren(frameChildren, {
        x: startPosX, y: startPosY, w: startW, h: startH
      })
    }
  }

  let resizeRaf = null
  let currentW = startW
  let currentH = startH
  let currentPosX = startPosX
  let currentPosY = startPosY

  const onMouseMove = (ev) => {
    if (resizeRaf) return
    resizeRaf = requestAnimationFrame(() => {
      resizeRaf = null
      const rawDx = (ev.clientX - startMouseX) / (store.zoom * totalScale.value)
      const rawDy = (ev.clientY - startMouseY) / (store.zoom * totalScale.value)

      // Rotate screen-space delta into the item's local coordinate system
      const localDx = rawDx * cosR + rawDy * sinR
      const localDy = -rawDx * sinR + rawDy * cosR

      // Apply sign so dragging outward from any handle increases size
      // For edge handles (signX=0 or signY=0), only use the non-zero axis delta
      const dx = signX !== 0 ? localDx * signX : 0
      const dy = signY !== 0 ? localDy * signY : 0

      let newW, newH
      const shiftHeld = ev.shiftKey

      if (isSingleAxis) {
        // Edge handles: only one axis changes, aspect ratio never applies
        newW = signX !== 0 ? Math.max(minW, Math.min(maxW, Math.round(startW + dx))) : startW
        newH = signY !== 0 ? Math.max(minH, Math.min(maxH, Math.round(startH + dy))) : startH
      } else if (isPresentationSlide) {
        newW = Math.max(minW, Math.min(maxW, Math.round(startW + dx)))
        newH = Math.max(minH, Math.min(maxH, Math.round(newW / (16 / 9))))
        newW = Math.round(newH * (16 / 9))
      } else if (freeResize && !shiftHeld) {
        newW = Math.max(minW, Math.min(maxW, Math.round(startW + dx)))
        newH = Math.max(minH, Math.min(maxH, Math.round(startH + dy)))
      } else {
        const absDx = Math.abs(dx)
        const absDy = Math.abs(dy)
        if (absDx >= absDy) {
          newW = Math.max(minW, Math.min(maxW, Math.round(startW + dx)))
          newH = Math.max(minH, Math.min(maxH, Math.round(newW / aspectRatio)))
          newW = Math.round(newH * aspectRatio)
        } else {
          newH = Math.max(minH, Math.min(maxH, Math.round(startH + dy)))
          newW = Math.max(minW, Math.min(maxW, Math.round(newH * aspectRatio)))
          newH = Math.round(newW / aspectRatio)
        }
      }

      // Size change in local space
      const dw = newW - startW
      const dh = newH - startH

      // Position correction: when resizing from TL/BL/TR corners, the opposite
      // corner must stay fixed in world space. Because CSS rotates around the
      // center of the (pos_x, pos_y, width, height) box, changing size shifts
      // the center. We compensate by adjusting pos_x/pos_y in world space.
      //
      // localOffsetX/Y = how much the top-left corner moves in local space
      const localOffX = (signX === -1) ? -dw : 0
      const localOffY = (signY === -1) ? -dh : 0

      // The center also shifts by half the size delta:
      //   centerShift_local = (dw/2 + localOffX, dh/2 + localOffY)
      // Rotate that center shift back to world space for the position fix.
      const csx = dw / 2 + localOffX
      const csy = dh / 2 + localOffY
      const worldCsx = csx * cosR - csy * sinR
      const worldCsy = csx * sinR + csy * cosR

      currentW = newW
      currentH = newH
      currentPosX = Math.round(startPosX + worldCsx - dw / 2)
      currentPosY = Math.round(startPosY + worldCsy - dh / 2)

      // Update reactive properties — Vue's itemStyle computed handles the DOM
      props.item.width = newW
      props.item.height = newH
      props.item.pos_x = currentPosX
      props.item.pos_y = currentPosY

      // --- Apply constraints to static-frame children during resize ---
      if (childSnapshots.length > 0) {
        applyAllConstraints(frameChildren, childSnapshots, {
          x: currentPosX, y: currentPosY, w: currentW, h: currentH
        })
      }
    })
  }

  const onMouseUp = () => {
    resizing.value = false
    if (resizeRaf) cancelAnimationFrame(resizeRaf)
    document.removeEventListener('mousemove', onMouseMove)
    document.removeEventListener('mouseup', onMouseUp)

    // Write final values to reactive store
    props.item.width = currentW
    props.item.height = currentH
    props.item.pos_x = currentPosX
    props.item.pos_y = currentPosY

    // Manually push undo with the ORIGINAL values
    store.pushUndo({
      type: 'update',
      itemId: props.item.id,
      previousData: { width: startW, height: startH, pos_x: startPosX, pos_y: startPosY },
      newData: { width: currentW, height: currentH, pos_x: currentPosX, pos_y: currentPosY }
    })
    // Single API call to persist (skipUndo because we already pushed it)
    emit('update', { width: currentW, height: currentH, pos_x: currentPosX, pos_y: currentPosY }, { skipUndo: true })

    // Re-arm action bar cooldown so lock button isn't immediately clickable
    resetActionBarCooldown()

    // --- Persist constraint-adjusted children ---
    if (childSnapshots.length > 0) {
      for (const child of frameChildren) {
        store.updateItem(child.id, {
          pos_x: child.pos_x, pos_y: child.pos_y,
          width: child.width, height: child.height
        }, { skipUndo: true })
      }
    }
  }

  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup', onMouseUp)
}

// ========================================
// AUDIO: Auto-play on viewport intersection
// ========================================
let _audioObserver = null

onMounted(() => {
  if (props.item.type !== 'audio') return
  if (!props.item.style_data?.audio_autoplay) return
  if (!props.item.url) return
  if (typeof IntersectionObserver === 'undefined') return

  _audioObserver = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          audioPlayerRef.value?.play()
        } else {
          audioPlayerRef.value?.pause()
        }
      }
    },
    { threshold: 0.3 }
  )
  if (itemEl.value) _audioObserver.observe(itemEl.value)
})

onUnmounted(() => {
  if (_audioObserver) { _audioObserver.disconnect(); _audioObserver = null }
})

// Re-create observer when autoplay setting changes
watch(() => props.item.style_data?.audio_autoplay, (val) => {
  if (_audioObserver) { _audioObserver.disconnect(); _audioObserver = null }
  if (!val || props.item.type !== 'audio' || !props.item.url) return
  if (typeof IntersectionObserver === 'undefined') return
  _audioObserver = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          audioPlayerRef.value?.play()
        } else {
          audioPlayerRef.value?.pause()
        }
      }
    },
    { threshold: 0.3 }
  )
  if (itemEl.value) _audioObserver.observe(itemEl.value)
})
</script>

<style scoped>
/* Image/video filename label — hidden by default, shown only on hover */
.image-name-label {
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}
.group:hover > * > .image-name-label,
.group:hover > .image-name-label,
.group:hover .image-name-label {
  opacity: 1;
}

/*
 * Ambient motion uses the standalone CSS `translate` property
 * which composes independently of the `transform` property
 * (used for canvas positioning). GPU-composited = butter-smooth.
 *
 * KEY: `linear` timing function + smoothly-varying keyframes = continuous
 * motion with no pause-move-pause jank. Each step changes gradually so
 * linear interpolation produces a fluid loop. `--m-dir` alternates direction
 * per element so nearby items don't move in lockstep.
 * --m-amp scales amplitude: 0.5=subtle, 1.5=normal, 3=lively
 */

/* Wobble for cards -- organic figure-8 loop, 8-step */
.motion-wobble {
  will-change: translate;
  animation: wobble var(--m-dur, 5s) linear var(--m-delay, 0s) infinite var(--m-dir, normal);
}
@keyframes wobble {
  0%, 100% { translate: 0 0; }
  12.5%    { translate: calc(1.2px * var(--m-amp, 1)) calc(0.8px * var(--m-amp, 1)); }
  25%      { translate: calc(1.6px * var(--m-amp, 1)) calc(-0.4px * var(--m-amp, 1)); }
  37.5%    { translate: calc(0.5px * var(--m-amp, 1)) calc(-1.4px * var(--m-amp, 1)); }
  50%      { translate: calc(-0.6px * var(--m-amp, 1)) calc(-0.6px * var(--m-amp, 1)); }
  62.5%    { translate: calc(-1.4px * var(--m-amp, 1)) calc(0.4px * var(--m-amp, 1)); }
  75%      { translate: calc(-1px * var(--m-amp, 1)) calc(1.3px * var(--m-amp, 1)); }
  87.5%    { translate: calc(-0.2px * var(--m-amp, 1)) calc(1px * var(--m-amp, 1)); }
}

/* Float for elements -- gentle hovering breath loop, 8-step */
.motion-float {
  will-change: translate;
  animation: floaty var(--m-dur, 6s) linear var(--m-delay, 0s) infinite var(--m-dir, normal);
}
@keyframes floaty {
  0%, 100% { translate: 0 0; }
  12.5%    { translate: calc(0.5px * var(--m-amp, 1)) calc(-1px * var(--m-amp, 1)); }
  25%      { translate: calc(1px * var(--m-amp, 1)) calc(-1.8px * var(--m-amp, 1)); }
  37.5%    { translate: calc(0.3px * var(--m-amp, 1)) calc(-2.2px * var(--m-amp, 1)); }
  50%      { translate: calc(-0.5px * var(--m-amp, 1)) calc(-1.3px * var(--m-amp, 1)); }
  62.5%    { translate: calc(-1px * var(--m-amp, 1)) calc(-0.3px * var(--m-amp, 1)); }
  75%      { translate: calc(-0.6px * var(--m-amp, 1)) calc(0.6px * var(--m-amp, 1)); }
  87.5%    { translate: calc(-0.1px * var(--m-amp, 1)) calc(0.3px * var(--m-amp, 1)); }
}

/* ======================================
 * DRAW-ON REVEAL ANIMATIONS
 * ======================================
 * Drawings: center-line stroked path with stroke-dashoffset → 0
 * Text: clip-path reveal from left to right
 */

/* Drawing stroke reveal: dashoffset animates from full length to 0 */
.draw-on-stroke {
  animation: drawOnReveal var(--draw-dur, 1s) ease-out var(--draw-delay, 0s) forwards;
}
@keyframes drawOnReveal {
  from { stroke-dashoffset: var(--draw-len, 100); }
  to   { stroke-dashoffset: 0; }
}

/* Subtle fade-in when filled strokes appear after draw-on completes */
.draw-on-fade-in {
  animation: drawOnFadeIn 0.3s ease-out forwards;
}
@keyframes drawOnFadeIn {
  from { opacity: 0; }
  to   { opacity: 1; }
}

/* Text reveal: clip-path sweeps from left to right */
.draw-on-text-reveal {
  animation: textReveal var(--text-draw-dur, 0.8s) ease-out forwards;
}
@keyframes textReveal {
  from { clip-path: inset(0 100% 0 0); }
  to   { clip-path: inset(0 0% 0 0); }
}

/* Contenteditable placeholder */
[contenteditable][data-placeholder]:empty::before {
  content: attr(data-placeholder);
  color: rgb(148 163 184);
  pointer-events: none;
  position: absolute;
  font-style: italic;
}
</style>

