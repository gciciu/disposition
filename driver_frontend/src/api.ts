export type AuthUser = {
  id: number | null;
  email: string;
  name: string;
  roles: string[];
  isActive: boolean;
  mustChangePassword: boolean;
};

export type LoginResponse = {
  token: string;
  refresh_token: string;
  user: AuthUser;
};

// Prefer 127.0.0.1 over localhost — on Windows localhost can resolve to ::1 while
// `php -S 127.0.0.1:8080` only accepts IPv4, which breaks login/refresh.
const API_BASE_URL =
  import.meta.env.VITE_API_URL || (import.meta.env.DEV ? "http://127.0.0.1:8080" : "");

const AUTH_KEYS = ["auth_token", "auth_user", "refresh_token"] as const;

type JsonBody = Record<string, unknown>;

export class ApiError extends Error {
  status: number;
  isSessionExpired: boolean;

  constructor(message: string, status: number, isSessionExpired = false) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.isSessionExpired = isSessionExpired;
  }
}

type SessionHandlers = {
  onExpired?: (() => void) | null;
  onTokens?: ((data: LoginResponse) => void) | null;
};

let onExpired: (() => void) | null = null;
let onTokens: ((data: LoginResponse) => void) | null = null;
let refreshPromise: Promise<string> | null = null;

export function setSessionHandlers(handlers: SessionHandlers) {
  onExpired = handlers.onExpired || null;
  onTokens = handlers.onTokens || null;
}

export function isUnauthorizedError(error: unknown): boolean {
  return error instanceof ApiError && (error.isSessionExpired || error.status === 401);
}

function isPublicAuthPath(path: string): boolean {
  return (
    path.includes("/api/login") ||
    path.includes("/api/token/refresh") ||
    path.includes("/api/token/logout")
  );
}

function expireSession(): void {
  clearStoredSession();
  onExpired?.();
}

function activeStorage(): Storage {
  if (localStorage.getItem("auth_token") || localStorage.getItem("refresh_token")) {
    return localStorage;
  }
  if (sessionStorage.getItem("auth_token") || sessionStorage.getItem("refresh_token")) {
    return sessionStorage;
  }
  return localStorage;
}

export function clearStoredSession(): void {
  for (const storage of [localStorage, sessionStorage]) {
    for (const key of AUTH_KEYS) {
      storage.removeItem(key);
    }
  }
}

export function readStoredToken(): string {
  return localStorage.getItem("auth_token") || sessionStorage.getItem("auth_token") || "";
}

export function readStoredRefreshToken(): string {
  return localStorage.getItem("refresh_token") || sessionStorage.getItem("refresh_token") || "";
}

export function readStoredUser(): AuthUser | null {
  try {
    const raw = localStorage.getItem("auth_user") || sessionStorage.getItem("auth_user");
    return raw ? (JSON.parse(raw) as AuthUser) : null;
  } catch {
    return null;
  }
}

export function persistSession(
  token: string,
  refreshToken: string,
  user: AuthUser,
  remember: boolean,
): void {
  const storage = remember ? localStorage : sessionStorage;
  const other = remember ? sessionStorage : localStorage;

  for (const key of AUTH_KEYS) {
    other.removeItem(key);
  }

  storage.setItem("auth_token", token);
  storage.setItem("refresh_token", refreshToken);
  storage.setItem("auth_user", JSON.stringify(user));
}

export function updateStoredUser(user: AuthUser): void {
  const storage = activeStorage();
  storage.setItem("auth_user", JSON.stringify(user));
}

function storageWithRefreshToken(): Storage {
  if (localStorage.getItem("refresh_token")) {
    return localStorage;
  }
  if (sessionStorage.getItem("refresh_token")) {
    return sessionStorage;
  }
  return localStorage;
}

function persistRefreshedSession(data: LoginResponse): void {
  const storage = storageWithRefreshToken();
  const other = storage === localStorage ? sessionStorage : localStorage;

  for (const key of AUTH_KEYS) {
    other.removeItem(key);
  }

  storage.setItem("auth_token", data.token);
  storage.setItem("refresh_token", data.refresh_token);
  if (data.user) {
    storage.setItem("auth_user", JSON.stringify(data.user));
  }

  onTokens?.(data);
}

