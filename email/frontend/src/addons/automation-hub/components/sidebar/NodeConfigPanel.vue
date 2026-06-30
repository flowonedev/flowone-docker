<template>
  <div class="w-80 bg-white dark:bg-surface-900 border-l border-surface-200 dark:border-surface-700 flex flex-col h-full">
    <template v-if="node">
      <!-- Header -->
      <div class="flex items-center gap-3 px-4 py-3 border-b border-surface-200 dark:border-surface-700">
        <div
          class="w-8 h-8 rounded-lg flex items-center justify-center"
          :class="colors.bg"
        >
          <span class="material-symbols-rounded text-lg" :class="colors.text">{{ nodeDef?.icon || 'settings' }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <input
            :value="node.label || nodeDef?.label || ''"
            @input="store.updateNodeLabel(node.uid, $event.target.value)"
            class="w-full bg-transparent text-sm font-semibold text-surface-800 dark:text-surface-100 focus:outline-none"
            placeholder="Node name"
          />
          <div class="text-[10px] text-surface-500 dark:text-surface-400">{{ nodeDef?.subtitle }}</div>
        </div>
        <button @click="updateConfig('disabled', !config.disabled)" :title="config.disabled ? 'Enable node' : 'Disable node'"
          :class="['relative w-9 h-5 rounded-full transition-colors shrink-0', config.disabled ? 'bg-surface-600' : 'bg-emerald-500']">
          <span :class="['absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform', config.disabled ? 'left-0.5' : 'left-[18px]']"></span>
        </button>
        <button
          @click="store.deselectAll()"
          class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>

      <!-- Config form -->
      <div class="node-config-form flex-1 overflow-y-auto p-4 space-y-4" @focusin="trackTextareaFocus">

        <!-- Connection status banner for external service nodes -->
        <div v-if="nodeConnectionProvider" :class="[
          'flex items-center gap-2.5 p-3 rounded-xl text-xs',
          isNodeProviderConnected
            ? 'bg-emerald-50 dark:bg-emerald-500/5 border border-emerald-200 dark:border-emerald-500/20'
            : 'bg-amber-50 dark:bg-amber-500/5 border border-amber-200 dark:border-amber-500/20'
        ]">
          <span :class="['material-symbols-rounded text-lg', isNodeProviderConnected ? 'text-emerald-500' : 'text-amber-500']">
            {{ isNodeProviderConnected ? 'check_circle' : 'warning' }}
          </span>
          <span :class="['flex-1', isNodeProviderConnected ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300']">
            {{ isNodeProviderConnected ? `${nodeConnectionProvider.label} connected -- all set` : `${nodeConnectionProvider.label} not configured` }}
          </span>
          <button
            v-if="!isNodeProviderConnected"
            @click="openIntegrationSettings"
            class="px-3 py-1 rounded-full bg-amber-500 text-white text-[11px] font-medium hover:bg-amber-600 transition-colors whitespace-nowrap"
          >
            Open Settings
          </button>
        </div>

        <!-- ═══════════ TRIGGERS: Manual ═══════════ -->
        <template v-if="node.type === 'trigger.manual'">
          <div class="flex items-start gap-2 p-3 bg-amber-50 dark:bg-amber-500/5 border border-amber-200 dark:border-amber-500/20 rounded-lg">
            <span class="material-symbols-rounded text-amber-500 dark:text-amber-400 text-lg mt-0.5">info</span>
            <div class="text-xs text-surface-600 dark:text-surface-300">
              This trigger starts the workflow when you click the <strong class="text-amber-600 dark:text-amber-400">Test</strong> button in the toolbar. No configuration needed. Use it for manual testing or on-demand runs.
            </div>
          </div>
        </template>

        <!-- ═══════════ TRIGGERS: Schedule ═══════════ -->
        <template v-if="node.type === 'trigger.schedule.cron'">
          <ConfigField label="Schedule Type">
            <ConfigSelect :value="config.schedule_type || 'interval'" @update="updateConfig('schedule_type', $event)" :options="[
              { value: 'interval', label: 'Interval' },
              { value: 'cron', label: 'Cron Expression' },
              { value: 'daily', label: 'Daily at Time' },
            ]" />
          </ConfigField>

          <template v-if="(config.schedule_type || 'interval') === 'interval'">
            <ConfigField label="Every">
              <div class="flex gap-2">
                <input type="number" :value="config.interval_value || 5" @input="updateConfig('interval_value', parseInt($event.target.value))" class="w-20 cfg-input" min="1" />
                <ConfigSelect :value="config.interval_unit || 'minutes'" @update="updateConfig('interval_unit', $event)" :options="[
                  { value: 'minutes', label: 'Minutes' },
                  { value: 'hours', label: 'Hours' },
                  { value: 'days', label: 'Days' },
                ]" class="flex-1" />
              </div>
            </ConfigField>
          </template>

          <template v-if="config.schedule_type === 'cron'">
            <ConfigField label="Cron Expression">
              <input :value="config.cron_expression || '*/5 * * * *'" @input="updateConfig('cron_expression', $event.target.value)" class="w-full cfg-input font-mono" placeholder="*/5 * * * *" />
            </ConfigField>
          </template>

          <template v-if="config.schedule_type === 'daily'">
            <ConfigField label="Time">
              <input type="time" :value="config.daily_time || '08:00'" @input="updateConfig('daily_time', $event.target.value)" class="w-full cfg-input" />
            </ConfigField>
          </template>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Board events ═══════════ -->
        <template v-else-if="node.type === 'trigger.board.card_moved'">
          <ConfigField label="Board">
            <ConfigSelect :value="config.board_id || ''" @update="onBoardSelect($event)" :options="boardOptions" />
          </ConfigField>
          <ConfigField label="From List">
            <ConfigSelect :value="config.from_list || ''" @update="updateConfig('from_list', $event)" :options="listOptionsForBoard(config.board_id)" />
          </ConfigField>
          <ConfigField label="To List">
            <ConfigSelect :value="config.to_list || ''" @update="updateConfig('to_list', $event)" :options="listOptionsForBoard(config.board_id)" />
          </ConfigField>
          <ConfigHint>Triggers when a card is moved between lists. Leave fields empty to match any.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <template v-else-if="node.type === 'trigger.board.card_created'">
          <ConfigField label="Board">
            <ConfigSelect :value="config.board_id || ''" @update="onBoardSelect($event)" :options="boardOptions" />
          </ConfigField>
          <ConfigField label="List">
            <ConfigSelect :value="config.list_name || ''" @update="updateConfig('list_name', $event)" :options="listOptionsForBoard(config.board_id)" />
          </ConfigField>
          <ConfigHint>Triggers when a new card is created on a board.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <template v-else-if="node.type === 'trigger.board.card_completed'">
          <ConfigField label="Board">
            <ConfigSelect :value="config.board_id || ''" @update="onBoardSelect($event)" :options="boardOptions" />
          </ConfigField>
          <ConfigHint>Triggers when a card is marked as complete.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <template v-else-if="node.type === 'trigger.board.card_overdue'">
          <ConfigField label="Board">
            <ConfigSelect :value="config.board_id || ''" @update="onBoardSelect($event)" :options="boardOptions" />
          </ConfigField>
          <ConfigField label="Grace Period">
            <div class="flex gap-2">
              <input type="number" :value="config.grace_value || 0" @input="updateConfig('grace_value', parseInt($event.target.value))" class="w-20 cfg-input" min="0" />
              <ConfigSelect :value="config.grace_unit || 'minutes'" @update="updateConfig('grace_unit', $event)" :options="[
                { value: 'minutes', label: 'Minutes' },
                { value: 'hours', label: 'Hours' },
              ]" class="flex-1" />
            </div>
          </ConfigField>
          <ConfigHint>Triggers when a card passes its due date.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: CRM events ═══════════ -->
        <template v-else-if="node.type === 'trigger.crm.deal_stage_changed'">
          <ConfigField label="From Stage">
            <ConfigSelect :value="config.from_stage || ''" @update="updateConfig('from_stage', $event)" :options="stageOptions" />
          </ConfigField>
          <ConfigField label="To Stage">
            <ConfigSelect :value="config.to_stage || ''" @update="updateConfig('to_stage', $event)" :options="stageOptions" />
          </ConfigField>
          <ConfigHint>Triggers when a CRM deal moves to a different pipeline stage.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <template v-else-if="node.type === 'trigger.crm.deal_won'">
          <ConfigField label="Min Deal Value">
            <input type="number" :value="config.min_value || ''" @input="updateConfig('min_value', $event.target.value ? parseFloat($event.target.value) : null)" class="w-full cfg-input" placeholder="No minimum" />
          </ConfigField>
          <ConfigHint>Triggers when a deal is marked as won.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <template v-else-if="node.type === 'trigger.crm.deal_lost'">
          <ConfigHint>Triggers when a deal is marked as lost.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <template v-else-if="node.type === 'trigger.crm.invoice_overdue'">
          <ConfigField label="Overdue By">
            <div class="flex gap-2">
              <input type="number" :value="config.overdue_value || 1" @input="updateConfig('overdue_value', parseInt($event.target.value))" class="w-20 cfg-input" min="1" />
              <ConfigSelect :value="config.overdue_unit || 'days'" @update="updateConfig('overdue_unit', $event)" :options="[
                { value: 'hours', label: 'Hours' },
                { value: 'days', label: 'Days' },
              ]" class="flex-1" />
            </div>
          </ConfigField>
          <ConfigHint>Triggers when a CRM invoice is past its due date.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Server Health ═══════════ -->
        <template v-else-if="node.type === 'trigger.server.health'">
          <ConfigField label="Metric">
            <ConfigSelect :value="config.metric || 'cpu_load'" @update="updateConfig('metric', $event)" :options="[
              { value: 'cpu_load', label: 'CPU Load' },
              { value: 'memory_usage', label: 'Memory Usage (%)' },
              { value: 'disk_usage', label: 'Disk Usage (%)' },
              { value: 'service_status', label: 'Service Status' },
            ]" />
          </ConfigField>

          <template v-if="config.metric !== 'service_status'">
            <ConfigField label="Condition">
              <div class="flex gap-2">
                <ConfigSelect :value="config.condition || 'above'" @update="updateConfig('condition', $event)" :options="[
                  { value: 'above', label: 'Above' },
                  { value: 'below', label: 'Below' },
                ]" class="w-28" />
                <input type="number" :value="config.threshold || 90" @input="updateConfig('threshold', parseFloat($event.target.value))" class="flex-1 cfg-input" placeholder="%" />
              </div>
            </ConfigField>
          </template>

          <template v-if="config.metric === 'service_status'">
            <ConfigField label="Service">
              <ConfigSelect :value="config.service || 'openlitespeed'" @update="updateConfig('service', $event)" :options="[
                { value: 'openlitespeed', label: 'OpenLiteSpeed' },
                { value: 'mariadb', label: 'MariaDB' },
                { value: 'postfix', label: 'Postfix' },
                { value: 'dovecot', label: 'Dovecot' },
                { value: 'fail2ban', label: 'Fail2ban' },
                { value: 'firewalld', label: 'Firewalld' },
                { value: 'redis', label: 'Redis' },
                { value: 'meilisearch', label: 'Meilisearch' },
              ]" />
            </ConfigField>
            <ConfigField label="When Status Is">
              <ConfigSelect :value="config.status_condition || 'stopped'" @update="updateConfig('status_condition', $event)" :options="[
                { value: 'stopped', label: 'Stopped' },
                { value: 'running', label: 'Running' },
              ]" />
            </ConfigField>
          </template>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Webhook ═══════════ -->
        <template v-else-if="node.type === 'trigger.webhook.incoming'">
          <ConfigField label="Webhook URL">
            <div class="flex gap-1">
              <input :value="webhookUrl" readonly class="w-full cfg-input font-mono text-[11px] text-surface-400 cursor-text" @click="$event.target.select()" />
              <button @click="copyWebhookUrl" class="shrink-0 p-2 rounded-lg bg-surface-700 hover:bg-surface-600 transition-colors" title="Copy">
                <span class="material-symbols-rounded text-sm text-surface-300">content_copy</span>
              </button>
            </div>
          </ConfigField>
          <ConfigField label="Expected Method">
            <ConfigSelect :value="config.expected_method || 'POST'" @update="updateConfig('expected_method', $event)" :options="[
              { value: 'POST', label: 'POST' },
              { value: 'GET', label: 'GET' },
              { value: 'PUT', label: 'PUT' },
              { value: 'ANY', label: 'Any Method' },
            ]" />
          </ConfigField>
          <ConfigField label="Secret Token">
            <input :value="config.secret || ''" @input="updateConfig('secret', $event.target.value)" class="w-full cfg-input font-mono" placeholder="Optional validation secret" />
          </ConfigField>
          <ConfigHint>Send an HTTP request to the URL above to trigger this workflow.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Telegram ═══════════ -->
        <template v-else-if="node.type === 'trigger.telegram.message'">
          <ConfigField label="Bot Token">
            <input :value="config.bot_token || ''" @input="updateConfig('bot_token', $event.target.value)" class="w-full cfg-input font-mono" placeholder="123456:ABC-DEF..." />
          </ConfigField>
          <ConfigField label="Chat ID">
            <input :value="config.chat_id || ''" @input="updateConfig('chat_id', $event.target.value)" class="w-full cfg-input" placeholder="Chat or group ID (optional)" />
          </ConfigField>
          <ConfigField label="Trigger On">
            <ConfigSelect :value="config.trigger_on || 'any'" @update="updateConfig('trigger_on', $event)" :options="[
              { value: 'any', label: 'Any Message' },
              { value: 'command', label: 'Specific Command' },
            ]" />
          </ConfigField>
          <template v-if="config.trigger_on === 'command'">
            <ConfigField label="Command">
              <input :value="config.command || '/status'" @input="updateConfig('command', $event.target.value)" class="w-full cfg-input" placeholder="/status" />
            </ConfigField>
          </template>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Send Email ═══════════ -->
        <template v-else-if="node.type === 'action.email.send'">
          <ConfigField label="Recipient Source">
            <ConfigSelect :value="config.recipient_source || 'manual'" @update="updateConfig('recipient_source', $event)" :options="[
              { value: 'manual', label: 'Manual / Variable' },
              { value: 'mailing_list', label: 'Mailing List' },
              { value: 'team_group', label: 'Team Group' },
              { value: 'upstream', label: 'From upstream {contact_emails} or {member_emails}' },
            ]" />
          </ConfigField>
          <template v-if="config.recipient_source === 'mailing_list'">
            <ConfigField label="Mailing List">
              <ConfigSelect :value="config.list_id || ''" @update="updateConfig('list_id', $event)" :options="mailingListOptions" />
            </ConfigField>
          </template>
          <template v-else-if="config.recipient_source === 'team_group'">
            <ConfigField label="Team Group">
              <ConfigSelect :value="config.group_id || ''" @update="updateConfig('group_id', $event)" :options="groupOptions" />
            </ConfigField>
          </template>
          <template v-else-if="config.recipient_source !== 'upstream'">
            <ConfigField label="To">
              <div class="flex gap-1">
                <input :value="config.to || ''" @input="updateConfig('to', $event.target.value)" class="flex-1 cfg-input" placeholder="recipient@example.com or {user_email}" />
                <div class="relative" v-if="colleaguesList.length">
                  <button @click="showContactPicker = !showContactPicker" class="shrink-0 p-2 rounded-lg bg-surface-700 hover:bg-surface-600 transition-colors" title="Pick from contacts">
                    <span class="material-symbols-rounded text-sm text-surface-300">person_search</span>
                  </button>
                  <div v-if="showContactPicker" class="absolute right-0 top-full mt-1 w-56 bg-surface-800 border border-surface-600 rounded-lg shadow-xl z-50 max-h-48 overflow-y-auto" @mousedown.stop @wheel.stop>
                    <button v-for="c in colleaguesList" :key="c.email" @click="updateConfig('to', c.email); showContactPicker = false" class="w-full px-3 py-2 text-left text-sm text-surface-200 hover:bg-surface-700 transition-colors flex items-center gap-2">
                      <span class="material-symbols-rounded text-xs text-surface-400">person</span>
                      <span class="truncate">{{ c.display_name || c.email }}</span>
                    </button>
                  </div>
                </div>
              </div>
            </ConfigField>
          </template>
          <ConfigField label="Subject">
            <input :value="config.subject || ''" @input="updateConfig('subject', $event.target.value)" class="w-full cfg-input" placeholder="Email subject" />
          </ConfigField>
          <ConfigField label="Body">
            <textarea :value="config.body || ''" @input="updateConfig('body', $event.target.value)" class="w-full cfg-input resize-y" rows="5" placeholder="Email body (supports {variables})" />
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Send Chat Message ═══════════ -->
        <template v-else-if="node.type === 'action.chat.send'">
          <ConfigField label="Send To">
            <ConfigSelect :value="chatTarget" @update="onChatTargetSelect($event)" :options="chatTargetOptions" />
          </ConfigField>
          <ConfigField label="Message">
            <textarea :value="config.message || ''" @input="updateConfig('message', $event.target.value)" class="w-full cfg-input resize-y" rows="4" placeholder="Message text (supports {variables})" />
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Send Notification ═══════════ -->
        <template v-else-if="node.type === 'action.notification.send'">
          <ConfigField label="Recipient">
            <ConfigSelect :value="config.recipient_type || 'trigger_user'" @update="updateConfig('recipient_type', $event)" :options="recipientOptions" />
          </ConfigField>
          <template v-if="config.recipient_type === 'custom'">
            <ConfigField label="Email">
              <input :value="config.to_email || ''" @input="updateConfig('to_email', $event.target.value)" class="w-full cfg-input" placeholder="user@example.com or {user_email}" />
            </ConfigField>
          </template>
          <ConfigField label="Title">
            <input :value="config.title || ''" @input="updateConfig('title', $event.target.value)" class="w-full cfg-input" placeholder="Notification title" />
          </ConfigField>
          <ConfigField label="Message">
            <textarea :value="config.message || ''" @input="updateConfig('message', $event.target.value)" class="w-full cfg-input resize-y" rows="3" placeholder="Notification message (supports {variables})" />
          </ConfigField>
          <ConfigField label="Type">
            <ConfigSelect :value="config.notification_type || 'info'" @update="updateConfig('notification_type', $event)" :options="[
              { value: 'info', label: 'Info' },
              { value: 'success', label: 'Success' },
              { value: 'warning', label: 'Warning' },
              { value: 'error', label: 'Error' },
            ]" />
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Telegram Send ═══════════ -->
        <template v-else-if="node.type === 'action.telegram.send'">
          <ConfigField label="Bot Token">
            <input :value="config.bot_token || ''" @input="updateConfig('bot_token', $event.target.value)" class="w-full cfg-input font-mono" placeholder="123456:ABC-DEF..." />
          </ConfigField>
          <ConfigField label="Chat ID">
            <input :value="config.chat_id || ''" @input="updateConfig('chat_id', $event.target.value)" class="w-full cfg-input" placeholder="Chat or group ID" />
          </ConfigField>
          <ConfigField label="Message">
            <textarea :value="config.message || ''" @input="updateConfig('message', $event.target.value)" class="w-full cfg-input resize-y" rows="4" placeholder="Message text (supports {variables})" />
          </ConfigField>
          <ConfigField label="Parse Mode">
            <ConfigSelect :value="config.parse_mode || 'Markdown'" @update="updateConfig('parse_mode', $event)" :options="[
              { value: 'Markdown', label: 'Markdown' },
              { value: 'HTML', label: 'HTML' },
              { value: '', label: 'Plain Text' },
            ]" />
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: HTTP Request ═══════════ -->
        <template v-else-if="node.type === 'action.http.request'">
          <ConfigField label="Method">
            <ConfigSelect :value="config.method || 'GET'" @update="updateConfig('method', $event)" :options="[
              { value: 'GET', label: 'GET' },
              { value: 'POST', label: 'POST' },
              { value: 'PUT', label: 'PUT' },
              { value: 'DELETE', label: 'DELETE' },
              { value: 'PATCH', label: 'PATCH' },
            ]" />
          </ConfigField>
          <ConfigField label="URL">
            <input :value="config.url || ''" @input="updateConfig('url', $event.target.value)" class="w-full cfg-input" placeholder="https://api.example.com/..." />
          </ConfigField>
          <ConfigField label="Headers">
            <textarea :value="config.headers || ''" @input="updateConfig('headers', $event.target.value)" class="w-full cfg-input font-mono resize-y" rows="3" :placeholder="'Authorization: Bearer ...\nContent-Type: application/json'" />
          </ConfigField>
          <ConfigField label="Body">
            <textarea :value="config.request_body || ''" @input="updateConfig('request_body', $event.target.value)" class="w-full cfg-input font-mono resize-y" rows="4" placeholder='{"key": "value"}' />
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: CRM Move Deal ═══════════ -->
        <template v-else-if="node.type === 'action.crm.move_deal'">
          <ConfigField label="Deal ID Source">
            <ConfigSelect :value="config.deal_source || 'trigger'" @update="updateConfig('deal_source', $event)" :options="[
              { value: 'trigger', label: 'From Trigger Data' },
              { value: 'manual', label: 'Specific Deal ID' },
            ]" />
          </ConfigField>
          <template v-if="config.deal_source === 'manual'">
            <ConfigField label="Deal ID">
              <input type="number" :value="config.deal_id || ''" @input="updateConfig('deal_id', $event.target.value)" class="w-full cfg-input" placeholder="Deal ID" />
            </ConfigField>
          </template>
          <ConfigField label="Target Stage">
            <ConfigSelect :value="config.target_stage || ''" @update="updateConfig('target_stage', $event)" :options="pipelineStageOptions" />
          </ConfigField>
          <ConfigHint>Moves a CRM deal to the specified pipeline stage.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Board Move Card ═══════════ -->
        <template v-else-if="node.type === 'action.board.move_card'">
          <ConfigField label="Card Source">
            <ConfigSelect :value="config.card_source || 'trigger'" @update="updateConfig('card_source', $event)" :options="[
              { value: 'trigger', label: 'From Trigger Data' },
              { value: 'manual', label: 'Specific Card ID' },
            ]" />
          </ConfigField>
          <template v-if="config.card_source === 'manual'">
            <ConfigField label="Card ID">
              <input type="number" :value="config.card_id || ''" @input="updateConfig('card_id', $event.target.value)" class="w-full cfg-input" placeholder="Card ID" />
            </ConfigField>
          </template>
          <ConfigField label="Target Board">
            <ConfigSelect :value="config.target_board_id || ''" @update="onMoveCardBoardSelect($event)" :options="boardOptions" />
          </ConfigField>
          <ConfigField label="Target List">
            <ConfigSelect :value="config.target_list_id || ''" @update="updateConfig('target_list_id', $event)" :options="listOptionsForBoard(config.target_board_id)" />
          </ConfigField>
          <ConfigHint>Moves a card to a specific list on a board.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Create Task ═══════════ -->
        <template v-else-if="node.type === 'action.task.create'">
          <ConfigField label="Board">
            <ConfigSelect :value="config.board_id || ''" @update="onBoardSelect($event)" :options="boardOptions" />
          </ConfigField>
          <ConfigField label="List">
            <ConfigSelect :value="config.list_id || ''" @update="updateConfig('list_id', $event)" :options="listOptionsForBoard(config.board_id)" />
          </ConfigField>
          <ConfigField label="Title">
            <input :value="config.title || ''" @input="updateConfig('title', $event.target.value)" class="w-full cfg-input" placeholder="Card title (supports {variables})" />
          </ConfigField>
          <ConfigField label="Description">
            <textarea :value="config.description || ''" @input="updateConfig('description', $event.target.value)" class="w-full cfg-input resize-y" rows="3" placeholder="Card description (supports {variables})" />
          </ConfigField>
          <ConfigField label="Assignee">
            <ConfigSelect :value="config.assignee || ''" @update="updateConfig('assignee', $event)" :options="assigneeOptions" />
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ LOGIC: Condition ═══════════ -->
        <template v-else-if="node.type === 'logic.condition'">
          <ConfigField label="Field">
            <ConfigSelect
              v-if="upstreamFieldOptions.length > 2"
              :value="config.field || ''"
              @update="onFieldSelect($event, 'condition')"
              :options="upstreamFieldOptions"
            />
            <input v-else :value="config.field || ''" @input="updateConfig('field', $event.target.value)" class="w-full cfg-input" placeholder="Connect upstream nodes first, or type field name" />
            <input
              v-if="config._custom_field === 'condition'"
              :value="config.field || ''"
              @input="updateConfig('field', $event.target.value)"
              class="w-full cfg-input mt-1.5"
              placeholder="Type field name (e.g. response.data.value)"
            />
          </ConfigField>
          <ConfigField label="Operator">
            <ConfigSelect :value="config.operator || 'equals'" @update="updateConfig('operator', $event)" :options="[
              { value: 'equals', label: 'Equals' },
              { value: 'not_equals', label: 'Not Equals' },
              { value: 'greater_than', label: 'Greater Than' },
              { value: 'less_than', label: 'Less Than' },
              { value: 'contains', label: 'Contains' },
              { value: 'not_empty', label: 'Not Empty' },
              { value: 'is_empty', label: 'Is Empty' },
            ]" />
          </ConfigField>
          <template v-if="!['not_empty', 'is_empty'].includes(config.operator)">
            <ConfigField label="Value">
              <input :value="config.value || ''" @input="updateConfig('value', $event.target.value)" class="w-full cfg-input" placeholder="Value or {variable}" />
            </ConfigField>
          </template>
          <ConfigHint>Routes data through "True" or "False" output based on the condition. The Value field supports {variables} from upstream.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ LOGIC: Delay ═══════════ -->
        <template v-else-if="node.type === 'logic.delay'">
          <ConfigField label="Wait">
            <div class="flex gap-2">
              <input type="number" :value="config.delay_value || 5" @input="updateConfig('delay_value', parseInt($event.target.value))" class="w-20 cfg-input" min="1" />
              <ConfigSelect :value="config.delay_unit || 'minutes'" @update="updateConfig('delay_unit', $event)" :options="[
                { value: 'seconds', label: 'Seconds' },
                { value: 'minutes', label: 'Minutes' },
                { value: 'hours', label: 'Hours' },
                { value: 'days', label: 'Days' },
              ]" class="flex-1" />
            </div>
          </ConfigField>
          <ConfigHint>Pauses the workflow for the specified duration before continuing.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ LOGIC: Filter ═══════════ -->
        <template v-else-if="node.type === 'logic.filter'">
          <ConfigField label="Field">
            <ConfigSelect
              v-if="upstreamFieldOptions.length > 2"
              :value="config.field || ''"
              @update="onFieldSelect($event, 'filter')"
              :options="upstreamFieldOptions"
            />
            <input v-else :value="config.field || ''" @input="updateConfig('field', $event.target.value)" class="w-full cfg-input" placeholder="Connect upstream nodes first, or type field name" />
            <input
              v-if="config._custom_field === 'filter'"
              :value="config.field || ''"
              @input="updateConfig('field', $event.target.value)"
              class="w-full cfg-input mt-1.5"
              placeholder="Type field name (e.g. response.data.value)"
            />
          </ConfigField>
          <ConfigField label="Operator">
            <ConfigSelect :value="config.operator || 'equals'" @update="updateConfig('operator', $event)" :options="[
              { value: 'equals', label: 'Equals' },
              { value: 'not_equals', label: 'Not Equals' },
              { value: 'contains', label: 'Contains' },
              { value: 'not_empty', label: 'Not Empty' },
              { value: 'is_empty', label: 'Is Empty' },
              { value: 'greater_than', label: 'Greater Than' },
              { value: 'less_than', label: 'Less Than' },
            ]" />
          </ConfigField>
          <template v-if="!['not_empty', 'is_empty'].includes(config.operator)">
            <ConfigField label="Value">
              <input :value="config.value || ''" @input="updateConfig('value', $event.target.value)" class="w-full cfg-input" placeholder="Value or {variable}" />
            </ConfigField>
          </template>
          <ConfigHint>Only passes data through if the condition is met. Otherwise the chain stops. The Value field supports {variables} from upstream.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ LOGIC: Merge ═══════════ -->
        <template v-else-if="node.type === 'logic.merge'">
          <ConfigField label="Merge Strategy">
            <ConfigSelect :value="config.strategy || 'combine'" @update="updateConfig('strategy', $event)" :options="[
              { value: 'combine', label: 'Combine All (merge both inputs)' },
              { value: 'first', label: 'First Only (keep input A)' },
              { value: 'last', label: 'Last Only (keep input B)' },
            ]" />
          </ConfigField>
          <ConfigHint>Waits for data from both input branches (A and B), then merges them using the chosen strategy before passing downstream.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Export to CSV ═══════════ -->
        <template v-else-if="node.type === 'action.export.csv'">
          <ConfigField label="Data Source">
            <ConfigSelect :value="config.data_source || 'input'" @update="updateConfig('data_source', $event)" :options="[
              { value: 'input', label: 'From Input Data (upstream node)' },
              { value: 'google_contacts', label: 'Google Contacts (from upstream)' },
              { value: 'email_campaign', label: 'Email Campaign Stats' },
              { value: 'crm_deals', label: 'CRM Deals' },
              { value: 'board_cards', label: 'Board Cards' },
            ]" />
          </ConfigField>

          <template v-if="config.data_source === 'email_campaign'">
            <ConfigField label="Campaign">
              <ConfigSelect :value="config.campaign_id || ''" @update="updateConfig('campaign_id', $event)" :options="campaignOptions" />
            </ConfigField>
            <ConfigField label="Filter Metric">
              <ConfigSelect :value="config.filter_metric || 'click_rate'" @update="updateConfig('filter_metric', $event)" :options="[
                { value: 'click_rate', label: 'Click Rate (%)' },
                { value: 'open_rate', label: 'Open Rate (%)' },
                { value: 'bounce', label: 'Bounced' },
                { value: 'unsubscribed', label: 'Unsubscribed' },
                { value: 'none', label: 'No Filter (export all)' },
              ]" />
            </ConfigField>
            <template v-if="config.filter_metric && config.filter_metric !== 'none' && !['bounce', 'unsubscribed'].includes(config.filter_metric)">
              <ConfigField label="Condition">
                <div class="flex gap-2">
                  <ConfigSelect :value="config.filter_condition || 'above'" @update="updateConfig('filter_condition', $event)" :options="[
                    { value: 'above', label: 'Above' },
                    { value: 'below', label: 'Below' },
                    { value: 'equals', label: 'Equals' },
                  ]" class="w-28" />
                  <input type="number" :value="config.filter_threshold ?? 60" @input="updateConfig('filter_threshold', parseFloat($event.target.value))" class="flex-1 cfg-input" placeholder="%" min="0" max="100" />
                  <span class="text-sm text-surface-400 self-center">%</span>
                </div>
              </ConfigField>
            </template>
          </template>

          <template v-if="config.data_source === 'crm_deals'">
            <ConfigField label="Stage Filter">
              <ConfigSelect :value="config.stage_filter || ''" @update="updateConfig('stage_filter', $event)" :options="pipelineStageOptions" />
            </ConfigField>
          </template>

          <template v-if="config.data_source === 'board_cards'">
            <ConfigField label="Board">
              <ConfigSelect :value="config.board_id || ''" @update="onBoardSelect($event)" :options="boardOptions" />
            </ConfigField>
            <ConfigField label="List Filter">
              <ConfigSelect :value="config.list_filter || ''" @update="updateConfig('list_filter', $event)" :options="listOptionsForBoard(config.board_id)" />
            </ConfigField>
          </template>

          <ConfigField label="Columns">
            <textarea :value="config.columns || ''" @input="updateConfig('columns', $event.target.value)" class="w-full cfg-input font-mono resize-y" rows="3" placeholder="email, name, click_rate, open_rate&#10;(one per line or comma-separated)&#10;Leave empty for all fields" />
          </ConfigField>

          <ConfigField label="File Name">
            <input :value="config.filename || ''" @input="updateConfig('filename', $event.target.value)" class="w-full cfg-input" placeholder="export-{date}.csv (auto-generated)" />
          </ConfigField>

          <ConfigField label="Notify on Completion">
            <ConfigSelect :value="config.notify || 'none'" @update="updateConfig('notify', $event)" :options="[
              { value: 'none', label: 'No notification' },
              { value: 'email', label: 'Send download link via email' },
              { value: 'notification', label: 'In-app notification' },
            ]" />
          </ConfigField>
          <template v-if="config.notify === 'email'">
            <ConfigField label="Send To">
              <input :value="config.notify_email || ''" @input="updateConfig('notify_email', $event.target.value)" class="w-full cfg-input" placeholder="recipient@example.com or {user_email}" />
            </ConfigField>
          </template>

          <ConfigHint>Generates a CSV file from the configured data source. Use with Filter / Condition nodes upstream to narrow down data.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Client Health Low ═══════════ -->
        <template v-else-if="node.type === 'trigger.client.health_low'">
          <ConfigField label="Client">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientOptions" />
          </ConfigField>
          <ConfigField label="Health Threshold">
            <div class="flex gap-2 items-center">
              <span class="text-xs text-surface-400">Below</span>
              <input type="number" :value="config.threshold || 40" @input="updateConfig('threshold', parseInt($event.target.value))" class="w-20 cfg-input" min="0" max="100" />
              <span class="text-xs text-surface-400">/ 100</span>
            </div>
          </ConfigField>
          <ConfigHint>Triggers when a client's health score drops below the threshold. Health is calculated from last activity recency.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Client Inactive ═══════════ -->
        <template v-else-if="node.type === 'trigger.client.inactive'">
          <ConfigField label="Client">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientOptions" />
          </ConfigField>
          <ConfigField label="Inactive For">
            <div class="flex gap-2">
              <input type="number" :value="config.days_inactive || 30" @input="updateConfig('days_inactive', parseInt($event.target.value))" class="w-20 cfg-input" min="1" />
              <span class="text-sm text-surface-400 self-center">days</span>
            </div>
          </ConfigField>
          <ConfigHint>Triggers when a client has had no activity (emails, tasks, meetings) for the specified number of days.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Invoice Paid ═══════════ -->
        <template v-else-if="node.type === 'trigger.invoice.paid'">
          <ConfigField label="Client Filter">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientOptions" />
          </ConfigField>
          <ConfigField label="Min Amount">
            <input type="number" :value="config.min_amount || ''" @input="updateConfig('min_amount', $event.target.value ? parseFloat($event.target.value) : null)" class="w-full cfg-input" placeholder="No minimum" />
          </ConfigField>
          <ConfigHint>Triggers when an invoice is marked as paid (fully or partially).</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Invoice Created ═══════════ -->
        <template v-else-if="node.type === 'trigger.invoice.created'">
          <ConfigField label="Client Filter">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientOptions" />
          </ConfigField>
          <ConfigHint>Triggers when a new invoice is created.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Financial Threshold ═══════════ -->
        <template v-else-if="node.type === 'trigger.financial.threshold'">
          <ConfigField label="Metric">
            <ConfigSelect :value="config.metric || 'revenue'" @update="updateConfig('metric', $event)" :options="[
              { value: 'revenue', label: 'Monthly Revenue' },
              { value: 'expenses', label: 'Monthly Expenses' },
              { value: 'outstanding', label: 'Outstanding Balance' },
              { value: 'overdue_count', label: 'Overdue Invoice Count' },
            ]" />
          </ConfigField>
          <ConfigField label="Condition">
            <div class="flex gap-2">
              <ConfigSelect :value="config.condition || 'above'" @update="updateConfig('condition', $event)" :options="[
                { value: 'above', label: 'Exceeds' },
                { value: 'below', label: 'Drops Below' },
              ]" class="w-28" />
              <input type="number" :value="config.threshold || 0" @input="updateConfig('threshold', parseFloat($event.target.value))" class="flex-1 cfg-input" placeholder="Amount" />
            </div>
          </ConfigField>
          <ConfigField label="Currency">
            <ConfigSelect :value="config.currency || 'EUR'" @update="updateConfig('currency', $event)" :options="[
              { value: 'EUR', label: 'EUR' },
              { value: 'USD', label: 'USD' },
              { value: 'GBP', label: 'GBP' },
              { value: 'HUF', label: 'HUF' },
            ]" />
          </ConfigField>
          <ConfigHint>Triggers when the selected financial metric crosses the configured threshold.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Get Client Data ═══════════ -->
        <template v-else-if="node.type === 'action.client.get_data'">
          <ConfigField label="Client">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientSelectOptions" />
          </ConfigField>
          <ConfigHint>Fetches client details, contacts, task counts, and last activity. Use {client_id} from a trigger or select a specific client.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Client Financials ═══════════ -->
        <template v-else-if="node.type === 'action.client.get_financials'">
          <ConfigField label="Client">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientSelectOptions" />
          </ConfigField>
          <ConfigHint>Fetches total invoiced, total paid, outstanding balance, and hourly rate for the client.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Client Health Score ═══════════ -->
        <template v-else-if="node.type === 'action.client.get_health'">
          <ConfigField label="Client">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientSelectOptions" />
          </ConfigField>
          <ConfigHint>Calculates the client health score (0-100) based on activity recency. Score: 7d=100, 14d=80, 30d=60, 60d=40, 90d=20.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Create Invoice ═══════════ -->
        <template v-else-if="node.type === 'action.invoice.create'">
          <ConfigField label="Client">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientSelectOptions" />
          </ConfigField>
          <ConfigField label="Due Date">
            <ConfigSelect :value="config.due_date_offset || '30'" @update="updateConfig('due_date_offset', $event)" :options="[
              { value: '7', label: 'Due in 7 days' },
              { value: '14', label: 'Due in 14 days' },
              { value: '30', label: 'Due in 30 days' },
              { value: '60', label: 'Due in 60 days' },
              { value: '90', label: 'Due in 90 days' },
            ]" />
          </ConfigField>
          <ConfigField label="Currency">
            <ConfigSelect :value="config.currency || 'EUR'" @update="updateConfig('currency', $event)" :options="[
              { value: 'EUR', label: 'EUR' },
              { value: 'USD', label: 'USD' },
              { value: 'GBP', label: 'GBP' },
              { value: 'HUF', label: 'HUF' },
            ]" />
          </ConfigField>
          <ConfigField label="Notes">
            <textarea :value="config.notes || ''" @input="updateConfig('notes', $event.target.value)" class="w-full cfg-input resize-y" rows="2" placeholder="Invoice notes (supports {variables})" />
          </ConfigField>
          <ConfigHint>Creates a draft invoice for the client. Add line items via the CRM interface or use the items JSON config for automation.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Send Invoice ═══════════ -->
        <template v-else-if="node.type === 'action.invoice.send'">
          <ConfigField label="Invoice Source">
            <ConfigSelect :value="config.invoice_source || 'trigger'" @update="updateConfig('invoice_source', $event)" :options="[
              { value: 'trigger', label: 'From Trigger/Upstream Data' },
              { value: 'manual', label: 'Specific Invoice ID' },
            ]" />
          </ConfigField>
          <template v-if="config.invoice_source === 'manual'">
            <ConfigField label="Invoice ID">
              <input type="number" :value="config.invoice_id || ''" @input="updateConfig('invoice_id', $event.target.value)" class="w-full cfg-input" placeholder="Invoice ID" />
            </ConfigField>
          </template>
          <ConfigHint>Marks a draft invoice as sent. Uses {invoice_id} from upstream data or a specific ID.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Record Payment ═══════════ -->
        <template v-else-if="node.type === 'action.invoice.record_payment'">
          <ConfigField label="Invoice Source">
            <ConfigSelect :value="config.invoice_source || 'trigger'" @update="updateConfig('invoice_source', $event)" :options="[
              { value: 'trigger', label: 'From Trigger/Upstream Data' },
              { value: 'manual', label: 'Specific Invoice ID' },
            ]" />
          </ConfigField>
          <template v-if="config.invoice_source === 'manual'">
            <ConfigField label="Invoice ID">
              <input type="number" :value="config.invoice_id || ''" @input="updateConfig('invoice_id', $event.target.value)" class="w-full cfg-input" placeholder="Invoice ID" />
            </ConfigField>
          </template>
          <ConfigField label="Payment Amount">
            <input type="number" :value="config.amount || ''" @input="updateConfig('amount', parseFloat($event.target.value))" class="w-full cfg-input" placeholder="Amount (leave empty for full payment)" step="0.01" />
          </ConfigField>
          <ConfigField label="Payment Method">
            <ConfigSelect :value="config.payment_method || 'bank_transfer'" @update="updateConfig('payment_method', $event)" :options="[
              { value: 'bank_transfer', label: 'Bank Transfer' },
              { value: 'credit_card', label: 'Credit Card' },
              { value: 'paypal', label: 'PayPal' },
              { value: 'cash', label: 'Cash' },
              { value: 'other', label: 'Other' },
            ]" />
          </ConfigField>
          <ConfigHint>Records a payment against an invoice. Automatically updates the invoice status (partial/paid).</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Email Statistics ═══════════ -->
        <template v-else-if="node.type === 'action.stats.email'">
          <ConfigField label="Period">
            <ConfigSelect :value="config.period || '30d'" @update="updateConfig('period', $event)" :options="statPeriodOptions" />
          </ConfigField>
          <ConfigHint>Fetches email statistics (sent, received, avg reply time) for the specified period. Data is passed downstream for conditions/filters.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Response Time ═══════════ -->
        <template v-else-if="node.type === 'action.stats.response_time'">
          <ConfigField label="Top Contacts">
            <div class="flex gap-2 items-center">
              <span class="text-xs text-surface-400">Show top</span>
              <input type="number" :value="config.top_contacts_limit || 10" @input="updateConfig('top_contacts_limit', parseInt($event.target.value))" class="w-16 cfg-input" min="1" max="50" />
              <span class="text-xs text-surface-400">contacts</span>
            </div>
          </ConfigField>
          <ConfigHint>Gets average reply time across all contacts and lists contacts ordered by fastest response. Useful for SLA monitoring.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Revenue Report ═══════════ -->
        <template v-else-if="node.type === 'action.stats.revenue_report'">
          <ConfigField label="Period">
            <ConfigSelect :value="config.period || '12m'" @update="updateConfig('period', $event)" :options="statPeriodOptions" />
          </ConfigField>
          <ConfigHint>Fetches revenue, expenses, and net profit with monthly breakdown. Use with Condition nodes to alert on thresholds.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Client Ranking ═══════════ -->
        <template v-else-if="node.type === 'action.stats.client_ranking'">
          <ConfigField label="Period">
            <ConfigSelect :value="config.period || '12m'" @update="updateConfig('period', $event)" :options="statPeriodOptions" />
          </ConfigField>
          <ConfigField label="Top Clients">
            <div class="flex gap-2 items-center">
              <span class="text-xs text-surface-400">Show top</span>
              <input type="number" :value="config.limit || 10" @input="updateConfig('limit', parseInt($event.target.value))" class="w-16 cfg-input" min="1" max="100" />
              <span class="text-xs text-surface-400">clients</span>
            </div>
          </ConfigField>
          <ConfigHint>Ranks clients by total revenue (paid invoices) in the specified period.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Invoice Aging ═══════════ -->
        <template v-else-if="node.type === 'action.stats.aging_report'">
          <ConfigHint>Generates an overdue invoice breakdown with aging buckets (0-30, 31-60, 61-90, 90+ days). Use with Condition to alert when overdue amounts are high.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: AI Prompt ═══════════ -->
        <template v-else-if="node.type === 'action.ai.prompt'">
          <ConfigField label="System Prompt">
            <textarea
              :value="config.system_prompt || 'You are a helpful AI assistant integrated into an automation workflow. Respond concisely and actionably.'"
              @input="updateConfig('system_prompt', $event.target.value)"
              rows="3"
              class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 rounded-xl focus:outline-none focus:border-primary-500 resize-y"
              placeholder="Define the AI's role and behavior..."
            />
          </ConfigField>
          <ConfigField label="User Prompt">
            <textarea
              :value="config.prompt || ''"
              @input="updateConfig('prompt', $event.target.value)"
              rows="4"
              class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 rounded-xl focus:outline-none focus:border-primary-500 resize-y"
              placeholder="What should the AI do? Use {variables} from upstream nodes..."
            />
          </ConfigField>
          <ConfigHint>The AI response will be available as {ai_response} in downstream nodes. Supports {variable} placeholders from upstream data.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: AI Summarize ═══════════ -->
        <template v-else-if="node.type === 'action.ai.summarize'">
          <ConfigField label="Text Source">
            <ConfigSelect :value="config.text_source || 'input'" @update="updateConfig('text_source', $event)" :options="[
              { value: 'input', label: 'From upstream node (auto-detect)' },
              { value: 'custom', label: 'Custom text' },
            ]" />
          </ConfigField>
          <ConfigField v-if="config.text_source === 'custom'" label="Text to Summarize">
            <textarea
              :value="config.text || ''"
              @input="updateConfig('text', $event.target.value)"
              rows="4"
              class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 rounded-xl focus:outline-none focus:border-primary-500 resize-y"
              placeholder="Text to summarize. Use {variables}..."
            />
          </ConfigField>
          <ConfigHint>Summary will be available as {ai_summary}, key points as {ai_key_points}, and sentiment as {ai_sentiment}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: AI Rewrite ═══════════ -->
        <template v-else-if="node.type === 'action.ai.rewrite'">
          <ConfigField label="Text Source">
            <ConfigSelect :value="config.text_source || 'input'" @update="updateConfig('text_source', $event)" :options="[
              { value: 'input', label: 'From upstream node (auto-detect)' },
              { value: 'custom', label: 'Custom text' },
            ]" />
          </ConfigField>
          <ConfigField v-if="config.text_source === 'custom'" label="Text to Rewrite">
            <textarea
              :value="config.text || ''"
              @input="updateConfig('text', $event.target.value)"
              rows="4"
              class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 rounded-xl focus:outline-none focus:border-primary-500 resize-y"
              placeholder="Text to rewrite. Use {variables}..."
            />
          </ConfigField>
          <ConfigField label="Writing Style">
            <ConfigSelect :value="config.writing_style || 'professional'" @update="updateConfig('writing_style', $event)" :options="[
              { value: 'professional', label: 'Professional' },
              { value: 'casual', label: 'Casual' },
              { value: 'formal', label: 'Formal' },
              { value: 'friendly', label: 'Friendly' },
              { value: 'concise', label: 'Concise' },
              { value: 'detailed', label: 'Detailed' },
              { value: 'persuasive', label: 'Persuasive' },
              { value: 'empathetic', label: 'Empathetic' },
            ]" />
          </ConfigField>
          <ConfigHint>Rewritten text will be available as {ai_rewritten} in downstream nodes.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Calendar Event Created ═══════════ -->
        <template v-else-if="node.type === 'trigger.calendar.event_created'">
          <ConfigField label="Calendar">
            <ConfigSelect :value="config.calendar_id || ''" @update="updateConfig('calendar_id', $event)" :options="calendarOptions" placeholder="All calendars" />
          </ConfigField>
          <ConfigHint>Fires whenever a new event is created. Use {event_title}, {event_start}, {event_end}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Calendar Event Upcoming ═══════════ -->
        <template v-else-if="node.type === 'trigger.calendar.event_upcoming'">
          <ConfigField label="Calendar">
            <ConfigSelect :value="config.calendar_id || ''" @update="updateConfig('calendar_id', $event)" :options="calendarOptions" placeholder="All calendars" />
          </ConfigField>
          <ConfigField label="Minutes Before">
            <input type="number" :value="config.minutes_before || 15" @input="updateConfig('minutes_before', parseInt($event.target.value))" class="w-full cfg-input" min="1" max="1440" />
          </ConfigField>
          <ConfigHint>Checked via cron. Fires when an event is about to start within the configured minutes.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Drive File Uploaded ═══════════ -->
        <template v-else-if="node.type === 'trigger.drive.file_uploaded'">
          <ConfigField label="Folder">
            <ConfigSelect :value="config.folder_id || ''" @update="updateConfig('folder_id', $event)" :options="driveFolderOptions" placeholder="Any folder" />
          </ConfigField>
          <ConfigHint>Fires when a new file is uploaded. Available: {file_name}, {file_type}, {file_size}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ TRIGGERS: Drive File Updated ═══════════ -->
        <template v-else-if="node.type === 'trigger.drive.file_updated'">
          <ConfigField label="Folder">
            <ConfigSelect :value="config.folder_id || ''" @update="updateConfig('folder_id', $event)" :options="driveFolderOptions" placeholder="Any folder" />
          </ConfigField>
          <ConfigHint>Fires when a file is modified. Available: {file_name}, {file_type}, {updated_at}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Calendar Create Event ═══════════ -->
        <template v-else-if="node.type === 'action.calendar.create_event'">
          <ConfigField label="Calendar">
            <ConfigSelect :value="config.calendar_id || ''" @update="updateConfig('calendar_id', $event)" :options="calendarOptions" placeholder="Default calendar" />
          </ConfigField>
          <ConfigField label="Title">
            <input :value="config.title || ''" @input="updateConfig('title', $event.target.value)" class="w-full cfg-input" placeholder="Event title (supports {variables})" />
          </ConfigField>
          <ConfigField label="Start">
            <input type="datetime-local" :value="config.start_time || ''" @input="updateConfig('start_time', $event.target.value)" class="w-full cfg-input" />
          </ConfigField>
          <ConfigField label="End">
            <input type="datetime-local" :value="config.end_time || ''" @input="updateConfig('end_time', $event.target.value)" class="w-full cfg-input" />
          </ConfigField>
          <ConfigField label="Description">
            <textarea :value="config.description || ''" @input="updateConfig('description', $event.target.value)" rows="3" class="w-full px-3 py-2 text-sm bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 rounded-xl focus:outline-none focus:border-primary-500 resize-y" placeholder="Description (supports {variables})" />
          </ConfigField>
          <ConfigField label="All Day">
            <button
              @click="updateConfig('all_day', !config.all_day)"
              class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
              :class="config.all_day ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
            >
              <span
                class="inline-block h-4 w-4 rounded-full bg-white transition-transform"
                :class="config.all_day ? 'translate-x-6' : 'translate-x-1'"
              />
            </button>
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Calendar Get Events ═══════════ -->
        <template v-else-if="node.type === 'action.calendar.get_events'">
          <ConfigField label="Calendar">
            <ConfigSelect :value="config.calendar_id || ''" @update="updateConfig('calendar_id', $event)" :options="calendarOptions" placeholder="All calendars" />
          </ConfigField>
          <ConfigField label="Start Date">
            <input type="date" :value="config.start_date || ''" @input="updateConfig('start_date', $event.target.value)" class="w-full cfg-input" />
          </ConfigField>
          <ConfigField label="End Date">
            <input type="date" :value="config.end_date || ''" @input="updateConfig('end_date', $event.target.value)" class="w-full cfg-input" />
          </ConfigField>
          <ConfigHint>Returns events as {events} array and {events_count}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Calendar Update Event ═══════════ -->
        <template v-else-if="node.type === 'action.calendar.update_event'">
          <ConfigField label="Event ID">
            <input :value="config.event_id || ''" @input="updateConfig('event_id', $event.target.value)" class="w-full cfg-input" placeholder="Use {event_id} from upstream" />
          </ConfigField>
          <ConfigField label="New Title">
            <input :value="config.title || ''" @input="updateConfig('title', $event.target.value)" class="w-full cfg-input" placeholder="Leave blank to keep current" />
          </ConfigField>
          <ConfigField label="New Start">
            <input type="datetime-local" :value="config.start_time || ''" @input="updateConfig('start_time', $event.target.value)" class="w-full cfg-input" />
          </ConfigField>
          <ConfigField label="New End">
            <input type="datetime-local" :value="config.end_time || ''" @input="updateConfig('end_time', $event.target.value)" class="w-full cfg-input" />
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Calendar Delete Event ═══════════ -->
        <template v-else-if="node.type === 'action.calendar.delete_event'">
          <ConfigField label="Event ID">
            <input :value="config.event_id || ''" @input="updateConfig('event_id', $event.target.value)" class="w-full cfg-input" placeholder="Use {event_id} from upstream" />
          </ConfigField>
          <ConfigHint>Permanently deletes the specified calendar event.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Calendar Get Upcoming ═══════════ -->
        <template v-else-if="node.type === 'action.calendar.get_upcoming'">
          <ConfigField label="Max Events">
            <input type="number" :value="config.limit || 5" @input="updateConfig('limit', parseInt($event.target.value))" class="w-full cfg-input" min="1" max="50" />
          </ConfigField>
          <ConfigHint>Returns next N events as {events}. Also {next_event_title} and {next_event_start}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Drive List Files ═══════════ -->
        <template v-else-if="node.type === 'action.drive.list_files'">
          <ConfigField label="Folder">
            <ConfigSelect :value="config.folder_id || ''" @update="updateConfig('folder_id', $event)" :options="driveFolderOptions" placeholder="Root folder" />
          </ConfigField>
          <ConfigField label="File Type Filter">
            <ConfigSelect :value="config.file_type_filter || 'all'" @update="updateConfig('file_type_filter', $event)" :options="[
              { value: 'all', label: 'All files' },
              { value: 'documents', label: 'Documents' },
              { value: 'images', label: 'Images' },
              { value: 'videos', label: 'Videos' },
            ]" />
          </ConfigField>
          <ConfigHint>Returns {files} array and {files_count}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Drive Get File Info ═══════════ -->
        <template v-else-if="node.type === 'action.drive.get_file_info'">
          <ConfigField label="File ID">
            <input :value="config.file_id || ''" @input="updateConfig('file_id', $event.target.value)" class="w-full cfg-input" placeholder="Use {file_id} from upstream" />
          </ConfigField>
          <ConfigHint>Returns {file_name}, {file_type}, {file_size}, {updated_at}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Drive Create Folder ═══════════ -->
        <template v-else-if="node.type === 'action.drive.create_folder'">
          <ConfigField label="Folder Name">
            <input :value="config.folder_name || ''" @input="updateConfig('folder_name', $event.target.value)" class="w-full cfg-input" placeholder="New folder name (supports {variables})" />
          </ConfigField>
          <ConfigField label="Parent Folder">
            <ConfigSelect :value="config.parent_folder_id || ''" @update="updateConfig('parent_folder_id', $event)" :options="driveFolderOptions" placeholder="Root" />
          </ConfigField>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Weather ═══════════ -->
        <template v-else-if="node.type === 'action.weather.get_current'">
          <ConfigField label="Location">
            <input :value="config.location || ''" @input="updateConfig('location', $event.target.value)" class="w-full cfg-input" placeholder="City name, e.g. Budapest (supports {variables})" />
          </ConfigField>
          <ConfigField label="Units">
            <ConfigSelect :value="config.units || 'metric'" @update="updateConfig('units', $event)" :options="[
              { value: 'metric', label: 'Metric (C)' },
              { value: 'imperial', label: 'Imperial (F)' },
            ]" />
          </ConfigField>
          <ConfigHint>Returns {weather_temp}, {weather_description}, {weather_humidity}, {weather_wind_speed}, {weather_city}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Google Get Contacts ═══════════ -->
        <template v-else-if="node.type === 'action.google.get_contacts'">
          <ConfigField label="Search Filter">
            <input :value="config.search || ''" @input="updateConfig('search', $event.target.value)" class="w-full cfg-input" placeholder="Optional: filter by name/email" />
          </ConfigField>
          <ConfigField label="Fetch All Contacts">
            <div class="flex items-center gap-3 py-1">
              <button @click="updateConfig('fetch_all', !config.fetch_all)"
                :class="['relative w-10 h-5 rounded-full transition-colors', config.fetch_all ? 'bg-primary-500' : 'bg-surface-600']">
                <span :class="['absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform', config.fetch_all ? 'left-5' : 'left-0.5']"></span>
              </button>
              <span class="text-xs text-surface-400">{{ config.fetch_all ? 'Yes (paginate all)' : 'No (use limit)' }}</span>
            </div>
          </ConfigField>
          <ConfigField v-if="!config.fetch_all" label="Max Results">
            <input type="number" :value="config.max_results || 500" @input="updateConfig('max_results', parseInt($event.target.value))" class="w-full cfg-input" min="1" max="2000" />
          </ConfigField>
          <ConfigHint>Returns {contacts} array with name/email/phone/company, {contacts_count}, and {contact_emails} (comma-separated). Pipe into Export CSV to save as a file.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Google Find Contact ═══════════ -->
        <template v-else-if="node.type === 'action.google.get_contact'">
          <ConfigField label="Search">
            <input :value="config.search || ''" @input="updateConfig('search', $event.target.value)" class="w-full cfg-input" placeholder="Name or email (supports {variables})" />
          </ConfigField>
          <ConfigHint>Returns {contact_name}, {contact_email}, {contact_phone}, {found}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Google Sync Calendar ═══════════ -->
        <template v-else-if="node.type === 'action.google.sync_calendar'">
          <ConfigField label="Google Calendar ID">
            <input :value="config.google_calendar_id || ''" @input="updateConfig('google_calendar_id', $event.target.value)" class="w-full cfg-input" placeholder="primary (or Google Calendar ID)" />
          </ConfigField>
          <ConfigHint>Forces a one-way sync from Google Calendar. Returns {events_synced}, {events_imported}, {events_updated}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Campaign Get Stats ═══════════ -->
        <template v-else-if="node.type === 'action.campaign.get_stats'">
          <ConfigField label="Campaign">
            <ConfigSelect :value="config.campaign_id || ''" @update="updateConfig('campaign_id', $event)" :options="campaignSelectOptions" />
          </ConfigField>
          <template v-if="!config.campaign_id">
            <ConfigField label="Or Filter by Status">
              <ConfigSelect :value="config.status_filter || ''" @update="updateConfig('status_filter', $event)" :options="[
                { value: '', label: '-- Latest Campaign --' },
                { value: 'draft', label: 'Latest Draft' },
                { value: 'processing', label: 'Currently Sending' },
                { value: 'completed', label: 'Last Completed' },
                { value: 'paused', label: 'Currently Paused' },
              ]" />
            </ConfigField>
          </template>
          <ConfigHint>Reads campaign statistics: open rate, click rate, bounces, unsubscribes. Use with Condition to make decisions based on performance.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Campaign Send ═══════════ -->
        <template v-else-if="node.type === 'action.campaign.send'">
          <ConfigField label="Send Mode">
            <ConfigSelect :value="config.send_mode || 'draft'" @update="updateConfig('send_mode', $event)" :options="[
              { value: 'draft', label: 'Send an existing draft campaign' },
              { value: 'new', label: 'Create and send a new campaign' },
            ]" />
          </ConfigField>

          <template v-if="(config.send_mode || 'draft') === 'draft'">
            <ConfigField label="Draft Campaign">
              <ConfigSelect :value="config.campaign_id || ''" @update="updateConfig('campaign_id', $event)" :options="draftCampaignOptions" />
            </ConfigField>
            <ConfigHint>Finalizes the selected draft campaign and starts sending to its mailing list. Make sure the draft has content and a mailing list assigned.</ConfigHint>
          </template>

          <template v-if="config.send_mode === 'new'">
            <ConfigField label="Mailing List">
              <ConfigSelect :value="config.mailing_list_id || ''" @update="updateConfig('mailing_list_id', $event)" :options="mailingListOptions" />
            </ConfigField>
            <ConfigField label="Subject">
              <input :value="config.subject || ''" @input="updateConfig('subject', $event.target.value)" class="w-full cfg-input" placeholder="Email subject (supports {variables})" />
            </ConfigField>
            <ConfigField label="Email Body (HTML)">
              <textarea :value="config.body_html || ''" @input="updateConfig('body_html', $event.target.value)" class="w-full cfg-input font-mono resize-y" rows="6" placeholder="<h1>Hello {name}!</h1>&#10;&#10;Supports merge tags: {name}, {email}, {phone}" />
            </ConfigField>
            <ConfigField label="From Name">
              <input :value="config.from_name || ''" @input="updateConfig('from_name', $event.target.value)" class="w-full cfg-input" placeholder="Sender display name (optional)" />
            </ConfigField>
            <ConfigHint>Creates a new campaign and sends it to all contacts in the mailing list. Unsubscribed contacts are automatically skipped. Supports merge tags in the body.</ConfigHint>
          </template>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: SQL Query ═══════════ -->
        <template v-else-if="node.type === 'action.sql.query'">
          <ConfigField label="Query Mode">
            <ConfigSelect :value="config.query_type || 'table'" @update="updateConfig('query_type', $event)" :options="[
              { value: 'table', label: 'Table Builder' },
              { value: 'custom', label: 'Custom SQL' },
            ]" />
          </ConfigField>

          <template v-if="(config.query_type || 'table') === 'table'">
            <ConfigField label="Table">
              <ConfigSelect :value="config.table || ''" @update="updateConfig('table', $event)" :options="sqlTableOptions" />
            </ConfigField>
            <ConfigField label="Columns">
              <input :value="config.columns || '*'" @input="updateConfig('columns', $event.target.value)" class="w-full cfg-input" placeholder="* or comma-separated: id, name, email" />
            </ConfigField>

            <div class="space-y-2">
              <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-surface-300">WHERE Conditions</span>
                <button @click="addSqlCondition" class="text-xs text-primary-400 hover:text-primary-300 flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">add</span> Add
                </button>
              </div>
              <div v-for="(cond, idx) in (config.conditions || [])" :key="idx" class="flex items-center gap-1.5">
                <input :value="cond.field" @input="updateSqlCondition(idx, 'field', $event.target.value)" class="cfg-input flex-1 min-w-0" placeholder="column" />
                <select :value="cond.operator || '='" @change="updateSqlCondition(idx, 'operator', $event.target.value)" class="cfg-input w-24 text-xs">
                  <option v-for="op in sqlOperatorOptions" :key="op.value" :value="op.value">{{ op.label }}</option>
                </select>
                <input v-if="!['IS NULL','IS NOT NULL'].includes(cond.operator)" :value="cond.value" @input="updateSqlCondition(idx, 'value', $event.target.value)" class="cfg-input flex-1 min-w-0" placeholder="value or {var}" />
                <button @click="removeSqlCondition(idx)" class="text-red-400 hover:text-red-300 shrink-0">
                  <span class="material-symbols-rounded text-base">close</span>
                </button>
              </div>
            </div>

            <ConfigField label="Order By">
              <input :value="config.order_by || ''" @input="updateConfig('order_by', $event.target.value)" class="w-full cfg-input" placeholder="Column name" />
            </ConfigField>
            <ConfigField label="Order Direction">
              <ConfigSelect :value="config.order_dir || 'ASC'" @update="updateConfig('order_dir', $event)" :options="[
                { value: 'ASC', label: 'Ascending' },
                { value: 'DESC', label: 'Descending' },
              ]" />
            </ConfigField>
            <ConfigField label="Limit">
              <input type="number" :value="config.limit || 100" @input="updateConfig('limit', $event.target.value)" class="w-full cfg-input" min="1" max="1000" />
            </ConfigField>
          </template>

          <template v-else>
            <ConfigField label="SQL Query">
              <textarea :value="config.custom_sql || ''" @input="updateConfig('custom_sql', $event.target.value)" class="w-full cfg-input font-mono resize-y" rows="6" placeholder="SELECT id, name, email&#10;FROM clients&#10;WHERE status = 'active'&#10;ORDER BY name ASC&#10;LIMIT 100" />
            </ConfigField>
            <ConfigHint>Write a read-only SELECT query. Only allowed tables: clients, crm_deals, crm_invoices, email_campaigns, mailing_lists, drive_files, calendar_events, etc. Variables like {user_email} are supported.</ConfigHint>
          </template>

          <ConfigHint>Read-only query. Returns {rows} array and {row_count}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Get Mailing List ═══════════ -->
        <template v-else-if="node.type === 'action.list.get_mailing_list'">
          <ConfigField label="Mailing List">
            <ConfigSelect :value="config.list_id || ''" @update="updateConfig('list_id', $event)" :options="mailingListOptions" />
          </ConfigField>
          <ConfigHint>Returns {contacts}, {contacts_count}, and {contact_emails} (comma-separated).</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Get Team Group ═══════════ -->
        <template v-else-if="node.type === 'action.list.get_team'">
          <ConfigField label="Team Group">
            <ConfigSelect :value="config.group_id || ''" @update="updateConfig('group_id', $event)" :options="groupOptions" />
          </ConfigField>
          <ConfigHint>Returns {members}, {members_count}, and {member_emails}. Leave empty for all colleagues.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Add to Mailing List ═══════════ -->
        <template v-else-if="node.type === 'action.list.add_contact'">
          <ConfigField label="Mailing List">
            <ConfigSelect :value="config.list_id || ''" @update="updateConfig('list_id', $event)" :options="mailingListOptions" />
          </ConfigField>
          <ConfigField label="Email">
            <input :value="config.email || ''" @input="updateConfig('email', $event.target.value)" class="w-full cfg-input" placeholder="email@example.com or {contact_email}" />
          </ConfigField>
          <ConfigField label="Name">
            <input :value="config.name || ''" @input="updateConfig('name', $event.target.value)" class="w-full cfg-input" placeholder="Contact name or {contact_name}" />
          </ConfigField>
          <ConfigHint>Adds a contact to the selected mailing list.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Remove from Mailing List ═══════════ -->
        <template v-else-if="node.type === 'action.list.remove_contact'">
          <ConfigField label="Mailing List">
            <ConfigSelect :value="config.list_id || ''" @update="updateConfig('list_id', $event)" :options="mailingListOptions" />
          </ConfigField>
          <ConfigField label="Email">
            <input :value="config.email || ''" @input="updateConfig('email', $event.target.value)" class="w-full cfg-input" placeholder="email@example.com or {contact_email}" />
          </ConfigField>
          <ConfigHint>Removes the contact matching this email from the list.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Start Sequence ═══════════ -->
        <template v-else-if="node.type === 'action.sequence.start'">
          <ConfigField label="Sequence">
            <ConfigSelect :value="config.sequence_id || ''" @update="updateConfig('sequence_id', $event)" :options="sequenceOptions" />
          </ConfigField>
          <ConfigField label="Client ID">
            <input :value="config.client_id || ''" @input="updateConfig('client_id', $event.target.value)" class="w-full cfg-input" placeholder="From upstream {client_id} or enter ID" />
          </ConfigField>
          <ConfigField label="Deal ID (optional)">
            <input :value="config.deal_id || ''" @input="updateConfig('deal_id', $event.target.value)" class="w-full cfg-input" placeholder="From upstream {deal_id} or enter ID" />
          </ConfigField>
          <ConfigHint>Enrolls the client in the selected email sequence.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Stop Sequence ═══════════ -->
        <template v-else-if="node.type === 'action.sequence.stop'">
          <ConfigField label="Sequence">
            <ConfigSelect :value="config.sequence_id || ''" @update="updateConfig('sequence_id', $event)" :options="sequenceOptions" />
          </ConfigField>
          <ConfigField label="Client ID">
            <input :value="config.client_id || ''" @input="updateConfig('client_id', $event.target.value)" class="w-full cfg-input" placeholder="From upstream {client_id}" />
          </ConfigField>
          <ConfigField label="Or Enrollment ID">
            <input :value="config.enrollment_id || ''" @input="updateConfig('enrollment_id', $event.target.value)" class="w-full cfg-input" placeholder="From upstream {enrollment_id}" />
          </ConfigField>
          <ConfigHint>Cancels an active sequence enrollment.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Sequence Status ═══════════ -->
        <template v-else-if="node.type === 'action.sequence.get_status'">
          <ConfigField label="Sequence">
            <ConfigSelect :value="config.sequence_id || ''" @update="updateConfig('sequence_id', $event)" :options="sequenceOptions" />
          </ConfigField>
          <ConfigField label="Client ID">
            <input :value="config.client_id || ''" @input="updateConfig('client_id', $event.target.value)" class="w-full cfg-input" placeholder="From upstream {client_id}" />
          </ConfigField>
          <ConfigField label="Or Enrollment ID">
            <input :value="config.enrollment_id || ''" @input="updateConfig('enrollment_id', $event.target.value)" class="w-full cfg-input" placeholder="From upstream {enrollment_id}" />
          </ConfigField>
          <ConfigHint>Returns {status}, {current_step}, {total_steps}, and {next_run_at}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Moodboard Get Info ═══════════ -->
        <template v-else-if="node.type === 'action.moodboard.get_info'">
          <ConfigField label="Moodboard">
            <ConfigSelect :value="config.board_id || ''" @update="updateConfig('board_id', $event)" :options="moodboardOptions" />
          </ConfigField>
          <ConfigField label="Or by Client">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientSelectOptions" />
          </ConfigField>
          <ConfigHint>Returns moodboard details. If no board selected, gets latest for the client.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: List Moodboards ═══════════ -->
        <template v-else-if="node.type === 'action.moodboard.list'">
          <ConfigField label="Filter by Client">
            <ConfigSelect :value="config.client_id || ''" @update="updateConfig('client_id', $event)" :options="clientSelectOptions" />
          </ConfigField>
          <ConfigField label="Include Archived">
            <div class="flex items-center gap-3 py-1">
              <button @click="updateConfig('show_archived', !config.show_archived)"
                :class="['relative w-10 h-5 rounded-full transition-colors', config.show_archived ? 'bg-primary-500' : 'bg-surface-600']">
                <span :class="['absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform', config.show_archived ? 'left-5' : 'left-0.5']"></span>
              </button>
              <span class="text-xs text-surface-400">{{ config.show_archived ? 'Yes' : 'No' }}</span>
            </div>
          </ConfigField>
          <ConfigField label="Limit">
            <input type="number" :value="config.limit || 20" @input="updateConfig('limit', $event.target.value)" class="w-full cfg-input" min="1" max="100" />
          </ConfigField>
          <ConfigHint>Returns {moodboards} array and {moodboard_count}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Share Moodboard ═══════════ -->
        <template v-else-if="node.type === 'action.moodboard.share'">
          <ConfigField label="Moodboard">
            <ConfigSelect :value="config.board_id || ''" @update="updateConfig('board_id', $event)" :options="moodboardOptions" />
          </ConfigField>
          <ConfigField label="Send To (email)">
            <input :value="config.recipient_email || ''" @input="updateConfig('recipient_email', $event.target.value)" class="w-full cfg-input" placeholder="email or {client_email}. Leave empty for link only." />
          </ConfigField>
          <ConfigHint>Generates a share link. If email provided, sends it. Returns {share_url}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Push to Billingo ═══════════ -->
        <template v-else-if="node.type === 'action.invoice.push_billingo'">
          <ConfigField label="Invoice">
            <ConfigSelect :value="config.invoice_id || ''" @update="updateConfig('invoice_id', $event)" :options="invoiceOptions" />
          </ConfigField>
          <ConfigHint>Pushes the invoice to the billing provider (Billingo). Returns {external_invoice_id} and {external_pdf_url}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Download Invoice PDF ═══════════ -->
        <template v-else-if="node.type === 'action.invoice.download_pdf'">
          <ConfigField label="Invoice">
            <ConfigSelect :value="config.invoice_id || ''" @update="updateConfig('invoice_id', $event)" :options="invoiceOptions" />
          </ConfigField>
          <ConfigField label="Save to Drive Folder">
            <ConfigSelect :value="config.folder_id || ''" @update="updateConfig('folder_id', $event)" :options="driveFolderOptions" />
          </ConfigField>
          <ConfigHint>Downloads the PDF from billing provider. If folder selected, saves to Drive. Returns {file_name} and {file_id}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Send Invoice to Client ═══════════ -->
        <template v-else-if="node.type === 'action.invoice.send_to_client'">
          <ConfigField label="Invoice">
            <ConfigSelect :value="config.invoice_id || ''" @update="updateConfig('invoice_id', $event)" :options="invoiceOptions" />
          </ConfigField>
          <ConfigField label="Recipient">
            <ConfigSelect :value="config.recipient_type || 'client'" @update="updateConfig('recipient_type', $event)" :options="[
              { value: 'client', label: 'Client contact email (from invoice)' },
              { value: 'upstream', label: 'From upstream data ({contact_email})' },
              { value: 'custom', label: 'Custom email...' },
            ]" />
          </ConfigField>
          <ConfigField v-if="config.recipient_type === 'custom'" label="Custom Email">
            <input :value="config.custom_email || ''" @input="updateConfig('custom_email', $event.target.value)" class="w-full cfg-input" placeholder="email@example.com or {variable}" />
          </ConfigField>
          <ConfigField label="Subject (optional)">
            <input :value="config.subject || ''" @input="updateConfig('subject', $event.target.value)" class="w-full cfg-input" placeholder="Invoice {invoice_number}" />
          </ConfigField>
          <ConfigHint>Emails the invoice with PDF attachment to the recipient.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Invoice Get Status ═══════════ -->
        <template v-else-if="node.type === 'action.invoice.get_status'">
          <ConfigField label="Invoice">
            <ConfigSelect :value="config.invoice_id || ''" @update="updateConfig('invoice_id', $event)" :options="invoiceOptions" />
          </ConfigField>
          <ConfigField label="Sync from Provider">
            <div class="flex items-center gap-3 py-1">
              <button @click="updateConfig('sync_from_provider', !config.sync_from_provider)"
                :class="['relative w-10 h-5 rounded-full transition-colors', config.sync_from_provider ? 'bg-primary-500' : 'bg-surface-600']">
                <span :class="['absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform', config.sync_from_provider ? 'left-5' : 'left-0.5']"></span>
              </button>
              <span class="text-xs text-surface-400">{{ config.sync_from_provider ? 'Fetch latest status' : 'Use local data' }}</span>
            </div>
          </ConfigField>
          <ConfigHint>Returns {status}, {total}, {paid_amount}, {due_date}, and more.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Trello Sync Boards ═══════════ -->
        <template v-else-if="node.type === 'action.trello.sync_boards'">
          <ConfigField label="Board Filter">
            <input :value="config.board_id || ''" @input="updateConfig('board_id', $event.target.value)" class="w-full cfg-input" placeholder="Leave empty for all boards, or paste Board ID" />
          </ConfigField>
          <div class="flex items-start gap-2 p-3 bg-blue-50 dark:bg-blue-500/5 border border-blue-200 dark:border-blue-500/20 rounded-lg">
            <span class="material-symbols-rounded text-blue-500 dark:text-blue-400 text-lg mt-0.5">info</span>
            <div class="text-xs text-surface-600 dark:text-surface-300">
              Imports Trello boards, lists, and cards into local Board Pro. Sync direction: <strong>Trello -> Local</strong> only.
            </div>
          </div>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Trello Get Boards ═══════════ -->
        <template v-else-if="node.type === 'action.trello.get_boards'">
          <ConfigField label="Board ID">
            <input :value="config.board_id || ''" @input="updateConfig('board_id', $event.target.value)" class="w-full cfg-input" placeholder="Optional. Get lists/cards for a specific board" />
          </ConfigField>
          <ConfigHint>Returns {boards} array. If board ID set, also {lists} with cards.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Mailchimp Get Lists ═══════════ -->
        <template v-else-if="node.type === 'action.mailchimp.get_lists'">
          <ConfigHint>Fetches all audiences (lists) from your Mailchimp account. Returns {lists} array with id, name, member_count, and {lists_count}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Mailchimp Get Members ═══════════ -->
        <template v-else-if="node.type === 'action.mailchimp.get_members'">
          <ConfigField label="Audience / List ID">
            <input :value="config.list_id || ''" @input="updateConfig('list_id', $event.target.value)" class="w-full cfg-input" placeholder="Mailchimp List ID (or use {list_id} from upstream)" />
          </ConfigField>
          <ConfigField label="Status Filter">
            <ConfigSelect :value="config.status || ''" @update="updateConfig('status', $event)" :options="[
              { value: '', label: 'All statuses' },
              { value: 'subscribed', label: 'Subscribed' },
              { value: 'unsubscribed', label: 'Unsubscribed' },
              { value: 'cleaned', label: 'Cleaned' },
              { value: 'pending', label: 'Pending' },
            ]" />
          </ConfigField>
          <ConfigField label="Limit">
            <input type="number" :value="config.limit || 100" @input="updateConfig('limit', parseInt($event.target.value) || 100)" class="w-full cfg-input" min="1" max="1000" />
          </ConfigField>
          <ConfigHint>Returns {members} array with email, status, merge fields. Use {list_id} from a Get Lists node upstream, or paste the Audience ID directly.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Mailchimp Add Member ═══════════ -->
        <template v-else-if="node.type === 'action.mailchimp.add_member'">
          <ConfigField label="Audience / List ID">
            <input :value="config.list_id || ''" @input="updateConfig('list_id', $event.target.value)" class="w-full cfg-input" placeholder="Mailchimp List ID" />
          </ConfigField>
          <ConfigField label="Email">
            <input :value="config.email || ''" @input="updateConfig('email', $event.target.value)" class="w-full cfg-input" placeholder="subscriber@example.com (supports {variables})" />
          </ConfigField>
          <ConfigField label="Status">
            <ConfigSelect :value="config.status || 'subscribed'" @update="updateConfig('status', $event)" :options="[
              { value: 'subscribed', label: 'Subscribed' },
              { value: 'pending', label: 'Pending (double opt-in)' },
            ]" />
          </ConfigField>
          <ConfigField label="First Name">
            <input :value="config.first_name || ''" @input="updateConfig('first_name', $event.target.value)" class="w-full cfg-input" placeholder="Optional (supports {variables})" />
          </ConfigField>
          <ConfigField label="Last Name">
            <input :value="config.last_name || ''" @input="updateConfig('last_name', $event.target.value)" class="w-full cfg-input" placeholder="Optional (supports {variables})" />
          </ConfigField>
          <ConfigField label="Update if exists">
            <div class="flex items-center gap-3 py-1">
              <button @click="updateConfig('update_existing', config.update_existing === false ? true : false)"
                :class="['relative w-10 h-5 rounded-full transition-colors', config.update_existing !== false ? 'bg-primary-500' : 'bg-surface-600']">
                <span :class="['absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform', config.update_existing !== false ? 'left-5' : 'left-0.5']"></span>
              </button>
              <span class="text-xs text-surface-400">{{ config.update_existing !== false ? 'Yes (update merge fields if member exists)' : 'No (fail if member exists)' }}</span>
            </div>
          </ConfigField>
          <ConfigHint>Adds or updates a subscriber in the selected audience. Returns {member_email}, {member_id}, {member_status}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Mailchimp Remove Member ═══════════ -->
        <template v-else-if="node.type === 'action.mailchimp.remove_member'">
          <ConfigField label="Audience / List ID">
            <input :value="config.list_id || ''" @input="updateConfig('list_id', $event.target.value)" class="w-full cfg-input" placeholder="Mailchimp List ID" />
          </ConfigField>
          <ConfigField label="Email">
            <input :value="config.email || ''" @input="updateConfig('email', $event.target.value)" class="w-full cfg-input" placeholder="subscriber@example.com (supports {variables})" />
          </ConfigField>
          <ConfigHint>Unsubscribes the contact from the audience. The member is not deleted, just set to "unsubscribed" status.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Mailchimp Get Campaigns ═══════════ -->
        <template v-else-if="node.type === 'action.mailchimp.get_campaigns'">
          <ConfigField label="Status Filter">
            <ConfigSelect :value="config.status || ''" @update="updateConfig('status', $event)" :options="[
              { value: '', label: 'All statuses' },
              { value: 'sent', label: 'Sent' },
              { value: 'save', label: 'Draft' },
              { value: 'sending', label: 'Currently sending' },
              { value: 'paused', label: 'Paused' },
              { value: 'schedule', label: 'Scheduled' },
            ]" />
          </ConfigField>
          <ConfigField label="Limit">
            <input type="number" :value="config.limit || 10" @input="updateConfig('limit', parseInt($event.target.value) || 10)" class="w-full cfg-input" min="1" max="100" />
          </ConfigField>
          <ConfigHint>Fetches campaigns from your Mailchimp account. Returns {campaigns} array with id, title, subject, status, send_time.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Mailchimp Send Campaign ═══════════ -->
        <template v-else-if="node.type === 'action.mailchimp.send_campaign'">
          <ConfigField label="Campaign ID">
            <input :value="config.campaign_id || ''" @input="updateConfig('campaign_id', $event.target.value)" class="w-full cfg-input" placeholder="Mailchimp Campaign ID (or use {campaign_id} from upstream)" />
          </ConfigField>
          <div class="flex items-start gap-2 p-3 bg-amber-50 dark:bg-amber-500/5 border border-amber-200 dark:border-amber-500/20 rounded-lg">
            <span class="material-symbols-rounded text-amber-500 dark:text-amber-400 text-lg mt-0.5">warning</span>
            <div class="text-xs text-surface-600 dark:text-surface-300">
              This will immediately send the campaign to all recipients. The campaign must be in <strong>draft</strong> ("save") status. This action cannot be undone.
            </div>
          </div>
          <ConfigHint>Sends a Mailchimp campaign. Use with MC Get Campaigns to find draft campaigns. Returns {campaign_id}, {campaign_title}, {send_status}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Printer List ═══════════ -->
        <template v-else-if="node.type === 'action.printer.list'">
          <div class="flex items-center gap-2 p-2.5 rounded-lg text-xs"
            :class="driveAvailable ? 'bg-emerald-50 dark:bg-emerald-500/5 border border-emerald-200 dark:border-emerald-500/20 text-emerald-700 dark:text-emerald-300' : 'bg-amber-50 dark:bg-amber-500/5 border border-amber-200 dark:border-amber-500/20 text-amber-700 dark:text-amber-300'">
            <span class="material-symbols-rounded text-base">{{ driveAvailable ? 'check_circle' : 'warning' }}</span>
            <span class="flex-1">{{ driveAvailable ? 'FlowOne Drive detected' : 'FlowOne Drive not running -- required for printer access' }}</span>
            <button @click="resetPrintersCache(); fetchPrinters()" class="ml-auto text-xs underline opacity-70 hover:opacity-100">Retry</button>
          </div>

          <!-- Detected printers preview -->
          <div v-if="printers.length" class="mt-1">
            <ConfigField label="Detected Printers">
              <div class="space-y-1">
                <div v-for="p in printers" :key="p.name" class="flex items-center gap-2 text-xs px-2.5 py-1.5 rounded-md bg-surface-700/50">
                  <span class="material-symbols-rounded text-sm text-surface-400">print</span>
                  <span class="text-surface-200 flex-1 truncate">{{ p.displayName || p.name }}</span>
                  <span v-if="p.isDefault" class="text-[10px] text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded-full font-medium">DEFAULT</span>
                </div>
              </div>
            </ConfigField>
          </div>

          <ConfigHint>Queries the local machine for available printers. Returns {printers} array with name, displayName, status, isDefault, {printers_count}, and {default_printer}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ ACTIONS: Printer Print ═══════════ -->
        <template v-else-if="node.type === 'action.printer.print'">
          <div class="flex items-center gap-2 p-2.5 rounded-lg text-xs mb-1"
            :class="driveAvailable ? 'bg-emerald-50 dark:bg-emerald-500/5 border border-emerald-200 dark:border-emerald-500/20 text-emerald-700 dark:text-emerald-300' : 'bg-amber-50 dark:bg-amber-500/5 border border-amber-200 dark:border-amber-500/20 text-amber-700 dark:text-amber-300'">
            <span class="material-symbols-rounded text-base">{{ driveAvailable ? 'check_circle' : 'warning' }}</span>
            <span>{{ driveAvailable ? 'FlowOne Drive detected' : 'FlowOne Drive not running' }}</span>
            <button v-if="!driveAvailable" @click="resetPrintersCache(); fetchPrinters()" class="ml-auto text-xs underline">Retry</button>
          </div>

          <ConfigField label="Printer">
            <ConfigSelect v-if="printerSelectOptions.length"
              :value="config.printer_name || ''" @update="updateConfig('printer_name', $event)" :options="printerSelectOptions" placeholder="Select a printer" />
            <input v-else :value="config.printer_name || ''" @input="updateConfig('printer_name', $event.target.value)"
              class="w-full cfg-input" placeholder="Printer name (or use {printer_name} from upstream)" />
          </ConfigField>

          <ConfigField label="Print Source">
            <ConfigSelect :value="config.print_source || 'upstream'" @update="updateConfig('print_source', $event)" :options="[
              { value: 'upstream', label: 'From upstream node (file_path or html)' },
              { value: 'drive_file', label: 'Drive file (by ID)' },
              { value: 'html', label: 'Custom HTML content' },
            ]" />
          </ConfigField>

          <template v-if="config.print_source === 'drive_file'">
            <ConfigField label="Drive File ID">
              <input :value="config.drive_file_id || ''" @input="updateConfig('drive_file_id', $event.target.value)" class="w-full cfg-input" placeholder="Use {file_id} from upstream or enter ID" />
            </ConfigField>
          </template>

          <template v-if="config.print_source === 'html'">
            <ConfigField label="HTML Content">
              <textarea :value="config.html_content || ''" @input="updateConfig('html_content', $event.target.value)" class="w-full cfg-input font-mono resize-y" rows="4" placeholder="<h1>Report</h1>&#10;<p>{summary}</p>" />
            </ConfigField>
          </template>

          <ConfigField label="Copies">
            <input type="number" :value="config.copies || 1" @input="updateConfig('copies', parseInt($event.target.value) || 1)" class="w-full cfg-input" min="1" max="100" />
          </ConfigField>

          <ConfigField label="Silent Print">
            <div class="flex items-center gap-3 py-1">
              <button @click="updateConfig('silent', config.silent === false ? true : false)"
                :class="['relative w-10 h-5 rounded-full transition-colors', config.silent !== false ? 'bg-primary-500' : 'bg-surface-600']">
                <span :class="['absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform', config.silent !== false ? 'left-5' : 'left-0.5']"></span>
              </button>
              <span class="text-xs text-surface-400">{{ config.silent !== false ? 'Yes (no dialog)' : 'No (show print dialog)' }}</span>
            </div>
          </ConfigField>

          <ConfigField label="Duplex (Double-sided)">
            <ConfigSelect :value="config.duplex || 'default'" @update="updateConfig('duplex', $event)" :options="[
              { value: 'default', label: 'Printer default' },
              { value: 'long-edge', label: 'Long edge (book style)' },
              { value: 'short-edge', label: 'Short edge (notepad style)' },
            ]" />
          </ConfigField>

          <ConfigHint>Sends a document to the selected printer via FlowOne Drive. Returns {print_success}, {print_printer}, {print_error}.</ConfigHint>
          <VariableBubble :type="node.type" />
        </template>

        <!-- ═══════════ Fallback ═══════════ -->
        <template v-else>
          <div class="text-center py-6">
            <span class="material-symbols-rounded text-2xl text-surface-500">info</span>
            <p class="text-xs text-surface-400 mt-2">This node has no configurable options.</p>
          </div>
          <VariableBubble :type="node.type" />
        </template>

      </div>

      <!-- Footer: delete node -->
      <div class="p-3 border-t border-surface-700">
        <button
          @click="store.removeNode(node.uid)"
          class="w-full flex items-center justify-center gap-2 px-4 py-2 rounded-full bg-red-500/10 text-red-400 hover:bg-red-500/20 transition-colors text-sm"
        >
          <span class="material-symbols-rounded text-lg">delete</span>
          Delete Node
        </button>
      </div>
    </template>

    <!-- Empty state -->
    <template v-else>
      <div class="flex-1 flex items-center justify-center p-6">
        <div class="text-center">
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">touch_app</span>
          <p class="text-sm text-surface-500 dark:text-surface-400 mt-3">Select a node to configure it</p>
          <p class="text-xs text-surface-400 dark:text-surface-500 mt-1">Click on any node in the canvas</p>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch, h } from 'vue'
