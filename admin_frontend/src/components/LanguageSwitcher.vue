<script setup>
import { useI18n } from 'vue-i18n'
import { SUPPORTED_LOCALES, setLocale } from '@/i18n'

const { locale, t } = useI18n()

function selectLocale(code) {
  setLocale(code)
}
</script>

<template>
  <div class="lang-flags" role="group" :aria-label="t('lang.switch')">
    <button
      v-for="code in SUPPORTED_LOCALES"
      :key="code"
      type="button"
      class="lang-flags__btn"
      :class="{ 'lang-flags__btn--active': locale === code }"
      :aria-label="t(`lang.${code}`)"
      :aria-pressed="locale === code"
      :title="t(`lang.${code}`)"
      @click="selectLocale(code)"
    >
      <svg
        v-if="code === 'en'"
        class="lang-flags__icon"
        viewBox="0 0 60 30"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
      >
        <rect width="60" height="30" fill="#012169" />
        <path d="M0 0l60 30M60 0L0 30" stroke="#fff" stroke-width="6" />
        <path d="M0 0l60 30M60 0L0 30" stroke="#C8102E" stroke-width="2" />
        <path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10" />
        <path d="M30 0v30M0 15h60" stroke="#C8102E" stroke-width="6" />
      </svg>
      <svg
        v-else-if="code === 'de'"
        class="lang-flags__icon"
        viewBox="0 0 5 3"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
      >
        <rect width="5" height="1" y="0" fill="#000" />
        <rect width="5" height="1" y="1" fill="#D00" />
        <rect width="5" height="1" y="2" fill="#FFCE00" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.lang-flags {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.2rem;
  border-radius: 0.6rem;
}

.lang-flags__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 1.5rem;
  padding: 0;
  border: 1px solid transparent;
  border-radius: 0.35rem;
  background: transparent;
  cursor: pointer;
  opacity: 0.55;
  transition:
    opacity 0.15s ease,
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.lang-flags__btn:hover {
  opacity: 0.9;
}

.lang-flags__btn--active {
  opacity: 1;
  border-color: rgb(148 163 184 / 0.7);
  box-shadow: 0 0 0 1px rgb(148 163 184 / 0.35);
}

.lang-flags__icon {
  display: block;
  width: 1.35rem;
  height: 0.9rem;
  border-radius: 0.12rem;
  box-shadow: 0 0 0 1px rgb(15 23 42 / 0.12);
  overflow: hidden;
}
</style>
