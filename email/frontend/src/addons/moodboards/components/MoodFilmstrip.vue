<template>
  <transition name="filmstrip-slide">
    <div
      v-if="store.showFilmstrip && store.presentationSlides.length > 0"
      class="absolute bottom-20 left-4 right-4 z-30"
    >
      <div class="bg-white/95 dark:bg-surface-800/95 backdrop-blur-sm rounded-2xl shadow-xl border border-surface-200 dark:border-surface-700 px-3 py-2.5 flex flex-col gap-2">
        <!-- Header row -->
        <div class="flex items-center justify-between px-1">
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider flex items-center gap-1.5">
              <span class="material-symbols-rounded text-sm">slideshow</span>
              Slides ({{ store.presentationSlides.length }})
            </span>
            <!-- Select All / Deselect -->
            <button
              @click="selectedSlideIds.size === store.presentationSlides.length ? deselectAllSlides() : selectAllSlides()"
              class="px-2 py-0.5 rounded-full text-[10px] font-medium transition-colors border"
              :class="selectedSlideIds.size === store.presentationSlides.length
                ? 'bg-primary-50 dark:bg-primary-900/20 border-primary-300 dark:border-primary-700 text-primary-600 dark:text-primary-400'
                : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
            >
              {{ selectedSlideIds.size === store.presentationSlides.length ? 'Deselect All' : 'Select All' }}
            </button>
            <span v-if="selectedSlideIds.size > 0" class="text-[10px] text-surface-400 tabular-nums">
              {{ selectedSlideIds.size }} selected
            </span>
          </div>
          <div class="flex items-center gap-1">
            <!-- Bulk timing dropdown (when multiple selected) -->
            <div v-if="hasMultiSelection" class="relative">
              <button
                @click.stop="showBulkTimingDropdown = !showBulkTimingDropdown"
                class="px-2.5 py-1 rounded-full text-[10px] font-medium bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 border border-amber-300 dark:border-amber-700 hover:bg-amber-100 dark:hover:bg-amber-900/30 transition-colors flex items-center gap-1"
              >
                <span class="material-symbols-rounded text-sm">timer</span>
                Bulk Timing
                <span class="material-symbols-rounded text-[12px]">expand_more</span>
              </button>

              <!-- Bulk timing panel -->
              <div
                v-if="showBulkTimingDropdown"
                class="absolute right-0 bottom-full mb-2 z-[100] w-56 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2"
                @click.stop
              >
                <div class="px-3 pb-1.5 text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">
                  Set for {{ selectedSlideIds.size }} slides
                </div>

                <!-- Transition type -->
                <div class="px-3 py-1">
                  <div class="text-[10px] font-medium text-surface-500 dark:text-surface-400 mb-1">Transition</div>
                  <div class="flex items-center gap-1">
                    <button
                      @click="setBulkTransition('fly')"
                      class="flex-1 px-2 py-1 rounded-lg text-[10px] font-medium border transition-colors flex items-center justify-center gap-0.5 bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-primary-400"
                    >
                      <span class="material-symbols-rounded" style="font-size: 12px;">swipe_right</span>
                      Fly
                    </button>
                    <button
                      @click="setBulkTransition('fade')"
                      class="flex-1 px-2 py-1 rounded-lg text-[10px] font-medium border transition-colors flex items-center justify-center gap-0.5 bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-primary-400"
                    >
                      <span class="material-symbols-rounded" style="font-size: 12px;">blur_on</span>
                      Fade
                    </button>
                    <button
                      @click="setBulkTransition('instant')"
                      class="flex-1 px-2 py-1 rounded-lg text-[10px] font-medium border transition-colors flex items-center justify-center gap-0.5 bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-primary-400"
                    >
                      <span class="material-symbols-rounded" style="font-size: 12px;">bolt</span>
                      Cut
                    </button>
                  </div>
                </div>

                <div class="h-px mx-2 my-1 bg-surface-200 dark:bg-surface-700"></div>

                <!-- Duration presets -->
                <div class="px-3 py-1">
                  <div class="text-[10px] font-medium text-surface-500 dark:text-surface-400 mb-1">Duration</div>
                  <div class="flex flex-wrap gap-1">
                    <button
                      v-for="d in durationPresets"
                      :key="d"
                      @click="setBulkDuration(d)"
                      class="min-w-[32px] text-[10px] font-medium py-1 px-1.5 rounded-lg border transition-colors text-center bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-primary-400 hover:text-primary-500"
                    >
                      {{ d }}s
                    </button>
                  </div>
                  <!-- Custom input -->
                  <div class="flex items-center gap-1.5 mt-1.5">
                    <input
                      type="number"
                      v-model="bulkCustomDuration"
                      @keydown.enter.stop="applyBulkCustomDuration"
                      @click.stop
                      min="0.1"
                      max="30"
                      step="0.1"
                      class="flex-1 text-[11px] px-2 py-1 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-300 outline-none focus:border-primary-400
                             [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                      placeholder="Custom (s)"
                    />
                    <button
                      @click="applyBulkCustomDuration"
                      class="px-2 py-1 rounded-lg text-[11px] font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
                    >
                      Set
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <button
              @click="$emit('start-presentation', 0)"
              class="px-3 py-1 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center gap-1"
            >
              <span class="material-symbols-rounded text-sm">play_arrow</span>
              Present
            </button>
            <button
              @click="store.showFilmstrip = false"
              class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors"
            >
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
          </div>
        </div>

        <!-- Scrollable slide area with navigation arrows -->
        <div class="relative group/strip">
          <!-- Left scroll arrow -->
          <button
            v-if="canScrollLeft"
            @click="scrollBy(-300)"
            class="absolute left-0 top-0 bottom-0 z-10 w-10 flex items-center justify-start
                   bg-gradient-to-r from-white/95 via-white/80 to-transparent
                   dark:from-surface-800/95 dark:via-surface-800/80 dark:to-transparent
                   opacity-0 group-hover/strip:opacity-100 transition-opacity duration-200
                   hover:!opacity-100 rounded-l-lg"
            title="Scroll left"
          >
            <span class="material-symbols-rounded text-lg text-surface-600 dark:text-surface-300 ml-0.5 hover:text-primary-500 transition-colors">
              chevron_left
            </span>
          </button>

          <!-- Right scroll arrow -->
          <button
            v-if="canScrollRight"
            @click="scrollBy(300)"
            class="absolute right-0 top-0 bottom-0 z-10 w-10 flex items-center justify-end
                   bg-gradient-to-l from-white/95 via-white/80 to-transparent
                   dark:from-surface-800/95 dark:via-surface-800/80 dark:to-transparent
                   opacity-0 group-hover/strip:opacity-100 transition-opacity duration-200
                   hover:!opacity-100 rounded-r-lg"
            title="Scroll right"
          >
            <span class="material-symbols-rounded text-lg text-surface-600 dark:text-surface-300 mr-0.5 hover:text-primary-500 transition-colors">
              chevron_right
            </span>
          </button>

          <!-- Slide thumbnails row -->
          <div
            ref="scrollContainer"
            class="flex items-center overflow-x-auto pt-2 pb-1.5 px-1 filmstrip-scroll scroll-smooth"
            @wheel.prevent="onWheel"
            @dragover.prevent
            @scroll="updateScrollState"
          >
            <!-- Leading "+" insert button (before first slide) -->
            <button
              v-if="!store.isPublicView"
              @click.stop="$emit('insert-slide-at', 0)"
              class="flex-shrink-0 w-5 h-[72px] flex items-center justify-center
                     opacity-0 hover:opacity-100 focus:opacity-100 transition-all duration-150 group/ins"
              title="Insert slide at beginning"
            >
              <div class="w-5 h-5 rounded-full bg-primary-500/80 hover:bg-primary-500 text-white flex items-center justify-center shadow-sm transition-all hover:scale-110">
                <span class="material-symbols-rounded" style="font-size: 14px;">add</span>
              </div>
            </button>

            <template v-for="(frame, index) in store.presentationSlides" :key="frame.id">
              <!-- Slide thumbnail -->
              <div
                :draggable="!store.isPublicView"
                @dragstart="onDragStart(index, $event)"
                @dragover.prevent="onDragOver(index, $event)"
                @drop="onDrop(index)"
                @dragend="onDragEnd"
                @click="onSlideClick(frame, index, $event)"
                @contextmenu.prevent="onContextMenu(frame, index, $event)"
                :ref="el => setThumbRef(frame.id, el)"
                class="flex-shrink-0 group cursor-pointer relative select-none mx-1 transition-all duration-150"
                :class="{
                  'opacity-40 scale-95': dragIndex === index,
                  'opacity-50': store.focusedSlideId && store.focusedSlideId !== frame.id && !selectedSlideIds.has(frame.id) && dragIndex !== index,
                }"
              >
                <!-- Drop indicator (left edge glow) -->
                <div
                  v-if="dropTarget === index && dropTarget !== dragIndex && dragIndex !== null"
                  class="absolute -left-2 top-0 bottom-0 w-1 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.6)]"
                />

                <!-- Thumbnail card (16:9 ratio) -->
                <div
                  class="w-32 h-[72px] rounded-lg border-2 transition-all duration-150 flex flex-col items-center justify-center overflow-hidden"
                  :class="dropTarget === index && dropTarget !== dragIndex
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 scale-105'
                    : store.focusedSlideId === frame.id
                      ? 'border-primary-500 bg-primary-100 dark:bg-primary-900/40 shadow-lg shadow-primary-500/20 ring-1 ring-primary-400/40 scale-105'
                      : selectedSlideIds.has(frame.id)
                        ? 'border-primary-400 bg-primary-50/70 dark:bg-primary-900/30 ring-1 ring-primary-300/30'
                        : 'border-surface-300 dark:border-surface-600 hover:border-primary-400 hover:shadow-md bg-surface-100/50 dark:bg-surface-700/30'"
                >
                  <!-- Frame color indicator -->
                  <div class="w-full h-1.5 flex-shrink-0 bg-primary-500" />
                  <!-- Frame content preview -->
                  <div class="flex-1 flex flex-col items-center justify-center px-2 py-1 w-full">
                    <span class="text-[11px] font-bold truncate w-full text-center text-primary-600 dark:text-primary-300">
                      {{ frame.title || 'Slide ' + (index + 1) }}
                    </span>
                    <!-- Transition type + duration -->
                    <div class="flex items-center gap-0.5 mt-0.5">
                      <button
                        @click.stop="cycleTransition(frame)"
                        class="text-[9px] text-primary-400 dark:text-primary-500 hover:text-primary-600 dark:hover:text-primary-300 flex items-center gap-0.5 transition-colors rounded px-1 hover:bg-primary-100 dark:hover:bg-primary-900/30"
                        :title="'Transition: ' + (frame.transition_type || 'fly') + ' (click to change)'"
                      >
                        <span class="material-symbols-rounded" style="font-size: 10px;">
                          {{ frame.transition_type === 'fade' ? 'blur_on' : frame.transition_type === 'instant' ? 'bolt' : 'swipe_right' }}
                        </span>
                        {{ frame.transition_type || 'fly' }}
                      </button>
                      <!-- Duration dropdown trigger -->
                      <div v-if="(frame.transition_type || 'fly') !== 'instant'" class="relative">
                        <button
                          :ref="el => setDurationBtnRef(frame.id, el)"
                          @click.stop="toggleDurationDropdown(frame.id)"
                          class="text-[9px] text-primary-400/70 dark:text-primary-500/70 hover:text-primary-600 dark:hover:text-primary-300 flex items-center gap-0.5 transition-colors rounded px-0.5 hover:bg-primary-100 dark:hover:bg-primary-900/30"
                          :title="'Duration: ' + getDisplayDuration(frame) + 's (click to change)'"
                        >
                          <span class="material-symbols-rounded" style="font-size: 9px;">timer</span>
                          {{ getDisplayDuration(frame) }}s
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Slide number badge (double-click to edit position) -->
                <div
                  v-if="editingBadgeIndex !== index"
                  @dblclick.stop="startEditBadge(index)"
                  class="absolute -top-1.5 -left-1.5 rounded-full flex items-center justify-center font-bold text-white shadow-sm transition-all duration-150 cursor-default"
                  :class="[
                    store.focusedSlideId === frame.id
                      ? 'w-6 h-6 text-[11px] ring-2 ring-primary-300 dark:ring-primary-400 -top-2 -left-2'
                      : 'w-5 h-5 text-[10px]',
                    selectedSlideIds.has(frame.id) && selectedSlideIds.size > 1
                      ? 'bg-amber-500'
                      : 'bg-primary-500'
                  ]"
                  :title="'Slide ' + (index + 1) + ' — double-click to move'"
                >
                  {{ index + 1 }}
                </div>
                <!-- Editable badge input -->
                <input
                  v-else
                  ref="badgeInputRef"
                  type="number"
                  :min="1"
                  :max="store.presentationSlides.length"
                  :value="index + 1"
                  @blur="commitBadgeEdit(index, $event)"
                  @keydown.enter="$event.target.blur()"
                  @keydown.escape="cancelBadgeEdit"
                  @click.stop
                  @mousedown.stop
                  class="absolute -top-2.5 -left-2.5 w-8 h-7 rounded-lg bg-primary-600 text-white text-[11px] font-bold text-center
                         border-2 border-primary-300 shadow-lg outline-none z-10
                         [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                />
              </div>

              <!-- "+" insert button between slides -->
              <button
                v-if="!store.isPublicView"
                @click.stop="$emit('insert-slide-at', index + 1)"
                class="flex-shrink-0 w-5 h-[72px] flex items-center justify-center
                       opacity-0 hover:opacity-100 focus:opacity-100 transition-all duration-150"
                :title="'Insert slide after ' + (index + 1)"
              >
                <div class="w-5 h-5 rounded-full bg-primary-500/80 hover:bg-primary-500 text-white flex items-center justify-center shadow-sm transition-all hover:scale-110">
                  <span class="material-symbols-rounded" style="font-size: 14px;">add</span>
                </div>
              </button>
            </template>

            <!-- Spacer at end so last item can scroll fully into view -->
            <div class="flex-shrink-0 w-2" />
          </div>
        </div>

        <!-- Scroll position indicator (draggable minimap bar) — only when overflowing -->
        <div v-if="isOverflowing" class="px-1">
          <div
            ref="scrollbarTrack"
            class="h-2.5 rounded-full bg-surface-200 dark:bg-surface-700 relative cursor-pointer group/bar"
            @mousedown="onScrollbarMouseDown"
          >
            <div
              class="absolute top-0.5 left-0 h-1.5 rounded-full transition-colors duration-150 pointer-events-none"
              :class="isDraggingScrollbar
                ? 'bg-primary-500 shadow-sm shadow-primary-500/30'
                : 'bg-primary-400/60 group-hover/bar:bg-primary-500/80'"
              :style="{
                width: scrollThumbWidth + '%',
                left: scrollThumbLeft + '%',
                transition: isDraggingScrollbar ? 'none' : 'all 0.15s'
              }"
            />
          </div>
        </div>
      </div>

      <!-- Context menu for individual slides (opens upward from click point) -->
      <div
        v-if="contextMenu.show"
        class="fixed z-[60] bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 py-0.5 min-w-[160px]"
        :style="{ left: contextMenu.x + 'px', bottom: contextMenu.bottom + 'px' }"
        @click.stop
      >
        <button
          @click="setTransition('fly')"
          class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
        >
          <span class="material-symbols-rounded text-[16px]">swipe_right</span>
          Fly transition
          <span v-if="contextMenu.frame?.transition_type === 'fly' || !contextMenu.frame?.transition_type" class="ml-auto material-symbols-rounded text-primary-500 text-xs">check</span>
        </button>
        <button
          @click="setTransition('fade')"
          class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
        >
          <span class="material-symbols-rounded text-[16px]">blur_on</span>
          Fade transition
          <span v-if="contextMenu.frame?.transition_type === 'fade'" class="ml-auto material-symbols-rounded text-primary-500 text-xs">check</span>
        </button>
        <button
          @click="setTransition('instant')"
          class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
        >
          <span class="material-symbols-rounded text-[16px]">bolt</span>
          Instant (cut)
          <span v-if="contextMenu.frame?.transition_type === 'instant'" class="ml-auto material-symbols-rounded text-primary-500 text-xs">check</span>
        </button>

        <!-- Duration section (only for non-instant transitions) -->
        <template v-if="(contextMenu.frame?.transition_type || 'fly') !== 'instant'">
          <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
          <div class="px-4 py-1.5">
            <div class="text-[10px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500 mb-1.5 flex items-center gap-1">
              <span class="material-symbols-rounded" style="font-size: 12px;">timer</span>
              Duration
            </div>
            <div class="flex flex-wrap items-center gap-1">
              <button
                v-for="d in durationPresets"
                :key="d"
                @click="setDuration(d)"
                class="min-w-[32px] text-[11px] font-medium py-1 rounded-lg border transition-colors text-center"
                :class="getContextDuration() === d
                  ? 'bg-primary-100 dark:bg-primary-900/30 border-primary-400 dark:border-primary-600 text-primary-600 dark:text-primary-300'
                  : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:border-primary-300 dark:hover:border-primary-500'"
              >
                {{ d }}s
              </button>
            </div>
            <!-- Custom duration input -->
            <div class="flex items-center gap-1.5 mt-1.5">
              <input
                type="number"
                :value="contextCustomDuration ?? getContextDuration()"
                @focus="contextCustomDuration = getContextDuration()"
                @input="contextCustomDuration = parseFloat($event.target.value)"
                @keydown.enter.stop="applyContextCustomDuration"
                @click.stop
                min="0.1"
                max="30"
                step="0.1"
                class="flex-1 text-[11px] px-2 py-1 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-300 outline-none focus:border-primary-400 dark:focus:border-primary-500
                       [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                placeholder="Custom seconds"
              />
              <button
                @click="applyContextCustomDuration"
                class="px-2 py-1 rounded-lg text-[11px] font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
              >
                Set
              </button>
            </div>
          </div>
        </template>

        <!-- Delete slide -->
        <div class="h-px bg-surface-200 dark:bg-surface-700 my-1 mx-2"></div>
        <button
          @click="deleteContextSlide"
          class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2 text-red-600 dark:text-red-400"
        >
          <span class="material-symbols-rounded text-[16px]">delete</span>
          Delete slide
        </button>
      </div>
    </div>
  </transition>

  <!-- Teleported duration dropdown (rendered above all overflow containers) -->
  <Teleport to="body">
    <div
      v-if="durationDropdownId && durationDropdownFrame"
      class="fixed w-[130px] bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 py-1 z-[99999]"
      :style="durationDropdownStyle"
      @click.stop
      @mousedown.stop
    >
      <div class="px-2.5 py-1 text-[9px] font-bold uppercase tracking-wider text-surface-400 dark:text-surface-500">Duration</div>
      <button
        v-for="d in durationPresets"
        :key="d"
        @click.stop="setDurationForFrame(durationDropdownFrame, d); durationDropdownId = null"
        class="w-full px-3 py-1 text-[11px] text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center justify-between transition-colors"
        :class="getDisplayDuration(durationDropdownFrame) === d
          ? 'text-primary-500 font-semibold'
          : 'text-surface-600 dark:text-surface-400'"
      >
        {{ d }}s
        <span v-if="getDisplayDuration(durationDropdownFrame) === d" class="material-symbols-rounded text-primary-500" style="font-size: 12px;">check</span>
      </button>
      <div class="h-px bg-surface-200 dark:bg-surface-700 my-0.5 mx-2"></div>
      <div class="px-2.5 py-1.5 flex items-stretch gap-1">
        <input
          ref="durationCustomInput"
          type="number"
          :value="durationCustomValue ?? getDisplayDuration(durationDropdownFrame)"
          @focus="durationCustomValue = getDisplayDuration(durationDropdownFrame)"
          @input="durationCustomValue = parseFloat($event.target.value)"
          @keydown.enter.stop="applyCustomDuration(durationDropdownFrame); durationDropdownId = null"
          @click.stop
          min="0.1"
          max="30"
          step="0.1"
          class="w-full text-[11px] px-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-300 outline-none focus:border-primary-400 dark:focus:border-primary-500
                 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
          placeholder="Custom"
        />
        <button
          @click.stop="applyCustomDuration(durationDropdownFrame); durationDropdownId = null"
          class="px-1.5 rounded-lg bg-primary-500 text-white hover:bg-primary-600 transition-colors flex-shrink-0 flex items-center justify-center"
          title="Apply"
        >
          <span class="material-symbols-rounded" style="font-size: 14px;">check</span>
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const emit = defineEmits(['fly-to-slide', 'start-presentation', 'insert-slide-at'])

const store = useMoodBoardsStore()

const scrollContainer = ref(null)
const scrollbarTrack = ref(null)
const dragIndex = ref(null)
const dropTarget = ref(null)
const durationCustomInput = ref(null)
const isDraggingScrollbar = ref(false)

// ─── Multi-select slides ─────────────────────────────────────
const selectedSlideIds = ref(new Set())
const lastClickedIndex = ref(null)

function onSlideClick(frame, index, event) {
  if (event.ctrlKey || event.metaKey) {
    // Toggle individual slide
    const newSet = new Set(selectedSlideIds.value)
    if (newSet.has(frame.id)) {
      newSet.delete(frame.id)
    } else {
      newSet.add(frame.id)
    }
    selectedSlideIds.value = newSet
    lastClickedIndex.value = index
  } else if (event.shiftKey && lastClickedIndex.value !== null) {
    // Range select
    const from = Math.min(lastClickedIndex.value, index)
    const to = Math.max(lastClickedIndex.value, index)
    const newSet = new Set(selectedSlideIds.value)
    for (let i = from; i <= to; i++) {
      newSet.add(store.presentationSlides[i].id)
    }
    selectedSlideIds.value = newSet
  } else {
    // Single click — select only this one
    selectedSlideIds.value = new Set([frame.id])
    lastClickedIndex.value = index
  }
  store.focusedSlideId = frame.id
  emit('fly-to-slide', frame)
}

function selectAllSlides() {
  selectedSlideIds.value = new Set(store.presentationSlides.map(s => s.id))
}

function deselectAllSlides() {
  selectedSlideIds.value = new Set()
}

const hasMultiSelection = computed(() => selectedSlideIds.value.size > 1)

// ─── Bulk timing operations ──────────────────────────────────
const showBulkTimingDropdown = ref(false)
const bulkCustomDuration = ref(null)

function setBulkTransition(type) {
  for (const id of selectedSlideIds.value) {
    store.updateItem(id, { transition_type: type })
  }
}

function setBulkDuration(dur) {
  for (const id of selectedSlideIds.value) {
    store.updateItem(id, { transition_duration: dur })
  }
  bulkCustomDuration.value = null
}

function applyBulkCustomDuration() {
  const val = parseFloat(bulkCustomDuration.value)
  if (!isNaN(val) && val >= 0.1 && val <= 30) {
    const rounded = Math.round(val * 10) / 10
    for (const id of selectedSlideIds.value) {
      store.updateItem(id, { transition_duration: rounded })
    }
  }
  bulkCustomDuration.value = null
  showBulkTimingDropdown.value = false
}

// ─── Per-thumbnail refs for scroll-into-view ─────────────────
const thumbRefs = {}
function setThumbRef(frameId, el) {
  if (el) thumbRefs[frameId] = el
  else delete thumbRefs[frameId]
}

// ─── Duration button refs (for Teleport positioning) ─────────
const durationBtnRefs = {}
function setDurationBtnRef(frameId, el) {
  if (el) durationBtnRefs[frameId] = el
  else delete durationBtnRefs[frameId]
}

// Computed: the frame object for the currently open duration dropdown
const durationDropdownFrame = computed(() => {
  if (!durationDropdownId.value) return null
  return store.presentationSlides.find(f => f.id === durationDropdownId.value) || null
})

// Computed: position style for the teleported dropdown
const durationDropdownStyle = computed(() => {
  if (!durationDropdownId.value) return {}
  const btn = durationBtnRefs[durationDropdownId.value]
  if (!btn) return {}
  // Use $el if it's a Vue component ref, otherwise it's a raw DOM element
  const el = btn.$el || btn
  const rect = el.getBoundingClientRect()
  return {
    left: (rect.left + rect.width / 2 - 65) + 'px', // center the 130px dropdown
    bottom: (window.innerHeight - rect.top + 6) + 'px',
  }
})

// Auto-scroll the focused thumbnail into view when focusedSlideId changes
watch(() => store.focusedSlideId, (id) => {
  if (!id) return
  nextTick(() => {
    const el = thumbRefs[id]
    const container = scrollContainer.value
    if (!el || !container) return
    const elRect = el.getBoundingClientRect()
    const containerRect = container.getBoundingClientRect()
    const pad = 48
    if (elRect.left < containerRect.left + pad) {
      container.scrollBy({ left: elRect.left - containerRect.left - pad, behavior: 'smooth' })
    } else if (elRect.right > containerRect.right - pad) {
      container.scrollBy({ left: elRect.right - containerRect.right + pad, behavior: 'smooth' })
    }
  })
})

// Sync: when a slide item is selected on the canvas, highlight it in the filmstrip
watch(() => [...store.selectedItemIds], (ids) => {
  if (!ids.length) return
  // Check if any selected item is a slide
  const slideIds = new Set(store.presentationSlides.map(s => s.id))
  for (const id of ids) {
    if (slideIds.has(id)) {
      store.focusedSlideId = id
      return
    }
  }
})

// ─── Editable slide number badge ─────────────────────────────
const editingBadgeIndex = ref(null)
const badgeInputRef = ref(null)

function startEditBadge(index) {
  editingBadgeIndex.value = index
  nextTick(() => {
    // badgeInputRef is an array from v-for — find the rendered input
    const inputs = badgeInputRef.value
    if (inputs && inputs.length) {
      const input = inputs[0]
      input.select()
      input.focus()
    }
  })
}

function commitBadgeEdit(fromIndex, event) {
  const raw = parseInt(event.target.value)
  editingBadgeIndex.value = null

  if (isNaN(raw)) return // invalid — do nothing

  // Convert 1-based input to 0-based index, clamped to valid range
  const toIndex = Math.max(0, Math.min(store.presentationSlides.length - 1, raw - 1))
  if (toIndex === fromIndex) return // same position — no-op

  // Reorder: move fromIndex to toIndex
  const frames = [...store.presentationSlides]
  const [moved] = frames.splice(fromIndex, 1)
  frames.splice(toIndex, 0, moved)

  // Update slide_order for all frames
  for (let i = 0; i < frames.length; i++) {
    store.updateItem(frames[i].id, { slide_order: i })
  }
}

function cancelBadgeEdit() {
  editingBadgeIndex.value = null
}

// ─── Scroll state ────────────────────────────────────────────
const canScrollLeft = ref(false)
const canScrollRight = ref(false)
const isOverflowing = ref(false)
const scrollThumbWidth = ref(100)
const scrollThumbLeft = ref(0)

function updateScrollState() {
  const el = scrollContainer.value
  if (!el) return
  const { scrollLeft, scrollWidth, clientWidth } = el
  canScrollLeft.value = scrollLeft > 4
  canScrollRight.value = scrollLeft < scrollWidth - clientWidth - 4
  isOverflowing.value = scrollWidth > clientWidth + 4
  if (scrollWidth > clientWidth) {
    scrollThumbWidth.value = Math.max(10, (clientWidth / scrollWidth) * 100)
    scrollThumbLeft.value = (scrollLeft / (scrollWidth - clientWidth)) * (100 - scrollThumbWidth.value)
  } else {
    scrollThumbWidth.value = 100
    scrollThumbLeft.value = 0
  }
}

function scrollBy(px) {
  const el = scrollContainer.value
  if (!el) return
  el.scrollBy({ left: px, behavior: 'smooth' })
}

function onWheel(e) {
  const el = scrollContainer.value
  if (!el) return
  const delta = e.deltaY !== 0 ? e.deltaY : e.deltaX
  el.scrollLeft += delta
  requestAnimationFrame(updateScrollState)
}

// ─── Draggable scrollbar ─────────────────────────────────────
function scrollToTrackPosition(clientX) {
  const track = scrollbarTrack.value
  const el = scrollContainer.value
  if (!track || !el) return
  const rect = track.getBoundingClientRect()
  // Calculate click position as a ratio along the track
  const ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width))
  // Scroll the container to the proportional position
  el.scrollLeft = ratio * (el.scrollWidth - el.clientWidth)
  updateScrollState()
}

