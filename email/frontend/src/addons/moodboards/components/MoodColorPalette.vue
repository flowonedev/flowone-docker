<template>
  <div :class="embedded
    ? 'flex flex-col overflow-hidden'
    : 'w-64 bg-white dark:bg-surface-800 rounded-2xl shadow-lg border border-surface-200 dark:border-surface-700 overflow-hidden'"
  >
    <!-- Header -->
    <div v-if="!embedded" class="flex items-center justify-between px-4 py-2.5 border-b border-surface-100 dark:border-surface-700">
      <h3 class="text-sm font-semibold text-surface-800 dark:text-surface-200">Board Palette</h3>
      <button
        @click="$emit('close')"
        class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400"
      >
        <span class="material-symbols-rounded text-lg">close</span>
      </button>
    </div>

    <!-- Content area (parent handles scrolling when embedded) -->
    <div :class="embedded ? '' : 'flex-1 overflow-y-auto'">

      <!-- ═══ COLORS (global tokens) ═══ -->
      <div class="px-4 py-3">
        <div class="flex items-center justify-between mb-2">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Colors</p>
          <span v-if="gsReady" class="text-[8px] text-surface-400 font-medium">Click apply / Shift+click edit</span>
        </div>

        <div class="flex flex-wrap gap-2.5" v-if="colors.length">
          <div
            v-for="token in colors"
            :key="token.id"
            class="relative group"
          >
            <button
              @click="onSwatchClick($event, token)"
              @dblclick.stop="startRename(token)"
              class="w-9 h-9 rounded-lg border-2 border-surface-200 dark:border-surface-600 hover:scale-110 hover:ring-2 hover:ring-primary-300 transition-all shadow-sm cursor-pointer"
              :style="{ backgroundColor: token.value }"
              :title="`${token.name}\nClick = apply · Shift+click = edit color · Dbl-click = rename`"
            />
            <span
              v-if="getUsage(token.id) > 0"
              class="absolute -top-1.5 -left-1.5 min-w-[14px] h-[14px] flex items-center justify-center text-[7px] font-bold bg-primary-500 text-white rounded-full px-0.5 shadow pointer-events-none"
            >{{ getUsage(token.id) }}</span>
            <button
              @click.stop="removeColor(token.id)"
              class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow"
              title="Remove"
            >
              <span class="material-symbols-rounded text-[10px]">close</span>
            </button>
          </div>
        </div>
        <p v-else class="text-xs text-surface-400 italic">No colors saved yet</p>

        <!-- Inline rename -->
        <div v-if="renamingToken" class="mt-2 flex items-center gap-1.5">
          <div class="w-5 h-5 rounded border border-surface-300 dark:border-surface-600 flex-shrink-0" :style="{ backgroundColor: renamingToken.value }" />
          <input
            ref="renameInput"
            v-model="renameValue"
            class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-primary-300 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300 focus:outline-none"
            @keydown.enter="finishRename"
            @keydown.escape="renamingToken = null"
            @blur="finishRename"
          />
        </div>

        <!-- Hidden color input for shift+click editing -->
        <input
          ref="hiddenColorInput"
          type="color"
          class="absolute w-0 h-0 opacity-0 pointer-events-none"
          @input="onHiddenColorChange($event.target.value)"
        />

        <!-- Add color -->
        <div class="mt-3 flex items-center gap-1.5">
          <MoodColorPicker
            :model-value="newColor"
            @update:model-value="newColor = $event"
            label="Pick a color"
            :show-caret="false"
            dropdown-position="top-full left-0"
          />
          <input
            v-model="newColorName"
            class="flex-1 min-w-0 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300"
            placeholder="Name (e.g. Primary)"
            @keydown.enter="handleAddColor"
          />
          <button
            @click="handleAddColor"
            class="px-3 py-1.5 text-xs font-medium bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors flex-shrink-0"
          >
            Add
          </button>
        </div>
      </div>

      <!-- Save color from selection -->
      <div v-if="canSaveColorFromSelection" class="px-4 pb-2">
        <button
          @click="saveColorFromSelection"
          class="w-full flex items-center justify-center gap-1 px-2 py-1.5 text-[10px] font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-dashed border-surface-300 dark:border-surface-600"
        >
          <span class="material-symbols-rounded" style="font-size: 13px;">add_circle</span>
          Save color from selection
        </button>
      </div>

      <div class="border-t border-surface-100 dark:border-surface-700"></div>

      <!-- ═══ GRADIENTS (global) ═══ -->
      <div class="px-4 py-3">
        <div class="flex items-center justify-between mb-2">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Gradients</p>
          <span v-if="gsReady" class="text-[8px] text-surface-400 font-medium">Click apply / Shift+click edit</span>
        </div>

        <div class="flex flex-wrap gap-2.5" v-if="gradients.length">
          <div
            v-for="grad in gradients"
            :key="grad.id"
            class="relative group"
          >
            <button
              @click="onGradientSwatchClick($event, grad)"
              class="w-12 h-8 rounded-lg border-2 border-surface-200 dark:border-surface-600 hover:scale-110 hover:ring-2 hover:ring-primary-300 transition-all shadow-sm cursor-pointer"
              :style="{ background: gradientCSS(grad) }"
              :title="`${grad.name || 'Gradient'}\nClick = apply · Shift+click = edit`"
            />
            <span
              v-if="getUsage(grad.id) > 0"
              class="absolute -top-1.5 -left-1.5 min-w-[14px] h-[14px] flex items-center justify-center text-[7px] font-bold bg-primary-500 text-white rounded-full px-0.5 shadow pointer-events-none"
            >{{ getUsage(grad.id) }}</span>
            <button
              @click.stop="removeGradient(grad.id)"
              class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow"
              title="Remove"
            >
              <span class="material-symbols-rounded text-[10px]">close</span>
            </button>
          </div>
        </div>
        <p v-else class="text-xs text-surface-400 italic">No gradients saved yet</p>

        <!-- Save gradient from selection -->
        <div v-if="canSaveGradientFromSelection" class="mt-2">
          <button
            @click="saveGradientFromSelection"
            class="w-full flex items-center justify-center gap-1 px-2 py-1.5 text-[10px] font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-dashed border-surface-300 dark:border-surface-600"
          >
            <span class="material-symbols-rounded" style="font-size: 13px;">add_circle</span>
            Save gradient from selection
          </button>
        </div>
      </div>

      <div class="border-t border-surface-100 dark:border-surface-700"></div>

      <!-- ═══ SAVE AS REUSABLE PALETTE ═══ -->
      <div class="px-4 py-3">
        <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider mb-2">Save Board Palette</p>
        <div class="flex items-center gap-1.5">
          <input
            v-model="newPaletteName"
            class="min-w-0 flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300"
            placeholder="Palette name..."
            @keydown.enter="handleSaveBoardPalette"
          />
          <button
            @click="handleSaveBoardPalette"
            :disabled="!newPaletteName.trim() || savingPalette"
            class="px-3 py-1.5 text-xs font-medium bg-primary-500 hover:bg-primary-600 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg transition-colors flex items-center gap-1"
          >
            <span class="material-symbols-rounded" style="font-size: 14px;">save</span>
            Save
          </button>
        </div>
        <div class="mt-2 flex items-center justify-between">
          <span class="text-[10px] text-surface-400">Share with colleagues</span>
          <button
            @click="sharePaletteToggle = !sharePaletteToggle"
            :class="[
              'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
              sharePaletteToggle ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform',
                sharePaletteToggle ? 'translate-x-4.5' : 'translate-x-0.5'
              ]"
            />
          </button>
        </div>
      </div>

      <div class="border-t border-surface-100 dark:border-surface-700"></div>

      <!-- ═══ SAVED PALETTES (cross-board reusable sets) ═══ -->
      <div class="px-4 py-3">
        <div class="flex items-center justify-between mb-1">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Saved Palettes</p>
          <button
            @click="store.fetchUserPalettes()"
            class="p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 transition-colors"
            title="Refresh palettes"
          >
            <span class="material-symbols-rounded" style="font-size: 14px;">refresh</span>
          </button>
        </div>
        <p class="text-[9px] text-surface-400 mb-2">Reusable color sets you can apply to any board</p>

        <div v-if="store.userPalettesLoading" class="flex items-center gap-2 text-xs text-surface-400 py-2">
          <span class="material-symbols-rounded animate-spin" style="font-size: 14px;">progress_activity</span>
          Loading...
        </div>

        <div v-else-if="store.userPalettes.length === 0" class="text-xs text-surface-400 italic py-1">
          No saved palettes yet. Save your board palette above to create one.
        </div>

        <div v-else class="space-y-2">
          <div
            v-for="up in store.userPalettes"
            :key="up.id"
            class="rounded-xl border border-surface-200 dark:border-surface-600 p-2 hover:border-primary-300 dark:hover:border-primary-600 transition-colors group"
          >
            <div class="flex items-center justify-between mb-1.5">
              <div class="flex items-center gap-1 min-w-0">
                <span v-if="up.is_shared" class="material-symbols-rounded text-primary-500" style="font-size: 12px;" title="Shared palette">group</span>
                <span v-if="!up.is_own" class="material-symbols-rounded text-amber-500" style="font-size: 12px;" title="From colleague">person</span>
                <template v-if="editingPaletteId === up.id">
                  <input
                    ref="editNameInput"
                    v-model="editingPaletteName"
                    class="flex-1 text-[11px] bg-surface-50 dark:bg-surface-700 border border-primary-300 rounded px-1 py-0.5 text-surface-700 dark:text-surface-300 focus:outline-none min-w-0"
                    @keydown.enter="finishEditName(up.id)"
                    @keydown.escape="editingPaletteId = null"
                    @blur="finishEditName(up.id)"
                  />
                </template>
                <span v-else class="text-[11px] font-medium text-surface-700 dark:text-surface-300 truncate">
                  {{ up.name }}
                </span>
              </div>
              <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
                <button
                  v-if="up.is_own" @click="startEditName(up)"
                  class="p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400" title="Rename"
                >
                  <span class="material-symbols-rounded" style="font-size: 12px;">edit</span>
                </button>
                <button
                  v-if="up.is_own" @click="togglePaletteShared(up)"
                  :class="['p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors', up.is_shared ? 'text-primary-500' : 'text-surface-400']"
                  :title="up.is_shared ? 'Make private' : 'Share with colleagues'"
                >
                  <span class="material-symbols-rounded" style="font-size: 12px;">{{ up.is_shared ? 'lock_open' : 'lock' }}</span>
                </button>
                <button
                  v-if="up.is_own" @click="handleDeletePalette(up.id)"
                  class="p-0.5 rounded hover:bg-red-50 dark:hover:bg-red-900/20 text-surface-400 hover:text-red-500 transition-colors" title="Delete"
                >
                  <span class="material-symbols-rounded" style="font-size: 12px;">delete</span>
                </button>
              </div>
            </div>

            <div class="flex flex-wrap gap-0.5 mb-1" v-if="up.colors?.length">
              <div
                v-for="(c, ci) in up.colors.slice(0, 12)" :key="'c-' + ci"
                class="w-4 h-4 rounded border border-surface-200 dark:border-surface-600"
                :style="{ backgroundColor: c }" :title="c"
              />
              <span v-if="up.colors.length > 12" class="text-[9px] text-surface-400 self-center ml-0.5">+{{ up.colors.length - 12 }}</span>
            </div>

            <div class="flex flex-wrap gap-0.5 mb-1.5" v-if="up.gradients?.length">
              <div
                v-for="(g, gi) in up.gradients.slice(0, 8)" :key="'g-' + gi"
                class="w-6 h-4 rounded border border-surface-200 dark:border-surface-600"
                :style="{ background: gradientCSS(g) }"
              />
              <span v-if="up.gradients.length > 8" class="text-[9px] text-surface-400 self-center ml-0.5">+{{ up.gradients.length - 8 }}</span>
            </div>

            <div class="flex items-center gap-1">
              <button
                @click="handleApplyPalette(up.id, 'merge')"
                class="flex-1 flex items-center justify-center gap-1 px-2 py-1 text-[10px] font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20 hover:bg-primary-100 dark:hover:bg-primary-900/30 rounded-lg transition-colors"
                title="Merge into current board"
              >
                <span class="material-symbols-rounded" style="font-size: 12px;">add_circle</span>
                Merge
              </button>
              <button
                @click="handleApplyPalette(up.id, 'replace')"
                class="flex-1 flex items-center justify-center gap-1 px-2 py-1 text-[10px] font-medium text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/30 rounded-lg transition-colors"
                title="Replace current palette"
              >
                <span class="material-symbols-rounded" style="font-size: 12px;">swap_horiz</span>
                Replace
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="border-t border-surface-100 dark:border-surface-700"></div>

      <!-- ═══ EXTRACT FROM BOARD ═══ -->
      <div class="px-4 py-3">
        <button
          @click="extractFromBoard"
          class="w-full flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-xl transition-colors border border-surface-200 dark:border-surface-600"
        >
          <span class="material-symbols-rounded text-sm">colorize</span>
          Extract Colors from Board
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import MoodColorPicker from './MoodColorPicker.vue'

