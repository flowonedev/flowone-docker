<script setup>
import { ref, computed } from 'vue'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useToastStore } from '@/stores/toast'
import CalendarShareModal from './CalendarShareModal.vue'
import api from '@/services/api'

const calendar = useCalendarStore()
const toast = useToastStore()

// Share modal
const showShareModal = ref(false)
const sharingCalendar = ref(null)

function openShareModal(cal) {
  sharingCalendar.value = cal
  showShareModal.value = true
}

function onShareUpdated() {
  calendar.fetchCalendars({ force: true })
}

const emit = defineEmits(['close'])

// New calendar modal
const showNewCalendarModal = ref(false)
const newCalendarName = ref('')
const newCalendarColor = ref('#3b82f6')
const creatingCalendar = ref(false)

// Edit calendar modal
const showEditCalendarModal = ref(false)
const editingCalendar = ref(null)
const editCalendarName = ref('')
const editCalendarColor = ref('')

// Delete confirmation
const showDeleteConfirm = ref(false)
const calendarToDelete = ref(null)

// Subscription URL modal
const showSubscriptionModal = ref(false)
const subscriptionCalendar = ref(null)
const subscriptionData = ref(null)
const loadingSubscription = ref(false)

async function getSubscriptionUrl(cal) {
  subscriptionCalendar.value = cal
  loadingSubscription.value = true
  showSubscriptionModal.value = true
  
  try {
    const response = await api.get(`/calendars/${cal.id}/subscription`)
    if (response.data.success) {
      subscriptionData.value = response.data.data
    } else {
      toast.error('Failed to get subscription URL')
      showSubscriptionModal.value = false
    }
  } catch (e) {
    toast.error('Failed to get subscription URL')
    showSubscriptionModal.value = false
  } finally {
    loadingSubscription.value = false
  }
}

async function regenerateSubscription() {
  if (!subscriptionCalendar.value) return
  loadingSubscription.value = true
  
  try {
    const response = await api.post(`/calendars/${subscriptionCalendar.value.id}/subscription/regenerate`)
    if (response.data.success) {
      subscriptionData.value = response.data.data
      toast.success('Subscription URL regenerated')
    }
  } catch (e) {
    toast.error('Failed to regenerate URL')
  } finally {
    loadingSubscription.value = false
  }
}

function copyToClipboard(text, label) {
  navigator.clipboard.writeText(text).then(() => {
    toast.success(`${label} copied to clipboard`)
  }).catch(() => {
    toast.error('Failed to copy')
  })
}

// Per-calendar .ics export (one calendar at a time). Uses the existing
// authenticated GET /calendars/{id}/export endpoint and triggers a blob
// download so the request carries the Bearer token.
const exportingCalendarId = ref(null)

async function exportCalendar(cal) {
  if (exportingCalendarId.value) return
  exportingCalendarId.value = cal.id
  try {
    const res = await api.get(`/calendars/${cal.id}/export`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'text/calendar' }))
    const a = document.createElement('a')
    a.href = url
    a.download = `${(cal.name || 'calendar').replace(/[^a-z0-9_-]+/gi, '_')}.ics`
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    toast.error('Failed to export calendar')
  } finally {
    exportingCalendarId.value = null
  }
}

async function createNewCalendar() {
  if (!newCalendarName.value.trim()) {
    toast.warning('Please enter a calendar name')
    return
  }
  
  creatingCalendar.value = true
  const result = await calendar.createCalendar(newCalendarName.value.trim(), newCalendarColor.value)
  creatingCalendar.value = false
  
  if (result.success) {
    toast.success('Calendar created')
    showNewCalendarModal.value = false
    newCalendarName.value = ''
    newCalendarColor.value = '#3b82f6'
  } else {
    toast.error(result.error || 'Failed to create calendar')
  }
}

function openEditModal(cal) {
  editingCalendar.value = cal
  editCalendarName.value = cal.name
  editCalendarColor.value = cal.color || '#3b82f6'
  showEditCalendarModal.value = true
}

async function saveCalendarEdit() {
  if (!editCalendarName.value.trim()) {
    toast.warning('Please enter a calendar name')
    return
  }
  
  const result = await calendar.updateCalendar(editingCalendar.value.id, {
    name: editCalendarName.value.trim(),
    color: editCalendarColor.value
  })
  
  if (result.success) {
    toast.success('Calendar updated')
    showEditCalendarModal.value = false
    await calendar.fetchCalendars()
  } else {
    toast.error(result.error || 'Failed to update calendar')
  }
}

function confirmDeleteCalendar(cal) {
  calendarToDelete.value = cal
  showDeleteConfirm.value = true
}

