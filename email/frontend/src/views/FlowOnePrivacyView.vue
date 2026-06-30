<script setup>
import { onMounted } from 'vue'
import logoUrl from '@/assets/flowone-logo.png'

onMounted(() => {
  document.title = 'Privacy Policy - FlowOne.Pro'
})
</script>

<template>
  <div class="min-h-screen bg-surface-950 text-white flex flex-col">

    <header class="px-6 py-4 border-b border-surface-800/50 flex items-center justify-between max-w-6xl w-full mx-auto">
      <router-link to="/" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <img :src="logoUrl" alt="FlowOne.Pro" class="w-10 h-10 object-contain" />
        <span class="text-lg font-bold tracking-tight">FlowOne.Pro</span>
      </router-link>
      <router-link
        to="/login"
        class="px-5 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors flex items-center gap-2"
      >
        <span class="material-symbols-rounded text-lg">login</span>
        Sign In
      </router-link>
    </header>

    <main class="flex-1 px-6 py-12">
      <article class="max-w-3xl mx-auto">
        <h1 class="text-3xl font-bold mb-2">Privacy Policy</h1>
        <p class="text-sm text-surface-500 mb-10">Last updated: February 8, 2026</p>

        <p class="text-sm text-surface-400 leading-relaxed mb-10">
          FlowOne.Pro is operated by Pixel Ranger Studio. This Privacy Policy explains how we collect, use, and protect user data, including data accessed through Google APIs.
        </p>

        <div class="prose-dark space-y-8">

          <section>
            <h2>1. Information We Process</h2>
            <p>FlowOne.Pro processes only the data necessary to provide its core functionality.</p>
            <p>When users sign in using Google OAuth, and only after explicit user consent, the application may access:</p>
            <ul>
              <li>Google Drive data (limited to files created or opened through the application)</li>
              <li>Google Calendar events</li>
              <li>Google Contacts information (read-only access)</li>
            </ul>
            <p>We do not access any Google user data without user authorization.</p>
          </section>

          <section>
            <h2>2. Purpose of Data Processing</h2>
            <p>Google user data is used solely to provide the core features of the application, including:</p>
            <ul>
              <li>File storage and document collaboration</li>
              <li>Calendar synchronization and event management</li>
              <li>Contact integration for communication features</li>
            </ul>
            <p>We do not use Google user data for advertising, profiling, or marketing purposes.</p>
          </section>

          <section>
            <h2>3. Data Storage and Security</h2>
            <p>We implement appropriate technical and organizational measures to protect user data from unauthorized access, alteration, disclosure, or destruction.</p>
            <p>Google user data is stored only as long as necessary to provide the requested functionality or as required for legitimate operational purposes.</p>
          </section>

          <section>
            <h2>4. Data Sharing and Transfer</h2>
            <p>We do not sell, rent, trade, or otherwise transfer Google user data to third parties.</p>
            <p>Google user data is not shared except:</p>
            <ul>
              <li>When required by law</li>
              <li>When necessary to provide core service functionality</li>
            </ul>
            <p>FlowOne.Pro complies with the <strong>Google API Services User Data Policy</strong>, including the Limited Use requirements.</p>
          </section>

          <section>
            <h2>5. User Rights and Revocation</h2>
            <p>Users may:</p>
            <ul>
              <li>Revoke Google account access at any time via: <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">https://myaccount.google.com/permissions</a></li>
              <li>Request access to their stored data</li>
              <li>Request correction or deletion of their data</li>
            </ul>
            <p>Data deletion requests can be submitted via email.</p>
          </section>

          <section>
            <h2>6. Contact Information</h2>
            <p>FlowOne.Pro<br>Operated by Pixel Ranger Studio</p>
            <div class="mt-4 inline-flex items-center gap-3 px-5 py-3 rounded-xl bg-surface-900/80 border border-surface-700/50">
              <span class="material-symbols-rounded text-xl text-primary-400">mail</span>
              <a href="mailto:pixelrangerstudio@gmail.com" class="text-base font-medium">pixelrangerstudio@gmail.com</a>
            </div>
          </section>
        </div>
      </article>
    </main>

    <footer class="px-6 py-6 border-t border-surface-800/50 text-center">
      <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
        <p class="text-xs text-surface-600">
          &copy; {{ new Date().getFullYear() }} FlowOne.Pro. Developed by
          <a href="https://pixelranger.hu" target="_blank" rel="noopener" class="text-surface-400 hover:text-primary-400 transition-colors">Pixel Ranger Studio</a>.
        </p>
        <div class="flex items-center gap-6 text-xs">
          <router-link to="/" class="text-surface-500 hover:text-surface-300 transition-colors">Home</router-link>
          <router-link to="/terms" class="text-surface-500 hover:text-surface-300 transition-colors">Terms of Service</router-link>
          <a href="mailto:pixelrangerstudio@gmail.com" class="text-surface-500 hover:text-surface-300 transition-colors">Contact</a>
        </div>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.prose-dark h2 {
  @apply text-xl font-semibold text-surface-100 mt-8 mb-3;
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
