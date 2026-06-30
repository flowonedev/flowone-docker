<template>
  <div class="flex flex-col h-full overflow-hidden">
    <!-- Header -->
    <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-1.5">
          <span class="material-symbols-rounded text-sm text-primary-500">token</span>
          <h3 class="text-[11px] font-semibold text-surface-700 dark:text-surface-300 uppercase tracking-wider">Globals</h3>
        </div>
      </div>
      <!-- Search -->
      <div class="relative">
        <span class="material-symbols-rounded absolute left-2 top-1/2 -translate-y-1/2 text-sm text-surface-400">search</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search globals..."
          class="w-full pl-7 pr-3 py-1.5 text-xs rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-800 dark:text-surface-200 focus:ring-1 focus:ring-primary-500/40 outline-none"
        />
      </div>
      <!-- Filter chips -->
      <div class="flex items-center flex-wrap gap-0.5 mt-1.5">
        <button
          v-for="f in filterChips"
          :key="f.key"
          @click="activeFilter = f.key"
          class="px-1.5 py-0.5 text-[9px] rounded-full transition-colors"
          :class="activeFilter === f.key
            ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
            : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'"
        >{{ f.label }}</button>
      </div>
    </div>

    <!-- Scrollable sections -->
    <div class="flex-1 overflow-y-auto custom-scrollbar min-h-0">

      <!-- ════ COLORS ════ -->
      <GlobalSection
        v-if="showSection('colors')"
        title="Colors"
        icon="palette"
        :count="colors.length"
        :collapsed="sections.colors"
        @toggle="sections.colors = !sections.colors"
      >
        <div class="flex flex-wrap gap-2.5" v-if="filteredColors.length">
          <div
            v-for="token in filteredColors"
            :key="token.id"
            class="relative group"
          >
            <button
              @click="onColorClick($event, token)"
              @dblclick.stop="startColorRename(token)"
              class="w-9 h-9 rounded-lg border-2 border-surface-200 dark:border-surface-600 hover:scale-110 hover:ring-2 hover:ring-primary-300 transition-all shadow-sm cursor-pointer"
              :style="{ backgroundColor: token.value }"
              :title="`${token.name}\nClick = apply · Shift+click = edit · Dbl-click = rename`"
            />
            <span
              v-if="getColorUsage(token.id) > 0"
              class="absolute -top-1.5 -left-1.5 min-w-[14px] h-[14px] flex items-center justify-center text-[7px] font-bold bg-primary-500 text-white rounded-full px-0.5 shadow pointer-events-none"
            >{{ getColorUsage(token.id) }}</span>
            <button
              @click.stop="gs?.removeGlobalColor(token.id)"
              class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow"
            ><span class="material-symbols-rounded text-[10px]">close</span></button>
          </div>
        </div>
        <p v-else class="text-xs text-surface-400 italic">No colors</p>

        <!-- Rename inline -->
        <div v-if="renamingColor" class="mt-2 flex items-center gap-1.5">
          <div class="w-5 h-5 rounded border border-surface-300 dark:border-surface-600 flex-shrink-0" :style="{ backgroundColor: renamingColor.value }" />
          <input ref="colorRenameInput" v-model="colorRenameName" class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-primary-300 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300 focus:outline-none" @keydown.enter="finishColorRename" @keydown.escape="renamingColor = null" @blur="finishColorRename" />
        </div>

        <!-- Hidden input for shift+click editing -->
        <input ref="hiddenColorInput" type="color" class="absolute w-0 h-0 opacity-0 pointer-events-none" @input="onColorEdit($event.target.value)" />

        <!-- Add color -->
        <div class="mt-3 flex items-center gap-1.5">
          <MoodColorPicker :model-value="newColor" @update:model-value="newColor = $event" label="Pick" :show-caret="false" dropdown-position="top-full left-0" />
          <input v-model="newColorName" class="flex-1 min-w-0 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300" placeholder="Name (e.g. Primary)" @keydown.enter="addColor" />
          <button @click="addColor" class="px-2.5 py-1 text-xs font-medium bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors flex-shrink-0">Add</button>
        </div>

        <!-- Save from selection -->
        <button
          v-if="canSaveColor"
          @click="saveColorFromSelection"
          class="mt-2 w-full flex items-center justify-center gap-1 px-2 py-1.5 text-[10px] font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-dashed border-surface-300 dark:border-surface-600"
        >
          <span class="material-symbols-rounded" style="font-size: 13px;">add_circle</span>
          Save color from selection
        </button>
      </GlobalSection>

      <!-- ════ GRADIENTS ════ -->
      <GlobalSection
        v-if="showSection('gradients')"
        title="Gradients"
        icon="gradient"
        :count="gradients.length"
        :collapsed="sections.gradients"
        @toggle="sections.gradients = !sections.gradients"
      >
        <div class="flex flex-wrap gap-2.5" v-if="filteredGradients.length">
          <div v-for="grad in filteredGradients" :key="grad.id" class="relative group">
            <button
              @click="onGradientClick($event, grad)"
              class="w-14 h-9 rounded-lg border-2 border-surface-200 dark:border-surface-600 hover:scale-110 hover:ring-2 hover:ring-primary-300 transition-all shadow-sm cursor-pointer"
              :style="{ background: gradientCSS(grad) }"
              :title="`${grad.name || 'Gradient'}\nClick = apply`"
            />
            <button
              @click.stop="gs?.removeGlobalGradient(grad.id)"
              class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow"
            ><span class="material-symbols-rounded text-[10px]">close</span></button>
          </div>
        </div>
        <p v-else class="text-xs text-surface-400 italic">No gradients</p>

        <button
          v-if="canSaveGradient"
          @click="saveGradientFromSelection"
          class="mt-2 w-full flex items-center justify-center gap-1 px-2 py-1.5 text-[10px] font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-dashed border-surface-300 dark:border-surface-600"
        >
          <span class="material-symbols-rounded" style="font-size: 13px;">add_circle</span>
          Save gradient from selection
        </button>
      </GlobalSection>

      <!-- ════ CHARACTER STYLES ════ -->
      <GlobalSection
        v-if="showSection('text')"
        title="Character Styles"
        icon="text_fields"
        :count="textStyles.length"
        :collapsed="sections.text"
        @toggle="sections.text = !sections.text"
      >
        <div v-if="filteredTextStyles.length" class="space-y-1">
          <div
            v-for="ts in filteredTextStyles"
            :key="ts.id"
            class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer group transition-colors"
            @click="applyTextStyle(ts)"
          >
            <span
              class="text-lg font-bold text-surface-600 dark:text-surface-400 leading-none flex-shrink-0 w-7 text-center"
              :style="{ fontFamily: ts.font_family, fontWeight: ts.font_weight }"
            >Ag</span>
            <span class="text-[11px] font-medium text-surface-700 dark:text-surface-300 flex-1 truncate">{{ ts.name }}</span>
            <span v-if="getTextStyleUsage(ts.id) > 0" class="text-[8px] font-bold text-primary-500 bg-primary-50 dark:bg-primary-900/30 rounded-full min-w-[16px] h-[16px] flex items-center justify-center">{{ getTextStyleUsage(ts.id) }}</span>
            <button
              @click.stop="editTextStyle(ts)"
              class="p-0.5 rounded opacity-0 group-hover:opacity-100 text-surface-400 hover:text-surface-600 transition-all"
            ><span class="material-symbols-rounded text-xs">edit</span></button>
            <button
              @click.stop="gs?.removeGlobalTextStyle(ts.id)"
              class="p-0.5 rounded opacity-0 group-hover:opacity-100 text-surface-400 hover:text-red-500 transition-all"
            ><span class="material-symbols-rounded text-xs">delete</span></button>
          </div>
        </div>
        <p v-else class="text-xs text-surface-400 italic">No character styles</p>

        <!-- Inline text style editor -->
        <div v-if="tsEditorOpen" class="mt-2 p-2.5 rounded-lg border border-primary-200 dark:border-primary-800 bg-primary-50/30 dark:bg-primary-900/10 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-[9px] font-semibold text-primary-600 dark:text-primary-400 uppercase tracking-wider">{{ tsEditor.id ? 'Edit Style' : 'New Style' }}</span>
            <button @click="tsEditorOpen = false" class="text-surface-400 hover:text-surface-600"><span class="material-symbols-rounded text-sm">close</span></button>
          </div>
          <input v-model="tsEditor.name" class="w-full text-xs bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300" placeholder="Style name..." />
          <div class="grid grid-cols-2 gap-1.5">
            <input v-model="tsEditor.font_family" class="text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300" placeholder="Font family" />
            <input v-model="tsEditor.font_weight" class="text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300" placeholder="Weight" />
            <input v-model.number="tsEditor.font_size" type="number" class="text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300" placeholder="Size" />
            <input v-model.number="tsEditor.line_height" type="number" step="0.01" class="text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300" placeholder="Line height" />
          </div>
          <button @click="saveTextStyle" :disabled="!tsEditor.name?.trim()" class="w-full px-3 py-1.5 text-xs font-medium bg-primary-500 hover:bg-primary-600 disabled:opacity-40 text-white rounded-lg transition-colors">
            {{ tsEditor.id ? 'Update' : 'Create' }}
          </button>
        </div>

        <div class="mt-2 flex items-center gap-1">
          <button @click="openNewTextStyle" class="flex-1 flex items-center justify-center gap-1 px-2 py-1.5 text-[10px] font-medium text-surface-500 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-dashed border-surface-300 dark:border-surface-600">
            <span class="material-symbols-rounded" style="font-size: 13px;">add</span> New style
          </button>
          <button v-if="canSaveTextStyle" @click="saveTextStyleFromSelection" class="flex-1 flex items-center justify-center gap-1 px-2 py-1.5 text-[10px] font-medium text-surface-500 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-dashed border-surface-300 dark:border-surface-600">
            <span class="material-symbols-rounded" style="font-size: 13px;">add_circle</span> From selection
          </button>
        </div>
      </GlobalSection>

      <!-- ════ CSS CLASSES ════ -->
      <GlobalSection
        v-if="showSection('classes')"
        title="CSS Classes"
        icon="style"
        :count="cssClasses.length"
        :collapsed="sections.classes"
        @toggle="sections.classes = !sections.classes"
      >
        <div v-if="filteredCssClasses.length" class="space-y-1">
          <div
            v-for="cls in filteredCssClasses"
            :key="cls.id"
            class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer group transition-colors"
            @click="editCssClass(cls)"
          >
            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-medium bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300">.{{ cls.name }}</span>
            <span class="text-[9px] text-surface-400 flex-1 truncate">{{ cssClassSummary(cls) }}</span>
            <button
              @click.stop="gs?.removeGlobalCssClass(cls.id)"
              class="p-0.5 rounded opacity-0 group-hover:opacity-100 text-surface-400 hover:text-red-500 transition-all"
            ><span class="material-symbols-rounded text-xs">delete</span></button>
          </div>
        </div>
        <p v-else class="text-xs text-surface-400 italic">No CSS classes defined</p>

        <!-- Class editor -->
        <div v-if="cssClassEditorOpen" class="mt-2 p-2.5 rounded-lg border border-primary-200 dark:border-primary-800 bg-primary-50/30 dark:bg-primary-900/10 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-[9px] font-semibold text-primary-600 dark:text-primary-400 uppercase tracking-wider">{{ cssClassEditor.id ? 'Edit Class' : 'New Class' }}</span>
            <button @click="cssClassEditorOpen = false" class="text-surface-400 hover:text-surface-600"><span class="material-symbols-rounded text-sm">close</span></button>
          </div>
          <input v-model="cssClassEditor.name" class="w-full text-xs bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300" placeholder="Class name (e.g. orange, large-text)..." />

          <!-- Property rows -->
          <div class="space-y-1.5">
            <div v-for="(prop, idx) in cssClassEditor.propRows" :key="idx" class="flex items-center gap-1">
              <select v-model="prop.key" class="flex-1 text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-1.5 py-1 text-surface-700 dark:text-surface-300">
                <option value="">Select property...</option>
                <optgroup label="Color">
                  <option value="color">text color</option>
                  <option value="background">background</option>
                </optgroup>
                <optgroup label="Typography">
                  <option value="font_size">font size</option>
                  <option value="font_weight">font weight</option>
                  <option value="font_family">font family</option>
                  <option value="text_align">text align</option>
                </optgroup>
                <optgroup label="Spacing">
                  <option value="padding">padding</option>
                  <option value="margin">margin</option>
                </optgroup>
                <optgroup label="Visual">
                  <option value="border_radius">border radius</option>
                  <option value="opacity">opacity</option>
                  <option value="border_color">border color</option>
                  <option value="border_width">border width</option>
                </optgroup>
              </select>
              <input
                v-if="isColorProp(prop.key)"
                type="color"
                v-model="prop.value"
                class="w-7 h-7 rounded border border-surface-200 dark:border-surface-600 cursor-pointer p-0"
              />
              <input
                v-else
                v-model="prop.value"
                class="flex-1 text-[10px] bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-1.5 py-1 text-surface-700 dark:text-surface-300"
                :placeholder="propPlaceholder(prop.key)"
              />
              <button @click="cssClassEditor.propRows.splice(idx, 1)" class="p-0.5 text-surface-400 hover:text-red-500"><span class="material-symbols-rounded text-xs">close</span></button>
            </div>
          </div>
          <button @click="cssClassEditor.propRows.push({ key: '', value: '' })" class="flex items-center gap-1 text-[10px] text-primary-500 hover:text-primary-600">
            <span class="material-symbols-rounded" style="font-size: 13px;">add</span> Add property
          </button>
          <button @click="saveCssClass" :disabled="!cssClassEditor.name?.trim() || !cssClassEditor.propRows.some(p => p.key && p.value)" class="w-full px-3 py-1.5 text-xs font-medium bg-primary-500 hover:bg-primary-600 disabled:opacity-40 text-white rounded-lg transition-colors">
            {{ cssClassEditor.id ? 'Update' : 'Create' }}
          </button>
        </div>

        <div class="mt-2">
          <button @click="openNewCssClass" class="w-full flex items-center justify-center gap-1 px-2 py-1.5 text-[10px] font-medium text-surface-500 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-dashed border-surface-300 dark:border-surface-600">
            <span class="material-symbols-rounded" style="font-size: 13px;">add</span> New class
          </button>
        </div>
      </GlobalSection>

      <!-- ════ COMPONENTS ════ -->
      <GlobalSection
        v-if="showSection('components')"
        title="Components"
        icon="widgets"
        :count="components.length"
        :collapsed="sections.components"
        @toggle="sections.components = !sections.components"
      >
        <div v-if="filteredComponents.length" class="space-y-1">
          <div
            v-for="comp in filteredComponents"
            :key="comp.id"
            class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer group transition-colors"
            @click="$emit('place-component', comp)"
          >
            <div class="w-7 h-7 rounded-md bg-surface-100 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-sm text-surface-500">{{ getCategoryIcon(comp.category) }}</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-[11px] font-medium text-surface-700 dark:text-surface-300 truncate">{{ comp.name }}</p>
              <p class="text-[8px] text-surface-400">{{ comp.items_data?.length || 0 }} items</p>
            </div>
            <span v-if="getCompUsageCount(comp.id) > 0" class="text-[8px] font-bold text-primary-500 bg-primary-50 dark:bg-primary-900/30 rounded-full min-w-[16px] h-[16px] flex items-center justify-center">{{ getCompUsageCount(comp.id) }}</span>
            <span class="material-symbols-rounded text-sm text-primary-500 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">add_circle</span>
          </div>
        </div>
        <p v-else class="text-xs text-surface-400 italic">No components saved</p>

        <!-- Save from selection -->
        <div v-if="store.selectedItems.length >= 1" class="mt-2 flex items-center gap-1.5">
          <input v-model="newCompName" class="flex-1 min-w-0 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300" placeholder="Component name..." @keydown.enter="saveComponent" />
          <button @click="saveComponent" :disabled="!newCompName.trim() || savingComp" class="px-2.5 py-1 text-xs font-medium bg-primary-500 hover:bg-primary-600 disabled:opacity-40 text-white rounded-lg transition-colors flex-shrink-0">Save</button>
        </div>
      </GlobalSection>

      <!-- ════ UTILITIES ════ -->
      <GlobalSection
        v-if="activeFilter === 'all'"
        title="Utilities"
        icon="build"
        :count="0"
        :collapsed="sections.utilities"
        @toggle="sections.utilities = !sections.utilities"
        :hide-count="true"
      >
        <button
          @click="extractColors"
          class="w-full flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-lg transition-colors border border-surface-200 dark:border-surface-600"
        >
          <span class="material-symbols-rounded text-sm">colorize</span>
          Extract Colors from Board
        </button>
      </GlobalSection>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, reactive } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import MoodColorPicker from './MoodColorPicker.vue'
