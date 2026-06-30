<script setup>
import { ref, onMounted, onUnmounted, computed, watch, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useThemeStore } from '@/stores/theme'
import { useToastStore } from '@/stores/toast'
import { useAccountsStore } from '@/stores/accounts'
import { useAuthStore } from '@/stores/auth'
import { useClientsStore } from '@/stores/clients'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useI18n } from 'vue-i18n'
import AppHeader from '@/components/shared/AppHeader.vue'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import MeetingSettingsToggles from '@/components/call/MeetingSettingsToggles.vue'
import MeetingLinkDialog from '@/components/call/MeetingLinkDialog.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import CalendarSidebar from '@/addons/calendar/components/CalendarSidebar.vue'
import CalendarTodayPanel from '@/addons/calendar/components/CalendarTodayPanel.vue'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
import api from '@/services/api'
import { featureGuides } from '@/data/featureGuides'
import StepGuide from '@/components/shared/StepGuide.vue'
import { calendarGuide } from '@/data/stepGuides'

const router = useRouter()
const route = useRoute()
const accountsStore = useAccountsStore()
const authStore = useAuthStore()
const calendar = useCalendarStore()
const clientsStore = useClientsStore()
const boardsStore = useBoardsStore()
const colleaguesStore = useColleaguesStore()
const theme = useThemeStore()
const toast = useToastStore()
const { t, locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

// Feature guide
const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.calendar

// Account dropdown state
const showAccountDropdown = ref(false)

// Computed for account display
const allAccounts = computed(() => {
  const primaryAccount = {
    id: 'primary',
    account_email: authStore.userEmail,
    display_name: authStore.displayName,
    is_primary: true,
    is_default: accountsStore.accounts.length === 0,
  }
  return [primaryAccount, ...accountsStore.accounts]
})

const currentAccount = computed(() => {
  if (!accountsStore.activeAccountId || accountsStore.activeAccountId === 'primary') {
    return allAccounts.value[0]
  }
  return allAccounts.value.find(a => a.id === parseInt(accountsStore.activeAccountId)) || allAccounts.value[0]
})

function getAccountInitials(account) {
  const name = account.display_name || account.account_email
  return name.substring(0, 2).toUpperCase()
}

// Accent color mapping (ID -> hex color) - matches theme store colors
const accentColorMap = {
  green: '#22c55e',
  red: '#ef4444',
  purple: '#a855f7',
  blue: '#3b82f6',
  gold: '#eab308',
  mono: '#404040',
  teal: '#14b8a6',
  orange: '#f97316',
  gradient: '#a855f7', // Use purple as fallback for gradient
}

// Avatar reactivity key
const avatarKey = ref(0)

function getAccountAccentColor(account) {
  const _ = avatarKey.value
  const accountId = account.id === 'primary' ? 'primary' : account.id
  const accentId = localStorage.getItem(`webmail_accent_${accountId}`) 
    || localStorage.getItem('webmail_accent') 
    || 'green'
  return accentColorMap[accentId] || accentColorMap.green
}

function getAccountAvatarStyle(account) {
  return { backgroundColor: getAccountAccentColor(account) }
}

// Watch for theme/account changes to update avatar colors
watch(() => theme.accentColor, () => {
  avatarKey.value++
})

watch(() => accountsStore.activeAccountId, () => {
  avatarKey.value++
})

const weekDayNames = computed(() => {
  // 2021-08-02 is a Monday; week starts on Monday.
  const base = new Date(Date.UTC(2021, 7, 2))
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(base)
    d.setUTCDate(base.getUTCDate() + i)
    return d.toLocaleDateString(localeTag.value, { weekday: 'short' })
  })
})

const weekDayNarrow = computed(() => {
  const base = new Date(Date.UTC(2021, 7, 2))
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(base)
    d.setUTCDate(base.getUTCDate() + i)
    return d.toLocaleDateString(localeTag.value, { weekday: 'narrow' })
  })
})

// Week view computed (Monday-start)
const weekViewDays = computed(() => {
  const date = new Date(calendar.currentDate)
  const dayOfWeek = date.getDay()
  const startOfWeek = new Date(date)
  // Shift so Monday=0: Sunday(0)->6, Mon(1)->0, Tue(2)->1, etc.
  const mondayOffset = dayOfWeek === 0 ? 6 : dayOfWeek - 1
  startOfWeek.setDate(date.getDate() - mondayOffset)
  
  const days = []
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  
  for (let i = 0; i < 7; i++) {
    const dayDate = new Date(startOfWeek)
    dayDate.setDate(startOfWeek.getDate() + i)
    dayDate.setHours(0, 0, 0, 0)
    
    days.push({
      date: dayDate,
      day: dayDate.getDate(),
      dayName: dayDate.toLocaleDateString(localeTag.value, { weekday: 'short' }),
      monthName: dayDate.toLocaleDateString(localeTag.value, { month: 'short' }),
      isToday: dayDate.getTime() === today.getTime(),
      events: calendar.getEventsForDay(dayDate)
    })
  }
  return days
})

// Week view header (date range)
const weekViewHeader = computed(() => {
  if (weekViewDays.value.length === 0) return ''
  const start = weekViewDays.value[0].date
  const end = weekViewDays.value[6].date
  
  const startMonth = start.toLocaleDateString(localeTag.value, { month: 'short' })
  const endMonth = end.toLocaleDateString(localeTag.value, { month: 'short' })
  const year = end.getFullYear()
  
  if (startMonth === endMonth) {
    return `${startMonth} ${start.getDate()} - ${end.getDate()}, ${year}`
  }
  return `${startMonth} ${start.getDate()} - ${endMonth} ${end.getDate()}, ${year}`
})

// Day view header
const dayViewHeader = computed(() => {
  const date = new Date(calendar.currentDate)
  return date.toLocaleDateString(localeTag.value, { 
    weekday: 'long', 
    month: 'long', 
    day: 'numeric', 
    year: 'numeric' 
  })
})

// Day view data
const dayViewData = computed(() => {
  const date = new Date(calendar.currentDate)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const isToday = date.toDateString() === today.toDateString()
  
  const events = calendar.viewEvents.filter(event => {
    const eventDate = new Date(event.start_time)
    return eventDate.toDateString() === date.toDateString()
  })
  
  const allDayEvents = events.filter(e => e.all_day)
  const timedEvents = events.filter(e => !e.all_day).sort((a, b) => 
    new Date(a.start_time) - new Date(b.start_time)
  )
  
  return {
    date,
    day: date.getDate(),
    dayName: date.toLocaleDateString(localeTag.value, { weekday: 'long' }),
    isToday,
    events: timedEvents,
    allDayEvents
  }
})

// Hours for week view
const hours = Array.from({ length: 24 }, (_, i) => i)

// Get events for a specific hour
function getEventsForHour(day, hour) {
  return day.events.filter(event => {
    if (event.all_day) return false
    const eventStart = new Date(event.start_time)
    return eventStart.getHours() === hour
  })
}

// Get all-day events for a day
function getAllDayEvents(day) {
  return day.events.filter(event => event.all_day)
}

// Grid rows are 3rem each (matching the explicit grid-template-rows)
function getEventStyle(event) {
  const start = new Date(event.start_time)
  const end = new Date(event.end_time)
  const startHour = start.getHours() + start.getMinutes() / 60
  const endHour = end.getHours() + end.getMinutes() / 60
  const duration = Math.max(endHour - startHour, 0.333)

  return {
    top: `${startHour * 3}rem`,
    height: `${Math.max(duration * 3, 1)}rem`,
  }
}

// Quick add
const quickAddText = ref('')
const addingEvent = ref(false)

// Event modal
const showEventModal = ref(false)
const editingEvent = ref(null)

// Stop tracking when event modal is closed
watch(() => showEventModal.value, (open) => {
  if (!open) {
    clientTimeTracker.stopTracking()
  }
})

const eventForm = ref({
  title: '',
  description: '',
  location: '',
  start_date: '',
  start_time: '09:00',
  end_date: '',
  end_time: '10:00',
  all_day: false,
  calendar_id: null,
  client_id: null,
  board_id: null,
  card_id: null,
  recurrence: 'none',
  participants: [],
  is_meeting: false,
  waiting_room: false,
  participants_hidden: false,
})

const clientBoards = ref([])
const loadingClientBoards = ref(false)

watch(() => eventForm.value.client_id, async (newId) => {
  eventForm.value.board_id = null
  eventForm.value.card_id = null
  clientBoards.value = []
  if (!newId) return
  loadingClientBoards.value = true
  try {
    const { data } = await api.get('/clients/board-mapping')
    const mapping = data?.data?.mapping || {}
    const matchingBoardIds = Object.entries(mapping)
      .filter(([, v]) => v.client_id === newId)
      .map(([id]) => parseInt(id))
    clientBoards.value = boardsStore.boards
      .filter(b => matchingBoardIds.includes(b.id))
      .map(b => ({ id: b.id, name: b.name }))
  } catch { clientBoards.value = [] }
  finally { loadingClientBoards.value = false }
})

// Compute the accent color for the event modal based on selected calendar
const eventModalColor = computed(() => {
  if (editingEvent.value?.is_shared_event) {
    return editingEvent.value.calendar_color || '#3b82f6'
  }
  if (eventForm.value.calendar_id) {
    const cal = calendar.calendars.find(c => c.id === eventForm.value.calendar_id)
    if (cal) return calendar.getCalendarColor(cal)
  }
  // Default to first calendar color
  if (calendar.calendars.length > 0) {
    return calendar.getCalendarColor(calendar.calendars[0])
  }
  return '#3b82f6'
})

// Suppression flag - prevents watchers from firing during programmatic form assignment
let _suppressFormWatchers = false

// When user changes start_time, shift end_time by the same delta to preserve duration
watch(() => eventForm.value.start_time, (newStartTime, oldStartTime) => {
  if (_suppressFormWatchers) return
  if (!newStartTime || !oldStartTime || !eventForm.value.start_date || eventForm.value.all_day) return

  const startDate = eventForm.value.start_date
  const oldStartMs = new Date(`${startDate}T${oldStartTime}`).getTime()
  const newStartMs = new Date(`${startDate}T${newStartTime}`).getTime()
  const shiftMs = newStartMs - oldStartMs

  if (shiftMs === 0) return

  const endDate = eventForm.value.end_date || startDate
  const currentEndMs = new Date(`${endDate}T${eventForm.value.end_time}`).getTime()
  const newEnd = new Date(currentEndMs + shiftMs)

  const endYear = newEnd.getFullYear()
  const endMonth = String(newEnd.getMonth() + 1).padStart(2, '0')
  const endDay = String(newEnd.getDate()).padStart(2, '0')
  eventForm.value.end_date = `${endYear}-${endMonth}-${endDay}`
  eventForm.value.end_time = newEnd.toTimeString().slice(0, 5)
})

watch(() => eventForm.value.start_date, (newStartDate, oldStartDate) => {
  if (_suppressFormWatchers) return
  if (!newStartDate || !eventForm.value.start_time || eventForm.value.all_day) return
  if (!oldStartDate || !eventForm.value.end_date) return
  
  // Shift end date by the same number of days
  const oldStart = new Date(oldStartDate)
  const oldEnd = new Date(eventForm.value.end_date)
  const dayDiff = Math.round((oldEnd - oldStart) / 86400000)
  
  const newStart = new Date(newStartDate)
  const newEnd = new Date(newStart.getTime() + dayDiff * 86400000)
  
  const endYear = newEnd.getFullYear()
  const endMonth = String(newEnd.getMonth() + 1).padStart(2, '0')
  const endDay = String(newEnd.getDate()).padStart(2, '0')
  eventForm.value.end_date = `${endYear}-${endMonth}-${endDay}`
})

const meetingLink = ref(null) // Holds the generated meeting link after creation
const adminMeetingLink = ref(null)
const showMeetingLinkDialog = ref(false) // Show meeting link dialog after creation
const createdMeetingTitle = ref('') // Title of the just-created meeting
const addingMeeting = ref(false) // True while POST /events/:id/add-meeting is in flight
const recreatingMeeting = ref(false) // True while recreating (revoke + re-mint) the meeting link
const removingMeeting = ref(false) // True while turning the meeting back into a plain event
const participantInput = ref('')
const sendingInvites = ref(false)
const selectedGroupForEvent = ref(null)
const showParticipantPicker = ref(false)
const participantSearch = ref('')

// Filtered colleagues and groups for event participant picker
const filteredColleaguesForEvent = computed(() => {
  const search = participantSearch.value.toLowerCase()
  const existingEmails = eventForm.value.participants.map(p => p.email.toLowerCase())
  
  return colleaguesStore.sortedColleagues.filter(c => {
    // Don't show already added
    if (existingEmails.includes(c.email.toLowerCase())) return false
    // Search filter
    if (!search) return true
    return c.email.toLowerCase().includes(search) || 
           (c.display_name && c.display_name.toLowerCase().includes(search))
  }).slice(0, 10) // Limit to 10
})

const filteredGroupsForEvent = computed(() => {
  const search = participantSearch.value.toLowerCase()
  
  return colleaguesStore.sortedGroups.filter(g => {
    if (!search) return true
    return g.name.toLowerCase().includes(search)
  })
})

// Recurrence options
const recurrenceOptions = computed(() => ([
  { value: 'none', label: t('calendarView.recurrence.none') },
  { value: 'daily', label: t('calendarView.recurrence.daily') },
  { value: 'weekly', label: t('calendarView.recurrence.weekly') },
  { value: 'biweekly', label: t('calendarView.recurrence.biweekly') },
  { value: 'monthly', label: t('calendarView.recurrence.monthly') },
  { value: 'yearly', label: t('calendarView.recurrence.yearly') },
]))