import { useRouter } from 'vue-router'
import { useAutomationHubStore } from '../../stores/automationHub'
import { useNodeRegistry } from '../../composables/useNodeRegistry'
import { useAutomationData } from '../../composables/useAutomationData'
import { getPublicOrigin } from '@/services/serverRegistry'

const router = useRouter()
const store = useAutomationHubStore()
const { getNodeDef, getCategoryColors, getVariableDocs } = useNodeRegistry()
const {
  colleagues, groups, conversations, channels, boards, mailingLists, campaigns,
  clients, invoices, calendars, driveFolders, sequences, moodboards,
  printers, driveAvailable, connections,
  pipelineStages, invoiceStatuses, statPeriods, boardListsCache,
  fetchColleagues, fetchGroups, fetchConversations, fetchChannels,
  fetchBoards, fetchBoardLists, fetchMailingLists, fetchCampaigns,
  fetchClients, fetchInvoices, fetchCalendars, fetchDriveFolders,
  fetchSequences, fetchMoodboards, fetchPrinters, resetPrintersCache,
  fetchConnections,
} = useAutomationData()

const node = computed(() => store.selectedNode)
const nodeDef = computed(() => node.value ? getNodeDef(node.value.type) : null)
const colors = computed(() => node.value ? getCategoryColors(node.value.category) : {})
const config = computed(() => node.value?.config || {})

