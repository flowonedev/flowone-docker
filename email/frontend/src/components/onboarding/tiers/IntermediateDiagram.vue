<script setup>
import { computed, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import OnboardingFlowNode from '../OnboardingFlowNode.vue'
import OnboardingNodeTooltip from '../OnboardingNodeTooltip.vue'
import { intermediateModel as model } from '../model/intermediate'
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
          <stop offset="0%" :stop-color="model.branches[conn.branch]?.start || '#8b5cf6'" />
          <stop offset="100%" :stop-color="model.branches[conn.branch]?.end || '#a855f7'" />
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
            r="3.5" :fill="model.branches[conn.branch]?.end || '#a855f7'" opacity="0.9">
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

        <!-- Step 2: Kanban board -->
        <div v-if="currentStep === 2" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">dashboard</span> {{ tr('Board', 'Tábla') }}</div>
          <div class="grid grid-cols-3 gap-2">
            <div v-for="(col, ci) in [{name:tr('To Do','Teendő'),color:'bg-blue-400',cards:[tr('Design mockup','Design vázlat'),tr('Review brief','Brief áttekintése')]},{name:tr('In Progress','Folyamatban'),color:'bg-amber-400',cards:[tr('Homepage','Főoldal')]},{name:tr('Done','Kész'),color:'bg-green-400',cards:[tr('Logo','Logó'),tr('Icons','Ikonok')]}]" :key="ci" class="bg-white dark:bg-surface-700 rounded-lg p-2">
              <div class="flex items-center gap-1 mb-2"><span :class="['w-2 h-2 rounded-full', col.color]"></span><span class="text-[9px] text-surface-400">{{ col.name }}</span></div>
              <div class="space-y-1">
                <div v-for="(card, cardi) in col.cards" :key="card" :class="['bg-surface-50 dark:bg-surface-600 rounded p-1.5 text-[9px] text-surface-600 dark:text-surface-300', 'animate-board-card-' + (ci * 2 + cardi + 1)]">{{ card }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 3: Chat -->
        <div v-else-if="currentStep === 3" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">chat</span> #{{ tr('design-team', 'design-csapat') }}</div>
          <div class="space-y-2">
            <div class="flex gap-2 animate-chat-1"><div class="w-6 h-6 rounded-full bg-blue-500 shrink-0 mt-0.5"></div><div class="bg-white dark:bg-surface-700 rounded-xl px-3 py-2 text-[10px] text-surface-600 dark:text-surface-300">{{ tr('Mockups are uploaded to Drive', 'A mockupok felkerültek a Drive-ra') }}</div></div>
            <div class="flex gap-2 justify-end animate-chat-2"><div class="bg-primary-500 rounded-xl px-3 py-2 text-[10px] text-white">{{ tr("Great, I'll review them now", 'Szuper, most átnézem őket') }}</div><div class="w-6 h-6 rounded-full bg-violet-500 shrink-0 mt-0.5"></div></div>
            <div class="flex gap-2 animate-chat-3"><div class="w-6 h-6 rounded-full bg-green-500 shrink-0 mt-0.5"></div><div class="bg-white dark:bg-surface-700 rounded-xl px-3 py-2 text-[10px] text-surface-600 dark:text-surface-300">{{ tr('Added comments on the board card', 'Kommenteket adtam a táblakártyához') }}</div></div>
          </div>
        </div>

        <!-- Step 4: Drive -->
        <div v-else-if="currentStep === 4" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">cloud</span> {{ tr('Team Drive', 'Csapat Drive') }}</div>
          <div class="space-y-1.5">
            <div v-for="(f, fi) in [{icon:'folder',name:tr('Projects','Projektek'),color:'text-amber-500',meta:tr('5 items','5 elem')},{icon:'description',name:'Homepage.fig',color:'text-blue-500',meta:tr('v3 - 2h ago','v3 - 2 órája')},{icon:'image',name:'Logo_final.svg',color:'text-green-500',meta:tr('v2 - today','v2 - ma')}]" :key="fi" :class="['flex items-center gap-3 bg-white dark:bg-surface-700 rounded-lg p-2.5', 'animate-drive-' + (fi+1)]">
              <span :class="['material-symbols-rounded text-lg', f.color]">{{ f.icon }}</span>
              <span class="text-[10px] text-surface-600 dark:text-surface-300 flex-1">{{ f.name }}</span>
              <span class="text-[9px] text-surface-400">{{ f.meta }}</span>
            </div>
          </div>
        </div>

        <!-- Step 5: Video -->
        <div v-else-if="currentStep === 5" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="bg-surface-800 dark:bg-surface-950 rounded-xl p-4 flex items-center justify-center gap-4 mb-3">
            <div v-for="(p, pi) in [{color:'from-blue-500/30 to-violet-500/30',icon:'person'},{color:'from-green-500/30 to-emerald-500/30',icon:'person'},{color:'from-amber-500/30 to-orange-500/30',icon:'screen_share'}]" :key="pi" :class="['w-14 h-10 rounded-lg bg-gradient-to-br flex items-center justify-center animate-video-' + (pi+1), p.color]">
              <span class="material-symbols-rounded text-white/60 text-lg">{{ p.icon }}</span>
            </div>
          </div>
          <div class="flex items-center justify-center gap-3">
            <span v-for="(btn, bi) in [{icon:'mic',bg:'bg-surface-200 dark:bg-surface-700'},{icon:'videocam',bg:'bg-surface-200 dark:bg-surface-700'},{icon:'call_end',bg:'bg-red-500'},{icon:'screen_share',bg:'bg-primary-500'}]" :key="bi" :class="['w-8 h-8 rounded-full flex items-center justify-center', btn.bg]">
              <span :class="['material-symbols-rounded text-base', btn.bg.includes('red') || btn.bg.includes('primary') ? 'text-white' : 'text-surface-500']">{{ btn.icon }}</span>
            </span>
          </div>
        </div>

        <!-- Step 6: Huddles -->
        <div v-else-if="currentStep === 6" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="text-center">
            <div class="w-16 h-16 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center mx-auto mb-3 animate-huddle-pulse">
              <span class="material-symbols-rounded text-green-500 text-2xl">headphones</span>
            </div>
            <div class="text-xs font-medium text-surface-700 dark:text-surface-200 mb-1">{{ tr('Quick Huddle', 'Gyors huddle') }}</div>
            <div class="text-[10px] text-surface-400 mb-3">#{{ tr('design-team', 'design-csapat') }} - {{ tr('3 participants', '3 résztvevő') }}</div>
            <div class="flex justify-center -space-x-2">
              <div v-for="c in ['bg-blue-500','bg-violet-500','bg-green-500']" :key="c" :class="['w-8 h-8 rounded-full border-2 border-white dark:border-surface-800', c]"></div>
            </div>
          </div>
        </div>

        <!-- Step 7: Moodboards -->
        <div v-else-if="currentStep === 7" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">palette</span> {{ tr('Moodboard', 'Moodboard') }}</div>
          <div class="relative h-28">
            <div class="absolute top-0 left-0 w-16 h-12 rounded-lg bg-gradient-to-br from-pink-400 to-rose-400 opacity-80 animate-mood-1"></div>
            <div class="absolute top-2 left-14 w-14 h-10 rounded-lg bg-gradient-to-br from-amber-400 to-orange-400 opacity-70 animate-mood-2"></div>
            <div class="absolute bottom-0 left-2 w-12 h-14 rounded-lg bg-gradient-to-br from-blue-400 to-cyan-400 opacity-75 animate-mood-3"></div>
            <div class="absolute top-0 right-2 w-20 h-16 rounded-lg bg-gradient-to-br from-violet-400 to-purple-400 opacity-60 animate-mood-4"></div>
            <div class="absolute bottom-0 right-4 px-2 py-1 bg-white/80 dark:bg-surface-700/80 rounded text-[9px] text-surface-600 dark:text-surface-300 animate-mood-5">{{ tr('Color palette', 'Színpaletta') }}</div>
          </div>
        </div>

        <!-- Step 8: Colleagues -->
        <div v-else-if="currentStep === 8" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">badge</span> {{ tr('Team Directory', 'Csapatjegyzék') }}</div>
          <div class="space-y-2">
            <div v-for="(m, mi) in [{name:'Minta P.',role:tr('Designer','Designer'),status:tr('Online','Online'),color:'bg-blue-500'},{name:'Teszt D.',role:tr('Developer','Fejlesztő'),status:tr('In meeting','Mítingen'),color:'bg-violet-500'},{name:'Pelda N.',role:'PM',status:tr('Online','Online'),color:'bg-green-500'}]" :key="mi" :class="['flex items-center gap-3 bg-white dark:bg-surface-700 rounded-lg p-2.5', 'animate-team-' + (mi+1)]">
              <div class="relative"><div :class="['w-8 h-8 rounded-full flex items-center justify-center text-white text-[10px] font-bold', m.color]">{{ m.name[0] }}</div><div :class="['absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white dark:border-surface-700', m.status === 'Online' ? 'bg-green-500' : 'bg-amber-500']"></div></div>
              <div class="flex-1"><div class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ m.name }}</div><div class="text-[9px] text-surface-400">{{ m.role }}</div></div>
              <span class="text-[9px] text-surface-400">{{ m.status }}</span>
            </div>
          </div>
        </div>

        <!-- Step 9: Email Tracking -->
        <div v-else-if="currentStep === 9" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">visibility</span> {{ tr('Tracking', 'Követés') }}</div>
          <div class="bg-white dark:bg-surface-700 rounded-lg p-3 mb-2">
            <div class="text-[10px] font-medium text-surface-700 dark:text-surface-200 mb-2">{{ tr('Proposal to Pixel Ranger Studio', 'Ajánlat a Pixel Ranger Studio-nak') }}</div>
            <div class="flex items-center gap-2 animate-track-1">
              <span class="material-symbols-rounded text-green-500 text-sm">visibility</span>
              <span class="text-[10px] text-surface-500">{{ tr('Opened 3x', '3x megnyitva') }}</span>
              <span class="text-[9px] text-surface-400 ml-auto">{{ tr('Last: 2h ago', 'Utolsó: 2 órája') }}</span>
            </div>
          </div>
          <div class="bg-white dark:bg-surface-700 rounded-lg p-3 animate-track-2">
            <div class="text-[10px] font-medium text-surface-700 dark:text-surface-200 mb-2">Invoice #2024-015</div>
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-amber-500 text-sm">schedule</span>
              <span class="text-[10px] text-surface-500">{{ tr('Not opened yet', 'Még nem nyitották meg') }}</span>
              <span class="text-[9px] text-surface-400 ml-auto">{{ tr('Sent 5h ago', '5 órája küldve') }}</span>
            </div>
          </div>
        </div>

        <!-- Step 10: Reactions -->
        <div v-else-if="currentStep === 10" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="bg-white dark:bg-surface-700 rounded-lg p-3 mb-3">
            <div class="text-[10px] text-surface-500 mb-2">{{ tr('From:', 'Feladó:') }} petra.minta@example.com</div>
            <div class="text-xs text-surface-700 dark:text-surface-200">"{{ tr('The designs look amazing, great work!', 'Nagyon jól néznek ki a designok, szép munka!') }}"</div>
          </div>
          <div class="flex items-center gap-2 animate-react-appear">
            <span class="text-lg animate-react-1">&#128077;</span>
            <span class="text-lg animate-react-2">&#10084;&#65039;</span>
            <span class="text-lg animate-react-3">&#127881;</span>
            <span class="text-[10px] text-surface-400 ml-2">{{ tr('React without replying', 'Reagálj válasz nélkül') }}</span>
          </div>
        </div>

        <!-- Step 11: AI -->
        <div v-else-if="currentStep === 11" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">smart_toy</span> {{ tr('AI Assistant', 'AI asszisztens') }}</div>
          <div class="bg-white dark:bg-surface-700 rounded-lg p-3 mb-2">
            <div class="text-[10px] text-surface-400 mb-1">{{ tr('Summary of 8 emails:', '8 e-mail összefoglalója:') }}</div>
            <div class="text-[10px] text-surface-600 dark:text-surface-300 animate-ai-type">{{ tr('Client approved logo v3. Waiting for color palette feedback. Meeting scheduled for Friday.', 'Az ügyfél jóváhagyta a logó v3-at. Színpaletta-visszajelzésre várunk. A megbeszélés péntekre van ütemezve.') }}</div>
          </div>
          <div class="flex gap-2 animate-ai-suggest">
            <span class="text-[9px] bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 px-2 py-1 rounded-full">{{ tr('Suggested reply', 'Javasolt válasz') }}</span>
            <span class="text-[9px] bg-surface-200 dark:bg-surface-600 text-surface-500 px-2 py-1 rounded-full">{{ tr('Summarize thread', 'Szál összefoglalása') }}</span>
          </div>
        </div>

        <!-- Step 12: Templates -->
        <div v-else-if="currentStep === 12" class="bg-surface-100/80 dark:bg-surface-800/80 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-surface-200 dark:border-surface-700 shadow-xl w-full max-w-sm sm:max-w-none sm:w-auto">
          <div class="flex items-center gap-2 mb-3 text-xs text-surface-400"><span class="material-symbols-rounded text-sm">draft</span> {{ tr('Templates', 'Sablonok') }}</div>
          <div class="space-y-2">
            <div v-for="(tpl, ti) in [{name:tr('Welcome Email','Üdvözlő e-mail'),desc:tr('New client onboarding','Új ügyfél onboarding')},{name:tr('Project Update','Projektfrissítés'),desc:tr('Weekly status report','Heti státusz riport')},{name:tr('Invoice Reminder','Számla emlékeztető'),desc:tr('Payment follow-up','Fizetés utánkövetés')}]" :key="ti" :class="['flex items-center gap-3 bg-white dark:bg-surface-700 rounded-lg p-2.5 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-650', 'animate-tpl-' + (ti+1)]">
              <span class="material-symbols-rounded text-primary-500 text-base">description</span>
              <div class="flex-1"><div class="text-[10px] font-medium text-surface-700 dark:text-surface-200">{{ tpl.name }}</div><div class="text-[9px] text-surface-400">{{ tpl.desc }}</div></div>
              <span class="material-symbols-rounded text-surface-400 text-sm">arrow_forward</span>
            </div>
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