import GlobalSection from './GlobalSection.vue'
import api from '@/services/api'

const emit = defineEmits(['place-component', 'edit-component-items'])
const store = useMoodBoardsStore()

const search = ref('')
const activeFilter = ref('all')
const filterChips = [
  { key: 'all', label: 'All' },
  { key: 'colors', label: 'Colors' },
  { key: 'gradients', label: 'Gradients' },
  { key: 'text', label: 'Text' },
  { key: 'classes', label: 'Classes' },
  { key: 'components', label: 'Components' },
]

const sections = reactive({
  colors: false,
  gradients: false,
  text: false,
  classes: false,
  components: false,
  utilities: true,
})

function showSection(key) {
  return activeFilter.value === 'all' || activeFilter.value === key
}

// ── Global styles store (lazy) ──
const gs = ref(null)
onMounted(async () => {
  try {
    const mod = await import('@/addons/moodboards/stores/moodBoardGlobalStyles')
    gs.value = mod.useMoodBoardGlobalStylesStore()
    mergeFromLegacyPalette()
    loadComponents()
  } catch (e) {
    console.error('[MoodGlobalsPanel] Global styles store init failed:', e)
  }
})

function mergeFromLegacyPalette() {
  if (!gs.value) return
  const legacy = store.getColorPalette()
  if (!legacy?.length) return
  const existing = new Set(gs.value.globalColors.map(t => t.value.toLowerCase()))
  const toAdd = legacy.filter(hex => !existing.has(hex.toLowerCase()))
  for (let i = 0; i < toAdd.length; i++) {
    gs.value.addGlobalColor({
      id: `gc-${Date.now()}-${i}-${Math.random().toString(36).slice(2, 6)}`,
      name: toAdd[i].toUpperCase(),
      value: toAdd[i],
    })
  }
}

