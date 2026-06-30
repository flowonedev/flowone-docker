<script setup>
/**
 * CrmSequenceStepEditor - Full-page form for creating/editing email sequences
 * Multi-step builder with RichTextEditor for email body and template variables.
 */
import { ref, computed, watch, nextTick } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import RichTextEditor from '@/components/RichTextEditor.vue'

const props = defineProps({
  sequence: { type: Object, default: null },
})

const emit = defineEmits(['saved', 'close'])
const toast = useToastStore()
const saving = ref(false)
const expandedStep = ref(0) // Which step is expanded (shows the full editor)

const form = ref({
  name: '',
  description: '',
  trigger_stage: '',
  is_active: 1,
  steps: [{ delay_days: 0, subject: '', body: '', body_html: '', template_id: null }],
})

watch(() => props.sequence, (s) => {
  if (s) {
    form.value = {
      name: s.name || '',
      description: s.description || '',
      trigger_stage: s.trigger_stage || '',
      is_active: s.is_active ?? 1,
      steps: s.steps?.length
        ? JSON.parse(JSON.stringify(s.steps)).map(step => ({
            ...step,
            body_html: step.body_html || step.body || '',
          }))
        : [{ delay_days: 0, subject: '', body: '', body_html: '', template_id: null }],
    }
    expandedStep.value = 0
  }
}, { immediate: true })

const isEditing = computed(() => !!props.sequence?.id)

// Template variables
const templateVars = [
  { key: '{client_name}', label: 'Client Name', icon: 'person' },
  { key: '{client_email}', label: 'Client Email', icon: 'mail' },
  { key: '{client_domain}', label: 'Client Domain', icon: 'language' },
  { key: '{deal_title}', label: 'Deal Title', icon: 'handshake' },
  { key: '{deal_value}', label: 'Deal Value', icon: 'payments' },
  { key: '{deal_stage}', label: 'Deal Stage', icon: 'conversion_path' },
  { key: '{your_name}', label: 'Your Name', icon: 'badge' },
  { key: '{step_number}', label: 'Step Number', icon: 'format_list_numbered' },
  { key: '{today}', label: 'Today Date', icon: 'calendar_today' },
]

const stageOptions = [
  { value: '', label: 'Manual only (no auto-trigger)' },
  { value: 'lead', label: 'Lead' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'proposal', label: 'Proposal' },
  { value: 'negotiation', label: 'Negotiation' },
  { value: 'won', label: 'Won' },
]

function addStep() {
  const lastStep = form.value.steps[form.value.steps.length - 1]
  const newIndex = form.value.steps.length
  form.value.steps.push({
    delay_days: (lastStep?.delay_days || 1) + 2,
    subject: '',
    body: '',
    body_html: '',
    template_id: null,
  })
  expandedStep.value = newIndex
}

function removeStep(index) {
  if (form.value.steps.length <= 1) {
    toast.error('At least one step is required')
    return
  }
  form.value.steps.splice(index, 1)
  if (expandedStep.value >= form.value.steps.length) {
    expandedStep.value = form.value.steps.length - 1
  }
}

function moveStep(index, direction) {
  const newIndex = index + direction
  if (newIndex < 0 || newIndex >= form.value.steps.length) return
  const steps = form.value.steps
  ;[steps[index], steps[newIndex]] = [steps[newIndex], steps[index]]
  expandedStep.value = newIndex
}

function insertVarToSubject(stepIndex, varKey) {
  if (!form.value.steps[stepIndex].subject) form.value.steps[stepIndex].subject = ''
  form.value.steps[stepIndex].subject += varKey
}

function insertVarToBody(stepIndex, varKey) {
  const step = form.value.steps[stepIndex]
  const tag = `<span class="template-var" style="background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:4px;font-size:0.85em;font-weight:500;">${varKey}</span>&nbsp;`
  step.body_html = (step.body_html || '') + tag
}

