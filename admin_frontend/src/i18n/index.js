import { createI18n } from 'vue-i18n'
import en from '@/locales/en.json'
import de from '@/locales/de.json'

export const SUPPORTED_LOCALES = ['en', 'de']
export const DEFAULT_LOCALE = 'en'
export const LOCALE_STORAGE_KEY = 'locale'

export function getStoredLocale() {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
  if (SUPPORTED_LOCALES.includes(stored)) {
    return stored
  }
  return DEFAULT_LOCALE
}

export function setStoredLocale(locale) {
  if (!SUPPORTED_LOCALES.includes(locale)) {
    return
  }
  localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  document.documentElement.lang = locale
}

const i18n = createI18n({
  legacy: false,
  locale: getStoredLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: { en, de },
})

document.documentElement.lang = i18n.global.locale.value

export function setLocale(locale) {
  if (!SUPPORTED_LOCALES.includes(locale)) {
    return
  }
  i18n.global.locale.value = locale
  setStoredLocale(locale)
}

export default i18n
