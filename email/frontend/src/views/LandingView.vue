<script setup>
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import logoUrl from '@/assets/flowone-logo.png'

const { t } = useI18n()

onMounted(() => {
  document.title = t('landingView.metaTitle')
  let meta = document.querySelector('meta[name="description"]')
  if (meta) meta.setAttribute('content', t('landingView.metaDescription'))
  setMeta('og:title', t('landingView.metaTitle'))
  setMeta('og:description', t('landingView.ogDescription'))
  setMeta('og:url', 'https://flowone.pro/')
  setMeta('og:type', 'website')
  setMeta('og:site_name', t('landingView.devconWebmail'))
})

function setMeta(property, content) {
  let el = document.querySelector(`meta[property="${property}"]`)
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute('property', property)
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}
</script>

<template>
  <div class="min-h-screen bg-surface-950 text-white flex flex-col">

    <!-- Nav -->
    <header class="px-6 py-4 border-b border-surface-800/50 flex items-center justify-between max-w-6xl w-full mx-auto">
      <div class="flex items-center gap-3">
        <img :src="logoUrl" alt="FlowOne.Pro" class="w-10 h-10 object-contain" />
        <span class="text-lg font-bold tracking-tight">{{ $t('landingView.devconWebmail') }}</span>
      </div>
      <router-link
        to="/login"
        class="px-5 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors flex items-center gap-2"
      >
        <span class="material-symbols-rounded text-lg">login</span>
        {{ $t('landingView.signIn') }}
      </router-link>
    </header>

    <!-- Hero -->
    <main class="flex-1 flex flex-col items-center px-6">
      <div class="max-w-5xl text-center py-16">

        <p class="text-sm text-surface-400 mb-6">
          {{ $t('landingView.publicAccessibleNotice') }}
        </p>

        <h1 class="text-4xl md:text-5xl font-bold mb-4 leading-tight">{{ $t('landingView.devconWebmail') }}</h1>
        <h2 class="text-xl md:text-2xl font-medium text-primary-400 mb-8">{{ $t('landingView.secureBusinessEmailAmpCollaboration') }}</h2>

        <p class="text-base text-surface-300 mb-4 max-w-2xl mx-auto leading-relaxed">
          {{ $t('landingView.longDescription') }}
        </p>
        <p class="text-sm text-surface-500 mb-12 max-w-2xl mx-auto leading-relaxed">
          {{ $t('landingView.secondaryDescription') }}
        </p>

        <div class="flex flex-wrap items-center justify-center gap-4 mb-14">
          <router-link
            to="/login"
            class="px-8 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-base font-semibold transition-colors flex items-center gap-2"
          >
            <span class="material-symbols-rounded">arrow_forward</span>
            {{ $t('landingView.getStarted') }}
          </router-link>
        </div>

        <!-- Feature grid -->
        <h3 class="text-lg font-semibold text-surface-200 mb-6">{{ $t('landingView.featuresOfDevconWebmail') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 text-left">

          <!-- Core Features -->
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-blue-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-blue-400 mb-4 block">mail</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.email') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.fullImapEmailClientWith') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-green-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-green-400 mb-4 block">calendar_month</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.calendar') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.sharedCalendarsEventSchedulingAnd') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-purple-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-purple-400 mb-4 block">dashboard</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.boards') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.kanbanstyleTaskBoardsWithMilestones') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-cyan-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-cyan-400 mb-4 block">folder</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.drive') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.cloudFileStorageWithDocument') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-amber-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-amber-400 mb-4 block">forum</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.teamChat') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.realtimeMessagingWithChannelsThreads') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-rose-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-rose-400 mb-4 block">people</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.clients') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.crmstyleClientManagementWithEmail') }}</p>
          </div>

          <!-- Pro Addons & Advanced Features -->
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-indigo-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-indigo-400 mb-4 block">auto_awesome</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.aiAssistant') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.aiAssistantDesc') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-orange-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-orange-400 mb-4 block">account_tree</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.automations') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.automationsDesc') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-pink-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-pink-400 mb-4 block">monitoring</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.crmPro') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.crmProDesc') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-teal-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-teal-400 mb-4 block">campaign</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.emailMarketing') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.emailMarketingDesc') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-fuchsia-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-fuchsia-400 mb-4 block">palette</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.moodboards') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.moodboardsDesc') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-lime-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-lime-400 mb-4 block">timer</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.timeTracker') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.timeTrackerDesc') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-sky-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-sky-400 mb-4 block">captive_portal</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.clientPortal') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.clientPortalDesc') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-violet-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-violet-400 mb-4 block">trending_up</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.boardPro') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.boardProDesc') }}</p>
          </div>
          <div class="p-6 rounded-2xl bg-surface-900/60 border border-surface-800/50 hover:border-emerald-500/30 transition-colors">
            <span class="material-symbols-rounded text-3xl text-emerald-400 mb-4 block">task_alt</span>
            <h4 class="text-base font-semibold text-surface-200 mb-2">{{ $t('landingView.tasks') }}</h4>
            <p class="text-sm text-surface-400 leading-relaxed">{{ $t('landingView.tasksDesc') }}</p>
          </div>
        </div>
      </div>
    </main>

    <!-- Links section -->
    <div class="px-6 py-5 border-t border-surface-800/50 bg-surface-900/40">
      <div class="max-w-6xl mx-auto flex flex-wrap items-center justify-center gap-4">
        <router-link
          to="/privacy"
          class="flex items-center gap-2 px-5 py-2.5 rounded-full bg-surface-800 hover:bg-surface-700 border border-surface-700/50 text-surface-300 hover:text-white transition-colors text-sm font-medium"
        >
          <span class="material-symbols-rounded text-lg">policy</span>
          {{ $t('landingView.privacyPolicy') }}
        </router-link>
        <router-link
          to="/terms"
          class="flex items-center gap-2 px-5 py-2.5 rounded-full bg-surface-800 hover:bg-surface-700 border border-surface-700/50 text-surface-300 hover:text-white transition-colors text-sm font-medium"
        >
          <span class="material-symbols-rounded text-lg">gavel</span>
          Terms of Service
        </router-link>
        <a
          href="mailto:pixelrangerstudio@gmail.com"
          class="flex items-center gap-2 px-5 py-2.5 rounded-full bg-surface-800 hover:bg-surface-700 border border-surface-700/50 text-surface-300 hover:text-white transition-colors text-sm font-medium"
        >
          <span class="material-symbols-rounded text-lg">mail</span>
          pixelrangerstudio@gmail.com
        </a>
      </div>
    </div>

    <!-- Footer -->
    <footer class="px-6 py-6 border-t border-surface-800/50 text-center">
      <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
        <p class="text-xs text-surface-600">
          &copy; {{ new Date().getFullYear() }} FlowOne.Pro. {{ $t('landingView.developedBy') }}
          <a href="https://pixelranger.hu" target="_blank" rel="noopener" class="text-surface-400 hover:text-primary-400 transition-colors">{{ $t('landingView.pixelRangerStudio') }}</a>.
        </p>
        <div class="flex items-center gap-6 text-xs">
          <router-link to="/privacy" class="text-surface-500 hover:text-surface-300 transition-colors">{{ $t('landingView.privacyPolicy') }}</router-link>
          <router-link to="/terms" class="text-surface-500 hover:text-surface-300 transition-colors">Terms of Service</router-link>
          <a href="mailto:pixelrangerstudio@gmail.com" class="text-surface-500 hover:text-surface-300 transition-colors">{{ $t('landingView.contact') }}</a>
        </div>
      </div>
    </footer>
  </div>
</template>
