<template>
  <div class="flex items-center gap-0.5 p-1 bg-white dark:bg-surface-800 rounded-2xl shadow-lg border border-surface-200 dark:border-surface-700">

    <!-- ─── TEXT (always visible) ─── -->
    <button
      @click="$emit('add-item', 'text')"
      class="p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors"
      title="Text Block (T)"
    >
      <span class="material-symbols-rounded text-xl">text_fields</span>
    </button>

    <!-- ─── DRAW (always visible) ─── -->
    <button
      @click="$emit('toggle-draw')"
      class="p-2 rounded-xl transition-all duration-150"
      :class="drawMode
        ? 'bg-primary-500/15 dark:bg-primary-500/20 text-primary-500 ring-1 ring-primary-500/40'
        : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400'"
      title="Draw (D)"
    >
      <span class="material-symbols-rounded text-xl">draw</span>
    </button>

    <!-- ─── SLIDES DROPDOWN ─── -->
    <div class="relative" ref="slidesRef">
      <button
        @click="$emit('add-item', 'slide')"
        class="flex items-center gap-0.5 p-2 rounded-xl transition-all duration-150"
        :class="(slidesVisible || filmstripOpen)
          ? 'bg-primary-500/15 dark:bg-primary-500/20 text-primary-500 ring-1 ring-primary-500/40'
          : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400'"
        title="Add Slide (S)"
      >
        <span class="material-symbols-rounded text-xl">slideshow</span>
        <span
          class="material-symbols-rounded text-xs -ml-0.5 opacity-60 cursor-pointer"
          @click.stop="toggleMenu('slides')"
        >arrow_drop_down</span>
      </button>
      <transition name="tb-menu">
        <div v-if="openMenu === 'slides'" class="absolute bottom-full left-0 mb-2 w-52 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50">
          <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Slides</div>
          <button
            @click="$emit('add-item', 'slide'); openMenu = null"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">add</span>
            <span class="flex-1 text-left">Add Slide</span>
            <span class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">S</span>
          </button>
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
          <button
            @click="$emit('toggle-slides-visible')"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors"
            :class="slidesVisible
              ? 'text-primary-500 dark:text-primary-400 bg-primary-500/10'
              : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'"
          >
            <span class="material-symbols-rounded text-lg">subtitles</span>
            <span class="flex-1 text-left">Show Slides</span>
            <span class="w-3 h-3 rounded-sm" :class="slidesVisible ? 'bg-primary-500' : 'border border-surface-300 dark:border-surface-500'" />
          </button>
          <button
            @click="$emit('toggle-filmstrip')"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors"
            :class="filmstripOpen
              ? 'text-primary-500 dark:text-primary-400 bg-primary-500/10'
              : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'"
          >
            <span class="material-symbols-rounded text-lg">view_carousel</span>
            <span class="flex-1 text-left">Film Strip</span>
            <span class="w-3 h-3 rounded-sm" :class="filmstripOpen ? 'bg-primary-500' : 'border border-surface-300 dark:border-surface-500'" />
          </button>
        </div>
      </transition>
    </div>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-700"></div>

    <!-- ─── SHAPES DROPDOWN (Pen, Line, shapes, layout) ─── -->
    <div class="relative" ref="shapesRef">
      <button
        @click.stop="toggleMenu('shapes')"
        class="flex items-center gap-0.5 p-2 rounded-xl transition-all duration-150"
        :class="(penMode || lineMode)
          ? 'bg-primary-500/15 dark:bg-primary-500/20 text-primary-500 ring-1 ring-primary-500/40'
          : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400'"
        title="Shapes & Tools"
      >
        <span class="material-symbols-rounded text-xl">{{ lastShapeIcon }}</span>
        <span class="material-symbols-rounded text-xs -ml-0.5 opacity-60">arrow_drop_down</span>
      </button>
      <transition name="tb-menu">
        <div v-if="openMenu === 'shapes'" class="absolute bottom-full left-0 mb-2 w-52 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50">
          <!-- Tools section -->
          <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Tools</div>
          <button
            @click="onToolItemClick('pen')"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors"
            :class="penMode
              ? 'text-primary-500 dark:text-primary-400 bg-primary-500/10'
              : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'"
          >
            <span class="material-symbols-rounded text-lg">ink_pen</span>
            <span class="flex-1 text-left">Pen Tool</span>
            <span class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">P</span>
          </button>
          <button
            @click="onToolItemClick('line')"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors"
            :class="lineMode
              ? 'text-primary-500 dark:text-primary-400 bg-primary-500/10'
              : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'"
          >
            <span class="material-symbols-rounded text-lg">pen_size_1</span>
            <span class="flex-1 text-left">Line Tool</span>
            <span class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">L</span>
          </button>

          <!-- Shapes divider -->
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
          <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Shapes</div>
          <button
            v-for="s in shapeItems"
            :key="s.type"
            @click="onShapeClick(s)"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">{{ s.icon }}</span>
            <span class="flex-1 text-left">{{ s.label }}</span>
            <span v-if="s.shortcut" class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">{{ s.shortcut }}</span>
          </button>

          <!-- Layout divider -->
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
          <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Layout</div>
          <button
            v-for="l in layoutItems"
            :key="l.type"
            @click="onShapeClick(l)"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">{{ l.icon }}</span>
            <span class="flex-1 text-left">{{ l.label }}</span>
            <span v-if="l.shortcut" class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">{{ l.shortcut }}</span>
          </button>
        </div>
      </transition>
    </div>

    <!-- ─── CONTENT DROPDOWN (grouped with dividers) ─── -->
    <div class="relative" ref="contentRef">
      <button
        @click.stop="toggleMenu('content')"
        class="flex items-center gap-0.5 p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors"
        title="Add Content"
      >
        <span class="material-symbols-rounded text-xl">{{ lastContentIcon }}</span>
        <span class="material-symbols-rounded text-xs -ml-0.5 opacity-60">arrow_drop_down</span>
      </button>
      <transition name="tb-menu">
        <div v-if="openMenu === 'content'" class="absolute bottom-full left-0 mb-2 w-52 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50">
          <!-- Notes -->
          <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Notes</div>
          <button
            v-for="c in contentNotes"
            :key="c.type"
            @click="onContentClick(c)"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">{{ c.icon }}</span>
            <span class="flex-1 text-left">{{ c.label }}</span>
            <span v-if="c.shortcut" class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">{{ c.shortcut }}</span>
          </button>

          <!-- Media divider -->
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
          <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Media</div>
          <button
            v-for="c in contentMedia"
            :key="c.type"
            @click="onContentClick(c)"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">{{ c.icon }}</span>
            <span class="flex-1 text-left">{{ c.label }}</span>
          </button>

          <!-- Data divider -->
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
          <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Data</div>
          <button
            v-for="c in contentData"
            :key="c.type"
            @click="onContentClick(c)"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">{{ c.icon }}</span>
            <span class="flex-1 text-left">{{ c.label }}</span>
          </button>
        </div>
      </transition>
    </div>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-700"></div>

    <!-- ─── BOOLEAN OPS DROPDOWN (visible when 2+ shapes selected) ─── -->
    <div v-if="canBoolean" class="relative" ref="booleanRef">
      <button
        @click.stop="toggleMenu('boolean')"
        class="flex items-center gap-0.5 p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors"
        title="Boolean Operations"
      >
        <span class="material-symbols-rounded text-xl">join_inner</span>
        <span class="material-symbols-rounded text-xs -ml-0.5 opacity-60">arrow_drop_down</span>
      </button>
      <transition name="tb-menu">
        <div v-if="openMenu === 'boolean'" class="absolute bottom-full left-0 mb-2 w-56 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50">
          <button
            v-for="op in booleanOps"
            :key="op.id"
            @click="onBooleanClick(op.id)"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">{{ op.icon }}</span>
            <span class="flex-1 text-left">{{ op.label }}</span>
            <span class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">{{ op.shortcut }}</span>
          </button>
        </div>
      </transition>
    </div>

    <!-- ─── IMPORT DROPDOWN ─── -->
    <div class="relative" ref="importRef">
      <button
        @click.stop="toggleMenu('import')"
        class="flex items-center gap-0.5 p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors"
        title="Import"
      >
        <span class="material-symbols-rounded text-xl">cloud_upload</span>
        <span class="material-symbols-rounded text-xs -ml-0.5 opacity-60">arrow_drop_down</span>
      </button>
      <transition name="tb-menu">
        <div v-if="openMenu === 'import'" class="absolute bottom-full left-0 mb-2 w-52 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50">
          <button
            v-for="imp in importItems"
            :key="imp.id"
            @click="onImportClick(imp)"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">{{ imp.icon }}</span>
            <span class="flex-1 text-left">{{ imp.label }}</span>
            <span v-if="imp.shortcut" class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">{{ imp.shortcut }}</span>
          </button>
        </div>
      </transition>
    </div>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-700"></div>

    <!-- ─── VIEW DROPDOWN ─── -->
    <div class="relative" ref="viewRef">
      <button
        @click.stop="toggleMenu('view')"
        class="flex items-center gap-0.5 p-2 rounded-xl transition-colors"
        :class="(snapToGrid || showRulers || measureMode || (measureVisible && measureCount > 0))
          ? 'bg-primary-500/10 text-primary-500 dark:text-primary-400'
          : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400'"
        title="View Options"
      >
        <span class="material-symbols-rounded text-xl">tune</span>
        <span class="material-symbols-rounded text-xs -ml-0.5 opacity-60">arrow_drop_down</span>
      </button>
      <transition name="tb-menu">
        <div v-if="openMenu === 'view'" class="absolute bottom-full left-0 mb-2 w-56 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50">
          <!-- Snap to Grid -->
          <button @click="$emit('toggle-snap-grid')" class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors" :class="snapToGrid ? 'text-primary-500 dark:text-primary-400 bg-primary-500/10' : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'">
            <span class="material-symbols-rounded text-lg">grid_4x4</span>
            <span class="flex-1 text-left">Snap to Grid</span>
            <span class="w-3 h-3 rounded-sm" :class="snapToGrid ? 'bg-primary-500' : 'border border-surface-300 dark:border-surface-500'" />
          </button>
          <!-- Snap to Center (smart guides) -->
          <button @click="$emit('toggle-snap-center')" class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors" :class="snapToCenter ? 'text-primary-500 dark:text-primary-400 bg-primary-500/10' : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'">
            <span class="material-symbols-rounded text-lg">align_horizontal_center</span>
            <span class="flex-1 text-left">Snap to Center</span>
            <span class="w-3 h-3 rounded-sm" :class="snapToCenter ? 'bg-primary-500' : 'border border-surface-300 dark:border-surface-500'" />
          </button>
          <!-- Rulers -->
          <button @click="$emit('toggle-rulers')" class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors" :class="showRulers ? 'text-primary-500 dark:text-primary-400 bg-primary-500/10' : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'">
            <span class="material-symbols-rounded text-lg">straighten</span>
            <span class="flex-1 text-left">Rulers</span>
            <span class="w-3 h-3 rounded-sm" :class="showRulers ? 'bg-primary-500' : 'border border-surface-300 dark:border-surface-500'" />
          </button>
          <!-- Measure tool row: tool toggle + visibility toggle -->
          <div class="w-full flex items-center gap-1 px-1.5 py-1">
            <button @click="$emit('toggle-measure'); openMenu = null" class="flex-1 flex items-center gap-3 px-1.5 py-1.5 rounded-lg text-sm transition-colors" :class="measureMode ? 'text-primary-500 dark:text-primary-400 bg-primary-500/10' : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'">
              <span class="material-symbols-rounded text-lg">square_foot</span>
              <span class="flex-1 text-left">Measure Tool</span>
              <span class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">M</span>
            </button>
            <button
              v-if="measureCount > 0"
              @click.stop="$emit('toggle-measure-visibility')"
              class="p-1.5 rounded-lg transition-colors flex-shrink-0"
              :class="measureVisible
                ? 'text-primary-500 dark:text-primary-400 hover:bg-primary-500/10'
                : 'text-surface-400 dark:text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
              :title="measureVisible ? 'Hide measurements' : 'Show measurements'"
            >
              <span class="material-symbols-rounded text-lg">{{ measureVisible ? 'visibility' : 'visibility_off' }}</span>
            </button>
          </div>
          <!-- Measure line style controls -->
          <div class="px-3 py-2 space-y-2 border-t border-surface-200/60 dark:border-surface-700/60 mt-1">
            <div class="flex items-center justify-between">
              <span class="text-[10px] font-semibold uppercase tracking-wider text-surface-400 dark:text-surface-500">Line Style</span>
              <label class="relative w-5 h-5 rounded cursor-pointer border border-surface-300 dark:border-surface-600 overflow-hidden flex-shrink-0" :style="{ backgroundColor: measureLineColor }" title="Custom color">
                <input type="color" :value="measureLineColor" @input="$emit('set-measure-color', $event.target.value)" class="absolute inset-0 opacity-0 cursor-pointer" />
              </label>
            </div>
            <div class="flex items-center gap-1.5">
              <button
                v-for="c in measurePresetColors" :key="c"
                @click="$emit('set-measure-color', c)"
                class="w-[22px] h-[22px] rounded-full transition-all flex-shrink-0"
                :class="measureLineColor === c
                  ? 'ring-2 ring-offset-1 ring-offset-white dark:ring-offset-surface-800 ring-primary-400 scale-110'
                  : 'hover:scale-110 ring-1 ring-surface-200 dark:ring-surface-600'"
                :style="{ backgroundColor: c }"
              />
            </div>
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">line_weight</span>
              <input type="range" min="0.5" max="5" step="0.5" :value="measureLineWidth" @input="$emit('set-measure-width', parseFloat($event.target.value))" class="flex-1 h-1 accent-primary-500 cursor-pointer" />
              <span class="text-[10px] text-surface-400 w-6 text-right tabular-nums">{{ measureLineWidth }}px</span>
            </div>
          </div>
          <!-- Clear all measurements (with confirmation) -->
          <button v-if="measureCount > 0" @click="showClearMeasureConfirm = true" class="w-full flex items-center gap-3 px-3 py-2 text-sm text-red-500 dark:text-red-400 hover:bg-red-500/10 transition-colors">
            <span class="material-symbols-rounded text-lg">delete_sweep</span>
            <span class="flex-1 text-left">Clear All ({{ measureCount }})</span>
          </button>
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
          <!-- Fit screen -->
          <button @click="$emit('fit-screen'); openMenu = null" class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors">
            <span class="material-symbols-rounded text-lg">fit_screen</span>
            <span class="flex-1 text-left">Fit to Screen</span>
            <span class="text-[11px] text-surface-400 dark:text-surface-500 font-mono">Ctrl+1</span>
          </button>
        </div>
      </transition>
    </div>

    <!-- ─── ZOOM (compact inline) ─── -->
    <div class="flex items-center">
      <button @click="$emit('zoom-out')" title="Zoom out" class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 transition-colors">
        <span class="material-symbols-rounded text-lg">remove</span>
      </button>
      <button @click="$emit('zoom-reset')" :title="`${Math.round(zoom * 100)}% (click to reset)`" class="px-1.5 py-1 text-[11px] font-medium text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors min-w-[40px] text-center tabular-nums">
        {{ Math.round(zoom * 100) }}%
      </button>
      <button @click="$emit('zoom-in')" title="Zoom in" class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 transition-colors">
        <span class="material-symbols-rounded text-lg">add</span>
      </button>
    </div>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-700"></div>

    <!-- ─── UNDO / REDO ─── -->
    <div class="flex items-center">
      <button @click="$emit('undo')" :disabled="undoCount === 0" title="Undo (Ctrl+Z)" class="p-1.5 rounded-lg transition-colors" :class="undoCount > 0 ? 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400' : 'text-surface-300 dark:text-surface-600 cursor-not-allowed'">
        <span class="material-symbols-rounded text-lg">undo</span>
      </button>
      <button @click="$emit('redo')" :disabled="redoCount === 0" title="Redo (Ctrl+Shift+Z)" class="p-1.5 rounded-lg transition-colors" :class="redoCount > 0 ? 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400' : 'text-surface-300 dark:text-surface-600 cursor-not-allowed'">
        <span class="material-symbols-rounded text-lg">redo</span>
      </button>
    </div>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-700"></div>

    <!-- ─── MOTION (single button + right-click mega menu) ─── -->
    <div class="relative" ref="motionMenuRef">
      <button
        @click="$emit('toggle-motion')"
        @contextmenu.prevent.stop="toggleMenu('motion')"
        :title="motionEnabled ? 'Motion ON (right-click for settings)' : 'Motion OFF (right-click for settings)'"
        class="p-2 rounded-xl transition-all duration-150"
        :class="motionEnabled
          ? 'bg-primary-500/15 dark:bg-primary-500/20 text-primary-500 ring-1 ring-primary-500/40'
          : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400'"
      >
        <span class="material-symbols-rounded text-xl">{{ motionEnabled ? 'animation' : 'motion_photos_off' }}</span>
      </button>
      <!-- Motion settings mega dropdown -->
      <transition name="tb-menu">
        <div v-if="openMenu === 'motion'" class="absolute bottom-full right-0 mb-2 w-64 max-h-[70vh] overflow-y-auto bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 z-50">
          <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Motion Settings</div>

          <!-- Toggle rows -->
          <div v-for="mt in motionToggles" :key="mt.id" class="flex items-center justify-between px-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer" @click="$emit(mt.event)">
            <span class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
              <span class="material-symbols-rounded text-base">{{ mt.icon }}</span> {{ mt.label }}
            </span>
            <span :class="['relative inline-flex h-5 w-10 shrink-0 rounded-full transition-colors duration-200', mt.value ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']">
              <span :class="['inline-block h-4 w-4 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5', mt.value ? 'translate-x-[22px]' : 'translate-x-[2px]']" />
            </span>
          </div>

          <!-- Draw-On trigger -->
          <div v-if="motionDrawOn" class="px-3 py-1.5">
            <div class="flex items-center gap-1">
              <span class="text-[10px] text-surface-400 dark:text-surface-400 w-14 truncate">Trigger</span>
              <button
                @click="$emit('set-motion-draw-on-trigger', motionDrawOnTrigger === 'viewport' ? 'always' : 'viewport')"
                class="flex-1 flex items-center justify-center gap-1 text-[10px] px-2 py-1 rounded-lg border transition-colors"
                :class="motionDrawOnTrigger === 'viewport'
                  ? 'bg-primary-50 dark:bg-primary-900/20 border-primary-300 dark:border-primary-700 text-primary-600 dark:text-primary-400'
                  : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400'"
              >
                <span class="material-symbols-rounded" style="font-size: 12px;">{{ motionDrawOnTrigger === 'viewport' ? 'visibility' : 'replay' }}</span>
                {{ motionDrawOnTrigger === 'viewport' ? 'On Viewport Enter' : 'Always Replay' }}
              </button>
            </div>
          </div>

          <!-- Draw-On speed -->
          <div v-if="motionDrawOn" class="px-3 pb-1">
            <div class="flex items-center gap-2">
              <span class="text-[10px] text-surface-400 w-14 truncate">Speed</span>
              <input type="range" min="0.3" max="3" step="0.1" :value="motionDrawOnSpeed" @input="$emit('set-motion-draw-on-speed', parseFloat($event.target.value))" class="flex-1 h-1 accent-primary-500 cursor-pointer" />
              <span class="text-[10px] text-surface-400 w-6 text-right">{{ motionDrawOnSpeed.toFixed(1) }}</span>
            </div>
          </div>

          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1.5 mx-3"></div>

          <!-- Sliders -->
          <div v-for="sl in motionSliders" :key="sl.label" class="px-3 pb-1.5">
            <div class="text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500 mb-1">{{ sl.label }}</div>
            <div class="flex items-center gap-2">
              <input type="range" :min="sl.min" :max="sl.max" :step="sl.step" :value="sl.value" @input="$emit(sl.event, parseFloat($event.target.value))" class="flex-1 h-1 accent-primary-500 cursor-pointer" />
              <span class="text-[10px] text-surface-400 w-6 text-right tabular-nums">{{ sl.value.toFixed(1) }}</span>
            </div>
          </div>

          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1.5 mx-3"></div>
          <div class="px-3 pb-1.5">
            <div class="text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500 mb-1">Line Wave</div>
            <div v-for="lsl in lineSliders" :key="lsl.label" class="flex items-center gap-2 mb-1">
              <span class="text-[10px] text-surface-400 w-12 truncate">{{ lsl.label }}</span>
              <input type="range" :min="lsl.min" :max="lsl.max" :step="lsl.step" :value="lsl.value" @input="$emit(lsl.event, parseFloat($event.target.value))" class="flex-1 h-1 accent-primary-500 cursor-pointer" />
              <span class="text-[10px] text-surface-400 w-6 text-right tabular-nums">{{ lsl.value.toFixed(1) }}</span>
            </div>
          </div>
        </div>
      </transition>
    </div>

    <!-- ─── PRESENT (conditional — only shown when slides exist) ─── -->
    <template v-if="hasSlides">
      <div class="w-px h-5 bg-surface-200 dark:bg-surface-700"></div>
      <div class="flex items-center gap-0.5">
        <button @click="$emit('start-presentation')" title="Present (fullscreen)" class="p-2 rounded-xl hover:bg-amber-500/15 dark:hover:bg-amber-500/20 text-amber-500 dark:text-amber-400 hover:ring-1 hover:ring-amber-500/40 transition-all duration-150">
          <span class="material-symbols-rounded text-xl">present_to_all</span>
        </button>
        <button @click="$emit('start-scroll-story')" title="Scroll Story" class="p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors">
          <span class="material-symbols-rounded text-xl">auto_stories</span>
        </button>
      </div>
    </template>

    <!-- ─── EXPORT dropdown ─── -->
    <div class="w-px h-5 bg-surface-200 dark:bg-surface-700"></div>
    <div class="relative" ref="exportRef">
      <button
        @click="toggleMenu('export')"
        class="relative flex items-center gap-0.5 p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors"
        :title="offlineCacheStatus === 'stale' ? 'Export -- Offline cache outdated' : 'Export'"
      >
        <span class="material-symbols-rounded text-xl">download</span>
        <span class="material-symbols-rounded text-xs -ml-0.5 opacity-60">arrow_drop_down</span>
        <span
          v-if="offlineCacheStatus === 'stale'"
          class="absolute top-1 right-1 w-2 h-2 rounded-full bg-amber-400 ring-2 ring-white dark:ring-surface-800"
        />
        <span
          v-else-if="offlineCacheStatus === 'saved'"
          class="absolute top-1 right-1 w-2 h-2 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-surface-800"
        />
      </button>
      <transition name="tb-menu">
        <div v-if="openMenu === 'export'" class="absolute bottom-full right-0 mb-2 w-56 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-50">
          <button
            @click="$emit('export-pptx'); openMenu = null"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">slideshow</span>
            <span class="flex-1 text-left">PowerPoint (.pptx)</span>
          </button>
          <button
            @click="$emit('export-pdf'); openMenu = null"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">picture_as_pdf</span>
            <span class="flex-1 text-left">PDF (.pdf)</span>
          </button>
          <button
            v-if="hasSlides"
            @click="$emit('export-presentation'); openMenu = null"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white transition-colors"
          >
            <span class="material-symbols-rounded text-lg">language</span>
            <span class="flex-1 text-left">Offline Presentation (HTML)</span>
          </button>
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
          <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Offline Cache</div>
          <button
            @click="$emit('save-offline'); openMenu = null"
            :disabled="offlineSaving"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors"
            :class="offlineSaving
              ? 'text-surface-400 dark:text-surface-500 cursor-wait'
              : 'text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'"
          >
            <span class="material-symbols-rounded text-lg">download_for_offline</span>
            <span class="flex-1 text-left">{{ offlineSaving ? 'Saving...' : 'Save for Offline' }}</span>
            <span
              v-if="offlineCacheStatus === 'saved'"
              class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"
              title="Cached"
            />
            <span
              v-else-if="offlineCacheStatus === 'stale'"
              class="w-2 h-2 rounded-full bg-amber-400 shrink-0"
              title="Cache outdated"
            />
          </button>
          <button
            v-if="offlineCacheStatus !== 'none'"
            @click="$emit('clear-offline-cache'); openMenu = null"
            class="w-full flex items-center gap-3 px-3 py-2 text-sm text-red-500 dark:text-red-400 hover:bg-red-500/10 transition-colors"
          >
            <span class="material-symbols-rounded text-lg">delete_sweep</span>
            <span class="flex-1 text-left">Clear Offline Cache</span>
          </button>
          <div
            v-if="offlineCacheStatus !== 'none'"
            class="px-3 py-1.5 text-[10px] text-surface-400 dark:text-surface-500 flex items-center gap-1.5"
          >
            <span class="material-symbols-rounded text-xs">info</span>
            {{ offlineCachedCount }} images cached
            <span v-if="offlineCacheStatus === 'stale'" class="text-amber-500"> -- changes detected, re-save recommended</span>
          </div>
        </div>
      </transition>
    </div>

    <!-- Clear Measurements Confirmation Dialog -->
    <Teleport to="body">
      <transition name="fade">
        <div v-if="showClearMeasureConfirm" class="fixed inset-0 z-[9999] flex items-center justify-center">
          <div class="absolute inset-0 bg-black/40" @click="showClearMeasureConfirm = false" />
          <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl p-6 w-80 space-y-4">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-2xl text-red-500">delete_sweep</span>
              <h3 class="text-base font-semibold text-surface-900 dark:text-white">Clear Measurements</h3>
            </div>
            <p class="text-sm text-surface-600 dark:text-surface-400">
              This will permanently delete all <strong>{{ measureCount }}</strong> measurement{{ measureCount !== 1 ? 's' : '' }} from this board. This cannot be undone.
            </p>
            <div class="flex items-center gap-2 justify-end">
              <button @click="showClearMeasureConfirm = false" class="px-4 py-2 text-sm rounded-full border border-surface-300 dark:border-surface-600 text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
                Cancel
              </button>
              <button @click="$emit('clear-measurements'); showClearMeasureConfirm = false; openMenu = null" class="px-4 py-2 text-sm rounded-full bg-red-500 text-white hover:bg-red-600 transition-colors">
                Clear All
              </button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { useAddons } from '@/composables/useAddons'

