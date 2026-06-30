<template>
  <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-2xl max-h-[80vh] flex flex-col overflow-hidden">
      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
        <div>
          <h3 class="text-lg font-semibold text-surface-800 dark:text-surface-200">New Automation</h3>
          <p class="text-xs text-surface-400 mt-0.5">Pick a template or start from scratch</p>
        </div>
        <button @click="$emit('close')" class="p-1.5 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
          <span class="material-symbols-rounded">close</span>
        </button>
      </div>

      <!-- Search -->
      <div class="px-6 py-3 border-b border-surface-100 dark:border-surface-700/50">
        <div class="relative">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
          <input
            v-model="search"
            type="text"
            placeholder="Search templates..."
            class="w-full pl-9 pr-3 py-2 text-sm border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-900 text-surface-800 dark:text-surface-200 rounded-xl focus:ring-1 focus:ring-primary-500/40 outline-none"
          />
        </div>
      </div>

      <!-- Templates -->
      <div class="flex-1 overflow-y-auto p-6">
        <!-- Blank Rule -->
        <button
          @click="$emit('select', null)"
          class="w-full mb-4 p-4 rounded-xl border-2 border-dashed border-surface-300 dark:border-surface-600 hover:border-primary-400 dark:hover:border-primary-500 hover:bg-primary-50/50 dark:hover:bg-primary-500/5 transition-all text-left group"
        >
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-surface-100 dark:bg-surface-700 flex items-center justify-center group-hover:bg-primary-100 dark:group-hover:bg-primary-500/20 transition-colors">
              <span class="material-symbols-rounded text-surface-400 group-hover:text-primary-500 transition-colors">add</span>
            </div>
            <div>
              <p class="text-sm font-semibold text-surface-700 dark:text-surface-300 group-hover:text-primary-600 dark:group-hover:text-primary-400">Blank Rule</p>
              <p class="text-xs text-surface-400">Start from scratch with full control</p>
            </div>
          </div>
        </button>

        <!-- Grouped templates -->
        <div v-for="(templates, category) in filteredCategories" :key="category" class="mb-5">
          <h4 class="text-[11px] font-semibold text-surface-400 uppercase tracking-wider mb-2 px-1">{{ category }}</h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <button
              v-for="tpl in templates"
              :key="tpl.id"
              @click="$emit('select', tpl)"
              class="p-3 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-600 hover:bg-primary-50/30 dark:hover:bg-primary-500/5 transition-all text-left group"
            >
              <div class="flex items-start gap-3">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors"
                     :class="tpl.iconBg">
                  <span class="material-symbols-rounded text-lg" :class="tpl.iconColor">{{ tpl.icon }}</span>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300 group-hover:text-primary-600 dark:group-hover:text-primary-400 truncate">{{ tpl.name }}</p>
                  <p class="text-[11px] text-surface-400 mt-0.5 line-clamp-2">{{ tpl.description }}</p>
                  <div class="flex items-center gap-1.5 mt-2">
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400">{{ tpl.triggerLabel }}</span>
                    <span class="material-symbols-rounded text-surface-300 text-xs">arrow_forward</span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400">{{ tpl.actionLabel }}</span>
                  </div>
                </div>
              </div>
            </button>
          </div>
        </div>

        <div v-if="Object.keys(filteredCategories).length === 0" class="text-center py-8 text-surface-400">
          <span class="material-symbols-rounded text-3xl mb-2 block">search_off</span>
          <p class="text-sm">No templates match "{{ search }}"</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

defineEmits(['close', 'select'])

const search = ref('')

