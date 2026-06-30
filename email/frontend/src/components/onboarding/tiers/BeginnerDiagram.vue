<script setup>
import { computed, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import OnboardingFlowNode from '../OnboardingFlowNode.vue'
import OnboardingNodeTooltip from '../OnboardingNodeTooltip.vue'
import { beginnerModel as model } from '../model/beginner'
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
    <!-- SVG connections (desktop only) -->
    <svg v-if="!isMobile" class="absolute inset-0 w-full h-full pointer-events-none" :viewBox="`0 0 ${cW} ${cH}`" preserveAspectRatio="xMidYMid meet">
      <defs>
        <linearGradient
          v-for="conn in visibleConnections" :key="'grad-' + conn.from + '-' + conn.to"
          :id="prefixedGradId(conn)" gradientUnits="userSpaceOnUse"
          :x1="nodeCenter(conn.from).x" :y1="nodeCenter(conn.from).y"
          :x2="nodeCenter(conn.to).x" :y2="nodeCenter(conn.to).y"
        >
          <stop offset="0%" :stop-color="model.branches[conn.branch]?.start || '#3b82f6'" />
          <stop offset="100%" :stop-color="model.branches[conn.branch]?.end || '#06b6d4'" />
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
            r="3.5" :fill="model.branches[conn.branch]?.end || '#06b6d4'" opacity="0.9">
            <animateMotion :dur="'2.5s'" repeatCount="indefinite"
              :path="connPath(conn.from, conn.to)"
              :begin="'-' + ((dotIdx - 1) / 2 * 2.5).toFixed(2) + 's'" />
          </circle>
        </template>
      </g>
    </svg>

    <!-- Animated micro-scene per step -->
    <Transition enter-active-class="transition-all duration-700 ease-out" enter-from-class="opacity-0 scale-90" leave-active-class="transition-all duration-300" leave-to-class="opacity-0 scale-90" mode="out-in">
      <div v-if="currentStep >= model.sceneRange.first && currentStep <= model.sceneRange.last"
        :key="'scene-' + currentStep"
        :class="isMobile ? 'flex items-center justify-center w-full px-4 py-6 min-h-full' : 'absolute z-20 pointer-events-none'"
        :style="isMobile ? {} : { left: scenePosition.left, top: scenePosition.top, transform: 'translate(0, -35%)', width: '320px' }">

        <!-- Step 2: Email arrival -->
        <div v-if="currentStep === 2" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">inbox</span> {{ tr('Inbox', 'Bejövő') }}</div>
          <div class="space-y-2">
            <div class="bg-white dark:bg-surface-700 rounded-lg p-3 flex items-center gap-3 animate-slide-in-1">
              <div class="w-7 h-7 rounded-full bg-blue-500 flex items-center justify-center text-white text-[10px] font-bold shrink-0">J</div>
              <div class="flex-1 min-w-0"><div class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">{{ tr('Project Update', 'Projektfrissítés') }}</div><div class="text-[10px] text-surface-400 truncate">{{ tr("Hey, here's the latest...", 'Szia, itt a legfrissebb...') }}</div></div>
              <span class="text-[10px] text-surface-400">{{ tr('now', 'most') }}</span>
            </div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-3 flex items-center gap-3 animate-slide-in-2">
              <div class="w-7 h-7 rounded-full bg-violet-500 flex items-center justify-center text-white text-[10px] font-bold shrink-0">M</div>
              <div class="flex-1 min-w-0"><div class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">{{ tr('Meeting Notes', 'Értekezlet jegyzetek') }}</div><div class="text-[10px] text-surface-400 truncate">{{ tr('Attached the agenda...', 'Csatoltam a napirendet...') }}</div></div>
              <span class="text-[10px] text-surface-400">2m</span>
            </div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-3 flex items-center gap-3 animate-slide-in-3">
              <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center text-white text-[10px] font-bold shrink-0">K</div>
              <div class="flex-1 min-w-0"><div class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">{{ tr('Invoice Ready', 'Számla elkészült') }}</div><div class="text-[10px] text-surface-400 truncate">{{ tr('Your invoice is ready...', 'A számlája elkészült...') }}</div></div>
              <span class="text-[10px] text-surface-400">5m</span>
            </div>
          </div>
        </div>

        <!-- Step 3: Compose -->
        <div v-else-if="currentStep === 3" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">edit_note</span> {{ tr('Compose', 'Írás') }}</div>
          <div class="bg-white dark:bg-surface-700 rounded-lg p-3 space-y-2">
            <div class="flex items-center gap-2"><span class="text-[10px] text-surface-400 w-8">{{ tr('To:', 'Címzett:') }}</span><span class="text-xs text-surface-700 dark:text-surface-200">petra.minta@example.com</span></div>
            <div class="flex items-center gap-2"><span class="text-[10px] text-surface-400 w-8">{{ tr('Subj:', 'Tárgy:') }}</span><span class="text-xs text-surface-700 dark:text-surface-200">{{ tr('Re: Project Update', 'Válasz: Projektfrissítés') }}</span></div>
            <div class="border-t border-surface-200 dark:border-surface-600 pt-2">
              <div class="text-xs text-surface-600 dark:text-surface-300 animate-typewriter">{{ tr("Thanks for the update! I'll review the docs and get back to you by tomorrow.", "Köszönöm a frissítést! Átnézem a dokumentumokat, és holnap visszajelzek.") }}</div>
            </div>
          </div>
          <div class="flex justify-end mt-3">
            <div class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-primary-600 text-white text-xs rounded-full animate-send-pulse">
              <span class="material-symbols-rounded text-sm">send</span> {{ tr('Send', 'Küldés') }}
            </div>
          </div>
        </div>

        <!-- Step 4: Conversation thread -->
        <div v-else-if="currentStep === 4" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-3 sm:p-4 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 px-2 py-2 bg-white dark:bg-surface-700 rounded-lg mb-1 animate-thread-1">
            <span class="material-symbols-rounded text-surface-400 text-xs">star</span>
            <span class="text-[10px] font-semibold text-surface-700 dark:text-surface-200 truncate flex-1">Minta Petra</span>
            <span class="text-[10px] text-surface-500 truncate max-w-[100px]">Re: Honlap - Sziasztok, A SEO...</span>
            <span class="text-[8px] bg-green-500 text-white px-1.5 py-0.5 rounded font-semibold">{{ tr('Done', 'Kész') }}</span>
            <span class="text-[8px] bg-primary-500 text-white w-4 h-4 rounded-full flex items-center justify-center font-bold">7</span>
            <span class="material-symbols-rounded text-surface-400" style="font-size: 10px">attach_file</span>
            <span class="text-[9px] text-surface-400">Feb 12</span>
          </div>
          <div class="ml-4 border-l-2 border-surface-200 dark:border-surface-600 pl-2 space-y-0.5">
            <div v-for="(msg, mi) in [
              { sender: 'Minta Petra', date: tr('Feb 12', 'febr. 12.'), label: tr('Done', 'Kész'), bold: true },
              { sender: 'Minta Petra', date: tr('Feb 12', 'febr. 12.') },
              { sender: 'Teszt Daniel', date: tr('Feb 12', 'febr. 12.') },
              { sender: 'Pelda Nora', date: tr('Feb 11', 'febr. 11.') },
              { sender: 'Pelda Nora', date: tr('Feb 11', 'febr. 11.'), unread: true },
            ]" :key="mi" :class="['flex items-center gap-2 px-2 py-1.5 rounded-md', msg.unread ? 'bg-primary-50/50 dark:bg-primary-500/5' : 'hover:bg-white/50 dark:hover:bg-surface-700/50', 'animate-thread-' + (mi + 2)]">
              <span class="material-symbols-rounded text-surface-400" style="font-size: 11px">subdirectory_arrow_right</span>
              <span v-if="msg.unread" class="w-1.5 h-1.5 rounded-full bg-primary-500 shrink-0"></span>
              <span :class="['text-[10px] flex-1 truncate', msg.bold ? 'font-semibold text-surface-700 dark:text-surface-200' : 'text-surface-500 dark:text-surface-400']">{{ msg.sender }}</span>
              <span v-if="msg.label" class="text-[8px] bg-green-500 text-white px-1.5 py-0.5 rounded font-semibold">{{ msg.label }}</span>
              <span class="text-[9px] text-surface-400 shrink-0">{{ msg.date }}</span>
            </div>
          </div>
        </div>

        <!-- Step 5: Labels & Filters -->
        <div v-else-if="currentStep === 5" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">label</span> {{ tr('Organize', 'Rendezés') }}</div>
          <div class="space-y-2">
            <div class="bg-white dark:bg-surface-700 rounded-lg p-3 flex items-center gap-3 animate-label-1">
              <span class="text-xs text-surface-600 dark:text-surface-300 flex-1">{{ tr('Project Update', 'Projektfrissítés') }}</span>
              <span class="text-[10px] bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 px-2 py-0.5 rounded-full animate-label-appear-1">{{ tr('Work', 'Munka') }}</span>
              <span class="text-[10px] bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400 px-2 py-0.5 rounded-full animate-label-appear-2">{{ tr('Important', 'Fontos') }}</span>
            </div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-3 animate-label-2">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-rounded text-amber-500 text-sm">filter_alt</span>
                <span class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ tr('Auto-filter rule', 'Automatikus szűrőszabály') }}</span>
              </div>
              <div class="text-[10px] text-surface-400 ml-6">{{ tr('From', 'Feladó') }} <span class="text-surface-600 dark:text-surface-300">@example.com</span> &rarr; {{ tr('Apply label', 'Címke') }} <span class="bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 px-1.5 py-0.5 rounded">{{ tr('Work', 'Munka') }}</span></div>
            </div>
          </div>
        </div>

        <!-- Step 6: Multi-account -->
        <div v-else-if="currentStep === 6" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">switch_account</span> {{ tr('Accounts', 'Fiókok') }}</div>
          <div class="space-y-2">
            <div class="bg-white dark:bg-surface-700 rounded-lg p-3 flex items-center gap-3 border-2 border-primary-500 animate-acct-1">
              <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white text-xs font-bold shrink-0">T</div>
              <div class="flex-1 min-w-0"><div class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">teszt.elek@example.com</div><div class="text-[10px] text-primary-500 font-medium">{{ tr('Active', 'Aktív') }}</div></div>
            </div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-3 flex items-center gap-3 animate-acct-2">
              <div class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center text-white text-xs font-bold shrink-0">G</div>
              <div class="flex-1 min-w-0"><div class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">teszt.elek.secondary@example.com</div><div class="text-[10px] text-surface-400">Gmail IMAP</div></div>
            </div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-3 flex items-center gap-3 animate-acct-3">
              <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-bold shrink-0">O</div>
              <div class="flex-1 min-w-0"><div class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">teszt.elek.outlook@example.com</div><div class="text-[10px] text-surface-400">Outlook</div></div>
            </div>
          </div>
        </div>

        <!-- Step 7: Contacts -->
        <div v-else-if="currentStep === 7" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">contacts</span> {{ tr('Contacts', 'Kontaktok') }}</div>
          <div class="space-y-2">
            <div v-for="(c, i) in [{name:'Minta Petra',email:'petra.minta@example.com',color:'bg-blue-500'},{name:'Teszt Daniel',email:'daniel.teszt@example.com',color:'bg-violet-500'},{name:'Pelda Nora',email:'nora.pelda@example.com',color:'bg-green-500'}]"
              :key="i" class="bg-white dark:bg-surface-700 rounded-lg p-3 flex items-center gap-3" :class="'animate-contact-' + (i+1)">
              <div :class="['w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0', c.color]">{{ c.name[0] }}</div>
              <div class="flex-1"><div class="text-xs font-medium text-surface-700 dark:text-surface-200">{{ c.name }}</div><div class="text-[10px] text-surface-400">{{ c.email }}</div></div>
              <span class="text-[10px] bg-surface-100 dark:bg-surface-600 text-surface-400 px-2 py-0.5 rounded-full">{{ tr('Auto-saved', 'Automatikusan mentve') }}</span>
            </div>
          </div>
        </div>

        <!-- Step 8: Tasks from email -->
        <div v-else-if="currentStep === 8" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">task_alt</span> {{ tr('Quick Tasks', 'Gyors feladatok') }}</div>
          <div class="bg-white dark:bg-surface-700 rounded-lg p-3 mb-3 relative">
            <div class="text-[10px] text-surface-500 mb-1">{{ tr('From email:', 'E-mailből:') }}</div>
            <div class="text-xs text-surface-700 dark:text-surface-200">"...{{ tr('please', 'kérlek') }} <span class="bg-blue-200/70 dark:bg-blue-500/30 px-0.5 rounded animate-highlight selection-highlight">{{ tr('review the proposal by Friday', 'nézd át az ajánlatot péntekig') }}</span> {{ tr('and send feedback', 'és küldj visszajelzést') }}..."</div>
            <div class="absolute -top-3 left-1/2 -translate-x-1/2 flex items-center gap-1 bg-surface-800 dark:bg-surface-700 text-white rounded-lg px-2 py-1 shadow-lg animate-toolbar-pop">
              <span class="material-symbols-rounded text-amber-400" style="font-size: 12px">add_task</span>
              <span class="text-[9px] font-medium whitespace-nowrap">{{ tr('Add to Tasks', 'Feladathoz ad') }}</span>
            </div>
          </div>
          <div class="flex items-center gap-2 animate-task-create">
            <span class="material-symbols-rounded text-amber-500 text-base">arrow_downward</span>
            <span class="text-[10px] text-surface-400">{{ tr('becomes a task', 'feladattá válik') }}</span>
          </div>
          <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-lg p-3 mt-2 animate-task-appear">
            <div class="flex items-center gap-2">
              <span class="w-4 h-4 rounded border-2 border-amber-400"></span>
              <span class="text-xs font-medium text-surface-700 dark:text-surface-200">{{ tr('Review the proposal', 'Ajánlat áttekintése') }}</span>
            </div>
            <div class="text-[10px] text-surface-400 ml-6 mt-1">{{ tr('Due: Friday', 'Határidő: péntek') }} &middot; {{ tr('From:', 'Feladó:') }} petra.minta@example.com</div>
          </div>
        </div>

        <!-- Step 9: Super Search -->
        <div v-else-if="currentStep === 9" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="bg-white dark:bg-surface-700 rounded-xl p-3 flex items-center gap-2 mb-3 border border-surface-200 dark:border-surface-600">
            <span class="material-symbols-rounded text-surface-400 text-lg">search</span>
            <span class="text-xs text-surface-600 dark:text-surface-300 animate-typewriter-fast">{{ tr('project proposal', 'projekt ajánlat') }}</span>
          </div>
          <div class="space-y-1.5">
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2.5 flex items-center gap-2 animate-search-1">
              <span class="material-symbols-rounded text-blue-500 text-sm">mail</span>
              <div class="flex-1"><div class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ tr('Re: Project Proposal', 'Válasz: Projekt ajánlat') }}</div><div class="text-[9px] text-surface-400">petra.minta@example.com</div></div>
            </div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2.5 flex items-center gap-2 animate-search-2">
              <span class="material-symbols-rounded text-amber-500 text-sm">description</span>
              <div class="flex-1"><div class="text-[10px] font-medium text-surface-700 dark:text-surface-200">Proposal_v3.pdf</div><div class="text-[9px] text-surface-400">{{ tr('Drive / Projects', 'Drive / Projektek') }}</div></div>
            </div>
            <div class="bg-white dark:bg-surface-700 rounded-lg p-2.5 flex items-center gap-2 animate-search-3">
              <span class="material-symbols-rounded text-green-500 text-sm">task_alt</span>
              <div class="flex-1"><div class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ tr('Review the proposal', 'Ajánlat áttekintése') }}</div><div class="text-[9px] text-surface-400">{{ tr('Task · Due Friday', 'Feladat · Határidő: péntek') }}</div></div>
            </div>
          </div>
        </div>

        <!-- Step 10: Calendar -->
        <div v-else-if="currentStep === 10" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">calendar_month</span> {{ tr('Calendar', 'Naptár') }}</div>
          <div class="grid grid-cols-7 gap-0.5 mb-3">
            <span v-for="d in ['M','T','W','T','F','S','S']" :key="d" class="text-[8px] text-surface-400 text-center font-medium">{{ d }}</span>
            <span v-for="n in 28" :key="n" :class="['text-[9px] text-center py-1 rounded', n === 14 ? 'bg-primary-500 text-white font-bold' : n === 18 ? 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 font-medium' : 'text-surface-500']">{{ n }}</span>
          </div>
          <div class="space-y-1.5 animate-cal-events">
            <div class="flex items-center gap-2 p-2 bg-primary-50 dark:bg-primary-500/10 rounded-lg border-l-3 border-primary-500">
              <span class="text-[10px] text-primary-600 dark:text-primary-400 font-medium">10:00</span>
              <span class="text-[10px] text-surface-700 dark:text-surface-200">{{ tr('Team standup', 'Csapat standup') }}</span>
            </div>
            <div class="flex items-center gap-2 p-2 bg-amber-50 dark:bg-amber-500/10 rounded-lg border-l-3 border-amber-500">
              <span class="text-[10px] text-amber-600 dark:text-amber-400 font-medium">14:00</span>
              <span class="text-[10px] text-surface-700 dark:text-surface-200">{{ tr('Client review', 'Ügyfél review') }}</span>
            </div>
          </div>
        </div>

        <!-- Step 11: Security (2FA) -->
        <div v-else-if="currentStep === 11" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">shield</span> {{ tr('Security', 'Biztonság') }}</div>
          <div class="text-center py-3">
            <div class="w-14 h-14 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center mx-auto mb-3 animate-shield-pulse">
              <span class="material-symbols-rounded text-green-500 text-2xl">verified_user</span>
            </div>
            <div class="text-xs font-medium text-surface-700 dark:text-surface-200 mb-1">{{ tr('Two-Factor Authentication', 'Kétfaktoros azonosítás') }}</div>
            <div class="text-[10px] text-surface-400 mb-3">{{ tr('TOTP app + backup codes', 'TOTP app + tartalék kódok') }}</div>
            <div class="flex items-center justify-center gap-2">
              <span v-for="i in 6" :key="i" :class="['w-6 h-8 rounded bg-surface-200 dark:bg-surface-700 flex items-center justify-center text-sm font-mono font-bold text-surface-700 dark:text-surface-200', 'animate-code-' + i]">{{ [4,7,2,9,1,5][i-1] }}</span>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Node layer (desktop only) -->
    <template v-if="!isMobile">
      <template v-for="nodeDef in model.nodes" :key="nodeDef.id">
        <Transition enter-active-class="transition-all duration-500 ease-out" enter-from-class="opacity-0 scale-90" leave-active-class="transition-all duration-300 ease-in" leave-to-class="opacity-0 scale-90">
          <div
            v-if="nodeVisible(nodeDef)"
            class="absolute -translate-x-1/2 -translate-y-1/2 z-10 transition-all duration-300"
            :style="{
              left: (model.positions[nodeDef.id].x * 100) + '%',
              top: (model.positions[nodeDef.id].y * 100) + '%',
              opacity: isNodeHoverDimmed(nodeDef.id) ? 0.12 : 1,
              filter: isNodeHoverDimmed(nodeDef.id) ? 'blur(2px)' : 'none',
              transform: `translate(-50%, -50%) ${hoveredNode === nodeDef.id ? 'scale(1.08)' : 'scale(1)'}`,
            }"
            @mouseenter="onNodeEnter(nodeDef.id)"
            @mouseleave="onNodeLeave"
          >
            <OnboardingFlowNode
              :icon="nodeDef.icon"
              :label="t(nodeDef.labelKey)"
              :active="nodeState(nodeDef) === 'active'"
              :visited="nodeState(nodeDef) === 'visited'"
              :dimmed="nodeState(nodeDef) === 'dimmed'"
            />
          </div>
        </Transition>
      </template>
    </template>

    <!-- Tooltip (desktop only) -->
    <Teleport to="body">
      <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
        <OnboardingNodeTooltip
          v-if="hoveredNode && !isMobile"
          :nodeId="hoveredNode"
          :style="tooltipStyle"
          :tooltipGroup="model.tooltipGroup"
          @mouseenter="onNodeEnter(hoveredNode)"
          @mouseleave="onNodeLeave"
        />
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.onb-draw-on { animation: onbDrawOn var(--draw-dur, 0.8s) ease-out forwards; }
@keyframes onbDrawOn { to { stroke-dashoffset: 0; } }

