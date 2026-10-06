<script setup>
import { watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()
const route = useRoute()

const updateDocumentTitle = () => {
  const titleKey = route.meta?.titleKey
  document.title = titleKey
    ? `${t(titleKey)} — ${t('app.title')}`
    : t('app.title')
}

watch([locale, () => route.meta?.titleKey], updateDocumentTitle, { immediate: true })
</script>

<template>
  <RouterView />
</template>
