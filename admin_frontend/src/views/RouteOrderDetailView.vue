<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  mdiRoutes,
  mdiArrowLeft,
  mdiAlertCircle,
  mdiCheckCircle,
  mdiLoading,
  mdiCalculator,
} from '@mdi/js'
import { useToast } from 'vue-toastification'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import DeliveryProofThumb from '@/components/DeliveryProofThumb.vue'
import OrderCommentsModal from '@/components/OrderCommentsModal.vue'
import api from '@/api.js'
import { useRealtime } from '@/composables/useRealtime.js'
import { withoutCountry } from '@/utils/address.js'
import { commentRowClass } from '@/utils/commentColors.js'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const routeDetail = ref(null)
const loading = ref(false)
const unloadDraft = ref(null)
const applyingUnload = ref(false)
const commentsModalOpen = ref(false)

const UNLOAD_MIN = 1
const UNLOAD_MAX = 60
const UNLOAD_DEFAULT = 10

const order = computed(() => {
  const orderId = Number(route.params.orderId)
  if (!routeDetail.value?.orders || Number.isNaN(orderId)) {
    return null
  }
  return routeDetail.value.orders.find((item) => item.id === orderId) ?? null
})

const title = computed(() => {
  if (!order.value) {
    return t('deliveryRoutes.orderDetailTitle')
  }
  return t('deliveryRoutes.orderDetailTitleWithPosition', {
    position: order.value.position,
    client: order.value.clientName,
  })
})

const statusLabel = computed(() => {
  if (!order.value) {
    return '—'
  }
  if (order.value.status === 'refused') {
    return t('deliveryRoutes.statusRefused')
  }
  if (isPartialDelivery(order.value)) {
    return t('deliveryRoutes.statusPartial')
  }
  if (order.value.status === 'delivered') {
    return t('deliveryRoutes.statusDelivered')
  }
  return t('deliveryRoutes.statusPending')
})

const routeDelayMinutes = computed(() => Number(routeDetail.value?.delayMinutes) || 0)
const nextPendingOrderId = computed(() => {
  const orders = routeDetail.value?.orders
  if (!Array.isArray(orders)) {
    return null
  }
  const next = orders.find((item) => item.status === 'pending')
  return next?.id ?? null
})
const showDelayOnOrder = computed(
  () =>
    routeDelayMinutes.value > 0 &&
    order.value?.id != null &&
    order.value.id === nextPendingOrderId.value &&
    order.value.status === 'pending',
)

const displayAddress = (item) => withoutCountry(item.formattedAddress || item.address)

const accessoryIndexSet = (item) =>
  new Set(
    (item?.accessoryProductIndexes ?? [])
      .map((index) => Number(index))
      .filter((index) => Number.isInteger(index) && index >= 0),
  )

const regularProducts = computed(() => {
  const products = order.value?.products ?? []
  const accessories = accessoryIndexSet(order.value)
  return products
    .map((product, index) => ({ product, index }))
    .filter(({ index }) => !accessories.has(index))
})

const accessoryProducts = computed(() => {
  const products = order.value?.products ?? []
  const accessories = accessoryIndexSet(order.value)
  return products
    .map((product, index) => ({ product, index }))
    .filter(({ index }) => accessories.has(index))
})

const isProductDelivered = (item, index) => {
  if (item.status !== 'delivered') {
    return false
  }
  // Legacy completions before per-product tracking: treat all as delivered.
  if (item.deliveredProductIndexes == null) {
    return true
  }
  return item.deliveredProductIndexes.includes(index)
}

/** Delivered stop where at least one product was not handed over. */
const isPartialDelivery = (item) => {
  if (item.status !== 'delivered') {
    return false
  }
  const products = item.products ?? []
  if (products.length === 0 || item.deliveredProductIndexes == null) {
    return false
  }
  return products.some((_, index) => !isProductDelivered(item, index))
}

const formatDeliveryParts = (value) => {
  if (!value) {
    return null
  }
  try {
    const date = new Date(value)
    return {
      date: date.toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
      }),
      time: date.toLocaleTimeString('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
      }),
    }
  } catch {
    return null
  }
}

const weightLabel = computed(() => {
  if (order.value?.weightKg == null) {
    return '—'
  }
  return t('deliveryRoutes.weightValue', {
    value: Number(order.value.weightKg).toLocaleString(locale.value, {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }),
  })
})

const completedParts = computed(() => formatDeliveryParts(order.value?.completedAt))

const savedUnloadMinutes = () => order.value?.unloadingDurationMinutes ?? UNLOAD_DEFAULT

const isUnloadDirty = computed(() => {
  if (!order.value || unloadDraft.value == null) {
    return false
  }
  return Number(unloadDraft.value) !== Number(savedUnloadMinutes())
})