function onScrollbarMouseDown(e) {
  e.preventDefault()
  isDraggingScrollbar.value = true
  // Jump to clicked position immediately
  scrollToTrackPosition(e.clientX)
  document.addEventListener('mousemove', onScrollbarMouseMove)
  document.addEventListener('mouseup', onScrollbarMouseUp)
}

function onScrollbarMouseMove(e) {
  if (!isDraggingScrollbar.value) return
  scrollToTrackPosition(e.clientX)
}

function onScrollbarMouseUp() {
  isDraggingScrollbar.value = false
  document.removeEventListener('mousemove', onScrollbarMouseMove)
  document.removeEventListener('mouseup', onScrollbarMouseUp)
}

watch(() => store.presentationSlides.length, async () => {
  await nextTick()
  updateScrollState()
})

// ─── Auto-scroll during drag ─────────────────────────────────
let autoScrollRAF = null
const EDGE_ZONE = 60
const SCROLL_SPEED = 8

function autoScrollDuringDrag(e) {
  if (dragIndex.value === null) return
  const el = scrollContainer.value
  if (!el) return
  const rect = el.getBoundingClientRect()
  const mouseX = e.clientX
  cancelAnimationFrame(autoScrollRAF)

  function tick() {
    if (dragIndex.value === null) return
    const leftDist = mouseX - rect.left
    const rightDist = rect.right - mouseX
    if (leftDist < EDGE_ZONE && el.scrollLeft > 0) {
      const factor = 1 - (leftDist / EDGE_ZONE)
      el.scrollLeft -= Math.ceil(SCROLL_SPEED * factor)
      autoScrollRAF = requestAnimationFrame(tick)
    } else if (rightDist < EDGE_ZONE && el.scrollLeft < el.scrollWidth - el.clientWidth) {
      const factor = 1 - (rightDist / EDGE_ZONE)
      el.scrollLeft += Math.ceil(SCROLL_SPEED * factor)
      autoScrollRAF = requestAnimationFrame(tick)
    }
    updateScrollState()
  }
  tick()
}

