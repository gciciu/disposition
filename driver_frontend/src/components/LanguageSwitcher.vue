<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { SUPPORTED_LOCALES, setLocale, type AppLocale } from "../i18n";

const { locale, t } = useI18n();

const currentLocale = computed({
  get: () => locale.value as AppLocale,
  set: (value: AppLocale) => setLocale(value),
});
</script>

<template>
  <label class="lang-switch" :title="t('lang.switch')">
    <span class="sr-only">{{ t("lang.switch") }}</span>
    <select v-model="currentLocale" class="lang-switch__select" :aria-label="t('lang.switch')">
      <option v-for="code in SUPPORTED_LOCALES" :key="code" :value="code">
        {{ t(`lang.${code}`) }}
      </option>
    </select>
  </label>
</template>