// ── Colors ──
const colors = computed(() => gs.value?.globalColors || [])
const filteredColors = computed(() => {
  if (!search.value) return colors.value
  const q = search.value.toLowerCase()
  return colors.value.filter(c => c.name?.toLowerCase().includes(q) || c.value?.toLowerCase().includes(q))
})

const newColor = ref('#6366f1')
const newColorName = ref('')
const renamingColor = ref(null)
const colorRenameName = ref('')
const colorRenameInput = ref(null)
const hiddenColorInput = ref(null)
let _editingColorId = null

function addColor() {
  if (!newColor.value || !gs.value) return
  const isValid = /^#[0-9a-fA-F]{3,8}$/.test(newColor.value) || /^rgba?\(/.test(newColor.value)
  if (!isValid) return
  gs.value.addGlobalColor({
    id: `gc-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: newColorName.value.trim() || newColor.value.toUpperCase(),
    value: newColor.value,
  })
  newColorName.value = ''
}

function onColorClick(event, token) {
  if (event.shiftKey) {
    _editingColorId = token.id
    if (hiddenColorInput.value) {
      hiddenColorInput.value.value = token.value
      hiddenColorInput.value.click()
    }
  } else {
    if (!gs.value || !store.selectedItems.length) return
    gs.value.applyColorToItems(token.id, [...store.selectedItemIds])
  }
}

let _colorEditDebounce = null
function onColorEdit(hex) {
  if (!_editingColorId) return
  clearTimeout(_colorEditDebounce)
  _colorEditDebounce = setTimeout(() => gs.value?.updateGlobalColorValue(_editingColorId, hex), 300)
}

function startColorRename(token) {
  renamingColor.value = token
  colorRenameName.value = token.name || token.value
  nextTick(() => { colorRenameInput.value?.focus(); colorRenameInput.value?.select() })
}

function finishColorRename() {
  if (!renamingColor.value || !gs.value) return
  const n = colorRenameName.value.trim()
  if (n && n !== renamingColor.value.name) gs.value.renameGlobalColor(renamingColor.value.id, n)
  renamingColor.value = null
}

function getColorUsage(id) { return gs.value?.getUsageCount(id) || 0 }

const selectedItem = computed(() => store.selectedItems[0])
const canSaveColor = computed(() => {
  const item = selectedItem.value
  if (!item || !gs.value) return false
  const sd = item.style_data || {}
  return !!(item.color || sd.shape_fill || sd.text_color || sd.fill_color)
})

function saveColorFromSelection() {
  const item = selectedItem.value
  if (!item || !gs.value) return
  const sd = item.style_data || {}
  const hex = item.color || sd.shape_fill || sd.text_color || sd.fill_color
  if (!hex || gs.value.globalColors.some(c => c.value.toLowerCase() === hex.toLowerCase())) return
  gs.value.addGlobalColor({ id: `gc-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`, name: hex.toUpperCase(), value: hex })
}

// ── Gradients ──
const gradients = computed(() => gs.value?.globalGradients || [])
const filteredGradients = computed(() => {
  if (!search.value) return gradients.value
  const q = search.value.toLowerCase()
  return gradients.value.filter(g => g.name?.toLowerCase().includes(q))
})

function gradientCSS(g) {
  const stops = (g.stops || []).map(s => `${s.color} ${s.position}%`).join(', ')
  if (g.type === 'radial') return `radial-gradient(circle, ${stops})`
  return `linear-gradient(${g.angle || 135}deg, ${stops})`
}

function onGradientClick(event, grad) {
  if (!gs.value || !store.selectedItems.length) return
  gs.value.applyGradientToItems(grad.id, [...store.selectedItemIds])
}

function _getItemGradient(item) {
  const sd = item?.style_data
  if (!sd) return null
  const t = item.type
  if (t === 'shape' || t === 'pen_shape') {
    if (sd.shape_fill_type === 'linear' || sd.shape_fill_type === 'radial') return sd.shape_fill_gradient
  } else if (t === 'frame') {
    if (sd.fill_type === 'linear' || sd.fill_type === 'radial') return sd.fill_gradient
  } else if (t === 'text') {
    if (sd.text_fill_type === 'linear' || sd.text_fill_type === 'radial') return sd.text_fill_gradient
  }
  return null
}

function _getItemFillType(item) {
  const sd = item?.style_data
  if (!sd) return 'solid'
  const t = item.type
  if (t === 'shape' || t === 'pen_shape') return sd.shape_fill_type || 'solid'
  if (t === 'frame') return sd.fill_type || 'solid'
  if (t === 'text') return sd.text_fill_type || 'solid'
  return 'solid'
}

const canSaveGradient = computed(() => {
  const item = selectedItem.value
  if (!item || !gs.value) return false
  const grad = _getItemGradient(item)
  return !!(grad?.stops?.length)
})

function saveGradientFromSelection() {
  const item = selectedItem.value
  if (!item || !gs.value) return
  const grad = _getItemGradient(item)
  if (!grad?.stops?.length) return
  const fillType = _getItemFillType(item)
  gs.value.addGlobalGradient({
    id: `gg-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: `Gradient ${gs.value.globalGradients.length + 1}`,
    type: fillType === 'radial' ? 'radial' : 'linear',
    angle: grad.angle ?? 135,
    stops: JSON.parse(JSON.stringify(grad.stops)),
  })
}

