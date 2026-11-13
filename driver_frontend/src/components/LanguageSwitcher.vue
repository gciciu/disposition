<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { SUPPORTED_LOCALES, setLocale, type AppLocale } from "../i18n";

const { locale, t } = useI18n();

function selectLocale(code: AppLocale) {
  setLocale(code);
}
</script>

<template>
  <div class="lang-switch" role="group" :aria-label="t('lang.switch')">
    <button
      v-for="code in SUPPORTED_LOCALES"
      :key="code"
      type="button"
      class="lang-switch__btn"
      :class="{ 'lang-switch__btn--active': locale === code }"
      :aria-label="t(`lang.${code}`)"
      :aria-pressed="locale === code"
      :title="t(`lang.${code}`)"
      @click="selectLocale(code)"
    >
      <svg
        v-if="code === 'en'"
        class="lang-switch__flag"
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
        class="lang-switch__flag"
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
