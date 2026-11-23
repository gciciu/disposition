import { onUnmounted, ref } from "vue";
import { fetchDriverRealtimeTicketRequest, type RealtimeTicket } from "../api";

type UseRealtimeOptions = {
  routeId?: number | null | (() => number | null | undefined);
  onEvent: (event: string, data: unknown) => void;
  onResync?: () => void;
};

/**
 * Subscribe to driver realtime Mercure (SSE) events for the open route.
 */
export function useRealtime(options: UseRealtimeOptions) {
  const connected = ref(false);
  let source: EventSource | null = null;
  let stopped = false;
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  let backoffMs = 1000;
  let ticketExpiresAt = 0;
  let hadOpenConnection = false;
  let wasHidden = false;

  const onEvent = typeof options.onEvent === "function" ? options.onEvent : () => {};
  const onResync = typeof options.onResync === "function" ? options.onResync : null;

  const resolveRouteId = (): number | null => {
    const value = typeof options.routeId === "function" ? options.routeId() : options.routeId;
    if (value == null || value === undefined) {
      return null;
    }
    const id = Number(value);
    return Number.isFinite(id) && id > 0 ? id : null;
  };

  const clearReconnect = () => {
    if (reconnectTimer != null) {
      clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }
  };

  const closeSource = () => {
    if (source) {
      source.onopen = null;
      source.onmessage = null;
      source.onerror = null;
      try {
        source.close();
      } catch {
        // ignore
      }
      source = null;
    }
    connected.value = false;
  };

  const scheduleReconnect = () => {
    if (stopped) {
      return;
    }
    clearReconnect();
    const delay = backoffMs;
    backoffMs = Math.min(backoffMs * 2, 15000);
    reconnectTimer = setTimeout(() => {
      void connect();
    }, delay);
  };

  const requestResync = () => {
    if (!onResync || stopped) {
      return;
    }
    try {
      onResync();
    } catch {
      // ignore listener errors
    }
  };

  const resolveHubBase = (ticketPayload: RealtimeTicket): string => {
    if (import.meta.env.VITE_MERCURE_URL) {
      return import.meta.env.VITE_MERCURE_URL;
    }
    // Prod: same-origin via nginx /.well-known/mercure (avoids cross-subdomain CORS).
    if (typeof window !== "undefined") {
      const host = window.location.hostname;
      if (host && host !== "localhost" && host !== "127.0.0.1") {
        return `${window.location.origin}/.well-known/mercure`;
      }
    }
    return ticketPayload.hubUrl || "http://localhost:3000/.well-known/mercure";
  };

  const buildHubUrl = (ticketPayload: RealtimeTicket) => {
    const hubBase = resolveHubBase(ticketPayload);
    const url = new URL(
      hubBase,
      typeof window !== "undefined" ? window.location.origin : undefined,
    );
    const topics = Array.isArray(ticketPayload.topics) ? ticketPayload.topics : [];
    for (const topic of topics) {
      if (typeof topic === "string" && topic.length > 0) {
        url.searchParams.append("topic", topic);
      }
    }
    if (ticketPayload.token) {
      url.searchParams.set("authorization", ticketPayload.token);
    }
    return url.toString();
  };

  const connect = async () => {
    if (stopped) {
      return;
    }

    clearReconnect();
    closeSource();

    let ticketPayload: RealtimeTicket;
    try {
      ticketPayload = await fetchDriverRealtimeTicketRequest(resolveRouteId());
    } catch {
      scheduleReconnect();
      return;
    }

    if (!ticketPayload?.token) {
      scheduleReconnect();
      return;
    }

    ticketExpiresAt = Number(ticketPayload.expiresAt) || 0;

    try {
      source = new EventSource(buildHubUrl(ticketPayload));
    } catch {
      scheduleReconnect();
      return;
    }

    source.onopen = () => {
      connected.value = true;
      backoffMs = 1000;
      if (hadOpenConnection) {
        requestResync();
      }
      hadOpenConnection = true;
    };

    source.onmessage = (message) => {
      let payload: { event?: string; data?: unknown };
      try {
        payload = JSON.parse(message.data) as { event?: string; data?: unknown };
      } catch {
        return;
      }
      if (!payload || typeof payload.event !== "string") {
        return;
      }
      onEvent(payload.event, payload.data ?? null);
    };

    source.onerror = () => {
      connected.value = false;
      closeSource();
      if (ticketExpiresAt > 0 && ticketExpiresAt * 1000 - Date.now() < 5000) {
        backoffMs = Math.min(backoffMs, 1000);
      }
      scheduleReconnect();
    };
  };

  const onVisibilityChange = () => {
    if (typeof document === "undefined") {
      return;
    }
    if (document.hidden) {
      wasHidden = true;
      return;
    }
    if (wasHidden && !stopped) {
      wasHidden = false;
      requestResync();
    }
  };

  const stop = () => {
    stopped = true;
    clearReconnect();
    closeSource();
    if (typeof document !== "undefined") {
      document.removeEventListener("visibilitychange", onVisibilityChange);
    }
  };

  const start = () => {
    stopped = false;
    backoffMs = 1000;
    hadOpenConnection = false;
    wasHidden = false;
    if (typeof document !== "undefined") {
      document.removeEventListener("visibilitychange", onVisibilityChange);
      document.addEventListener("visibilitychange", onVisibilityChange);
    }
    void connect();
  };

  onUnmounted(stop);

  return {
    connected,
    start,
    stop,
  };
}