// ── Text Styles ──
const textStyles = computed(() => gs.value?.globalTextStyles || [])
const filteredTextStyles = computed(() => {
  if (!search.value) return textStyles.value
  const q = search.value.toLowerCase()
  return textStyles.value.filter(s => s.name?.toLowerCase().includes(q))
})

const tsEditorOpen = ref(false)
const tsEditor = ref({})

function openNewTextStyle() {
  tsEditor.value = { name: '', font_family: 'Inter', font_weight: '400', font_size: 16, line_height: 1.4, letter_spacing: 0, text_transform: 'none', text_color: '#000000' }
  tsEditorOpen.value = true
}

function editTextStyle(ts) {
  tsEditor.value = { ...ts }
  tsEditorOpen.value = true
}

function saveTextStyle() {
  if (!tsEditor.value.name?.trim() || !gs.value) return
  if (tsEditor.value.id) {
    gs.value.updateGlobalTextStyle(tsEditor.value.id, { ...tsEditor.value })
  } else {
    gs.value.addGlobalTextStyle({ ...tsEditor.value, id: `ts-${Date.now()}-${Math.random().toString(36).slice(2, 6)}` })
  }
  tsEditorOpen.value = false
}

function applyTextStyle(ts) {
  if (!gs.value || !store.selectedItems.length) return
  gs.value.applyTextStyleToItems(ts.id, [...store.selectedItemIds])
}