function stopAutoScroll() {
  cancelAnimationFrame(autoScrollRAF)
  autoScrollRAF = null
}

// ─── Drag-to-reorder ─────────────────────────────────────────
function onDragStart(index, e) {
  dragIndex.value = index
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', index.toString())
  if (e.target) {
    const thumb = e.target.querySelector('.rounded-lg')
    if (thumb) e.dataTransfer.setDragImage(thumb, 64, 36)
  }
  document.addEventListener('drag', autoScrollDuringDrag)
}

function onDragOver(index, e) {
  e.dataTransfer.dropEffect = 'move'
  dropTarget.value = index
}

function onDrop(toIndex) {
  const fromIndex = dragIndex.value
  if (fromIndex === null || fromIndex === toIndex) {
    dragIndex.value = null
    dropTarget.value = null
    return
  }
  const frames = [...store.presentationSlides]
  const [moved] = frames.splice(fromIndex, 1)
  frames.splice(toIndex, 0, moved)
  for (let i = 0; i < frames.length; i++) {
    store.updateItem(frames[i].id, { slide_order: i })
  }
  dragIndex.value = null
  dropTarget.value = null
}

function onDragEnd() {
  dragIndex.value = null
  dropTarget.value = null
  stopAutoScroll()
  document.removeEventListener('drag', autoScrollDuringDrag)
}

