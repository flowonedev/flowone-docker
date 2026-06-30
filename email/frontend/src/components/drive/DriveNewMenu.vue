<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  disabled: { type: Boolean, default: false },
  officeEnabled: { type: Boolean, default: false },
  // Toolbar-style trigger (smaller, auto width) for the file-manager header
  compact: { type: Boolean, default: false },
})

const emit = defineEmits([
  'new-folder',
  'new-office',
  'upload-files',
  'upload-folder',
])

const open = ref(false)
const containerRef = ref(null)

function toggle() {
  if (props.disabled) return
  open.value = !open.value
}

function close() {
  open.value = false
}

function pick(eventName) {
  emit(eventName)
  close()
}

function pickOffice(type) {
  emit('new-office', type)
  close()
}

function onDocumentClick(e) {
  if (!open.value) return
  if (containerRef.value && !containerRef.value.contains(e.target)) close()
}

function onKey(e) {
  if (e.key === 'Escape') close()
}

onMounted(() => {
  document.addEventListener('mousedown', onDocumentClick)
  document.addEventListener('keydown', onKey)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocumentClick)
  document.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div ref="containerRef" class="relative">
    <button
      type="button"
      :disabled="disabled"
      @click="toggle"
      :class="compact
        ? 'h-8 px-3 inline-flex items-center gap-1.5 rounded-md text-[13px] font-medium border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800/60 text-surface-700 dark:text-surface-200 hover:bg-surface-50 dark:hover:bg-surface-700/60 transition-colors flex-shrink-0 disabled:opacity-50 disabled:cursor-not-allowed'
        : 'w-full h-12 px-4 flex items-center justify-center gap-2 rounded-full bg-white dark:bg-surface-800 border border-primary-300 dark:border-primary-700 text-primary-700 dark:text-primary-300 font-medium shadow-sm hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-colors disabled:opacity-50 disabled:cursor-not-allowed'"
    >
      <span class="material-symbols-rounded" :class="compact ? 'text-base' : 'text-xl'">{{ compact ? 'add_circle' : 'add' }}</span>
      <span>{{ $t('driveView.new') }}</span>
      <span v-if="compact" class="material-symbols-rounded text-base opacity-70 -ml-1">arrow_drop_down</span>
    </button>

    <Transition
      enter-active-class="transition ease-out duration-150"
      leave-active-class="transition ease-in duration-100"
      enter-from-class="opacity-0 -translate-y-1"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <div
        v-if="open"
        class="absolute left-0 top-full mt-2 z-30 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-lg overflow-hidden"
        :class="compact ? 'w-64' : 'right-0'"
      >
        <button
          @click="pick('new-folder')"
          class="w-full px-3 py-2.5 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
        >
          <span class="material-symbols-rounded text-lg text-surface-500">create_new_folder</span>
          {{ $t('driveView.newFolder') }}
        </button>

        <template v-if="officeEnabled">
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>

          <button
            @click="pickOffice('docx')"
            class="w-full px-3 py-2.5 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-lg text-blue-500">description</span>
            {{ $t('officeEditor.newWordDocument') }}
          </button>
          <button
            @click="pickOffice('xlsx')"
            class="w-full px-3 py-2.5 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-lg text-green-600">table</span>
            {{ $t('officeEditor.newSpreadsheet') }}
          </button>
          <button
            @click="pickOffice('pptx')"
            class="w-full px-3 py-2.5 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
          >
            <span class="material-symbols-rounded text-lg text-orange-500">co_present</span>
            {{ $t('officeEditor.newOfficePresentation') }}
          </button>
        </template>

        <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>

        <button
          @click="pick('upload-files')"
          class="w-full px-3 py-2.5 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
        >
          <span class="material-symbols-rounded text-lg text-surface-500">upload_file</span>
          {{ $t('driveView.uploadFiles') }}
        </button>
        <button
          @click="pick('upload-folder')"
          class="w-full px-3 py-2.5 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200"
        >
          <span class="material-symbols-rounded text-lg text-surface-500">drive_folder_upload</span>
          {{ $t('driveView.uploadFolder') }}
        </button>
      </div>
    </Transition>
  </div>
</template>
