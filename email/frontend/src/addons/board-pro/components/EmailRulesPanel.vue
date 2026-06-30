<template>
  <div class="boardpro-email-rules p-4">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-semibold text-surface-800 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded">filter_alt</span>
        Email Auto-Link Rules
      </h3>
      <div class="flex items-center gap-2">
        <HowItWorksButton @click="showGuide = true" />
        <button
          class="px-3 py-1.5 text-xs rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center gap-1"
          @click="openCreate"
        >
          <span class="material-symbols-rounded text-sm">add</span>
          New Rule
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-6">
      <span class="material-symbols-rounded animate-spin text-surface-400">progress_activity</span>
    </div>

    <div v-else-if="rules.length === 0" class="text-center py-6 text-surface-400">
      <p class="text-sm">No email rules yet</p>
      <p class="text-xs mt-1">Auto-link incoming emails to cards based on subject or sender</p>
    </div>

    <!-- Rules list -->
    <div v-else class="space-y-2">
      <div
        v-for="rule in rules"
        :key="rule.id"
        class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3 flex items-center gap-3"
      >
        <div class="flex items-center">
          <button
            class="relative w-9 h-5 rounded-full transition-colors"
            :class="rule.is_active ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
            @click="toggleActive(rule)"
          >
            <span
              class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
              :class="rule.is_active ? 'left-[18px]' : 'left-0.5'"
            ></span>
          </button>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <p class="text-sm text-surface-800 dark:text-surface-200">
              <span class="font-medium text-primary-500 dark:text-primary-400">{{ formatRuleType(rule.rule_type) }}</span>:
              <span class="font-mono text-xs">{{ rule.rule_value }}</span>
            </p>
            <span
              v-if="rule.run_count > 0"
              class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] font-medium rounded-full bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400"
              :title="rule.last_run_at ? ('Last run: ' + formatDate(rule.last_run_at)) : ''"
            >
              <span class="material-symbols-rounded" style="font-size: 11px">play_arrow</span>
              {{ rule.run_count }}
            </span>
          </div>
          <p class="text-xs text-surface-400 mt-0.5">
            <template v-if="Number(rule.auto_create_card)">
              <span class="text-primary-400">Auto-create</span>
              <span v-if="rule.card_title_template"> &middot; {{ rule.card_title_template }}</span>
              <span v-if="rule.body_handling && rule.body_handling !== 'none'"> &middot; {{ bodyHandlingLabel(rule.body_handling) }}</span>
            </template>
            <template v-else>Link only</template>
            <span v-if="rule.auto_assign_to"> &middot; {{ rule.auto_assign_to }}</span>
            <span v-if="getListName(rule.list_id)"> &middot; "{{ getListName(rule.list_id) }}"</span>
            <span v-if="rule.auto_link_email" class="ml-1">
              <span class="material-symbols-rounded text-surface-300" style="font-size: 11px; vertical-align: -2px">link</span>
            </span>
            <span v-if="rule.auto_attach_files" class="ml-0.5">
              <span class="material-symbols-rounded text-surface-300" style="font-size: 11px; vertical-align: -2px">attach_file</span>
            </span>
            <span v-if="rule.last_run_at" class="ml-2 text-surface-300 dark:text-surface-500">Last: {{ formatDate(rule.last_run_at) }}</span>
          </p>
        </div>
        <div class="flex items-center gap-0.5 flex-shrink-0">
          <button
            class="p-1.5 rounded-lg text-surface-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10 transition-colors"
            @click="openEdit(rule)"
            title="Edit rule"
          >
            <span class="material-symbols-rounded text-sm">edit</span>
          </button>
          <button
            class="p-1.5 rounded-lg text-surface-400 hover:text-green-600 hover:bg-green-50 dark:hover:bg-green-500/10 transition-colors"
            :class="{ 'opacity-50 pointer-events-none': runningRuleId === rule.id }"
            @click="handleRunRule(rule)"
            title="Run rule now"
          >
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': runningRuleId === rule.id }">
              {{ runningRuleId === rule.id ? 'progress_activity' : 'play_arrow' }}
            </span>
          </button>
          <button
            class="p-1.5 rounded-lg text-surface-400 hover:text-violet-500 hover:bg-violet-50 dark:hover:bg-violet-500/10 transition-colors"
            @click="handleDuplicate(rule)"
            title="Duplicate rule"
          >
            <span class="material-symbols-rounded text-sm">content_copy</span>
          </button>
          <button
            class="p-1.5 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors"
            @click="handleDelete(rule.id)"
            title="Delete rule"
          >
            <span class="material-symbols-rounded text-sm">delete</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Email Rules Guide -->
    <EmailRulesGuide v-if="showGuide" @close="showGuide = false" />

    <!-- Create / Edit Modal -->
    <div v-if="showModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold text-surface-800 dark:text-surface-200 mb-4">
          {{ editingRule ? 'Edit Email Rule' : 'New Email Rule' }}
        </h3>

        <div class="space-y-3">
          <!-- Match Type -->
          <div>
            <label :class="labelClass">Match Type</label>
            <select v-model="form.rule_type" :class="selectClass">
              <option value="subject_contains">Subject contains</option>
              <option value="sender_domain">Sender domain</option>
              <option value="sender_email">Sender email</option>
              <option value="label_match">Label/folder match</option>
            </select>
          </div>

          <!-- Value -->
          <div>
            <label :class="labelClass">Value</label>
            <input v-model="form.rule_value" type="text" :class="inputClass" :placeholder="valuePlaceholder" />
          </div>

          <!-- Target List -->
          <div>
            <label :class="labelClass">Target List (for new cards)</label>
            <select v-model="form.list_id" :class="selectClass">
              <option :value="null">First list (default)</option>
              <option v-for="list in boardLists" :key="list.id" :value="list.id">{{ list.name }}</option>
            </select>
          </div>

          <!-- Auto-assign -->
          <div>
            <label :class="labelClass">Auto-assign to (optional)</label>
            <select v-model="form.auto_assign_to" :class="selectClass">
              <option :value="null">No one</option>
              <option v-for="member in boardMembers" :key="member.email" :value="member.email">{{ member.display_name || member.email }}</option>
            </select>
          </div>

          <!-- Auto-create toggle -->
          <div class="flex items-center justify-between py-1">
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400">Auto-create card when matched</label>
            <button
              class="relative w-9 h-5 rounded-full transition-colors"
              :class="form.auto_create_card ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
              @click="form.auto_create_card = !form.auto_create_card"
            >
              <span
                class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                :class="form.auto_create_card ? 'left-[18px]' : 'left-0.5'"
              ></span>
            </button>
          </div>

          <!-- ============================================================ -->
          <!-- Card Creation Settings (visible when auto-create is on) -->
          <!-- ============================================================ -->
          <template v-if="form.auto_create_card">
            <div class="border-t border-surface-200 dark:border-surface-700 pt-3 mt-1 space-y-3">
              <p class="text-xs font-semibold text-surface-500 uppercase tracking-wider flex items-center gap-1.5">
                <span class="material-symbols-rounded text-sm">tune</span>
                Card Creation Settings
              </p>

              <!-- Card Title Template -->
              <div>
                <label :class="labelClass">Card title template</label>
                <input v-model="form.card_title_template" type="text" :class="inputClass" placeholder="e.g. FEEDBACK - {type}" />
                <div class="flex flex-wrap gap-1 mt-1.5">
                  <button
                    v-for="v in titleVars" :key="v.key"
                    class="px-1.5 py-0.5 text-[10px] rounded-md bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-900/30 transition-colors"
                    @click="insertTitleVar(v.key)"
                  >{{ v.key }}</button>
                </div>
                <p class="text-[10px] text-surface-400 mt-0.5">Leave empty to use the email subject as card title</p>
              </div>

              <!-- Type Detection Categories (shown when {type} is used) -->
              <div v-if="form.card_title_template?.includes('{type}')">
                <label :class="labelClass">Type categories</label>
                <p class="text-[10px] text-surface-400 mb-2">The backend scans subject + body for these keywords to determine the {type} value</p>
                <div class="space-y-1.5">
                  <div
                    v-for="(cat, idx) in form.type_categories"
                    :key="idx"
                    class="flex items-center gap-2"
                  >
                    <input
                      v-model="cat.label"
                      type="text"
                      class="w-24 px-2 py-1.5 text-xs border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 rounded-lg"
                      placeholder="Label"
                    />
                    <input
                      v-model="cat.keywords"
                      type="text"
                      class="flex-1 px-2 py-1.5 text-xs border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 rounded-lg"
                      placeholder="keywords, comma, separated"
                    />
                    <button
                      class="p-1 text-surface-400 hover:text-red-500 transition-colors"
                      @click="form.type_categories.splice(idx, 1)"
                    >
                      <span class="material-symbols-rounded text-sm">close</span>
                    </button>
                  </div>
                </div>
                <button
                  class="mt-1.5 flex items-center gap-1 text-xs text-primary-500 hover:text-primary-600 transition-colors"
                  @click="form.type_categories.push({ label: '', keywords: '' })"
                >
                  <span class="material-symbols-rounded text-sm">add</span>
                  Add category
                </button>
                <div class="mt-1.5">
                  <label class="text-[10px] font-medium text-surface-500 block mb-0.5">Default type (when no keywords match)</label>
                  <input v-model="form.type_default" type="text" class="w-40 px-2 py-1.5 text-xs border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 rounded-lg" placeholder="General" />
                </div>
              </div>

              <!-- Email body handling -->
              <div>
                <label :class="labelClass">Email body handling</label>
                <select v-model="form.body_handling" :class="selectClass">
                  <option value="none">Skip body</option>
                  <option value="description">As card description</option>
                  <option value="checklist">As checklist items</option>
                  <option value="both">Description + checklist</option>
                </select>
                <p class="text-[10px] text-surface-400 mt-1">
                  <template v-if="form.body_handling === 'checklist' || form.body_handling === 'both'">
                    Each paragraph or line from the email body becomes a checklist item on the created card
                  </template>
                  <template v-else-if="form.body_handling === 'description'">
                    The email body text is placed into the card's description field
                  </template>
                  <template v-else>Only the email subject is used</template>
                </p>
              </div>

              <!-- Checklist title (when checklist mode) -->
              <div v-if="form.body_handling === 'checklist' || form.body_handling === 'both'">
                <label :class="labelClass">Checklist title</label>
                <input v-model="form.checklist_title" type="text" :class="inputClass" placeholder="e.g. Feedback Items, Action Points" />
                <p class="text-[10px] text-surface-400 mt-1">Name of the checklist created on the card (default: "Email Content")</p>
              </div>

              <!-- Auto-link source email -->
              <div class="flex items-center justify-between py-1">
                <div>
                  <label class="text-xs font-medium text-surface-600 dark:text-surface-400">Link source email to card</label>
                  <p class="text-[10px] text-surface-400">The triggering email appears under "Linked Emails"</p>
                </div>
                <button
                  class="relative w-9 h-5 rounded-full transition-colors flex-shrink-0"
                  :class="form.auto_link_email ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                  @click="form.auto_link_email = !form.auto_link_email"
                >
                  <span
                    class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                    :class="form.auto_link_email ? 'left-[18px]' : 'left-0.5'"
                  ></span>
                </button>
              </div>

              <!-- Auto-attach files -->
              <div class="flex items-center justify-between py-1">
                <div>
                  <label class="text-xs font-medium text-surface-600 dark:text-surface-400">Attach email files to card</label>
                  <p class="text-[10px] text-surface-400">Screenshots and attachments from the email are added to the card</p>
                </div>
                <button
                  class="relative w-9 h-5 rounded-full transition-colors flex-shrink-0"
                  :class="form.auto_attach_files ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                  @click="form.auto_attach_files = !form.auto_attach_files"
                >
                  <span
                    class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                    :class="form.auto_attach_files ? 'left-[18px]' : 'left-0.5'"
                  ></span>
                </button>
              </div>

            </div>
          </template>
        </div>

        <!-- Footer buttons -->
        <div class="flex justify-end gap-2 mt-5">
          <button
            class="px-4 py-2 text-sm rounded-full bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
            @click="showModal = false"
          >Cancel</button>
          <button
            class="px-4 py-2 text-sm rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors"
            :disabled="!form.rule_value || saving"
            @click="handleSave"
          >{{ saving ? (editingRule ? 'Saving...' : 'Creating...') : (editingRule ? 'Save' : 'Create') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted } from 'vue'
import { useBoardProStore } from '../stores/boardPro'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'
import EmailRulesGuide from './EmailRulesGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'

const store = useBoardProStore()
const boardsStore = useBoardsStore()
const toast = useToastStore()

const showGuide = ref(false)
const loading = computed(() => store.emailRulesLoading)
const rules = computed(() => store.emailRules)
const showModal = ref(false)
const saving = ref(false)
const editingRule = ref(null)

const boardLists = computed(() => boardsStore.currentLists || [])
const boardMembers = computed(() => boardsStore.currentMembers || [])

const labelClass = 'text-xs font-medium text-surface-600 dark:text-surface-400 block mb-1'
const inputClass = 'w-full px-3 py-2 text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 rounded-xl'
const selectClass = inputClass

const titleVars = [
  { key: '{subject}' },
  { key: '{sender}' },
  { key: '{sender_name}' },
  { key: '{type}' },
  { key: '{date}' },
]

const defaultTypeCategories = [
  { label: 'Design', keywords: 'design, ui, layout, visual, css' },
  { label: 'Bug', keywords: 'bug, error, broken, fix, crash' },
  { label: 'Feature', keywords: 'feature, request, suggestion, idea, enhancement' },
  { label: 'UX', keywords: 'ux, usability, flow, confusing, user experience' },
]

const defaultForm = {
  rule_type: 'subject_contains',
  rule_value: '',
  auto_create_card: true,
  list_id: null,
  auto_assign_to: null,
  card_title_template: '',
  type_categories: defaultTypeCategories.map(c => ({ ...c })),
  type_default: 'General',
  body_handling: 'none',
  checklist_title: '',
  auto_link_email: true,
  auto_attach_files: true,
}

const form = reactive({ ...defaultForm, type_categories: defaultTypeCategories.map(c => ({ ...c })) })

const valuePlaceholder = computed(() => {
  switch (form.rule_type) {
    case 'subject_contains': return 'e.g., FlowOne.Pro Feedback'
    case 'sender_domain': return 'e.g., client.com'
    case 'sender_email': return 'e.g., john@client.com'
    case 'label_match': return 'e.g., INBOX/Important'
    default: return ''
  }
})

function formatRuleType(type) {
  const map = {
    subject_contains: 'Subject contains',
    sender_domain: 'Sender domain',
    sender_email: 'Sender email',
    label_match: 'Label match',
  }
  return map[type] || type
}

function bodyHandlingLabel(val) {
  const map = {
    description: 'Body as description',
    checklist: 'Body as checklist',
    both: 'Description + checklist',
  }
  return map[val] || ''
}

function getListName(listId) {
  if (!listId) return null
  const list = boardLists.value.find(l => l.id === listId)
  return list?.name || null
}

function formatDate(d) {
  if (!d) return ''
  const dt = new Date(d)
  const now = new Date()
  const diff = now - dt
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)
  if (hours < 1) return 'just now'
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function insertTitleVar(varKey) {
  form.card_title_template = (form.card_title_template || '') + varKey
}

function resetForm() {
  Object.assign(form, {
    ...defaultForm,
    type_categories: defaultTypeCategories.map(c => ({ ...c })),
  })
}

function openCreate() {
  editingRule.value = null
  resetForm()
  showModal.value = true
}

function openEdit(rule) {
  editingRule.value = rule
  const cats = Array.isArray(rule.type_categories) && rule.type_categories.length
    ? rule.type_categories.map(c => ({ ...c }))
    : defaultTypeCategories.map(c => ({ ...c }))

  Object.assign(form, {
    rule_type: rule.rule_type || 'subject_contains',
    rule_value: rule.rule_value || '',
    auto_create_card: Boolean(Number(rule.auto_create_card)),
    list_id: rule.list_id || null,
    auto_assign_to: rule.auto_assign_to || null,
    card_title_template: rule.card_title_template || '',
    type_categories: cats,
    type_default: rule.type_default || 'General',
    body_handling: rule.body_handling || 'none',
    checklist_title: rule.checklist_title || '',
    auto_link_email: rule.auto_link_email !== undefined ? Boolean(Number(rule.auto_link_email)) : true,
    auto_attach_files: rule.auto_attach_files !== undefined ? Boolean(Number(rule.auto_attach_files)) : true,
  })
  showModal.value = true
}

function buildPayload() {
  const isCreate = form.auto_create_card
  const usesType = isCreate && form.card_title_template?.includes('{type}')
  const usesChecklist = isCreate && (form.body_handling === 'checklist' || form.body_handling === 'both')

  return {
    rule_type: form.rule_type,
    rule_value: form.rule_value,
    auto_create_card: form.auto_create_card ? 1 : 0,
    list_id: form.list_id,
    auto_assign_to: form.auto_assign_to,
    card_title_template: isCreate ? form.card_title_template : '',
    type_categories: usesType ? form.type_categories.filter(c => c.label.trim() && c.keywords.trim()) : [],
    type_default: usesType ? (form.type_default || 'General') : 'General',
    body_handling: isCreate ? form.body_handling : 'none',
    checklist_title: usesChecklist ? (form.checklist_title || 'Email Content') : '',
    auto_link_email: isCreate ? (form.auto_link_email ? 1 : 0) : 0,
    auto_attach_files: isCreate ? (form.auto_attach_files ? 1 : 0) : 0,
  }
}

async function handleSave() {
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return
  saving.value = true
  try {
    const payload = buildPayload()
    if (editingRule.value) {
      await store.updateEmailRule(editingRule.value.id, payload)
      toast.success('Email rule updated')
    } else {
      await store.createEmailRule(boardId, payload)
      toast.success('Email rule created')
    }
    showModal.value = false
    editingRule.value = null
  } catch (e) {
    toast.error(editingRule.value ? 'Failed to update email rule' : 'Failed to create email rule')
  } finally {
    saving.value = false
  }
}

async function handleDuplicate(rule) {
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return
  try {
    await store.duplicateEmailRule(boardId, rule)
    toast.success('Rule duplicated')
  } catch (e) {
    toast.error('Failed to duplicate rule')
  }
}

async function toggleActive(rule) {
  try {
    await store.updateEmailRule(rule.id, { is_active: Number(rule.is_active) ? 0 : 1 })
    toast.success(Number(rule.is_active) ? 'Rule disabled' : 'Rule enabled')
  } catch (e) {
    toast.error('Failed to update rule')
  }
}

async function handleDelete(ruleId) {
  try {
    await store.deleteEmailRule(ruleId)
    toast.success('Rule deleted')
  } catch (e) {
    toast.error('Failed to delete rule')
  }
}

const runningRuleId = ref(null)

async function handleRunRule(rule) {
  if (runningRuleId.value) return
  runningRuleId.value = rule.id
  try {
    const result = await store.runEmailRule(rule.id)
    if (result) {
      toast.success(`Processed ${result.processed} emails: ${result.cards_created} card(s) created`)
      const boardId = boardsStore.currentBoard?.id
      if (boardId) await store.fetchEmailRules(boardId)
    }
  } catch (e) {
    const msg = e?.response?.data?.error || 'Failed to run rule'
    toast.error(msg)
    console.error('[EmailRules] Run rule error:', e?.response?.data || e)
  } finally {
    runningRuleId.value = null
  }
}

onMounted(() => {
  const boardId = boardsStore.currentBoard?.id
  if (boardId) store.fetchEmailRules(boardId)
})
</script>