// ─── Context menu ────────────────────────────────────────────
const contextMenu = ref({ show: false, x: 0, bottom: 0, frame: null, index: -1 })

function onContextMenu(frame, index, e) {
  const bottom = window.innerHeight - e.clientY
  contextMenu.value = { show: true, x: e.clientX, bottom, frame, index }
}

function setTransition(type) {
  if (!contextMenu.value.frame) return
  store.updateItem(contextMenu.value.frame.id, { transition_type: type })
  contextMenu.value.show = false
}

const transitionOrder = ['fly', 'fade', 'instant']
function cycleTransition(frame) {
  const current = frame.transition_type || 'fly'
  const nextIdx = (transitionOrder.indexOf(current) + 1) % transitionOrder.length
  store.updateItem(frame.id, { transition_type: transitionOrder[nextIdx] })
}

// ─── Transition duration ─────────────────────────────────────
const durationPresets = [0.3, 0.5, 0.8, 1, 1.5, 2, 3, 5]
const defaultDurations = { fly: 0.6, fade: 0.4, instant: 0 }

// Inline dropdown on thumbnail
const durationDropdownId = ref(null)
const durationCustomValue = ref(null)

// Context menu custom input
const contextCustomDuration = ref(null)

function getDisplayDuration(frame) {
  if (frame.transition_duration != null) return frame.transition_duration
  return defaultDurations[frame.transition_type || 'fly'] ?? 0.6
}