.animate-board-card-1 { animation: fadeSlideIn 0.3s ease-out 0.2s both; }
.animate-board-card-2 { animation: fadeSlideIn 0.3s ease-out 0.35s both; }
.animate-board-card-3 { animation: fadeSlideIn 0.3s ease-out 0.5s both; }
.animate-board-card-4 { animation: fadeSlideIn 0.3s ease-out 0.65s both; }
.animate-board-card-5 { animation: fadeSlideIn 0.3s ease-out 0.8s both; }
@keyframes fadeSlideIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

.animate-chat-1 { animation: fadeSlideIn 0.4s ease-out 0.2s both; }
.animate-chat-2 { animation: fadeSlideIn 0.4s ease-out 0.7s both; }
.animate-chat-3 { animation: fadeSlideIn 0.4s ease-out 1.2s both; }

.animate-drive-1 { animation: fadeSlideIn 0.3s ease-out 0.2s both; }
.animate-drive-2 { animation: fadeSlideIn 0.3s ease-out 0.5s both; }
.animate-drive-3 { animation: fadeSlideIn 0.3s ease-out 0.8s both; }

.animate-video-1 { animation: fadeIn 0.4s ease-out 0.2s both; }
.animate-video-2 { animation: fadeIn 0.4s ease-out 0.5s both; }
.animate-video-3 { animation: fadeIn 0.4s ease-out 0.8s both; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.animate-huddle-pulse { animation: huddlePulse 2s ease-in-out 1 forwards; }
@keyframes huddlePulse { 0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.3); } 50% { box-shadow: 0 0 0 12px rgba(34, 197, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }

.animate-mood-1 { animation: fadeIn 0.4s ease-out 0.2s both; }
.animate-mood-2 { animation: fadeIn 0.4s ease-out 0.5s both; }
.animate-mood-3 { animation: fadeIn 0.4s ease-out 0.8s both; }
.animate-mood-4 { animation: fadeIn 0.4s ease-out 1.1s both; }
.animate-mood-5 { animation: fadeSlideIn 0.3s ease-out 1.4s both; }

.animate-team-1 { animation: fadeSlideIn 0.3s ease-out 0.2s both; }
.animate-team-2 { animation: fadeSlideIn 0.3s ease-out 0.5s both; }
.animate-team-3 { animation: fadeSlideIn 0.3s ease-out 0.8s both; }

.animate-track-1 { animation: fadeIn 0.4s ease-out 0.5s both; }
.animate-track-2 { animation: fadeSlideIn 0.4s ease-out 0.8s both; }

.animate-react-appear { animation: fadeIn 0.3s ease-out 0.5s both; }
.animate-react-1 { animation: reactPop 0.3s ease-out 0.8s both; }
.animate-react-2 { animation: reactPop 0.3s ease-out 1.0s both; }
.animate-react-3 { animation: reactPop 0.3s ease-out 1.2s both; }
@keyframes reactPop { from { opacity: 0; transform: scale(0.3); } to { opacity: 1; transform: scale(1); } }

.animate-ai-type { overflow: hidden; white-space: nowrap; border-right: 2px solid currentColor; animation: typewriter 2s steps(80) 0.3s both, blink 0.7s step-end infinite; width: 0; }
@keyframes typewriter { from { width: 0; } to { width: 100%; } }
@keyframes blink { 50% { border-color: transparent; } }
.animate-ai-suggest { animation: fadeIn 0.4s ease-out 2.5s both; }

.animate-tpl-1 { animation: fadeSlideIn 0.3s ease-out 0.2s both; }
.animate-tpl-2 { animation: fadeSlideIn 0.3s ease-out 0.5s both; }
.animate-tpl-3 { animation: fadeSlideIn 0.3s ease-out 0.8s both; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
</style>