.animate-slide-in-1 { animation: slideInRight 0.5s ease-out 0.2s both; }
.animate-slide-in-2 { animation: slideInRight 0.5s ease-out 0.6s both; }
.animate-slide-in-3 { animation: slideInRight 0.5s ease-out 1.0s both; }
@keyframes slideInRight { from { opacity: 0; transform: translateX(-30px); } to { opacity: 1; transform: translateX(0); } }

.animate-typewriter { animation: fadeIn 0.4s ease-out 0.2s both; white-space: normal; border-right: none; width: auto; overflow: visible; }
.animate-typewriter-fast { overflow: hidden; white-space: nowrap; border-right: 2px solid currentColor; animation: typewriter 1s steps(20) 0.3s both, blink 0.7s step-end infinite; width: 0; }
@keyframes typewriter { from { width: 0; } to { width: 100%; } }
@keyframes blink { 50% { border-color: transparent; } }

.animate-send-pulse { animation: sendPulse 2s ease-in-out 2.5s both; }
@keyframes sendPulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); box-shadow: 0 0 20px rgba(var(--color-primary-500), 0.4); } 100% { transform: scale(1); } }

.animate-thread-1 { animation: threadIn 0.4s ease-out 0.2s both; }
.animate-thread-2 { animation: threadIn 0.3s ease-out 0.5s both; }
.animate-thread-3 { animation: threadIn 0.3s ease-out 0.7s both; }
.animate-thread-4 { animation: threadIn 0.3s ease-out 0.9s both; }
.animate-thread-5 { animation: threadIn 0.3s ease-out 1.1s both; }
.animate-thread-6 { animation: threadIn 0.3s ease-out 1.3s both; }
@keyframes threadIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }

