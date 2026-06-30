<script setup>
/**
 * SmartViewModal — create / edit a Smart View (saved search).
 *
 * Modes (driven by the `view` prop):
 *   - view = null               → create mode. Prefilled from the current
 *                                  emailSearch state (so the user can press
 *                                  "+" with active filters and save them).
 *   - view = { id, … }          → edit mode. PUTs back on save.
 *
 * What the user picks:
 *   - name
 *   - icon (Material Symbols name, picked from a curated palette)
 *   - color (named palette — matches the labels palette for consistency)
 *   - query (canonical search-syntax string — editable, syntax-validated by
 *           the backend on save via the AST parser)
 *
 * We do NOT expose `filters_json` for hand-edit in this modal — it travels
 * silently with the structured filters from emailSearch on first save. Later
 * edits keep the JSON we stored (the AST parser canonicalises the query
 * string round-trip on every save so display stays consistent).
 */
import { ref, watch, computed } from 'vue'
import Modal from '@/components/shared/Modal.vue'
import { useSmartViewsStore } from '@/stores/smartViews'
import { useToastStore } from '@/stores/toast'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  show: { type: Boolean, default: false },
  view: { type: Object, default: null }, // null = create
})
const emit = defineEmits(['close', 'saved'])

const { t } = useI18n()
const smartViews = useSmartViewsStore()
const toast = useToastStore()

// Curated icon palette — keeps the UI consistent and prevents the user from
// typing in random Material Symbols names that may not exist.
const ICON_PALETTE = [
  'filter_alt', 'mark_email_unread', 'star', 'attach_file', 'alternate_email',
  'flag', 'inventory_2', 'campaign', 'forum', 'group', 'business',
  'shopping_cart', 'receipt_long', 'event', 'task_alt', 'priority_high',
  'snooze', 'bolt', 'travel_explore', 'newspaper',
]

// Same palette as labels (see LabelService::COLORS) for visual consistency.
const COLOR_PALETTE = [
  'primary', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald',
  'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia',
  'pink', 'rose',
]

const form = ref({
  name: '',
  icon: 'filter_alt',
  color: 'primary',
  query: '',
  scope: 'all',
})
const filtersSnapshot = ref(null)
const saving = ref(false)
const nameRef = ref(null)

const isEdit = computed(() => !!props.view?.id && !props.view?.builtin)
const title = computed(() => isEdit.value ? t('smartViews.editTitle') : t('smartViews.createTitle'))

watch(() => props.show, async (open) => {
  if (!open) return
  if (isEdit.value) {
    form.value = {
      name:  props.view.name || '',
      icon:  props.view.icon || 'filter_alt',
      color: props.view.color || 'primary',
      query: props.view.query || '',
      scope: props.view.scope || 'all',
    }
    filtersSnapshot.value = props.view.filters_json || null
  } else {
    // CREATE — prefill from current search state.
    const draft = smartViews.draftFromCurrentSearch()
    form.value = {
      name:  draft.name,
      icon:  draft.icon,
      color: draft.color,
      query: draft.query,
      scope: draft.scope,
    }
    filtersSnapshot.value = draft.filters_json
  }
  // Focus name on next tick. setTimeout so the Transition's enter has settled
  // and the input is actually in the DOM and visible.
  setTimeout(() => nameRef.value?.focus(), 50)
})

function colorChipClass(c) {
  return `bg-${c}-500`
}

async function onSave() {
  const name = form.value.name.trim()
  if (!name) {
    toast.error(t('smartViews.nameRequired'))
    nameRef.value?.focus()
    return
  }
  const query = form.value.query.trim()
  if (!query && !filtersSnapshot.value) {
    toast.error(t('smartViews.queryRequired'))
    return
  }

  saving.value = true
  try {
    const payload = {
      name,
      icon: form.value.icon,
      color: form.value.color,
      query,
      filters_json: filtersSnapshot.value || {},
      scope: form.value.scope,
    }
    let saved
    if (isEdit.value) {
      saved = await smartViews.update(props.view.id, payload)
      if (saved) toast.success(t('smartViews.updated'))
    } else {
      saved = await smartViews.create(payload)
      if (saved) toast.success(t('smartViews.created'))
    }
    if (saved) {
      emit('saved', saved)
      emit('close')
    }
  } catch (e) {
    toast.error(e?.response?.data?.message || t('smartViews.saveFailed'))
  } finally {
    saving.value = false
  }
}

