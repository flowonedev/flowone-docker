<template>
  <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-3xl max-h-[85vh] flex flex-col overflow-hidden">
      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
        <div>
          <h3 class="text-lg font-semibold text-surface-800 dark:text-surface-200">New Automation Rule</h3>
          <p class="text-xs text-surface-400 mt-0.5">Start with a template to set up faster, or build from scratch</p>
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
          class="w-full mb-5 p-4 rounded-xl border-2 border-dashed border-surface-300 dark:border-surface-600 hover:border-primary-400 dark:hover:border-primary-500 hover:bg-primary-50/50 dark:hover:bg-primary-500/5 transition-all text-left group"
        >
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-surface-100 dark:bg-surface-700 flex items-center justify-center group-hover:bg-primary-100 dark:group-hover:bg-primary-500/20 transition-colors">
              <span class="material-symbols-rounded text-surface-400 group-hover:text-primary-500 transition-colors">add</span>
            </div>
            <div>
              <p class="text-sm font-semibold text-surface-700 dark:text-surface-300 group-hover:text-primary-600 dark:group-hover:text-primary-400">Blank Rule</p>
              <p class="text-xs text-surface-400">Start from scratch — choose any trigger and action</p>
            </div>
          </div>
        </button>

        <!-- Grouped templates -->
        <div v-for="(templates, category) in filteredCategories" :key="category" class="mb-6">
          <h4 class="text-[11px] font-semibold text-surface-400 uppercase tracking-wider mb-3 px-1">{{ category }}</h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <button
              v-for="tpl in templates"
              :key="tpl.id"
              @click="$emit('select', tpl)"
              class="p-4 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-600 hover:bg-primary-50/30 dark:hover:bg-primary-500/5 transition-all text-left group"
            >
              <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors"
                     :class="tpl.iconBg">
                  <span class="material-symbols-rounded text-xl" :class="tpl.iconColor">{{ tpl.icon }}</span>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-semibold text-surface-700 dark:text-surface-300 group-hover:text-primary-600 dark:group-hover:text-primary-400">{{ tpl.name }}</p>
                  <p class="text-[11px] text-surface-400 mt-0.5 line-clamp-2">{{ tpl.description }}</p>
                  <div class="flex items-center gap-1.5 mt-2.5">
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center gap-0.5">
                      <span class="material-symbols-rounded" style="font-size:10px">{{ tpl.triggerIcon }}</span>
                      {{ tpl.triggerLabel }}
                    </span>
                    <span class="material-symbols-rounded text-surface-300 text-xs">arrow_forward</span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center gap-0.5">
                      <span class="material-symbols-rounded" style="font-size:10px">{{ tpl.actionIcon }}</span>
                      {{ tpl.actionLabel }}
                    </span>
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
  // --- Deals ---
  {
    id: 'follow_up_stale',
    category: 'Deal Management',
    name: 'Follow Up Stale Deals',
    description: 'Create a reminder when a deal sits in the same stage for too long, so you never forget to follow up.',
    icon: 'hourglass_top',
    iconBg: 'bg-amber-50 dark:bg-amber-500/10',
    iconColor: 'text-amber-500',
    triggerIcon: 'hourglass_top',
    triggerLabel: 'Deal idle',
    actionIcon: 'alarm',
    actionLabel: 'Create reminder',
    trigger_type: 'deal_stage_idle',
    trigger_config: { stage: 'proposal', days: 7 },
    action_type: 'create_reminder',
    action_config: { title: 'Follow up on deal: {deal_title}', delay_hours: 0 },
    name_template: 'Follow up idle deals in Proposal',
    description_template: 'When a deal stays in Proposal for 7+ days, create a follow-up reminder.',
  },
  {
    id: 'invoice_deal_won',
    category: 'Deal Management',
    name: 'Auto-Invoice on Deal Won',
    description: 'Automatically create an invoice draft the moment a deal is marked as won.',
    icon: 'emoji_events',
    iconBg: 'bg-green-50 dark:bg-green-500/10',
    iconColor: 'text-green-500',
    triggerIcon: 'emoji_events',
    triggerLabel: 'Deal won',
    actionIcon: 'receipt',
    actionLabel: 'Create invoice',
    trigger_type: 'deal_won',
    trigger_config: {},
    action_type: 'create_invoice_draft',
    action_config: {},
    name_template: 'Invoice on deal won',
    description_template: 'Create invoice draft automatically when a deal is won.',
  },
  {
    id: 'advance_deal',
    category: 'Deal Management',
    name: 'Auto-Advance Deal Stage',
    description: 'Move a deal to the next stage automatically when it enters a specific stage and conditions are met.',
    icon: 'arrow_forward',
    iconBg: 'bg-blue-50 dark:bg-blue-500/10',
    iconColor: 'text-blue-500',
    triggerIcon: 'swap_horiz',
    triggerLabel: 'Stage changed',
    actionIcon: 'arrow_forward',
    actionLabel: 'Move deal',
    trigger_type: 'deal_stage_changed',
    trigger_config: { stage: 'contacted' },
    action_type: 'move_deal_stage',
    action_config: { to_stage: 'proposal' },
    name_template: 'Advance deal from Contacted',
    description_template: 'Move deal from Contacted to Proposal automatically.',
  },
  {
    id: 'notify_deal_lost',
    category: 'Deal Management',
    name: 'Alert on Lost Deal',
    description: 'Get a notification whenever a deal is marked as lost for post-mortem review.',
    icon: 'cancel',
    iconBg: 'bg-red-50 dark:bg-red-500/10',
    iconColor: 'text-red-500',
    triggerIcon: 'cancel',
    triggerLabel: 'Deal lost',
    actionIcon: 'notifications',
    actionLabel: 'Notify',
    trigger_type: 'deal_lost',
    trigger_config: {},
    action_type: 'notify_user',
    action_config: { message: 'Deal "{deal_title}" was lost. Review and learn.' },
    name_template: 'Notify on deal lost',
    description_template: 'Get notified when a deal is marked as lost.',
  },

  // --- Client Management ---
  {
    id: 'chase_overdue_invoice',
    category: 'Client Management',
    name: 'Chase Overdue Invoices',
    description: 'Automatically send a follow-up email when an invoice is overdue by a set number of days.',
    icon: 'schedule',
    iconBg: 'bg-orange-50 dark:bg-orange-500/10',
    iconColor: 'text-orange-500',
    triggerIcon: 'schedule',
    triggerLabel: 'Invoice overdue',
    actionIcon: 'mail',
    actionLabel: 'Send email',
    trigger_type: 'invoice_overdue',
    trigger_config: { days: 7 },
    action_type: 'send_email',
    action_config: { subject: 'Friendly reminder: Invoice #{invoice_number}', body_html: '<p>Hi {client_name},</p><p>This is a friendly reminder that invoice #{invoice_number} for {invoice_amount} is now overdue. Could you please arrange payment at your earliest convenience?</p><p>Thanks!</p>' },
    name_template: 'Chase invoices overdue 7+ days',
    description_template: 'Send reminder email when invoice is 7+ days overdue.',
  },
  {
    id: 'reengage_dormant',
    category: 'Client Management',
    name: 'Re-Engage Dormant Clients',
    description: 'Start an email sequence when there\'s been no contact with a client for a specified period.',
    icon: 'person_off',
    iconBg: 'bg-purple-50 dark:bg-purple-500/10',
    iconColor: 'text-purple-500',
    triggerIcon: 'person_off',
    triggerLabel: 'No contact',
    actionIcon: 'route',
    actionLabel: 'Start sequence',
    trigger_type: 'no_contact_days',
    trigger_config: { days: 30 },
    action_type: 'start_sequence',
    action_config: { sequence_id: '' },
    name_template: 'Re-engage after 30 days silence',
    description_template: 'Start re-engagement sequence when no contact for 30+ days.',
  },
  {
    id: 'alert_low_health',
    category: 'Client Management',
    name: 'Alert on Low Client Health',
    description: 'Get notified when a client\'s health score drops below a threshold so you can take action.',
    icon: 'heart_broken',
    iconBg: 'bg-pink-50 dark:bg-pink-500/10',
    iconColor: 'text-pink-500',
    triggerIcon: 'heart_broken',
    triggerLabel: 'Health low',
    actionIcon: 'notifications',
    actionLabel: 'Notify',
    trigger_type: 'client_health_low',
    trigger_config: { threshold: 30 },
    action_type: 'notify_user',
    action_config: { message: 'Client "{client_name}" health score is low. Schedule a check-in.' },
    name_template: 'Alert on client health < 30',
    description_template: 'Notify when client health score drops below 30.',
  },

  // --- Cross-Feature ---
  {
    id: 'invoice_board_close',
    category: 'Cross-Feature',
    name: 'Invoice When Board Closes',
    description: 'Create an invoice draft when a project board is marked as completed/closed.',
    icon: 'check_box',
    iconBg: 'bg-emerald-50 dark:bg-emerald-500/10',
    iconColor: 'text-emerald-500',
    triggerIcon: 'check_box',
    triggerLabel: 'Board closed',
    actionIcon: 'receipt',
    actionLabel: 'Create invoice',
    trigger_type: 'board_closed',
    trigger_config: {},
    action_type: 'create_invoice_draft',
    action_config: {},
    name_template: 'Invoice on board completion',
    description_template: 'Create invoice draft when a board is closed.',
  },
  {
    id: 'onboarding_won',
    category: 'Cross-Feature',
    name: 'Onboarding Sequence on Won',
    description: 'Kick off a client onboarding email sequence the moment a deal is won.',
    icon: 'rocket_launch',
    iconBg: 'bg-indigo-50 dark:bg-indigo-500/10',
    iconColor: 'text-indigo-500',
    triggerIcon: 'emoji_events',
    triggerLabel: 'Deal won',
    actionIcon: 'route',
    actionLabel: 'Start sequence',
    trigger_type: 'deal_won',
    trigger_config: {},
    action_type: 'start_sequence',
    action_config: { sequence_id: '' },
    name_template: 'Start onboarding on deal won',
    description_template: 'Enroll new client into onboarding sequence when deal is won.',
  },
  {
    id: 'task_assign',
    category: 'Cross-Feature',
    name: 'Assign Task on Stage Change',
    description: 'Create and assign a task to a colleague when a deal enters a specific stage.',
    icon: 'add_task',
    iconBg: 'bg-teal-50 dark:bg-teal-500/10',
    iconColor: 'text-teal-500',
    triggerIcon: 'swap_horiz',
    triggerLabel: 'Stage changed',
    actionIcon: 'add_task',
    actionLabel: 'Assign task',
    trigger_type: 'deal_stage_changed',
    trigger_config: { stage: 'won' },
    action_type: 'assign_task',
    action_config: { title: 'Set up project for: {deal_title}', description: '', assign_to_email: '', due_days: 3, priority: 'high' },
    name_template: 'Create setup task on deal won',
    description_template: 'Assign project setup task when deal reaches Won stage.',
  },

  // --- Team ---
  {
    id: 'sick_reassign',
    category: 'Team',
    name: 'Reassign Deals on Sick Leave',
    description: 'Automatically reassign active deals when a team member sets their status to sick.',
    icon: 'sick',
    iconBg: 'bg-rose-50 dark:bg-rose-500/10',
    iconColor: 'text-rose-500',
    triggerIcon: 'sick',
    triggerLabel: 'Colleague sick',
    actionIcon: 'swap_calls',
    actionLabel: 'Reassign deals',
    trigger_type: 'colleague_sick_status',
    trigger_config: { keyword: 'sick' },
    action_type: 'reassign_deals',
    action_config: { new_owner_email: '' },
    name_template: 'Reassign deals when colleague is sick',
    description_template: 'Auto-reassign deals when a team member calls in sick.',
  },
  {
    id: 'time_alert',
    category: 'Team',
    name: 'Alert on Hours Threshold',
    description: 'Get notified when tracked time on a project or client exceeds a weekly/monthly threshold.',
    icon: 'timer',
    iconBg: 'bg-cyan-50 dark:bg-cyan-500/10',
    iconColor: 'text-cyan-500',
    triggerIcon: 'timer',
    triggerLabel: 'Time threshold',
    actionIcon: 'notifications',
    actionLabel: 'Notify',
    trigger_type: 'time_spent_reached',
    trigger_config: { hours: 40, period: 'week', scope: 'board' },
    action_type: 'notify_user',
    action_config: { message: 'Time tracked on "{target_name}" has exceeded {threshold} hrs this {period}.' },
    name_template: 'Alert on 40+ hours per week',
    description_template: 'Notify when tracked hours exceed 40h/week per project.',
  },

  // --- Email Tracking ---
  {
    id: 'follow_up_email_open',
    category: 'Email Tracking',
    name: 'Follow Up on Email Open',
    description: 'Start an email sequence when a recipient opens your tracked email, to capitalize on their interest.',
    icon: 'mark_email_read',
    iconBg: 'bg-emerald-50 dark:bg-emerald-500/10',
    iconColor: 'text-emerald-500',
    triggerIcon: 'mark_email_read',
    triggerLabel: 'Email opened',
    actionIcon: 'route',
    actionLabel: 'Start sequence',
    trigger_type: 'email_opened',
    trigger_config: { scope: 'all', campaign_id: '' },
    action_type: 'start_sequence',
    action_config: { sequence_id: '' },
    name_template: 'Follow up when email is opened',
    description_template: 'Enroll recipient into a follow-up sequence when they open your email.',
  },
  {
    id: 'reengage_link_clickers',
    category: 'Email Tracking',
    name: 'Re-engage Link Clickers',
    description: 'Send a targeted follow-up email when someone clicks a specific link in your email.',
    icon: 'ads_click',
    iconBg: 'bg-blue-50 dark:bg-blue-500/10',
    iconColor: 'text-blue-500',
    triggerIcon: 'ads_click',
    triggerLabel: 'Link clicked',
    actionIcon: 'mail',
    actionLabel: 'Send email',
    trigger_type: 'email_link_clicked',
    trigger_config: { scope: 'all', campaign_id: '', link_match: 'any', link_value: '' },
    action_type: 'send_email',
    action_config: { subject: 'Thanks for your interest!', body_html: '<p>Hi {recipient_name},</p><p>I noticed you checked out the link I sent. Would you like to learn more?</p>' },
    name_template: 'Send follow-up when link is clicked',
    description_template: 'Automatically send a follow-up email when a recipient clicks a link.',
  },
  {
    id: 'task_on_link_click',
    category: 'Email Tracking',
    name: 'Task on Link Click',
    description: 'Create a task when someone clicks a link in your email, so you remember to follow up personally.',
    icon: 'add_task',
    iconBg: 'bg-violet-50 dark:bg-violet-500/10',
    iconColor: 'text-violet-500',
    triggerIcon: 'ads_click',
    triggerLabel: 'Link clicked',
    actionIcon: 'add_task',
    actionLabel: 'Assign task',
    trigger_type: 'email_link_clicked',
    trigger_config: { scope: 'all', campaign_id: '', link_match: 'any', link_value: '' },
    action_type: 'assign_task',
    action_config: { title: 'Follow up with {recipient_name} - clicked link in "{email_subject}"', description: 'Recipient {recipient_email} clicked: {link_url}', assign_to_email: '', due_days: 1, priority: 'high' },
    name_template: 'Create task on email link click',
    description_template: 'Assign a follow-up task when a recipient clicks a tracked link.',
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