const { kanbanBoardsEnabled } = useAddons()

const store = useMoodBoardsStore()

const props = defineProps({
  zoom: { type: Number, default: 1 },
  drawMode: { type: Boolean, default: false },
  penMode: { type: Boolean, default: false },
  lineMode: { type: Boolean, default: false },
  snapToGrid: { type: Boolean, default: false },
  snapToCenter: { type: Boolean, default: true },
  showRulers: { type: Boolean, default: false },
  measureMode: { type: Boolean, default: false },
  measureVisible: { type: Boolean, default: true },
  measureCount: { type: Number, default: 0 },
  measureLineColor: { type: String, default: '#0ea5e9' },
  measureLineWidth: { type: Number, default: 1.5 },
  hasSlides: { type: Boolean, default: false },
  filmstripOpen: { type: Boolean, default: false },
  motionEnabled: { type: Boolean, default: false },
  motionCards: { type: Boolean, default: true },
  motionElements: { type: Boolean, default: true },
  motionLines: { type: Boolean, default: true },
  motionIntensity: { type: Number, default: 1.5 },
  motionCardIntensity: { type: Number, default: 1 },
  motionSpeed: { type: Number, default: 1 },
  motionLineWave: { type: Number, default: 2 },
  motionLineSpeed: { type: Number, default: 0.5 },
  motionLineDensity: { type: Number, default: 0.3 },
  motionDrawOn: { type: Boolean, default: true },
  motionDrawOnTrigger: { type: String, default: 'viewport' },
  motionDrawOnSpeed: { type: Number, default: 1 },
  slidesVisible: { type: Boolean, default: false },
  undoCount: { type: Number, default: 0 },
  redoCount: { type: Number, default: 0 },
  offlineCacheStatus: { type: String, default: 'none' },
  offlineCachedCount: { type: Number, default: 0 },
  offlineSaving: { type: Boolean, default: false },
})