const props = defineProps({
  embedded: { type: Boolean, default: false }
})

const emit = defineEmits(['close', 'pick-color'])
const store = useMoodBoardsStore()

const newColor = ref('#6366f1')
const newColorName = ref('')
const newPaletteName = ref('')
const sharePaletteToggle = ref(false)
const savingPalette = ref(false)
const editingPaletteId = ref(null)
const editingPaletteName = ref('')
const editNameInput = ref(null)
const hiddenColorInput = ref(null)
const renameInput = ref(null)
const renamingToken = ref(null)
const renameValue = ref('')

const _gs = ref(null)
const gsReady = ref(false)
let _editingTokenId = null

onMounted(async () => {
  try {
    const mod = await import('@/addons/moodboards/stores/moodBoardGlobalStyles')
    _gs.value = mod.useMoodBoardGlobalStylesStore()
    gsReady.value = true
    mergeFromLegacyPalette()
  } catch (e) {
    console.error('[MoodColorPalette] Global styles store init failed:', e)
  }

  if (store.userPalettes.length === 0) {
    store.fetchUserPalettes()
  }
})

function mergeFromLegacyPalette() {
  if (!_gs.value) return
  const legacyPalette = store.getColorPalette()
  if (!legacyPalette?.length) return
  const existingHexes = new Set(_gs.value.globalColors.map(t => t.value.toLowerCase()))
  const toAdd = legacyPalette.filter(hex => !existingHexes.has(hex.toLowerCase()))
  for (let i = 0; i < toAdd.length; i++) {
    _gs.value.addGlobalColor({
      id: `gc-${Date.now()}-${i}-${Math.random().toString(36).slice(2, 6)}`,
      name: toAdd[i].toUpperCase(),
      value: toAdd[i],
    })
  }
}

