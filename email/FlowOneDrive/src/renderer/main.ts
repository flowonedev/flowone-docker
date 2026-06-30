import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import './styles/main.css'
import { useThemeStore } from './stores/theme'

const savedTheme = localStorage.getItem('drive_theme') || 'dark'
document.documentElement.setAttribute('data-theme', savedTheme)

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.mount('#app')

const themeStore = useThemeStore()
themeStore.init()