const emit = defineEmits([
  'add-item',
  'toggle-draw', 'toggle-pen', 'toggle-line',
  'toggle-snap-grid', 'toggle-snap-center', 'toggle-rulers', 'toggle-measure', 'toggle-measure-visibility', 'clear-measurements', 'set-measure-color', 'set-measure-width',
  'zoom-in', 'zoom-out', 'zoom-reset', 'fit-screen',
  'open-drive-picker', 'open-calendar-picker', 'open-board-picker', 'trigger-file-upload',
  'toggle-filmstrip', 'start-presentation', 'start-scroll-story', 'export-presentation', 'export-pptx', 'export-pdf',
  'toggle-motion', 'toggle-motion-cards', 'toggle-motion-elements', 'toggle-motion-lines',
  'set-motion-intensity', 'set-motion-card-intensity', 'set-motion-speed',
  'set-motion-line-wave', 'set-motion-line-speed', 'set-motion-line-density',
  'toggle-motion-draw-on', 'set-motion-draw-on-trigger', 'set-motion-draw-on-speed',
  'toggle-slides-visible',
  'undo', 'redo',
  'save-offline', 'clear-offline-cache',
])

// ── Measure presets ──
const measurePresetColors = ['#0ea5e9', '#f43f5e', '#22c55e', '#f59e0b', '#8b5cf6', '#ffffff', '#000000']
const isMeasureCustomColor = computed(() => !measurePresetColors.includes(props.measureLineColor))

