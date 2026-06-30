<template>
  <div class="boardpro-automation-panel p-4">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-base font-semibold text-surface-800 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded">bolt</span>
        Automations
        <span v-if="rules.length" class="text-xs bg-surface-100 dark:bg-surface-700 px-2 py-0.5 rounded-full">
          {{ rules.length }}
        </span>
      </h3>
      <div class="flex items-center gap-2">
        <HowItWorksButton @click="showGuide = true" />
        <button
          class="px-3 py-1.5 text-xs rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center gap-1"
          @click="showTemplates = true"
        >
          <span class="material-symbols-rounded text-sm">add</span>
          New Rule
        </button>
      </div>
    </div>

    <!-- Tabs: Rules / Execution Log -->
    <div class="flex gap-1 mb-4">
      <button
        v-for="tab in [
          { key: 'rules', label: 'Rules', icon: 'tune' },
          { key: 'log', label: 'Execution Log', icon: 'history' },
        ]" :key="tab.key"
        @click="activeTab = tab.key; if (tab.key === 'log' && !logLoaded) loadLog()"
        :class="[
          'px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-1.5 transition-colors',
          activeTab === tab.key
            ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300'
            : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
        ]"
      >
        <span class="material-symbols-rounded text-sm">{{ tab.icon }}</span>
        {{ tab.label }}
      </button>
    </div>

    <!-- Rules Tab -->
    <template v-if="activeTab === 'rules'">
    <!-- Rules list -->
    <div v-if="loading" class="flex items-center justify-center py-8">
      <span class="material-symbols-rounded animate-spin text-surface-400">progress_activity</span>
    </div>

    <div v-else-if="rules.length === 0" class="text-center py-8 text-surface-400">
      <span class="material-symbols-rounded text-4xl mb-2 block">bolt</span>
      <p class="text-sm">No automation rules yet</p>
      <p class="text-xs mt-1">Pick a template or create from scratch</p>
      <button
        class="mt-3 px-4 py-2 text-xs rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors"
        @click="showTemplates = true"
      >
        Browse Templates
      </button>
    </div>

    <div v-else class="space-y-2">
      <div
        v-for="rule in rules"
        :key="rule.id"
        class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3"
      >
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <div class="flex items-center">
              <button
                class="relative w-9 h-5 rounded-full transition-colors"
                :class="rule.is_active ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                @click="toggleRule(rule)"
              >
                <span
                  class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                  :class="rule.is_active ? 'left-[18px]' : 'left-0.5'"
                ></span>
              </button>
            </div>
            <div>
              <p class="text-sm font-medium text-surface-800 dark:text-surface-200">{{ rule.name }}</p>
              <p class="text-xs text-surface-500 mt-0.5">
                When <span class="font-medium text-primary-500 dark:text-primary-400">{{ formatTrigger(rule.trigger_type) }}</span>
                then <span class="font-medium text-green-600 dark:text-green-400">{{ formatAction(rule.action_type) }}</span>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-1">
            <span v-if="rule.run_count > 0" class="text-xs text-surface-400">
              {{ rule.run_count }}x
            </span>
            <button
              class="p-1 text-surface-400 hover:text-red-500 transition-colors"
              @click="deleteRule(rule.id)"
            >
              <span class="material-symbols-rounded text-sm">delete</span>
            </button>
          </div>
        </div>
      </div>
    </div>
    </template>

    <!-- Execution Log Tab -->
    <template v-if="activeTab === 'log'">
      <div v-if="logLoading" class="flex items-center justify-center py-8">
        <span class="material-symbols-rounded animate-spin text-surface-400">progress_activity</span>
      </div>

      <div v-else-if="executionLog.length === 0" class="text-center py-8 text-surface-400">
        <span class="material-symbols-rounded text-4xl mb-2 block">history</span>
        <p class="text-sm">No automation activity yet</p>
        <p class="text-xs mt-1">Executed actions will appear here</p>
      </div>

      <div v-else class="space-y-2">
        <div
          v-for="entry in executionLog"
          :key="entry.id"
          class="flex items-center gap-3 p-3 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700"
        >
          <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"
               :class="entry.action_taken?.includes('error') ? 'bg-red-100 dark:bg-red-500/10' : 'bg-green-100 dark:bg-green-500/10'">
            <span class="material-symbols-rounded text-base"
                  :class="entry.action_taken?.includes('error') ? 'text-red-500' : 'text-green-500'">
              {{ entry.action_taken?.includes('error') ? 'error' : 'check_circle' }}
            </span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-surface-800 dark:text-surface-200 truncate">
              {{ entry.rule_name || `Rule #${entry.rule_id}` }}
            </p>
            <p class="text-xs text-surface-500 truncate">
              {{ formatAction(entry.action_type) }} on {{ entry.target_type }} #{{ entry.target_id }}
              <span v-if="entry.result_detail"> -- {{ entry.result_detail }}</span>
            </p>
          </div>
          <span class="text-[10px] text-surface-400 flex-shrink-0 whitespace-nowrap">{{ formatDate(entry.created_at) }}</span>
        </div>
      </div>
    </template>

    <!-- Template Picker -->
    <BoardAutomationTemplates v-if="showTemplates" @close="showTemplates = false" @select="onTemplateSelect" />

    <!-- Automation Guide -->
    <AutomationGuide v-if="showGuide" @close="showGuide = false" />

    <!-- Create Rule Modal -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showCreate = false">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-lg font-semibold text-surface-800 dark:text-surface-200 mb-4">New Automation Rule</h3>

        <div class="space-y-3">
          <div>
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400 block mb-1">Rule Name</label>
            <input
              v-model="newRule.name"
              type="text"
              class="w-full px-3 py-2 text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-800 dark:text-surface-200 rounded-lg focus:ring-1 focus:ring-primary-500/40 outline-none"
              placeholder="e.g., Notify on overdue"
            />
          </div>

          <div>
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400 block mb-1">When (Trigger)</label>
            <select
              v-model="newRule.trigger_type"
              class="w-full px-3 py-2 text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-800 dark:text-surface-200 rounded-lg focus:ring-1 focus:ring-primary-500/40 outline-none"
            >
              <option value="card_moved_to_list">Card moved to list</option>
              <option value="card_completed">Card completed</option>
              <option value="card_overdue">Card is overdue</option>
              <option value="card_idle_days">Card idle for X days</option>
              <option value="card_created">Card created</option>
              <option value="label_added">Label added</option>
              <option value="checklist_completed">Checklist completed</option>
              <option value="email_received_on_card">Email received on card</option>
            </select>
          </div>

          <!-- Trigger-specific config -->
          <div v-if="newRule.trigger_type === 'card_idle_days'">
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400 block mb-1">Idle Days</label>
            <input
              v-model.number="newRule.trigger_config.days"
              type="number"
              min="1"
              class="w-full px-3 py-2 text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-800 dark:text-surface-200 rounded-lg focus:ring-1 focus:ring-primary-500/40 outline-none"
              placeholder="7"
            />
          </div>

          <div>
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400 block mb-1">Then (Action)</label>
            <select
              v-model="newRule.action_type"
              class="w-full px-3 py-2 text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-800 dark:text-surface-200 rounded-lg focus:ring-1 focus:ring-primary-500/40 outline-none"
            >
              <option value="move_card">Move card to list</option>
              <option value="assign_member">Assign member</option>
              <option value="add_label">Add label</option>
              <option value="send_notification">Send notification</option>
              <option value="create_invoice_draft">Create invoice draft</option>
              <option value="post_chat_message">Post chat message</option>
              <option value="update_deal_stage">Update deal stage</option>
            </select>
          </div>

          <!-- Action-specific config -->
          <div v-if="newRule.action_type === 'send_notification' || newRule.action_type === 'post_chat_message'">
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400 block mb-1">Message</label>
            <input
              v-model="newRule.action_config.message"
              type="text"
              class="w-full px-3 py-2 text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-800 dark:text-surface-200 rounded-lg focus:ring-1 focus:ring-primary-500/40 outline-none"
              placeholder="e.g., Card {{card_title}} needs attention"
            />
            <p class="text-[10px] text-surface-400 mt-1">Variables: {{card_title}}, {{assigned_to}}, {{list_name}}</p>
          </div>
        </div>

        <div class="flex justify-end gap-2 mt-5">
          <button
            class="px-4 py-2 text-sm rounded-full bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
            @click="showCreate = false"
          >
            Cancel
          </button>
          <button
            class="px-4 py-2 text-sm rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            :disabled="!newRule.name || !newRule.trigger_type || !newRule.action_type"
            @click="handleCreate"
          >
            Create Rule
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useBoardProStore } from '../stores/boardPro'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import AutomationGuide from './AutomationGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import BoardAutomationTemplates from './BoardAutomationTemplates.vue'