const templates = [
  // --- Notifications & Alerts ---
  {
    id: 'notify_overdue',
    category: 'Notifications',
    name: 'Alert on Overdue Cards',
    description: 'Get notified when a card passes its due date so nothing slips through.',
    icon: 'alarm',
    iconBg: 'bg-red-50 dark:bg-red-500/10',
    iconColor: 'text-red-500',
    triggerLabel: 'Card overdue',
    actionLabel: 'Notify',
    trigger_type: 'card_overdue',
    action_type: 'send_notification',
    trigger_config: {},
    action_config: { message: 'Card "{{card_title}}" is overdue and needs attention!' },
  },
  {
    id: 'notify_idle',
    category: 'Notifications',
    name: 'Idle Card Reminder',
    description: 'Get alerted when a card hasn\'t been updated for several days.',
    icon: 'snooze',
    iconBg: 'bg-amber-50 dark:bg-amber-500/10',
    iconColor: 'text-amber-500',
    triggerLabel: 'Card idle',
    actionLabel: 'Notify',
    trigger_type: 'card_idle_days',
    action_type: 'send_notification',
    trigger_config: { days: 7 },
    action_config: { message: 'Card "{{card_title}}" has been idle for {{idle_days}} days.' },
  },
  {
    id: 'notify_email',
    category: 'Notifications',
    name: 'New Email on Card',
    description: 'Get notified when an email arrives linked to a card.',
    icon: 'mark_email_unread',
    iconBg: 'bg-blue-50 dark:bg-blue-500/10',
    iconColor: 'text-blue-500',
    triggerLabel: 'Email received',
    actionLabel: 'Notify',
    trigger_type: 'email_received_on_card',
    action_type: 'send_notification',
    trigger_config: {},
    action_config: { message: 'New email on card "{{card_title}}" from {{sender}}.' },
  },

  // --- Workflow ---
  {
    id: 'move_completed',
    category: 'Workflow',
    name: 'Archive Completed Cards',
    description: 'Automatically move cards to a "Done" list when all checklists are complete.',
    icon: 'done_all',
    iconBg: 'bg-green-50 dark:bg-green-500/10',
    iconColor: 'text-green-500',
    triggerLabel: 'Card completed',
    actionLabel: 'Move card',
    trigger_type: 'card_completed',
    action_type: 'move_card',
    trigger_config: {},
    action_config: { target_list_name: 'Done' },
  },
  {
    id: 'assign_new',
    category: 'Workflow',
    name: 'Auto-Assign New Cards',
    description: 'Automatically assign a team member to every new card created in the board.',
    icon: 'person_add',
    iconBg: 'bg-indigo-50 dark:bg-indigo-500/10',
    iconColor: 'text-indigo-500',
    triggerLabel: 'Card created',
    actionLabel: 'Assign member',
    trigger_type: 'card_created',
    action_type: 'assign_member',
    trigger_config: {},
    action_config: { member_email: '' },
  },
  {
    id: 'move_by_label',
    category: 'Workflow',
    name: 'Move on Label Change',
    description: 'When a specific label is added to a card, move it to the corresponding list.',
    icon: 'label',
    iconBg: 'bg-pink-50 dark:bg-pink-500/10',
    iconColor: 'text-pink-500',
    triggerLabel: 'Label added',
    actionLabel: 'Move card',
    trigger_type: 'label_added',
    action_type: 'move_card',
    trigger_config: {},
    action_config: { target_list_name: '' },
  },

  // --- Billing & CRM ---
  {
    id: 'invoice_checklist',
    category: 'Billing',
    name: 'Invoice on Milestone Done',
    description: 'Create an invoice draft when all checklist items in a card are completed.',
    icon: 'receipt',
    iconBg: 'bg-emerald-50 dark:bg-emerald-500/10',
    iconColor: 'text-emerald-500',
    triggerLabel: 'Checklist done',
    actionLabel: 'Create invoice',
    trigger_type: 'checklist_completed',
    action_type: 'create_invoice_draft',
    trigger_config: {},
    action_config: {},
  },
  {
    id: 'invoice_card_completed',
    category: 'Billing',
    name: 'Invoice on Card Completion',
    description: 'Create an invoice draft automatically when a card is fully completed.',
    icon: 'paid',
    iconBg: 'bg-teal-50 dark:bg-teal-500/10',
    iconColor: 'text-teal-500',
    triggerLabel: 'Card completed',
    actionLabel: 'Create invoice',
    trigger_type: 'card_completed',
    action_type: 'create_invoice_draft',
    trigger_config: {},
    action_config: {},
  },
  {
    id: 'deal_on_move',
    category: 'Billing',
    name: 'Update Deal on Card Move',
    description: 'When a card is moved to a specific list, update the linked CRM deal stage.',
    icon: 'swap_horiz',
    iconBg: 'bg-violet-50 dark:bg-violet-500/10',
    iconColor: 'text-violet-500',
    triggerLabel: 'Card moved',
    actionLabel: 'Update deal',
    trigger_type: 'card_moved_to_list',
    action_type: 'update_deal_stage',
    trigger_config: {},
    action_config: { to_stage: '' },
  },

  // --- Communication ---
  {
    id: 'chat_overdue',
    category: 'Communication',
    name: 'Chat Alert on Overdue',
    description: 'Post a message in chat when a card becomes overdue to keep the team informed.',
    icon: 'chat',
    iconBg: 'bg-cyan-50 dark:bg-cyan-500/10',
    iconColor: 'text-cyan-500',
    triggerLabel: 'Card overdue',
    actionLabel: 'Chat message',
    trigger_type: 'card_overdue',
    action_type: 'post_chat_message',
    trigger_config: {},
    action_config: { message: '⚠️ Card "{{card_title}}" is now overdue. Assigned to: {{assigned_to}}' },
  },
  {
    id: 'chat_completed',
    category: 'Communication',
    name: 'Celebrate Completions',
    description: 'Post a chat message when a card is completed to celebrate team progress.',
    icon: 'celebration',
    iconBg: 'bg-yellow-50 dark:bg-yellow-500/10',
    iconColor: 'text-yellow-600',
    triggerLabel: 'Card completed',
    actionLabel: 'Chat message',
    trigger_type: 'card_completed',
    action_type: 'post_chat_message',
    trigger_config: {},
    action_config: { message: '🎉 "{{card_title}}" is complete! Great work, {{assigned_to}}!' },
  },
]

const filteredCategories = computed(() => {
  const q = search.value.toLowerCase().trim()
  const groups = {}
  for (const tpl of templates) {
    if (q && !tpl.name.toLowerCase().includes(q) && !tpl.description.toLowerCase().includes(q) && !tpl.category.toLowerCase().includes(q)) {
      continue
    }
    if (!groups[tpl.category]) groups[tpl.category] = []
    groups[tpl.category].push(tpl)
  }
  return groups
})
</script>