function isAccessTokenExpired(token: string): boolean {
  try {
    let part = token.split(".")[1].replace(/-/g, "+").replace(/_/g, "/");
    const pad = part.length % 4;
    if (pad) {
      part += "=".repeat(4 - pad);
    }
    const json = decodeURIComponent(
      atob(part)
        .split("")
        .map((char) => `%${char.charCodeAt(0).toString(16).padStart(2, "0")}`)
        .join(""),
    );
    const payload = JSON.parse(json) as { exp?: number };
    return !payload?.exp || payload.exp * 1000 <= Date.now() + 5000;
  } catch {
    return true;
  }
}

async function refreshAccessToken(): Promise<string> {
  const refreshToken = readStoredRefreshToken();
  if (!refreshToken) {
    throw new Error("missing refresh token");
  }

  const response = await fetch(`${API_BASE_URL}/api/token/refresh`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ refresh_token: refreshToken }),
  });

  if (!response.ok) {
    throw new ApiError(await parseError(response), response.status, response.status === 401);
  }

  const data = (await response.json()) as LoginResponse;
  if (!data?.token || !data?.refresh_token) {
    throw new Error("invalid refresh response");
  }

  persistRefreshedSession(data);
  return data.token;
}

function refreshOnce(): Promise<string> {
  if (!refreshPromise) {
    refreshPromise = refreshAccessToken().finally(() => {
      refreshPromise = null;
    });
  }
  return refreshPromise;
}

export async function ensureFreshAccessToken(): Promise<void> {
  const token = readStoredToken();
  if (token && !isAccessTokenExpired(token)) {
    return;
  }
  if (!readStoredRefreshToken()) {
    throw new ApiError("Session expired", 401, true);
  }

  try {
    await refreshOnce();
  } catch (error) {
    expireSession();
    throw error;
  }
}

async function parseError(response: Response): Promise<string> {
  try {
    const data = (await response.json()) as {
      message?: string;
      error?: string;
      detail?: string;
    };
    return data.message || data.error || data.detail || "Request failed";
  } catch {
    return "Request failed";
  }
}

function applyAuthHeaders(headers: Headers, token: string): void {
  // Send both: some PHP SAPIs strip Authorization ("JWT Token not found").
  // X-Delivery-Authorization is restored in backend public/index.php.
  const value = `Bearer ${token}`;
  headers.set("Authorization", value);
  headers.set("X-Delivery-Authorization", value);
}

