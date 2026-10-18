<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api.js'
import OverlayLayer from '@/components/OverlayLayer.vue'

const props = defineProps({
  routeId: {
    type: [Number, String],
    required: true,
  },
  orderId: {
    type: [Number, String],
    required: true,
  },
  proofType: {
    type: String,
    default: null,
  },
})

const { t } = useI18n()

const objectUrl = ref('')
const loading = ref(false)
const failed = ref(false)
const previewOpen = ref(false)

const revoke = () => {
  if (objectUrl.value) {
    URL.revokeObjectURL(objectUrl.value)
    objectUrl.value = ''
  }
}

const loadProof = async () => {
  revoke()
  failed.value = false
  loading.value = true

  try {
    const { data } = await api.get(
      `/api/admin/routes/${props.routeId}/orders/${props.orderId}/proof`,
      { responseType: 'blob' },
    )
    objectUrl.value = URL.createObjectURL(data)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

const openPreview = () => {
  if (!objectUrl.value) {
    return
  }
  previewOpen.value = true
}

const closePreview = () => {
  previewOpen.value = false
}

const onKeydown = (event) => {
  if (event.key === 'Escape' && previewOpen.value) {
    closePreview()
  }
}

watch(
  () => [props.routeId, props.orderId],
  () => {
    loadProof()
  },
  { immediate: true },
)

watch(previewOpen, (open) => {
  if (open) {
    window.addEventListener('keydown', onKeydown)
  } else {
    window.removeEventListener('keydown', onKeydown)
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  revoke()
})
</script>

<template>
  <div class="inline-flex items-center justify-center">
    <div
      v-if="loading"
      class="h-14 w-14 animate-pulse rounded border border-gray-200 bg-gray-100 dark:border-slate-700 dark:bg-slate-800"
      :title="t('deliveryRoutes.proofLoading')"
    />
    <div
      v-else-if="failed"
      class="flex h-14 w-14 items-center justify-center rounded border border-dashed border-gray-300 text-center text-[10px] leading-tight text-gray-400 dark:border-slate-600"
    >
      {{ t('deliveryRoutes.proofMissing') }}
    </div>
    <button
      v-else-if="objectUrl"
      type="button"
      class="group relative h-14 w-14 overflow-hidden rounded border border-gray-200 bg-white p-0 transition hover:border-blue-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-slate-700 dark:bg-slate-900"
      :title="t('deliveryRoutes.proofEnlarge')"
      @click="openPreview"
    >
      <img
        :src="objectUrl"
        :alt="
          proofType === 'signature'
            ? t('deliveryRoutes.proofSignature')
            : t('deliveryRoutes.proofPhoto')
        "
        class="h-full w-full object-cover"
        :class="proofType === 'signature' ? 'object-contain bg-white p-0.5' : ''"
      />
      <span
        class="pointer-events-none absolute inset-x-0 bottom-0 bg-black/50 px-0.5 py-0.5 text-center text-[9px] font-medium tracking-wide text-white opacity-0 transition group-hover:opacity-100"
      >
        {{
          proofType === 'signature'
            ? t('deliveryRoutes.proofSignature')
            : t('deliveryRoutes.proofPhoto')
        }}
      </span>
    </button>
    <span v-else class="text-sm text-gray-400">—</span>
  </div>

  <Teleport to="body">
    <OverlayLayer v-if="previewOpen && objectUrl" @overlay-click="closePreview">
      <div class="relative z-50 flex max-h-[90vh] max-w-[min(96vw,56rem)] flex-col items-center gap-3 p-4">
        <img
          :src="objectUrl"
          :alt="
            proofType === 'signature'
              ? t('deliveryRoutes.proofSignature')
              : t('deliveryRoutes.proofPhoto')
          "
          class="max-h-[80vh] max-w-full rounded-lg bg-white object-contain shadow-2xl"
          @click.stop
        />
        <button
          type="button"
          class="rounded bg-white/95 px-3 py-1.5 text-sm font-medium text-gray-800 shadow hover:bg-white"
          @click="closePreview"
        >
          {{ t('deliveryRoutes.proofClose') }}
        </button>
      </div>
    </OverlayLayer>
  </Teleport>
</template>