async function onDelete() {
  if (!isEdit.value) return
  if (!window.confirm(t('smartViews.confirmDelete', { name: props.view.name }))) return
  saving.value = true
  const ok = await smartViews.remove(props.view.id)
  saving.value = false
  if (ok) {
    toast.success(t('smartViews.deleted'))
    emit('close')
  } else {
    toast.error(t('smartViews.saveFailed'))
  }
}
</script>

<template>
  <Modal :show="show" :title="title" size="md" @close="emit('close')">
    <div class="space-y-4">
      <!-- Name -->
      <div>
        <label class="block text-sm font-medium mb-1.5">{{ t('smartViews.nameLabel') }}</label>
        <input
          ref="nameRef"
          v-model="form.name"
          type="text"
          class="input w-full"
          maxlength="64"
          :placeholder="t('smartViews.namePlaceholder')"
          @keyup.enter="onSave"
        />
      </div>

      <!-- Icon picker -->
      <div>
        <label class="block text-sm font-medium mb-1.5">{{ t('smartViews.iconLabel') }}</label>
        <div class="grid grid-cols-10 gap-1.5">
          <button
            v-for="icon in ICON_PALETTE"
            :key="icon"
            type="button"
            @click="form.icon = icon"
            :class="[
              'h-9 w-9 flex items-center justify-center rounded-lg border transition-colors',
              form.icon === icon
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/20 text-primary-600'
                : 'border-surface-200 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-800'
            ]"
            :title="icon"
          >
            <span class="material-symbols-rounded text-lg">{{ icon }}</span>
          </button>
        </div>
      </div>

      <!-- Color picker -->
      <div>
        <label class="block text-sm font-medium mb-1.5">{{ t('smartViews.colorLabel') }}</label>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="c in COLOR_PALETTE"
            :key="c"
            type="button"
            @click="form.color = c"
            :class="[
              'h-7 w-7 rounded-full border-2 transition-transform',
              colorChipClass(c),
              form.color === c
                ? 'border-surface-900 dark:border-white scale-110'
                : 'border-transparent hover:scale-105'
            ]"
            :title="c"
          />
        </div>
      </div>

      <!-- Query -->
      <div>
        <label class="block text-sm font-medium mb-1.5">{{ t('smartViews.queryLabel') }}</label>
        <textarea
          v-model="form.query"
          class="input w-full font-mono text-sm"
          rows="3"
          maxlength="2048"
          :placeholder="t('smartViews.queryPlaceholder')"
        />
        <p class="text-xs text-surface-500 mt-1">
          {{ t('smartViews.queryHint') }}
        </p>
      </div>

      <!-- Scope -->
      <div>
        <label class="block text-sm font-medium mb-1.5">{{ t('smartViews.scopeLabel') }}</label>
        <select v-model="form.scope" class="input w-full">
          <option value="all">{{ t('smartViews.scopeAll') }}</option>
          <option value="folder">{{ t('smartViews.scopeFolder') }}</option>
          <option value="accounts">{{ t('smartViews.scopeAccounts') }}</option>
        </select>
      </div>
    </div>

    <template #footer>
      <div class="flex justify-between items-center w-full">
        <button
          v-if="isEdit"
          type="button"
          class="btn-ghost text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
          :disabled="saving"
          @click="onDelete"
        >
          <span class="material-symbols-rounded text-lg">delete</span>
          {{ t('smartViews.delete') }}
        </button>
        <div v-else />
        <div class="flex gap-2">
          <button type="button" class="btn-ghost" :disabled="saving" @click="emit('close')">
            {{ t('smartViews.cancel') }}
          </button>
          <button type="button" class="btn-primary" :disabled="saving" @click="onSave">
            <span v-if="saving" class="spinner"></span>
            {{ isEdit ? t('smartViews.save') : t('smartViews.create') }}
          </button>
        </div>
      </div>
    </template>
  </Modal>
</template>
