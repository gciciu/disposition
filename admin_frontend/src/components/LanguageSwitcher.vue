<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { mdiTranslate } from '@mdi/js'
import { SUPPORTED_LOCALES, setLocale } from '@/i18n'
import BaseIcon from '@/components/BaseIcon.vue'

const { locale, t } = useI18n()

const currentLocale = computed({
  get: () => locale.value,
  set: (value) => setLocale(value),
})
</script>

<template>
  <label
    class="flex items-center gap-2 px-3 py-2 text-sm text-gray-500 dark:text-slate-400"
    :title="t('lang.switch')"
  >
    <BaseIcon :path="mdiTranslate" :size="18" class="hidden sm:inline-flex" />
    <select
      v-model="currentLocale"
      class="cursor-pointer rounded-md border-0 bg-transparent py-1 pr-6 pl-1 text-sm font-medium text-gray-700 focus:ring-0 dark:text-slate-200"
      :aria-label="t('lang.switch')"
    >
      <option v-for="code in SUPPORTED_LOCALES" :key="code" :value="code">
        {{ t(`lang.${code}`) }}
      </option>
    </select>
  </label>
</template>