const showContactPicker = ref(false)
const showVarDocs = ref(false)

const webhookUrl = computed(() => {
  const token = config.value.webhook_token || node.value?.uid || ''
  // External services POST to this URL, so it must point at THIS deployment's
  // public origin (white-label safe) rather than a hardcoded flowone.pro host.
  const base = getPublicOrigin()
  return `${base}/api/automation-hub/webhook/${token}`
})

const printerSelectOptions = computed(() =>
  (printers.value || []).map(p => ({
    value: p.name,
    label: `${p.displayName || p.name}${p.isDefault ? ' (default)' : ''}`,
  }))
)

const nodeConnectionProvider = computed(() => {
  const t = node.value?.type || ''
  if (t.startsWith('action.mailchimp.')) return { key: 'mailchimp', label: 'Mailchimp', subtab: 'mailchimp' }
  if (t === 'action.weather.get_current') return { key: 'openweathermap', label: 'Weather', subtab: 'weather' }
  return null
})

const isNodeProviderConnected = computed(() => {
  const p = nodeConnectionProvider.value
  if (!p) return true
  return !!connections.value[p.key]?.connected
})

function openIntegrationSettings() {
  const p = nodeConnectionProvider.value
  if (p) router.push(`/settings?tab=integrations&subtab=${p.subtab}`)
}

