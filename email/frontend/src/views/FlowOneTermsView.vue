<script setup>
import { onMounted } from 'vue'
import logoUrl from '@/assets/flowone-logo.png'

onMounted(() => {
  document.title = 'Terms of Service - FlowOne.Pro'
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
        <h1 class="text-3xl font-bold mb-2">Terms of Service</h1>
        <p class="text-sm text-surface-500 mb-10">Last updated: February 8, 2026</p>

        <p class="text-sm text-surface-400 leading-relaxed mb-10">
          These Terms of Service ("Terms") govern your use of FlowOne.Pro, operated by Pixel Ranger Studio ("we", "us", or "our"). By accessing or using FlowOne.Pro, you agree to these Terms.
        </p>

        <div class="prose-dark space-y-8">

          <section>
            <h2>1. Description of Service</h2>
            <p>FlowOne.Pro is a web-based business productivity platform that provides email management, calendar organization, file storage, collaboration tools, and related services.</p>
            <p>The application may integrate with third-party services, including Google APIs, to provide certain features.</p>
          </section>

          <section>
            <h2>2. Google Integration</h2>
            <p>FlowOne.Pro uses Google OAuth 2.0 for authentication and authorization.</p>
            <p>When users explicitly grant permission, FlowOne.Pro may access limited data from:</p>
            <ul>
              <li>Google Drive</li>
              <li>Google Calendar</li>
              <li>Google Contacts</li>
            </ul>
            <p>This access is used solely to provide requested application functionality.</p>
            <p>FlowOne.Pro complies with the <strong>Google API Services User Data Policy</strong>, including the Limited Use requirements.</p>
            <p>Users may revoke access at any time via: <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">https://myaccount.google.com/permissions</a></p>
          </section>

          <section>
            <h2>3. User Responsibilities</h2>
            <p>You agree to:</p>
            <ul>
              <li>Provide accurate account information</li>
              <li>Maintain the security of your account</li>
              <li>Use the service in compliance with applicable laws</li>
              <li>Not misuse, disrupt, or attempt unauthorized access to the service</li>
            </ul>
            <p>You are responsible for all activity conducted under your account.</p>
          </section>

          <section>
            <h2>4. Data and Privacy</h2>
            <p>Your use of FlowOne.Pro is also governed by our <router-link to="/privacy" class="text-primary-400 hover:text-primary-300 underline underline-offset-2">Privacy Policy</router-link>.</p>
            <p>We implement reasonable technical and organizational measures to protect user data. However, no system can guarantee absolute security.</p>
          </section>

          <section>
            <h2>5. Service Availability</h2>
            <p>We strive to maintain reliable service but do not guarantee uninterrupted availability. The service may be modified, suspended, or discontinued at any time.</p>
          </section>

          <section>
            <h2>6. Limitation of Liability</h2>
            <p>FlowOne.Pro is provided "as is" without warranties of any kind.</p>
            <p>To the maximum extent permitted by law, Pixel Ranger Studio shall not be liable for indirect, incidental, special, or consequential damages resulting from the use or inability to use the service.</p>
          </section>

          <section>
            <h2>7. Termination</h2>
            <p>We reserve the right to suspend or terminate accounts that violate these Terms or applicable laws.</p>
            <p>Users may stop using the service at any time.</p>
          </section>

          <section>
            <h2>8. Changes to Terms</h2>
            <p>We may update these Terms periodically. Continued use of the service after changes constitutes acceptance of the updated Terms.</p>
          </section>

          <section>
            <h2>9. Contact Information</h2>
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
          <router-link to="/privacy" class="text-surface-500 hover:text-surface-300 transition-colors">Privacy Policy</router-link>
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
