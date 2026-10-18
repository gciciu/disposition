import { onUnmounted, ref } from 'vue'
import api from '@/api.js'

/**
 * Subscribe to admin realtime WebSocket events.
 *
 * @param {{
 *   routeId?: number|string|null|(() => number|string|null|undefined),
 *   onEvent: (event: string, data: any) => void
 * }} options
 */
export function useRealtime(options = {}) {
  const connected = ref(false)
  let socket = null
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

  const closeSocket = () => {
    if (socket) {
      socket.onopen = null
      socket.onmessage = null
      socket.onerror = null
      socket.onclose = null
      try {
        socket.close()
      } catch {
        // ignore
      }
      socket = null
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

  const connect = async () => {
    if (stopped) {
      return
    }

    clearReconnect()
    closeSocket()

    let ticketPayload
    try {
      ticketPayload = await fetchTicket()
    } catch {
      scheduleReconnect()
      return
    }

    const ticket = ticketPayload?.ticket
    const wsBase =
      import.meta.env.VITE_REALTIME_WS_URL || ticketPayload?.wsUrl || 'ws://127.0.0.1:8090/ws'

    if (!ticket) {
      scheduleReconnect()
      return
    }

    ticketExpiresAt = Number(ticketPayload.expiresAt) || 0

    const url = `${wsBase}${wsBase.includes('?') ? '&' : '?'}ticket=${encodeURIComponent(ticket)}`

    try {
      socket = new WebSocket(url)
    } catch {
      scheduleReconnect()
      return
    }

    socket.onopen = () => {
      connected.value = true
      backoffMs = 1000
    }

    socket.onmessage = (message) => {
      let payload
      try {
        payload = JSON.parse(message.data)
      } catch {
        return
      }
      if (!payload || typeof payload.event !== 'string') {
        return
      }
      if (payload.event === 'realtime.connected') {
        return
      }
      onEvent(payload.event, payload.data ?? null)
    }

    socket.onerror = () => {
      // onclose will handle reconnect
    }

    socket.onclose = () => {
      connected.value = false
      socket = null
      // Refresh ticket before reconnect if close to expiry
      if (ticketExpiresAt > 0 && ticketExpiresAt * 1000 - Date.now() < 5000) {
        backoffMs = Math.min(backoffMs, 1000)
      }
      scheduleReconnect()
    }
  }

  const stop = () => {
    stopped = true
    clearReconnect()
    closeSocket()
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