function toggleDurationDropdown(frameId) {
  durationDropdownId.value = durationDropdownId.value === frameId ? null : frameId
  durationCustomValue.value = null
}

function setDurationForFrame(frame, dur) {
  store.updateItem(frame.id, { transition_duration: dur })
}

function applyCustomDuration(frame) {
  const val = parseFloat(durationCustomValue.value)
  if (!isNaN(val) && val >= 0.1 && val <= 30) {
    store.updateItem(frame.id, { transition_duration: Math.round(val * 10) / 10 })
  }
  durationCustomValue.value = null
}

function setDuration(dur) {
  if (!contextMenu.value.frame) return
  store.updateItem(contextMenu.value.frame.id, { transition_duration: dur })
  contextCustomDuration.value = null
}

function applyContextCustomDuration() {
  const val = parseFloat(contextCustomDuration.value)
  if (!isNaN(val) && val >= 0.1 && val <= 30 && contextMenu.value.frame) {
    store.updateItem(contextMenu.value.frame.id, { transition_duration: Math.round(val * 10) / 10 })
  }
  contextCustomDuration.value = null
}

function getContextDuration() {
  const f = contextMenu.value.frame
  if (!f) return 0.6
  return getDisplayDuration(f)
}

function deleteContextSlide() {
  if (!contextMenu.value.frame) return
  const id = contextMenu.value.frame.id
  contextMenu.value.show = false
  contextCustomDuration.value = null
  deleteSlides([id])
}