// ── Lazy data fetching based on node type ──
watch(() => node.value?.type, (type) => {
  if (!type) return
  showContactPicker.value = false
  showVarDocs.value = false

  if (type.startsWith('trigger.board.') || type === 'action.board.move_card' || type === 'action.task.create' || (type === 'action.export.csv' && config.value.data_source === 'board_cards')) {
    fetchBoards()
  }
  if (type === 'action.notification.send') {
    fetchColleagues()
    fetchGroups()
  }
  if (type === 'action.chat.send') {
    fetchChannels()
    fetchConversations()
  }
  if (type === 'action.email.send') {
    fetchColleagues()
    fetchMailingLists()
    fetchGroups()
  }
  if (type === 'action.task.create') {
    fetchColleagues()
  }
  if (type === 'action.export.csv') {
    fetchCampaigns()
    fetchBoards()
  }
  if (type === 'action.campaign.get_stats' || type === 'action.campaign.send') {
    fetchCampaigns()
    fetchMailingLists()
  }
  if (type.startsWith('action.list.')) {
    fetchMailingLists()
    if (type === 'action.list.get_team') fetchGroups()
  }
  if (type.startsWith('action.sequence.')) {
    fetchSequences()
    fetchClients()
  }
  if (type.startsWith('action.moodboard.')) {
    fetchMoodboards()
    fetchClients()
  }
  if (type.startsWith('action.printer.')) {
    fetchPrinters()
  }
  if (type.startsWith('action.mailchimp.') || type.startsWith('action.trello.') || type === 'action.weather.get_current') {
    fetchConnections()
  }
  if (type.startsWith('action.invoice.') && !type.includes('create')) {
    fetchInvoices()
    fetchClients()
    if (type === 'action.invoice.download_pdf') fetchDriveFolders()
  }
  if (type.startsWith('trigger.client.') || type.startsWith('action.client.') || type === 'trigger.invoice.paid' || type === 'trigger.invoice.created' || type === 'action.invoice.create') {
    fetchClients()
  }
  if (type === 'action.invoice.send' || type === 'action.invoice.record_payment') {
    fetchInvoices()
  }
  if (type.includes('calendar.')) {
    fetchCalendars()
  }
  if (type.includes('drive.')) {
    fetchDriveFolders()
  }
}, { immediate: true })