const colors = computed(() => _gs.value?.globalColors || [])
const gradients = computed(() => _gs.value?.globalGradients || [])

function handleAddColor() {
  if (!newColor.value) return
  const isHex = /^#[0-9a-fA-F]{3,8}$/.test(newColor.value)
  const isRgba = /^rgba?\(\s*\d+/.test(newColor.value)
  if (!isHex && !isRgba) return

  const name = newColorName.value.trim() || newColor.value.toUpperCase()
  if (_gs.value) {
    _gs.value.addGlobalColor({
      id: `gc-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
      name,
      value: newColor.value,
    })
  } else {
    store.addToPalette(newColor.value)
  }
  newColorName.value = ''
}

function startRename(token) {
  renamingToken.value = token
  renameValue.value = token.name || token.value
  nextTick(() => {
    const el = Array.isArray(renameInput.value) ? renameInput.value[0] : renameInput.value
    el?.focus()
    el?.select()
  })
}

function finishRename() {
  if (!renamingToken.value || !_gs.value) return
  const newName = renameValue.value.trim()
  if (newName && newName !== renamingToken.value.name) {
    _gs.value.renameGlobalColor(renamingToken.value.id, newName)
  }
  renamingToken.value = null
}

function removeColor(tokenId) {
  _gs.value?.removeGlobalColor(tokenId)
}

function onSwatchClick(event, token) {
  if (event.shiftKey) {
    _editingTokenId = token.id
    if (hiddenColorInput.value) {
      hiddenColorInput.value.value = token.value
      hiddenColorInput.value.click()
    }
  } else {
    applyToSelected(token)
  }
}

let _editDebounce = null
function onHiddenColorChange(newHex) {
  if (!_editingTokenId) return
  clearTimeout(_editDebounce)
  _editDebounce = setTimeout(() => {
    _gs.value?.updateGlobalColorValue(_editingTokenId, newHex)
  }, 300)
}

function applyToSelected(token) {
  if (!_gs.value || !store.selectedItems.length) return
  _gs.value.applyColorToItems(token.id, [...store.selectedItemIds])
}

function getUsage(tokenId) {
  return _gs.value?.getUsageCount(tokenId) || 0
}

function extractFromBoard() {
  if (_gs.value) {
    _gs.value.extractColorsFromBoard()
  }
}

function gradientCSS(sg) {
  const stops = (sg.stops || []).map(s => `${s.color} ${s.position}%`).join(', ')
  const type = sg.type || 'linear'
  if (type === 'radial') return `radial-gradient(circle, ${stops})`
  return `linear-gradient(${sg.angle || 135}deg, ${stops})`
}

function onGradientSwatchClick(event, grad) {
  if (event.shiftKey) {
    // TODO: open gradient editor (future enhancement)
    return
  }
  applyGradientToSelected(grad)
}

function applyGradientToSelected(grad) {
  if (!_gs.value || !store.selectedItems.length) return
  _gs.value.applyGradientToItems(grad.id, [...store.selectedItemIds])
}

function removeGradient(gradientId) {
  _gs.value?.removeGlobalGradient(gradientId)
}

// ── Save from selection ──

const selectedItem = computed(() => store.selectedItems[0])

const canSaveColorFromSelection = computed(() => {
  const item = selectedItem.value
  if (!item || !_gs.value) return false
  const sd = item.style_data || {}
  return !!(item.color || sd.shape_fill || sd.text_color || sd.fill_color || sd.line_color)
})

const canSaveGradientFromSelection = computed(() => {
  const item = selectedItem.value
  if (!item || !_gs.value) return false
  return !!(item.style_data?.gradient?.stops?.length)
})

function saveColorFromSelection() {
  const item = selectedItem.value
  if (!item || !_gs.value) return
  const sd = item.style_data || {}
  const hex = item.color || sd.shape_fill || sd.text_color || sd.fill_color || sd.line_color
  if (!hex) return
  const exists = _gs.value.globalColors.some(c => c.value.toLowerCase() === hex.toLowerCase())
  if (exists) return
  _gs.value.addGlobalColor({
    id: `gc-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: hex.toUpperCase(),
    value: hex,
  })
}

function saveGradientFromSelection() {
  const item = selectedItem.value
  if (!item || !_gs.value) return
  const sd = item.style_data || {}
  if (!sd.gradient?.stops?.length) return
  _gs.value.addGlobalGradient({
    id: `gg-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: `Gradient ${_gs.value.globalGradients.length + 1}`,
    type: sd.fill_type || 'linear',
    angle: sd.gradient.angle ?? 135,
    stops: JSON.parse(JSON.stringify(sd.gradient.stops)),
  })
}

async function handleSaveBoardPalette() {
  const name = newPaletteName.value.trim()
  if (!name) return
  savingPalette.value = true
  try {
    await store.saveBoardAsUserPalette(name, sharePaletteToggle.value)
    newPaletteName.value = ''
    sharePaletteToggle.value = false
  } finally {
    savingPalette.value = false
  }
}

async function handleApplyPalette(paletteId, mode) {
  await store.applyUserPaletteToBoard(paletteId, mode)
}

async function handleDeletePalette(id) {
  await store.deleteUserPalette(id)
}

function startEditName(palette) {
  editingPaletteId.value = palette.id
  editingPaletteName.value = palette.name
  nextTick(() => {
    const el = Array.isArray(editNameInput.value) ? editNameInput.value[0] : editNameInput.value
    el?.focus()
    el?.select()
  })
}

async function finishEditName(id) {
  if (!editingPaletteName.value.trim()) {
    editingPaletteId.value = null
    return
  }
  await store.updateUserPalette(id, { name: editingPaletteName.value.trim() })
  editingPaletteId.value = null
}

async function togglePaletteShared(palette) {
  await store.updateUserPalette(palette.id, { is_shared: !palette.is_shared })
}
</script>