// ── Dropdown state ──
const openMenu = ref(null)
const showClearMeasureConfirm = ref(false)
const shapesRef = ref(null)
const slidesRef = ref(null)
const booleanRef = ref(null)
const contentRef = ref(null)
const importRef = ref(null)
const viewRef = ref(null)
const motionMenuRef = ref(null)
const exportRef = ref(null)

function toggleMenu(menu) {
  openMenu.value = openMenu.value === menu ? null : menu
}

// Close on outside click
function onDocClick(e) {
  const refs = [shapesRef, slidesRef, booleanRef, contentRef, importRef, viewRef, motionMenuRef, exportRef]
  if (openMenu.value && !refs.some(r => r.value?.contains(e.target))) {
    openMenu.value = null
  }
}
onMounted(() => document.addEventListener('click', onDocClick, true))
onUnmounted(() => document.removeEventListener('click', onDocClick, true))

// ── Shapes dropdown (shapes only) ──
const lastShapeIcon = ref('square')
const shapeItems = [
  { type: 'shape',          icon: 'square',          label: 'Rectangle', shortcut: 'R' },
  { type: 'shape_circle',   icon: 'circle',          label: 'Ellipse',   shortcut: 'O' },
  { type: 'shape_triangle', icon: 'change_history',  label: 'Triangle',  shortcut: '' },
  { type: 'shape_star',     icon: 'star',            label: 'Star',      shortcut: '' },
]
const layoutItems = [
  { type: 'frame',  icon: 'crop_free',   label: 'Artboard',  shortcut: 'F' },
  { type: 'column', icon: 'view_column', label: 'Column', shortcut: '' },
]

