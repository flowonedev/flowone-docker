<script setup>
import { ref, computed } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  defaultCategoryId: { type: [Number, null], default: null },
})

const emit = defineEmits(['close', 'created', 'back'])

const chatStore = useChatStore()
const toast = useToastStore()

const name = ref('')
const topic = ref('')
const purpose = ref('')
const isPublic = ref(true)
const isDefault = ref(false)
const creating = ref(false)
const selectedCategoryId = ref(props.defaultCategoryId)

const availableCategories = computed(() => chatStore.categories || [])

function slugPreview() {
  return name.value
    .toLowerCase()
    .replace(/[^a-z0-9\-]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
}

async function handleCreate() {
  if (!name.value.trim()) {
    toast.error('Channel name is required')
    return
  }

  creating.value = true
  try {
    const result = await chatStore.createChannel({
      name: name.value.trim(),
      is_public: isPublic.value,
      topic: topic.value.trim() || null,
      purpose: purpose.value.trim() || null,
      is_default: isDefault.value,
      category_id: selectedCategoryId.value || null,
    })

    if (result.success) {
      toast.success('Channel created')
      emit('created', result.channel)
    } else {
      toast.error(result.error || 'Failed to create channel')
    }
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[10000] flex items-center justify-center">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/40" @click="emit('close')"></div>

      <!-- Modal -->
      <div class="relative w-full max-w-lg mx-4 bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl shadow-2xl overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between p-5 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
          <div class="flex items-center gap-2">
            <button
              @click="emit('back')"
              class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              title="Back"
            >
              <span class="material-symbols-rounded text-surface-500">arrow_back</span>
            </button>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Create Channel</h2>
          </div>
          <button
            @click="emit('close')"
            class="w-9 h-9 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-xl text-surface-500">close</span>
          </button>
        </div>

        <!-- Body -->
        <div class="p-5 space-y-5">
          <!-- Name -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">Channel Name</label>
            <input
              v-model="name"
              type="text"
              maxlength="100"
              placeholder="e.g. project-updates"
              class="w-full px-4 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
            <p v-if="name" class="text-xs text-surface-400 mt-1">
              Slug: <span class="font-mono">#{{ slugPreview() }}</span>
            </p>
          </div>

          <!-- Visibility toggle -->
          <div class="flex items-center justify-between">
            <div>
              <span class="text-sm font-medium text-surface-700 dark:text-surface-300">Public Channel</span>
              <p class="text-xs text-surface-500 mt-0.5">Anyone in your organization can find and join</p>
            </div>
            <button
              @click="isPublic = !isPublic"
              :class="[
                'relative w-11 h-6 rounded-full transition-colors',
                isPublic ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
              ]"
            >
              <span
                :class="[
                  'absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform',
                  isPublic ? 'left-[22px]' : 'left-0.5'
                ]"
              ></span>
            </button>
          </div>

          <!-- Default toggle -->
          <div class="flex items-center justify-between">
            <div>
              <span class="text-sm font-medium text-surface-700 dark:text-surface-300">Default Channel</span>
              <p class="text-xs text-surface-500 mt-0.5">New team members auto-join this channel</p>
            </div>
            <button
              @click="isDefault = !isDefault"
              :class="[
                'relative w-11 h-6 rounded-full transition-colors',
                isDefault ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
              ]"
            >
              <span
                :class="[
                  'absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform',
                  isDefault ? 'left-[22px]' : 'left-0.5'
                ]"
              ></span>
            </button>
          </div>

          <!-- Category -->
          <div v-if="availableCategories.length > 0">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">Category <span class="text-surface-400 font-normal">(optional)</span></label>
            <select
              v-model="selectedCategoryId"
              class="w-full px-4 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            >
              <option :value="null">No category</option>
              <option v-for="cat in availableCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
            </select>
          </div>

          <!-- Topic -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">Topic <span class="text-surface-400 font-normal">(optional)</span></label>
            <input
              v-model="topic"
              type="text"
              maxlength="500"
              placeholder="What is this channel about?"
              class="w-full px-4 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>

          <!-- Purpose -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">Purpose <span class="text-surface-400 font-normal">(optional)</span></label>
            <textarea
              v-model="purpose"
              rows="2"
              placeholder="Describe the purpose of this channel..."
              class="w-full px-4 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none resize-none"
            ></textarea>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-3 p-5 border-t border-surface-200 dark:border-[rgb(var(--color-border))]">
          <button
            @click="emit('back')"
            class="px-5 py-2.5 text-sm font-medium text-surface-600 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
          >
            Back
          </button>
          <button
            @click="handleCreate"
            :disabled="creating || !name.trim()"
            class="px-5 py-2.5 bg-primary-500 hover:bg-primary-600 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-full transition-colors"
          >
            {{ creating ? 'Creating...' : 'Create Channel' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