// Participant management
function addParticipant() {
  const email = participantInput.value.trim()
  if (!email) return
  
  // Basic email validation
  if (!email.includes('@')) {
    toast.warning(t('calendarView.pleaseEnterAValidEmail'))
    return
  }
  
  // Check if already added
  if (eventForm.value.participants.some(p => p.email === email)) {
    toast.warning(t('calendarView.participantAlreadyAdded'))
    return
  }
  
  eventForm.value.participants.push({ email, status: 'pending' })
  participantInput.value = ''
}

function removeParticipant(email) {
  eventForm.value.participants = eventForm.value.participants.filter(p => p.email !== email)
}

async function addGroupToEvent(groupId = null) {
  const gid = groupId || selectedGroupForEvent.value
  if (!gid) return
  
  const group = colleaguesStore.sortedGroups.find(g => g.id === gid)
  if (!group) return
  
  // Get group members
  const members = await colleaguesStore.getGroupMembers(gid)
  if (!members || members.length === 0) {
    toast.warning(t('calendarView.groupHasNoMembers'))
    return
  }
  
  let addedCount = 0
  for (const member of members) {
    // Skip if already added
    if (eventForm.value.participants.some(p => p.email.toLowerCase() === member.email.toLowerCase())) continue
    
    eventForm.value.participants.push({ email: member.email, status: 'pending' })
    addedCount++
  }
  
  if (addedCount > 0) {
    toast.success(t('calendarView.addedAddedcountMembersFromGroupname', { addedCount, group: group.name }))
  } else {
    toast.info(t('calendarView.allGroupMembersAreAlready'))
  }
  
  selectedGroupForEvent.value = null
  participantSearch.value = ''
}

function addColleagueToEvent(colleague) {
  if (eventForm.value.participants.some(p => p.email.toLowerCase() === colleague.email.toLowerCase())) {
    toast.info(t('calendarView.alreadyAdded'))
    return
  }
  
  eventForm.value.participants.push({ email: colleague.email, status: 'pending' })
  toast.success(t('calendarView.addedColleaguedisplaynameColleagueemail', { name: colleague.display_name || colleague.email }))
  participantSearch.value = ''
}

async function sendInvites() {
  if (!editingEvent.value) return
  
  const pendingParticipants = eventForm.value.participants.filter(p => p.status === 'pending')
  if (pendingParticipants.length === 0) {
    toast.info(t('calendarView.noPendingInvitationsToSend'))
    return
  }
  
  sendingInvites.value = true
  const emails = pendingParticipants.map(p => p.email)
  const result = await calendar.inviteParticipants(editingEvent.value.id, emails)
  
  if (result.error) {
    toast.error(result.error)
  } else {
    toast.success(t('calendarView.invitationsSentToResultsuccesslength0', { count: result.success?.length || 0 }))
    // Refresh the event to get updated participant statuses
    await calendar.fetchEvents()
  }
  sendingInvites.value = false
}

// Delete confirmation
const showDeleteConfirm = ref(false)
const eventToDelete = ref(null)

// Clean all events confirmation
const showCleanAllConfirm = ref(false)
const cleaningAllEvents = ref(false)

// Quick subscription copy
const copyingSubscription = ref(false)

// Subscription URL modal
const showSubscriptionModal = ref(false)
const subscriptionUrl = ref('')

async function copySubscriptionUrl() {
  // Get the default calendar
  const defaultCal = calendar.calendars.find(c => c.is_default) || calendar.calendars[0]
  if (!defaultCal) {
    toast.error(t('calendarView.noCalendarFound'))
    return
  }
  
  copyingSubscription.value = true
  try {
    const response = await api.get(`/calendars/${defaultCal.id}/subscription`)
    if (response.data.success) {
      const url = response.data.data.webcal_url
      subscriptionUrl.value = url
      
      // Try clipboard API first, then fallback
      let copied = false
      try {
        await navigator.clipboard.writeText(url)
        copied = true
      } catch (clipErr) {
        // Fallback for iOS Safari
        copied = fallbackCopy(url)
      }
      
      if (copied) {
        toast.success(t('calendarView.urlCopiedPasteItIn'))
      } else {
        // Show modal so user can manually copy
        showSubscriptionModal.value = true
      }
    } else {
      toast.error(t('calendarView.failedToGetSubscriptionUrl'))
    }
  } catch (e) {
    toast.error(t('calendarView.failedToGetUrl'))
  } finally {
    copyingSubscription.value = false
  }
}

function fallbackCopy(text) {
  try {
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.style.position = 'fixed'
    textarea.style.left = '-9999px'
    textarea.style.top = '0'
    document.body.appendChild(textarea)
    textarea.focus()
    textarea.select()
    const success = document.execCommand('copy')
    document.body.removeChild(textarea)
    return success
  } catch (e) {
    return false
  }
}

function copyFromModal() {
  let copied = false
  try {
    navigator.clipboard.writeText(subscriptionUrl.value)
    copied = true
  } catch (e) {
    copied = fallbackCopy(subscriptionUrl.value)
  }
  if (copied) {
    toast.success(t('calendarView.urlCopied'))
    showSubscriptionModal.value = false
  } else {
    toast.error(t('calendarView.pleaseSelectAndCopyManually'))
  }
}

// Mobile state
const isMobile = ref(false)
const sidebarOpen = ref(false)

// Right panel (Today) state - shown by default on desktop, hidden on mobile
const showTodayPanel = ref(true)

// Context menu state
const contextMenu = ref({
  show: false,
  x: 0,
  y: 0,
  event: null
})

function showContextMenu(e, event) {
  e.preventDefault()
  e.stopPropagation()
  contextMenu.value = {
    show: true,
    x: e.clientX,
    y: e.clientY,
    event: event
  }
}

function hideContextMenu() {
  contextMenu.value.show = false
  contextMenu.value.event = null
}

function contextMenuEdit() {
  if (contextMenu.value.event) {
    openEditModal(contextMenu.value.event)
  }
  hideContextMenu()
}

function contextMenuDelete() {
  if (contextMenu.value.event) {
    eventToDelete.value = contextMenu.value.event
    showDeleteConfirm.value = true
  }
  hideContextMenu()
}

// Drag and drop state
const draggedEvent = ref(null)
const dragOverDay = ref(null)
const dragOverHour = ref(null)

function onDragStart(e, event) {
  // Prevent dragging participant events (shared via chat — read-only)
  if (event.is_participant_event) {
    e.preventDefault()
    return
  }

  // Recurring events do not support instance exceptions yet.
  // Dragging them rewrites the whole series, which is misleading.
  if (event.is_recurrence_instance || event.recurrence) {
    e.preventDefault()
    toast.warning('Recurring events must be edited from the series form')
    return
  }

  draggedEvent.value = event
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', event.recurrence_parent_id || event.id)
  // Add dragging class
  e.target.classList.add('opacity-50')
}

function onDragEnd(e) {
  e.target.classList.remove('opacity-50')
  draggedEvent.value = null
  dragOverDay.value = null
  dragOverHour.value = null
}

function onDragOver(e, day) {
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  dragOverDay.value = day?.date || day
}

function onDragLeave() {
  dragOverDay.value = null
}

async function onDropOnDay(e, day) {
  e.preventDefault()
  if (!draggedEvent.value) return
  
  const event = draggedEvent.value
  const targetDate = day.date || day
  
  // Calculate new dates
  const oldStart = new Date(event.start_time)
  const oldEnd = new Date(event.end_time)
  const duration = oldEnd - oldStart
  
  const newStart = new Date(targetDate)
  newStart.setHours(oldStart.getHours(), oldStart.getMinutes(), 0, 0)
  const newEnd = new Date(newStart.getTime() + duration)
  
  // Update the event (use parent ID for recurring instances)
  const updateId = event.recurrence_parent_id || event.id
  try {
    const result = await calendar.updateEvent(updateId, {
      ...event,
      start_time: formatDateTimeLocal(newStart),
      end_time: formatDateTimeLocal(newEnd)
    })
    if (result.success) {
      toast.success(t('calendarView.eventMoved'))
      // Force refresh events to update all views (including recurrence expansion)
      await calendar.fetchEvents(null, null, { force: true, quiet: true })
    } else {
      toast.error(result.error || t('calendarView.failedToMoveEvent'))
    }
  } catch (err) {
    toast.error(t('calendarView.failedToMoveEvent'))
  }
  
  draggedEvent.value = null
  dragOverDay.value = null
}

// Day view drag and drop
function onDragOverHour(e, hour) {
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  dragOverHour.value = hour
}

function onDragLeaveHour() {
  dragOverHour.value = null
}

async function onDropOnHour(e, hour) {
  e.preventDefault()
  if (!draggedEvent.value) return
  
  const event = draggedEvent.value
  
  // Calculate new times
  const oldStart = new Date(event.start_time)
  const oldEnd = new Date(event.end_time)
  const duration = oldEnd - oldStart
  
  // Use selectedDay if available (from day modal), otherwise use calendar.currentDate (for day view)
  // or dragOverDay (for week view)
  let targetDate
  if (selectedDay.value?.date) {
    targetDate = new Date(selectedDay.value.date)
  } else if (dragOverDay.value) {
    targetDate = new Date(dragOverDay.value)
  } else {
    targetDate = new Date(calendar.currentDate)
  }
  
  const newStart = new Date(targetDate)
  newStart.setHours(hour, 0, 0, 0)
  const newEnd = new Date(newStart.getTime() + duration)
  
  // Update the event (use parent ID for recurring instances)
  const hourUpdateId = event.recurrence_parent_id || event.id
  try {
    const result = await calendar.updateEvent(hourUpdateId, {
      ...event,
      start_time: formatDateTimeLocal(newStart),
      end_time: formatDateTimeLocal(newEnd)
    })
    if (result.success) {
      const hourFormatted = newStart.toLocaleTimeString(localeTag.value, { hour: '2-digit', minute: '2-digit' })
      toast.success(t('calendarView.eventMovedTo', { time: hourFormatted }))
      // Force refresh events to update all views (including recurrence expansion)
      await calendar.fetchEvents(null, null, { force: true, quiet: true })
    } else {
      toast.error(result.error || t('calendarView.failedToMoveEvent'))
    }
  } catch (err) {
    toast.error(t('calendarView.failedToMoveEvent'))
  }
  
  draggedEvent.value = null
  dragOverHour.value = null
  dragOverDay.value = null
}

function checkMobile() {
  isMobile.value = window.innerWidth < 768
  if (!isMobile.value) {
    sidebarOpen.value = false
  }
}

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

function closeSidebar() {
  sidebarOpen.value = false
}

// iOS-style continuous scrolling months for mobile
// Generate 6 months of data (current month first, then 5 more)
const continuousMonthsData = computed(() => {
  const months = []
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  
  // Start from current month (not 2 months before)
  const startDate = new Date(calendar.currentDate)
  startDate.setDate(1)
  
  // Generate 6 months of weeks
  for (let m = 0; m < 6; m++) {
    const monthDate = new Date(startDate)
    monthDate.setMonth(monthDate.getMonth() + m)
    
    const monthName = monthDate.toLocaleDateString(localeTag.value, { month: 'long' })
    const year = monthDate.getFullYear()
    const monthIndex = monthDate.getMonth()
    
    // Get first day of month and find the Monday of that week
    const firstOfMonth = new Date(year, monthIndex, 1)
    const lastOfMonth = new Date(year, monthIndex + 1, 0)
    
    // Find the Monday of the week containing the 1st
    const startOfFirstWeek = new Date(firstOfMonth)
    const dayOfWeek = firstOfMonth.getDay()
    // Adjust to Monday (0 = Sunday, so we need Monday = 1)
    const daysToSubtract = dayOfWeek === 0 ? 6 : dayOfWeek - 1
    startOfFirstWeek.setDate(firstOfMonth.getDate() - daysToSubtract)
    
    const weeks = []
    let currentWeekStart = new Date(startOfFirstWeek)
    
    // Generate weeks until we've covered the entire month
    while (currentWeekStart <= lastOfMonth || weeks.length === 0) {
      const week = []
      for (let d = 0; d < 7; d++) {
        const dayDate = new Date(currentWeekStart)
        dayDate.setDate(currentWeekStart.getDate() + d)
        
        week.push({
          date: new Date(dayDate),
          day: dayDate.getDate(),
          isCurrentMonth: dayDate.getMonth() === monthIndex,
          isToday: dayDate.toDateString() === today.toDateString(),
          events: calendar.getEventsForDay(dayDate)
        })
      }
      weeks.push(week)
      currentWeekStart.setDate(currentWeekStart.getDate() + 7)
    }
    
    months.push({
      name: monthName,
      year,
      monthIndex,
      weeks,
      isCurrentMonth: monthDate.getMonth() === new Date(calendar.currentDate).getMonth() && 
                      monthDate.getFullYear() === new Date(calendar.currentDate).getFullYear()
    })
  }
  
  return months
})

// Get short event label (max 6-7 chars)
function getShortEventLabel(event) {
  const title = event.title || ''
  if (title.length <= 7) return title
  return title.substring(0, 6) + '…'
}

// Selected day for viewing
const selectedDay = ref(null)
const showDayModal = ref(false)

function formatTime(dateStr) {
  const date = new Date(dateStr)
  return date.toLocaleTimeString(localeTag.value, { hour: '2-digit', minute: '2-digit' })
}

function formatHourLabel(hour) {
  const d = new Date()
  d.setHours(hour, 0, 0, 0)
  return d.toLocaleTimeString(localeTag.value, { hour: 'numeric' })
}

