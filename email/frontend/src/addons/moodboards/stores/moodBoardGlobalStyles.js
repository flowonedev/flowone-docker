/**
 * moodBoardGlobalStyles.js
 *
 * Dedicated Pinia store for board-level global colors and text styles.
 * Handles CRUD, semantic-binding propagation, and usage lookups.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useMoodBoardsStore } from './moodBoards'
import * as gsApi from '../services/globalStylesApi'
import {
  buildColorPropagationUpdates,
  buildTextStylePropagationUpdates,
  buildGradientPropagationUpdates,
  countGlobalUsage,
  findItemsUsingGlobal,
  linkColorToItem,
  linkTextStyleToItem,
  linkGradientToItem,
  unlinkFromGlobal,
  getGlobalsMap,
} from '../utils/globalStyleResolver'

export const useMoodBoardGlobalStylesStore = defineStore('moodBoardGlobalStyles', () => {
  // Lazy accessor to avoid circular dependency (moodBoards imports us, we import moodBoards)
  let _moodStore = null
  function moodStore() {
    if (!_moodStore) _moodStore = useMoodBoardsStore()
    return _moodStore
  }

  // ── State ──
  const globalColors = ref([])
  const globalGradients = ref([])
  const globalTextStyles = ref([])
  const globalCssClasses = ref([])
  const loading = ref(false)

  // ── Computed ──
  const currentBoardId = computed(() => moodStore().currentBoard?.id)
  const currentItems = computed(() => moodStore().currentBoard?.items || [])

  // ── Fetch ──

  async function fetchAll() {
    if (!currentBoardId.value) return
    if (moodStore().isPublicView) return
    loading.value = true
    try {
      const [colors, textStyles, cssClasses] = await Promise.all([
        gsApi.fetchGlobalColors(currentBoardId.value),
        gsApi.fetchGlobalTextStyles(currentBoardId.value),
        gsApi.fetchGlobalCssClasses(currentBoardId.value).catch(() => []),
      ])
      globalColors.value = Array.isArray(colors) ? colors : []
      globalTextStyles.value = Array.isArray(textStyles) ? textStyles : []
      globalCssClasses.value = Array.isArray(cssClasses) ? cssClasses : []
    } catch (e) {
      console.error('[GlobalStyles] fetchAll error:', e)
    } finally {
      loading.value = false
    }
  }

  function hydrateFromBoard() {
    const board = moodStore().currentBoard
    if (!board) return

    const rawColors = board.design_tokens
    if (rawColors) {
      try {
        globalColors.value = typeof rawColors === 'string'
          ? JSON.parse(rawColors)
          : (Array.isArray(rawColors) ? rawColors : [])
      } catch { globalColors.value = [] }
    }

    const rawGrad = board.gradient_palette
    if (rawGrad) {
      try {
        const arr = typeof rawGrad === 'string' ? JSON.parse(rawGrad) : (Array.isArray(rawGrad) ? rawGrad : [])
        globalGradients.value = arr.map((g, i) => g.id ? g : { ...g, id: `gg-migrated-${i}` })
      } catch { globalGradients.value = [] }
    }

    const rawTs = board.global_text_styles
    if (rawTs) {
      try {
        globalTextStyles.value = typeof rawTs === 'string'
          ? JSON.parse(rawTs)
          : (Array.isArray(rawTs) ? rawTs : [])
      } catch { globalTextStyles.value = [] }
    }

    const rawCls = board.global_css_classes
    if (rawCls) {
      try {
        globalCssClasses.value = typeof rawCls === 'string'
          ? JSON.parse(rawCls)
          : (Array.isArray(rawCls) ? rawCls : [])
      } catch { globalCssClasses.value = [] }
    }
  }

  // ── Global Color CRUD ──

  async function addGlobalColor(token) {
    globalColors.value = [...globalColors.value, token]
    await _saveColors()
  }

  async function removeGlobalColor(tokenId) {
    globalColors.value = globalColors.value.filter(t => t.id !== tokenId)
    await _saveColors()
  }

  async function updateGlobalColorValue(tokenId, newColor) {
    const token = globalColors.value.find(t => t.id === tokenId)
    if (!token || !currentBoardId.value) return

    const oldColor = token.value
    token.value = newColor
    await _saveColors()

    const localUpdates = buildColorPropagationUpdates(currentItems.value, tokenId, newColor)
    if (localUpdates.length) {
      for (const upd of localUpdates) {
        const item = currentItems.value.find(i => i.id === upd.id)
        if (!item) continue
        if (upd.style_data) item.style_data = upd.style_data
        if (upd.color) item.color = upd.color
      }
    }

    try {
      await gsApi.propagateColorByToken(currentBoardId.value, tokenId, newColor)
    } catch (e) {
      console.error('[GlobalStyles] propagateColor error:', e)
    }
  }

  async function renameGlobalColor(tokenId, newName) {
    const token = globalColors.value.find(t => t.id === tokenId)
    if (!token) return
    token.name = newName
    await _saveColors()
  }

  // ── Global Text Style CRUD ──

  async function addGlobalTextStyle(style) {
    globalTextStyles.value = [...globalTextStyles.value, style]
    await _saveTextStyles()
  }

  async function removeGlobalTextStyle(styleId) {
    globalTextStyles.value = globalTextStyles.value.filter(s => s.id !== styleId)
    await _saveTextStyles()
  }

  async function updateGlobalTextStyle(styleId, newProps) {
    const style = globalTextStyles.value.find(s => s.id === styleId)
    if (!style || !currentBoardId.value) return

    Object.assign(style, newProps)
    await _saveTextStyles()

    const localUpdates = buildTextStylePropagationUpdates(currentItems.value, styleId, style)
    if (localUpdates.length) {
      for (const upd of localUpdates) {
        const item = currentItems.value.find(i => i.id === upd.id)
        if (item && upd.style_data) item.style_data = upd.style_data
      }
    }

    try {
      await gsApi.propagateTextStyle(currentBoardId.value, styleId, style)
    } catch (e) {
      console.error('[GlobalStyles] propagateTextStyle error:', e)
    }
  }

  async function renameGlobalTextStyle(styleId, newName) {
    const style = globalTextStyles.value.find(s => s.id === styleId)
    if (!style) return
    style.name = newName
    await _saveTextStyles()
  }

  // ── Global Gradient CRUD ──

  async function addGlobalGradient(gradient) {
    globalGradients.value = [...globalGradients.value, gradient]
    await _saveGradients()
  }

  async function removeGlobalGradient(gradientId) {
    globalGradients.value = globalGradients.value.filter(g => g.id !== gradientId)
    await _saveGradients()
  }

  async function updateGlobalGradient(gradientId, newProps) {
    const grad = globalGradients.value.find(g => g.id === gradientId)
    if (!grad || !currentBoardId.value) return

    Object.assign(grad, newProps)
    await _saveGradients()

    const localUpdates = buildGradientPropagationUpdates(currentItems.value, gradientId, grad)
    for (const upd of localUpdates) {
      const item = currentItems.value.find(i => i.id === upd.id)
      if (item && upd.style_data) item.style_data = upd.style_data
    }
    if (localUpdates.length) moodStore().batchUpdateItems(localUpdates)
  }

  // ── Global CSS Class CRUD ──

  async function addGlobalCssClass(cls) {
    globalCssClasses.value = [...globalCssClasses.value, cls]
    await _saveCssClasses()
  }

  async function removeGlobalCssClass(classId) {
    globalCssClasses.value = globalCssClasses.value.filter(c => c.id !== classId)
    await _saveCssClasses()
  }

  async function updateGlobalCssClass(classId, newProps) {
    const cls = globalCssClasses.value.find(c => c.id === classId)
    if (!cls || !currentBoardId.value) return
    Object.assign(cls, newProps)
    await _saveCssClasses()
  }

  async function renameGlobalCssClass(classId, newName) {
    const cls = globalCssClasses.value.find(c => c.id === classId)
    if (!cls) return
    cls.name = newName
    await _saveCssClasses()
  }

  function getGlobalCssClassByName(name) {
    if (!name) return null
    const lower = name.toLowerCase().trim()
    return globalCssClasses.value.find(c => c.name?.toLowerCase().trim() === lower) || null
  }

  function resolveClassOverrides(item) {
    if (!item?.title) return {}
    const names = item.title.split(',').map(s => s.trim()).filter(Boolean)
    if (names.length <= 1) return {}
    const overrides = {}
    for (let i = 1; i < names.length; i++) {
      const cls = getGlobalCssClassByName(names[i])
      if (cls?.properties) Object.assign(overrides, cls.properties)
    }
    return overrides
  }

  // ── Apply / Link / Unlink helpers ──

  function applyColorToItems(tokenId, itemIds) {
    const token = globalColors.value.find(t => t.id === tokenId)
    if (!token) return

    const updates = []
    for (const id of itemIds) {
      const item = currentItems.value.find(i => i.id === id)
      if (!item) continue

      let styleKey = 'background_color'
      if (item.type === 'shape' || item.type === 'pen_shape') styleKey = 'shape_fill'
      else if (item.type === 'text') styleKey = 'text_color'
      else if (item.type === 'line') styleKey = 'line_color'
      else if (item.type === 'frame') styleKey = 'fill_color'

      if (item.type === 'note' || item.type === 'color_swatch') {
        const sd = linkColorToItem(item.style_data, '_item_color', token)
        updates.push({ id, color: token.value, style_data: sd })
      } else {
        const sd = linkColorToItem(item.style_data, styleKey, token)
        updates.push({ id, style_data: sd })
      }
    }
    if (updates.length) moodStore().batchUpdateItems(updates)
  }

  function applyTextStyleToItems(styleId, itemIds) {
    const style = globalTextStyles.value.find(s => s.id === styleId)
    if (!style) return

    const updates = []
    for (const id of itemIds) {
      const item = currentItems.value.find(i => i.id === id)
      if (!item) continue

      const isShapeText = item.type === 'shape' || item.type === 'pen_shape'
      const sd = linkTextStyleToItem(item.style_data, style, isShapeText)
      updates.push({ id, style_data: sd })
    }
    if (updates.length) moodStore().batchUpdateItems(updates)
  }

  function applyGradientToItems(gradientId, itemIds) {
    const grad = globalGradients.value.find(g => g.id === gradientId)
    if (!grad) return

    const updates = []
    for (const id of itemIds) {
      const item = currentItems.value.find(i => i.id === id)
      if (!item) continue
      const sd = linkGradientToItem(item.style_data, grad)
      updates.push({ id, style_data: sd })
    }
    if (updates.length) moodStore().batchUpdateItems(updates)
  }

  function unlinkGlobal(itemId, styleKey) {
    const item = currentItems.value.find(i => i.id === itemId)
    if (!item) return
    const sd = unlinkFromGlobal(item.style_data, styleKey)
    moodStore().updateItem(itemId, { style_data: sd })
  }

  // ── Usage queries ──

  function getUsageCount(globalId) {
    return countGlobalUsage(currentItems.value, globalId)
  }

  function getItemsUsing(globalId) {
    return findItemsUsingGlobal(currentItems.value, globalId)
  }

  function getItemGlobals(itemId) {
    const item = currentItems.value.find(i => i.id === itemId)
    return item ? getGlobalsMap(item) : {}
  }

  // ── Template globals auto-registration ──

  async function ensureTemplateGlobals(colorTokens) {
    if (!colorTokens?.length || !currentBoardId.value) return
    const existingIds = new Set(globalColors.value.map(c => c.id))
    const existingHexes = new Set(globalColors.value.map(c => c.value?.toLowerCase()))
    const toAdd = colorTokens.filter(c =>
      !existingIds.has(c.id) && !existingHexes.has(c.value?.toLowerCase())
    )
    if (!toAdd.length) return
    globalColors.value = [...globalColors.value, ...toAdd]
    await _saveColors()
  }

  // ── Migration: plain hex palette → global tokens ──

  function migrateFromColorPalette(hexArray) {
    if (!hexArray?.length) return
    const existingHexes = new Set(globalColors.value.map(t => t.value.toLowerCase()))
    const toAdd = hexArray
      .filter(hex => !existingHexes.has(hex.toLowerCase()))
      .map((hex, i) => ({
        id: `gc-${Date.now()}-${i}`,
        name: hex.toUpperCase(),
        value: hex,
      }))
    if (!toAdd.length) return
    globalColors.value = [...globalColors.value, ...toAdd]
    _saveColors()
  }

  // ── Auto-extract colors from board ──

  function extractColorsFromBoard() {
    const existing = new Set(globalColors.value.map(t => t.value.toLowerCase()))
    const colorSet = new Map()

    for (const item of currentItems.value) {
      if (item.color && !existing.has(item.color.toLowerCase())) {
        colorSet.set(item.color.toLowerCase(), item.color)
      }
      const sd = item.style_data
      if (!sd) continue
      for (const field of ['shape_fill', 'text_color', 'shape_border_color', 'fill_color', 'line_color']) {
        if (sd[field] && !existing.has(sd[field].toLowerCase())) {
          colorSet.set(sd[field].toLowerCase(), sd[field])
        }
      }
    }

    if (!colorSet.size) return

    const newTokens = [...colorSet.entries()].slice(0, 20).map(([, original], i) => ({
      id: `gc-${Date.now()}-${i}`,
      name: `Color ${globalColors.value.length + i + 1}`,
      value: original,
    }))

    globalColors.value = [...globalColors.value, ...newTokens]
    _saveColors()
  }

  // ── Private helpers ──

  async function _saveColors() {
    if (!currentBoardId.value) return
    try {
      await gsApi.saveGlobalColors(currentBoardId.value, globalColors.value)
    } catch (e) {
      console.error('[GlobalStyles] saveColors error:', e)
    }
  }

  async function _saveTextStyles() {
    if (!currentBoardId.value) return
    try {
      await gsApi.saveGlobalTextStyles(currentBoardId.value, globalTextStyles.value)
    } catch (e) {
      console.error('[GlobalStyles] saveTextStyles error:', e)
    }
  }

  async function _saveGradients() {
    if (!currentBoardId.value) return
    try {
      await moodStore().saveGradientPalette(globalGradients.value)
    } catch (e) {
      console.error('[GlobalStyles] saveGradients error:', e)
    }
  }

  async function _saveCssClasses() {
    if (!currentBoardId.value) return
    try {
      await gsApi.saveGlobalCssClasses(currentBoardId.value, globalCssClasses.value)
    } catch (e) {
      console.error('[GlobalStyles] saveCssClasses error:', e)
    }
  }

  function $reset() {
    globalColors.value = []
    globalGradients.value = []
    globalTextStyles.value = []
    globalCssClasses.value = []
    loading.value = false
  }

  return {
    // State
    globalColors,
    globalGradients,
    globalTextStyles,
    globalCssClasses,
    loading,

    // Fetch
    fetchAll,
    hydrateFromBoard,

    // Color CRUD
    addGlobalColor,
    removeGlobalColor,
    updateGlobalColorValue,
    renameGlobalColor,

    // Gradient CRUD
    addGlobalGradient,
    removeGlobalGradient,
    updateGlobalGradient,

    // Text Style CRUD
    addGlobalTextStyle,
    removeGlobalTextStyle,
    updateGlobalTextStyle,
    renameGlobalTextStyle,

    // CSS Class CRUD
    addGlobalCssClass,
    removeGlobalCssClass,
    updateGlobalCssClass,
    renameGlobalCssClass,
    getGlobalCssClassByName,
    resolveClassOverrides,

    // Apply / Link
    applyColorToItems,
    applyGradientToItems,
    applyTextStyleToItems,
    unlinkGlobal,

    // Usage
    getUsageCount,
    getItemsUsing,
    getItemGlobals,

    // Extract / Migrate
    extractColorsFromBoard,
    migrateFromColorPalette,
    ensureTemplateGlobals,

    $reset,
  }
})
