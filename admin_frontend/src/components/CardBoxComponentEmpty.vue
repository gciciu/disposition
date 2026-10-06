<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <div class="py-24 text-center text-gray-500 dark:text-slate-400">
    <p>{{ t('common.empty') }}</p>
  </div>
</template>
