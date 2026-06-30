<template>
  <teleport to="body">
    <div
      v-if="visible"
      class="fixed z-[10001]"
      :style="positionStyle"
      @click.stop
    >
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl border border-surface-200 dark:border-surface-700 w-[300px] overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-surface-100 dark:border-surface-700">
          <span class="text-xs font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-1.5">
            <span class="material-symbols-rounded text-sm text-primary-500">add_comment</span>
            Add Comment
          </span>
          <button
            @click="$emit('cancel')"
            class="p-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400"
          >
            <span class="material-symbols-rounded text-base">close</span>
          </button>
        </div>

        <!-- Guest name (public only, one-time) -->
        <div v-if="isPublic && !storedGuestName" class="px-4 pt-3">
          <input
            v-model="guestNameInput"
            type="text"
            placeholder="Your name..."
            class="w-full px-3 py-2 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none focus:ring-1 focus:ring-primary-500"
            @keydown.enter="storedGuestName = guestNameInput.trim()"
          />
        </div>

        <!-- Comment input -->
        <div class="px-4 py-3">
          <textarea
            ref="textareaRef"
            v-model="commentText"
            rows="3"
            :placeholder="isPublic && !storedGuestName ? 'Enter your name first...' : 'Write a comment...'"
            :disabled="isPublic && !storedGuestName"
            class="w-full px-3 py-2 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 resize-none outline-none focus:ring-1 focus:ring-primary-500 disabled:opacity-50"
            @keydown.ctrl.enter="submit"
            @keydown.meta.enter="submit"
            @keydown.escape="$emit('cancel')"
          />
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-between px-4 py-2.5 border-t border-surface-100 dark:border-surface-700 bg-surface-50/50 dark:bg-surface-700/30">
          <span class="text-[10px] text-surface-400">Ctrl+Enter to send</span>
          <div class="flex gap-2">
            <button
              @click="$emit('cancel')"
              class="px-3 py-1.5 rounded-full text-xs font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              Cancel
            </button>
            <button
              @click="submit"
              :disabled="!commentText.trim() || (isPublic && !storedGuestName)"
              class="px-4 py-1.5 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none flex items-center gap-1"
            >
              <span class="material-symbols-rounded text-sm">send</span>
              Comment
            </button>
          </div>
        </div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
  itemId: { type: Number, default: null },
  pinX: { type: Number, default: null },
  pinY: { type: Number, default: null },
  isPublic: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'cancel'])

const commentText = ref('')
const textareaRef = ref(null)
const guestNameInput = ref('')
const storedGuestName = ref(localStorage.getItem('mood_comment_guest_name') || '')

watch(() => props.visible, (val) => {
  if (val) {
    commentText.value = ''
    nextTick(() => {
      if (storedGuestName.value || !props.isPublic) {
        textareaRef.value?.focus()
      }
    })
  }
})

watch(storedGuestName, (val) => {
  if (val) {
    localStorage.setItem('mood_comment_guest_name', val)
    nextTick(() => textareaRef.value?.focus())
  }
})

const positionStyle = computed(() => {
  const maxX = window.innerWidth - 320
  const maxY = window.innerHeight - 280
  return {
    left: Math.min(props.x, maxX) + 'px',
    top: Math.min(props.y, maxY) + 'px',
  }
})

function submit() {
  const text = commentText.value.trim()
  if (!text) return
  if (props.isPublic && !storedGuestName.value) return

  const data = {
    content: text,
    item_id: props.itemId,
    author_name: props.isPublic ? storedGuestName.value : undefined,
  }
  if (props.pinX != null && props.pinY != null) {
    data.pin_x = props.pinX
    data.pin_y = props.pinY
  }
  emit('submit', data)
  commentText.value = ''
}
</script>