// Re-fetch when CSV data source changes
watch(() => config.value.data_source, (ds) => {
  if (ds === 'board_cards') fetchBoards()
  if (ds === 'email_campaign') fetchCampaigns()
})

// ── Computed option lists ──
const boardOptions = computed(() => {
  const opts = [{ value: '', label: '-- Any Board --' }]
  for (const b of boards.value) {
    opts.push({ value: String(b.id), label: b.name || b.title || `Board #${b.id}` })
  }
  return opts
})

function listOptionsForBoard(boardId) {
  const opts = [{ value: '', label: '-- Any List --' }]
  if (!boardId) return opts
  const key = String(boardId)
  const lists = boardListsCache.value[key] || []
  for (const l of lists) {
    opts.push({ value: String(l.id || l.name || l.title), label: l.name || l.title || `List #${l.id}` })
  }
  return opts
}

const stageOptions = computed(() => {
  const opts = [{ value: '', label: '-- Any Stage --' }]
  for (const s of pipelineStages) {
    opts.push(s)
  }
  return opts
})

const pipelineStageOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select Stage --' }]
  for (const s of pipelineStages) {
    opts.push(s)
  }
  return opts
})

const colleaguesList = computed(() => colleagues.value || [])

const recipientOptions = computed(() => {
  const opts = [
    { value: 'trigger_user', label: 'Trigger User ({user_email})' },
    { value: 'custom', label: 'Custom Email...' },
  ]
  for (const c of colleagues.value) {
    opts.push({ value: `colleague:${c.email}`, label: c.display_name || c.email })
  }
  for (const g of groups.value) {
    opts.push({ value: `group:${g.id || g.name}`, label: `[Group] ${g.name}` })
  }
  return opts
})

