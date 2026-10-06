import { computed, reactive } from "vue";
import {
  type AuthUser,
  changePasswordRequest,
  clearStoredSession,
  fetchMeRequest,
  loginRequest,
  logoutRequest,
  persistSession,
  readStoredRefreshToken,
  readStoredToken,
  readStoredUser,
  updateStoredUser,
} from "./api";

const COURIER_ROLE = "ROLE_COURIER";

const state = reactive({
  token: readStoredToken(),
  refreshToken: readStoredRefreshToken(),
  user: readStoredUser() as AuthUser | null,
  bootstrapped: false,
});

export function useAuth() {
  const isAuthenticated = computed(() => Boolean(state.token || state.refreshToken));
  const mustChangePassword = computed(() => Boolean(state.user?.mustChangePassword));
  const isCourier = computed(() => (state.user?.roles || []).includes(COURIER_ROLE));

  async function login(email: string, password: string, remember = true) {
    const data = await loginRequest(email, password, remember);
    const roles = data.user?.roles || [];

    if (!roles.includes(COURIER_ROLE)) {
      if (data.refresh_token) {
        await logoutRequest(data.refresh_token);
      }
      throw new Error("driversOnly");
    }

    state.token = data.token;
    state.refreshToken = data.refresh_token || "";
    state.user = data.user;
    persistSession(state.token, state.refreshToken, data.user, remember);

    return data.user;
  }

  async function changePassword(password: string, passwordConfirmation: string) {
    const { user } = await changePasswordRequest(password, passwordConfirmation);
    state.user = user;
    updateStoredUser(user);
    return user;
  }

  async function fetchMe() {
    if (!state.token && !state.refreshToken) {
      return null;
    }

    const { user } = await fetchMeRequest();
    if (!(user.roles || []).includes(COURIER_ROLE)) {
      await logout();
      throw new Error("driversOnly");
    }

    state.user = user;
    updateStoredUser(user);
    return user;
  }

  async function logout() {
    const currentRefreshToken = state.refreshToken || readStoredRefreshToken();
    state.token = "";
    state.refreshToken = "";
    state.user = null;
    clearStoredSession();
    await logoutRequest(currentRefreshToken);
  }

  async function bootstrap() {
    if (state.bootstrapped) {
      return;
    }

    state.bootstrapped = true;

    if (!state.token && !state.refreshToken) {
      return;
    }

    try {
      await fetchMe();
    } catch {
      await logout();
    }
  }

  return {
    state,
    isAuthenticated,
    mustChangePassword,
    isCourier,
    login,
    changePassword,
    fetchMe,
    logout,
    bootstrap,
  };
}
