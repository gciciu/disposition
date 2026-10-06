import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api, { clearStoredSession, revokeRefreshToken, setSessionHandlers } from '@/api.js'
import i18n from '@/i18n'
import { useMainStore } from '@/stores/main.js'

const ADMIN_ROLES = ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']

export const useAuthStore = defineStore('auth', () => {
  const token = ref(readStoredToken())
  const refreshToken = ref(readStoredRefreshToken())
  const user = ref(readStoredUser())

  const isAuthenticated = computed(() => Boolean(token.value || refreshToken.value))
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
    if (localStorage.getItem('auth_token') || localStorage.getItem('refresh_token')) {
      return localStorage
    }
    if (sessionStorage.getItem('auth_token') || sessionStorage.getItem('refresh_token')) {
      return sessionStorage
    }
    return localStorage
  }

  function persist() {
    const storage = activeStorage()
    const other = storage === localStorage ? sessionStorage : localStorage
    other.removeItem('auth_token')
    other.removeItem('auth_user')
    other.removeItem('refresh_token')

    if (token.value) {
      storage.setItem('auth_token', token.value)
    } else {
      storage.removeItem('auth_token')
    }

    if (refreshToken.value) {
      storage.setItem('refresh_token', refreshToken.value)
    } else {
      storage.removeItem('refresh_token')
    }

    if (user.value) {
      storage.setItem('auth_user', JSON.stringify(user.value))
    } else {
      storage.removeItem('auth_user')
    }
  }

  function clearSession() {
    token.value = ''
    refreshToken.value = ''
    user.value = null
    clearStoredSession()
  }

  async function login(email, password, remember = true) {
    const { data } = await api.post('/api/login', { email, password, remember })

    const nextUser = data.user
    const roles = nextUser?.roles || []
    const allowed = roles.some((role) => ADMIN_ROLES.includes(role))

    if (!allowed) {
      if (data.refresh_token) {
        await revokeRefreshToken(data.refresh_token)
      }
      throw new Error(i18n.global.t('login.adminOnly'))
    }

    const storage = remember ? localStorage : sessionStorage
    const other = remember ? sessionStorage : localStorage
    other.removeItem('auth_token')
    other.removeItem('auth_user')
    other.removeItem('refresh_token')

    token.value = data.token
    refreshToken.value = data.refresh_token || ''
    user.value = nextUser
    storage.setItem('auth_token', token.value)
    if (refreshToken.value) {
      storage.setItem('refresh_token', refreshToken.value)
    }
    storage.setItem('auth_user', JSON.stringify(user.value))
    syncMainStore()

    return nextUser
  }

  async function fetchMe() {
    if (!token.value && !refreshToken.value) {
      return null
    }

    const { data } = await api.get('/api/me')
    user.value = data.user
    persist()
    syncMainStore()

    if (!isAdmin.value) {
      await logout()
      throw new Error(i18n.global.t('login.adminOnly'))
    }

    return user.value
  }

  async function logout() {
    const currentRefreshToken = refreshToken.value || readStoredRefreshToken()
    clearSession()
    await revokeRefreshToken(currentRefreshToken)
  }

  setSessionHandlers({
    onTokens(data) {
      token.value = data.token || ''
      refreshToken.value = data.refresh_token || ''
      if (data.user) {
        user.value = data.user
      }
      syncMainStore()

      const roles = data.user?.roles || []
      if (!roles.some((role) => ADMIN_ROLES.includes(role))) {
        throw new Error(i18n.global.t('login.adminOnly'))
      }
    },
    onExpired() {
      token.value = ''
      refreshToken.value = ''
      user.value = null
    },
  })

  if (user.value) {
    syncMainStore()
  }

  return {
    token,
    refreshToken,
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

function readStoredRefreshToken() {
  return localStorage.getItem('refresh_token') || sessionStorage.getItem('refresh_token') || ''
}

function readStoredUser() {
  try {
    const raw = localStorage.getItem('auth_user') || sessionStorage.getItem('auth_user')
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}
