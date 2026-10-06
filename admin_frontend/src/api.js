import axios from 'axios'

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080'

const raw = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

export const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

const AUTH_KEYS = ['auth_token', 'auth_user', 'refresh_token']

let refreshPromise = null
let onTokens = null
let onExpired = null

export function setSessionHandlers(handlers) {
  onTokens = handlers.onTokens || null
  onExpired = handlers.onExpired || null
}

function readRefreshToken() {
  return localStorage.getItem('refresh_token') || sessionStorage.getItem('refresh_token') || ''
}

function storageWithRefreshToken() {
  if (localStorage.getItem('refresh_token')) {
    return localStorage
  }
  if (sessionStorage.getItem('refresh_token')) {
    return sessionStorage
  }
  return null
}

export function clearStoredSession() {
  for (const storage of [localStorage, sessionStorage]) {
    for (const key of AUTH_KEYS) {
      storage.removeItem(key)
    }
  }
}

function persistRefreshedSession(data) {
  const storage = storageWithRefreshToken() || localStorage
  const other = storage === localStorage ? sessionStorage : localStorage

  for (const key of AUTH_KEYS) {
    other.removeItem(key)
  }

  storage.setItem('auth_token', data.token)
  storage.setItem('refresh_token', data.refresh_token)
  if (data.user) {
    storage.setItem('auth_user', JSON.stringify(data.user))
  }

  onTokens?.(data)
}

export function expireSession({ redirect = true } = {}) {
  clearStoredSession()
  onExpired?.()

  if (!redirect) {
    return
  }

  const path = (window.location.hash || '#/').replace(/^#/, '')
  if (path.startsWith('/login')) {
    return
  }

  const params = new URLSearchParams({
    expired: '1',
    redirect: path,
  })
  window.location.hash = `#/login?${params.toString()}`
}

async function refreshAccessToken() {
  const refreshToken = readRefreshToken()
  if (!refreshToken) {
    throw new Error('missing refresh token')
  }

  const { data } = await raw.post('/api/token/refresh', { refresh_token: refreshToken })
  if (!data?.token || !data?.refresh_token) {
    throw new Error('invalid refresh response')
  }

  persistRefreshedSession(data)
  return data.token
}

function refreshOnce() {
  if (!refreshPromise) {
    refreshPromise = refreshAccessToken().finally(() => {
      refreshPromise = null
    })
  }

  return refreshPromise
}

function isAccessTokenExpired(token) {
  try {
    const part = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')
    const json = decodeURIComponent(
      atob(part)
        .split('')
        .map((char) => `%${char.charCodeAt(0).toString(16).padStart(2, '0')}`)
        .join(''),
    )
    const payload = JSON.parse(json)
    return !payload?.exp || payload.exp * 1000 <= Date.now() + 5000
  } catch {
    return true
  }
}

export async function ensureFreshAccessToken() {
  const token = localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token') || ''
  if (token && !isAccessTokenExpired(token)) {
    return
  }

  try {
    await refreshOnce()
  } catch (error) {
    expireSession({ redirect: false })
    throw error
  }
}

export function revokeRefreshToken(refreshToken) {
  if (!refreshToken) {
    return Promise.resolve()
  }

  return raw.post('/api/token/logout', { refresh_token: refreshToken }).catch(() => {})
}

function isPublicAuthRequest(config) {
  const url = config?.url || ''
  return url.includes('/api/login') || url.includes('/api/token/refresh') || url.includes('/api/token/logout')
}

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
    if (config.headers && typeof config.headers.set === 'function') {
      config.headers.set('Content-Type', false)
    } else {
      delete config.headers['Content-Type']
    }
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status
    const original = error.config

    if (status !== 401 || !original || isPublicAuthRequest(original)) {
      return Promise.reject(error)
    }

    if (original._retry || !readRefreshToken()) {
      expireSession()
      return Promise.reject(error)
    }

    original._retry = true

    try {
      const token = await refreshOnce()
      original.headers = original.headers || {}
      original.headers.Authorization = `Bearer ${token}`
      return api(original)
    } catch (refreshError) {
      expireSession()
      return Promise.reject(refreshError)
    }
  },
)

export default api
