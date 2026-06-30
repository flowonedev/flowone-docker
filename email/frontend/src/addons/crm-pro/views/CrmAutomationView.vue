<script setup>
/**
 * CrmAutomationView - Manage automation rules
 * List rules, create/edit/toggle/delete, view execution history.
 * Editor opens as a full-page inline form (not a sidebar).
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import AppHeader from '@/components/shared/AppHeader.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import CrmSidebar from '../components/CrmSidebar.vue'
import CrmAutomationRuleEditor from '../components/CrmAutomationRuleEditor.vue'
import CrmAutomationGuide from '../components/CrmAutomationGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import CrmAutomationTemplates from '../components/CrmAutomationTemplates.vue'

const router = useRouter()
const toast = useToastStore()
const loading = ref(true)
const rules = ref([])
const log = ref([])
const activeTab = ref('rules') // rules, log
const showEditor = ref(false)
const showTemplates = ref(false)
const editingRule = ref(null)
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
    const [rulesRes, logRes] = await Promise.all([
      api.get('/crm/automation/rules'),
      api.get('/crm/automation/log', { params: { limit: 50 } }),
    ])
    if (rulesRes.data?.success) rules.value = rulesRes.data.data?.rules || []
    if (logRes.data?.success) log.value = logRes.data.data?.log || []
  } catch (e) {
    toast.error('Failed to load automation data')
  } finally {
    loading.value = false
  }
}

function openNewRule() {
  showTemplates.value = true
}

function onTemplateSelect(tpl) {
  showTemplates.value = false
  if (!tpl) {
    // Blank rule
    editingRule.value = null
  } else {
    editingRule.value = {
      name: tpl.name_template || tpl.name,
      description: tpl.description_template || tpl.description,
      is_active: 1,
      visibility: 'private',
      trigger_type: tpl.trigger_type,
      trigger_config: { ...(tpl.trigger_config || {}) },
      action_type: tpl.action_type,
      action_config: { ...(tpl.action_config || {}) },
    }
  }
  showEditor.value = true
}

function openEditRule(rule) {
  editingRule.value = { ...rule }
  showEditor.value = true
}

async function onRuleSaved() {
  showEditor.value = false
  editingRule.value = null
  await fetchData()
  toast.success('Automation rule saved')
}

function closeEditor() {
  showEditor.value = false
  editingRule.value = null
}

async function toggleRule(rule) {
  try {
    await api.post(`/crm/automation/rules/${rule.id}/toggle`)
    await fetchData()
  } catch (e) {
    toast.error('Failed to toggle rule')
  }
}

async function deleteRule(rule) {
  if (!confirm(`Delete automation rule "${rule.name}"?`)) return
  try {
    await api.delete(`/crm/automation/rules/${rule.id}`)
    await fetchData()
    toast.success('Rule deleted')
  } catch (e) {
    toast.error('Failed to delete rule')
  }
}

// Separate own vs shared rules
const myRules = computed(() => rules.value.filter(r => r.is_own !== false))
const sharedRules = computed(() => rules.value.filter(r => r.is_own === false))

const testingRule = ref(null)
async function testRule(rule) {
  testingRule.value = rule.id
  try {
    await api.post(`/crm/automation/rules/${rule.id}/test`)
    toast.success(`Test fired! Check your action target (chat, email, etc.)`)
  } catch (e) {
    toast.error('Test fire failed: ' + (e.response?.data?.message || e.message))
  } finally {
    testingRule.value = null
  }
}

async function duplicateRule(rule) {
  try {
    await api.post(`/crm/automation/rules/${rule.id}/duplicate`)
    await fetchData()
    toast.success('Rule duplicated! You now have your own copy.')
  } catch (e) {
    toast.error('Failed to duplicate rule: ' + (e.response?.data?.message || e.message))
  }
}

async function toggleVisibility(rule) {
  const newVisibility = rule.visibility === 'shared' ? 'private' : 'shared'
  try {
    await api.put(`/crm/automation/rules/${rule.id}`, { visibility: newVisibility })
    rule.visibility = newVisibility
    toast.success(newVisibility === 'shared' ? 'Rule is now visible to shared users' : 'Rule is now private')
  } catch (e) {
    toast.error('Failed to update visibility')
  }
}

const triggerLabels = {
  deal_stage_idle: 'Deal idle in stage',
  deal_stage_changed: 'Deal stage changed',
  client_health_low: 'Client health low',
  invoice_overdue: 'Invoice overdue',
  no_contact_days: 'No contact for days',
  deal_won: 'Deal won',
  deal_lost: 'Deal lost',
  task_changed: 'Task changed',
  board_closed: 'Board closed',
  moodboard_ready: 'Moodboard ready',
  time_spent_reached: 'Tracked time threshold',
  colleague_sick_status: 'Colleague sick',
  drive_folder_permission_changed: 'Drive folder permissions changed',
  email_opened: 'Email opened',
  email_link_clicked: 'Email link clicked',
}

const actionLabels = {
  create_reminder: 'Create reminder',
  send_email: 'Send email',
  create_invoice_draft: 'Create invoice draft',
  move_deal_stage: 'Move deal to stage',
  notify_user: 'Notify user',
  start_sequence: 'Start email sequence',
  assign_task: 'Assign task',
  send_chat_message: 'Send chat message',
  reassign_deals: 'Reassign deals',
}

const triggerIcons = {
  deal_stage_idle: 'hourglass_top', deal_stage_changed: 'swap_horiz',
  client_health_low: 'heart_broken', invoice_overdue: 'schedule',
  no_contact_days: 'person_off', deal_won: 'emoji_events', deal_lost: 'cancel',
  task_changed: 'task_alt', board_closed: 'check_box',
  moodboard_ready: 'palette', time_spent_reached: 'timer',
  colleague_sick_status: 'sick', drive_folder_permission_changed: 'folder_shared',
  email_opened: 'mark_email_read', email_link_clicked: 'ads_click',
}

const actionIcons = {
  create_reminder: 'alarm', send_email: 'mail', create_invoice_draft: 'receipt',
  move_deal_stage: 'arrow_forward', notify_user: 'notifications', start_sequence: 'route',
  assign_task: 'add_task', send_chat_message: 'chat', reassign_deals: 'swap_calls',
}

function formatDate(d) {
  if (!d) return '--'
  const dt = new Date(d)
  const now = new Date()
  const diff = now - dt
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)
  if (hours < 1) return 'Just now'
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function triggerSummary(rule) {
  const cfg = rule.trigger_config || {}
  switch (rule.trigger_type) {
    case 'deal_stage_idle': return `Deal in "${cfg.stage}" for ${cfg.days || 7}+ days`
    case 'deal_stage_changed': return cfg.stage ? `Deal enters "${cfg.stage}"` : 'Any stage change'
    case 'client_health_low': return `Health score < ${cfg.threshold || 30}`
    case 'invoice_overdue': return `Overdue by ${cfg.days || 7}+ days`
    case 'no_contact_days': return `No contact for ${cfg.days || 14}+ days`
    case 'deal_won': return 'When a deal is won'
    case 'deal_lost': return 'When a deal is lost'
    case 'task_changed': {
      const parts = []
      if (cfg.change_type) parts.push(cfg.change_type)
      if (cfg.status) parts.push(`status: ${cfg.status}`)
      return parts.length ? `Task ${parts.join(', ')}` : 'Any task change'
    }
    case 'board_closed': return 'When a board is closed'
    case 'moodboard_ready': return 'When a moodboard is marked ready'
    case 'time_spent_reached': {
      const scope = cfg.scope === 'client' ? 'per client' : (cfg.board_id ? `on board #${cfg.board_id}` : 'per board')
      const who = cfg.colleague_email ? `by ${cfg.colleague_email}` : 'by me'
      return `${cfg.hours || 10}+ hrs/${cfg.period || 'week'} ${scope} ${who}`
    }
    case 'colleague_sick_status': return `Colleague status contains "${cfg.keyword || 'sick'}"`
    case 'drive_folder_permission_changed': {
      if (cfg.folder_id) return `Folder #${cfg.folder_id} permissions changed`
      return cfg.change_type ? `Folder ${cfg.change_type}` : 'Any folder permission change'
    }
    case 'email_opened': {
      const scope = cfg.scope === 'campaigns' ? 'campaign emails' : cfg.scope === 'regular' ? 'regular emails' : 'any email'
      return `When ${scope} opened`
    }
    case 'email_link_clicked': {
      const scope = cfg.scope === 'campaigns' ? 'campaign' : cfg.scope === 'regular' ? 'regular' : 'any'
      const link = cfg.link_match === 'contains' && cfg.link_value ? ` matching "${cfg.link_value}"` : ''
      return `Link clicked in ${scope} email${link}`
    }
    default: return rule.trigger_type
  }
}
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <AppHeader
      current-view="crm-automation"
      icon="smart_toy"
      title="Workflows"
    >
      <template #title-badge>
        <ViewInfoButton view-key="crmAutomation" />
        <HowItWorksButton @click="showGuide = true" />
      </template>
    </AppHeader>

    <div :class="isMobile ? 'flex-1 flex flex-col' : 'flex-1 flex overflow-hidden'">
      <CrmSidebar />

      <div :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 flex flex-col overflow-hidden'">

    <!-- Editor mode: full-page form -->
    <template v-if="showEditor">
      <CrmAutomationRuleEditor
        :rule="editingRule"
        @saved="onRuleSaved"
        @close="closeEditor"
      />
    </template>

    <!-- List mode -->
    <template v-else>
      <!-- Sub-header -->
      <div class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))]">
        <div class="flex gap-1">
          <button
            v-for="tab in [
              { key: 'rules', label: 'Rules', icon: 'tune' },
              { key: 'log', label: 'Execution Log', icon: 'history' },
            ]" :key="tab.key"
            @click="activeTab = tab.key"
            :class="[
              'px-3 py-2 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors',
              activeTab === tab.key
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300'
                : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
            {{ tab.label }}
          </button>
        </div>
        <div class="flex items-center gap-2">
          <button @click="router.push('/crm/sequences')" class="px-3 py-2 rounded-full text-sm font-medium text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] flex items-center gap-1.5">
            <span class="material-symbols-rounded text-lg">route</span>
            Deal Follow-ups
          </button>
          <button @click="openNewRule"
                  class="px-4 py-2 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium flex items-center gap-2 transition-colors">
            <span class="material-symbols-rounded text-lg">add</span>
            New Rule
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex-1 flex items-center justify-center">
        <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
      </div>

      <div v-else class="flex-1 overflow-auto p-6">
        <!-- Rules Tab -->
        <template v-if="activeTab === 'rules'">
          <div v-if="rules.length" class="space-y-6 max-w-5xl mx-auto">

            <!-- My Rules -->
            <div v-if="myRules.length" class="space-y-3">
              <h3 class="text-xs font-semibold text-surface-400 uppercase tracking-wider px-1">My Rules</h3>
              <div
                v-for="rule in myRules" :key="rule.id"
                :class="['bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border p-4 transition-all', rule.is_active ? 'border-surface-200 dark:border-[rgb(var(--color-border))]' : 'border-surface-200 dark:border-[rgb(var(--color-border))] opacity-60']"
              >
                <div class="flex items-start gap-4">
                  <button @click="toggleRule(rule)" :class="['relative w-11 h-6 rounded-full transition-colors flex-shrink-0 mt-0.5', rule.is_active ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600']">
                    <span :class="['absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform', rule.is_active ? 'left-[22px]' : 'left-0.5']"></span>
                  </button>

                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <h3 class="text-sm font-semibold text-surface-900 dark:text-white">{{ rule.name }}</h3>
                      <span v-if="rule.visibility === 'shared'" class="text-[10px] px-1.5 py-0.5 rounded-full bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400 flex items-center gap-0.5">
                        <span class="material-symbols-rounded" style="font-size:10px">group</span> Shared
                      </span>
                      <span v-if="rule.run_count > 0" class="text-[10px] px-1.5 py-0.5 rounded-full bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] text-surface-500">
                        {{ rule.run_count }} runs
                      </span>
                    </div>
                    <p v-if="rule.description" class="text-xs text-surface-400 mb-2">{{ rule.description }}</p>

                    <div class="flex items-center gap-2 text-xs">
                      <span class="flex items-center gap-1 px-2 py-1 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400">
                        <span class="material-symbols-rounded text-sm">{{ triggerIcons[rule.trigger_type] || 'bolt' }}</span>
                        {{ triggerSummary(rule) }}
                      </span>
                      <span class="material-symbols-rounded text-surface-300 text-sm">arrow_forward</span>
                      <span class="flex items-center gap-1 px-2 py-1 rounded-lg bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400">
                        <span class="material-symbols-rounded text-sm">{{ actionIcons[rule.action_type] || 'play_arrow' }}</span>
                        {{ actionLabels[rule.action_type] || rule.action_type }}
                      </span>
                    </div>

                    <p v-if="rule.last_run_at" class="text-[10px] text-surface-400 mt-2">Last run: {{ formatDate(rule.last_run_at) }}</p>
                  </div>

                  <div class="flex items-center gap-1 flex-shrink-0">
                    <button
                      @click="toggleVisibility(rule)"
                      :class="[
                        'p-1.5 rounded-lg transition-colors',
                        rule.visibility === 'shared'
                          ? 'text-green-500 hover:bg-green-50 dark:hover:bg-green-500/10'
                          : 'text-surface-300 dark:text-surface-500 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]'
                      ]"
                      :title="rule.visibility === 'shared' ? 'Visible to shared users – click to make private' : 'Private – click to make visible'"
                    >
                      <span class="material-symbols-rounded text-sm">{{ rule.visibility === 'shared' ? 'visibility' : 'visibility_off' }}</span>
                    </button>
                    <button @click="testRule(rule)" :disabled="testingRule === rule.id" class="p-1.5 rounded-lg text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-500/10 disabled:opacity-50" title="Test fire this rule">
                      <span class="material-symbols-rounded text-sm" :class="testingRule === rule.id ? 'animate-spin' : ''">{{ testingRule === rule.id ? 'progress_activity' : 'play_arrow' }}</span>
                    </button>
                    <button @click="duplicateRule(rule)" class="p-1.5 rounded-lg text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-500/10" title="Duplicate rule">
                      <span class="material-symbols-rounded text-sm">content_copy</span>
                    </button>
                    <button @click="openEditRule(rule)" class="p-1.5 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]">
                      <span class="material-symbols-rounded text-sm">edit</span>
                    </button>
                    <button @click="deleteRule(rule)" class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                      <span class="material-symbols-rounded text-sm">delete</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Shared With Me -->
            <div v-if="sharedRules.length" class="space-y-3">
              <h3 class="text-xs font-semibold text-surface-400 uppercase tracking-wider px-1 flex items-center gap-1.5">
                <span class="material-symbols-rounded text-sm">group</span>
                Shared with me
              </h3>
              <div
                v-for="rule in sharedRules" :key="'shared-' + rule.id"
                class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-green-200 dark:border-green-800/50 p-4 transition-all"
              >
                <div class="flex items-start gap-4">
                  <div class="w-10 h-10 rounded-xl bg-green-50 dark:bg-green-500/10 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-rounded text-lg text-green-500">share</span>
                  </div>

                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <h3 class="text-sm font-semibold text-surface-900 dark:text-white">{{ rule.name }}</h3>
                      <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400">
                        {{ rule.access_role === 'editor' ? 'Editor' : 'Viewer' }}
                      </span>
                      <span v-if="rule.run_count > 0" class="text-[10px] px-1.5 py-0.5 rounded-full bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] text-surface-500">
                        {{ rule.run_count }} runs
                      </span>
                    </div>
                    <p class="text-[11px] text-surface-400 mb-1">Shared by {{ rule.shared_by || rule.user_email }}</p>
                    <p v-if="rule.description" class="text-xs text-surface-400 mb-2">{{ rule.description }}</p>

                    <div class="flex items-center gap-2 text-xs">
                      <span class="flex items-center gap-1 px-2 py-1 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400">
                        <span class="material-symbols-rounded text-sm">{{ triggerIcons[rule.trigger_type] || 'bolt' }}</span>
                        {{ triggerSummary(rule) }}
                      </span>
                      <span class="material-symbols-rounded text-surface-300 text-sm">arrow_forward</span>
                      <span class="flex items-center gap-1 px-2 py-1 rounded-lg bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400">
                        <span class="material-symbols-rounded text-sm">{{ actionIcons[rule.action_type] || 'play_arrow' }}</span>
                        {{ actionLabels[rule.action_type] || rule.action_type }}
                      </span>
                    </div>

                    <p v-if="rule.last_run_at" class="text-[10px] text-surface-400 mt-2">Last run: {{ formatDate(rule.last_run_at) }}</p>
                  </div>

                  <div class="flex items-center gap-1 flex-shrink-0">
                    <button @click="duplicateRule(rule)" class="p-1.5 rounded-lg text-green-500 hover:bg-green-50 dark:hover:bg-green-500/10" title="Copy to my rules">
                      <span class="material-symbols-rounded text-sm">content_copy</span>
                    </button>
                    <button v-if="rule.access_role === 'editor'" @click="openEditRule(rule)" class="p-1.5 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]">
                      <span class="material-symbols-rounded text-sm">edit</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-else class="text-center py-16 text-surface-400">
            <span class="material-symbols-rounded text-5xl">smart_toy</span>
            <p class="text-sm mt-3">No automation rules yet</p>
            <p class="text-xs mt-1">Pick a template to get started quickly, or build from scratch</p>
            <button @click="openNewRule" class="mt-4 px-4 py-2 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
              Browse Templates
            </button>
          </div>
        </template>

        <!-- Log Tab -->
        <template v-if="activeTab === 'log'">
          <div v-if="log.length" class="space-y-2 max-w-4xl mx-auto">
            <div
              v-for="entry in log" :key="entry.id"
              class="flex items-center gap-3 p-3 rounded-lg bg-white dark:bg-[rgb(var(--color-surface))] border border-surface-200 dark:border-[rgb(var(--color-border))]"
            >
              <div class="w-8 h-8 rounded-full bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-rounded text-base" :class="entry.action_taken?.includes('error') ? 'text-red-500' : 'text-green-500'">
                  {{ entry.action_taken?.includes('error') ? 'error' : 'check_circle' }}
                </span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-white truncate">
                  {{ entry.rule_name || `Rule #${entry.rule_id}` }}
                </p>
                <p class="text-xs text-surface-400 truncate">
                  {{ entry.action_taken }} on {{ entry.target_type }} #{{ entry.target_id }}
                  <span v-if="entry.result_detail"> - {{ entry.result_detail }}</span>
                </p>
              </div>
              <span class="text-xs text-surface-400 flex-shrink-0">{{ formatDate(entry.created_at) }}</span>
            </div>
          </div>

          <div v-else class="text-center py-16 text-surface-400">
            <span class="material-symbols-rounded text-5xl">history</span>
            <p class="text-sm mt-3">No automation activity yet</p>
            <p class="text-xs mt-1">Executed actions will appear here</p>
          </div>
        </template>
      </div>
    </template>

      </div><!-- end flex-1 content -->
    </div><!-- end flex sidebar+content -->

    <!-- Template Picker -->
    <CrmAutomationTemplates v-if="showTemplates" @close="showTemplates = false" @select="onTemplateSelect" />

    <!-- Automation Guide Modal -->
    <CrmAutomationGuide v-if="showGuide" @close="showGuide = false" />

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>
