import { createApp } from 'vue'
import { createPinia } from 'pinia'
import i18n from '@/i18n'
import App from './App.vue'
import router from './router/index.js'
// Use the SHARED frontend stylesheet (same one the email mobile app ships) so
// the chat app never drifts from it; chat-specific deltas layer on top.
import '@/assets/styles/main.css'
import './styles/chat-overrides.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(i18n)
app.mount('#app')
