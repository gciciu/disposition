import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/api.js'
import i18n from '@/i18n'
import { useMainStore } from '@/stores/main.js'

const ADMIN_ROLES = ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']

export const useAuthStore = defineStore('auth', () => {
  const token = ref(readStoredToken())
  const user = ref(readStoredUser())

  const isAuthenticated = computed(() => Boolean(token.value))
  const isAdmin = computed(() => {
    const roles = user.value?.roles || []
    return roles.some((role) => ADMIN_ROLES.includes(role))
  })

  function syncMainStore() {
    const mainStore = useMainStore()
    if (user.value) {
      mainStore.setUser({
        name: user.value.name,
        email: user.value.email,
      })
    }
  }

  function activeStorage() {
    if (localStorage.getItem('auth_token')) {
      return localStorage
    }
    if (sessionStorage.getItem('auth_token')) {
      return sessionStorage
    }
    return localStorage
  }

  function persist() {
    const storage = activeStorage()
    const other = storage === localStorage ? sessionStorage : localStorage
    other.removeItem('auth_token')
    other.removeItem('auth_user')

    if (token.value) {
      storage.setItem('auth_token', token.value)
    } else {
      localStorage.removeItem('auth_token')
      sessionStorage.removeItem('auth_token')
    }

    if (user.value) {
      storage.setItem('auth_user', JSON.stringify(user.value))
    } else {
      localStorage.removeItem('auth_user')
      sessionStorage.removeItem('auth_user')
    }
  }

  async function login(email, password, remember = true) {
    const { data } = await api.post('/api/login', { email, password })

    const nextUser = data.user
    const roles = nextUser?.roles || []
    const allowed = roles.some((role) => ADMIN_ROLES.includes(role))

    if (!allowed) {
      throw new Error(i18n.global.t('login.adminOnly'))
    }

    const storage = remember ? localStorage : sessionStorage
    if (!remember) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
    } else {
      sessionStorage.removeItem('auth_token')
      sessionStorage.removeItem('auth_user')
    }

    token.value = data.token
    user.value = nextUser
    storage.setItem('auth_token', token.value)
    storage.setItem('auth_user', JSON.stringify(user.value))
    syncMainStore()

    return nextUser
  }

  async function fetchMe() {
    if (!token.value) {
      return null
    }

    const { data } = await api.get('/api/me')
    user.value = data.user
    persist()
    syncMainStore()

    if (!isAdmin.value) {
      logout()
      throw new Error(i18n.global.t('login.adminOnly'))
    }

    return user.value
  }

  function logout() {
    token.value = ''
    user.value = null
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    sessionStorage.removeItem('auth_token')
    sessionStorage.removeItem('auth_user')
  }

  if (user.value) {
    syncMainStore()
  }

  return {
    token,
    user,
    isAuthenticated,
    isAdmin,
    login,
    fetchMe,
    logout,
  }
})

function readStoredToken() {
  return localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token') || ''
}

function readStoredUser() {
  try {
    const raw = localStorage.getItem('auth_user') || sessionStorage.getItem('auth_user')
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}