async function deleteCalendarConfirmed() {
  if (!calendarToDelete.value) return
  
  const result = await calendar.deleteCalendar(calendarToDelete.value.id)
  
  if (result.success) {
    toast.success('Calendar deleted')
    showDeleteConfirm.value = false
    calendarToDelete.value = null
    await calendar.fetchCalendars()
  } else {
    toast.error(result.error || 'Failed to delete calendar')
  }
}

// Get color hex for display
function getColorHex(colorValue) {
  if (!colorValue) return '#3b82f6'
  if (colorValue.startsWith('#')) return colorValue
  const colorObj = calendar.calendarColors.find(c => c.id === colorValue)
  return colorObj?.hex || '#3b82f6'
}
</script>

<template>
  <div class="h-full flex flex-col bg-white dark:bg-surface-800 border-r border-surface-200 dark:border-surface-700 pb-16 md:pb-0">
    <!-- Header -->
    <div class="p-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
      <h2 class="font-semibold text-surface-900 dark:text-surface-100">Calendars</h2>
      <button 
        @click="showNewCalendarModal = true"
        class="btn-primary btn-sm"
      >
        <span class="material-symbols-rounded text-lg">add</span>
        New
      </button>
    </div>
    
    <!-- Calendars list -->
    <div class="flex-1 overflow-y-auto p-2">
      <div v-if="calendar.calendars.length === 0" class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-4xl mb-2">calendar_month</span>
        <p class="text-sm">No calendars yet</p>
      </div>
      
      <div v-else class="space-y-1">
        <div 
          v-for="cal in calendar.calendars" 
          :key="cal.id"
          class="group px-3 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <!-- Row 1: visibility toggle + name + default badge -->
          <div class="flex items-center gap-2">
            <!-- Visibility toggle (checkbox styled) -->
            <button 
              @click="calendar.toggleCalendarVisibility(cal.id)"
              class="w-5 h-5 flex-shrink-0 rounded border-2 flex items-center justify-center transition-colors"
              :style="{ 
                borderColor: getColorHex(cal.color),
                backgroundColor: calendar.isCalendarVisible(cal.id) ? getColorHex(cal.color) : 'transparent'
              }"
            >
              <span 
                v-if="calendar.isCalendarVisible(cal.id)" 
                class="material-symbols-rounded text-white text-sm"
              >check</span>
            </button>
            
            <!-- Calendar name -->
            <span class="flex-1 text-sm text-surface-900 dark:text-surface-100 truncate">
              {{ cal.name }}
            </span>
            
            <!-- Default badge -->
            <span 
              v-if="cal.is_default" 
              class="text-[10px] px-1.5 py-0.5 rounded bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400"
            >
              Default
            </span>
          </div>

          <!-- Row 2: actions, aligned under the calendar name (hover only) -->
          <div class="flex items-center gap-0.5 mt-1 pl-7 opacity-0 group-hover:opacity-100 transition-opacity">
            <button 
              @click="openShareModal(cal)"
              class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600"
              title="Share calendar"
            >
              <span class="material-symbols-rounded text-sm text-surface-500">share</span>
            </button>
            <button 
              @click="getSubscriptionUrl(cal)"
              class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600"
              title="Get subscription URL (for iPhone/external apps)"
            >
              <span class="material-symbols-rounded text-sm text-surface-500">link</span>
            </button>
            <button 
              @click="exportCalendar(cal)"
              :disabled="exportingCalendarId === cal.id"
              class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600 disabled:opacity-50"
              title="Export (.ics)"
            >
              <span 
                v-if="exportingCalendarId === cal.id" 
                class="spinner w-3.5 h-3.5"
              ></span>
              <span 
                v-else 
                class="material-symbols-rounded text-sm text-surface-500"
              >download</span>
            </button>
            <button 
              @click="openEditModal(cal)"
              class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600"
              title="Edit"
            >
              <span class="material-symbols-rounded text-sm text-surface-500">edit</span>
            </button>
            <button 
              v-if="!cal.is_default"
              @click="confirmDeleteCalendar(cal)"
              class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600"
              title="Delete"
            >
              <span class="material-symbols-rounded text-sm text-red-500">delete</span>
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Shared with me -->
    <div v-if="calendar.sharedCalendars.length > 0" class="px-2 pb-2">
      <div class="flex items-center gap-2 px-3 py-2">
        <span class="material-symbols-rounded text-surface-400 text-lg">people</span>
        <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Shared with me</span>
      </div>
      
      <div class="space-y-1">
        <div 
          v-for="cal in calendar.sharedCalendars" 
          :key="'shared-' + cal.id"
          class="group flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <!-- Visibility toggle -->
          <button 
            @click="calendar.toggleCalendarVisibility(cal.id)"
            class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors"
            :style="{ 
              borderColor: cal.color || '#3b82f6',
              backgroundColor: calendar.isCalendarVisible(cal.id) ? (cal.color || '#3b82f6') : 'transparent'
            }"
          >
            <span 
              v-if="calendar.isCalendarVisible(cal.id)" 
              class="material-symbols-rounded text-white text-sm"
            >check</span>
          </button>
          
          <!-- Name + owner -->
          <div class="flex-1 min-w-0">
            <span class="text-sm text-surface-900 dark:text-surface-100 truncate block">{{ cal.name }}</span>
            <span class="text-[10px] text-surface-400 truncate block">{{ cal.shared_by }}</span>
          </div>
          
          <!-- Permission badge -->
          <span 
            class="text-[10px] px-1.5 py-0.5 rounded"
            :class="cal.can_edit 
              ? 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400'
              : 'bg-surface-100 dark:bg-surface-600 text-surface-500'"
          >
            {{ cal.can_edit ? 'Edit' : 'View' }}
          </span>
        </div>
      </div>
    </div>
    
    <!-- Color legend -->
    <div class="p-4 border-t border-surface-200 dark:border-surface-700">
      <p class="text-xs text-surface-500 mb-2">Available colors:</p>
      <div class="flex flex-wrap gap-1.5">
        <div 
          v-for="color in calendar.calendarColors" 
          :key="color.id"
          class="w-5 h-5 rounded-full"
          :style="{ backgroundColor: color.hex }"
          :title="color.name"
        ></div>
      </div>
    </div>
    
    <!-- New Calendar Modal -->
    <Teleport to="body">
      <div v-if="showNewCalendarModal" class="modal-overlay" @click.self="showNewCalendarModal = false">
        <div class="modal max-w-sm">
          <div class="modal-header">
            <h3 class="font-semibold">New Calendar</h3>
            <button @click="showNewCalendarModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="modal-body space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Calendar Name
              </label>
              <input 
                v-model="newCalendarName"
                type="text"
                class="input"
                placeholder="e.g., Work, Personal, Birthdays"
                @keyup.enter="createNewCalendar"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Color
              </label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="color in calendar.calendarColors"
                  :key="color.id"
                  @click="newCalendarColor = color.hex"
                  class="w-8 h-8 rounded-full transition-transform hover:scale-110 flex items-center justify-center"
                  :style="{ backgroundColor: color.hex }"
                  :title="color.name"
                >
                  <span 
                    v-if="newCalendarColor === color.hex"
                    class="material-symbols-rounded text-white text-sm"
                  >check</span>
                </button>
              </div>
            </div>
          </div>
          
          <div class="modal-footer">
            <button @click="showNewCalendarModal = false" class="btn-ghost">Cancel</button>
            <button 
              @click="createNewCalendar" 
              class="btn-primary"
              :disabled="creatingCalendar || !newCalendarName.trim()"
            >
              <span v-if="creatingCalendar" class="spinner w-4 h-4"></span>
              <span class="material-symbols-rounded">add</span>
              Create
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Edit Calendar Modal -->
    <Teleport to="body">
      <div v-if="showEditCalendarModal" class="modal-overlay" @click.self="showEditCalendarModal = false">
        <div class="modal max-w-sm">
          <div class="modal-header">
            <h3 class="font-semibold">Edit Calendar</h3>
            <button @click="showEditCalendarModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="modal-body space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Calendar Name
              </label>
              <input 
                v-model="editCalendarName"
                type="text"
                class="input"
                @keyup.enter="saveCalendarEdit"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Color
              </label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="color in calendar.calendarColors"
                  :key="color.id"
                  @click="editCalendarColor = color.hex"
                  class="w-8 h-8 rounded-full transition-transform hover:scale-110 flex items-center justify-center"
                  :style="{ backgroundColor: color.hex }"
                  :title="color.name"
                >
                  <span 
                    v-if="editCalendarColor === color.hex"
                    class="material-symbols-rounded text-white text-sm"
                  >check</span>
                </button>
              </div>
            </div>
          </div>
          
          <div class="modal-footer">
            <button @click="showEditCalendarModal = false" class="btn-ghost">Cancel</button>
            <button 
              @click="saveCalendarEdit" 
              class="btn-primary"
              :disabled="!editCalendarName.trim()"
            >
              <span class="material-symbols-rounded">save</span>
              Save
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Delete Confirmation Modal -->
    <Teleport to="body">
      <div v-if="showDeleteConfirm" class="modal-overlay" @click.self="showDeleteConfirm = false">
        <div class="modal max-w-sm">
          <div class="modal-header">
            <h3 class="font-semibold text-red-600">Delete Calendar</h3>
            <button @click="showDeleteConfirm = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="modal-body">
            <p class="text-surface-600 dark:text-surface-400">
              Are you sure you want to delete "<strong>{{ calendarToDelete?.name }}</strong>"? 
              All events in this calendar will be deleted permanently.
            </p>
          </div>
          
          <div class="modal-footer">
            <button @click="showDeleteConfirm = false" class="btn-ghost">Cancel</button>
            <button @click="deleteCalendarConfirmed" class="btn bg-red-500 hover:bg-red-600 text-white">
              <span class="material-symbols-rounded">delete</span>
              Delete
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Subscription URL Modal -->
    <Teleport to="body">
      <div v-if="showSubscriptionModal" class="modal-overlay" @click.self="showSubscriptionModal = false">
        <div class="modal max-w-md">
          <div class="modal-header">
            <h3 class="font-semibold">Subscribe to Calendar</h3>
            <button @click="showSubscriptionModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="modal-body space-y-4">
            <div v-if="loadingSubscription" class="flex items-center justify-center py-8">
              <span class="spinner w-8 h-8"></span>
            </div>
            
            <template v-else-if="subscriptionData">
              <p class="text-sm text-surface-600 dark:text-surface-400">
                Use these URLs to subscribe to "<strong>{{ subscriptionCalendar?.name }}</strong>" from your phone or other calendar apps.
              </p>
              
              <!-- iPhone/iOS instructions -->
              <div class="bg-blue-50 dark:bg-blue-500/10 rounded-lg p-3">
                <div class="flex items-start gap-2">
                  <span class="material-symbols-rounded text-blue-500">phone_iphone</span>
                  <div class="text-sm">
                    <p class="font-medium text-blue-700 dark:text-blue-400">iPhone / iOS</p>
                    <p class="text-blue-600 dark:text-blue-300 text-xs mt-1">
                      Tap the webcal URL below, or go to Settings → Calendar → Accounts → Add Subscribed Calendar and paste the URL.
                    </p>
                  </div>
                </div>
              </div>
              
              <!-- Webcal URL -->
              <div>
                <label class="block text-xs font-medium text-surface-500 mb-1">Webcal URL (recommended)</label>
                <div class="flex gap-2">
                  <input 
                    type="text" 
                    readonly 
                    :value="subscriptionData.webcal_url"
                    class="input text-xs flex-1 font-mono"
                  />
                  <button 
                    @click="copyToClipboard(subscriptionData.webcal_url, 'Webcal URL')"
                    class="btn-ghost btn-icon flex-shrink-0"
                    title="Copy"
                  >
                    <span class="material-symbols-rounded">content_copy</span>
                  </button>
                </div>
              </div>
              
              <!-- HTTPS URL -->
              <div>
                <label class="block text-xs font-medium text-surface-500 mb-1">HTTPS URL (alternative)</label>
                <div class="flex gap-2">
                  <input 
                    type="text" 
                    readonly 
                    :value="subscriptionData.https_url"
                    class="input text-xs flex-1 font-mono"
                  />
                  <button 
                    @click="copyToClipboard(subscriptionData.https_url, 'HTTPS URL')"
                    class="btn-ghost btn-icon flex-shrink-0"
                    title="Copy"
                  >
                    <span class="material-symbols-rounded">content_copy</span>
                  </button>
                </div>
              </div>
              
              <!-- Note -->
              <div class="text-xs text-surface-500 flex items-start gap-1.5">
                <span class="material-symbols-rounded text-sm">info</span>
                <span>Your calendar app will automatically check for updates. Changes you make here will appear on your subscribed devices.</span>
              </div>
            </template>
          </div>
          
          <div class="modal-footer justify-between">
            <button 
              @click="regenerateSubscription"
              class="btn-ghost text-orange-500 text-xs"
              :disabled="loadingSubscription"
              title="Generate a new URL (invalidates old one)"
            >
              <span class="material-symbols-rounded text-sm">refresh</span>
              Regenerate URL
            </button>
            <button @click="showSubscriptionModal = false" class="btn-primary">Done</button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Calendar Share Modal -->
    <CalendarShareModal
      v-if="sharingCalendar"
      :calendar="sharingCalendar"
      :show="showShareModal"
      @close="showShareModal = false; sharingCalendar = null"
      @updated="onShareUpdated"
    />
  </div>
</template>