function stripHtml(html) {
  const tmp = document.createElement('div')
  tmp.innerHTML = html || ''
  return tmp.textContent || tmp.innerText || ''
}

async function save() {
  if (!form.value.name.trim()) {
    toast.error('Sequence name is required')
    return
  }
  if (!form.value.steps.length) {
    toast.error('At least one step is required')
    return
  }

  // Convert body_html to plain text body for each step
  const payload = {
    ...form.value,
    steps: form.value.steps.map(step => ({
      ...step,
      body: stripHtml(step.body_html),
    })),
  }

  saving.value = true
  try {
    if (isEditing.value) {
      await api.put(`/crm/sequences/${props.sequence.id}`, payload)
    } else {
      await api.post('/crm/sequences', payload)
    }
    emit('saved')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save sequence')
  } finally {
    saving.value = false
  }
}

const totalDuration = computed(() => {
  return form.value.steps.reduce((max, step) => Math.max(max, step.delay_days || 0), 0)
})

const inputClass = 'w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-[rgb(var(--color-bg))] text-sm text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 outline-none transition-colors'
const labelClass = 'block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1.5'
const smallLabelClass = 'block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1'
</script>

<template>
  <div class="flex-1 overflow-auto">
    <!-- Top bar -->
    <div class="sticky top-0 z-10 flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))]">
      <div class="flex items-center gap-3">
        <button @click="emit('close')" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors">
          <span class="material-symbols-rounded text-xl text-surface-500">arrow_back</span>
        </button>
        <div>
          <h2 class="text-lg font-bold text-surface-900 dark:text-white">
            {{ isEditing ? 'Edit Email Sequence' : 'New Email Sequence' }}
          </h2>
          <p class="text-xs text-surface-400">{{ form.steps.length }} step{{ form.steps.length !== 1 ? 's' : '' }} over ~{{ totalDuration }} days</p>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <button @click="emit('close')" class="px-4 py-2 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors">Cancel</button>
        <button @click="save" :disabled="saving"
                class="px-6 py-2.5 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50 flex items-center gap-2 transition-colors">
          <span v-if="saving" class="material-symbols-rounded text-base animate-spin">progress_activity</span>
          {{ saving ? 'Saving...' : (isEditing ? 'Update Sequence' : 'Create Sequence') }}
        </button>
      </div>
    </div>

    <!-- Content -->
    <div class="max-w-4xl mx-auto p-6 space-y-6">

      <!-- Basic info -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div>
            <label :class="labelClass">Sequence Name *</label>
            <input v-model="form.name" placeholder="e.g. New Client Onboarding" :class="inputClass" />
          </div>
          <div>
            <label :class="labelClass">Description</label>
            <input v-model="form.description" placeholder="What is this sequence for?" :class="inputClass" />
          </div>
          <div>
            <label :class="labelClass">
              Auto-trigger Stage
              <span class="text-xs text-surface-400 font-normal">(optional)</span>
            </label>
            <select v-model="form.trigger_stage" :class="inputClass">
              <option v-for="s in stageOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
        </div>
        <p v-if="form.trigger_stage" class="text-[11px] text-surface-400 mt-2">
          When a deal enters the "{{ form.trigger_stage }}" stage, the client will automatically be enrolled in this sequence.
        </p>
      </div>

      <!-- Steps Builder -->
      <div class="space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">route</span>
            Sequence Steps
          </h3>

          <!-- Step mini-nav pills -->
          <div class="flex items-center gap-1">
            <button
              v-for="(step, i) in form.steps" :key="i"
              @click="expandedStep = i"
              :class="[
                'w-8 h-8 rounded-full text-xs font-bold flex items-center justify-center transition-all',
                expandedStep === i
                  ? 'bg-primary-600 text-white shadow-sm'
                  : 'bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] text-surface-500 hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))]'
              ]"
            >
              {{ i + 1 }}
            </button>
            <button @click="addStep" class="w-8 h-8 rounded-full bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] text-surface-400 hover:bg-primary-100 dark:hover:bg-primary-500/20 hover:text-primary-600 dark:hover:text-primary-400 flex items-center justify-center transition-colors">
              <span class="material-symbols-rounded text-lg">add</span>
            </button>
          </div>
        </div>

        <!-- Active step editor -->
        <div
          v-for="(step, i) in form.steps" :key="i"
          v-show="expandedStep === i"
          class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl border border-surface-200 dark:border-[rgb(var(--color-border))] overflow-hidden"
        >
          <!-- Step header -->
          <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
            <div class="flex items-center gap-3">
              <span class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center text-sm font-bold text-primary-600 dark:text-primary-400">
                {{ i + 1 }}
              </span>
              <div>
                <h4 class="text-sm font-semibold text-surface-900 dark:text-white">Step {{ i + 1 }}</h4>
                <p class="text-[11px] text-surface-400">{{ step.delay_days > 0 ? `Sends ${step.delay_days} day${step.delay_days !== 1 ? 's' : ''} after enrollment` : 'Sends immediately' }}</p>
              </div>
            </div>
            <div class="flex items-center gap-1">
              <button v-if="i > 0" @click="moveStep(i, -1)" class="p-1.5 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]" title="Move up">
                <span class="material-symbols-rounded text-sm">arrow_upward</span>
              </button>
              <button v-if="i < form.steps.length - 1" @click="moveStep(i, 1)" class="p-1.5 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]" title="Move down">
                <span class="material-symbols-rounded text-sm">arrow_downward</span>
              </button>
              <button @click="removeStep(i)" class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10" title="Remove step">
                <span class="material-symbols-rounded text-sm">delete</span>
              </button>
            </div>
          </div>

          <!-- Step config row -->
          <div class="px-5 py-4 grid grid-cols-1 sm:grid-cols-3 gap-4 border-b border-surface-100 dark:border-[rgb(var(--color-border))]">
            <div>
              <label :class="smallLabelClass">Delay (days from enrollment)</label>
              <input v-model.number="step.delay_days" type="number" min="0" max="365" :class="inputClass" />
            </div>
            <div>
              <label :class="smallLabelClass">Email Subject</label>
              <input v-model="step.subject" placeholder="Follow-up email subject" :class="inputClass" />
            </div>
            <div>
              <label :class="smallLabelClass">Template ID <span class="text-surface-400 font-normal">(optional)</span></label>
              <input v-model.number="step.template_id" type="number" min="0" placeholder="Leave empty for custom" :class="inputClass" />
            </div>
          </div>

          <!-- Template Variables Bar -->
          <div class="px-5 py-3 border-b border-surface-100 dark:border-[rgb(var(--color-border))] flex flex-wrap gap-1.5">
            <span class="text-[11px] text-surface-400 mr-1 self-center">Insert variable:</span>
            <button
              v-for="v in templateVars" :key="v.key"
              @click="insertVarToBody(i, v.key)"
              class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-medium bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-500/30 hover:bg-sky-100 dark:hover:bg-sky-500/20 transition-colors"
            >
              <span class="material-symbols-rounded text-xs">{{ v.icon }}</span>
              {{ v.key }}
            </button>
          </div>

          <!-- Rich Text Editor for email body -->
          <div class="p-5">
            <RichTextEditor
              v-model="step.body_html"
              placeholder="Write your email content... Use template variables like {client_name} for personalization."
              :compact="false"
              :showAI="false"
            />
          </div>
        </div>

        <!-- Add Step button (visible below the current step) -->
        <button @click="addStep"
                class="w-full px-4 py-3 rounded-xl border-2 border-dashed border-surface-300 dark:border-surface-600 text-surface-500 hover:border-primary-400 hover:text-primary-600 dark:hover:text-primary-400 text-sm font-medium flex items-center justify-center gap-2 transition-colors">
          <span class="material-symbols-rounded text-lg">add</span>
          Add Step {{ form.steps.length + 1 }}
        </button>
      </div>

    </div>
  </div>
</template>
