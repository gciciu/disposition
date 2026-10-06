import { createApp } from 'vue'
import { createPinia } from 'pinia'
import Toast, { POSITION } from 'vue-toastification'
import 'vue-toastification/dist/index.css'

import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { useMainStore } from '@/stores/main.js'

import './css/main.css'

const pinia = createPinia()
const app = createApp(App)

app.use(pinia)
app.use(router)
app.use(i18n)
app.use(Toast, {
  position: POSITION.BOTTOM_RIGHT,
  timeout: 4000,
  closeOnClick: true,
  pauseOnFocusLoss: true,
  pauseOnHover: true,
  draggable: true,
  showCloseButtonOnHover: false,
  hideProgressBar: false,
  closeButton: 'button',
  icon: true,
  maxToasts: 5,
  newestOnTop: true,
})
app.mount('#app')

const mainStore = useMainStore(pinia)

mainStore.fetchSampleClients()
mainStore.fetchSampleHistory()
