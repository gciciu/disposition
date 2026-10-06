<script setup>
import { useI18n } from 'vue-i18n'
import { containerMaxW } from '@/config.js'
import BaseLevel from '@/components/BaseLevel.vue'

const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="px-6 py-2" :class="containerMaxW">
    <BaseLevel>
      <div class="flex flex-col items-center justify-center gap-6 lg:flex-row lg:justify-start">
        <b>&copy;{{ year }} {{ t('app.title') }}</b>
        <slot />
      </div>
    </BaseLevel>
  </footer>
</template>
