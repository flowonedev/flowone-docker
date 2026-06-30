<script setup>
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

onMounted(() => {
  document.title = t('privacyPolicyView.metaTitle')
  let meta = document.querySelector('meta[name="description"]')
  if (meta) meta.setAttribute('content', t('privacyPolicyView.metaDescription'))
  setMeta('og:title', t('privacyPolicyView.metaTitle'))
  setMeta('og:description', t('privacyPolicyView.metaDescription'))
  setMeta('og:url', 'https://flowone.pro/privacy-policy')
  setMeta('og:type', 'website')
  setMeta('og:site_name', t('privacyPolicyView.devconWebmail'))
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

const lastUpdated = 'February 8, 2026'
</script>

<template>
  <div class="min-h-screen bg-surface-950 text-white flex flex-col">

    <!-- Nav -->
    <header class="px-6 py-4 border-b border-surface-800/50 flex items-center justify-between max-w-6xl w-full mx-auto">
      <router-link to="/" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <div class="w-10 h-10 rounded-xl bg-primary-500/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-2xl text-primary-400">mail</span>
        </div>
        <span class="text-lg font-bold tracking-tight">{{ $t('privacyPolicyView.devconWebmail') }}</span>
      </router-link>
      <router-link
        to="/login"
        class="px-5 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors flex items-center gap-2"
      >
        <span class="material-symbols-rounded text-lg">login</span>
        {{ $t('privacyPolicyView.signIn') }}
      </router-link>
    </header>

    <!-- Content -->
    <main class="flex-1 px-6 py-12">
      <article class="max-w-3xl mx-auto">
        <h1 class="text-3xl font-bold mb-2">{{ $t('privacyPolicyView.privacyPolicy') }}</h1>
        <p class="text-sm text-surface-500 mb-10">{{ $t('privacyPolicyView.lastUpdated') }}: {{ lastUpdated }}</p>

        <p class="text-sm text-surface-400 leading-relaxed mb-10">
          {{ $t('privacyPolicyView.intro') }}
        </p>

        <div class="prose-dark space-y-8">

          <section>
            <h2>{{ $t('privacyPolicyView.1ScopeOfProcessedData') }}</h2>
            <p>
              {{ $t('privacyPolicyView.scopeBody') }}
            </p>
          </section>

          <section>
            <h2>{{ $t('privacyPolicyView.2PurposeOfDataProcessing') }}</h2>
            <p>
              {{ $t('privacyPolicyView.purposeBody') }}
            </p>
          </section>

          <section>
            <h2>{{ $t('privacyPolicyView.3DataStorageAndSecurity') }}</h2>
            <p>
              {{ $t('privacyPolicyView.storageBody') }}
            </p>
          </section>

          <section>
            <h2>{{ $t('privacyPolicyView.4DataTransfer') }}</h2>
            <p>
              {{ $t('privacyPolicyView.transferBody') }}
            </p>
          </section>

          <section>
            <h2>{{ $t('privacyPolicyView.5UserRights') }}</h2>
            <p>
              {{ $t('privacyPolicyView.rightsBody') }}
            </p>
          </section>

          <section>
            <h2>{{ $t('privacyPolicyView.6Contact') }}</h2>
            <p>
              {{ $t('privacyPolicyView.contactIntro') }}
            </p>
            <div class="mt-4 inline-flex items-center gap-3 px-5 py-3 rounded-xl bg-surface-900/80 border border-surface-700/50">
              <span class="material-symbols-rounded text-xl text-primary-400">mail</span>
              <a href="mailto:support@example.com" class="text-base font-medium">support@example.com</a>
            </div>
          </section>
        </div>
      </article>
    </main>

    <!-- Footer -->
    <footer class="px-6 py-6 border-t border-surface-800/50 text-center">
      <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
        <p class="text-xs text-surface-600">
          &copy; {{ new Date().getFullYear() }} {{ $t('privacyPolicyView.devconWebmail') }}. {{ $t('privacyPolicyView.developedBy') }}
          <a href="https://example.com" target="_blank" rel="noopener" class="text-surface-400 hover:text-primary-400 transition-colors">{{ $t('privacyPolicyView.pixelRangerStudio') }}</a>.
        </p>
        <div class="flex items-center gap-6 text-xs">
          <router-link to="/" class="text-surface-500 hover:text-surface-300 transition-colors">{{ $t('privacyPolicyView.home') }}</router-link>
          <a href="mailto:support@example.com" class="text-surface-500 hover:text-surface-300 transition-colors">{{ $t('privacyPolicyView.contact') }}</a>
        </div>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.prose-dark h2 {
  @apply text-xl font-semibold text-surface-100 mt-8 mb-3;
}
.prose-dark h3 {
  @apply text-base font-medium text-surface-200 mt-5 mb-2;
}
.prose-dark p {
  @apply text-sm text-surface-400 leading-relaxed mb-3;
}
.prose-dark ul {
  @apply list-disc pl-6 space-y-1.5 text-sm text-surface-400 mb-3;
}
.prose-dark li {
  @apply leading-relaxed;
}
.prose-dark a {
  @apply text-primary-400 hover:text-primary-300 underline underline-offset-2 transition-colors;
}
.prose-dark strong {
  @apply text-surface-200 font-semibold;
}
</style>

