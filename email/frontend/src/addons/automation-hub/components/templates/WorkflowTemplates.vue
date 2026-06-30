<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:w-[780px] max-h-[92vh] sm:max-h-[85vh] flex flex-col">
      <!-- Header -->
      <div class="flex items-center gap-3 px-4 sm:px-6 py-4 border-b border-surface-200 dark:border-surface-700">
        <span class="material-symbols-rounded text-2xl text-primary-500 dark:text-primary-400">auto_awesome</span>
        <div class="min-w-0">
          <h2 class="text-base font-semibold text-surface-800 dark:text-surface-100">New Workflow</h2>
          <p class="text-xs text-surface-500 dark:text-surface-400 hidden sm:block">Pick a template to get started or create a blank workflow</p>
        </div>
        <div class="flex-1" />
        <button @click="$emit('close')" class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors">
          <span class="material-symbols-rounded text-xl">close</span>
        </button>
      </div>

      <!-- Search + category filter -->
      <div class="px-4 sm:px-6 py-3 border-b border-surface-100 dark:border-surface-700/50 flex flex-col sm:flex-row gap-2 sm:gap-3">
        <div class="relative flex-1">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
          <input
            v-model="search"
            type="text"
            placeholder="Search templates..."
            class="w-full pl-9 pr-3 py-2 text-sm bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 placeholder-surface-400 dark:placeholder-surface-500 rounded-xl focus:outline-none focus:border-primary-500 transition-colors"
          />
        </div>
        <div class="flex gap-1 overflow-x-auto -webkit-overflow-scrolling-touch pb-1 sm:pb-0">
          <button
            v-for="cat in allCategories"
            :key="cat"
            @click="activeCategory = activeCategory === cat ? null : cat"
            class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors whitespace-nowrap flex-shrink-0"
            :class="activeCategory === cat
              ? 'bg-primary-500 text-white'
              : 'bg-surface-50 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:text-surface-800 dark:hover:text-surface-200 border border-surface-200 dark:border-surface-600'"
          >{{ cat }}</button>
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-5">
        <!-- Blank Workflow -->
        <button
          @click="$emit('select', null)"
          class="w-full p-4 rounded-xl border-2 border-dashed border-surface-300 dark:border-surface-600 hover:border-primary-400 dark:hover:border-primary-500/50 hover:bg-primary-50 dark:hover:bg-primary-500/5 transition-all text-left group"
        >
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-surface-100 dark:bg-surface-700 flex items-center justify-center group-hover:bg-primary-100 dark:group-hover:bg-primary-500/20 transition-colors">
              <span class="material-symbols-rounded text-surface-400 group-hover:text-primary-500 dark:group-hover:text-primary-400 transition-colors">add</span>
            </div>
            <div>
              <p class="text-sm font-semibold text-surface-600 dark:text-surface-300 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">Blank Workflow</p>
              <p class="text-xs text-surface-500">Start from scratch with an empty canvas</p>
            </div>
          </div>
        </button>

        <!-- Grouped templates -->
        <div v-for="(items, category) in filteredByCategory" :key="category">
          <h3 class="text-[10px] font-bold text-surface-400 uppercase tracking-wider mb-2 px-1">{{ category }}</h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <button
              v-for="tpl in items"
              :key="tpl.id"
              @click="$emit('select', tpl)"
              class="bg-surface-50 dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-500/40 hover:bg-white dark:hover:bg-surface-750 transition-all text-left group p-4"
            >
              <div class="flex items-start gap-3 mb-2">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" :class="tpl.iconBg">
                  <span class="material-symbols-rounded text-lg" :class="tpl.iconColor">{{ tpl.icon }}</span>
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-semibold text-surface-700 dark:text-surface-200 group-hover:text-primary-600 dark:group-hover:text-primary-300 truncate transition-colors">{{ tpl.name }}</div>
                  <div class="text-[11px] text-surface-500 mt-0.5 line-clamp-2">{{ tpl.description }}</div>
                </div>
              </div>

              <!-- Node flow preview -->
              <div class="flex items-center gap-1 mt-3 flex-wrap">
                <template v-for="(node, i) in tpl.nodes" :key="i">
                  <span
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium"
                    :class="nodeChipClass(node.type)"
                  >
                    <span class="material-symbols-rounded text-[10px]">{{ nodeChipIcon(node.type) }}</span>
                    {{ nodeChipLabel(node.type) }}
                  </span>
                  <span v-if="i < tpl.nodes.length - 1" class="material-symbols-rounded text-surface-600 text-xs">arrow_forward</span>
                </template>
              </div>
            </button>
          </div>
        </div>

        <div v-if="Object.keys(filteredByCategory).length === 0 && search" class="text-center py-12">
          <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">search_off</span>
          <p class="text-xs text-surface-500 mt-2">No templates match "{{ search }}"</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useNodeRegistry } from '../../composables/useNodeRegistry'

defineEmits(['close', 'select'])

const { getNodeDef, getCategoryColors } = useNodeRegistry()

const search = ref('')
const activeCategory = ref(null)

