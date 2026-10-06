import { createI18n } from "vue-i18n";
import en from "./locales/en.json";
import de from "./locales/de.json";

export const SUPPORTED_LOCALES = ["en", "de"] as const;
export type AppLocale = (typeof SUPPORTED_LOCALES)[number];
export const DEFAULT_LOCALE: AppLocale = "en";
export const LOCALE_STORAGE_KEY = "driver_locale";

export function isAppLocale(value: string | null): value is AppLocale {
  return value === "en" || value === "de";
}

export function getStoredLocale(): AppLocale {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
  if (isAppLocale(stored)) {
    return stored;
  }
  return DEFAULT_LOCALE;
}

export function setStoredLocale(locale: AppLocale): void {
  localStorage.setItem(LOCALE_STORAGE_KEY, locale);
  document.documentElement.lang = locale;
}

const i18n = createI18n({
  legacy: false,
  locale: getStoredLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: { en, de },
});

document.documentElement.lang = i18n.global.locale.value;

export function setLocale(locale: AppLocale): void {
  i18n.global.locale.value = locale;
  setStoredLocale(locale);
}

export function translateApiError(message: string, t: (key: string) => string): string {
  const normalized = message.trim().toLowerCase();

  if (
    normalized.includes("неверный логин") ||
    normalized.includes("invalid login") ||
    normalized.includes("invalid credentials")
  ) {
    return t("errors.invalidCredentials");
  }

  if (normalized.includes("отключена") || normalized.includes("disabled")) {
    return t("errors.accountDisabled");
  }

  if (
    normalized.includes("пароли не совпадают") ||
    normalized.includes("passwords do not match")
  ) {
    return t("password.mismatch");
  }

  if (
    normalized.includes("не короче 8") ||
    normalized.includes("at least 8") ||
    normalized.includes("mindestens 8")
  ) {
    return t("password.tooShort");
  }

  if (
    normalized.includes("укажите новый пароль") ||
    normalized.includes("new password")
  ) {
    return t("password.tooShort");
  }

  return message || t("login.failed");
}

export default i18n;
