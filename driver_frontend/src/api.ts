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

const API_BASE_URL = import.meta.env.VITE_API_URL || "http://localhost:8080";

const AUTH_KEYS = ["auth_token", "auth_user", "refresh_token"] as const;

type JsonBody = Record<string, unknown>;

export class ApiError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = "ApiError";
    this.status = status;
  }
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

async function parseError(response: Response): Promise<string> {
  try {
    const data = (await response.json()) as {
      message?: string;
      error?: string;
      detail?: string;
    };
    return data.message || data.error || data.detail || "Ошибка запроса";
  } catch {
    return "Ошибка запроса";
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  if (!headers.has("Accept")) {
    headers.set("Accept", "application/json");
  }
  if (init.body && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  const token = readStoredToken();
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    headers,
  });

  if (!response.ok) {
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
