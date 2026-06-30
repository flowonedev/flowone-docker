<script setup>
import { computed, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import OnboardingFlowNode from '../OnboardingFlowNode.vue'
import OnboardingNodeTooltip from '../OnboardingNodeTooltip.vue'
import { advancedModel as model } from '../model/advanced'
import { useDiagram } from '../model/useDiagram'

const { t, locale } = useI18n()
const props = defineProps({ currentStep: { type: Number, default: 0 } })
const stepRef = toRef(props, 'currentStep')
const isHu = computed(() => String(locale.value || '').toLowerCase().startsWith('hu'))
const tr = (en, hu) => (isHu.value ? hu : en)

const {
  container, cW, cH, isMobile,
  hoveredNode, tooltipStyle, onNodeEnter, onNodeLeave,
  nodeCenter, connPath, estimatePathLength,
  visibleConnections, isDrawingOn,
  isConnInHoverFocus, isNodeHoverDimmed,
  nodeState, nodeVisible, scenePosition,
} = useDiagram(model, stepRef)

const prefixedGradId = (conn) => `${model.svgPrefix}-grad-${conn.from}-${conn.to}`
</script>

<template>
  <div ref="container" :class="['relative w-full h-full', isMobile ? 'overflow-y-auto' : 'overflow-visible']">
    <svg v-if="!isMobile" class="absolute inset-0 w-full h-full pointer-events-none" :viewBox="`0 0 ${cW} ${cH}`" preserveAspectRatio="xMidYMid meet">
      <defs>
        <linearGradient v-for="conn in visibleConnections" :key="'grad-' + conn.from + '-' + conn.to"
          :id="prefixedGradId(conn)" gradientUnits="userSpaceOnUse"
          :x1="nodeCenter(conn.from).x" :y1="nodeCenter(conn.from).y"
          :x2="nodeCenter(conn.to).x" :y2="nodeCenter(conn.to).y">
          <stop offset="0%" :stop-color="model.branches[conn.branch]?.start || '#6366f1'" />
          <stop offset="100%" :stop-color="model.branches[conn.branch]?.end || '#8b5cf6'" />
        </linearGradient>
        <filter :id="`${model.svgPrefix}-glow`" x="-100%" y="-100%" width="300%" height="300%">
          <feGaussianBlur in="SourceGraphic" stdDeviation="6" />
        </filter>
      </defs>
      <g v-for="conn in visibleConnections" :key="'conn-' + conn.from + '-' + conn.to"
        :style="{ opacity: isConnInHoverFocus(conn) ? 1 : 0.08, transition: 'opacity 0.3s ease' }">
        <path v-if="conn.step === currentStep" :d="connPath(conn.from, conn.to)"
          :stroke="`url(#${prefixedGradId(conn)})`" stroke-width="8" fill="none"
          stroke-opacity="0.3" stroke-linecap="round" :filter="`url(#${model.svgPrefix}-glow)`" />
        <path :d="connPath(conn.from, conn.to)"
          :stroke="`url(#${prefixedGradId(conn)})`"
          :stroke-width="isConnInHoverFocus(conn) && hoveredNode ? 3 : 2"
          fill="none" :stroke-opacity="conn.step === currentStep ? 1 : 0.4"
          stroke-linecap="round"
          :class="isDrawingOn(conn) ? 'onb-draw-on' : ''"
          :style="isDrawingOn(conn) ? {
            strokeDasharray: estimatePathLength(conn.from, conn.to),
            strokeDashoffset: estimatePathLength(conn.from, conn.to),
            '--draw-dur': '0.8s',
          } : {}" />
        <template v-if="conn.step === currentStep">
          <circle v-for="dotIdx in 2" :key="'dot-' + conn.from + '-' + conn.to + '-' + dotIdx"
            r="3.5" :fill="model.branches[conn.branch]?.end || '#8b5cf6'" opacity="0.9">
            <animateMotion dur="2.5s" repeatCount="indefinite"
              :path="connPath(conn.from, conn.to)"
              :begin="'-' + ((dotIdx - 1) / 2 * 2.5).toFixed(2) + 's'" />
          </circle>
        </template>
      </g>
    </svg>

    <!-- Animated micro-scenes -->
    <Transition enter-active-class="transition-all duration-700 ease-out" enter-from-class="opacity-0 scale-90" leave-active-class="transition-all duration-300" leave-to-class="opacity-0 scale-90" mode="out-in">
      <div v-if="currentStep >= model.sceneRange.first && currentStep <= model.sceneRange.last"
        :key="'scene-' + currentStep"
        :class="isMobile ? 'flex items-center justify-center w-full px-4 py-6 min-h-full' : 'absolute z-20 pointer-events-none'"
        :style="isMobile ? {} : { left: scenePosition.left, top: scenePosition.top, transform: 'translate(0, -35%)', width: '280px' }">

        <!-- Step 2: CRM Client birth -->
        <div v-if="currentStep === 2" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">person</span> {{ tr('Auto-created', 'Automatikusan létrehozva') }}</div>
          <div class="text-center py-2">
            <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white text-xl font-bold mx-auto mb-2 animate-client-birth">DS</div>
            <div class="text-xs font-medium text-surface-700 dark:text-surface-200 animate-client-name">Pixel Ranger Studio</div>
            <div class="text-[10px] text-surface-400 animate-client-email">support@example.com</div>
          </div>
          <div class="grid grid-cols-3 gap-2 text-center mt-3">
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-stat-1"><div class="text-sm font-bold text-blue-500">12</div><div class="text-[9px] text-surface-400">{{ tr('Emails', 'E-mailek') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-stat-2"><div class="text-sm font-bold text-amber-500">4</div><div class="text-[9px] text-surface-400">{{ tr('Tasks', 'Feladatok') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-stat-3"><div class="text-sm font-bold text-green-500">8.5h</div><div class="text-[9px] text-surface-400">{{ tr('Time', 'Idő') }}</div></div>
          </div>
        </div>

        <!-- Step 3: Client Portal -->
        <div v-else-if="currentStep === 3" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">storefront</span> {{ tr('Client Portal', 'Ügyfélportál') }}</div>
          <div class="bg-white dark:bg-surface-700 rounded-lg p-3 mb-2 animate-portal-1">
            <div class="flex items-center justify-between mb-2"><span class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ tr('Project Progress', 'Projekt előrehaladás') }}</span><span class="text-[10px] text-green-500 font-bold">75%</span></div>
            <div class="h-1.5 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden"><div class="h-full bg-green-500 rounded-full" style="width: 75%"></div></div>
          </div>
          <div class="bg-white dark:bg-surface-700 rounded-lg p-3 animate-portal-2">
            <div class="text-[10px] font-medium text-surface-700 dark:text-surface-200 mb-1">{{ tr('Pending Approvals', 'Jóváhagyásra vár') }}</div>
            <div class="flex items-center gap-2"><span class="w-5 h-5 rounded bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center"><span class="material-symbols-rounded text-amber-500 text-xs">pending</span></span><span class="text-[10px] text-surface-500">{{ tr('Logo v3 - awaiting feedback', 'Logó v3 - visszajelzésre vár') }}</span></div>
          </div>
        </div>

        <!-- Step 4: Pipelines -->
        <div v-else-if="currentStep === 4" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">filter_alt</span> {{ tr('Pipeline', 'Pipeline') }}</div>
          <div class="flex gap-1 mb-3">
            <div v-for="(s, si) in [{name:tr('Lead','Lead'),active:false},{name:tr('Proposal','Ajánlat'),active:false},{name:tr('Negotiation','Tárgyalás'),active:true},{name:tr('Won','Nyert'),active:false}]" :key="si" :class="['flex-1 h-2 rounded-full transition-all', s.active ? 'bg-amber-400 animate-pipe-stage' : si < 2 ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"></div>
          </div>
          <div class="space-y-1.5">
            <div v-for="(deal, di) in [{name:tr('Branding Package','Branding csomag'),value:'450K Ft',stage:tr('Negotiation','Tárgyalás')},{name:tr('Website Redesign','Weboldal újratervezés'),value:'1.2M Ft',stage:tr('Proposal','Ajánlat')}]" :key="di" :class="['flex items-center gap-2 bg-white dark:bg-surface-700 rounded-lg p-2.5', 'animate-deal-' + (di+1)]">
              <span class="material-symbols-rounded text-amber-500 text-base">handshake</span>
              <div class="flex-1"><div class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ deal.name }}</div><div class="text-[9px] text-surface-400">{{ deal.stage }}</div></div>
              <span class="text-[10px] font-bold text-green-500">{{ deal.value }}</span>
            </div>
          </div>
        </div>

        <!-- Step 5: CRM Automations -->
        <div v-else-if="currentStep === 5" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">bolt</span> {{ tr('Automation', 'Automatizálás') }}</div>
          <div class="flex items-center gap-2 mb-3 animate-auto-trigger">
            <span class="w-6 h-6 rounded-lg bg-amber-500/20 flex items-center justify-center"><span class="material-symbols-rounded text-amber-500 text-sm">bolt</span></span>
            <span class="text-[10px] text-surface-500">{{ tr('When deal moves to "Won"', 'Ha az üzlet "Nyert" státuszba kerül') }}</span>
          </div>
          <div class="ml-3 border-l-2 border-primary-300 dark:border-primary-700 pl-3 space-y-2">
            <div class="text-[10px] text-surface-600 dark:text-surface-300 flex items-center gap-2 animate-auto-1"><span class="material-symbols-rounded text-sm text-green-500">check_circle</span> {{ tr('Create project board', 'Projekt tábla létrehozása') }}</div>
            <div class="text-[10px] text-surface-600 dark:text-surface-300 flex items-center gap-2 animate-auto-2"><span class="material-symbols-rounded text-sm text-green-500">check_circle</span> {{ tr('Send welcome email', 'Üdvözlő e-mail küldése') }}</div>
            <div class="text-[10px] text-surface-600 dark:text-surface-300 flex items-center gap-2 animate-auto-3"><span class="material-symbols-rounded text-sm text-blue-500">schedule</span> {{ tr('Start onboarding sequence', 'Onboarding szekvencia indítása') }}</div>
          </div>
        </div>

        <!-- Step 6: Board Automations -->
        <div v-else-if="currentStep === 6" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">smart_toy</span> {{ tr('Board Rules', 'Tábla szabályok') }}</div>
          <div class="space-y-2">
            <div v-for="(rule, ri) in [{trigger:tr('Card created','Kártya létrehozva'),action:tr('Assign to owner','Tulajdonoshoz rendelés'),icon:'person_add'},{trigger:tr('Moved to Done','Kész oszlopba került'),action:tr('Notify client','Ügyfél értesítése'),icon:'notifications'},{trigger:tr('Due date passed','Lejárt határidő'),action:tr('Move to Overdue','Áthelyezés lejártba'),icon:'warning'}]" :key="ri" :class="['bg-white dark:bg-surface-700 rounded-lg p-2.5 flex items-center gap-2', 'animate-rule-' + (ri+1)]">
              <span class="material-symbols-rounded text-primary-500 text-base">{{ rule.icon }}</span>
              <div class="flex-1"><div class="text-[10px] text-surface-500">{{ rule.trigger }}</div><div class="text-[10px] font-medium text-surface-700 dark:text-surface-200">&rarr; {{ rule.action }}</div></div>
            </div>
          </div>
        </div>

        <!-- Step 7: Email Sequences -->
        <div v-else-if="currentStep === 7" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">schedule_send</span> {{ tr('Sequence', 'Szekvencia') }}</div>
          <div class="space-y-1">
            <div v-for="(step, si) in [{label:tr('Welcome email','Üdvözlő e-mail'),delay:tr('Day 0','0. nap'),done:true},{label:tr('Follow-up','Utánkövetés'),delay:tr('Day 3','3. nap'),done:true},{label:tr('Check-in call','Egyeztető hívás'),delay:tr('Day 7','7. nap'),done:false},{label:tr('Final offer','Végső ajánlat'),delay:tr('Day 14','14. nap'),done:false}]" :key="si" :class="['flex items-center gap-2 py-1.5', 'animate-seq-' + (si+1)]">
              <span :class="['w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold', step.done ? 'bg-green-500 text-white' : 'bg-surface-300 dark:bg-surface-600 text-surface-500']">{{ step.done ? '\u2713' : si + 1 }}</span>
              <span :class="['text-[10px] flex-1', step.done ? 'text-surface-400 line-through' : 'text-surface-700 dark:text-surface-200']">{{ step.label }}</span>
              <span class="text-[9px] text-surface-400">{{ step.delay }}</span>
            </div>
          </div>
        </div>

        <!-- Step 8: Time Tracking -->
        <div v-else-if="currentStep === 8" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center justify-between mb-3"><span class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ tr('Wine Label Project', 'Borcímke projekt') }}</span><span class="material-symbols-rounded text-red-500 text-xl animate-timer-blink">stop_circle</span></div>
          <div class="text-center mb-3">
            <div class="text-2xl font-mono font-bold text-surface-800 dark:text-surface-100 animate-timer-count">02:34:17</div>
            <div class="text-[10px] text-surface-400 mt-1">{{ tr('Running - Design task', 'Fut - Design feladat') }}</div>
          </div>
          <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden"><div class="h-full bg-gradient-to-r from-primary-500 to-violet-500 rounded-full animate-timer-bar" style="width: 65%"></div></div>
        </div>

        <!-- Step 9: Client Report -->
        <div v-else-if="currentStep === 9" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center justify-between mb-3"><span class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ tr('Monthly Report', 'Havi riport') }}</span><span class="material-symbols-rounded text-green-500 text-base">check_circle</span></div>
          <div class="grid grid-cols-3 gap-2 text-center mb-3">
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-report-1"><div class="text-sm font-bold text-blue-500">8</div><div class="text-[9px] text-surface-400">{{ tr('Tasks Done', 'Kész feladat') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-report-2"><div class="text-sm font-bold text-amber-500">24h</div><div class="text-[9px] text-surface-400">{{ tr('Tracked', 'Követve') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-report-3"><div class="text-sm font-bold text-green-500">92%</div><div class="text-[9px] text-surface-400">{{ tr('On Time', 'Határidőre') }}</div></div>
          </div>
          <div class="flex items-center gap-2 animate-report-share"><span class="material-symbols-rounded text-primary-500 text-sm">share</span><span class="text-[10px] text-surface-500">{{ tr('Shared via client portal', 'Megosztva ügyfélportálon') }}</span></div>
        </div>

        <!-- Step 10: Invoice -->
        <div v-else-if="currentStep === 10" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center justify-between mb-3"><span class="text-[10px] font-medium text-surface-700 dark:text-surface-200">INV-2024-015</span><span class="text-[10px] bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400 px-2 py-0.5 rounded-full font-medium animate-inv-badge">{{ tr('Paid', 'Fizetve') }}</span></div>
          <div class="space-y-1.5">
            <div class="flex justify-between text-[10px] animate-inv-1"><span class="text-surface-400">Design (8.5h)</span><span class="text-surface-600 dark:text-surface-300">85,000 Ft</span></div>
            <div class="flex justify-between text-[10px] animate-inv-2"><span class="text-surface-400">Revisions (2h)</span><span class="text-surface-600 dark:text-surface-300">20,000 Ft</span></div>
            <div class="border-t border-surface-200 dark:border-surface-600 pt-1.5 flex justify-between text-[10px] font-semibold animate-inv-3"><span class="text-surface-500">{{ tr('Total', 'Összesen') }}</span><span class="text-surface-800 dark:text-surface-100">105,000 Ft</span></div>
          </div>
          <div class="flex items-center gap-2 mt-2 animate-inv-provider"><span class="text-[9px] bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 px-2 py-0.5 rounded-full">Billingo</span><span class="text-[9px] bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 px-2 py-0.5 rounded-full">Szamlazz.hu</span></div>
        </div>

        <!-- Step 11: Campaigns -->
        <div v-else-if="currentStep === 11" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">campaign</span> {{ tr('Campaign', 'Kampány') }}</div>
          <div class="text-[10px] font-medium text-surface-700 dark:text-surface-200 mb-3">{{ tr('Spring Newsletter', 'Tavaszi hírlevél') }}</div>
          <div class="grid grid-cols-3 gap-2 text-center mb-3">
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-camp-1"><div class="text-sm font-bold text-blue-500">89%</div><div class="text-[9px] text-surface-400">{{ tr('Delivered', 'Kézbesítve') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-camp-2"><div class="text-sm font-bold text-green-500">34%</div><div class="text-[9px] text-surface-400">{{ tr('Opened', 'Megnyitva') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 animate-camp-3"><div class="text-sm font-bold text-amber-500">12%</div><div class="text-[9px] text-surface-400">{{ tr('Clicked', 'Kattintva') }}</div></div>
          </div>
          <div class="flex gap-2 flex-wrap"><span v-for="tag in ['VIP', 'Design', 'Active']" :key="tag" class="text-[9px] bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 px-2 py-0.5 rounded-full">{{ tag }}</span></div>
        </div>

        <!-- Step 12: Dashboard -->
        <div v-else-if="currentStep === 12" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">analytics</span> {{ tr('Dashboard', 'Műszerfal') }}</div>
          <div class="grid grid-cols-2 gap-2 mb-3">
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 text-center animate-dash-1"><div class="text-sm font-bold text-green-500">2.4M Ft</div><div class="text-[9px] text-surface-400">{{ tr('Revenue', 'Bevétel') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 text-center animate-dash-2"><div class="text-sm font-bold text-blue-500">850K Ft</div><div class="text-[9px] text-surface-400">{{ tr('Pipeline', 'Pipeline') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 text-center animate-dash-3"><div class="text-sm font-bold text-amber-500">87%</div><div class="text-[9px] text-surface-400">{{ tr('Utilization', 'Kihasználtság') }}</div></div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2 text-center animate-dash-4"><div class="text-sm font-bold text-violet-500">12</div><div class="text-[9px] text-surface-400">{{ tr('Active Projects', 'Aktív projektek') }}</div></div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Node layer (desktop only) -->
    <template v-if="!isMobile">
      <template v-for="nodeDef in model.nodes" :key="nodeDef.id">
        <Transition enter-active-class="transition-all duration-500 ease-out" enter-from-class="opacity-0 scale-90">
          <div v-if="nodeVisible(nodeDef)" class="absolute -translate-x-1/2 -translate-y-1/2 z-10 transition-all duration-300"
            :style="{ left: (model.positions[nodeDef.id].x * 100) + '%', top: (model.positions[nodeDef.id].y * 100) + '%',
              opacity: isNodeHoverDimmed(nodeDef.id) ? 0.12 : 1, filter: isNodeHoverDimmed(nodeDef.id) ? 'blur(2px)' : 'none',
              transform: `translate(-50%, -50%) ${hoveredNode === nodeDef.id ? 'scale(1.08)' : 'scale(1)'}` }"
            @mouseenter="onNodeEnter(nodeDef.id)" @mouseleave="onNodeLeave">
            <OnboardingFlowNode :icon="nodeDef.icon" :label="t(nodeDef.labelKey)" :active="nodeState(nodeDef) === 'active'" :visited="nodeState(nodeDef) === 'visited'" :dimmed="nodeState(nodeDef) === 'dimmed'" />
          </div>
        </Transition>
      </template>
    </template>

    <Teleport to="body">
      <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
        <OnboardingNodeTooltip v-if="hoveredNode && !isMobile" :nodeId="hoveredNode" :style="tooltipStyle" :tooltipGroup="model.tooltipGroup" @mouseenter="onNodeEnter(hoveredNode)" @mouseleave="onNodeLeave" />
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.onb-draw-on { animation: onbDrawOn var(--draw-dur, 0.8s) ease-out forwards; }
@keyframes onbDrawOn { to { stroke-dashoffset: 0; } }

.animate-client-birth { animation: clientBirth 0.6s ease-out 0.3s both; }
@keyframes clientBirth { from { opacity: 0; transform: scale(0.3) rotate(-20deg); } to { opacity: 1; transform: scale(1) rotate(0); } }
.animate-client-name { animation: fadeIn 0.3s ease-out 0.6s both; }
.animate-client-email { animation: fadeIn 0.3s ease-out 0.8s both; }
.animate-stat-1 { animation: fadeIn 0.3s ease-out 1.0s both; }
.animate-stat-2 { animation: fadeIn 0.3s ease-out 1.2s both; }
.animate-stat-3 { animation: fadeIn 0.3s ease-out 1.4s both; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.animate-portal-1 { animation: slideIn 0.4s ease-out 0.3s both; }
.animate-portal-2 { animation: slideIn 0.4s ease-out 0.7s both; }
@keyframes slideIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

.animate-pipe-stage { animation: pipeGlow 2s ease-in-out 1 forwards; }
@keyframes pipeGlow { 0% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.4); } 50% { box-shadow: 0 0 8px 2px rgba(251, 191, 36, 0.2); } 100% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0); } }
.animate-deal-1 { animation: slideIn 0.3s ease-out 0.3s both; }
.animate-deal-2 { animation: slideIn 0.3s ease-out 0.6s both; }

.animate-auto-trigger { animation: fadeIn 0.3s ease-out 0.2s both; }
.animate-auto-1 { animation: slideIn 0.3s ease-out 0.5s both; }
.animate-auto-2 { animation: slideIn 0.3s ease-out 0.8s both; }
.animate-auto-3 { animation: slideIn 0.3s ease-out 1.1s both; }

.animate-rule-1 { animation: slideIn 0.3s ease-out 0.2s both; }
.animate-rule-2 { animation: slideIn 0.3s ease-out 0.5s both; }
.animate-rule-3 { animation: slideIn 0.3s ease-out 0.8s both; }

.animate-seq-1 { animation: fadeIn 0.3s ease-out 0.2s both; }
.animate-seq-2 { animation: fadeIn 0.3s ease-out 0.5s both; }
.animate-seq-3 { animation: fadeIn 0.3s ease-out 0.8s both; }
.animate-seq-4 { animation: fadeIn 0.3s ease-out 1.1s both; }

.animate-timer-blink { animation: timerBlink 1s ease-in-out 3; }
@keyframes timerBlink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
.animate-timer-count { animation: fadeIn 0.5s ease-out 0.3s both; }
.animate-timer-bar { animation: barGrow 1s ease-out 0.5s both; }
@keyframes barGrow { from { width: 0; } to { width: 65%; } }

.animate-report-1 { animation: fadeIn 0.3s ease-out 0.2s both; }
.animate-report-2 { animation: fadeIn 0.3s ease-out 0.4s both; }
.animate-report-3 { animation: fadeIn 0.3s ease-out 0.6s both; }
.animate-report-share { animation: slideIn 0.3s ease-out 1.0s both; }

.animate-inv-badge { animation: badgePop 0.3s ease-out 0.3s both; }
@keyframes badgePop { from { opacity: 0; transform: scale(0.5); } to { opacity: 1; transform: scale(1); } }
.animate-inv-1 { animation: fadeIn 0.3s ease-out 0.4s both; }
.animate-inv-2 { animation: fadeIn 0.3s ease-out 0.6s both; }
.animate-inv-3 { animation: fadeIn 0.3s ease-out 0.8s both; }
.animate-inv-provider { animation: fadeIn 0.3s ease-out 1.0s both; }

.animate-camp-1 { animation: fadeIn 0.3s ease-out 0.2s both; }
.animate-camp-2 { animation: fadeIn 0.3s ease-out 0.4s both; }
.animate-camp-3 { animation: fadeIn 0.3s ease-out 0.6s both; }

.animate-dash-1 { animation: slideIn 0.3s ease-out 0.2s both; }
.animate-dash-2 { animation: slideIn 0.3s ease-out 0.4s both; }
.animate-dash-3 { animation: slideIn 0.3s ease-out 0.6s both; }
.animate-dash-4 { animation: slideIn 0.3s ease-out 0.8s both; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
</style>
