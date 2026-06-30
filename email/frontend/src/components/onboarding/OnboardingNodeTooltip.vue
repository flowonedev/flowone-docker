<script setup>
import { useI18n } from 'vue-i18n'
import { boldKeywords } from './model/shared'

const { t } = useI18n()

const props = defineProps({
  nodeId: { type: String, required: true },
  posX: { type: String, default: 'right' },
  posY: { type: String, default: 'center' },
  tooltipGroup: { type: String, default: '' },
})

const tooltipPrefix = props.tooltipGroup
  ? `onboarding.${props.tooltipGroup}.tooltips`
  : 'onboarding.tooltips'
</script>

<template>
  <div
    :class="[
      'onb-tooltip fixed z-[9999] w-80 sm:w-96 rounded-2xl border shadow-2xl backdrop-blur-md overflow-hidden',
      'bg-white/95 dark:bg-surface-800/95 border-surface-200 dark:border-surface-600',
    ]"
    :style="{
      maxHeight: '80vh',
    }"
  >
    <!-- Header -->
    <div class="px-5 pt-4 pb-3">
      <h4 class="text-base font-semibold text-surface-800 dark:text-surface-100 mb-1.5">
        {{ t(`${tooltipPrefix}.${nodeId}.title`) }}
      </h4>
      <p class="text-sm text-surface-500 dark:text-surface-400 leading-relaxed" v-html="boldKeywords(t(`${tooltipPrefix}.${nodeId}.detail`))">
      </p>
    </div>

    <!-- CSS UI mockup preview per node -->
    <div class="px-4 pb-4">
      <!-- Email mockup -->
      <div v-if="nodeId === 'email'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-3 mb-3">
          <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white text-sm font-bold">TE</div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">Teszt Elek</div>
            <div class="text-xs text-surface-400 truncate">teszt.elek{'@'}example.com</div>
          </div>
          <span class="text-xs text-surface-400">2m</span>
        </div>
        <div class="text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">Wine Label Project</div>
        <div class="text-xs text-surface-400 leading-relaxed">Please proceed with the wine label project. Deadline is Jan 19th. I've attached the brief...</div>
        <div class="flex gap-2 mt-2">
          <span class="text-xs bg-surface-200 dark:bg-surface-700 text-surface-500 px-2 py-1 rounded-lg flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">attach_file</span>brief.pdf
          </span>
          <span class="text-xs bg-surface-200 dark:bg-surface-700 text-surface-500 px-2 py-1 rounded-lg flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">image</span>assets.zip
          </span>
        </div>
      </div>

      <!-- Conversations mockup -->
      <div v-else-if="nodeId === 'conversations'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="space-y-2.5">
          <div class="flex items-center gap-3 bg-primary-50 dark:bg-primary-900/30 rounded-lg p-2.5 border-l-3 border-primary-500">
            <span class="material-symbols-rounded text-primary-500 text-lg">mark_email_unread</span>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">Re: Wine Label Project</div>
              <div class="text-xs text-surface-400">3 messages in thread</div>
            </div>
            <span class="w-2.5 h-2.5 rounded-full bg-primary-500"></span>
          </div>
          <div class="flex items-center gap-3 rounded-lg p-2.5">
            <span class="material-symbols-rounded text-surface-400 text-lg">mark_email_read</span>
            <div class="flex-1 min-w-0">
              <div class="text-sm text-surface-600 dark:text-surface-300 truncate">Invoice #2024-015</div>
              <div class="text-xs text-surface-400">1 message</div>
            </div>
          </div>
        </div>
      </div>

      <!-- CRM Client mockup -->
      <div v-else-if="nodeId === 'crmClient'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-3 mb-3">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white text-sm font-bold">DS</div>
          <div>
            <div class="text-sm font-medium text-surface-700 dark:text-surface-200">Pixel Ranger Studio</div>
            <div class="text-xs text-surface-400">Auto-created from email</div>
          </div>
        </div>
        <div class="grid grid-cols-3 gap-2 text-center">
          <div class="bg-surface-200 dark:bg-surface-700 rounded-lg p-2">
            <div class="text-base font-bold text-blue-500">12</div>
            <div class="text-xs text-surface-400">Emails</div>
          </div>
          <div class="bg-surface-200 dark:bg-surface-700 rounded-lg p-2">
            <div class="text-base font-bold text-amber-500">4</div>
            <div class="text-xs text-surface-400">Tasks</div>
          </div>
          <div class="bg-surface-200 dark:bg-surface-700 rounded-lg p-2">
            <div class="text-base font-bold text-green-500">8.5h</div>
            <div class="text-xs text-surface-400">Time</div>
          </div>
        </div>
      </div>

      <!-- Tasks / Boards mockup (mini kanban) -->
      <div v-else-if="nodeId === 'tasksBoards'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="grid grid-cols-3 gap-2">
          <div class="bg-surface-200 dark:bg-surface-700/50 rounded-lg p-2.5">
            <div class="flex items-center gap-1.5 mb-2">
              <span class="w-2 h-2 rounded-full bg-blue-400"></span>
              <span class="text-xs text-surface-400">To Do</span>
            </div>
            <div class="space-y-1">
              <div class="bg-white dark:bg-surface-800 rounded-lg p-1.5 text-xs text-surface-600 dark:text-surface-300">Design</div>
              <div class="bg-white dark:bg-surface-800 rounded-lg p-1.5 text-xs text-surface-600 dark:text-surface-300">Back Label</div>
            </div>
          </div>
          <div class="bg-surface-200 dark:bg-surface-700/50 rounded-lg p-2.5">
            <div class="flex items-center gap-1.5 mb-2">
              <span class="w-2 h-2 rounded-full bg-amber-400"></span>
              <span class="text-xs text-surface-400">Progress</span>
            </div>
            <div class="bg-white dark:bg-surface-800 rounded-lg p-1.5 text-xs text-surface-600 dark:text-surface-300">Review</div>
          </div>
          <div class="bg-surface-200 dark:bg-surface-700/50 rounded-lg p-2.5">
            <div class="flex items-center gap-1.5 mb-2">
              <span class="w-2 h-2 rounded-full bg-green-400"></span>
              <span class="text-xs text-surface-400">Done</span>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-1.5 text-xs text-green-600 dark:text-green-400">Brief</div>
          </div>
        </div>
      </div>

      <!-- Time Tracking mockup -->
      <div v-else-if="nodeId === 'timeTracking'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-sm font-medium text-surface-700 dark:text-surface-200">Wine Label Project</span>
          <span class="material-symbols-rounded text-red-500 text-xl">stop_circle</span>
        </div>
        <div class="text-center mb-3">
          <div class="text-2xl font-mono font-bold text-surface-800 dark:text-surface-100">02:34:17</div>
          <div class="text-xs text-surface-400 mt-1">Running - Design task</div>
        </div>
        <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
          <div class="h-full bg-gradient-to-r from-primary-500 to-violet-500 rounded-full" style="width: 65%"></div>
        </div>
      </div>

      <!-- Client Report mockup -->
      <div v-else-if="nodeId === 'clientReport'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-sm font-medium text-surface-700 dark:text-surface-200">Progress Report</span>
          <span class="material-symbols-rounded text-green-500 text-lg">check_circle</span>
        </div>
        <div class="grid grid-cols-3 gap-2 text-center mb-3">
          <div>
            <div class="text-lg font-bold text-blue-500">2</div>
            <div class="text-xs text-surface-400">To Do</div>
          </div>
          <div>
            <div class="text-lg font-bold text-amber-500">1</div>
            <div class="text-xs text-surface-400">In Progress</div>
          </div>
          <div>
            <div class="text-lg font-bold text-green-500">3</div>
            <div class="text-xs text-surface-400">Done</div>
          </div>
        </div>
        <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
          <div class="h-full bg-green-500 rounded-full" style="width: 50%"></div>
        </div>
      </div>

      <!-- Invoice mockup -->
      <div v-else-if="nodeId === 'invoice'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-sm font-medium text-surface-700 dark:text-surface-200">INV-2024-015</span>
          <span class="text-xs bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 px-2.5 py-1 rounded-full font-medium">Paid</span>
        </div>
        <div class="space-y-2">
          <div class="flex justify-between text-sm">
            <span class="text-surface-400">Design work (8.5h)</span>
            <span class="text-surface-600 dark:text-surface-300">85,000 Ft</span>
          </div>
          <div class="flex justify-between text-sm">
            <span class="text-surface-400">Revisions (2h)</span>
            <span class="text-surface-600 dark:text-surface-300">20,000 Ft</span>
          </div>
          <div class="border-t border-surface-200 dark:border-surface-600 pt-2 flex justify-between text-sm font-semibold">
            <span class="text-surface-500">Total</span>
            <span class="text-surface-800 dark:text-surface-100">105,000 Ft</span>
          </div>
        </div>
      </div>

      <!-- Pipelines mockup -->
      <div v-else-if="nodeId === 'pipelines'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex gap-1.5 mb-3">
          <div v-for="(stage, i) in ['Lead', 'Proposal', 'Negotiation', 'Won']" :key="stage"
            :class="['flex-1 h-2 rounded-full', i < 2 ? 'bg-primary-500' : i === 2 ? 'bg-amber-400' : 'bg-surface-300 dark:bg-surface-600']"
          ></div>
        </div>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-violet-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-violet-500 text-base">handshake</span>
          </div>
          <div class="flex-1">
            <div class="text-sm font-medium text-surface-700 dark:text-surface-200">Branding Package</div>
            <div class="text-xs text-surface-400">Negotiation stage</div>
          </div>
          <span class="text-sm font-bold text-green-500">450K Ft</span>
        </div>
      </div>

      <!-- Automations mockup -->
      <div v-else-if="nodeId === 'automations'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="space-y-2.5">
          <div class="flex items-center gap-2">
            <span class="w-6 h-6 rounded-lg bg-amber-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-amber-500 text-sm">bolt</span>
            </span>
            <span class="text-xs text-surface-500">When deal moves to "Won"</span>
          </div>
          <div class="ml-3 border-l-2 border-primary-300 dark:border-primary-700 pl-3 space-y-2">
            <div class="text-sm text-surface-600 dark:text-surface-300 flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-green-500">check_circle</span> Create board
            </div>
            <div class="text-sm text-surface-600 dark:text-surface-300 flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-green-500">check_circle</span> Send welcome email
            </div>
            <div class="text-sm text-surface-600 dark:text-surface-300 flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-blue-500">schedule</span> Start sequence
            </div>
          </div>
        </div>
      </div>

      <!-- Board Automations mockup -->
      <div v-else-if="nodeId === 'boardAutomations'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-2 mb-3">
          <span class="material-symbols-rounded text-amber-500 text-lg">smart_toy</span>
          <span class="text-sm font-medium text-surface-700 dark:text-surface-200">Active Rules</span>
        </div>
        <div class="space-y-2">
          <div class="text-sm bg-surface-200 dark:bg-surface-700 rounded-lg p-2.5 text-surface-600 dark:text-surface-300">
            Card created &rarr; Assign to owner
          </div>
          <div class="text-sm bg-surface-200 dark:bg-surface-700 rounded-lg p-2.5 text-surface-600 dark:text-surface-300">
            Moved to Done &rarr; Notify client
          </div>
        </div>
      </div>

      <!-- Sequences mockup -->
      <div v-else-if="nodeId === 'sequences'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="space-y-2">
          <div v-for="(step, i) in [{label: 'Welcome email', delay: 'Day 0', done: true}, {label: 'Follow-up', delay: 'Day 3', done: true}, {label: 'Check-in', delay: 'Day 7', done: false}]"
            :key="i" class="flex items-center gap-2.5"
          >
            <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold', step.done ? 'bg-green-500 text-white' : 'bg-surface-300 dark:bg-surface-600 text-surface-500']">
              {{ step.done ? '\u2713' : i + 1 }}
            </span>
            <span class="text-sm flex-1" :class="step.done ? 'text-surface-400 line-through' : 'text-surface-700 dark:text-surface-200'">{{ step.label }}</span>
            <span class="text-xs text-surface-400">{{ step.delay }}</span>
          </div>
        </div>
      </div>

      <!-- Team Lists mockup -->
      <div v-else-if="nodeId === 'teamLists'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-2 mb-3">
          <span class="material-symbols-rounded text-primary-500 text-lg">groups</span>
          <span class="text-sm font-medium text-surface-700 dark:text-surface-200">Design Team</span>
          <span class="text-xs bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 px-2 py-0.5 rounded-full ml-auto">4 members</span>
        </div>
        <div class="flex -space-x-2">
          <div v-for="c in ['bg-blue-500', 'bg-violet-500', 'bg-amber-500', 'bg-green-500']" :key="c"
            :class="['w-8 h-8 rounded-full border-2 border-white dark:border-surface-800', c]"
          ></div>
        </div>
      </div>

      <!-- Emailing Lists mockup -->
      <div v-else-if="nodeId === 'emailingLists'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-sm font-medium text-surface-700 dark:text-surface-200">Newsletter Subscribers</span>
          <span class="text-xs text-surface-400">142 contacts</span>
        </div>
        <div class="flex gap-2 flex-wrap">
          <span v-for="tag in ['VIP', 'Design', 'Active']" :key="tag"
            class="text-xs bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 px-2.5 py-1 rounded-full"
          >{{ tag }}</span>
        </div>
      </div>

      <!-- Email Campaigns mockup -->
      <div v-else-if="nodeId === 'emailCampaigns'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="text-sm font-medium text-surface-700 dark:text-surface-200 mb-3">Spring Campaign</div>
        <div class="grid grid-cols-3 gap-2 text-center">
          <div>
            <div class="text-lg font-bold text-blue-500">89%</div>
            <div class="text-xs text-surface-400">Delivered</div>
          </div>
          <div>
            <div class="text-lg font-bold text-green-500">34%</div>
            <div class="text-xs text-surface-400">Opened</div>
          </div>
          <div>
            <div class="text-lg font-bold text-amber-500">12%</div>
            <div class="text-xs text-surface-400">Clicked</div>
          </div>
        </div>
      </div>

      <!-- Drive mockup -->
      <div v-else-if="nodeId === 'drive'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="space-y-1.5">
          <div v-for="f in [{icon: 'folder', name: 'Wine Label', color: 'text-amber-500'}, {icon: 'description', name: 'Brief.docx', color: 'text-blue-500'}, {icon: 'image', name: 'Assets.zip', color: 'text-green-500'}]"
            :key="f.name" class="flex items-center gap-3 p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700"
          >
            <span :class="['material-symbols-rounded text-lg', f.color]">{{ f.icon }}</span>
            <span class="text-sm text-surface-600 dark:text-surface-300 flex-1">{{ f.name }}</span>
            <span class="text-xs text-surface-400">v3</span>
          </div>
        </div>
      </div>

      <!-- Chat mockup -->
      <div v-else-if="nodeId === 'chat'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="space-y-2.5">
          <div class="flex gap-2">
            <div class="w-6 h-6 rounded-full bg-blue-500 flex-shrink-0 mt-0.5"></div>
            <div class="bg-surface-200 dark:bg-surface-700 rounded-xl px-3 py-2 text-sm text-surface-600 dark:text-surface-300 max-w-[80%]">
              The label designs are ready for review
            </div>
          </div>
          <div class="flex gap-2 justify-end">
            <div class="bg-primary-500 rounded-xl px-3 py-2 text-sm text-white max-w-[80%]">
              Looks great! Let me share with the client
            </div>
            <div class="w-6 h-6 rounded-full bg-violet-500 flex-shrink-0 mt-0.5"></div>
          </div>
        </div>
      </div>

      <!-- Video mockup -->
      <div v-else-if="nodeId === 'video'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="bg-surface-800 dark:bg-surface-950 rounded-xl p-4 flex items-center justify-center gap-6 mb-3">
          <div class="w-14 h-11 rounded-lg bg-gradient-to-br from-blue-500/30 to-violet-500/30 flex items-center justify-center">
            <span class="material-symbols-rounded text-blue-400 text-xl">person</span>
          </div>
          <div class="w-14 h-11 rounded-lg bg-gradient-to-br from-violet-500/30 to-pink-500/30 flex items-center justify-center">
            <span class="material-symbols-rounded text-violet-400 text-xl">person</span>
          </div>
        </div>
        <div class="flex items-center justify-center gap-3">
          <span class="w-8 h-8 rounded-full bg-surface-200 dark:bg-surface-700 flex items-center justify-center">
            <span class="material-symbols-rounded text-surface-500 text-base">mic</span>
          </span>
          <span class="w-8 h-8 rounded-full bg-surface-200 dark:bg-surface-700 flex items-center justify-center">
            <span class="material-symbols-rounded text-surface-500 text-base">videocam</span>
          </span>
          <span class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center">
            <span class="material-symbols-rounded text-white text-base">call_end</span>
          </span>
          <span class="w-8 h-8 rounded-full bg-surface-200 dark:bg-surface-700 flex items-center justify-center">
            <span class="material-symbols-rounded text-surface-500 text-base">screen_share</span>
          </span>
        </div>
      </div>

      <!-- Moodboards mockup -->
      <div v-else-if="nodeId === 'moodboards'" class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
        <div class="relative h-24">
          <div class="absolute top-0 left-0 w-14 h-11 rounded-lg bg-gradient-to-br from-pink-400 to-violet-400 opacity-80"></div>
          <div class="absolute top-3 left-12 w-16 h-9 rounded-lg bg-gradient-to-br from-amber-400 to-orange-400 opacity-70"></div>
          <div class="absolute bottom-0 left-3 w-12 h-12 rounded-lg bg-gradient-to-br from-blue-400 to-cyan-400 opacity-75"></div>
          <div class="absolute top-1 right-3 w-20 h-14 rounded-lg bg-gradient-to-br from-green-400 to-emerald-400 opacity-60"></div>
          <svg class="absolute inset-0 w-full h-full pointer-events-none" style="overflow: visible">
            <path d="M 28 22 C 42 42, 56 56, 72 62" stroke="url(#moodGrad)" stroke-width="2" fill="none" stroke-linecap="round" opacity="0.7">
              <animate attributeName="stroke-dashoffset" values="80;0" dur="1.5s" fill="freeze" />
            </path>
            <defs><linearGradient id="moodGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#ec4899" /><stop offset="100%" stop-color="#8b5cf6" /></linearGradient></defs>
          </svg>
        </div>
      </div>
    </div>

    <!-- Connection info footer -->
    <div class="px-5 py-2.5 bg-surface-50 dark:bg-surface-900/50 border-t border-surface-200 dark:border-surface-700">
      <div class="flex items-center gap-2 text-xs text-surface-400">
        <span class="material-symbols-rounded text-sm">link</span>
        {{ t(`${tooltipPrefix}.${nodeId}.connects`) }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.onb-tooltip {
  animation: tooltipFadeIn 0.2s ease-out;
}

@keyframes tooltipFadeIn {
  from { opacity: 0; transform: scale(0.96); }
  to { opacity: 1; transform: scale(1); }
}
</style>