function formatEventTime(event) {
  if (event.all_day) return t('calendarView.allDay')
  return formatTime(event.start_time)
}

function getEventColor(event) {
  // Synced events get a distinctive teal/cyan color
  if (event.is_synced) {
    return '#14b8a6' // teal-500
  }
  // Use calendar color from the calendar object
  if (event.calendar_id) {
    const cal = calendar.calendars.find(c => c.id === event.calendar_id)
    if (cal) {
      return calendar.getCalendarColor(cal)
    }
  }
  return event.color || event.calendar_color || '#3b82f6'
}

function isSyncedEvent(event) {
  return event.is_synced === true
}

// Quick add
async function quickAdd() {
  if (!quickAddText.value.trim()) return
  
  addingEvent.value = true
  const result = await calendar.quickAddEvent(quickAddText.value.trim())
  addingEvent.value = false
  
  if (result.success) {
    toast.success(t('calendarView.eventAdded'))
    quickAddText.value = ''
    await calendar.fetchEvents(null, null, { force: true })
  } else {
    toast.error(result.error || t('calendarView.failedToAddEvent'))
  }
}

// Format date to YYYY-MM-DD in local timezone (not UTC)
function formatDateLocal(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

// Format date+time to "YYYY-MM-DD HH:MM:SS" in local timezone (not UTC)
function formatDateTimeLocal(date) {
  const y = date.getFullYear()
  const mo = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  const h = String(date.getHours()).padStart(2, '0')
  const mi = String(date.getMinutes()).padStart(2, '0')
  const s = String(date.getSeconds()).padStart(2, '0')
  return `${y}-${mo}-${d} ${h}:${mi}:${s}`
}

// Event modal
function openNewEventModal(date = null) {
  editingEvent.value = null
  
  const now = new Date()
  const startDate = date || now
  
  _suppressFormWatchers = true
  eventForm.value = {
    title: '',
    description: '',
    location: '',
    start_date: formatDateLocal(startDate),
    start_time: '09:00',
    end_date: formatDateLocal(startDate),
    end_time: '10:00',
    all_day: false,
    calendar_id: calendar.defaultCalendar?.id,
    client_id: null,
    board_id: null,
    card_id: null,
    recurrence: 'none',
    participants: [],
    is_meeting: false,
    waiting_room: false,
    participants_hidden: false,
  }
  clientBoards.value = []
  nextTick(() => { _suppressFormWatchers = false })
  participantInput.value = ''
  meetingLink.value = null
  adminMeetingLink.value = null
  
  showEventModal.value = true
}

function openNewEventModalForTime(date, hour) {
  editingEvent.value = null
  
  const startHour = hour.toString().padStart(2, '0')
  const endHour = (hour + 1).toString().padStart(2, '0')
  
  _suppressFormWatchers = true
  eventForm.value = {
    title: '',
    description: '',
    location: '',
    start_date: formatDateLocal(date),
    start_time: `${startHour}:00`,
    end_date: formatDateLocal(date),
    end_time: `${endHour}:00`,
    all_day: false,
    calendar_id: calendar.defaultCalendar?.id,
    client_id: null,
    board_id: null,
    card_id: null,
    recurrence: 'none',
    participants: [],
    is_meeting: false,
    waiting_room: false,
    participants_hidden: false,
  }
  nextTick(() => { _suppressFormWatchers = false })
  participantInput.value = ''
  meetingLink.value = null
  adminMeetingLink.value = null
  
  showEventModal.value = true
}

function openEditEventModal(event) {
  // Participant events (shared via chat) are read-only — can't edit someone else's event
  if (event.is_participant_event) {
    toast.info(t('calendarView.sharedEventViewOnly', { title: event.title }))
    return
  }
  
  // Shared calendar events with view-only permission
  if (event.is_shared_event && event.shared_permission !== 'edit') {
    toast.info(t('calendarView.sharedFromViewOnly', { title: event.title, sharedBy: event.shared_by }))
    return
  }
  
  // For recurring event instances, use the parent event's original times for editing
  // The user edits the "series" (parent), not a single occurrence
  const isInstance = event.is_recurrence_instance
  const parentId = event.recurrence_parent_id || event.id
  
  // If this is a virtual recurrence instance, find the original parent event
  // (the one with the actual start/end dates from the DB)
  let editTarget = event
  if (isInstance) {
    const parent = calendar.events.find(e => e.id === parentId && !e.is_recurrence_instance)
    if (parent) {
      editTarget = parent
    }
  }
  
  editingEvent.value = { ...editTarget, id: parentId }
  
  const start = new Date(editTarget.start_time)
  const end = new Date(editTarget.end_time)
  
  _suppressFormWatchers = true
  eventForm.value = {
    title: editTarget.title,
    description: editTarget.description || '',
    location: editTarget.location || '',
    start_date: formatDateLocal(start),
    start_time: start.toTimeString().slice(0, 5),
    end_date: formatDateLocal(end),
    end_time: end.toTimeString().slice(0, 5),
    all_day: editTarget.all_day,
    calendar_id: editTarget.calendar_id,
    client_id: editTarget.client_id || null,
    board_id: editTarget.board_id || null,
    card_id: editTarget.card_id || null,
    recurrence: parseRecurrence(editTarget.recurrence),
    participants: editTarget.participants || [],
    is_meeting: editTarget.is_meeting || false,
    waiting_room: false,
    participants_hidden: false,
  }
  nextTick(() => { _suppressFormWatchers = false })

  if (editTarget.client_id) {
    api.get('/clients/board-mapping').then(res => {
      const mapping = res.data?.data?.mapping || {}
      const matchingBoardIds = Object.entries(mapping)
        .filter(([, v]) => v.client_id === (editTarget.client_id))
        .map(([id]) => parseInt(id))
      clientBoards.value = boardsStore.boards
        .filter(b => matchingBoardIds.includes(b.id))
        .map(b => ({ id: b.id, name: b.name }))
    }).catch(() => { clientBoards.value = [] })
  }
  participantInput.value = ''
  meetingLink.value = editTarget.meeting_link
    || (editTarget.meeting_token ? `${window.location.origin}/meet/${editTarget.meeting_token}` : null)
  adminMeetingLink.value = null

  // For events that are already meetings, fetch the host (admin) link
  // and the current waiting-room / workshop settings so the modal can
  // surface them. The list payload only carries the guest link.
  if (editTarget.is_meeting) {
    calendar.getEventMeeting(parentId).then(res => {
      // Ignore if the user already closed/switched events.
      if (!showEventModal.value || editingEvent.value?.id !== parentId) return
      if (res.success && res.data?.is_meeting) {
        if (res.data.meeting_link) meetingLink.value = res.data.meeting_link
        adminMeetingLink.value = res.data.admin_meeting_link || null
        eventForm.value.waiting_room = !!res.data.waiting_room_enabled
        eventForm.value.participants_hidden = !!res.data.participants_hidden
      }
    }).catch(() => { /* non-fatal: guest link still shown */ })
  }

  // Track calendar event viewing for client time tracking
  if (editTarget.client_id) {
    clientTimeTracker.trackCalendarEvent(editTarget)
  }
  
  showEventModal.value = true
}

// Parse RRULE string to simple recurrence value
function parseRecurrence(rrule) {
  if (!rrule) return 'none'
  if (rrule.includes('FREQ=DAILY')) return 'daily'
  if (rrule.includes('FREQ=WEEKLY') && rrule.includes('INTERVAL=2')) return 'biweekly'
  if (rrule.includes('FREQ=WEEKLY')) return 'weekly'
  if (rrule.includes('FREQ=MONTHLY')) return 'monthly'
  if (rrule.includes('FREQ=YEARLY')) return 'yearly'
  return 'none'
}

// Convert simple recurrence value to RRULE format
function buildRecurrenceRule(recurrence) {
  if (!recurrence || recurrence === 'none') return null
  
  const rules = {
    daily: 'RRULE:FREQ=DAILY',
    weekly: 'RRULE:FREQ=WEEKLY',
    biweekly: 'RRULE:FREQ=WEEKLY;INTERVAL=2',
    monthly: 'RRULE:FREQ=MONTHLY',
    yearly: 'RRULE:FREQ=YEARLY',
  }
  
  return rules[recurrence] || null
}

async function saveEvent() {
  if (!eventForm.value.title.trim()) {
    toast.warning(t('calendarView.pleaseEnterAnEventTitle'))
    return
  }
  
  const startTime = eventForm.value.all_day 
    ? `${eventForm.value.start_date} 00:00:00`
    : `${eventForm.value.start_date} ${eventForm.value.start_time}:00`
  
  const endTime = eventForm.value.all_day 
    ? `${eventForm.value.end_date} 23:59:59`
    : `${eventForm.value.end_date} ${eventForm.value.end_time}:00`
  
  // If this is a NEW meeting (not editing), use the meetings API
  if (eventForm.value.is_meeting && !editingEvent.value) {
    try {
      const meetingData = {
        title: eventForm.value.title,
        description: eventForm.value.description,
        start_time: startTime,
        end_time: endTime,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        calendar_id: eventForm.value.calendar_id,
        participants: eventForm.value.participants.map(p => p.email),
        color: null,
        reminders: [],
        waiting_room: eventForm.value.waiting_room,
        participants_hidden: eventForm.value.participants_hidden,
      }
      
      const response = await api.post('/meetings', meetingData)
      
      if (response.data?.data?.event) {
        toast.success(t('calendarView.meetingScheduled'))
        showEventModal.value = false
        
        await calendar.fetchEvents(null, null, { force: true })
        
        // Show the meeting link dialog so user can copy it
        if (response.data.data.meeting_link) {
          meetingLink.value = response.data.data.meeting_link
          adminMeetingLink.value = response.data.data.admin_meeting_link || null
          createdMeetingTitle.value = eventForm.value.title || t('calendarView.meeting')
          showMeetingLinkDialog.value = true
        }
      } else {
        toast.error(t('calendarView.failedToCreateMeeting'))
      }
    } catch (err) {
      console.error('[CalendarView] Failed to create meeting:', err)
      toast.error(err.response?.data?.error || t('calendarView.failedToCreateMeeting'))
    }
    return
  }
  
  const data = {
    title: eventForm.value.title,
    description: eventForm.value.description,
    location: eventForm.value.location,
    start_time: startTime,
    end_time: endTime,
    all_day: eventForm.value.all_day,
    calendar_id: eventForm.value.calendar_id,
    client_id: eventForm.value.client_id,
    board_id: eventForm.value.board_id,
    card_id: eventForm.value.card_id,
    recurrence: buildRecurrenceRule(eventForm.value.recurrence),
    participants: eventForm.value.participants,
    linked_message_id: eventForm.value.linked_message_id,
    linked_email_subject: eventForm.value.linked_email_subject,
    linked_email_sender: eventForm.value.linked_email_sender,
    linked_email_folder: eventForm.value.linked_email_folder,
  }
  
  let result
  if (editingEvent.value) {
    result = await calendar.updateEvent(editingEvent.value.id, data)
  } else {
    result = await calendar.createEvent(data)
  }
  
  if (result.success) {
    toast.success(editingEvent.value ? t('calendarView.eventUpdated') : t('calendarView.eventCreated'))
    showEventModal.value = false
    // Force refresh to get expanded recurrence occurrences from server
    await calendar.fetchEvents(null, null, { force: true })
  } else {
    toast.error(result.error || t('calendarView.failedToSaveEvent'))
  }
}

function confirmDeleteEvent(event) {
  // For recurrence instances, delete the parent event (deletes all occurrences)
  const parentId = event.recurrence_parent_id || event.id
  eventToDelete.value = { ...event, id: parentId }
  showDeleteConfirm.value = true
}

async function deleteEvent() {
  if (!eventToDelete.value) return
  
  const isRecurring = !!eventToDelete.value.recurrence
  const deleteResult = await calendar.deleteEvent(eventToDelete.value.id)
  if (deleteResult.success) {
    toast.success(isRecurring ? t('calendarView.recurringEventDeletedAllOccurrences') : t('calendarView.eventDeleted'))
    showEventModal.value = false
    // Force refresh to remove all occurrences from display
    await calendar.fetchEvents(null, null, { force: true })
  } else {
    toast.error(deleteResult.error || t('calendarView.failedToDeleteEvent'))
  }
  
  showDeleteConfirm.value = false
  eventToDelete.value = null
}

// Copy a meeting link to clipboard (guest invite link by default).
function copyToClipboard(value) {
  if (!value) return
  navigator.clipboard.writeText(value).then(() => {
    toast.success(t('calendarView.meetingLinkCopiedToClipboard'))
  }).catch(() => {
    // Fallback for older browsers
    const textarea = document.createElement('textarea')
    textarea.value = value
    document.body.appendChild(textarea)
    textarea.select()
    document.execCommand('copy')
    document.body.removeChild(textarea)
    toast.success(t('calendarView.meetingLinkCopiedToClipboard'))
  })
}

function copyMeetingLink() {
  copyToClipboard(meetingLink.value)
}

function copyAdminMeetingLink() {
  copyToClipboard(adminMeetingLink.value)
}

// Open a meeting link directly — ALWAYS in a new window/tab so the
// app (calendar, email, chat) is never replaced by the call screen.
function openMeetingUrl(link) {
  if (!link) return
  try {
    const u = new URL(link, window.location.origin)
    window.open(u.href, '_blank', 'noopener')
  } catch (_) {
    window.open(link, '_blank', 'noopener')
  }
}

function joinMeetingAsParticipant() {
  openMeetingUrl(meetingLink.value)
}

function startMeetingAsHost() {
  openMeetingUrl(adminMeetingLink.value || meetingLink.value)
}

// Revoke the current meeting links and mint fresh ones, applying the
// waiting-room / workshop settings chosen in the modal. This is the
// "cancel & recreate" flow — the old link stops working immediately.
async function recreateMeetingLink() {
  if (!editingEvent.value?.id || recreatingMeeting.value) return
  recreatingMeeting.value = true
  try {
    const result = await calendar.addMeetingToEvent(editingEvent.value.id, {
      force: true,
      waiting_room: !!eventForm.value.waiting_room,
      participants_hidden: !!eventForm.value.participants_hidden,
    })
    if (result.success && result.data) {
      meetingLink.value = result.data.meeting_link || null
      adminMeetingLink.value = result.data.admin_meeting_link || null
      editingEvent.value = {
        ...editingEvent.value,
        is_meeting: true,
        meeting_token: result.data.meeting_token || editingEvent.value.meeting_token,
        meeting_link: meetingLink.value,
      }
      toast.success(t('calendarView.meetingLinkRecreated'))
    } else {
      toast.error(result.error || t('calendarView.failedToCreateMeeting'))
    }
  } catch (err) {
    console.error('[CalendarView] recreateMeetingLink failed:', err)
    toast.error(t('calendarView.failedToCreateMeeting'))
  } finally {
    recreatingMeeting.value = false
  }
}

// Turn the meeting back into a plain event (revokes the links).
async function removeMeetingFromEditingEvent() {
  if (!editingEvent.value?.id || removingMeeting.value) return
  removingMeeting.value = true
  try {
    const result = await calendar.removeMeetingFromEvent(editingEvent.value.id)
    if (result.success) {
      meetingLink.value = null
      adminMeetingLink.value = null
      editingEvent.value = {
        ...editingEvent.value,
        is_meeting: false,
        meeting_link: null,
      }
      eventForm.value.is_meeting = false
      toast.success(t('calendarView.meetingRemoved'))
      await calendar.fetchEvents(null, null, { force: true })
    } else {
      toast.error(result.error || t('calendarView.failedToSaveEvent'))
    }
  } catch (err) {
    console.error('[CalendarView] removeMeetingFromEditingEvent failed:', err)
    toast.error(t('calendarView.failedToSaveEvent'))
  } finally {
    removingMeeting.value = false
  }
}

function openGuestJoinFromMeeting() {
  // The organizer joins via the host (admin) link when available so they
  // land in the room as host; otherwise fall back to the guest link.
  // Always opens in a new window so the calendar stays put.
  const m = adminMeetingLink.value || meetingLink.value || ''
  if (m) {
    openMeetingUrl(m)
    return
  }
  if (editingEvent.value?.meeting_token) {
    openMeetingUrl(`/meet/${editingEvent.value.meeting_token}`)
  }
}

// Upgrade the event currently open in the edit modal into an online
// meeting. Used when the event was imported from an .ics invite or
// otherwise created without the "online meeting" toggle. Idempotent
// server-side: re-clicking just returns the existing links.
async function addOnlineMeetingToEditingEvent() {
  if (!editingEvent.value?.id || addingMeeting.value) return
  addingMeeting.value = true
  try {
    const result = await calendar.addMeetingToEvent(editingEvent.value.id, {
      waiting_room: !!eventForm.value.waiting_room,
      participants_hidden: !!eventForm.value.participants_hidden,
    })
    if (result.success && result.data) {
      meetingLink.value = result.data.meeting_link || null
      adminMeetingLink.value = result.data.admin_meeting_link || null
      // Reflect new meeting state on the in-memory editing event so
      // the "Add online meeting" button hides and the meeting link
      // panel + "Join meeting" footer button appear immediately.
      editingEvent.value = {
        ...editingEvent.value,
        is_meeting: true,
        meeting_token: result.data.meeting_token || editingEvent.value.meeting_token,
        meeting_link: meetingLink.value,
        meeting_conversation_id: result.data.conversation_id || editingEvent.value.meeting_conversation_id,
      }
      eventForm.value.is_meeting = true
      toast.success(t('calendarView.meetingScheduled'))
      // Force refresh so the calendar grid shows the meeting indicator
      await calendar.fetchEvents(null, null, { force: true })
    } else {
      toast.error(result.error || t('calendarView.failedToCreateMeeting'))
    }
  } catch (err) {
    console.error('[CalendarView] addOnlineMeetingToEditingEvent failed:', err)
    toast.error(t('calendarView.failedToCreateMeeting'))
  } finally {
    addingMeeting.value = false
  }
}

// Clean all events
async function cleanAllEvents() {
  cleaningAllEvents.value = true
  
  const result = await calendar.deleteAllEvents()
  
  if (result.success) {
    toast.success(t('calendarView.deletedResultcountEvents', { count: result.count }))
  } else {
    toast.error(result.error || t('calendarView.failedToDeleteEvents'))
  }
  
  cleaningAllEvents.value = false
  showCleanAllConfirm.value = false
}

// Day view
function openDayView(day) {
  selectedDay.value = day
  showDayModal.value = true
}

onMounted(async () => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  await calendar.fetchCalendars()
  // Use quiet mode to prevent flash when switching views
  await calendar.fetchEvents(null, null, { quiet: true })
  
  // Fetch clients for the client selector
  if (!clientsStore.clients.length) {
    clientsStore.fetchClients()
  }
  
  // Start auto-sync if enabled
  if (calendar.autoSyncEnabled) {
    calendar.startAutoSync()
  }
  
  // Check for pending event from email list
  if (calendar.pendingEventData) {
    openModalWithPendingEvent()
  }
  
  // Check for event parameter from search results or embed card clicks
  const eventId = route.query.event
  if (eventId) {
    await nextTick()
    const event = calendar.events.find(e => e.id === parseInt(eventId))
    if (event) {
      // Navigate to the event's date
      calendar.currentDate = new Date(event.start_time)
      await nextTick()
      // Only open edit modal for own events; participant events just navigate to the date
      if (!event.is_participant_event) {
        openEditEventModal(event)
      }
      // Clear the query param
      router.replace({ path: '/calendar' })
    }
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
  // Phase 3.5: stop the auto-sync interval that startAutoSync() set up in
  // onMounted. Without this, leaving the calendar view (e.g. navigating to
  // inbox) leaves the setInterval running forever - and every poll spins
  // up a fresh fetchEvents + N sync API calls. Compounding tabs/tab swaps
  // produced visible CPU + network usage in the audit.
  calendar.stopAutoSync()
})

// Watch for pending event data (when navigating from email date click)
watch(() => calendar.pendingEventData, (data) => {
  if (data) {
    openModalWithPendingEvent()
  }
})

// Open event modal with pending event data from another view
function openModalWithPendingEvent() {
  const data = calendar.pendingEventData
  if (!data) return
  
  editingEvent.value = null
  
  _suppressFormWatchers = true
  eventForm.value = {
    title: data.title || '',
    description: data.description || '',
    location: data.location || '',
    start_date: data.start_date,
    start_time: data.start_time || '09:00',
    end_date: data.end_date,
    end_time: data.end_time || '10:00',
    all_day: data.all_day || false,
    calendar_id: calendar.defaultCalendar?.id,
    client_id: data.client_id || null,
    board_id: null,
    card_id: null,
    recurrence: 'none',
    participants: [],
    linked_message_id: data.linked_message_id,
    linked_email_subject: data.linked_email_subject,
    linked_email_sender: data.linked_email_sender,
    linked_email_folder: data.linked_email_folder,
  }
  nextTick(() => { _suppressFormWatchers = false })
  participantInput.value = ''
  
  showEventModal.value = true
  
  // Clear the pending data
  calendar.clearPendingEvent()
}
</script>

<template>
  <div class="h-[100dvh] bg-surface-50 dark:bg-surface-900 flex flex-col ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="calendar"
      icon="calendar_month"
      :title="$t('calendarView.calendar')"
      :show-mobile-menu="isMobile"
      @toggle-sidebar="toggleSidebar"
    >
      <template v-if="!isMobile" #title-badge>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>
    
    <!-- Main content area with sidebar -->
    <div class="flex-1 flex overflow-hidden relative">
      <!-- Calendar sidebar (desktop only) -->
      <aside
        v-if="!isMobile"
        class="sidebar-container w-64 flex-shrink-0"
      >
        <CalendarSidebar @close="closeSidebar" />
      </aside>
      
      <!-- Main calendar area -->
      <div class="flex-1 flex flex-col overflow-hidden">
    <!-- Toolbar -->
    <div class="flex items-center justify-between px-4 md:px-6 py-3 md:py-4 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 flex-shrink-0">
      <!-- Navigation -->
      <div class="flex items-center gap-3">
        <button @click="calendar.goToToday()" class="btn-secondary btn-sm">
          {{ $t('calendarTodayPanel.today') }}
        </button>
        <div class="flex items-center gap-1">
          <button @click="calendar.navigatePrevious()" class="btn-ghost btn-icon btn-sm">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <button @click="calendar.navigateNext()" class="btn-ghost btn-icon btn-sm">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
        <h2 class="text-sm md:text-lg font-semibold text-surface-900 dark:text-surface-100 truncate max-w-[120px] md:max-w-none">
          {{ calendar.viewMode === 'day' ? dayViewHeader : calendar.viewMode === 'week' ? weekViewHeader : calendar.monthName }}
        </h2>
      </div>
      
      <!-- View Mode Toggle -->
      <div class="flex items-center gap-0.5 md:gap-1 bg-surface-100 dark:bg-surface-700 rounded-lg p-0.5 md:p-1">
        <button 
          @click="calendar.setViewMode('day')"
          :class="[
            'px-2 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
            calendar.viewMode === 'day' 
              ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' 
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
        >
          <span class="hidden md:inline">{{ $t('calendarView.day') }}</span>
          <span class="md:hidden">{{ $t('calendarView.dayShort') }}</span>
        </button>
        <button 
          @click="calendar.setViewMode('week')"
          :class="[
            'px-2 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
            calendar.viewMode === 'week' 
              ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' 
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
        >
          <span class="hidden md:inline">{{ $t('calendarView.week') }}</span>
          <span class="md:hidden">{{ $t('calendarView.weekShort') }}</span>
        </button>
        <button 
          @click="calendar.setViewMode('month')"
          :class="[
            'px-2 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
            calendar.viewMode === 'month' 
              ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' 
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
        >
          <span class="hidden md:inline">{{ $t('calendarView.month') }}</span>
          <span class="md:hidden">{{ $t('calendarView.monthShort') }}</span>
        </button>
      </div>
      
      <!-- Quick Add (hidden on mobile) -->
      <div class="hidden md:flex items-center gap-2 flex-1 max-w-md mx-8">
        <div class="relative flex-1">
          <input 
            v-model="quickAddText"
            type="text"
            class="input pl-10 text-sm"
            :placeholder="$t('calendarView.quickAddMeetingTomorrowAt')"
            @keyup.enter="quickAdd"
          />
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">add</span>
        </div>
        <button @click="quickAdd" class="h-10 w-10 btn-primary rounded-full flex items-center justify-center" :disabled="addingEvent || !quickAddText.trim()">
          <span v-if="addingEvent" class="spinner w-4 h-4"></span>
          <span v-else class="material-symbols-rounded">add</span>
        </button>
      </div>
      
      <!-- Action Buttons (hidden on mobile - using FAB instead) -->
      <div class="hidden md:flex items-center gap-2">
        <!-- Subscribe Button - Copy URL -->
        <button 
          @click="copySubscriptionUrl" 
          :disabled="copyingSubscription"
          class="h-10 flex items-center gap-2 px-4 rounded-full bg-blue-100 text-blue-600 font-medium text-sm hover:bg-blue-500 hover:text-white dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-600 dark:hover:text-white transition-colors disabled:opacity-50"
          :title="$t('calendarView.copySubscriptionUrlForIphoneexternal')"
        >
          <span v-if="copyingSubscription" class="spinner w-4 h-4"></span>
          <span v-else class="material-symbols-rounded text-lg">link</span>
          <span>{{ $t('calendarView.subscribe') }}</span>
        </button>
        
        <!-- Clean All Events -->
        <button 
          @click="showCleanAllConfirm = true" 
          class="h-10 flex items-center gap-2 px-4 rounded-full bg-red-100 text-red-600 font-medium text-sm hover:bg-red-500 hover:text-white dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-600 dark:hover:text-white transition-colors"
        >
          <span class="material-symbols-rounded text-lg">delete_sweep</span>
          <span>{{ $t('calendarView.clearAll') }}</span>
        </button>
        
        <!-- New Event -->
        <button @click="openNewEventModal()" class="h-10 btn-primary btn-sm">
          <span class="material-symbols-rounded">add</span>
          {{ $t('calendarView.newEvent') }}
        </button>
      </div>
    </div>
    
    <!-- Feature Guide -->
    <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />
    
    <!-- Calendar Grid -->
    <div class="relative flex-1 overflow-auto p-2 md:p-6">
      <!-- Loading overlay - doesn't push content -->
      <div v-if="calendar.loading" class="absolute inset-0 z-30 flex items-center justify-center bg-white/70 dark:bg-surface-900/70 backdrop-blur-sm">
        <span class="spinner text-primary-500 w-8 h-8"></span>
      </div>
      
      <!-- Month View - Mobile (iOS-style continuous scroll) -->
      <div v-if="calendar.viewMode === 'month' && isMobile" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <!-- Week days header (sticky) -->
        <div class="grid grid-cols-7 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 sticky top-0 z-10">
          <div 
            v-for="(day, i) in weekDayNarrow"
            :key="i"
            class="py-2 text-center text-xs font-medium text-surface-500"
          >
            {{ day }}
          </div>
        </div>
        
        <!-- Continuous scrolling months -->
        <div class="overflow-y-auto max-h-[calc(100vh-220px)]">
          <div v-for="month in continuousMonthsData" :key="`${month.year}-${month.monthIndex}`">
            <!-- Month header -->
            <div class="px-3 py-2 text-lg font-bold text-surface-900 dark:text-surface-100 sticky top-0 bg-white/95 dark:bg-surface-800/95 backdrop-blur-sm z-[5] border-b border-surface-100 dark:border-surface-700">
              {{ month.name }}
              <span v-if="month.year !== new Date().getFullYear()" class="text-surface-500 font-normal ml-1">{{ month.year }}</span>
            </div>
            
            <!-- Weeks -->
            <div v-for="(week, weekIndex) in month.weeks" :key="weekIndex" class="grid grid-cols-7 border-b border-surface-100 dark:border-surface-700/50">
              <div 
                v-for="(day, dayIndex) in week" 
                :key="dayIndex"
                @click="openDayView(day)"
                :class="[
                  'min-h-[72px] p-1 cursor-pointer transition-colors relative',
                  day.isCurrentMonth ? 'bg-white dark:bg-surface-800' : 'bg-surface-50 dark:bg-surface-850',
                  !day.isToday && 'active:bg-surface-100 dark:active:bg-surface-700'
                ]"
              >
                <!-- Day number -->
                <div class="flex justify-center mb-1">
                  <span 
                    :class="[
                      'w-8 h-8 flex items-center justify-center rounded-full text-base font-medium',
                      day.isToday ? 'bg-red-500 text-white' : (day.isCurrentMonth ? 'text-surface-900 dark:text-surface-100' : 'text-surface-400')
                    ]"
                  >
                    {{ day.day }}
                  </span>
                </div>
                
                <!-- Event indicators -->
                <div class="space-y-0.5 px-0.5">
                  <!-- Show up to 2 event labels -->
                  <template v-for="(event, eventIndex) in day.events.slice(0, 2)" :key="event.virtual_id || event.id">
                    <div 
                      class="text-[10px] leading-tight px-1 py-0.5 rounded truncate font-medium"
                      :style="{ 
                        backgroundColor: getEventColor(event),
                        color: 'white'
                      }"
                    >
                      {{ getShortEventLabel(event) }}
                    </div>
                  </template>
                  <!-- Show dots for additional events -->
                  <div v-if="day.events.length > 2" class="flex justify-center gap-0.5 pt-0.5">
                    <span 
                      v-for="event in day.events.slice(2, 5)" 
                      :key="event.virtual_id || event.id"
                      class="w-1.5 h-1.5 rounded-full"
                      :style="{ backgroundColor: getEventColor(event) }"
                    ></span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Month View - Desktop (original grid) -->
      <div v-else-if="calendar.viewMode === 'month' && !isMobile" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <!-- Week days header -->
        <div class="grid grid-cols-7 border-b border-surface-200 dark:border-surface-700">
          <div 
            v-for="(day, i) in weekDayNames" 
            :key="day"
            class="py-2 md:py-3 text-center text-xs md:text-sm font-medium text-surface-500 border-r border-surface-200 dark:border-surface-700 last:border-r-0"
          >
            {{ day }}
          </div>
        </div>
        
        <!-- Calendar days -->
        <div class="grid grid-cols-7">
          <div 
            v-for="(day, index) in calendar.monthDays" 
            :key="index"
            @click="openDayView(day)"
            @dragover="onDragOver($event, day)"
            @dragleave="onDragLeave"
            @drop="onDropOnDay($event, day)"
            :class="[
              'min-h-[120px] p-2 border-r border-b border-surface-200 dark:border-surface-700 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors',
              { 'bg-surface-100 dark:bg-surface-850': !day.isCurrentMonth },
              { 'last:border-r-0': (index + 1) % 7 === 0 },
              { 'ring-2 ring-inset ring-primary-500': day.isToday },
              { 'bg-primary-100 dark:bg-primary-500/20': dragOverDay === day.date }
            ]"
          >
            <!-- Day number -->
            <div class="flex items-center justify-between mb-1">
              <span 
                :class="[
                  'w-7 h-7 flex items-center justify-center rounded-full text-sm font-medium',
                  day.isToday ? 'bg-primary-500 text-white' : (day.isCurrentMonth ? 'text-surface-900 dark:text-surface-100' : 'text-surface-400')
                ]"
              >
                {{ day.date.getDate() }}
              </span>
            </div>
            
            <!-- Events for this day -->
            <div class="space-y-1">
              <div 
                v-for="event in calendar.getEventsForDay(day.date).slice(0, 3)" 
                :key="event.virtual_id || event.id"
                draggable="true"
                @dragstart="onDragStart($event, event)"
                @dragend="onDragEnd"
                @click.stop="openEditEventModal(event)"
                @contextmenu="showContextMenu($event, event)"
                :style="{ backgroundColor: getEventColor(event) + '20', borderLeftColor: getEventColor(event) }"
                class="px-2 py-0.5 rounded text-xs truncate border-l-2 cursor-grab hover:opacity-80 flex items-center gap-1 active:cursor-grabbing"
              >
                <span v-if="isSyncedEvent(event)" class="material-symbols-rounded text-xs flex-shrink-0" :style="{ color: getEventColor(event) }" :title="$t('calendarView.syncedFromGoogleCalendar')">sync</span>
                <span v-if="event.recurrence" class="material-symbols-rounded text-xs flex-shrink-0" :style="{ color: getEventColor(event) }" :title="$t('calendarView.recurringEvent')">repeat</span>
                <span v-if="event.is_shared_event" class="material-symbols-rounded text-xs flex-shrink-0" :style="{ color: getEventColor(event) }" :title="$t('calendarView.sharedByEventsharedby', { sharedBy: event.shared_by })">people</span>
                <span class="font-medium" :style="{ color: getEventColor(event) }">
                  {{ formatEventTime(event) }}
                </span>
                <span class="ml-1 text-surface-700 dark:text-surface-300 truncate">{{ event.title }}</span>
              </div>
              <div 
                v-if="calendar.getEventsForDay(day.date).length > 3"
                class="text-xs text-surface-500 px-2"
              >
                {{ $t('calendarView.moreCount', { count: calendar.getEventsForDay(day.date).length - 3 }) }}
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Week View -->
      <div v-else-if="calendar.viewMode === 'week'" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <div class="overflow-x-auto">
          <div class="min-w-[700px]">

            <!-- Week header (same column template as time grid) -->
            <div class="grid border-b border-surface-200 dark:border-surface-700" style="grid-template-columns: 4rem repeat(7, 1fr);">
              <div class="py-2 md:py-3 text-center text-xs font-medium text-surface-400 border-r border-surface-200 dark:border-surface-700"></div>
              <div
                v-for="(day, dayIdx) in weekViewDays"
                :key="day.date.toISOString()"
                :class="[
                  'py-2 md:py-3 text-center',
                  dayIdx < weekViewDays.length - 1 ? 'border-r border-surface-200 dark:border-surface-700' : '',
                  day.isToday ? 'bg-primary-50 dark:bg-primary-500/10' : ''
                ]"
              >
                <div class="text-xs text-surface-500">{{ day.dayName }}</div>
                <div :class="['text-lg font-semibold mt-0.5', day.isToday ? 'text-primary-500' : 'text-surface-900 dark:text-surface-100']">
                  {{ day.day }}
                </div>
                <div class="text-xs text-surface-400">{{ day.monthName }}</div>
              </div>
            </div>

            <!-- All-day events row (same column template) -->
            <div class="grid border-b border-surface-200 dark:border-surface-700 min-h-[40px]" style="grid-template-columns: 4rem repeat(7, 1fr);">
              <div class="py-1 px-2 text-xs text-surface-400 border-r border-surface-200 dark:border-surface-700 flex items-center">
                {{ $t('calendarView.allDay') }}
              </div>
              <div
                v-for="(day, dayIdx) in weekViewDays"
                :key="'allday-' + day.date.toISOString()"
                @dragover="onDragOver($event, day)"
                @dragleave="onDragLeave"
                @drop="onDropOnDay($event, day)"
                :class="[
                  'py-1 px-1',
                  dayIdx < weekViewDays.length - 1 ? 'border-r border-surface-200 dark:border-surface-700' : '',
                  { 'bg-primary-100 dark:bg-primary-500/20': dragOverDay === day.date }
                ]"
              >
                <div
                  v-for="event in getAllDayEvents(day)"
                  :key="event.virtual_id || event.id"
                  draggable="true"
                  @dragstart="onDragStart($event, event)"
                  @dragend="onDragEnd"
                  @click="openEditEventModal(event)"
                  @contextmenu="showContextMenu($event, event)"
                  :style="{ backgroundColor: getEventColor(event) + '20', borderLeftColor: getEventColor(event) }"
                  class="px-1.5 py-0.5 mb-0.5 rounded text-xs truncate border-l-2 cursor-grab hover:opacity-80 active:cursor-grabbing"
                >
                  <span class="font-medium" :style="{ color: getEventColor(event) }">{{ event.title }}</span>
                </div>
              </div>
            </div>

            <!-- Time grid: ONE unified grid - labels, hour cells, and events all share the same rows -->
            <div class="week-view-grid overflow-y-auto" style="max-height: calc(100vh - 320px);">
              <div class="grid" style="grid-template-columns: 4rem repeat(7, 1fr); grid-template-rows: repeat(24, 3rem);">

                <!-- Time labels: column 1, each in its own grid row -->
                <div
                  v-for="(hour, hourIdx) in hours"
                  :key="'time-' + hour"
                  :style="{ gridColumn: '1', gridRow: String(hourIdx + 1) }"
                  class="border-r border-surface-200 dark:border-surface-700 border-b border-surface-100 px-2 text-right"
                >
                  <span class="text-xs text-surface-400 -mt-2 block">
                    {{ hour === 0 ? '12 AM' : hour < 12 ? `${hour} AM` : hour === 12 ? '12 PM' : `${hour - 12} PM` }}
                  </span>
                </div>

                <!-- Day hour cells: columns 2-8, each in its own grid row (clickable / droppable) -->
                <template v-for="(day, dayIdx) in weekViewDays" :key="'daycol-' + day.date.toISOString()">
                  <div
                    v-for="(hour, hourIdx) in hours"
                    :key="'cell-' + hourIdx"
                    :style="{ gridColumn: String(dayIdx + 2), gridRow: String(hourIdx + 1) }"
                    @click="openNewEventModalForTime(day.date, hour)"
                    @dragover="onDragOver($event, day); onDragOverHour($event, hour)"
                    @dragleave="onDragLeave; onDragLeaveHour"
                    @drop="onDropOnHour($event, hour); selectedDay = day"
                    :class="[
                      'border-b border-surface-100 dark:border-surface-700 hover:bg-surface-100 dark:hover:bg-surface-700/50 cursor-pointer transition-colors',
                      dayIdx < weekViewDays.length - 1 ? 'border-r border-surface-200 dark:border-surface-700' : '',
                      day.isToday ? 'bg-primary-50/50 dark:bg-primary-500/5' : '',
                      { 'bg-primary-100 dark:bg-primary-500/20': dragOverHour === hour && dragOverDay === day.date }
                    ]"
                  ></div>
                </template>

                <!-- Event overlays: one per day, spanning ALL 24 grid rows -->
                <div
                  v-for="(day, dayIdx) in weekViewDays"
                  :key="'events-' + day.date.toISOString()"
                  :style="{ gridColumn: String(dayIdx + 2), gridRow: '1 / -1', zIndex: 1 }"
                  class="relative pointer-events-none"
                >
                  <div
                    v-for="event in day.events.filter(e => !e.all_day)"
                    :key="event.virtual_id || event.id"
                    draggable="true"
                    @dragstart="onDragStart($event, event)"
                    @dragend="onDragEnd"
                    @click.stop="openEditEventModal(event)"
                    @contextmenu="showContextMenu($event, event)"
                    :style="{
                      ...getEventStyle(event),
                      backgroundColor: getEventColor(event) + '20',
                      borderLeftColor: getEventColor(event),
                      left: '2px',
                      right: '2px'
                    }"
                    class="absolute px-1.5 py-0.5 rounded text-xs border-l-2 cursor-grab hover:opacity-80 overflow-hidden pointer-events-auto active:cursor-grabbing"
                  >
                    <div class="font-medium truncate" :style="{ color: getEventColor(event) }">
                      {{ formatEventTime(event) }}
                    </div>
                    <div class="truncate text-surface-700 dark:text-surface-300">{{ event.title }}</div>
                  </div>
                </div>

              </div>
            </div>

          </div>
        </div>
      </div><!-- End week view -->
      
      <!-- Day View -->
      <div v-else-if="calendar.viewMode === 'day'" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <!-- Day header -->
        <div 
          class="p-4 md:p-6 text-center border-b border-surface-200 dark:border-surface-700"
          :class="{ 'bg-primary-500/10': dayViewData.isToday }"
        >
          <div class="text-sm text-surface-500 dark:text-surface-400">
            {{ dayViewData.dayName }}
          </div>
          <div 
            :class="[
              'text-4xl md:text-5xl font-bold mt-1',
              dayViewData.isToday ? 'text-primary-500' : 'text-surface-900 dark:text-surface-100'
            ]"
          >
            {{ dayViewData.day }}
          </div>
        </div>
        
        <!-- All-day events -->
        <div v-if="dayViewData.allDayEvents.length > 0" class="p-3 md:p-4 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900/50">
          <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2">{{ t('calendarView.allDayHeader') }}</h4>
          <div class="space-y-2">
            <div
              v-for="event in dayViewData.allDayEvents"
              :key="event.virtual_id || event.id"
              draggable="true"
              @dragstart="onDragStart($event, event)"
              @dragend="onDragEnd"
              @click="openEditEventModal(event)"
              @contextmenu="showContextMenu($event, event)"
              class="px-3 py-2 rounded-lg cursor-grab hover:opacity-80 transition-colors border-l-4 active:cursor-grabbing"
              :style="{ 
                backgroundColor: getEventColor(event) + '20', 
                borderLeftColor: getEventColor(event) 
              }"
            >
              <div class="font-medium" :style="{ color: getEventColor(event) }">{{ event.title }}</div>
              <div v-if="event.location" class="text-xs text-surface-500 mt-0.5 flex items-center gap-1">
                <span class="material-symbols-rounded text-xs">location_on</span>
                {{ event.location }}
              </div>
            </div>
          </div>
        </div>
        
        <!-- Timed events with hour slots for drop -->
        <div class="p-3 md:p-4">
          <h4 v-if="dayViewData.events.length > 0" class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-3">Events</h4>
          
          <!-- Hour slots for dropping events -->
          <div class="relative">
            <div class="space-y-2">
              <div
                v-for="event in dayViewData.events"
                :key="event.virtual_id || event.id"
                draggable="true"
                @dragstart="onDragStart($event, event)"
                @dragend="onDragEnd"
                @click="openEditEventModal(event)"
                @contextmenu="showContextMenu($event, event)"
                class="p-3 rounded-xl cursor-grab hover:shadow-md transition-all border-l-4 bg-surface-50 dark:bg-surface-700/50 active:cursor-grabbing"
                :style="{ borderLeftColor: getEventColor(event) }"
              >
                <div class="flex items-start gap-3">
                  <div class="text-center flex-shrink-0 w-14">
                    <div class="text-sm font-semibold" :style="{ color: getEventColor(event) }">
                      {{ formatEventTime(event) }}
                    </div>
                    <div v-if="event.end_time" class="text-xs text-surface-400">
                      {{ new Date(event.end_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                    </div>
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1.5">
                      {{ event.title }}
                      <span 
                        v-if="event.is_shared_event" 
                        class="inline-flex items-center gap-0.5 text-[10px] px-1.5 py-0.5 rounded-full font-normal"
                        :style="{ backgroundColor: getEventColor(event) + '20', color: getEventColor(event) }"
                      >
                        <span class="material-symbols-rounded text-xs">people</span>
                        {{ event.shared_by }}
                      </span>
                    </div>
                    <div v-if="event.description" class="text-sm text-surface-500 mt-1 line-clamp-2">{{ event.description }}</div>
                    <div v-if="event.location" class="text-xs text-surface-500 mt-1 flex items-center gap-1">
                      <span class="material-symbols-rounded text-xs">location_on</span>
                      {{ event.location }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Drop zones for hour-based repositioning -->
            <div v-if="draggedEvent" class="absolute inset-0 bg-surface-100/80 dark:bg-surface-700/80 rounded-lg">
              <div class="grid grid-cols-4 gap-1 p-2 h-full">
                <div 
                  v-for="hour in [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19]" 
                  :key="hour"
                  @dragover="onDragOverHour($event, hour)"
                  @dragleave="onDragLeaveHour"
                  @drop="onDropOnHour($event, hour)"
                  :class="[
                    'flex items-center justify-center text-xs font-medium rounded transition-colors',
                    dragOverHour === hour ? 'bg-primary-500 text-white' : 'bg-white dark:bg-surface-600 text-surface-600 dark:text-surface-300 hover:bg-primary-100 dark:hover:bg-primary-500/30'
                  ]"
                >
                  {{ formatHourLabel(hour) }}
                </div>
              </div>
            </div>
          </div>
          
          <!-- Empty state -->
          <div v-if="dayViewData.events.length === 0 && dayViewData.allDayEvents.length === 0" class="text-center py-12">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">event_available</span>
            <p class="text-surface-500 mt-3">{{ $t('calendarView.noEventsOnThisDay') }}</p>
            <button @click="openNewEventModal()" class="btn-primary btn-sm mt-4">
              <span class="material-symbols-rounded text-lg">add</span>
              {{ $t('calendarView.addEvent') }}
            </button>
          </div>
        </div>
      </div><!-- End day view -->
    </div><!-- End calendar grid area -->
      </div><!-- End main calendar area -->
      
      <!-- Today Panel (right sidebar) - desktop only -->
      <aside 
        v-if="showTodayPanel && !isMobile"
        class="w-72 flex-shrink-0 hidden lg:block"
      >
        <CalendarTodayPanel 
          @event-click="(event) => event ? openEditEventModal(event) : openNewEventModal()" 
          @collapse="showTodayPanel = false"
        />
      </aside>

      <!-- Reopen rail when the Today panel is collapsed - desktop only -->
      <button
        v-if="!showTodayPanel && !isMobile"
        @click="showTodayPanel = true"
        class="hidden lg:flex flex-shrink-0 w-9 flex-col items-center pt-4 border-l border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors"
        :title="$t('calendarView.toggleTodayPanel')"
      >
        <span class="material-symbols-rounded text-surface-500">calendar_today</span>
      </button>
    </div><!-- End main content area with sidebar -->

    <!-- Mobile Calendar Bottom Sheet -->
    <Teleport to="body">
      <Transition name="cal-sheet">
        <div
          v-if="isMobile && sidebarOpen"
          class="fixed inset-0 z-[60] bg-black/40"
          @click.self="closeSidebar"
        >
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[85vh] overflow-hidden flex flex-col" style="-webkit-overflow-scrolling: touch;">
            <!-- Drag handle -->
            <div class="flex justify-center pt-3 pb-1 flex-shrink-0">
              <div class="w-10 h-1 bg-surface-300 dark:bg-surface-600 rounded-full"></div>
            </div>

            <!-- Embed CalendarSidebar (its modals teleport to body, so they still work) -->
            <div class="flex-1 overflow-y-auto">
              <CalendarSidebar @close="closeSidebar" class="!h-auto !border-r-0 !pb-6" />
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Event Modal -->
    <Teleport to="body">
      <Transition
        :enter-active-class="isMobile ? 'transition-transform duration-300 ease-out' : 'transition-all duration-200'"
        :leave-active-class="isMobile ? 'transition-transform duration-200 ease-in' : 'transition-all duration-150'"
        :enter-from-class="isMobile ? 'translate-y-full' : 'opacity-0 scale-95'"
        :leave-to-class="isMobile ? 'translate-y-full' : 'opacity-0 scale-95'"
      >
      <div v-if="showEventModal" :class="['modal-overlay', { 'items-end p-0': isMobile }]" @click.self="showEventModal = false">
        <div 
          :class="['modal', isMobile ? 'w-full h-[95vh] max-w-none rounded-t-2xl rounded-b-none m-0 flex flex-col' : 'max-w-2xl']"
          :style="{ 
            '--event-color': eventModalColor,
            backgroundImage: isMobile ? undefined : `linear-gradient(135deg, ${eventModalColor}10 0%, transparent 50%)`
          }"
        >
          <!-- Mobile header -->
          <div v-if="isMobile" class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
            <button @click="showEventModal = false" class="p-1 -ml-1 text-surface-500">
              <span class="material-symbols-rounded text-2xl">close</span>
            </button>
            <div class="flex-1 text-center">
              <h3 class="font-semibold">
                {{ editingEvent ? $t('calendarView.editEvent') : (eventForm.is_meeting ? $t('calendarView.newMeeting') : $t('calendarView.newEvent')) }}
              </h3>
              <p 
                v-if="editingEvent?.is_shared_event" 
                class="text-[10px] mt-0.5 flex items-center justify-center gap-1"
                :style="{ color: editingEvent.calendar_color || '#3b82f6' }"
              >
                <span class="material-symbols-rounded text-xs">people</span>
                {{ $t('calendarView.sharedByEventsharedby', { sharedBy: editingEvent.shared_by }) }}
              </p>
            </div>
            <button @click="saveEvent" class="text-primary-500 font-medium">{{ eventForm.is_meeting && !editingEvent ? $t('calendarView.schedule') : $t('calendarView.save') }}</button>
          </div>
          <!-- Desktop header -->
          <div v-else class="modal-header" :style="{ borderBottomColor: eventModalColor + '25' }">
            <div class="flex items-center gap-3">
              <span 
                class="w-3.5 h-3.5 rounded-full flex-shrink-0 ring-2 ring-offset-2 ring-offset-white dark:ring-offset-surface-800"
                :style="{ backgroundColor: eventModalColor, ringColor: eventModalColor + '60' }"
              ></span>
              <h3 class="font-semibold">
                {{ editingEvent ? $t('calendarView.editEvent') : (eventForm.is_meeting ? $t('calendarView.newMeeting') : $t('calendarView.newEvent')) }}
              </h3>
              <span 
                v-if="editingEvent && isSyncedEvent(editingEvent)" 
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-teal-100 dark:bg-teal-500/20 text-teal-700 dark:text-teal-400"
              >
                <span class="material-symbols-rounded text-xs">sync</span>
                {{ $t('calendarView.googleCalendar') }}
              </span>
              <span 
                v-if="editingEvent?.is_shared_event" 
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs"
                :style="{ backgroundColor: (editingEvent.calendar_color || '#3b82f6') + '20', color: editingEvent.calendar_color || '#3b82f6' }"
              >
                <span class="material-symbols-rounded text-xs">people</span>
                {{ $t('calendarView.sharedByEventsharedby', { sharedBy: editingEvent.shared_by }) }}
              </span>
            </div>
            <button @click="showEventModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div :class="isMobile ? 'flex-1 overflow-y-auto overflow-x-hidden p-4 space-y-3' : 'modal-body space-y-5'">
            
            <!-- === SECTION 1: Title & Calendar === -->
            <div class="space-y-3">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">{{ $t('calendarView.title') }}</label>
                <input v-model="eventForm.title" type="text" class="input w-full text-base" :placeholder="$t('calendarView.eventTitle')" />
              </div>
              
              <!-- Calendar selector -->
              <div v-if="calendar.calendars.length > 0">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">{{ $t('calendarView.calendar') }}</label>
                <div 
                  v-if="editingEvent?.is_shared_event" 
                  class="flex items-center gap-2 px-3 py-2 rounded-lg border flex-wrap"
                  :style="{ backgroundColor: (editingEvent.calendar_color || '#3b82f6') + '10', borderColor: (editingEvent.calendar_color || '#3b82f6') + '40' }"
                >
                  <span class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: editingEvent.calendar_color || '#3b82f6' }"></span>
                  <span class="text-sm font-medium truncate" :style="{ color: editingEvent.calendar_color || '#3b82f6' }">
                    {{ editingEvent.shared_calendar_name || $t('calendarView.sharedCalendar') }}
                  </span>
                  <span class="text-xs text-surface-400 ml-auto">{{ $t('calendarView.fromSharedBy', { sharedBy: editingEvent.shared_by }) }}</span>
                </div>
                <div v-else class="flex flex-wrap gap-1.5">
                  <button
                    v-for="cal in calendar.calendars"
                    :key="cal.id"
                    @click="eventForm.calendar_id = cal.id"
                    :class="['flex items-center gap-2 px-3 py-2 rounded-lg border-2 transition-all', eventForm.calendar_id === cal.id ? 'shadow-sm' : 'border-surface-200 dark:border-surface-600 hover:border-surface-300']"
                    :style="eventForm.calendar_id === cal.id ? { borderColor: calendar.getCalendarColor(cal), backgroundColor: calendar.getCalendarColor(cal) + '12' } : {}"
                  >
                    <span class="w-3 h-3 rounded-full" :style="{ backgroundColor: calendar.getCalendarColor(cal) }"></span>
                    <span class="text-sm" :class="eventForm.calendar_id === cal.id ? 'font-medium' : ''">{{ cal.name }}</span>
                  </button>
                </div>
              </div>
            </div>
            
            <!-- === SECTION 2: Date & Time === -->
            <div 
              class="rounded-xl p-3 sm:p-4 space-y-3 border"
              :style="{ backgroundColor: eventModalColor + '06', borderColor: eventModalColor + '15' }"
            >
              <!-- Dates row -->
              <div class="flex gap-4" style="overflow: hidden;">
                <div style="width: calc(50% - 0.5rem);">
                  <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ $t('calendarView.startDate') }}</label>
                  <input v-model="eventForm.start_date" type="date" class="input w-full text-sm" style="-webkit-appearance: none; max-width: 100%; box-sizing: border-box;" />
                </div>
                <div style="width: calc(50% - 0.5rem);">
                  <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ $t('calendarView.endDate') }}</label>
                  <input v-model="eventForm.end_date" type="date" class="input w-full text-sm" style="-webkit-appearance: none; max-width: 100%; box-sizing: border-box;" />
                </div>
              </div>
              
              <!-- Times row -->
              <div v-if="!eventForm.all_day" class="flex gap-4" style="overflow: hidden;">
                <div style="width: calc(50% - 0.5rem);">
                  <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ $t('calendarView.startTime') }}</label>
                  <input v-model="eventForm.start_time" type="time" class="input w-full text-sm" style="-webkit-appearance: none; max-width: 100%; box-sizing: border-box;" />
                </div>
                <div style="width: calc(50% - 0.5rem);">
                  <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">{{ $t('calendarView.endTime') }}</label>
                  <input v-model="eventForm.end_time" type="time" class="input w-full text-sm" style="-webkit-appearance: none; max-width: 100%; box-sizing: border-box;" />
                </div>
              </div>
              
              <!-- All day + Repeat row -->
              <div class="flex items-center gap-4 pt-1">
                <div class="flex items-center gap-2">
                  <button
                    @click="eventForm.all_day = !eventForm.all_day"
                    :class="['w-10 h-5 rounded-full transition-colors relative shrink-0', eventForm.all_day ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                  >
                    <span :class="['absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', eventForm.all_day ? 'translate-x-5' : 'translate-x-0']"></span>
                  </button>
                  <span class="text-xs text-surface-600 dark:text-surface-400">{{ $t('calendarView.allDay') }}</span>
                </div>
                <div class="flex-1">
                  <select v-model="eventForm.recurrence" class="input w-full text-xs !py-1.5">
                    <option v-for="opt in recurrenceOptions" :key="opt.value" :value="opt.value">
                      {{ opt.label }}
                    </option>
                  </select>
                </div>
              </div>
            </div>
            
            <!-- === SECTION 3: Details === -->
            <div class="space-y-3">
              <div v-if="!eventForm.is_meeting">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                  <span class="material-symbols-rounded text-sm align-middle mr-1">location_on</span>
                  {{ $t('calendarView.location') }}
                </label>
                <input v-model="eventForm.location" type="text" class="input w-full text-sm" :placeholder="$t('calendarView.addLocation')" />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                  <span class="material-symbols-rounded text-sm align-middle mr-1">notes</span>
                  {{ $t('calendarView.description') }}
                </label>
                <textarea v-model="eventForm.description" rows="2" class="input w-full text-sm" :placeholder="$t('calendarView.addDescription')"></textarea>
              </div>
            </div>

            <!-- === SECTION 4: Meeting === -->
            <!-- New event: toggle to create as an online meeting -->
            <div v-if="!editingEvent" class="flex items-center gap-3 py-2 px-3 rounded-xl bg-surface-50 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600">
              <span class="material-symbols-rounded text-primary-500 text-xl flex-shrink-0">videocam</span>
              <div class="flex-1 min-w-0">
                <label class="text-sm font-medium text-surface-700 dark:text-surface-300 cursor-pointer" @click="eventForm.is_meeting = !eventForm.is_meeting">
                  {{ $t('calendarView.scheduleAsOnlineMeeting') }}
                </label>
                <p class="text-xs text-surface-400 mt-0.5">{{ $t('calendarView.createsAMeetingLinkAnd') }}</p>
              </div>
              <button
                type="button"
                @click="eventForm.is_meeting = !eventForm.is_meeting"
                :class="['relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none',
                         eventForm.is_meeting ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span
                  :class="['pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                           eventForm.is_meeting ? 'translate-x-5' : 'translate-x-0']"
                />
              </button>
            </div>

            <!-- Editing existing event that has NO online meeting yet:
                 offer to upgrade it (mint meeting token + guest/admin
                 links + chat conversation). Works for events imported
                 from .ics invites, copied from Google Calendar, or
                 anything created without the meeting toggle. -->
            <div
              v-if="editingEvent && !meetingLink"
              class="space-y-2"
            >
              <button
                type="button"
                :disabled="addingMeeting"
                @click="addOnlineMeetingToEditingEvent"
                class="w-full flex items-center gap-3 py-2.5 px-3 rounded-xl bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/30 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors disabled:opacity-60 disabled:cursor-not-allowed text-left"
              >
                <span class="material-symbols-rounded text-primary-500 text-xl flex-shrink-0">
                  {{ addingMeeting ? 'progress_activity' : 'videocam' }}
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-primary-700 dark:text-primary-300">
                    {{ addingMeeting ? $t('calendarView.creatingMeeting') : $t('calendarView.addOnlineMeeting') }}
                  </p>
                  <p class="text-xs text-primary-600/70 dark:text-primary-400/70 mt-0.5">
                    {{ $t('calendarView.generatesAMeetingLinkForThisEvent') }}
                  </p>
                </div>
                <span v-if="!addingMeeting" class="material-symbols-rounded text-primary-500">add</span>
              </button>
              <MeetingSettingsToggles
                v-model:waiting-room="eventForm.waiting_room"
                v-model:participants-hidden="eventForm.participants_hidden"
                size="md"
                layout="column"
                :waiting-room-label="$t('calendarView.waitingRoom')"
                :workshop-mode-label="$t('calendarView.workshopMode')"
                class="py-1 px-1"
              />
            </div>

            <MeetingSettingsToggles
              v-if="eventForm.is_meeting && !editingEvent"
              v-model:waiting-room="eventForm.waiting_room"
              v-model:participants-hidden="eventForm.participants_hidden"
              size="md"
              layout="column"
              :waiting-room-label="$t('calendarView.waitingRoom')"
              :workshop-mode-label="$t('calendarView.workshopMode')"
              class="py-1"
            />
            
            <!-- Existing meeting: links + management -->
            <div v-if="meetingLink && editingEvent" class="space-y-2">
              <!-- Invite (guest) link — share with participants -->
              <div class="flex items-center gap-2 py-2 px-3 rounded-xl bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/30">
                <span class="material-symbols-rounded text-primary-500">group</span>
                <div class="flex-1 min-w-0">
                  <p class="text-xs text-surface-500 mb-0.5">{{ $t('calendarView.inviteLinkForParticipants') }}</p>
                  <p class="text-sm text-primary-600 dark:text-primary-400 truncate">{{ meetingLink }}</p>
                </div>
                <button
                  type="button"
                  @click="copyMeetingLink"
                  class="flex-shrink-0 p-1.5 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors"
                  :title="$t('calendarView.copyLink')"
                >
                  <span class="material-symbols-rounded text-primary-500 text-lg">content_copy</span>
                </button>
                <button
                  v-if="!adminMeetingLink"
                  type="button"
                  @click="joinMeetingAsParticipant"
                  class="flex-shrink-0 inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium bg-primary-500 hover:bg-primary-600 text-white transition-colors"
                  :title="$t('calendarView.joinMeeting')"
                >
                  <span class="material-symbols-rounded text-sm">videocam</span>
                  {{ $t('calendarView.join') }}
                </button>
              </div>

              <!-- Host (admin) link — organizer joins as host -->
              <div v-if="adminMeetingLink" class="flex items-center gap-2 py-2 px-3 rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30">
                <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">shield_person</span>
                <div class="flex-1 min-w-0">
                  <p class="text-xs text-amber-700/80 dark:text-amber-300/80 mb-0.5">{{ $t('calendarView.hostLinkKeepPrivate') }}</p>
                  <p class="text-sm text-amber-700 dark:text-amber-300 truncate">{{ adminMeetingLink }}</p>
                </div>
                <button
                  type="button"
                  @click="copyAdminMeetingLink"
                  class="flex-shrink-0 p-1.5 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-500/20 transition-colors"
                  :title="$t('calendarView.copyHostLink')"
                >
                  <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-lg">content_copy</span>
                </button>
                <button
                  type="button"
                  @click="startMeetingAsHost"
                  class="flex-shrink-0 inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium bg-amber-500 hover:bg-amber-600 text-white transition-colors"
                  :title="$t('meetingActions.openAdminLinkTitle')"
                >
                  <span class="material-symbols-rounded text-sm">videocam</span>
                  {{ $t('calendarView.startMeeting') }}
                </button>
              </div>

              <!-- Waiting room / workshop toggles (applied on recreate) -->
              <MeetingSettingsToggles
                v-model:waiting-room="eventForm.waiting_room"
                v-model:participants-hidden="eventForm.participants_hidden"
                size="md"
                layout="column"
                :waiting-room-label="$t('calendarView.waitingRoom')"
                :workshop-mode-label="$t('calendarView.workshopMode')"
                class="py-1 px-1"
              />

              <!-- Recreate / remove actions -->
              <div class="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  :disabled="recreatingMeeting || removingMeeting"
                  @click="recreateMeetingLink"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                >
                  <span class="material-symbols-rounded text-base" :class="{ 'animate-spin': recreatingMeeting }">autorenew</span>
                  {{ recreatingMeeting ? $t('calendarView.creatingMeeting') : $t('calendarView.recreateMeetingLink') }}
                </button>
                <button
                  type="button"
                  :disabled="recreatingMeeting || removingMeeting"
                  @click="removeMeetingFromEditingEvent"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                >
                  <span class="material-symbols-rounded text-base">videocam_off</span>
                  {{ $t('calendarView.removeMeeting') }}
                </button>
              </div>
              <p class="text-xs text-surface-400 px-1">{{ $t('calendarView.recreateMeetingHint') }}</p>
            </div>
            
            <!-- === SECTION 5: Participants === -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                <span class="material-symbols-rounded text-sm align-middle mr-1">group</span>
                {{ $t('calendarView.participants') }}
                <span class="font-normal text-surface-500">({{ eventForm.participants.length }})</span>
              </label>
              
              <!-- Two-column: picker + manual input -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-2">
                <!-- Team Members & Groups Multi-selector -->
                <div class="relative">
                  <button
                    type="button"
                    @click="showParticipantPicker = !showParticipantPicker"
                    class="w-full px-3 py-2 text-left border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 flex items-center justify-between hover:border-surface-400 dark:hover:border-surface-500 transition-colors"
                  >
                    <span class="text-sm text-surface-500">{{ $t('calendarView.addTeamMembersOrGroups') }}</span>
                    <span class="material-symbols-rounded text-surface-400">{{ showParticipantPicker ? 'expand_less' : 'expand_more' }}</span>
                  </button>
                  
                  <!-- Dropdown -->
                  <div 
                    v-if="showParticipantPicker"
                    class="absolute z-20 w-full mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-xl overflow-hidden"
                  >
                    <div class="p-2 border-b border-surface-200 dark:border-surface-600">
                      <input
                        v-model="participantSearch"
                        type="text"
                        :placeholder="$t('calendarView.searchColleaguesOrGroups')"
                        class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-600 border-0 rounded-lg text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none"
                      />
                    </div>
                    
                    <div class="max-h-60 overflow-y-auto">
                      <div v-if="filteredGroupsForEvent.length > 0" class="p-2 border-b border-surface-200 dark:border-surface-600">
                        <p class="px-2 py-1 text-xs font-semibold text-surface-400 uppercase">{{ $t('calendarView.groups') }}</p>
                        <button
                          v-for="group in filteredGroupsForEvent"
                          :key="'group-' + group.id"
                          @click="addGroupToEvent(group.id)"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-50 dark:hover:bg-surface-600 rounded-lg flex items-center gap-3"
                        >
                          <div 
                            class="w-8 h-8 rounded-lg flex items-center justify-center"
                            :style="{ backgroundColor: (group.color || '#6366f1') + '20', color: group.color || '#6366f1' }"
                          >
                            <span class="material-symbols-rounded text-sm">{{ group.icon || 'group' }}</span>
                          </div>
                          <div class="flex-1 min-w-0">
                            <p class="font-medium text-surface-900 dark:text-surface-100">{{ group.name }}</p>
                            <p class="text-xs text-surface-500">{{ $t('calendarView.membersCount', { count: group.member_count || 0 }) }}</p>
                          </div>
                          <span class="material-symbols-rounded text-primary-500">add</span>
                        </button>
                      </div>
                      
                      <div v-if="filteredColleaguesForEvent.length > 0" class="p-2">
                        <p class="px-2 py-1 text-xs font-semibold text-surface-400 uppercase">{{ $t('calendarView.teamMembers') }}</p>
                        <button
                          v-for="colleague in filteredColleaguesForEvent"
                          :key="'colleague-' + colleague.id"
                          @click="addColleagueToEvent(colleague)"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-surface-50 dark:hover:bg-surface-600 rounded-lg flex items-center gap-3"
                        >
                          <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center text-primary-600 dark:text-primary-400 font-medium text-sm">
                            {{ (colleague.display_name || colleague.email).charAt(0).toUpperCase() }}
                          </div>
                          <div class="flex-1 min-w-0">
                            <p class="font-medium text-surface-900 dark:text-surface-100">{{ colleague.display_name || colleague.email.split('@')[0] }}</p>
                            <p class="text-xs text-surface-500 truncate">{{ colleague.email }}</p>
                          </div>
                          <span class="material-symbols-rounded text-primary-500">add</span>
                        </button>
                      </div>
                      
                      <div v-if="filteredGroupsForEvent.length === 0 && filteredColleaguesForEvent.length === 0" class="p-4 text-center text-sm text-surface-500">
                        {{ $t('calendarView.noMatchingColleaguesOrGroupsFound') }}
                      </div>
                    </div>
                  </div>
                </div>
                
                <!-- Manual email input -->
                <div class="flex gap-2">
                  <input 
                    v-model="participantInput"
                    type="email"
                    class="input flex-1 min-w-0 text-sm"
                    :placeholder="$t('calendarView.orEnterExternalEmail')"
                    @keyup.enter="addParticipant"
                  />
                  <button 
                    @click="addParticipant" 
                    class="btn-secondary btn-sm flex-shrink-0"
                    :disabled="!participantInput.trim()"
                  >
                    <span class="material-symbols-rounded">person_add</span>
                  </button>
                </div>
              </div>
              
              <!-- Participants list -->
              <div v-if="eventForm.participants.length > 0" class="space-y-1.5 max-h-40 overflow-y-auto">
                <div 
                  v-for="participant in eventForm.participants" 
                  :key="participant.email"
                  class="flex items-center justify-between p-2 rounded-lg bg-surface-50 dark:bg-surface-700/50"
                >
                  <div class="flex items-center gap-2 min-w-0 flex-1">
                    <span class="material-symbols-rounded text-surface-400 flex-shrink-0">person</span>
                    <span class="text-sm truncate">{{ participant.email }}</span>
                    <span 
                      v-if="participant.status === 'accepted'"
                      class="px-1.5 py-0.5 text-xs rounded bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 flex-shrink-0"
                    >
                      {{ $t('calendarView.accepted') }}
                    </span>
                    <span 
                      v-else-if="participant.status === 'declined'"
                      class="px-1.5 py-0.5 text-xs rounded bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 flex-shrink-0"
                    >
                      {{ $t('calendarView.declined') }}
                    </span>
                    <span 
                      v-else
                      class="px-1.5 py-0.5 text-xs rounded bg-surface-200 text-surface-600 dark:bg-surface-600 dark:text-surface-300 flex-shrink-0"
                    >
                      {{ $t('calendarView.pending') }}
                    </span>
                  </div>
                  <button 
                    @click="removeParticipant(participant.email)" 
                    class="btn-ghost btn-icon-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex-shrink-0"
                  >
                    <span class="material-symbols-rounded text-lg">close</span>
                  </button>
                </div>
              </div>
              
              <!-- Send invites button -->
              <button 
                v-if="editingEvent && eventForm.participants.some(p => p.status === 'pending')"
                @click="sendInvites"
                class="btn-secondary btn-sm w-full mt-3"
                :disabled="sendingInvites"
              >
                <span v-if="sendingInvites" class="spinner w-4 h-4"></span>
                <span v-else class="material-symbols-rounded">send</span>
                {{ $t('calendarView.sendInvitations') }}
              </button>
            </div>
            
            <!-- === SECTION 6: Client (optional) === -->
            <div v-if="clientsStore.clients.length > 0">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                <span class="material-symbols-rounded text-sm align-middle mr-1">business</span>
                {{ $t('calendarView.linkToClient') }}
                <span class="text-xs text-surface-400 font-normal ml-1">{{ $t('calendarView.optional') }}</span>
              </label>
              <select v-model="eventForm.client_id" class="input w-full text-sm">
                <option :value="null">{{ $t('calendarView.noClientLinked') }}</option>
                <option v-for="client in clientsStore.clients" :key="client.id" :value="client.id">
                  {{ client.display_name || client.domain }}
                </option>
              </select>
              <p class="text-xs text-surface-400 mt-1">{{ $t('calendarView.linkingHelpsTrackTimeSpent') }}</p>
            </div>

            <!-- === SECTION 7: Board (optional, shown when client selected) === -->
            <div v-if="eventForm.client_id && clientBoards.length > 0">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                <span class="material-symbols-rounded text-sm align-middle mr-1">view_kanban</span>
                Track to Board
                <span class="text-xs text-surface-400 font-normal ml-1">optional</span>
              </label>
              <select v-model="eventForm.board_id" class="input w-full text-sm">
                <option :value="null">No board linked</option>
                <option v-for="board in clientBoards" :key="board.id || board.board_id" :value="board.id || board.board_id">
                  {{ board.name || board.board_name }}
                </option>
              </select>
              <p class="text-xs text-surface-400 mt-1">Time will be tracked per participant to this board</p>
            </div>

            <div
              v-if="editingEvent && editingEvent.time_bridged_at"
              class="flex items-center gap-1.5 px-3 py-2 rounded-lg bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400 text-xs font-medium"
            >
              <span class="material-symbols-rounded text-sm">schedule</span>
              Team time logged
            </div>
          </div>
          
          <!-- Mobile footer -->
          <div v-if="isMobile && editingEvent" class="flex-shrink-0 p-4 border-t border-surface-200 dark:border-surface-700">
            <button @click="confirmDeleteEvent(editingEvent)" class="btn-ghost text-red-500 w-full justify-center">
              <span class="material-symbols-rounded">delete</span>
              {{ $t('calendarView.deleteEvent') }}
            </button>
          </div>
          <!-- Desktop footer -->
          <div v-if="!isMobile" class="modal-footer" :style="{ borderTopColor: eventModalColor + '15' }">
            <div class="flex-1 flex items-center gap-2">
              <button 
                v-if="editingEvent" 
                @click="confirmDeleteEvent(editingEvent)" 
                class="btn-ghost text-red-500"
              >
                <span class="material-symbols-rounded">delete</span>
                {{ $t('calendarSidebar.delete') }}
              </button>
              <button
                v-if="editingEvent && meetingLink"
                type="button"
                @click="openGuestJoinFromMeeting"
                class="btn-ghost text-primary-500"
              >
                <span class="material-symbols-rounded">videocam</span>
                {{ $t('calendarView.joinMeeting') }}
              </button>
            </div>
            <button @click="showEventModal = false" class="btn-ghost">{{ $t('calendarView.cancel') }}</button>
            <button 
              @click="saveEvent" 
              class="btn-primary"
              :style="{ backgroundColor: eventModalColor, borderColor: eventModalColor }"
            >
              <span class="material-symbols-rounded">save</span>
              {{ editingEvent ? $t('calendarView.update') : (eventForm.is_meeting ? $t('calendarView.scheduleMeeting') : $t('calendarView.create')) }}
            </button>
          </div>
        </div>
      </div>
      </Transition>
    </Teleport>
    
    <!-- Day View Modal -->
    <Teleport to="body">
      <Transition
        :enter-active-class="isMobile ? 'transition-transform duration-300 ease-out' : 'transition-all duration-200'"
        :leave-active-class="isMobile ? 'transition-transform duration-200 ease-in' : 'transition-all duration-150'"
        :enter-from-class="isMobile ? 'translate-y-full' : 'opacity-0 scale-95'"
        :leave-to-class="isMobile ? 'translate-y-full' : 'opacity-0 scale-95'"
      >
      <div v-if="showDayModal && selectedDay" :class="['modal-overlay', { 'items-end p-0': isMobile }]" @click.self="showDayModal = false">
        <div :class="['modal', isMobile ? 'w-full max-h-[80vh] max-w-none rounded-t-2xl rounded-b-none m-0 flex flex-col' : 'max-w-md']">
          <!-- Mobile header -->
          <div v-if="isMobile" class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
            <button @click="showDayModal = false" class="p-1 -ml-1 text-surface-500">
              <span class="material-symbols-rounded text-2xl">close</span>
            </button>
            <h3 class="font-semibold flex-1 text-center truncate">
              {{ selectedDay.date.toLocaleDateString(localeTag, { weekday: 'short', month: 'short', day: 'numeric' }) }}
            </h3>
            <button @click="openNewEventModal(selectedDay.date); showDayModal = false" class="text-primary-500 p-1 -mr-1">
              <span class="material-symbols-rounded text-2xl">add</span>
            </button>
          </div>
          <!-- Desktop header -->
          <div v-else class="modal-header">
            <h3 class="font-semibold">
              {{ selectedDay.date.toLocaleDateString(localeTag, { weekday: 'long', month: 'long', day: 'numeric' }) }}
            </h3>
            <button @click="showDayModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div :class="isMobile ? 'flex-1 overflow-y-auto p-4' : 'modal-body'">
            <div v-if="calendar.getEventsForDay(selectedDay.date).length === 0" class="text-center py-8 text-surface-500">
              <span class="material-symbols-rounded text-4xl mb-2">event_busy</span>
              <p>{{ $t('calendarView.noEventsThisDay') }}</p>
            </div>
            
            <div v-else class="space-y-2">
              <div 
                v-for="event in calendar.getEventsForDay(selectedDay.date)" 
                :key="event.virtual_id || event.id"
                @click="openEditEventModal(event); showDayModal = false"
                class="p-3 rounded-lg cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors border-l-4"
                :style="{ borderLeftColor: getEventColor(event) }"
              >
                <div class="flex items-center gap-2">
                  <p class="font-medium text-surface-900 dark:text-surface-100 flex-1">{{ event.title }}</p>
                  <span 
                    v-if="isSyncedEvent(event)" 
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-teal-100 dark:bg-teal-500/20 text-teal-700 dark:text-teal-400"
                  >
                    <span class="material-symbols-rounded text-xs">sync</span>
                    Synced
                  </span>
                </div>
                <p class="text-sm text-surface-500">
                  {{ formatEventTime(event) }}
                  <span v-if="!event.all_day"> - {{ formatTime(event.end_time) }}</span>
                </p>
                <p v-if="event.location" class="text-sm text-surface-500 flex items-center gap-1 mt-1">
                  <span class="material-symbols-rounded text-sm">location_on</span>
                  {{ event.location }}
                </p>
              </div>
            </div>
          </div>
          
          <!-- Desktop footer only -->
          <div v-if="!isMobile" class="modal-footer">
            <button @click="openNewEventModal(selectedDay.date); showDayModal = false" class="btn-primary w-full">
              <span class="material-symbols-rounded">add</span>
              {{ $t('calendarView.addEvent') }}
            </button>
          </div>
        </div>
      </div>
      </Transition>
    </Teleport>
    
    <!-- Delete Confirmation -->
    <ConfirmModal
      :show="showDeleteConfirm"
      :title="$t('calendarView.deleteEvent')"
      :message="$t('calendarView.confirmDeleteEventMessage', { title: eventToDelete?.title || '' })"
      :confirm-text="$t('calendarSidebar.delete')"
      type="danger"
      @confirm="deleteEvent"
      @cancel="showDeleteConfirm = false; eventToDelete = null"
    />
    
    <!-- Clean All Events Confirmation -->
    <ConfirmModal
      :show="showCleanAllConfirm"
      :title="$t('calendarView.deleteAllEvents')"
      :message="$t('calendarView.confirmDeleteAllEventsMessage')"
      :confirm-text="$t('calendarView.deleteAllEvents')"
      type="danger"
      :loading="cleaningAllEvents"
      @confirm="cleanAllEvents"
      @cancel="showCleanAllConfirm = false"
    />
    
    <!-- Meeting Link Dialog (shared component, also used by chat NewConversationModal) -->
    <MeetingLinkDialog
      :show="showMeetingLinkDialog"
      :meeting-link="meetingLink"
      :admin-meeting-link="adminMeetingLink"
      :meeting-title="createdMeetingTitle"
      @close="showMeetingLinkDialog = false"
    />
    
    <!-- Context Menu -->
    <Teleport to="body">
      <div 
        v-if="contextMenu.show" 
        class="fixed inset-0 z-50"
        @click="hideContextMenu"
        @contextmenu.prevent="hideContextMenu"
      >
        <div 
          class="fixed bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[160px] overflow-hidden"
          :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
          @click.stop
        >
          <button 
            @click="contextMenuEdit"
            class="w-full px-4 py-2 text-left text-sm flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-lg text-surface-500">edit</span>
            <span class="text-surface-700 dark:text-surface-300">{{ $t('calendarView.editEvent') }}</span>
          </button>
          <button 
            @click="contextMenuDelete"
            class="w-full px-4 py-2 text-left text-sm flex items-center gap-3 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors"
          >
            <span class="material-symbols-rounded text-lg text-red-500">delete</span>
            <span class="text-red-600 dark:text-red-400">{{ $t('calendarView.deleteEvent') }}</span>
          </button>
        </div>
      </div>
    </Teleport>
    
    <!-- Subscription URL Modal (for iOS fallback) -->
    <Teleport to="body">
      <div v-if="showSubscriptionModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="showSubscriptionModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl max-w-md w-full p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-white">{{ $t('calendarView.calendarSubscriptionUrl') }}</h3>
            <button @click="showSubscriptionModal = false" class="text-surface-400 hover:text-surface-600">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <p class="text-sm text-surface-600 dark:text-surface-400 mb-4">
            {{ $t('calendarView.subscriptionUrlInstructions') }}
          </p>
          
          <div class="relative">
            <input 
              type="text" 
              :value="subscriptionUrl" 
              readonly 
              class="input w-full pr-12 text-sm font-mono"
              @focus="$event.target.select()"
            />
            <button 
              @click="copyFromModal"
              class="absolute right-2 top-1/2 -translate-y-1/2 p-2 text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg"
            >
              <span class="material-symbols-rounded text-xl">content_copy</span>
            </button>
          </div>
          
          <p class="text-xs text-surface-500 mt-3">
            {{ $t('calendarView.subscriptionUrlTip') }}
          </p>
        </div>
      </div>
    </Teleport>
    
    <!-- Mobile bottom navigation -->
    <MobileBottomNav v-if="isMobile" :show-todo-button="false" />
    
    <!-- Mobile FAB: Add event -->
    <button 
      v-if="isMobile"
      @click="openNewEventModal()"
      class="fixed bottom-24 right-4 z-40 w-14 h-14 rounded-full bg-primary-500 text-white flex items-center justify-center shadow-lg hover:bg-primary-600 transition-colors"
    >
      <span class="material-symbols-rounded text-2xl">add</span>
    </button>

    <StepGuide
      v-if="showStepGuide"
      :title-key="calendarGuide.titleKey"
      :subtitle-key="calendarGuide.subtitleKey"
      :header-icon="calendarGuide.headerIcon"
      :header-color="calendarGuide.headerColor"
      :storage-key="calendarGuide.storageKey"
      :steps="calendarGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<style scoped>
.cal-sheet-enter-active {
  transition: opacity 0.2s ease;
}
.cal-sheet-enter-active > div:last-child {
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.cal-sheet-leave-active {
  transition: opacity 0.15s ease;
}
.cal-sheet-leave-active > div:last-child {
  transition: transform 0.2s ease-in;
}
.cal-sheet-enter-from { opacity: 0; }
.cal-sheet-enter-from > div:last-child { transform: translateY(100%); }
.cal-sheet-leave-to { opacity: 0; }
.cal-sheet-leave-to > div:last-child { transform: translateY(100%); }
</style>