const clampUnloadMinutes = (value) => {
  const parsed = Number.parseInt(String(value), 10)
  if (Number.isNaN(parsed)) {
    return UNLOAD_DEFAULT
  }
  return Math.min(UNLOAD_MAX, Math.max(UNLOAD_MIN, parsed))
}

const syncUnloadDraft = ({ preserveDirty = false } = {}) => {
  if (!order.value) {
    unloadDraft.value = null
    return
  }
  if (preserveDirty && isUnloadDirty.value && unloadDraft.value != null) {
    return
  }
  unloadDraft.value = savedUnloadMinutes()
}

const onUnloadDraftInput = (event) => {
  unloadDraft.value = event.target.value
}

const openCommentsModal = () => {
  commentsModalOpen.value = true
}

const onCommentsSaved = (routeData) => {
  if (!routeData) {
    return
  }
  routeDetail.value = routeData
  syncUnloadDraft({ preserveDirty: true })
}

const isOrderFinished = computed(
  () => order.value?.status === 'delivered' || order.value?.status === 'refused',
)

const applyUnload = async () => {
  if (
    !routeDetail.value?.id ||
    !order.value?.id ||
    applyingUnload.value ||
    isOrderFinished.value
  ) {
    return
  }

  const minutes = clampUnloadMinutes(unloadDraft.value)
  unloadDraft.value = minutes

  if (minutes === Number(savedUnloadMinutes())) {
    return
  }

  applyingUnload.value = true
  try {
    const { data } = await api.put(`/api/admin/routes/${routeDetail.value.id}`, {
      orders: [{ id: order.value.id, unloadingDurationMinutes: minutes }],
    })
    routeDetail.value = data.route
    syncUnloadDraft()
    toast.success(t('deliveryRoutes.unloadApplied'))
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.unloadApplyFailed'))
  } finally {
    applyingUnload.value = false
  }
}

const productBackgroundStyle = (item, index) => {
  const color = item?.productBackgroundColors?.[index]
  if (!color || typeof color !== 'string') {
    return undefined
  }
  return { backgroundColor: color }
}

const loadRoute = async () => {
  loading.value = true
  routeDetail.value = null
  unloadDraft.value = null
  try {
    const { data } = await api.get(`/api/admin/routes/${route.params.id}`)
    routeDetail.value = data.route
    if (!order.value) {
      toast.error(t('deliveryRoutes.loadOrderFailed'))
    } else {
      syncUnloadDraft()
    }
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.loadDetailFailed'))
  } finally {
    loading.value = false
  }
}

const backToRoute = () => {
  router.push({ name: 'route-detail', params: { id: route.params.id } })
}

const applyRealtimePayload = (event, data) => {
  if (!routeDetail.value || !data?.route) {
    return
  }
  if (data.route.id != null && data.route.id !== routeDetail.value.id) {
    return
  }

  if (
    event === 'route.started' ||
    event === 'route.completed' ||
    event === 'route.continued' ||
    event === 'route.delayed'
  ) {
    routeDetail.value = data.route
    syncUnloadDraft({ preserveDirty: true })
    return
  }

  if (event === 'order.completed' || event === 'order.refused') {
    const next = { ...data.route }
    if (data.order?.id != null && Array.isArray(next.orders)) {
      const orderIndex = next.orders.findIndex((item) => item.id === data.order.id)
      if (orderIndex !== -1) {
        next.orders = [...next.orders]
        next.orders[orderIndex] = data.order
      }
    }
    routeDetail.value = next
    syncUnloadDraft({ preserveDirty: true })
  }
}

const { start: startRealtime, stop: stopRealtime } = useRealtime({
  routeId: () => route.params.id,
  onEvent: applyRealtimePayload,
})

onMounted(async () => {
  await loadRoute()
  startRealtime()
})

watch(
  () => [route.params.id, route.params.orderId],
  async () => {
    stopRealtime()
    await loadRoute()
    startRealtime()
  },
)

