import { onUnmounted, ref } from 'vue'
import api from '@/api.js'

/**
 * Subscribe to admin realtime Mercure (SSE) events.
 *
 * @param {{
 *   routeId?: number|string|null|(() => number|string|null|undefined),
 *   onEvent: (event: string, data: any) => void
 * }} options
 */
export function useRealtime(options = {}) {
  const connected = ref(false)
  let source = null
  let stopped = false
  let reconnectTimer = null
  let backoffMs = 1000
  let ticketExpiresAt = 0

  const onEvent = typeof options.onEvent === 'function' ? options.onEvent : () => {}

  const resolveRouteId = () => {
    const value = typeof options.routeId === 'function' ? options.routeId() : options.routeId
    if (value == null || value === '') {
      return null
    }
    return value
  }

  const clearReconnect = () => {
    if (reconnectTimer != null) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
  }

  const closeSource = () => {
    if (source) {
      source.onopen = null
      source.onmessage = null
      source.onerror = null
      try {
        source.close()
      } catch {
        // ignore
      }
      source = null
    }
    connected.value = false
  }

  const scheduleReconnect = () => {
    if (stopped) {
      return
    }
    clearReconnect()
    const delay = backoffMs
    backoffMs = Math.min(backoffMs * 2, 15000)
    reconnectTimer = setTimeout(() => {
      void connect()
    }, delay)
  }

  const fetchTicket = async () => {
    const params = {}
    const routeId = resolveRouteId()
    if (routeId != null) {
      params.routeId = routeId
    }
    const { data } = await api.get('/api/admin/realtime/ticket', { params })
    return data
  }

  const buildHubUrl = (ticketPayload) => {
    const hubBase =
      import.meta.env.VITE_MERCURE_URL ||
      ticketPayload?.hubUrl ||
      'http://127.0.0.1:3000/.well-known/mercure'

    const url = new URL(hubBase)
    const topics = Array.isArray(ticketPayload?.topics) ? ticketPayload.topics : []
    for (const topic of topics) {
      if (typeof topic === 'string' && topic.length > 0) {
        url.searchParams.append('topic', topic)
      }
    }
    if (ticketPayload?.token) {
      url.searchParams.set('authorization', ticketPayload.token)
    }
    return url.toString()
  }

  const connect = async () => {
    if (stopped) {
      return
    }

    clearReconnect()
    closeSource()

    let ticketPayload
    try {
      ticketPayload = await fetchTicket()
    } catch {
      scheduleReconnect()
      return
    }

    if (!ticketPayload?.token) {
      scheduleReconnect()
      return
    }

    ticketExpiresAt = Number(ticketPayload.expiresAt) || 0

    try {
      source = new EventSource(buildHubUrl(ticketPayload))
    } catch {
      scheduleReconnect()
      return
    }

    source.onopen = () => {
      connected.value = true
      backoffMs = 1000
    }

    source.onmessage = (message) => {
      let payload
      try {
        payload = JSON.parse(message.data)
      } catch {
        return
      }
      if (!payload || typeof payload.event !== 'string') {
        return
      }
      onEvent(payload.event, payload.data ?? null)
    }

    source.onerror = () => {
      connected.value = false
      closeSource()
      if (ticketExpiresAt > 0 && ticketExpiresAt * 1000 - Date.now() < 5000) {
        backoffMs = Math.min(backoffMs, 1000)
      }
      scheduleReconnect()
    }
  }

  const stop = () => {
    stopped = true
    clearReconnect()
    closeSource()
  }

  const start = () => {
    stopped = false
    backoffMs = 1000
    void connect()
  }

  onUnmounted(stop)

  return {
    connected,
    start,
    stop,
  }
}

/**
 * Merge list-level fields from a full route payload into an existing list row.
 */
export function applyRouteListPatch(existing, routePayload) {
  if (!routePayload || routePayload.id == null) {
    return existing
  }
  return {
    ...existing,
    progress: routePayload.progress ?? existing.progress,
    deliveredCount: routePayload.deliveredCount ?? existing.deliveredCount,
    orderCount: routePayload.orderCount ?? existing.orderCount,
    startedAt: routePayload.startedAt ?? existing.startedAt,
    completedAt: routePayload.completedAt ?? existing.completedAt,
    totalDurationSeconds: routePayload.totalDurationSeconds ?? existing.totalDurationSeconds,
    totalDistanceMeters: routePayload.totalDistanceMeters ?? existing.totalDistanceMeters,
    totalDurationText: routePayload.totalDurationText ?? existing.totalDurationText,
    totalDistanceText: routePayload.totalDistanceText ?? existing.totalDistanceText,
    mapsUrl: routePayload.mapsUrl ?? existing.mapsUrl,
  }
}