function onToolItemClick(tool) {
  if (tool === 'pen') emit('toggle-pen')
  else if (tool === 'line') emit('toggle-line')
  openMenu.value = null
}

function onShapeClick(s) {
  lastShapeIcon.value = s.icon
  if (s.type === 'shape_circle') {
    emit('add-item', 'shape', { shape_type: 'circle' })
  } else if (s.type === 'shape_triangle') {
    emit('add-item', 'shape', { shape_type: 'triangle' })
  } else if (s.type === 'shape_star') {
    emit('add-item', 'shape', { shape_type: 'star' })
  } else {
    emit('add-item', s.type)
  }
  openMenu.value = null
}

// ── Content dropdown (grouped) ──
const lastContentIcon = ref('sticky_note_2')
const contentNotes = [
  { type: 'note',      icon: 'sticky_note_2', label: 'Sticky Note', shortcut: 'N' },
  { type: 'todo_list', icon: 'checklist',     label: 'Checklist',   shortcut: '' },
]
const contentMedia = [
  { type: 'image',     icon: 'image',         label: 'Image' },
  { type: 'image_set', icon: 'photo_library', label: 'Image Set' },
  { type: 'video',     icon: 'videocam',      label: 'Video' },
  { type: 'youtube',   icon: 'smart_display', label: 'YouTube' },
  { type: 'audio',     icon: 'graphic_eq',    label: 'Audio' },
]
const contentData = [
  { type: 'link',         icon: 'link',        label: 'Link / URL' },
  { type: 'table',        icon: 'table_chart', label: 'Table' },
  { type: 'color_swatch', icon: 'palette',     label: 'Color Swatch' },
]