function getTextStyleUsage(id) { return gs.value?.getUsageCount(id) || 0 }

const canSaveTextStyle = computed(() => {
  const item = selectedItem.value
  if (!item || !gs.value) return false
  return item.type === 'text' || item.type === 'shape' || item.type === 'pen_shape'
})

function saveTextStyleFromSelection() {
  const item = selectedItem.value
  if (!item || !gs.value) return
  const sd = item.style_data || {}
  const isShape = item.type === 'shape' || item.type === 'pen_shape'
  gs.value.addGlobalTextStyle({
    id: `ts-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: `Style from ${item.content?.substring(0, 15) || item.type}`,
    font_family: isShape ? (sd.shape_font_family ?? 'Inter') : (sd.font_family ?? 'Inter'),
    font_weight: isShape ? (sd.shape_font_weight ?? '400') : (sd.font_weight ?? '400'),
    font_size: isShape ? (sd.shape_font_size ?? 16) : (sd.font_size ?? 16),
    line_height: isShape ? (sd.shape_line_height ?? 1) : (sd.line_height ?? 1),
    letter_spacing: isShape ? (sd.shape_letter_spacing ?? 0) : (sd.letter_spacing ?? 0),
    text_transform: isShape ? (sd.shape_text_transform ?? 'none') : (sd.text_transform ?? 'none'),
    text_color: isShape ? (sd.shape_text_color ?? '#000000') : (sd.text_color ?? '#000000'),
  })
}

// ── Components ──
const components = ref([])
const loadingComps = ref(false)
const newCompName = ref('')
const savingComp = ref(false)

async function loadComponents() {
  loadingComps.value = true
  try {
    const res = await api.get('/mood-boards/components', { params: { scope: 'mine' } })
    components.value = res.data?.data || []
  } catch (e) {
    console.error('[MoodGlobalsPanel] Failed to load components:', e)
  } finally {
    loadingComps.value = false
  }
}

const filteredComponents = computed(() => {
  if (!search.value) return components.value
  const q = search.value.toLowerCase()
  return components.value.filter(c => c.name?.toLowerCase().includes(q))
})

function getCategoryIcon(cat) {
  const map = { buttons: 'smart_button', navigation: 'menu', cards: 'dashboard', forms: 'input', heroes: 'web', footers: 'call_to_action' }
  return map[cat] || 'widgets'
}

function getCompUsageCount(compId) {
  return (store.currentBoard?.items || []).filter(i => i.component_id === compId).length
}

async function saveComponent() {
  if (!newCompName.value.trim() || savingComp.value) return
  savingComp.value = true
  const items = store.selectedItems
  if (!items.length) { savingComp.value = false; return }

  const minX = Math.min(...items.map(i => i.pos_x || 0))
  const minY = Math.min(...items.map(i => i.pos_y || 0))
  const itemsData = items.map(item => {
    const clone = JSON.parse(JSON.stringify(item))
    delete clone.id; delete clone.board_id; delete clone.created_at; delete clone.updated_at
    delete clone.component_id; delete clone.component_instance_id; delete clone.component_item_index
    clone.pos_x = (clone.pos_x || 0) - minX
    clone.pos_y = (clone.pos_y || 0) - minY
    return clone
  })

  try {
    const res = await api.post('/mood-boards/components', { name: newCompName.value.trim(), items_data: itemsData, category: 'general', is_global: 0 })
    if (res.data.success) {
      components.value.unshift(res.data.data)
      newCompName.value = ''
      autoSetComponentName(items, res.data.data.name)
    }
  } catch (e) {
    console.error('Failed to save component:', e)
  } finally {
    savingComp.value = false
  }
}

function autoSetComponentName(items, name) {
  for (const item of items) {
    if (item.type === 'group' && !item.title) {
      store.updateItem(item.id, { title: name })
      return
    }
  }
}

// ── CSS Classes ──
const cssClasses = computed(() => gs.value?.globalCssClasses || [])
const filteredCssClasses = computed(() => {
  if (!search.value) return cssClasses.value
  const q = search.value.toLowerCase()
  return cssClasses.value.filter(c => c.name?.toLowerCase().includes(q))
})

const cssClassEditorOpen = ref(false)
const cssClassEditor = ref({})

const CSS_CLASS_PROP_MAP = {
  color: 'text color',
  background: 'background',
  font_size: 'font size',
  font_weight: 'font weight',
  font_family: 'font family',
  text_align: 'text align',
  padding: 'padding',
  margin: 'margin',
  border_radius: 'border radius',
  opacity: 'opacity',
  border_color: 'border color',
  border_width: 'border width',
}

function cssClassSummary(cls) {
  if (!cls.properties) return ''
  return Object.entries(cls.properties)
    .map(([k, v]) => `${CSS_CLASS_PROP_MAP[k] || k}: ${v}`)
    .join(', ')
}

function isColorProp(key) {
  return key === 'color' || key === 'background' || key === 'border_color'
}

function propPlaceholder(key) {
  const map = { font_size: '16', font_weight: '700', font_family: 'Inter', padding: '16', margin: '0', border_radius: '8', opacity: '0.8', border_width: '2', text_align: 'center' }
  return map[key] || 'value'
}

function openNewCssClass() {
  cssClassEditor.value = { name: '', propRows: [{ key: '', value: '' }] }
  cssClassEditorOpen.value = true
}

function editCssClass(cls) {
  const propRows = cls.properties
    ? Object.entries(cls.properties).map(([key, value]) => ({ key, value: String(value) }))
    : [{ key: '', value: '' }]
  cssClassEditor.value = { id: cls.id, name: cls.name, propRows }
  cssClassEditorOpen.value = true
}

function saveCssClass() {
  const ed = cssClassEditor.value
  if (!ed.name?.trim() || !gs.value) return
  const properties = {}
  for (const row of (ed.propRows || [])) {
    if (row.key && row.value !== '' && row.value != null) {
      properties[row.key] = row.value
    }
  }
  if (ed.id) {
    gs.value.updateGlobalCssClass(ed.id, { name: ed.name.trim(), properties })
  } else {
    gs.value.addGlobalCssClass({
      id: `cc-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
      name: ed.name.trim(),
      properties,
    })
  }
  cssClassEditorOpen.value = false
}

function extractColors() { gs.value?.extractColorsFromBoard() }
</script>