.animate-label-1 { animation: fadeIn 0.4s ease-out 0.3s both; }
.animate-label-2 { animation: fadeIn 0.4s ease-out 0.8s both; }
.animate-label-appear-1 { animation: labelPop 0.3s ease-out 0.8s both; }
.animate-label-appear-2 { animation: labelPop 0.3s ease-out 1.2s both; }
@keyframes labelPop { from { opacity: 0; transform: scale(0.5); } to { opacity: 1; transform: scale(1); } }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.animate-acct-1 { animation: fadeIn 0.3s ease-out 0.2s both; }
.animate-acct-2 { animation: fadeIn 0.3s ease-out 0.5s both; }
.animate-acct-3 { animation: fadeIn 0.3s ease-out 0.8s both; }

.animate-contact-1 { animation: slideInRight 0.4s ease-out 0.2s both; }
.animate-contact-2 { animation: slideInRight 0.4s ease-out 0.5s both; }
.animate-contact-3 { animation: slideInRight 0.4s ease-out 0.8s both; }

.animate-highlight { animation: highlightSweep 0.8s ease-out 0.5s both; }
@keyframes highlightSweep { from { background-size: 0% 100%; } to { background-size: 100% 100%; } }
.selection-highlight { box-shadow: 0 1px 0 0 rgba(59, 130, 246, 0.5); }
.animate-toolbar-pop { animation: toolbarPop 0.3s ease-out 0.8s both; }
@keyframes toolbarPop { from { opacity: 0; transform: translate(-50%, 4px) scale(0.9); } to { opacity: 1; transform: translate(-50%, 0) scale(1); } }
.animate-task-create { animation: fadeIn 0.3s ease-out 1.2s both; }
.animate-task-appear { animation: slideInRight 0.5s ease-out 1.5s both; }

.animate-search-1 { animation: fadeIn 0.3s ease-out 1.2s both; }
.animate-search-2 { animation: fadeIn 0.3s ease-out 1.5s both; }
.animate-search-3 { animation: fadeIn 0.3s ease-out 1.8s both; }

.animate-cal-events { animation: fadeIn 0.5s ease-out 0.5s both; }

.animate-shield-pulse { animation: shieldPulse 2s ease-in-out 1 forwards; }
@keyframes shieldPulse { 0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.3); } 50% { box-shadow: 0 0 0 12px rgba(34, 197, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }

.animate-code-1 { animation: codeIn 0.15s ease-out 0.8s both; }
.animate-code-2 { animation: codeIn 0.15s ease-out 0.95s both; }
.animate-code-3 { animation: codeIn 0.15s ease-out 1.1s both; }
.animate-code-4 { animation: codeIn 0.15s ease-out 1.25s both; }
.animate-code-5 { animation: codeIn 0.15s ease-out 1.4s both; }
.animate-code-6 { animation: codeIn 0.15s ease-out 1.55s both; }
@keyframes codeIn { from { opacity: 0; transform: scale(0.5) rotateY(90deg); } to { opacity: 1; transform: scale(1) rotateY(0); } }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
</style>