const store = useBoardProStore()
const boardsStore = useBoardsStore()

const showGuide = ref(false)
const showTemplates = ref(false)
const loading = computed(() => store.automationRulesLoading)
const rules = computed(() => store.automationRules)
const showCreate = ref(false)
const activeTab = ref('rules')
const executionLog = ref([])
const logLoading = ref(false)
const logLoaded = ref(false)

async function loadLog() {
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return
  logLoading.value = true
  try {
    executionLog.value = await store.fetchBoardAutomationLog(boardId)
    logLoaded.value = true
  } finally {
    logLoading.value = false
  }
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

const newRule = reactive({
  name: '',
  trigger_type: 'card_moved_to_list',
  action_type: 'send_notification',
  trigger_config: {},
  action_config: {},
})

function formatTrigger(type) {
  const map = {
    card_moved_to_list: 'card moves to list',
    card_completed: 'card completed',
    card_overdue: 'card is overdue',
    card_idle_days: 'card is idle',
    card_created: 'card created',
    label_added: 'label added',
    checklist_completed: 'checklist done',
    email_received_on_card: 'email received',
    list_all_completed: 'all cards done in list',
  }
  return map[type] || type
}

function formatAction(type) {
  const map = {
    move_card: 'move card',
    assign_member: 'assign member',
    add_label: 'add label',
    send_notification: 'notify',
    create_invoice_draft: 'create invoice',
    send_email: 'send email',
    post_chat_message: 'chat message',
    update_deal_stage: 'update deal',
    create_calendar_event: 'create event',
    start_crm_sequence: 'start sequence',
  }
  return map[type] || type
}

function onTemplateSelect(tpl) {
  showTemplates.value = false
  if (!tpl) {
    // Blank rule
    Object.assign(newRule, { name: '', trigger_type: 'card_moved_to_list', action_type: 'send_notification', trigger_config: {}, action_config: {} })
  } else {
    Object.assign(newRule, {
      name: tpl.name,
      trigger_type: tpl.trigger_type,
      action_type: tpl.action_type,
      trigger_config: { ...(tpl.trigger_config || {}) },
      action_config: { ...(tpl.action_config || {}) },
    })
  }
  showCreate.value = true
}

async function handleCreate() {
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return

  try {
    await store.createAutomationRule(boardId, { ...newRule })
    showCreate.value = false
    Object.assign(newRule, { name: '', trigger_type: 'card_moved_to_list', action_type: 'send_notification', trigger_config: {}, action_config: {} })
  } catch (e) { /* handled in store */ }
}

async function toggleRule(rule) {
  await store.updateAutomationRule(rule.id, { is_active: rule.is_active ? 0 : 1 })
}

async function deleteRule(ruleId) {
  await store.deleteAutomationRule(ruleId)
}

onMounted(() => {
  const boardId = boardsStore.currentBoard?.id
  if (boardId) store.fetchAutomationRules(boardId)
})
</script>