async function request<T>(
  path: string,
  init: RequestInit = {},
  allowRefresh = true,
): Promise<T> {
  const headers = new Headers(init.headers);
  if (!headers.has("Accept")) {
    headers.set("Accept", "application/json");
  }
  const isFormData = typeof FormData !== "undefined" && init.body instanceof FormData;
  if (init.body && !isFormData && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  const token = readStoredToken();
  if (token) {
    applyAuthHeaders(headers, token);
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    headers,
  });

  if (!response.ok) {
    if (
      response.status === 401 &&
      allowRefresh &&
      !isPublicAuthPath(path) &&
      readStoredRefreshToken()
    ) {
      try {
        const nextToken = await refreshOnce();
        const retryHeaders = new Headers(init.headers);
        if (!retryHeaders.has("Accept")) {
          retryHeaders.set("Accept", "application/json");
        }
        if (init.body && !isFormData && !retryHeaders.has("Content-Type")) {
          retryHeaders.set("Content-Type", "application/json");
        }
        applyAuthHeaders(retryHeaders, nextToken);
        return request(path, { ...init, headers: retryHeaders }, false);
      } catch {
        expireSession();
        throw new ApiError("Session expired", 401, true);
      }
    }

    if (response.status === 401 && !isPublicAuthPath(path)) {
      expireSession();
      throw new ApiError("Session expired", 401, true);
    }
    throw new ApiError(await parseError(response), response.status);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

export function loginRequest(email: string, password: string, remember: boolean) {
  return request<LoginResponse>("/api/login", {
    method: "POST",
    body: JSON.stringify({ email, password, remember }),
  });
}

export function fetchMeRequest() {
  return request<{ user: AuthUser }>("/api/me");
}

export function changePasswordRequest(password: string, passwordConfirmation: string) {
  return request<{ user: AuthUser }>("/api/me/password", {
    method: "POST",
    body: JSON.stringify({
      password,
      password_confirmation: passwordConfirmation,
    } satisfies JsonBody),
  });
}

export function logoutRequest(refreshToken: string) {
  if (!refreshToken) {
    return Promise.resolve();
  }

  return request<void>("/api/token/logout", {
    method: "POST",
    body: JSON.stringify({ refresh_token: refreshToken }),
  }).catch(() => undefined);
}

export type RouteCourier = {
  id: number | null;
  name: string;
  login: string;
};

export type RouteOriginType = "warehouse" | "courier_location" | "none";

export type RouteListItem = {
  id: number;
  name: string;
  createdAt: string;
  scheduledDate: string | null;
  scheduledStartTime: string | null;
  orderCount: number;
  totalDurationText: string | null;
  totalDistanceText: string | null;
  mapsUrl: string | null;
  courier: RouteCourier | null;
  originType: RouteOriginType;
  startedAt: string | null;
  completedAt: string | null;
  hasCompletionPdf?: boolean;
  allOrdersFinal?: boolean;
  delayMinutes?: number;
};

export type OrderStatus = "pending" | "delivered" | "refused";
export type ProofType = "photo" | "signature";

export type RouteOrder = {
  id: number;
  position: number;
  clientName: string;
  phone: string | null;
  address: string;
  formattedAddress: string | null;
  addressValid: boolean;
  products: string[];
  productBackgroundColors?: (string | null)[] | null;
  deliveredProductIndexes: number[] | null;
  accessoryProductIndexes?: number[] | null;
  travelDurationText: string | null;
  travelDistanceText: string | null;
  status: OrderStatus;
  isExchange?: boolean;
  completedAt: string | null;
  proofType: ProofType | null;
  hasProof: boolean;
  courierComment?: string | null;
};

export type RouteDetail = RouteListItem & {
  orders: RouteOrder[];
};

export function fetchMyRoutesRequest() {
  return request<{ routes: RouteListItem[] }>("/api/driver/routes");
}

export function fetchMyRouteRequest(id: number) {
  return request<{ route: RouteDetail }>(`/api/driver/routes/${id}`);
}

export function startRouteRequest(id: number, coords?: { lat: number; lng: number }) {
  return request<{ route: RouteDetail }>(`/api/driver/routes/${id}/start`, {
    method: "POST",
    body: JSON.stringify(coords ?? {}),
  });
}

export function finishRouteRequest(id: number) {
  return request<{ route: RouteDetail }>(`/api/driver/routes/${id}/finish`, {
    method: "POST",
  });
}

export function continueRouteRequest(id: number) {
  return request<{ route: RouteDetail }>(`/api/driver/routes/${id}/continue`, {
    method: "POST",
  });
}

export function completeOrderRequest(
  routeId: number,
  orderId: number,
  proofType: ProofType,
  file: Blob,
  filename = "proof.jpg",
  deliveredProductIndexes?: number[],
  courierComment?: string | null,
) {
  const body = new FormData();
  body.append("proofType", proofType);
  body.append("file", file, filename);
  if (deliveredProductIndexes !== undefined) {
    body.append("deliveredProductIndexes", JSON.stringify(deliveredProductIndexes));
  }
  if (courierComment != null && courierComment.trim() !== "") {
    body.append("courierComment", courierComment.trim());
  }

  return request<{ order: RouteOrder; route: RouteDetail }>(
    `/api/driver/routes/${routeId}/orders/${orderId}/complete`,
    {
      method: "POST",
      body,
    },
  );
}

export function refuseOrderRequest(
  routeId: number,
  orderId: number,
  courierComment?: string | null,
) {
  const payload: { courierComment?: string } = {};
  if (courierComment != null && courierComment.trim() !== "") {
    payload.courierComment = courierComment.trim();
  }

  return request<{ order: RouteOrder; route: RouteDetail }>(
    `/api/driver/routes/${routeId}/orders/${orderId}/refuse`,
    {
      method: "POST",
      body: JSON.stringify(payload),
    },
  );
}

export function reportDelayRequest(routeId: number, minutes: number) {
  return request<{ route: RouteDetail }>(`/api/driver/routes/${routeId}/delay`, {
    method: "POST",
    body: JSON.stringify({ minutes }),
  });
}