const chatTarget = computed(() => {
  if (config.value.channel_id) return `channel:${config.value.channel_id}`
  if (config.value.conversation_id) return `conv:${config.value.conversation_id}`
  return ''
})

const chatTargetOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select Chat Target --' }]
  if (channels.value.length) {
    opts.push({ value: '__header_channels', label: '--- Channels ---', disabled: true })
    for (const ch of channels.value) {
      opts.push({ value: `channel:${ch.id}`, label: `# ${ch.name || ch.slug}` })
    }
  }
  if (conversations.value.length) {
    opts.push({ value: '__header_convos', label: '--- Conversations ---', disabled: true })
    for (const cv of conversations.value) {
      const label = cv.name || cv.participants?.map(p => p.display_name || p.email).join(', ') || `Conv #${cv.id}`
      opts.push({ value: `conv:${cv.id}`, label })
    }
  }
  return opts
})

const assigneeOptions = computed(() => {
  const opts = [
    { value: '', label: '-- No Assignee --' },
    { value: '{user_email}', label: 'From Trigger Data ({user_email})' },
  ]
  for (const c of colleagues.value) {
    opts.push({ value: c.email, label: c.display_name || c.email })
  }
  return opts
})

const campaignOptions = computed(() => {
  const opts = [{ value: '', label: '-- All Campaigns --' }]
  for (const c of campaigns.value) {
    const label = c.subject || c.name || `Campaign #${c.campaign_id || c.id}`
    opts.push({ value: String(c.campaign_id || c.id), label })
  }
  return opts
})

