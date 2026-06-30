<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import aiHelper from '@/services/aiHelper'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue', 'command-ready'])

const command = ref('')
const output = ref([])
const error = ref([])
const executing = ref(false)
const commandHistory = ref([])
const historyIndex = ref(-1)
const cwd = ref('/')

const executeCommand = async () => {
  if (!command.value.trim() || executing.value) return

  const cmd = command.value.trim()
  commandHistory.value.push(cmd)
  historyIndex.value = commandHistory.value.length

  executing.value = true
  output.value = []
  error.value = []

  try {
    const result = await aiHelper.dryRunCommand(cmd, cwd.value)
    
    if (result.output) {
      output.value = result.output
    }
    if (result.error && result.error.length > 0) {
      error.value = result.error
    }
  } catch (e) {
    error.value = [e.message || 'Command execution failed']
  } finally {
    executing.value = false
  }
}

const handleKeyDown = (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    executeCommand()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    if (historyIndex.value > 0) {
      historyIndex.value--
      command.value = commandHistory.value[historyIndex.value]
    }
  } else if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (historyIndex.value < commandHistory.value.length - 1) {
      historyIndex.value++
      command.value = commandHistory.value[historyIndex.value]
    } else {
      historyIndex.value = commandHistory.value.length
      command.value = ''
    }
  }
}

const copyCommand = async () => {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(command.value)
    } else {
      // Fallback for non-HTTPS or older browsers
      const textArea = document.createElement('textarea')
      textArea.value = command.value
      textArea.style.position = 'fixed'
      textArea.style.left = '-9999px'
      document.body.appendChild(textArea)
      textArea.select()
      document.execCommand('copy')
      document.body.removeChild(textArea)
    }
  } catch (e) {
    console.error('Failed to copy', e)
  }
  emit('command-ready', command.value)
}

const close = () => {
  emit('update:modelValue', false)
}
</script>

<template>
  <Teleport to="body">
    <transition name="modal">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click.self="close"
      >
        <div class="fixed inset-0 bg-black/50" @click="close"></div>
        <div class="relative bg-surface-900 rounded-lg shadow-xl w-full max-w-3xl max-h-[70vh] overflow-hidden flex flex-col">
          <!-- Header -->
          <div class="flex items-center justify-between px-3 py-2 border-b border-surface-700">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-green-400 text-base">terminal</span>
              <h3 class="text-sm font-semibold text-white">Dry-Run Terminal</h3>
              <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-500/20 text-amber-400 border border-amber-500/30">
                READ-ONLY
              </span>
            </div>
            <button
              @click="close"
              class="p-1.5 hover:bg-surface-800 rounded transition-colors"
            >
              <span class="material-symbols-rounded text-surface-400 text-sm">close</span>
            </button>
          </div>

          <!-- Terminal Output -->
          <div class="flex-1 p-3 overflow-y-auto font-mono text-xs bg-surface-950">
            <div v-if="output.length === 0 && error.length === 0 && !executing" class="text-surface-500">
              <div>Dry-run terminal - Only read-only commands allowed</div>
              <div class="mt-1 text-[10px]">Allowed: grep, cat, ls, tail, head, find, stat, etc.</div>
            </div>
            
            <div v-if="executing" class="text-surface-400">
              <span class="inline-block animate-pulse">Executing...</span>
            </div>

            <div v-if="output.length > 0" class="text-green-400 space-y-0.5">
              <div v-for="(line, index) in output" :key="index">{{ line }}</div>
            </div>

            <div v-if="error.length > 0" class="text-red-400 space-y-0.5 mt-2">
              <div v-for="(line, index) in error" :key="index">{{ line }}</div>
            </div>
          </div>

          <!-- Command Input -->
          <div class="px-3 py-2 border-t border-surface-700 bg-surface-900">
            <div class="flex items-center gap-1.5">
              <span class="text-surface-400 font-mono text-xs">$</span>
              <input
                v-model="command"
                @keydown="handleKeyDown"
                type="text"
                :disabled="executing"
                placeholder="Enter command..."
                class="flex-1 bg-surface-950 border border-surface-700 rounded px-2 py-1.5 text-white font-mono text-xs focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-transparent disabled:opacity-50"
              />
              <button
                @click="copyCommand"
                class="p-1.5 rounded bg-primary-500 hover:bg-primary-600 text-white transition-colors"
                title="Copy command"
              >
                <span class="material-symbols-rounded text-xs">content_copy</span>
              </button>
              <button
                @click="executeCommand"
                :disabled="executing || !command.trim()"
                class="p-1.5 rounded bg-green-600 hover:bg-green-700 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span class="material-symbols-rounded text-xs">play_arrow</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>

