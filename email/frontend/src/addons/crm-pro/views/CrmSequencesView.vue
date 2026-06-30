<script setup>
/**
 * CrmSequencesView - Manage email sequences (drip campaigns)
 * List sequences, step editor (full-page), enrollment viewer.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import AppHeader from '@/components/shared/AppHeader.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import CrmSidebar from '../components/CrmSidebar.vue'
import CrmSequenceStepEditor from '../components/CrmSequenceStepEditor.vue'
import CrmSequencesGuide from '../components/CrmSequencesGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'

const router = useRouter()
const toast = useToastStore()
const loading = ref(true)
const sequences = ref([])
const showEditor = ref(false)
const editingSequence = ref(null)
const showEnrollments = ref(false)
const enrollmentsData = ref([])
const enrollmentSequenceId = ref(null)
const showGuide = ref(false)

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  fetchData()
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/crm/sequences')
    if (res.data?.success) sequences.value = res.data.data?.sequences || []
  } catch (e) {
    toast.error('Failed to load sequences')
  } finally {
    loading.value = false
  }
}

function openNewSequence() {
  editingSequence.value = null
  showEditor.value = true
}

function openEditSequence(seq) {
  editingSequence.value = { ...seq }
  showEditor.value = true
}

async function onSequenceSaved() {
  showEditor.value = false
  editingSequence.value = null
  await fetchData()
  toast.success('Sequence saved')
}

function closeEditor() {
  showEditor.value = false
  editingSequence.value = null
}

async function deleteSequence(seq) {
  if (!confirm(`Delete sequence "${seq.name}"?`)) return
  try {
    await api.delete(`/crm/sequences/${seq.id}`)
    await fetchData()
    toast.success('Sequence deleted')
  } catch (e) {
    toast.error('Failed to delete sequence')
  }
}

async function viewEnrollments(seq) {
  enrollmentSequenceId.value = seq.id
  try {
    const res = await api.get(`/crm/sequences/${seq.id}/enrollments`)
    if (res.data?.success) enrollmentsData.value = res.data.data?.enrollments || []
    showEnrollments.value = true
  } catch (e) {
    toast.error('Failed to load enrollments')
  }
}

async function cancelEnrollment(enrollment) {
  try {
    await api.post(`/crm/sequences/enrollments/${enrollment.id}/cancel`)
    viewEnrollments({ id: enrollmentSequenceId.value })
    toast.success('Enrollment cancelled')
  } catch (e) {
    toast.error('Failed to cancel enrollment')
  }
}

function formatDate(d) {
  if (!d) return '--'
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const stageLabels = {
  lead: 'Lead', contacted: 'Contacted', proposal: 'Proposal',
  negotiation: 'Negotiation', won: 'Won', lost: 'Lost',
}

const statusColors = {
  active: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
  completed: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400',
  cancelled: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400',
  paused: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400',
}
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <AppHeader
      current-view="crm-sequences"
      icon="timeline"
      title="Deal Follow-ups"
    >
      <template #title-badge>
        <ViewInfoButton view-key="crmSequences" />
        <HowItWorksButton @click="showGuide = true" />
      </template>
    </AppHeader>

    <div :class="isMobile ? 'flex-1 flex flex-col' : 'flex-1 flex overflow-hidden'">
      <CrmSidebar />

      <div :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 flex flex-col overflow-hidden'">

    <!-- Editor mode: full-page form -->
    <template v-if="showEditor">
      <CrmSequenceStepEditor
        :sequence="editingSequence"
        @saved="onSequenceSaved"
        @close="closeEditor"
      />
    </template>

    <!-- List mode -->
    <template v-else>
      <!-- Sub-header -->
      <div class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))]">
        <p class="text-sm text-surface-500">Multi-step drip campaigns for automated follow-ups</p>
        <div class="flex items-center gap-2">
          <button @click="openNewSequence"
                  class="px-4 py-2 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium flex items-center gap-2 transition-colors">
            <span class="material-symbols-rounded text-lg">add</span>
            New Sequence
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex-1 flex items-center justify-center">
        <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
      </div>

      <div v-else class="flex-1 overflow-auto p-6">
        <!-- Sequences list -->
        <div v-if="sequences.length" class="space-y-4 max-w-4xl mx-auto">
          <div
            v-for="seq in sequences" :key="seq.id"
            class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5"
          >
            <div class="flex items-start justify-between mb-3">
              <div>
                <div class="flex items-center gap-2">
                  <h3 class="text-base font-semibold text-surface-900 dark:text-white">{{ seq.name }}</h3>
                  <span :class="['text-[10px] font-medium px-2 py-0.5 rounded-full', seq.is_active ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400' : 'bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] text-surface-500']">
                    {{ seq.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </div>
                <p v-if="seq.description" class="text-xs text-surface-400 mt-1">{{ seq.description }}</p>
              </div>
              <div class="flex items-center gap-1">
                <button @click="openEditSequence(seq)" class="p-1.5 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]">
                  <span class="material-symbols-rounded text-sm">edit</span>
                </button>
                <button @click="deleteSequence(seq)" class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                  <span class="material-symbols-rounded text-sm">delete</span>
                </button>
              </div>
            </div>

            <!-- Steps visual -->
            <div class="flex items-center gap-1 mb-3 overflow-x-auto py-1">
              <div
                v-for="(step, i) in seq.steps" :key="i"
                class="flex items-center gap-1 flex-shrink-0"
              >
                <div class="px-3 py-1.5 rounded-lg bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/30 text-xs">
                  <p class="font-medium text-primary-700 dark:text-primary-300">
                    Step {{ i + 1 }}
                  </p>
                  <p class="text-[10px] text-primary-500 dark:text-primary-400">
                    {{ step.delay_days > 0 ? `+${step.delay_days}d` : 'Immediate' }}
                  </p>
                </div>
                <span v-if="i < seq.steps.length - 1" class="material-symbols-rounded text-xs text-surface-300">chevron_right</span>
              </div>
            </div>

            <!-- Meta -->
            <div class="flex items-center gap-4 text-xs text-surface-400">
              <span v-if="seq.trigger_stage" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">bolt</span>
                Auto-starts on: {{ stageLabels[seq.trigger_stage] || seq.trigger_stage }}
              </span>
              <span class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">group</span>
                {{ seq.active_enrollments || 0 }} active / {{ seq.total_enrollments || 0 }} total
              </span>
              <button @click="viewEnrollments(seq)" class="flex items-center gap-1 text-primary-600 hover:text-primary-700 font-medium">
                <span class="material-symbols-rounded text-sm">visibility</span>
                View Enrollments
              </button>
            </div>
          </div>
        </div>

        <!-- Empty -->
        <div v-else class="text-center py-16 text-surface-400">
          <span class="material-symbols-rounded text-5xl">route</span>
          <p class="text-sm mt-3">No email sequences yet</p>
          <p class="text-xs mt-1">Create multi-step drip campaigns to automate follow-ups</p>
          <button @click="openNewSequence" class="mt-4 px-4 py-2 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
            Create Your First Sequence
          </button>
        </div>
      </div>
    </template>

    <!-- Enrollments Modal -->
    <Teleport to="body">
      <div v-if="showEnrollments" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showEnrollments = false">
        <div class="bg-white dark:bg-[rgb(var(--color-surface-elevated))] rounded-2xl shadow-xl w-full max-w-xl max-h-[80vh] flex flex-col" @click.stop>
          <div class="flex items-center justify-between p-5 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
            <h3 class="text-lg font-bold text-surface-900 dark:text-white">Enrollments</h3>
            <button @click="showEnrollments = false" class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]">
              <span class="material-symbols-rounded text-lg text-surface-400">close</span>
            </button>
          </div>
          <div class="flex-1 overflow-auto p-5">
            <div v-if="enrollmentsData.length" class="space-y-2">
              <div
                v-for="en in enrollmentsData" :key="en.id"
                class="flex items-center gap-3 p-3 rounded-lg border border-surface-200 dark:border-[rgb(var(--color-border))]"
              >
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-white truncate">
                    {{ en.client_name || en.client_domain || `Client #${en.client_id}` }}
                  </p>
                  <p v-if="en.deal_title" class="text-xs text-surface-400 truncate">Deal: {{ en.deal_title }}</p>
                  <p class="text-xs text-surface-400">
                    Step {{ en.current_step + 1 }} | Started {{ formatDate(en.started_at) }}
                    <span v-if="en.next_run_at"> | Next: {{ formatDate(en.next_run_at) }}</span>
                  </p>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                  <span :class="['text-[10px] font-medium px-2 py-0.5 rounded-full', statusColors[en.status] || 'bg-surface-100 text-surface-500']">
                    {{ en.status }}
                  </span>
                  <button v-if="en.status === 'active'" @click="cancelEnrollment(en)" class="p-1 rounded text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                    <span class="material-symbols-rounded text-sm">cancel</span>
                  </button>
                </div>
              </div>
            </div>
            <div v-else class="text-center py-8 text-surface-400">
              <span class="material-symbols-rounded text-3xl">group_off</span>
              <p class="text-sm mt-2">No enrollments yet</p>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

      </div><!-- end flex-1 content -->
    </div><!-- end flex sidebar+content -->

    <!-- Sequences Guide Modal -->
    <CrmSequencesGuide v-if="showGuide" @close="showGuide = false" />

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>