const clientOptions = computed(() => {
  const opts = [{ value: '', label: '-- Any Client --' }]
  for (const c of clients.value) {
    opts.push({ value: String(c.id), label: c.display_name || c.domain || `Client #${c.id}` })
  }
  return opts
})

const clientSelectOptions = computed(() => {
  const opts = [
    { value: '', label: '-- From Upstream Data ({client_id}) --' },
  ]
  for (const c of clients.value) {
    opts.push({ value: String(c.id), label: c.display_name || c.domain || `Client #${c.id}` })
  }
  return opts
})

const statPeriodOptions = computed(() => statPeriods.map(s => ({ value: s.value, label: s.label })))

const campaignSelectOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select Campaign (or use filter) --' }]
  for (const c of campaigns.value) {
    const status = c.status ? ` [${c.status}]` : ''
    const label = (c.subject || `Campaign ${c.campaign_id?.substring(0, 8)}`) + status
    opts.push({ value: c.campaign_id, label })
  }
  return opts
})

const draftCampaignOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select Draft Campaign --' }]
  for (const c of campaigns.value) {
    if (c.status !== 'draft') continue
    opts.push({ value: c.campaign_id, label: c.subject || `Draft ${c.campaign_id?.substring(0, 8)}` })
  }
  if (opts.length === 1) {
    opts.push({ value: '', label: 'No draft campaigns found', disabled: true })
  }
  return opts
})

const mailingListOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select Mailing List --' }]
  for (const ml of mailingLists.value) {
    const count = ml.contact_count != null ? ` (${ml.contact_count} contacts)` : ''
    opts.push({ value: String(ml.id), label: (ml.name || `List #${ml.id}`) + count })
  }
  return opts
})

const sequenceOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select Sequence --' }]
  for (const s of sequences.value) {
    opts.push({ value: String(s.id), label: s.name || `Sequence #${s.id}` })
  }
  return opts
})

const moodboardOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select Moodboard --' }]
  for (const mb of moodboards.value) {
    const items = mb.item_count != null ? ` (${mb.item_count} items)` : ''
    opts.push({ value: String(mb.id), label: (mb.name || `Moodboard #${mb.id}`) + items })
  }
  return opts
})

const invoiceOptions = computed(() => {
  const opts = [{ value: '', label: '-- From Upstream ({invoice_id}) --' }]
  for (const inv of invoices.value) {
    const num = inv.invoice_number || `#${inv.id}`
    const st = inv.status ? ` [${inv.status}]` : ''
    opts.push({ value: String(inv.id), label: num + st })
  }
  return opts
})

const groupOptions = computed(() => {
  const opts = [{ value: '', label: '-- All Colleagues --' }]
  for (const g of groups.value) {
    opts.push({ value: String(g.id || g.name), label: g.name || `Group #${g.id}` })
  }
  return opts
})

const SQL_ALLOWED_TABLES = [
  'clients', 'client_contacts', 'crm_invoices', 'crm_invoice_items',
  'crm_deals', 'email_campaigns', 'email_queue', 'mailing_lists',
  'mailing_list_contacts', 'organization_colleagues', 'colleague_groups',
  'mood_boards', 'crm_sequences', 'crm_sequence_enrollments',
  'webmail_board_cards', 'webmail_boards', 'drive_files', 'drive_folders',
  'calendar_events', 'crm_expenses', 'crm_invoice_payments',
]

const sqlTableOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select Table --' }]
  for (const t of SQL_ALLOWED_TABLES) {
    opts.push({ value: t, label: t })
  }
  return opts
})

const sqlOperatorOptions = [
  { value: '=', label: '= (equals)' },
  { value: '!=', label: '!= (not equals)' },
  { value: '>', label: '> (greater than)' },
  { value: '<', label: '< (less than)' },
  { value: '>=', label: '>= (greater or equal)' },
  { value: '<=', label: '<= (less or equal)' },
  { value: 'LIKE', label: 'LIKE (pattern)' },
  { value: 'NOT LIKE', label: 'NOT LIKE' },
  { value: 'IS NULL', label: 'IS NULL' },
  { value: 'IS NOT NULL', label: 'IS NOT NULL' },
]

function addSqlCondition() {
  const conditions = [...(config.value.conditions || [])]
  conditions.push({ field: '', operator: '=', value: '' })
  updateConfig('conditions', conditions)
}

function removeSqlCondition(idx) {
  const conditions = [...(config.value.conditions || [])]
  conditions.splice(idx, 1)
  updateConfig('conditions', conditions)
}

function updateSqlCondition(idx, key, val) {
  const conditions = [...(config.value.conditions || [])]
  conditions[idx] = { ...conditions[idx], [key]: val }
  updateConfig('conditions', conditions)
}

const calendarOptions = computed(() => {
  const opts = [{ value: '', label: '-- All Calendars --' }]
  for (const c of calendars.value) {
    opts.push({ value: String(c.id), label: c.name || `Calendar #${c.id}` })
  }
  return opts
})

const driveFolderOptions = computed(() => {
  const opts = [{ value: '', label: '-- Root Folder --' }]
  const folders = driveFolders.value
  if (!folders.length) return opts

  const byParent = {}
  for (const f of folders) {
    const pid = f.parent_id || 0
    if (!byParent[pid]) byParent[pid] = []
    byParent[pid].push(f)
  }
  for (const key of Object.keys(byParent)) {
    byParent[key].sort((a, b) => (a.name || '').localeCompare(b.name || ''))
  }

  function walk(parentId, depth) {
    const children = byParent[parentId] || []
    for (const f of children) {
      const indent = depth > 0 ? '\u00A0\u00A0\u00A0\u00A0'.repeat(depth) + '└ ' : ''
      opts.push({ value: String(f.id), label: indent + (f.name || `Folder #${f.id}`) })
      walk(f.id, depth + 1)
    }
  }
  walk(0, 0)

  return opts
})

// ── Event handlers ──
function updateConfig(key, value) {
  if (!node.value) return
  store.updateNodeConfig(node.value.uid, { [key]: value })
}

function onBoardSelect(val) {
  updateConfig('board_id', val)
  if (val) fetchBoardLists(val)
}

function onMoveCardBoardSelect(val) {
  updateConfig('target_board_id', val)
  if (val) fetchBoardLists(val)
}

function onChatTargetSelect(val) {
  if (!val || val.startsWith('__header')) return
  if (val.startsWith('channel:')) {
    store.updateNodeConfig(node.value.uid, { channel_id: val.replace('channel:', ''), conversation_id: '' })
  } else if (val.startsWith('conv:')) {
    store.updateNodeConfig(node.value.uid, { conversation_id: val.replace('conv:', ''), channel_id: '' })
  }
}

function copyWebhookUrl() {
  navigator.clipboard?.writeText(webhookUrl.value)
}

// ── Inline sub-components ──
const ConfigField = (props, { slots }) => h('div', [
  h('label', { class: 'block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1.5' }, props.label),
  slots.default?.(),
])
ConfigField.props = ['label']

const ConfigSelect = (props, { emit }) => h('select', {
  value: props.value,
  class: 'w-full bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg px-3 py-2 text-sm text-surface-800 dark:text-surface-200 focus:outline-none focus:border-primary-500 transition-colors',
  onChange: (e) => emit('update', e.target.value),
}, (props.options || []).map(o =>
  h('option', { value: o.value, disabled: o.disabled || false }, o.label)
))
ConfigSelect.props = ['value', 'options']
ConfigSelect.emits = ['update']

const ConfigHint = (_, { slots }) => h('div', {
  class: 'flex items-start gap-2 p-2.5 rounded-lg bg-surface-50 dark:bg-surface-800/50 border border-surface-200 dark:border-surface-700/50'
}, [
  h('span', { class: 'material-symbols-rounded text-sm text-surface-400 dark:text-surface-500 mt-0.5 shrink-0' }, 'info'),
  h('p', { class: 'text-[11px] text-surface-500 leading-relaxed' }, slots.default?.()),
])

// ── Upstream variable collection ──
function getUpstreamNodes(nodeUid) {
  const result = []
  const visited = new Set()
  const queue = [nodeUid]
  visited.add(nodeUid)

  while (queue.length) {
    const current = queue.shift()
    const incoming = store.edges.filter(e => e.targetUid === current)
    for (const edge of incoming) {
      if (!visited.has(edge.sourceUid)) {
        visited.add(edge.sourceUid)
        const n = store.nodes.get(edge.sourceUid)
        if (n) {
          result.push(n)
          queue.push(edge.sourceUid)
        }
      }
    }
  }
  return result.reverse()
}

const upstreamVariables = computed(() => {
  if (!node.value) return []
  const groups = []
  const upstream = getUpstreamNodes(node.value.uid)

  for (const n of upstream) {
    const def = getNodeDef(n.type)
    const docs = getVariableDocs(n.type)
    if (!docs?.length) continue
    const clickable = docs.filter(d => d.var.startsWith('{') && d.var.endsWith('}'))
    if (!clickable.length) continue
    groups.push({
      nodeUid: n.uid,
      nodeLabel: n.label || def?.label || n.type,
      nodeIcon: def?.icon || 'settings',
      nodeCategory: n.category || def?.category || 'action',
      vars: clickable,
    })
  }

  const ownDocs = getVariableDocs(node.value.type)
  if (ownDocs?.length) {
    const ownDef = getNodeDef(node.value.type)
    const clickable = ownDocs.filter(d => d.var.startsWith('{') && d.var.endsWith('}'))
    if (clickable.length) {
      groups.push({
        nodeUid: node.value.uid,
        nodeLabel: node.value.label || ownDef?.label || node.value.type,
        nodeIcon: ownDef?.icon || 'settings',
        nodeCategory: node.value.category || ownDef?.category || 'action',
        vars: clickable,
        isSelf: true,
      })
    }
  }

  return groups
})

const totalVarCount = computed(() => upstreamVariables.value.reduce((sum, g) => sum + g.vars.length, 0))

const upstreamFieldOptions = computed(() => {
  const opts = [{ value: '', label: '-- Select a field --' }]
  const groups = upstreamVariables.value
  for (const group of groups) {
    if (group.isSelf) continue
    for (const d of group.vars) {
      const raw = d.var.replace(/^\{|\}$/g, '')
      if (raw.includes('*')) continue
      opts.push({ value: raw, label: `${raw}  --  ${d.desc}` })
    }
  }
  opts.push({ value: '__custom__', label: 'Custom field path...' })
  return opts
})

function onFieldSelect(val, source) {
  if (val === '__custom__') {
    updateConfig('_custom_field', source)
    updateConfig('field', '')
    return
  }
  updateConfig('_custom_field', '')
  updateConfig('field', val)
}

const lastFocusedTextarea = ref(null)

function trackTextareaFocus(e) {
  if (e.target?.tagName === 'TEXTAREA' || e.target?.tagName === 'INPUT') {
    lastFocusedTextarea.value = e.target
  }
}

function insertVariable(varStr) {
  const el = lastFocusedTextarea.value
  if (!el) {
    const textareas = document.querySelectorAll('.node-config-form textarea, .node-config-form input[type="text"]')
    if (textareas.length) {
      const ta = textareas[textareas.length - 1]
      ta.focus()
      insertAtCursor(ta, varStr)
      return
    }
    return
  }
  el.focus()
  insertAtCursor(el, varStr)
}

function insertAtCursor(el, text) {
  const start = el.selectionStart ?? el.value.length
  const end = el.selectionEnd ?? el.value.length
  const before = el.value.substring(0, start)
  const after = el.value.substring(end)
  el.value = before + text + after
  el.selectionStart = el.selectionEnd = start + text.length
  el.dispatchEvent(new Event('input', { bubbles: true }))
}

const catColorMap = {
  trigger: { bg: 'bg-amber-50 dark:bg-amber-500/15', border: 'border-amber-300 dark:border-amber-500/40', text: 'text-amber-700 dark:text-amber-300', icon: 'text-amber-600 dark:text-amber-400', chip: 'bg-white dark:bg-surface-800 border-amber-300 dark:border-amber-500/40 text-amber-800 dark:text-amber-200 hover:bg-amber-50 dark:hover:bg-amber-500/15 hover:border-amber-400' },
  action: { bg: 'bg-blue-50 dark:bg-blue-500/15', border: 'border-blue-300 dark:border-blue-500/40', text: 'text-blue-700 dark:text-blue-300', icon: 'text-blue-600 dark:text-blue-400', chip: 'bg-white dark:bg-surface-800 border-blue-300 dark:border-blue-500/40 text-blue-800 dark:text-blue-200 hover:bg-blue-50 dark:hover:bg-blue-500/15 hover:border-blue-400' },
  logic: { bg: 'bg-emerald-50 dark:bg-emerald-500/15', border: 'border-emerald-300 dark:border-emerald-500/40', text: 'text-emerald-700 dark:text-emerald-300', icon: 'text-emerald-600 dark:text-emerald-400', chip: 'bg-white dark:bg-surface-800 border-emerald-300 dark:border-emerald-500/40 text-emerald-800 dark:text-emerald-200 hover:bg-emerald-50 dark:hover:bg-emerald-500/15 hover:border-emerald-400' },
}

const VariableBubble = (props) => {
  const groups = upstreamVariables.value
  if (!groups.length) return null

  return h('div', { class: 'mt-3', onFocusin: trackTextareaFocus }, [
    h('button', {
      class: 'flex items-center gap-1.5 text-[11px] font-medium text-primary-500 dark:text-primary-400 hover:text-primary-600 dark:hover:text-primary-300 transition-colors',
      onClick: () => { showVarDocs.value = !showVarDocs.value }
    }, [
      h('span', { class: 'material-symbols-rounded text-sm' }, showVarDocs.value ? 'expand_less' : 'data_object'),
      'Available Variables',
      h('span', {
        class: 'inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-primary-500/15 text-primary-500 text-[10px] font-semibold'
      }, String(totalVarCount.value)),
    ]),
    showVarDocs.value ? h('div', { class: 'mt-2 space-y-3' },
      groups.map(group => {
        const colors = catColorMap[group.nodeCategory] || catColorMap.action
        return h('div', {
          class: `rounded-lg border ${colors.border} ${colors.bg} overflow-hidden`
        }, [
          h('div', { class: 'flex items-center gap-2 px-2.5 py-2 border-b ' + colors.border }, [
            h('span', { class: `material-symbols-rounded text-sm ${colors.icon}` }, group.nodeIcon),
            h('span', { class: `text-[11px] font-bold ${colors.text} truncate` }, group.nodeLabel),
            group.isSelf
              ? h('span', { class: 'text-[9px] text-surface-400 ml-auto' }, 'this node')
              : h('span', { class: 'text-[9px] text-surface-400 ml-auto flex items-center gap-0.5' }, [
                  h('span', { class: 'material-symbols-rounded text-[10px]' }, 'arrow_upward'),
                  'upstream',
                ]),
          ]),
          h('div', { class: 'px-2 py-2 flex flex-wrap gap-1.5' },
            group.vars.map(d =>
              h('button', {
                class: `inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-mono font-medium border ${colors.chip} transition-all cursor-pointer group/chip shadow-sm`,
                title: d.desc,
                onClick: () => insertVariable(d.var),
              }, [
                h('span', null, d.var),
                h('span', { class: 'material-symbols-rounded text-[11px] opacity-0 group-hover/chip:opacity-100 transition-opacity -mr-0.5' }, 'add_circle'),
              ])
            )
          ),
        ])
      })
    ) : null,
  ])
}
VariableBubble.props = ['type']
</script>

<style scoped>
.cfg-input {
  @apply bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg px-3 py-2 text-sm text-surface-800 dark:text-surface-200 focus:outline-none focus:border-primary-500 transition-colors;
}
</style>
