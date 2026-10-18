<script setup>
import { onMounted, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const bootstrapped = ref(false)

const updateDocumentTitle = () => {
  const titleKey = route.meta?.titleKey
  document.title = titleKey
    ? `${t(titleKey)} — ${t('app.title')}`
    : t('app.title')
}

watch([locale, () => route.meta?.titleKey], updateDocumentTitle, { immediate: true })

onMounted(async () => {
  try {
    await router.isReady()
  } finally {
    bootstrapped.value = true
  }
})
</script>

<template>
  <div
    v-if="!bootstrapped"
    class="flex min-h-screen items-center justify-center bg-gray-50 text-gray-500 dark:bg-slate-800 dark:text-slate-300"
  >
    {{ t('deliveryRoutes.loading') }}
  </div>
  <RouterView v-else />
</template>
