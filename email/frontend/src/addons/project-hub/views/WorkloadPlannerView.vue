<script setup>
import { ref, computed, watch, onMounted, onUnmounted, defineAsyncComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import ProjectHubLayout from '@/addons/project-hub/components/ProjectHubLayout.vue'
import ProjectHubViewIntro from '@/addons/project-hub/components/ProjectHubViewIntro.vue'
import { WORK_HUB_MODES, resolveMode } from '@/addons/project-hub/services/workHubService'

const MyWorkPanel = defineAsyncComponent(() => import('@/addons/project-hub/components/MyWorkPanel.vue'))
const WorkloadTimeline = defineAsyncComponent(() => import('@/addons/project-hub/components/WorkloadTimeline.vue'))
const WorkloadLive = defineAsyncComponent(() => import('@/addons/project-hub/components/WorkloadLive.vue'))
const TrafficTableView = defineAsyncComponent(() => import('@/addons/project-hub/components/TrafficTableView.vue'))
const TeamScheduleView = defineAsyncComponent(() => import('@/addons/project-hub/components/TeamScheduleView.vue'))
const TeamCompletionsView = defineAsyncComponent(() => import('@/addons/project-hub/components/TeamCompletionsView.vue'))
const BreakdownTab = defineAsyncComponent(() => import('@/addons/project-hub/components/BreakdownTab.vue'))

const route = useRoute()
const router = useRouter()
const hubStore = useProjectHubStore()
const colleaguesStore = useColleaguesStore()

const isAdmin = computed(() => colleaguesStore.isAdmin)
const visibleModes = computed(() => WORK_HUB_MODES.filter(m => !m.admin || isAdmin.value))
const activeMode = computed(() => resolveMode(route.query.mode))

const TEAM_SUB_VIEWS = [
  { key: 'timeline', label: 'Timeline', icon: 'calendar_month' },
  { key: 'live', label: 'Live', icon: 'radio_button_checked' },
  { key: 'schedule', label: 'Schedule', icon: 'table_chart' },
  { key: 'capacity', label: 'Capacity', icon: 'grid_on' },
  { key: 'done-log', label: 'Done Log', icon: 'task_alt' },
]

const teamIntroSections = [
  {
    heading: 'Timeline — Gantt-style resource view',
    items: [
      { icon: 'view_timeline', title: 'See 1–8 weeks ahead', body: 'Every team member\'s tasks laid out as bars from start → due date. Overlapping bars instantly reveal overbooked days.' },
      { icon: 'filter_alt', title: 'Filter by member, group, label', body: 'Slice the view to a campaign, a department, or a single person. Get the answer to "what is Anna doing next week?" in two clicks.' },
    ],
  },
  {
    heading: 'Live — what\'s happening right now',
    items: [
      { icon: 'radio_button_checked', title: 'Real-time activity feed', body: 'Refreshes every 30 seconds. See who\'s clocked in to which task at this very moment.' },
      { icon: 'circle', title: 'Presence dots', body: 'Online / away / offline indicators from the team store. Know if you can ping someone before you do.' },
    ],
  },
  {
    heading: 'Schedule — bookings calendar',
    items: [
      { icon: 'table_chart', title: 'Tabular calendar', body: 'Booked time per person per day. Find a free slot before promising delivery dates to a client.' },
    ],
  },
  {
    heading: 'Capacity — load heatmap',
    items: [
      { icon: 'grid_on', title: 'Role-by-role load', body: 'Graphic, account, dev — see which roles are overloaded and which have headroom. Filter by role to see only your team.' },
      { icon: 'warning', title: 'Bottleneck early warning', body: 'When a role hits 100%+ for the upcoming week, capacity flags it before deadlines slip.' },
    ],
  },
  {
    heading: 'Done Log — who finished what, each day',
    items: [
      { icon: 'task_alt', title: 'Day-by-day completions', body: 'Every completed task grouped by day and person. Monday standup answered before anyone speaks.' },
      { icon: 'history', title: 'Week navigation', body: 'Step back through previous weeks to review output per person, per client.' },
    ],
  },
]

const teamIntroBenefits = [
  '<strong>Spot overbooking before the client does.</strong> "We need it Friday" gets a real answer, not a guess.',
  '<strong>Answer "who can pick this up?" in 5 seconds</strong> — the Live tab kills the all-team Slack ping.',
  '<strong>Commit realistic delivery dates</strong> in front of clients: "Friday works because Anna has 4 free hours that day."',
  '<strong>Catch bottlenecks early.</strong> Designer at 120% + account manager at 40% = redistribute before missing a deadline.',
  '<strong>Hiring signal:</strong> if Capacity stays red for 3+ weeks, that\'s data-backed evidence to hire — not a gut feeling.',
]

const taskTimeIntroSections = [
  {
    items: [
      { icon: 'calendar_month', title: 'Pick any period', body: 'Week, month, quarter, year — get the exact slice your accountant or client asks for.' },
      { icon: 'business', title: 'Filter by client', body: 'Per-client breakdowns that map 1:1 to invoice line items. "Acme — November — 47.2h" — paste, send, done.' },
      { icon: 'view_kanban', title: 'Filter by board', body: '"How much did we spend on the rebrand project?" Answered in two clicks.' },
      { icon: 'warning', title: 'Over-estimate alerts', body: 'Cards that blew past their time_estimate are flagged so the next quote uses real numbers.' },
      { icon: 'people', title: 'Per-person columns', body: 'See exactly who logged what against this client / project — useful for billing rates vs. cost allocation.' },
      { icon: 'history', title: 'Audit trail', body: 'Every minute has a user, task, board, space, and client attached. Survives any tax authority sneeze.' },
    ],
  },
]

const taskTimeIntroBenefits = [
  '<strong>Invoicing in 2 clicks.</strong> No more end-of-month spreadsheet voodoo.',
  '<strong>Profit-per-client visibility.</strong> Cross-reference tracked hours × cost-rate vs. fee — find the clients you\'re losing money on.',
  '<strong>Estimate calibration.</strong> Real numbers from past work make the next quote sharper.',
  '<strong>Stop unpaid scope creep.</strong> "Free revision" turned into 8 logged hours? It\'s right here — basis for a hard conversation.',
  '<strong>Defend pricing decisions</strong> with hard data when a client pushes back.',
]

const members = ref([])
const loading = ref(false)
const labels = ref([])
const timelineRef = ref(null)
const teamSubView = ref('timeline')
let liveInterval = null

function setMode(mode) {
  router.replace({ name: 'workload', query: { ...route.query, mode } })
}

function openCard(card) {
  const cardId = card.card_id || card.id
  if (card.board_id) {
    hubStore.selectBoard(card.board_id)
  }
  router.push({ name: 'workload-card', params: { cardId } })
}

async function loadTimeline() {
  loading.value = true
  try {
    const t = timelineRef.value
    const filters = {}
    if (t?.selectedMember) filters.member_email = t.selectedMember
    if (t?.selectedGroupId) filters.group_id = t.selectedGroupId
    if (t?.selectedLabelId) filters.label_id = t.selectedLabelId
    const start = t?.startDate || new Date().toISOString().split('T')[0]
    const end = t?.endDate || start
    members.value = await hubStore.fetchWorkloadTimeline(start, end, null, filters)
  } finally {
    loading.value = false
  }
}

async function loadLive() {
  loading.value = true
  try {
    members.value = await hubStore.fetchWorkloadLive()
    await colleaguesStore.init()
    colleaguesStore.refreshPresence()
  } finally {
    loading.value = false
  }
}

function switchTeamSubView(tab) {
  teamSubView.value = tab
  clearInterval(liveInterval)
  liveInterval = null
  if (tab === 'timeline') loadTimeline()
  else if (tab === 'live') {
    loadLive()
    liveInterval = setInterval(loadLive, 30000)
  }
}

function initForMode(mode) {
  clearInterval(liveInterval)
  liveInterval = null
  if (mode === 'team') {
    switchTeamSubView(teamSubView.value)
  }
}

watch(activeMode, (mode) => initForMode(mode))

onMounted(async () => {
  await colleaguesStore.init()
  labels.value = await hubStore.fetchWorkloadLabels()
  initForMode(activeMode.value)
})

onUnmounted(() => clearInterval(liveInterval))
</script>

<template>
  <ProjectHubLayout title="Work Hub" icon="monitoring">
    <div class="max-w-full mx-auto p-6">
      <!-- Mode tabs (3 modes) -->
      <div class="flex items-center gap-1 p-1 bg-surface-100 dark:bg-surface-800 rounded-xl mb-5">
        <button
          v-for="mode in visibleModes"
          :key="mode.key"
          class="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all flex-1 justify-center"
          :class="activeMode === mode.key
            ? 'bg-white dark:bg-surface-700 text-primary-600 dark:text-primary-300 shadow-sm'
            : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200 hover:bg-surface-200/50 dark:hover:bg-surface-700/50'"
          @click="setMode(mode.key)"
        >
          <span class="material-symbols-rounded text-[18px]">{{ mode.icon }}</span>
          {{ mode.label }}
        </button>
      </div>

      <!-- My Work -->
      <MyWorkPanel v-if="activeMode === 'my-work'" @open-card="openCard" />

      <!-- Team (unified: timeline / live / schedule / capacity) -->
      <template v-else-if="activeMode === 'team'">
        <ProjectHubViewIntro
          storage-key="ph.intro.team.v1"
          icon="group"
          title="Team — resource visibility for whoever runs the studio"
          summary="Five views, one mental model: who's doing what, when, and how loaded they are. Use Timeline to plan ahead, Live to react now, Schedule to commit dates, Capacity to spot bottlenecks, Done Log to review output."
          :sections="teamIntroSections"
          :benefits="teamIntroBenefits"
        />
        <div class="flex items-center gap-1.5 mb-4">
          <button
            v-for="sv in TEAM_SUB_VIEWS" :key="sv.key"
            class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors flex items-center gap-1"
            :class="teamSubView === sv.key
              ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600'
              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
            @click="switchTeamSubView(sv.key)"
          >
            <span class="material-symbols-rounded text-sm">{{ sv.icon }}</span>
            {{ sv.label }}
          </button>
        </div>

        <WorkloadTimeline
          v-if="teamSubView === 'timeline'"
          ref="timelineRef"
          :members="members"
          :loading="loading"
          :labels="labels"
          @reload="loadTimeline"
        />

        <WorkloadLive
          v-else-if="teamSubView === 'live'"
          :members="members"
          :loading="loading"
        />

        <TeamScheduleView v-else-if="teamSubView === 'schedule'" @open-card="openCard" />

        <TrafficTableView v-else-if="teamSubView === 'capacity'" />

        <TeamCompletionsView v-else-if="teamSubView === 'done-log'" @open-card="openCard" />
      </template>

      <!-- Task Time -->
      <template v-else-if="activeMode === 'task-time'">
        <ProjectHubViewIntro
          storage-key="ph.intro.task-time.v1"
          icon="schedule"
          title="Task Time — where billable hours become invoices"
          summary="Filter by period, client, or board. Get the breakdown that maps 1:1 to what you charge. No spreadsheets, no end-of-month panic."
          :sections="taskTimeIntroSections"
          :benefits="taskTimeIntroBenefits"
        />
        <BreakdownTab
          :period="route.query.period || 'month'"
          :client-id="route.query.client_id || null"
          :board-id="route.query.board_id || null"
        />
      </template>
    </div>
  </ProjectHubLayout>
</template>
