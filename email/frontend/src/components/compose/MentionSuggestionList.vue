<template>
  <div
    class="mention-suggestion-list"
    @mousedown.prevent
  >
    <template v-if="items.length">
      <button
        v-for="(item, index) in items"
        :key="item.email"
        type="button"
        :class="[
          'mention-suggestion-item',
          { 'mention-suggestion-item--active': index === selectedIndex }
        ]"
        @click="selectItem(index)"
        @mouseenter="selectedIndex = index"
      >
        <span class="mention-suggestion-avatar" :style="{ background: avatarColor(item.email) }">
          {{ initials(item) }}
        </span>
        <span class="mention-suggestion-text">
          <span class="mention-suggestion-name">
            {{ item.name || item.email }}
            <span
              v-if="item.is_colleague"
              class="mention-suggestion-badge"
              title="From your domain"
            >colleague</span>
          </span>
          <span class="mention-suggestion-email">{{ item.email }}</span>
        </span>
      </button>
    </template>
    <div v-else class="mention-suggestion-empty">
      No matches
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue'

/**
 * Suggestion popup for the TipTap @mention extension.
 *
 * Props (provided by TipTap suggestion plugin):
 *   items   — array from MentionExtension.suggestion.items()
 *   command — function the extension expects us to call with the chosen
 *             item; it inserts the mention node.
 *
 * Public method `onKeyDown(props)` is consumed by the extension's render
 * hook to drive arrow / enter selection without focus-stealing.
 */
const props = defineProps({
  items: { type: Array, required: true },
  command: { type: Function, required: true },
})

const selectedIndex = ref(0)

watch(() => props.items, () => {
  selectedIndex.value = 0
})

function selectItem(index) {
  const item = props.items[index]
  if (!item) return
  props.command({
    email: item.email,
    name: item.name || item.email,
  })
}

function onKeyDown({ event }) {
  if (!props.items?.length) return false
  if (event.key === 'ArrowDown') {
    selectedIndex.value = (selectedIndex.value + 1) % props.items.length
    return true
  }
  if (event.key === 'ArrowUp') {
    selectedIndex.value = (selectedIndex.value - 1 + props.items.length) % props.items.length
    return true
  }
  if (event.key === 'Enter' || event.key === 'Tab') {
    selectItem(selectedIndex.value)
    return true
  }
  return false
}

function initials(item) {
  const src = item.name || item.email || ''
  const parts = src.split(/[\s.@]+/).filter(Boolean).slice(0, 2)
  if (!parts.length) return '?'
  return parts.map((p) => p[0]?.toUpperCase() ?? '').join('') || '?'
}

const PALETTE = [
  '#0ea5e9', '#10b981', '#f59e0b', '#ef4444',
  '#8b5cf6', '#ec4899', '#14b8a6', '#f97316',
]
function avatarColor(email) {
  if (!email) return PALETTE[0]
  let h = 0
  for (let i = 0; i < email.length; i++) h = (h * 31 + email.charCodeAt(i)) >>> 0
  return PALETTE[h % PALETTE.length]
}

defineExpose({ onKeyDown })
</script>

<style scoped>
.mention-suggestion-list {
  background: var(--ui-surface, #ffffff);
  border: 1px solid var(--ui-border, rgba(0,0,0,0.1));
  border-radius: 10px;
  box-shadow: 0 12px 32px rgba(0,0,0,0.18);
  padding: 4px;
  min-width: 240px;
  max-width: 340px;
  max-height: 280px;
  overflow-y: auto;
}
.dark .mention-suggestion-list {
  background: #1f2937;
  border-color: rgba(255,255,255,0.08);
}

.mention-suggestion-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 10px;
  border: none;
  background: transparent;
  cursor: pointer;
  border-radius: 6px;
  text-align: left;
  font: inherit;
  color: inherit;
}
.mention-suggestion-item:hover,
.mention-suggestion-item--active {
  background: rgba(14, 165, 233, 0.10);
}
.dark .mention-suggestion-item:hover,
.dark .mention-suggestion-item--active {
  background: rgba(14, 165, 233, 0.18);
}

.mention-suggestion-avatar {
  flex: 0 0 auto;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 600;
  color: #fff;
  letter-spacing: 0.02em;
}
.mention-suggestion-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.mention-suggestion-name {
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: flex;
  align-items: center;
  gap: 6px;
}
.mention-suggestion-email {
  font-size: 11.5px;
  color: rgba(0,0,0,0.55);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dark .mention-suggestion-email {
  color: rgba(255,255,255,0.55);
}
.mention-suggestion-badge {
  font-size: 9.5px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  font-weight: 600;
  background: rgba(14, 165, 233, 0.15);
  color: #0284c7;
  padding: 2px 6px;
  border-radius: 999px;
}
.dark .mention-suggestion-badge {
  background: rgba(14, 165, 233, 0.22);
  color: #7dd3fc;
}
.mention-suggestion-empty {
  padding: 10px 12px;
  font-size: 12.5px;
  color: rgba(0,0,0,0.55);
}
.dark .mention-suggestion-empty {
  color: rgba(255,255,255,0.55);
}
</style>