function onContentClick(c) {
  lastContentIcon.value = c.icon
  emit('add-item', c.type)
  openMenu.value = null
}

// ── Import dropdown (boards gated by kanban_boards addon) ──
const importItems = computed(() => {
  const items = [
    { id: 'drive',    icon: 'cloud',          label: 'From Drive',       shortcut: '',        event: 'open-drive-picker' },
    { id: 'upload',   icon: 'upload_file',    label: 'Upload Files',     shortcut: 'Ctrl+U',  event: 'trigger-file-upload' },
    { id: 'calendar', icon: 'calendar_month', label: 'Calendar Event',   shortcut: '',        event: 'open-calendar-picker' },
  ]
  if (kanbanBoardsEnabled.value) {
    items.push({ id: 'boards', icon: 'dashboard', label: 'From Todo Boards', shortcut: '', event: 'open-board-picker' })
  }
  return items
})

function onImportClick(imp) {
  emit(imp.event)
  openMenu.value = null
}

// ── Boolean operations ──
const canBoolean = computed(() => store.canBooleanOp())

const booleanOps = [
  { id: 'union',     icon: 'join_full',    label: 'Union',     shortcut: 'Alt+Shift+U' },
  { id: 'subtract',  icon: 'join_left',    label: 'Subtract',  shortcut: 'Alt+Shift+S' },
  { id: 'intersect', icon: 'join_inner',   label: 'Intersect', shortcut: 'Alt+Shift+I' },
  { id: 'exclude',   icon: 'join_right',   label: 'Exclude',   shortcut: 'Alt+Shift+E' },
  { id: 'flatten',   icon: 'layers_clear', label: 'Flatten',   shortcut: 'Alt+Shift+F' },
]

