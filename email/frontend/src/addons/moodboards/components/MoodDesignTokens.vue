<template>
  <div class="flex flex-col">
    <!-- Loading / error state -->
    <div v-if="!ready" class="px-4 py-3 text-[11px] text-surface-400 italic">
      <template v-if="loadError">Global styles error: {{ loadError }}</template>
      <template v-else>Loading global colors...</template>
    </div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-2.5 border-b border-surface-100 dark:border-surface-700">
        <div class="flex items-center gap-1.5">
          <span class="material-symbols-rounded text-sm text-primary-500">token</span>
          <h3 class="text-[11px] font-semibold text-surface-700 dark:text-surface-300 uppercase tracking-wider">
            Global Colors
          </h3>
        </div>
        <span class="text-[9px] text-surface-400">Semantic Tokens</span>
      </div>

      <!-- Existing tokens -->
      <div class="px-4 py-3 space-y-2" v-if="colors.length">
        <div
          v-for="token in colors"
          :key="token.id"
          class="flex items-center gap-2 group"
        >
          <label class="relative flex-shrink-0 cursor-pointer">
            <div
              class="w-7 h-7 rounded-lg border-2 border-surface-200 dark:border-surface-600 shadow-sm hover:scale-110 hover:ring-2 hover:ring-primary-300 transition-all"
              :style="{ backgroundColor: token.value }"
            />
            <input
              type="color"
              :value="token.value"
              @change="onTokenColorChange(token.id, $event.target.value)"
              class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
            />
          </label>

          <div class="flex-1 min-w-0">
            <template v-if="editingTokenId === token.id">
              <input
                ref="editInput"
                v-model="editingTokenName"
                class="w-full text-[11px] bg-surface-50 dark:bg-surface-700 border border-primary-300 rounded px-1.5 py-0.5 text-surface-700 dark:text-surface-300 focus:outline-none"
                @keydown.enter="finishEditName(token.id)"
                @keydown.escape="editingTokenId = null"
                @blur="finishEditName(token.id)"
              />
            </template>
            <template v-else>
              <div class="flex items-center gap-1.5">
                <p
                  class="text-[11px] font-medium text-surface-700 dark:text-surface-300 truncate cursor-pointer hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                  @dblclick="startEditName(token)"
                >
                  {{ token.name }}
                </p>
                <span
                  v-if="getUsage(token.id) > 0"
                  class="flex-shrink-0 text-[8px] font-bold bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 px-1.5 py-0.5 rounded-full"
                  :title="`Used by ${getUsage(token.id)} item(s)`"
                >
                  {{ getUsage(token.id) }}
                </span>
              </div>
              <p class="text-[9px] text-surface-400 font-mono uppercase">{{ token.value }}</p>
            </template>
          </div>

          <button
            v-if="moodStore.selectedItems.length"
            @click="applyToSelected(token)"
            class="p-1 rounded-lg hover:bg-primary-50 dark:hover:bg-primary-900/20 text-primary-500 opacity-0 group-hover:opacity-100 transition-opacity"
            title="Apply and link to selected items"
          >
            <span class="material-symbols-rounded text-sm">format_paint</span>
          </button>

          <button
            v-if="getUsage(token.id) > 0"
            @click="selectItemsUsing(token.id)"
            class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 opacity-0 group-hover:opacity-100 transition-opacity"
            title="Select all items using this color"
          >
            <span class="material-symbols-rounded text-sm">select_all</span>
          </button>

          <button
            @click="removeToken(token.id)"
            class="p-1 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"
            title="Remove token"
          >
            <span class="material-symbols-rounded text-sm">close</span>
          </button>
        </div>
      </div>

      <p v-else class="px-4 py-3 text-[11px] text-surface-400 italic">
        No global colors yet. Add colors below.
      </p>

      <!-- Add new token -->
      <div class="px-4 py-3 border-t border-surface-100 dark:border-surface-700">
        <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider mb-2">Add Color</p>
        <div class="flex items-center gap-2">
          <label class="relative flex-shrink-0 cursor-pointer">
            <div
              class="w-7 h-7 rounded-lg border-2 border-dashed border-surface-300 dark:border-surface-600 hover:border-primary-400 transition-colors"
              :style="{ backgroundColor: newTokenColor }"
            />
            <input
              type="color"
              v-model="newTokenColor"
              class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
            />
          </label>
          <input
            v-model="newTokenName"
            class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300"
            placeholder="Color name..."
            @keydown.enter="addToken"
          />
          <button
            @click="addToken"
            :disabled="!newTokenName.trim()"
            class="px-2.5 py-1 text-xs font-medium bg-primary-500 hover:bg-primary-600 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg transition-colors"
          >
            Add
          </button>
        </div>
      </div>

      <!-- Extract from board -->
      <div class="px-4 py-2 border-t border-surface-100 dark:border-surface-700">
        <button
          @click="extractColors"
          class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-[11px] font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700 rounded-xl transition-colors border border-surface-200 dark:border-surface-600"
        >
          <span class="material-symbols-rounded text-sm">colorize</span>
          Auto-extract from board
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const moodStore = useMoodBoardsStore()

const _gs = ref(null)
const ready = ref(false)
const loadError = ref(null)

onMounted(async () => {
  try {
    const mod = await import('@/addons/moodboards/stores/moodBoardGlobalStyles')
    _gs.value = mod.useMoodBoardGlobalStylesStore()
    ready.value = true
  } catch (e) {
    loadError.value = e.message
    console.error('[MoodDesignTokens] Store init failed:', e)
  }
})

const colors = computed(() => _gs.value?.globalColors || [])

const newTokenName = ref('')
const newTokenColor = ref('#6366f1')
const editingTokenId = ref(null)
const editingTokenName = ref('')
const editInput = ref(null)

function addToken() {
  const name = newTokenName.value.trim()
  if (!name || !_gs.value) return
  const id = `gc-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`
  _gs.value.addGlobalColor({ id, name, value: newTokenColor.value })
  newTokenName.value = ''
  newTokenColor.value = '#6366f1'
}

function removeToken(tokenId) {
  _gs.value?.removeGlobalColor(tokenId)
}

function onTokenColorChange(tokenId, newColor) {
  _gs.value?.updateGlobalColorValue(tokenId, newColor)
}

function startEditName(token) {
  editingTokenId.value = token.id
  editingTokenName.value = token.name
  nextTick(() => {
    const el = Array.isArray(editInput.value) ? editInput.value[0] : editInput.value
    el?.focus()
    el?.select()
  })
}

function finishEditName(tokenId) {
  if (!editingTokenName.value.trim()) {
    editingTokenId.value = null
    return
  }
  _gs.value?.renameGlobalColor(tokenId, editingTokenName.value.trim())
  editingTokenId.value = null
}

function applyToSelected(token) {
  const ids = [...moodStore.selectedItemIds]
  if (!ids.length || !_gs.value) return
  _gs.value.applyColorToItems(token.id, ids)
}

function selectItemsUsing(tokenId) {
  const items = _gs.value?.getItemsUsing(tokenId) || []
  if (!items.length) return
  moodStore.selectedItemIds = new Set(items.map(i => i.id))
}

function getUsage(tokenId) {
  return _gs.value?.getUsageCount(tokenId) || 0
}

function extractColors() {
  _gs.value?.extractColorsFromBoard()
}
</script>