function deleteSlides(ids) {
  if (!ids.length) return
  const idSet = new Set(ids)
  for (const id of ids) {
    store.deleteItem(id)
    selectedSlideIds.value.delete(id)
    if (store.focusedSlideId === id) {
      store.focusedSlideId = null
    }
  }
  // Re-index remaining slides
  nextTick(() => {
    const remaining = store.presentationSlides
    for (let i = 0; i < remaining.length; i++) {
      store.updateItem(remaining[i].id, { slide_order: i })
    }
  })
}

function deleteSelectedSlides() {
  const ids = [...selectedSlideIds.value]
  if (!ids.length) return
  deleteSlides(ids)
}

function closeContextMenu() {
  contextMenu.value.show = false
  contextCustomDuration.value = null
}

function closeDurationDropdown(e) {
  // Close duration dropdown when clicking outside
  if (durationDropdownId.value !== null) {
    durationDropdownId.value = null
    durationCustomValue.value = null
  }
  showBulkTimingDropdown.value = false
}

// ─── Keyboard shortcuts ──────────────────────────────────────
function onKeydown(e) {
  if (!store.showFilmstrip) return
  // Don't handle keyboard when editing badge
  if (editingBadgeIndex.value !== null) return
  if (e.key === 'ArrowLeft') {
    scrollBy(-160)
  } else if (e.key === 'ArrowRight') {
    scrollBy(160)
  } else if (e.key === 'Home') {
    scrollContainer.value?.scrollTo({ left: 0, behavior: 'smooth' })
  } else if (e.key === 'End') {
    const el = scrollContainer.value
    if (el) el.scrollTo({ left: el.scrollWidth, behavior: 'smooth' })
  } else if (e.key === 'Delete' || e.key === 'Backspace') {
    if (selectedSlideIds.value.size > 0 && !store.isPublicView) {
      e.preventDefault()
      deleteSelectedSlides()
    }
  }
}