watch(
  () => order.value?.id,
  () => {
    syncUnloadDraft()
  },
)
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiRoutes" :title="title" main>
        <BaseButton
          :label="t('deliveryRoutes.backToRoute')"
          :icon="mdiArrowLeft"
          color="whiteDark"
          @click="backToRoute"
        />
      </SectionTitleLineWithButton>

      <CardBox v-if="loading" class="mb-6">
        <div class="flex items-center gap-2 p-4 text-gray-500">
          <BaseIcon :path="mdiLoading" class="animate-spin" size="20" />
          {{ t('deliveryRoutes.loading') }}
        </div>
      </CardBox>

      <template v-else-if="order && routeDetail">
        <CardBox class="mb-6">
          <div class="mb-4 flex flex-wrap items-center gap-3">
            <span
              class="inline-flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold"
              :class="{
                'bg-yellow-500 text-white': isPartialDelivery(order),
                'bg-emerald-600 text-white':
                  order.status === 'delivered' && !isPartialDelivery(order),
                'bg-red-600 text-white': order.status === 'refused',
                'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-200':
                  order.status !== 'delivered' && order.status !== 'refused',
              }"
            >
              {{ order.position }}
            </span>
            <div>
              <div class="text-lg font-semibold">
                {{ order.clientName }}
                <span
                  v-if="order.isExchange"
                  class="ml-2 align-middle text-xs font-bold uppercase text-violet-700 dark:text-violet-300"
                >
                  {{ t('deliveryRoutes.statusExchange') }}
                </span>
              </div>
              <div
                class="text-sm font-bold uppercase"
                :class="{
                  'text-yellow-600': isPartialDelivery(order),
                  'text-emerald-600':
                    order.status === 'delivered' && !isPartialDelivery(order),
                  'text-red-600': order.status === 'refused',
                  'text-gray-500':
                    order.status !== 'delivered' && order.status !== 'refused',
                }"
              >
                {{ statusLabel }}
              </div>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.phone') }}</div>
              <div>{{ order.phone || '—' }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.weight') }}</div>
              <div>{{ weightLabel }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.arrivalTime') }}</div>
              <div>{{ order.arrivalTime || '—' }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 uppercase">
                {{ t('deliveryRoutes.unloadTime') }}
                <span class="ml-1 font-normal normal-case">
                  {{ t('deliveryRoutes.unloadMinutesUnit') }}
                </span>
              </div>
              <div class="mt-1 flex items-center gap-0.5">
                <input
                  class="w-12 rounded border border-gray-300 px-1.5 py-1 text-sm dark:border-slate-600 dark:bg-slate-800 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 dark:disabled:bg-slate-900 dark:disabled:text-slate-500"
                  type="number"
                  :min="UNLOAD_MIN"
                  :max="UNLOAD_MAX"
                  :value="unloadDraft ?? savedUnloadMinutes()"
                  :disabled="isOrderFinished || applyingUnload"
                  :aria-label="`${t('deliveryRoutes.unloadTime')} (${t('deliveryRoutes.unloadMinutesUnit')})`"
                  @input="onUnloadDraftInput"
                />
                <button
                  v-if="!isOrderFinished"
                  type="button"
                  class="inline-flex items-center justify-center rounded p-1 transition-colors"
                  :class="
                    isUnloadDirty
                      ? 'text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-slate-700'
                      : 'cursor-default text-gray-300 dark:text-slate-600'
                  "
                  :disabled="applyingUnload || !isUnloadDirty"
                  :title="t('deliveryRoutes.unloadApply')"
                  :aria-label="t('deliveryRoutes.unloadApply')"
                  @click="applyUnload"
                >
                  <BaseIcon
                    :path="applyingUnload ? mdiLoading : mdiCalculator"
                    :class="{ 'animate-spin': applyingUnload }"
                    size="20"
                  />
                </button>
              </div>
            </div>
            <div>
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.duration') }}</div>
              <div>{{ order.travelDurationText || '—' }}</div>
              <div
                v-if="showDelayOnOrder"
                class="mt-0.5 text-xs font-normal text-red-600"
              >
                {{
                  t('deliveryRoutes.delayMinutesValue', {
                    minutes: routeDelayMinutes,
                  })
                }}
              </div>
            </div>
            <div>
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.distance') }}</div>
              <div>{{ order.travelDistanceText || '—' }}</div>
            </div>
            <div class="sm:col-span-2">
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.address') }}</div>
              <div class="mt-1 flex items-start gap-2">
                <BaseIcon
                  :path="order.addressValid ? mdiCheckCircle : mdiAlertCircle"
                  :class="order.addressValid ? 'text-emerald-600' : 'text-red-600'"
                  size="18"
                  class="mt-0.5 shrink-0"
                />
                <div>
                  <div>{{ displayAddress(order) }}</div>
                  <div
                    v-if="!order.addressValid"
                    class="mt-1 text-xs text-red-600"
                  >
                    {{ t('deliveryRoutes.addressInvalid') }}
                  </div>
                </div>
              </div>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.comment') }}</div>
              <button
                type="button"
                class="mt-1 w-full rounded border border-dashed border-gray-300 px-3 py-2 text-left transition hover:border-gray-400 hover:bg-gray-50 dark:border-slate-600 dark:hover:border-slate-500 dark:hover:bg-slate-800/60"
                :title="t('deliveryRoutes.commentsAddHint')"
                @click="openCommentsModal"
              >
                <div v-if="order.comments?.length" class="space-y-1">
                  <div
                    v-for="comment in order.comments"
                    :key="comment.id"
                    class="rounded border-l-4 px-2 py-1 text-sm"
                    :class="commentRowClass(comment.color)"
                  >
                    {{ comment.text }}
                  </div>
                </div>
                <span v-else class="text-sm text-gray-400">
                  {{ t('deliveryRoutes.commentsAddHint') }}
                </span>
              </button>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
              <div class="text-xs text-gray-500 uppercase">
                {{ t('deliveryRoutes.courierComment') }}
              </div>
              <div class="mt-1 rounded border border-gray-200 px-3 py-2 text-sm dark:border-slate-600">
                <span v-if="order.courierComment" class="whitespace-pre-wrap">
                  {{ order.courierComment }}
                </span>
                <span v-else class="text-gray-400">
                  {{ t('deliveryRoutes.courierCommentEmpty') }}
                </span>
              </div>
            </div>
          </div>
        </CardBox>

        <OrderCommentsModal
          v-model="commentsModalOpen"
          :route-id="routeDetail?.id"
          :order-id="order?.id"
          :comments="order?.comments ?? []"
          :client-name="order?.clientName ?? ''"
          @saved="onCommentsSaved"
        />

        <CardBox class="mb-6">
          <div class="mb-3 text-xs font-semibold tracking-wide text-gray-500 uppercase">
            {{ t('deliveryRoutes.products') }}
          </div>
          <ul v-if="regularProducts.length" class="list-none space-y-2 pl-0">
            <li
              v-for="{ product, index: idx } in regularProducts"
              :key="idx"
              class="flex items-start gap-2 rounded px-2 py-1.5 text-sm"
              :style="productBackgroundStyle(order, idx)"
            >
              <span
                v-if="order.status === 'delivered' || order.status === 'refused'"
                class="mt-0.5 shrink-0 font-bold"
                :class="
                  isProductDelivered(order, idx)
                    ? 'text-emerald-600'
                    : isPartialDelivery(order)
                      ? 'text-yellow-600'
                      : 'text-red-500'
                "
              >
                {{ isProductDelivered(order, idx) ? '✓' : '✗' }}
              </span>
              <span
                :class="{
                  'text-yellow-700 dark:text-yellow-500':
                    isPartialDelivery(order) && !isProductDelivered(order, idx),
                  'text-gray-400 line-through':
                    (order.status === 'delivered' || order.status === 'refused') &&
                    !isProductDelivered(order, idx) &&
                    !isPartialDelivery(order),
                }"
              >
                {{ product }}
              </span>
            </li>
          </ul>
          <div v-else class="text-sm text-gray-400">—</div>
        </CardBox>

        <CardBox v-if="accessoryProducts.length" class="mb-6">
          <div class="mb-3 text-xs font-semibold tracking-wide text-gray-500 uppercase">
            {{ t('deliveryRoutes.accessories') }}
          </div>
          <ul class="list-none space-y-2 pl-0">
            <li
              v-for="{ product, index: idx } in accessoryProducts"
              :key="`acc-${idx}`"
              class="flex items-start gap-2 rounded px-2 py-1.5 text-sm"
              :style="productBackgroundStyle(order, idx)"
            >
              <span
                v-if="order.status === 'delivered' || order.status === 'refused'"
                class="mt-0.5 shrink-0 font-bold"
                :class="
                  isProductDelivered(order, idx)
                    ? 'text-emerald-600'
                    : isPartialDelivery(order)
                      ? 'text-yellow-600'
                      : 'text-red-500'
                "
              >
                {{ isProductDelivered(order, idx) ? '✓' : '✗' }}
              </span>
              <span
                :class="{
                  'text-yellow-700 dark:text-yellow-500':
                    isPartialDelivery(order) && !isProductDelivered(order, idx),
                  'text-gray-400 line-through':
                    (order.status === 'delivered' || order.status === 'refused') &&
                    !isProductDelivered(order, idx) &&
                    !isPartialDelivery(order),
                }"
              >
                {{ product }}
              </span>
            </li>
          </ul>
        </CardBox>

        <CardBox>
          <div class="mb-3 text-xs font-semibold tracking-wide text-gray-500 uppercase">
            {{ t('deliveryRoutes.proof') }}
          </div>
          <div class="flex flex-wrap items-start gap-4">
            <DeliveryProofThumb
              v-if="order.hasProof"
              :route-id="routeDetail.id"
              :order-id="order.id"
              :proof-type="order.proofType"
            />
            <div v-else class="text-sm text-gray-400">—</div>
            <div v-if="completedParts" class="text-sm text-gray-600 dark:text-slate-300">
              <div class="text-xs text-gray-500 uppercase">
                {{ t('deliveryRoutes.completedAt') }}
              </div>
              <div>{{ completedParts.date }}</div>
              <div>{{ completedParts.time }}</div>
            </div>
          </div>
        </CardBox>
      </template>
    </SectionMain>
  </LayoutAuthenticated>
</template>