async function onBooleanClick(opId) {
  openMenu.value = null
  await store.booleanOp(opId)
}

// ── Motion toggles data ──
const motionToggles = computed(() => [
  { id: 'cards',    icon: 'sticky_note_2', label: 'Cards',    event: 'toggle-motion-cards',    value: props.motionCards },
  { id: 'elements', icon: 'image',         label: 'Elements', event: 'toggle-motion-elements', value: props.motionElements },
  { id: 'lines',    icon: 'trending_flat', label: 'Lines',    event: 'toggle-motion-lines',    value: props.motionLines },
  { id: 'drawOn',   icon: 'draw',         label: 'Draw-On',  event: 'toggle-motion-draw-on',  value: props.motionDrawOn },
])

const motionSliders = computed(() => [
  { label: 'Elements Wobble', value: props.motionIntensity,     min: 0, max: 5, step: 0.1, event: 'set-motion-intensity' },
  { label: 'Cards Wobble',    value: props.motionCardIntensity, min: 0, max: 5, step: 0.1, event: 'set-motion-card-intensity' },
  { label: 'Animation Speed', value: props.motionSpeed,         min: 0.3, max: 2, step: 0.1, event: 'set-motion-speed' },
])

const lineSliders = computed(() => [
  { label: 'Amount',  value: props.motionLineWave,   min: 0, max: 5, step: 0.1, event: 'set-motion-line-wave' },
  { label: 'Speed',   value: props.motionLineSpeed,  min: 0.1, max: 2, step: 0.1, event: 'set-motion-line-speed' },
  { label: 'Density', value: props.motionLineDensity, min: 0.1, max: 1, step: 0.05, event: 'set-motion-line-density' },
])
</script>

<style scoped>
.tb-menu-enter-active,
.tb-menu-leave-active {
  transition: opacity 0.12s ease, transform 0.12s ease;
}
.tb-menu-enter-from,
.tb-menu-leave-to {
  opacity: 0;
  transform: translateY(4px);
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