// ─── ResizeObserver ──────────────────────────────────────────
let resizeObserver = null

onMounted(() => {
  document.addEventListener('click', closeContextMenu)
  document.addEventListener('click', closeDurationDropdown)
  document.addEventListener('keydown', onKeydown)
  nextTick(updateScrollState)
  if (scrollContainer.value && typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => updateScrollState())
    resizeObserver.observe(scrollContainer.value)
  }
})

onUnmounted(() => {
  document.removeEventListener('click', closeContextMenu)
  document.removeEventListener('click', closeDurationDropdown)
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('drag', autoScrollDuringDrag)
  document.removeEventListener('mousemove', onScrollbarMouseMove)
  document.removeEventListener('mouseup', onScrollbarMouseUp)
  stopAutoScroll()
  resizeObserver?.disconnect()
})
</script>

<style scoped>
.filmstrip-slide-enter-active,
.filmstrip-slide-leave-active {
  transition: all 0.25s ease;
}
.filmstrip-slide-enter-from,
.filmstrip-slide-leave-to {
  opacity: 0;
  transform: translateY(20px);
}

/* Hide native scrollbar — minimap bar is the only scroll indicator */
.filmstrip-scroll {
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.filmstrip-scroll::-webkit-scrollbar {
  display: none;
}
</style>
