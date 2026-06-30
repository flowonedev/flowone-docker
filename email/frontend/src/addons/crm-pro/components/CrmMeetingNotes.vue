<script setup>
/**
 * CrmMeetingNotes - Meeting notes management for a client
 * Create/edit notes with attendees and action items.
 */
import { ref, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: { type: Number, required: true },
})

const toast = useToastStore()
const notes = ref([])
const loading = ref(false)
const showNew = ref(false)
const expandedNote = ref(null)

const newNote = ref({
  title: '',
  content: '',
  meeting_date: new Date().toISOString().slice(0, 16),
  attendees: [],
  action_items: [],
})
const newAttendee = ref('')
const newActionItem = ref('')

watch(() => props.clientId, () => fetchNotes(), { immediate: true })

async function fetchNotes() {
  loading.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/meeting-notes`)
    if (res.data?.success) notes.value = res.data.data?.notes || []
  } catch (e) { notes.value = [] }
  loading.value = false
}

async function createNote() {
  if (!newNote.value.title.trim()) {
    toast.error('Title is required')
    return
  }
  try {
    await api.post(`/clients/${props.clientId}/meeting-notes`, newNote.value)
    toast.success('Meeting note saved')
    showNew.value = false
    newNote.value = { title: '', content: '', meeting_date: new Date().toISOString().slice(0, 16), attendees: [], action_items: [] }
    newAttendee.value = ''
    newActionItem.value = ''
    fetchNotes()
  } catch (e) {
    toast.error('Failed to save note')
  }
}

function addAttendee() {
  if (newAttendee.value.trim()) {
    newNote.value.attendees.push(newAttendee.value.trim())
    newAttendee.value = ''
  }
}

function removeAttendee(idx) {
  newNote.value.attendees.splice(idx, 1)
}

function addActionItem() {
  if (newActionItem.value.trim()) {
    newNote.value.action_items.push({ text: newActionItem.value.trim(), assignee: '', done: false })
    newActionItem.value = ''
  }
}

function removeActionItem(idx) {
  newNote.value.action_items.splice(idx, 1)
}

async function toggleActionItem(note, idx) {
  const items = [...(note.action_items || [])]
  items[idx] = { ...items[idx], done: !items[idx].done }
  try {
    await api.put(`/clients/${props.clientId}/meeting-notes/${note.id}`, { action_items: items })
    note.action_items = items
  } catch (e) { /* ignore */ }
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">notes</span>
        Meeting Notes
      </h3>
      <button @click="showNew = !showNew"
              class="text-xs text-primary-600 hover:text-primary-700 font-medium flex items-center gap-0.5">
        <span class="material-symbols-rounded text-sm">add</span> New
      </button>
    </div>

    <!-- New Note Form -->
    <div v-if="showNew" class="p-3 mb-3 bg-surface-50 dark:bg-surface-800/50 rounded-lg space-y-2">
      <input v-model="newNote.title" placeholder="Meeting title"
             class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
      <input v-model="newNote.meeting_date" type="datetime-local"
             class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs" />
      <textarea v-model="newNote.content" placeholder="Meeting notes..." rows="4"
                class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm resize-none focus:ring-2 focus:ring-primary-500 outline-none"></textarea>

      <!-- Attendees -->
      <div>
        <p class="text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Attendees</p>
        <div class="flex flex-wrap gap-1 mb-1">
          <span v-for="(a, i) in newNote.attendees" :key="i"
                class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300 rounded-full text-xs">
            {{ a }}
            <button @click="removeAttendee(i)" class="text-blue-400 hover:text-red-500"><span class="material-symbols-rounded text-xs">close</span></button>
          </span>
        </div>
        <div class="flex gap-1">
          <input v-model="newAttendee" placeholder="Add attendee email" @keyup.enter="addAttendee"
                 class="flex-1 px-2 py-1 rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs" />
          <button @click="addAttendee" class="px-2 py-1 bg-blue-100 dark:bg-blue-500/20 text-blue-600 rounded text-xs">Add</button>
        </div>
      </div>

      <!-- Action Items -->
      <div>
        <p class="text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Action Items</p>
        <div class="space-y-1 mb-1">
          <div v-for="(item, i) in newNote.action_items" :key="i" class="flex items-center gap-2 text-xs">
            <span class="text-surface-400">{{ i + 1 }}.</span>
            <span class="flex-1">{{ item.text }}</span>
            <button @click="removeActionItem(i)" class="text-red-400"><span class="material-symbols-rounded text-xs">close</span></button>
          </div>
        </div>
        <div class="flex gap-1">
          <input v-model="newActionItem" placeholder="Add action item" @keyup.enter="addActionItem"
                 class="flex-1 px-2 py-1 rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs" />
          <button @click="addActionItem" class="px-2 py-1 bg-amber-100 dark:bg-amber-500/20 text-amber-600 rounded text-xs">Add</button>
        </div>
      </div>

      <div class="flex justify-end gap-2">
        <button @click="showNew = false" class="px-3 py-1.5 text-xs text-surface-500">Cancel</button>
        <button @click="createNote"
                class="px-4 py-1.5 rounded-lg bg-primary-600 text-white text-xs font-medium hover:bg-primary-700">
          Save Note
        </button>
      </div>
    </div>

    <!-- Notes List -->
    <div v-if="loading" class="text-center py-3">
      <div class="animate-spin w-5 h-5 border-2 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
    </div>
    <div v-else-if="notes.length" class="space-y-2">
      <div v-for="note in notes.slice(0, 5)" :key="note.id"
           class="p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800/50 cursor-pointer"
           @click="expandedNote = expandedNote === note.id ? null : note.id">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-sm text-surface-400">description</span>
          <span class="text-sm font-medium text-surface-700 dark:text-surface-200 flex-1 truncate">{{ note.title }}</span>
          <span class="text-xs text-surface-400">{{ formatDate(note.meeting_date) }}</span>
        </div>

        <!-- Expanded -->
        <div v-if="expandedNote === note.id" class="mt-2 pl-6 space-y-2">
          <p v-if="note.content" class="text-xs text-surface-500 whitespace-pre-wrap">{{ note.content }}</p>
          <div v-if="note.attendees?.length" class="flex flex-wrap gap-1">
            <span v-for="(a, i) in note.attendees" :key="i"
                  class="px-2 py-0.5 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 rounded-full text-[10px]">
              {{ a }}
            </span>
          </div>
          <div v-if="note.action_items?.length" class="space-y-1">
            <div v-for="(item, i) in note.action_items" :key="i"
                 class="flex items-center gap-2 text-xs cursor-pointer"
                 @click.stop="toggleActionItem(note, i)">
              <span class="material-symbols-rounded text-sm" :class="item.done ? 'text-green-500' : 'text-surface-300'">
                {{ item.done ? 'check_circle' : 'radio_button_unchecked' }}
              </span>
              <span :class="item.done ? 'line-through text-surface-400' : 'text-surface-600 dark:text-surface-300'">{{ item.text }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <p v-else class="text-xs text-surface-400 text-center py-2">No meeting notes yet</p>
  </div>
</template>