const templates = [
  // ── Getting Started ───────────────────────────────────────────────────
  {
    id: 'manual-hello-world',
    name: 'Hello World',
    category: 'Getting Started',
    description: 'A simple workflow to test the system. Click Test to run it and see a notification appear.',
    icon: 'waving_hand',
    iconBg: 'bg-amber-500/15',
    iconColor: 'text-amber-400',
    nodes: [
      { type: 'trigger.manual', x: 100, y: 200, config: {} },
      { type: 'action.notification.send', x: 420, y: 200, config: { title: 'Hello from Workflows!', message: 'Your first workflow ran successfully.', recipient_type: 'trigger_user' } },
    ],
    edges: [{ from: 0, to: 1 }],
  },
  {
    id: 'manual-email-stats-csv',
    name: 'Email Stats to CSV',
    category: 'Getting Started',
    description: 'Fetch email statistics for the last 7 days and export to a CSV file. Click Test to run.',
    icon: 'download',
    iconBg: 'bg-blue-500/15',
    iconColor: 'text-blue-400',
    nodes: [
      { type: 'trigger.manual', x: 100, y: 200, config: {} },
      { type: 'action.stats.email', x: 400, y: 200, config: { period: '7d' } },
      { type: 'action.export.csv', x: 700, y: 200, config: { source: 'input', filename: 'email-stats-{timestamp}' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 1, to: 2 }],
  },

  // ── Server Monitoring ─────────────────────────────────────────────────
  {
    id: 'server-health-alert',
    name: 'Server Health Alert',
    category: 'Server Monitoring',
    description: 'Check server metrics every 5 minutes. If CPU or RAM exceeds 90%, send a Telegram alert.',
    icon: 'monitor_heart',
    iconBg: 'bg-red-500/15',
    iconColor: 'text-red-400',
    nodes: [
      { type: 'trigger.schedule.cron', x: 100, y: 200, config: { schedule_type: 'interval', interval_value: 5, interval_unit: 'minutes' } },
      { type: 'logic.condition', x: 420, y: 200, config: { field: 'value', operator: 'greater_than', value: '90' } },
      { type: 'action.telegram.send', x: 740, y: 120, config: { message: 'ALERT: {metric} is at {value}% on server' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, fromPort: 'true', to: 2 },
    ],
  },
  {
    id: 'daily-server-digest',
    name: 'Daily Server Digest',
    category: 'Server Monitoring',
    description: 'Send a daily Telegram summary of all server metrics every morning at 8:00.',
    icon: 'summarize',
    iconBg: 'bg-blue-500/15',
    iconColor: 'text-blue-400',
    nodes: [
      { type: 'trigger.schedule.cron', x: 100, y: 200, config: { schedule_type: 'daily', daily_time: '08:00' } },
      { type: 'action.telegram.send', x: 420, y: 200, config: { message: 'Daily Server Report\nCPU: {cpu_load}%\nRAM: {memory_usage}%\nDisk: {disk_usage}%' } },
    ],
    edges: [{ from: 0, to: 1 }],
  },
  {
    id: 'service-down-multi',
    name: 'Service Down Alert',
    category: 'Server Monitoring',
    description: 'When a critical service goes down, send both a Telegram message and an in-app notification.',
    icon: 'error',
    iconBg: 'bg-amber-500/15',
    iconColor: 'text-amber-400',
    nodes: [
      { type: 'trigger.server.health', x: 100, y: 200, config: { metric: 'service_status', service: 'postfix', status_condition: 'stopped' } },
      { type: 'action.telegram.send', x: 420, y: 120, config: { message: 'CRITICAL: {service} is DOWN on the server!' } },
      { type: 'action.notification.send', x: 420, y: 310, config: { title: 'Service Down', message: '{service} is not running', recipient_type: 'trigger_user' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 0, to: 2 }],
  },

  // ── CRM ───────────────────────────────────────────────────────────────
  {
    id: 'deal-won-celebration',
    name: 'Deal Won Celebration',
    category: 'CRM',
    description: 'When a deal is won, post a celebration to team chat and send a Telegram notification.',
    icon: 'emoji_events',
    iconBg: 'bg-emerald-500/15',
    iconColor: 'text-emerald-400',
    nodes: [
      { type: 'trigger.crm.deal_won', x: 100, y: 200, config: {} },
      { type: 'action.chat.send', x: 420, y: 120, config: { message: 'Deal "{deal_title}" worth {expected_value} has been WON!' } },
      { type: 'action.telegram.send', x: 420, y: 310, config: { message: 'New deal closed: {deal_title} - {expected_value}' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 0, to: 2 }],
  },
  {
    id: 'deal-lost-followup',
    name: 'Deal Lost Follow-up',
    category: 'CRM',
    description: 'When a deal is lost, wait 3 days, then create a follow-up task to re-engage the prospect.',
    icon: 'thumb_down',
    iconBg: 'bg-red-500/15',
    iconColor: 'text-red-400',
    nodes: [
      { type: 'trigger.crm.deal_lost', x: 100, y: 200, config: {} },
      { type: 'logic.delay', x: 400, y: 200, config: { delay_value: 3, delay_unit: 'days' } },
      { type: 'action.task.create', x: 700, y: 200, config: { title: 'Re-engage: {deal_title}', description: 'Lost reason: {lost_reason}. Consider reaching out again.' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 1, to: 2 }],
  },
  {
    id: 'overdue-invoice-escalation',
    name: 'Overdue Invoice Escalation',
    category: 'CRM',
    description: 'Escalate overdue invoices: email the manager for high-value ones, create a reminder task for others.',
    icon: 'receipt_long',
    iconBg: 'bg-purple-500/15',
    iconColor: 'text-purple-400',
    nodes: [
      { type: 'trigger.crm.invoice_overdue', x: 100, y: 200, config: {} },
      { type: 'logic.condition', x: 420, y: 200, config: { field: 'amount', operator: 'greater_than', value: '1000' } },
      { type: 'action.email.send', x: 740, y: 120, config: { subject: 'High-value invoice overdue: #{invoice_id}', body: 'Invoice #{invoice_id} for {client_name} ({amount}) is overdue. Please escalate.' } },
      { type: 'action.task.create', x: 740, y: 310, config: { title: 'Follow up on invoice #{invoice_id} - {client_name}' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, fromPort: 'true', to: 2 },
      { from: 1, fromPort: 'false', to: 3 },
    ],
  },
  {
    id: 'deal-stage-notify',
    name: 'Deal Stage Notification',
    category: 'CRM',
    description: 'When a deal moves to a new stage, notify the deal owner and log it in team chat.',
    icon: 'trending_up',
    iconBg: 'bg-cyan-500/15',
    iconColor: 'text-cyan-400',
    nodes: [
      { type: 'trigger.crm.deal_stage_changed', x: 100, y: 200, config: {} },
      { type: 'action.notification.send', x: 420, y: 120, config: { title: 'Deal moved: {deal_title}', message: 'Moved from {from_stage} to {to_stage}', recipient_type: 'trigger_user' } },
      { type: 'action.chat.send', x: 420, y: 310, config: { message: 'Deal "{deal_title}" moved to {to_stage} ({expected_value})' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 0, to: 2 }],
  },

  // ── Board ─────────────────────────────────────────────────────────────
  {
    id: 'card-completed-notify',
    name: 'Card Completed Notification',
    category: 'Board',
    description: 'When a card is completed, send a notification and post a message to the team chat.',
    icon: 'task_alt',
    iconBg: 'bg-cyan-500/15',
    iconColor: 'text-cyan-400',
    nodes: [
      { type: 'trigger.board.card_completed', x: 100, y: 200, config: {} },
      { type: 'action.chat.send', x: 420, y: 200, config: { message: 'Card "{card_title}" has been completed by {user_email}!' } },
    ],
    edges: [{ from: 0, to: 1 }],
  },
  {
    id: 'card-overdue-escalation',
    name: 'Overdue Card Escalation',
    category: 'Board',
    description: 'When a card passes its due date, send an email reminder and create a notification.',
    icon: 'schedule',
    iconBg: 'bg-orange-500/15',
    iconColor: 'text-orange-400',
    nodes: [
      { type: 'trigger.board.card_overdue', x: 100, y: 200, config: {} },
      { type: 'action.email.send', x: 420, y: 120, config: { subject: 'Overdue: {card_title}', body: 'Card "{card_title}" was due on {due_date} and is now overdue.' } },
      { type: 'action.notification.send', x: 420, y: 310, config: { title: 'Overdue card', message: '"{card_title}" is past due', recipient_type: 'trigger_user' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 0, to: 2 }],
  },
  {
    id: 'card-moved-auto-task',
    name: 'Auto-task on Card Move',
    category: 'Board',
    description: 'When a card is moved to a specific list (e.g. "In Review"), automatically create a review task.',
    icon: 'swap_horiz',
    iconBg: 'bg-indigo-500/15',
    iconColor: 'text-indigo-400',
    nodes: [
      { type: 'trigger.board.card_moved', x: 100, y: 200, config: {} },
      { type: 'logic.condition', x: 420, y: 200, config: { field: 'to_list', operator: 'equals', value: 'In Review' } },
      { type: 'action.task.create', x: 740, y: 120, config: { title: 'Review: {card_title}', description: 'Card moved to review. Please check and approve.' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, fromPort: 'true', to: 2 },
    ],
  },

  // ── Clients ───────────────────────────────────────────────────────────
  {
    id: 'client-health-monitor',
    name: 'Client Health Monitor',
    category: 'Clients',
    description: 'When a client health score drops below threshold, fetch their data and send an alert to the team.',
    icon: 'heart_broken',
    iconBg: 'bg-red-500/15',
    iconColor: 'text-red-400',
    nodes: [
      { type: 'trigger.client.health_low', x: 100, y: 200, config: { threshold: 40 } },
      { type: 'action.client.get_data', x: 400, y: 200, config: { client_source: 'trigger' } },
      { type: 'action.notification.send', x: 700, y: 200, config: { title: 'Client at risk: {client_name}', message: 'Health score: {health_score}. {open_task_count} open tasks.', recipient_type: 'trigger_user' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 1, to: 2 }],
  },
  {
    id: 'inactive-client-followup',
    name: 'Inactive Client Follow-up',
    category: 'Clients',
    description: 'When a client has been inactive, send an email to re-engage and create a follow-up task.',
    icon: 'person_off',
    iconBg: 'bg-amber-500/15',
    iconColor: 'text-amber-400',
    nodes: [
      { type: 'trigger.client.inactive', x: 100, y: 200, config: { days: 30 } },
      { type: 'action.email.send', x: 420, y: 120, config: { subject: 'Checking in - {client_name}', body: 'Hi, we noticed it has been a while since we last connected. Just checking in!' } },
      { type: 'action.task.create', x: 420, y: 310, config: { title: 'Follow up: {client_name}', description: 'Client inactive for {days_inactive} days' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 0, to: 2 }],
  },

  // ── Financial ─────────────────────────────────────────────────────────
  {
    id: 'invoice-paid-notify',
    name: 'Invoice Paid Notification',
    category: 'Financial',
    description: 'When an invoice is paid, send a chat message and create a payment receipt task.',
    icon: 'paid',
    iconBg: 'bg-emerald-500/15',
    iconColor: 'text-emerald-400',
    nodes: [
      { type: 'trigger.invoice.paid', x: 100, y: 200, config: {} },
      { type: 'action.chat.send', x: 420, y: 120, config: { message: 'Invoice {invoice_number} for {client_name} has been paid! ({paid_amount} {currency})' } },
      { type: 'action.notification.send', x: 420, y: 310, config: { title: 'Payment received', message: '{invoice_number}: {paid_amount} {currency}', recipient_type: 'trigger_user' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 0, to: 2 }],
  },
  {
    id: 'weekly-revenue-report',
    name: 'Weekly Revenue Report',
    category: 'Financial',
    description: 'Every Monday morning, generate a revenue report for the past week and send it via Telegram.',
    icon: 'bar_chart',
    iconBg: 'bg-blue-500/15',
    iconColor: 'text-blue-400',
    nodes: [
      { type: 'trigger.schedule.cron', x: 100, y: 200, config: { schedule_type: 'cron', cron_expression: '0 8 * * 1' } },
      { type: 'action.stats.revenue_report', x: 420, y: 200, config: { period: '7d' } },
      { type: 'action.telegram.send', x: 740, y: 200, config: { message: 'Weekly Revenue Report\nRevenue: {total_revenue}\nExpenses: {total_expenses}\nProfit: {net_profit}' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 1, to: 2 }],
  },
  {
    id: 'monthly-aging-report',
    name: 'Monthly Aging Report',
    category: 'Financial',
    description: 'On the 1st of each month, generate an invoice aging report and export to CSV.',
    icon: 'update',
    iconBg: 'bg-purple-500/15',
    iconColor: 'text-purple-400',
    nodes: [
      { type: 'trigger.schedule.cron', x: 100, y: 200, config: { schedule_type: 'cron', cron_expression: '0 9 1 * *' } },
      { type: 'action.stats.aging_report', x: 420, y: 200, config: {} },
      { type: 'action.export.csv', x: 740, y: 200, config: { source: 'input', filename: 'aging-report-{timestamp}' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 1, to: 2 }],
  },

  // ── Statistics ────────────────────────────────────────────────────────
  {
    id: 'daily-response-time-check',
    name: 'Response Time Monitor',
    category: 'Statistics',
    description: 'Check response times daily. If average exceeds 4 hours, alert the team via notification.',
    icon: 'speed',
    iconBg: 'bg-orange-500/15',
    iconColor: 'text-orange-400',
    nodes: [
      { type: 'trigger.schedule.cron', x: 100, y: 200, config: { schedule_type: 'daily', daily_time: '09:00' } },
      { type: 'action.stats.response_time', x: 400, y: 200, config: { period: '1d' } },
      { type: 'logic.condition', x: 680, y: 200, config: { field: 'avg_reply_time', operator: 'greater_than', value: '14400' } },
      { type: 'action.notification.send', x: 960, y: 120, config: { title: 'Slow response times', message: 'Average reply time is {avg_reply_time_formatted}. Please check your inbox.', recipient_type: 'trigger_user' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, to: 2 },
      { from: 2, fromPort: 'true', to: 3 },
    ],
  },
  {
    id: 'client-ranking-export',
    name: 'Client Ranking Export',
    category: 'Statistics',
    description: 'On demand, generate a client ranking by revenue and export to CSV.',
    icon: 'leaderboard',
    iconBg: 'bg-indigo-500/15',
    iconColor: 'text-indigo-400',
    nodes: [
      { type: 'trigger.manual', x: 100, y: 200, config: {} },
      { type: 'action.stats.client_ranking', x: 400, y: 200, config: { period: '30d', limit: 20 } },
      { type: 'action.export.csv', x: 700, y: 200, config: { source: 'input', filename: 'client-ranking-{timestamp}' } },
    ],
    edges: [{ from: 0, to: 1 }, { from: 1, to: 2 }],
  },

  // ── Telegram ──────────────────────────────────────────────────────────
  {
    id: 'telegram-command-server',
    name: 'Telegram Server Check',
    category: 'Telegram',
    description: 'Reply to a Telegram "/status" command with the current server health metrics.',
    icon: 'send',
    iconBg: 'bg-sky-500/15',
    iconColor: 'text-sky-400',
    nodes: [
      { type: 'trigger.telegram.message', x: 100, y: 200, config: {} },
      { type: 'logic.condition', x: 400, y: 200, config: { field: 'telegram_text', operator: 'equals', value: '/status' } },
      { type: 'action.telegram.send', x: 700, y: 120, config: { message: 'Server Status\nCPU: OK\nRAM: OK\nDisk: OK\nAll services running.' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, fromPort: 'true', to: 2 },
    ],
  },

  // ── Webhook ───────────────────────────────────────────────────────────
  {
    id: 'webhook-to-chat',
    name: 'Webhook to Chat',
    category: 'Integrations',
    description: 'Receive a webhook from an external service and post the payload to a chat channel.',
    icon: 'webhook',
    iconBg: 'bg-violet-500/15',
    iconColor: 'text-violet-400',
    nodes: [
      { type: 'trigger.webhook.incoming', x: 100, y: 200, config: {} },
      { type: 'action.chat.send', x: 420, y: 200, config: { message: 'Webhook received: {webhook_body}' } },
    ],
    edges: [{ from: 0, to: 1 }],
  },
  {
    id: 'webhook-conditional-alert',
    name: 'Webhook Conditional Alert',
    category: 'Integrations',
    description: 'Receive a webhook, check a condition on the payload, and route to different actions.',
    icon: 'webhook',
    iconBg: 'bg-fuchsia-500/15',
    iconColor: 'text-fuchsia-400',
    nodes: [
      { type: 'trigger.webhook.incoming', x: 100, y: 200, config: {} },
      { type: 'logic.condition', x: 400, y: 200, config: { field: 'webhook_body.severity', operator: 'equals', value: 'critical' } },
      { type: 'action.telegram.send', x: 700, y: 120, config: { message: 'CRITICAL webhook alert: {webhook_body.message}' } },
      { type: 'action.notification.send', x: 700, y: 310, config: { title: 'Webhook alert', message: '{webhook_body.message}', recipient_type: 'trigger_user' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, fromPort: 'true', to: 2 },
      { from: 1, fromPort: 'false', to: 3 },
    ],
  },

  // ── Advanced ─────────────────────────────────────────────────────────
  {
    id: 'adv-client-onboarding-pipeline',
    name: 'Full Client Onboarding',
    category: 'Advanced',
    description: 'When a deal is won: fetch client data, push invoice to Billingo, start onboarding email sequence, notify the team on chat and Telegram, and create a welcome task.',
    icon: 'rocket_launch',
    iconBg: 'bg-gradient-to-br from-emerald-500/20 to-cyan-500/20',
    iconColor: 'text-emerald-400',
    nodes: [
      { type: 'trigger.crm.deal_won', x: 80, y: 250, config: {} },
      { type: 'action.client.get_data', x: 360, y: 250, config: { client_source: 'trigger' } },
      { type: 'action.invoice.push_billingo', x: 640, y: 100, config: {} },
      { type: 'action.sequence.start', x: 640, y: 250, config: {} },
      { type: 'action.task.create', x: 640, y: 400, config: { title: 'Onboard: {client_name}', description: 'New client won via deal "{deal_title}". Complete onboarding checklist.' } },
      { type: 'action.chat.send', x: 920, y: 175, config: { message: 'New client onboarded: {client_name}\nDeal: {deal_title} ({expected_value})\nInvoice pushed to Billingo. Sequence started.' } },
      { type: 'action.telegram.send', x: 920, y: 325, config: { message: 'NEW CLIENT: {client_name} - {expected_value}\nOnboarding sequence activated.' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, to: 2 },
      { from: 1, to: 3 },
      { from: 1, to: 4 },
      { from: 3, to: 5 },
      { from: 4, to: 6 },
    ],
  },
  {
    id: 'adv-contacts-backup-to-drive',
    name: 'Google Contacts Backup',
    category: 'Advanced',
    description: 'Fetch all Google contacts, export them to a CSV with name/email/phone/company, save to Drive in CSV Exports folder, and send a download link via email.',
    icon: 'backup',
    iconBg: 'bg-blue-500/15',
    iconColor: 'text-blue-400',
    nodes: [
      { type: 'trigger.manual', x: 80, y: 200, config: {} },
      { type: 'action.google.get_contacts', x: 360, y: 200, config: { fetch_all: true, max_results: 2000 } },
      { type: 'action.export.csv', x: 640, y: 200, config: { data_source: 'google_contacts', columns: 'name, email, phone, company', notify: 'email' } },
      { type: 'action.chat.send', x: 920, y: 120, config: { message: 'Contacts backup complete: {row_count} contacts exported to Drive.\nFile: {filename}' } },
      { type: 'action.notification.send', x: 920, y: 310, config: { title: 'Contacts backup done', message: '{row_count} contacts saved to CSV Exports folder', recipient_type: 'trigger_user' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, to: 2 },
      { from: 2, to: 3 },
      { from: 2, to: 4 },
    ],
  },
  {
    id: 'adv-invoice-lifecycle',
    name: 'Smart Invoice Pipeline',
    category: 'Advanced',
    description: 'When invoice is created: push to Billingo, check amount -- high-value invoices get Telegram alert to manager + email to client. Standard invoices get emailed directly. All get logged to chat.',
    icon: 'account_balance',
    iconBg: 'bg-purple-500/15',
    iconColor: 'text-purple-400',
    nodes: [
      { type: 'trigger.crm.invoice_overdue', x: 80, y: 250, config: {} },
      { type: 'action.invoice.push_billingo', x: 340, y: 250, config: {} },
      { type: 'logic.condition', x: 600, y: 250, config: { field: 'amount', operator: 'greater_than', value: '500' } },
      { type: 'action.telegram.send', x: 860, y: 100, config: { message: 'HIGH-VALUE invoice #{invoice_id} for {client_name}: {amount} {currency}\nPushed to Billingo. Needs manager attention.' } },
      { type: 'action.invoice.send_client', x: 860, y: 250, config: {} },
      { type: 'action.email.send', x: 860, y: 400, config: { subject: 'Invoice reminder: #{invoice_id}', body: 'Dear {client_name},\n\nPlease find your invoice #{invoice_id} for {amount} {currency}.\n\nThank you.' } },
      { type: 'action.chat.send', x: 1120, y: 250, config: { message: 'Invoice #{invoice_id} processed for {client_name} ({amount} {currency}) -- pushed to Billingo and sent.' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, to: 2 },
      { from: 2, fromPort: 'true', to: 3 },
      { from: 2, fromPort: 'true', to: 4 },
      { from: 2, fromPort: 'false', to: 5 },
      { from: 4, to: 6 },
      { from: 5, to: 6 },
    ],
  },
  {
    id: 'adv-daily-business-digest',
    name: 'Daily Business Intelligence',
    category: 'Advanced',
    description: 'Every morning at 8:00: query today\'s revenue, count overdue invoices, check server health, and send a comprehensive digest via Telegram, email, and in-app notification.',
    icon: 'analytics',
    iconBg: 'bg-gradient-to-br from-indigo-500/20 to-violet-500/20',
    iconColor: 'text-indigo-400',
    nodes: [
      { type: 'trigger.schedule.cron', x: 80, y: 250, config: { schedule_type: 'daily', daily_time: '08:00' } },
      { type: 'action.sql.query', x: 340, y: 150, config: { query_type: 'custom', custom_sql: 'SELECT COUNT(*) as total_invoices, SUM(amount) as total_revenue FROM crm_invoices WHERE DATE(created_at) = CURDATE()' } },
      { type: 'action.sql.query', x: 340, y: 350, config: { query_type: 'custom', custom_sql: 'SELECT COUNT(*) as overdue_count, SUM(amount) as overdue_total FROM crm_invoices WHERE status = \'overdue\'' } },
      { type: 'action.telegram.send', x: 640, y: 150, config: { message: 'DAILY BUSINESS DIGEST\n\nRevenue today: {total_revenue}\nInvoices: {total_invoices}\nOverdue: {overdue_count} ({overdue_total})\n\nGenerated at {triggered_at}' } },
      { type: 'action.email.send', x: 640, y: 350, config: { subject: 'Daily Business Digest - {triggered_at}', body: 'Good morning,\n\nRevenue today: {total_revenue}\nNew invoices: {total_invoices}\nOverdue invoices: {overdue_count} totaling {overdue_total}\n\nHave a productive day!' } },
      { type: 'action.notification.send', x: 920, y: 250, config: { title: 'Daily Digest Ready', message: 'Revenue: {total_revenue} | Overdue: {overdue_count}', recipient_type: 'trigger_user' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 0, to: 2 },
      { from: 1, to: 3 },
      { from: 2, to: 4 },
      { from: 3, to: 5 },
    ],
  },
  {
    id: 'adv-abandoned-deal-recovery',
    name: 'Stale Deal Recovery System',
    category: 'Advanced',
    description: 'Daily at 9AM: find deals stuck in pipeline for 14+ days, start a recovery email sequence, create follow-up tasks, alert the sales team via chat, and export stale deals to CSV for review.',
    icon: 'restore',
    iconBg: 'bg-amber-500/15',
    iconColor: 'text-amber-400',
    nodes: [
      { type: 'trigger.schedule.cron', x: 80, y: 250, config: { schedule_type: 'daily', daily_time: '09:00' } },
      { type: 'action.sql.query', x: 340, y: 250, config: { query_type: 'custom', custom_sql: 'SELECT d.id, d.title, d.expected_value, d.stage, d.assigned_to, DATEDIFF(NOW(), d.updated_at) as days_stale FROM crm_deals d WHERE d.status = \'open\' AND d.updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY) ORDER BY d.expected_value DESC' } },
      { type: 'logic.condition', x: 600, y: 250, config: { field: 'row_count', operator: 'greater_than', value: '0' } },
      { type: 'action.export.csv', x: 860, y: 120, config: { data_source: 'input', filename: 'stale-deals-{triggered_at}', notify: 'email' } },
      { type: 'action.chat.send', x: 860, y: 250, config: { message: 'STALE DEAL ALERT: {row_count} deals stuck 14+ days.\nTop deal: {title} ({expected_value})\nCSV report exported to Drive.' } },
      { type: 'action.task.create', x: 860, y: 400, config: { title: 'Review {row_count} stale deals', description: 'These deals have been inactive for 14+ days. Review the exported CSV and take action.' } },
      { type: 'action.telegram.send', x: 1120, y: 250, config: { message: 'SALES ALERT: {row_count} deals stale 14+ days. CSV exported. Check your tasks.' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, to: 2 },
      { from: 2, fromPort: 'true', to: 3 },
      { from: 2, fromPort: 'true', to: 4 },
      { from: 2, fromPort: 'true', to: 5 },
      { from: 4, to: 6 },
    ],
  },
  {
    id: 'adv-team-broadcast-multichannel',
    name: 'Multi-Channel Team Broadcast',
    category: 'Advanced',
    description: 'Send an announcement to your entire team across every channel: email to mailing list, in-app notification to team group, chat message, and Telegram. One trigger, total reach.',
    icon: 'campaign',
    iconBg: 'bg-gradient-to-br from-rose-500/20 to-orange-500/20',
    iconColor: 'text-rose-400',
    nodes: [
      { type: 'trigger.manual', x: 80, y: 250, config: {} },
      { type: 'action.list.get_team', x: 340, y: 250, config: {} },
      { type: 'action.email.send', x: 620, y: 80, config: { recipient_source: 'upstream', subject: 'Team Announcement', body: 'Hello team,\n\nThis is an important announcement.\n\nBest regards' } },
      { type: 'action.notification.send', x: 620, y: 230, config: { title: 'Team Announcement', message: 'Check your email for the full details.', recipient_type: 'trigger_user' } },
      { type: 'action.chat.send', x: 620, y: 380, config: { message: 'TEAM ANNOUNCEMENT: Check your email for important updates.' } },
      { type: 'action.telegram.send', x: 900, y: 230, config: { message: 'TEAM BROADCAST: New announcement sent to all {members_count} team members.' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, to: 2 },
      { from: 1, to: 3 },
      { from: 1, to: 4 },
      { from: 2, to: 5 },
    ],
  },
  {
    id: 'adv-server-incident-response',
    name: 'Full Incident Response',
    category: 'Advanced',
    description: 'When server health degrades: check CPU/RAM condition, if critical -- send Telegram + email to ops team + create incident task + notification. If warning -- just log to chat. Complete incident management.',
    icon: 'emergency',
    iconBg: 'bg-red-500/15',
    iconColor: 'text-red-400',
    nodes: [
      { type: 'trigger.server.health', x: 80, y: 250, config: { metric: 'cpu_load' } },
      { type: 'logic.condition', x: 340, y: 250, config: { field: 'value', operator: 'greater_than', value: '95' } },
      { type: 'action.telegram.send', x: 600, y: 80, config: { message: 'CRITICAL INCIDENT: {metric} at {value}%\nServer requires immediate attention!' } },
      { type: 'action.email.send', x: 600, y: 230, config: { subject: 'INCIDENT: {metric} at {value}%', body: 'Critical server incident detected.\n\nMetric: {metric}\nValue: {value}%\nTime: {triggered_at}\n\nPlease investigate immediately.' } },
      { type: 'action.task.create', x: 600, y: 380, config: { title: 'INCIDENT: {metric} at {value}%', description: 'Investigate and resolve. Detected at {triggered_at}.' } },
      { type: 'action.notification.send', x: 880, y: 150, config: { title: 'Server incident', message: '{metric}: {value}% -- task created', recipient_type: 'trigger_user' } },
      { type: 'action.chat.send', x: 600, y: 530, config: { message: 'Server warning: {metric} is at {value}%. Monitoring...' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, fromPort: 'true', to: 2 },
      { from: 1, fromPort: 'true', to: 3 },
      { from: 1, fromPort: 'true', to: 4 },
      { from: 3, to: 5 },
      { from: 1, fromPort: 'false', to: 6 },
    ],
  },
  {
    id: 'adv-weekly-full-business-report',
    name: 'Weekly Business Report',
    category: 'Advanced',
    description: 'Every Monday 8AM: query revenue, pipeline value, and overdue invoices via SQL, export a comprehensive CSV report to Drive, email it to the mailing list, and post summary to chat + Telegram.',
    icon: 'assessment',
    iconBg: 'bg-gradient-to-br from-blue-500/20 to-purple-500/20',
    iconColor: 'text-blue-400',
    nodes: [
      { type: 'trigger.schedule.cron', x: 80, y: 280, config: { schedule_type: 'cron', cron_expression: '0 8 * * 1' } },
      { type: 'action.sql.query', x: 340, y: 160, config: { query_type: 'custom', custom_sql: 'SELECT \'This Week\' as period, COUNT(*) as deals_closed, SUM(expected_value) as revenue, (SELECT COUNT(*) FROM crm_invoices WHERE status=\'overdue\') as overdue_invoices FROM crm_deals WHERE status=\'won\' AND closed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)' } },
      { type: 'action.sql.query', x: 340, y: 400, config: { query_type: 'custom', custom_sql: 'SELECT d.title, d.expected_value, d.stage, c.company_name as client FROM crm_deals d LEFT JOIN crm_clients c ON d.client_id=c.id WHERE d.status=\'won\' AND d.closed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY d.expected_value DESC' } },
      { type: 'action.export.csv', x: 620, y: 400, config: { data_source: 'input', filename: 'weekly-report', notify: 'email' } },
      { type: 'action.telegram.send', x: 620, y: 160, config: { message: 'WEEKLY BUSINESS REPORT\n\nDeals closed: {deals_closed}\nRevenue: {revenue}\nOverdue invoices: {overdue_invoices}\n\nFull CSV report exported to Drive.' } },
      { type: 'action.chat.send', x: 900, y: 280, config: { message: 'Weekly report is ready!\nDeals: {deals_closed} | Revenue: {revenue}\nCSV exported to Drive: {filename}' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 0, to: 2 },
      { from: 1, to: 4 },
      { from: 2, to: 3 },
      { from: 3, to: 5 },
    ],
  },
  {
    id: 'adv-deal-won-full-lifecycle',
    name: 'Deal Won - Full Lifecycle',
    category: 'Advanced',
    description: 'Complete deal closure: push invoice to Billingo, download PDF, send invoice to client, start onboarding sequence, export deal data to CSV, notify team across all channels. The ultimate sales automation.',
    icon: 'military_tech',
    iconBg: 'bg-gradient-to-br from-yellow-500/20 to-amber-500/20',
    iconColor: 'text-yellow-400',
    nodes: [
      { type: 'trigger.crm.deal_won', x: 60, y: 280, config: {} },
      { type: 'action.client.get_data', x: 300, y: 280, config: { client_source: 'trigger' } },
      { type: 'action.invoice.push_billingo', x: 540, y: 120, config: {} },
      { type: 'action.invoice.send_client', x: 780, y: 120, config: {} },
      { type: 'action.sequence.start', x: 540, y: 280, config: {} },
      { type: 'action.export.csv', x: 540, y: 440, config: { data_source: 'crm_deals', filename: 'deal-won-{deal_title}' } },
      { type: 'action.chat.send', x: 780, y: 280, config: { message: 'DEAL WON: {deal_title}\nClient: {client_name}\nValue: {expected_value}\nInvoice sent. Sequence started.' } },
      { type: 'action.telegram.send', x: 780, y: 440, config: { message: 'CLOSED: {deal_title} ({expected_value}) for {client_name}. Invoice + sequence active.' } },
      { type: 'action.task.create', x: 1020, y: 280, config: { title: 'Kickoff: {client_name}', description: 'Deal won: {deal_title}. Schedule kickoff meeting. Invoice sent, onboarding sequence started.' } },
    ],
    edges: [
      { from: 0, to: 1 },
      { from: 1, to: 2 },
      { from: 2, to: 3 },
      { from: 1, to: 4 },
      { from: 1, to: 5 },
      { from: 4, to: 6 },
      { from: 5, to: 7 },
      { from: 6, to: 8 },
    ],
  },
]

const allCategories = computed(() => {
  const cats = new Set()
  templates.forEach(t => cats.add(t.category))
  return Array.from(cats)
})

const filteredByCategory = computed(() => {
  const q = search.value.toLowerCase().trim()
  const groups = {}

  for (const tpl of templates) {
    if (activeCategory.value && tpl.category !== activeCategory.value) continue

    if (q) {
      const match = tpl.name.toLowerCase().includes(q)
        || tpl.description.toLowerCase().includes(q)
        || tpl.category.toLowerCase().includes(q)
        || tpl.nodes.some(n => n.type.toLowerCase().includes(q))
      if (!match) continue
    }

    if (!groups[tpl.category]) groups[tpl.category] = []
    groups[tpl.category].push(tpl)
  }
  return groups
})

function nodeChipClass(type) {
  if (type.startsWith('trigger.')) return 'bg-amber-500/15 text-amber-400'
  if (type.startsWith('logic.')) return 'bg-emerald-500/15 text-emerald-400'
  return 'bg-blue-500/15 text-blue-400'
}

function nodeChipIcon(type) {
  const def = getNodeDef(type)
  return def?.icon || 'settings'
}

function nodeChipLabel(type) {
  const def = getNodeDef(type)
  return def?.label || type.split('.').pop()
}
</script>
