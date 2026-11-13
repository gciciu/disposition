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
  mdiGoogleMaps,
  mdiContentSave,
  mdiFilePdfBox,
  mdiCalculator,
  mdiEmailOutline,
  mdiTrashCan,
} from '@mdi/js'
import { useToast } from 'vue-toastification'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import CardBoxModal from '@/components/CardBoxModal.vue'
import CardBoxComponentEmpty from '@/components/CardBoxComponentEmpty.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import OrderCommentsModal from '@/components/OrderCommentsModal.vue'
import api, { showApiError } from '@/api.js'
import { useRealtime } from '@/composables/useRealtime.js'
import { withoutCountry } from '@/utils/address.js'
import { commentRowClass } from '@/utils/commentColors.js'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const detail = ref(null)
const loading = ref(false)
const saving = ref(false)
const deleting = ref(false)
const deleteModalOpen = ref(false)
const couriers = ref([])
const warehouses = ref([])
const selectedCourier = ref(null)
const selectedWarehouse = ref(null)
const scheduledDate = ref('')
const scheduledStartTime = ref('')
const unloadDrafts = ref({})
const applyingUnloadId = ref(null)
const commentsModalOpen = ref(false)
const commentsOrder = ref(null)

const UNLOAD_MIN = 1
const UNLOAD_MAX = 60
const UNLOAD_DEFAULT = 10

const title = computed(() => {
  if (!detail.value) {
    return t('deliveryRoutes.detailTitle')
  }
  return detail.value.name
})

const isRouteStarted = computed(() => Boolean(detail.value?.startedAt))
const isRouteCompleted = computed(() => Boolean(detail.value?.completedAt))
const canEditAssignment = computed(() => !isRouteStarted.value)
const routeDelayMinutes = computed(() => Number(detail.value?.delayMinutes) || 0)
const nextPendingOrderId = computed(() => {
  const orders = detail.value?.orders
  if (!Array.isArray(orders)) {
    return null
  }
  const next = orders.find((item) => item.status === 'pending')
  return next?.id ?? null
})
const isNextPendingOrder = (order) =>
  routeDelayMinutes.value > 0 && order?.id != null && order.id === nextPendingOrderId.value
const canDownloadCompletionPdf = computed(
  () => Boolean(detail.value?.hasCompletionPdf) || isRouteCompleted.value,
)
const downloadingPdf = ref(false)

const routeStatusLabel = computed(() => {
  if (isRouteCompleted.value) {
    return t('deliveryRoutes.routeStatusCompleted')
  }
  if (isRouteStarted.value) {
    return t('deliveryRoutes.routeStatusInProgress')
  }
  return t('deliveryRoutes.routeStatusNotStarted')
})

const courierOptions = computed(() => [
  { id: null, label: t('deliveryRoutes.courierUnassigned') },
  ...couriers.value.map((courier) => ({
    id: courier.id,
    label: `${courier.name} (${courier.login})`,
  })),
])

const COURIER_LOCATION_ORIGIN = 'courier_location'

const warehouseOptions = computed(() => [
  { id: COURIER_LOCATION_ORIGIN, label: t('deliveryRoutes.warehouseCourierLocation') },
  ...warehouses.value.map((warehouse) => ({
    id: warehouse.id,
    label: warehouse.isDefault
      ? `${warehouse.name} (${t('deliveryRoutes.warehouseDefault')})`
      : warehouse.name,
  })),
])

const syncSelectedCourier = () => {
  const courierId = detail.value?.courier?.id ?? null
  selectedCourier.value =
    courierOptions.value.find((option) => option.id === courierId) ?? courierOptions.value[0]
}

const syncSelectedWarehouse = () => {
  let originId = COURIER_LOCATION_ORIGIN
  if (detail.value?.originType === 'warehouse' && detail.value?.warehouse?.id != null) {
    originId = detail.value.warehouse.id
  } else if (detail.value?.originType === COURIER_LOCATION_ORIGIN) {
    originId = COURIER_LOCATION_ORIGIN
  } else if (detail.value?.warehouse?.id != null) {
    originId = detail.value.warehouse.id
  }

  selectedWarehouse.value =
    warehouseOptions.value.find((option) => option.id === originId) ?? warehouseOptions.value[0]
}

const syncScheduleFields = () => {
  scheduledDate.value = detail.value?.scheduledDate ?? ''
  scheduledStartTime.value = detail.value?.scheduledStartTime ?? ''
}

const syncUnloadDrafts = ({ preserveDirty = false } = {}) => {
  const next = {}
  for (const order of detail.value?.orders ?? []) {
    const saved = order.unloadingDurationMinutes ?? UNLOAD_DEFAULT
    if (
      preserveDirty &&
      isUnloadDirty(order.id) &&
      unloadDrafts.value[order.id] != null
    ) {
      next[order.id] = unloadDrafts.value[order.id]
    } else {
      next[order.id] = saved
    }
  }
  unloadDrafts.value = next
}

const savedUnloadMinutes = (order) => order?.unloadingDurationMinutes ?? UNLOAD_DEFAULT

const isUnloadDirty = (orderId) => {
  const order = detail.value?.orders?.find((item) => item.id === orderId)
  if (!order) {
    return false
  }
  return Number(unloadDrafts.value[orderId]) !== Number(savedUnloadMinutes(order))
}

const clampUnloadMinutes = (value) => {
  const parsed = Number.parseInt(String(value), 10)
  if (Number.isNaN(parsed)) {
    return UNLOAD_DEFAULT
  }
  return Math.min(UNLOAD_MAX, Math.max(UNLOAD_MIN, parsed))
}

const onUnloadDraftInput = (orderId, event) => {
  unloadDrafts.value = {
    ...unloadDrafts.value,
    [orderId]: event.target.value,
  }
}

const openCommentsModal = (order) => {
  commentsOrder.value = order
  commentsModalOpen.value = true
}

const onCommentsSaved = (routeData) => {
  if (!routeData) {
    return
  }
  detail.value = routeData
  syncSelectedCourier()
  syncSelectedWarehouse()
  syncScheduleFields()
  syncUnloadDrafts({ preserveDirty: true })
  if (commentsOrder.value?.id != null) {
    commentsOrder.value =
      routeData.orders?.find((item) => item.id === commentsOrder.value.id) ?? null
  }
}

const isOrderFinished = (order) =>
  order?.status === 'delivered' || order?.status === 'refused'

const applyUnload = async (order) => {
  if (
    !detail.value?.id ||
    !order?.id ||
    applyingUnloadId.value != null ||
    isOrderFinished(order)
  ) {
    return
  }

  const minutes = clampUnloadMinutes(unloadDrafts.value[order.id])
  unloadDrafts.value = {
    ...unloadDrafts.value,
    [order.id]: minutes,
  }

  if (minutes === Number(savedUnloadMinutes(order))) {
    return
  }

  applyingUnloadId.value = order.id
  try {
    const { data } = await api.put(`/api/admin/routes/${detail.value.id}`, {
      orders: [{ id: order.id, unloadingDurationMinutes: minutes }],
    })
    detail.value = data.route
    syncSelectedCourier()
    syncSelectedWarehouse()
    syncScheduleFields()
    syncUnloadDrafts()
    toast.success(t('deliveryRoutes.unloadApplied'))
  } catch (e) {
    showApiError(toast, e, t('deliveryRoutes.unloadApplyFailed'))
  } finally {
    applyingUnloadId.value = null
  }
}


const loadCouriers = async () => {
  try {
    const { data } = await api.get('/api/admin/couriers')
    couriers.value = data.couriers ?? []
  } catch {
    couriers.value = []
  }
}

const loadWarehouses = async () => {
  try {
    const { data } = await api.get('/api/admin/warehouses')
    warehouses.value = data.warehouses ?? []
  } catch {
    warehouses.value = []
  }
}

const loadRoute = async () => {
  loading.value = true
  detail.value = null
  try {
    const { data } = await api.get(`/api/admin/routes/${route.params.id}`)
    detail.value = data.route
    syncSelectedCourier()
    syncSelectedWarehouse()
    syncScheduleFields()
    syncUnloadDrafts()
  } catch (e) {
    showApiError(toast, e, t('deliveryRoutes.loadDetailFailed'))
  } finally {
    loading.value = false
  }
}

const saveRoute = async () => {
  if (!detail.value || !canEditAssignment.value) {
    return
  }

  if (!selectedCourier.value?.id || !scheduledDate.value) {
    toast.error(t('deliveryRoutes.scheduleRequired'))
    return
  }

  saving.value = true
  try {
    const originId = selectedWarehouse.value?.id ?? COURIER_LOCATION_ORIGIN
    const payload = {
      courierId: selectedCourier.value.id,
      scheduledDate: scheduledDate.value,
      scheduledStartTime: scheduledStartTime.value || null,
    }

    if (originId === COURIER_LOCATION_ORIGIN) {
      payload.originType = COURIER_LOCATION_ORIGIN
      payload.warehouseId = null
    } else {
      payload.originType = 'warehouse'
      payload.warehouseId = originId
    }

    const { data } = await api.put(`/api/admin/routes/${detail.value.id}`, payload)
    detail.value = data.route
    syncSelectedCourier()
    syncSelectedWarehouse()
    syncScheduleFields()
    syncUnloadDrafts({ preserveDirty: true })
    toast.success(t('deliveryRoutes.saved'))
  } catch (e) {
    showApiError(toast, e, t('deliveryRoutes.saveFailed'))
  } finally {
    saving.value = false
  }
}

const askDelete = () => {
  if (!detail.value?.id) {
    return
  }
  deleteModalOpen.value = true
}

const confirmDelete = async () => {
  if (!detail.value?.id || deleting.value) {
    return
  }

  deleting.value = true
  try {
    await api.delete(`/api/admin/routes/${detail.value.id}`)
    toast.success(t('deliveryRoutes.deleted'))
    deleteModalOpen.value = false
    router.push({ name: 'routes' })
  } catch (e) {
    showApiError(toast, e, t('deliveryRoutes.deleteFailed'))
    deleteModalOpen.value = false
  } finally {
    deleting.value = false
  }
}

const formatDate = (value) => {
  if (!value) {
    return '—'
  }
  try {
    return new Date(value).toLocaleString(locale.value)
  } catch {
    return value
  }
}

const WEEKDAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']

const formatScheduledDateWithWeekday = (value) => {
  if (!value) {
    return '—'
  }
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value))
  if (!match) {
    return value
  }
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  return t(`deliveryRoutes.weekdayFull.${WEEKDAY_KEYS[date.getDay()]}`)
}

const displayAddress = (order) =>
  withoutCountry(order.formattedAddress || order.address)

const accessoryIndexSet = (order) =>
  new Set(
    (order?.accessoryProductIndexes ?? [])
      .map((index) => Number(index))
      .filter((index) => Number.isInteger(index) && index >= 0),
  )

const regularProductsOf = (order) => {
  const products = order?.products ?? []
  const accessories = accessoryIndexSet(order)
  return products
    .map((product, index) => ({ product, index }))
    .filter(({ index }) => !accessories.has(index))
}

const accessoryProductsOf = (order) => {
  const products = order?.products ?? []
  const accessories = accessoryIndexSet(order)
  return products
    .map((product, index) => ({ product, index }))
    .filter(({ index }) => accessories.has(index))
}

const isProductDelivered = (order, index) => {
  if (order.status !== 'delivered') {
    return false
  }
  // Legacy completions before per-product tracking: treat all as delivered.
  if (order.deliveredProductIndexes == null) {
    return true
  }
  return order.deliveredProductIndexes.includes(index)
}

/** Delivered stop where at least one product was not handed over. */
const isPartialDelivery = (order) => {
  if (order.status !== 'delivered') {
    return false
  }
  const products = order.products ?? []
  if (products.length === 0 || order.deliveredProductIndexes == null) {
    return false
  }
  return products.some((_, index) => !isProductDelivered(order, index))
}

const openOrderDetail = (order) => {
  if (!detail.value?.id || !order?.id) {
    return
  }
  router.push({
    name: 'route-order-detail',
    params: { id: detail.value.id, orderId: order.id },
  })
}

const downloadCompletionPdf = async () => {
  if (!detail.value?.id || downloadingPdf.value) {
    return
  }

  downloadingPdf.value = true
  try {
    const { data } = await api.get(`/api/admin/routes/${detail.value.id}/completion-pdf`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = `route_full_${detail.value.id}.pdf`
    link.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    showApiError(toast, e, t('deliveryRoutes.downloadPdfFailed'))
  } finally {
    downloadingPdf.value = false
  }
}

const applyRealtimePayload = (event, data) => {
  if (!detail.value || !data?.route) {
    return
  }
  if (data.route.id != null && data.route.id !== detail.value.id) {
    return
  }

  if (
    event === 'route.started' ||
    event === 'route.completed' ||
    event === 'route.continued' ||
    event === 'route.delayed'
  ) {
    detail.value = data.route
    syncSelectedCourier()
    syncSelectedWarehouse()
    syncUnloadDrafts({ preserveDirty: true })
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
    detail.value = next
    syncSelectedCourier()
    syncSelectedWarehouse()
    syncUnloadDrafts({ preserveDirty: true })
  }
}

const { start: startRealtime, stop: stopRealtime } = useRealtime({
  routeId: () => route.params.id,
  onEvent: applyRealtimePayload,
})

onMounted(async () => {
  await Promise.all([loadCouriers(), loadWarehouses()])
  await loadRoute()
  startRealtime()
})

watch(
  () => route.params.id,
  async () => {
    stopRealtime()
    await loadRoute()
    startRealtime()
  },
)

watch(courierOptions, () => {
  if (detail.value) {
    syncSelectedCourier()
  }
})

watch(warehouseOptions, () => {
  if (detail.value) {
    syncSelectedWarehouse()
  }
})
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiRoutes" :title="title" main>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <BaseButton
            :label="t('deliveryRoutes.backToList')"
            :icon="mdiArrowLeft"
            color="whiteDark"
            @click="router.push({ name: 'routes' })"
          />
          <BaseButton
            v-if="detail && canDownloadCompletionPdf"
            :label="
              downloadingPdf
                ? t('deliveryRoutes.downloadingPdf')
                : t('deliveryRoutes.downloadPdf')
            "
            :icon="downloadingPdf ? mdiLoading : mdiFilePdfBox"
            color="success"
            :disabled="downloadingPdf || loading"
            @click="downloadCompletionPdf"
          />
          <BaseButton
            v-if="detail && canEditAssignment"
            :label="saving ? t('deliveryRoutes.saving') : t('deliveryRoutes.save')"
            :icon="saving ? mdiLoading : mdiContentSave"
            color="info"
            :disabled="saving || loading || deleting"
            @click="saveRoute"
          />
          <BaseButton
            v-if="detail"
            :label="t('deliveryRoutes.delete')"
            :icon="mdiTrashCan"
            color="danger"
            :disabled="loading || deleting || saving"
            @click="askDelete"
          />
        </div>
      </SectionTitleLineWithButton>

      <CardBox v-if="loading" class="mb-6">
        <div class="flex items-center gap-2 p-4 text-gray-500">
          <BaseIcon :path="mdiLoading" class="animate-spin" size="20" />
          {{ t('deliveryRoutes.loading') }}
        </div>
      </CardBox>

      <template v-else-if="detail">
        <CardBox class="mb-6">
          <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="grid flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <div>
                <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.orders') }}</div>
                <div>{{ detail.orderCount }}</div>
              </div>
              <div>
                <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.created') }}</div>
                <div>{{ formatDate(detail.createdAt) }}</div>
              </div>
              <div v-if="detail.totalDurationText">
                <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.total') }}</div>
                <div>{{ detail.totalDurationText }} · {{ detail.totalDistanceText }}</div>
              </div>
              <div>
                <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.routeStatus') }}</div>
                <div>{{ routeStatusLabel }}</div>
              </div>
            </div>
            <BaseButton
              v-if="detail.mapsUrl"
              color="success"
              :icon="mdiGoogleMaps"
              :label="t('deliveryRoutes.openMaps')"
              :href="detail.mapsUrl"
              target="_blank"
            />
          </div>
        </CardBox>

        <CardBox class="mb-6">
          <div class="grid gap-4 md:grid-cols-2">
            <template v-if="canEditAssignment">
              <FormField :label="t('deliveryRoutes.courier')" :help="t('deliveryRoutes.courierHelp')">
                <FormControl v-model="selectedCourier" :options="courierOptions" />
              </FormField>
              <FormField
                :label="t('deliveryRoutes.warehouse')"
                :help="t('deliveryRoutes.warehouseHelp')"
              >
                <FormControl v-model="selectedWarehouse" :options="warehouseOptions" />
              </FormField>
              <FormField :label="t('deliveryRoutes.scheduledDate')">
                <FormControl v-model="scheduledDate" type="date" />
                <div
                  v-if="scheduledDate"
                  class="mt-1 text-sm text-gray-600 dark:text-slate-300"
                >
                  {{ formatScheduledDateWithWeekday(scheduledDate) }}
                </div>
              </FormField>
              <FormField
                :label="t('deliveryRoutes.scheduledStartTime')"
                :help="t('deliveryRoutes.scheduledStartTimeHelp')"
              >
                <FormControl v-model="scheduledStartTime" type="time" />
              </FormField>
            </template>
            <template v-else>
              <div>
                <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.courier') }}</div>
                <div>{{ detail.courier?.name || t('deliveryRoutes.courierUnassigned') }}</div>
              </div>
              <div>
                <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.warehouse') }}</div>
                <div>
                  {{
                    detail.originType === 'courier_location' || !detail.warehouse?.name
                      ? t('deliveryRoutes.warehouseCourierLocation')
                      : detail.warehouse.name
                  }}
                </div>
              </div>
              <div>
                <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.scheduledDate') }}</div>
                <div>{{ formatScheduledDateWithWeekday(detail.scheduledDate) }}</div>
              </div>
              <div>
                <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.scheduledStartTime') }}</div>
                <div>{{ detail.scheduledStartTime || '—' }}</div>
              </div>
            </template>
          </div>
        </CardBox>

        <CardBox v-if="!detail.orders?.length">
          <CardBoxComponentEmpty />
        </CardBox>

        <CardBox v-else has-table>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('deliveryRoutes.clientName') }}</th>
                <th>{{ t('deliveryRoutes.duration') }}</th>
                <th>{{ t('deliveryRoutes.arrivalTime') }}</th>
                <th>
                  {{ t('deliveryRoutes.unloadTime') }}
                  <span class="ml-1 text-xs font-normal normal-case text-gray-500 dark:text-slate-400">
                    {{ t('deliveryRoutes.unloadMinutesUnit') }}
                  </span>
                </th>
                <th>{{ t('deliveryRoutes.comment') }}</th>
                <th class="w-12 text-center">
                  <span class="sr-only">{{ t('deliveryRoutes.emailNotify') }}</span>
                  <BaseIcon :path="mdiEmailOutline" size="18" class="inline-block text-gray-500" />
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="order in detail.orders"
                :key="order.id"
                class="cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-800/60"
                @click="openOrderDetail(order)"
              >
                <td :data-label="'#'">
                  <span
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold"
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
                </td>
                <td :data-label="t('deliveryRoutes.clientName')">
                  <div class="font-medium">
                    {{ order.clientName }}
                    <span
                      v-if="order.isExchange"
                      class="ml-2 text-xs font-bold uppercase text-violet-700 dark:text-violet-300"
                    >
                      {{ t('deliveryRoutes.statusExchange') }}
                    </span>
                    <span
                      v-if="order.status === 'refused'"
                      class="ml-2 text-xs font-bold uppercase text-red-600"
                    >
                      {{ t('deliveryRoutes.statusRefused') }}
                    </span>
                    <span
                      v-else-if="isPartialDelivery(order)"
                      class="ml-2 text-xs font-bold uppercase text-yellow-600"
                    >
                      {{ t('deliveryRoutes.statusPartial') }}
                    </span>
                    <span
                      v-else-if="order.status === 'delivered'"
                      class="ml-2 text-xs font-bold uppercase text-emerald-600"
                    >
                      {{ t('deliveryRoutes.statusDelivered') }}
                    </span>
                  </div>
                  <div class="mt-1 flex items-center gap-1.5 text-sm">
                    <BaseIcon
                      :path="order.addressValid ? mdiCheckCircle : mdiAlertCircle"
                      :class="order.addressValid ? 'text-emerald-600' : 'text-red-600'"
                      size="16"
                      class="shrink-0"
                    />
                    <span>{{ displayAddress(order) }}</span>
                  </div>
                  <div
                    v-if="!order.addressValid"
                    class="mt-1 text-xs text-red-600"
                  >
                    {{ t('deliveryRoutes.addressInvalid') }}
                  </div>
                  <div class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    {{
                      order.weightKg != null
                        ? t('deliveryRoutes.weightValue', {
                            value: Number(order.weightKg).toLocaleString(locale, {
                              minimumFractionDigits: 1,
                              maximumFractionDigits: 1,
                            }),
                          })
                        : '—'
                    }}
                    <span class="mx-1">·</span>
                    {{ order.travelDistanceText || '—' }}
                  </div>
                  <div
                    v-if="regularProductsOf(order).length"
                    class="mt-2 space-y-0.5 text-xs text-gray-600 dark:text-slate-300"
                  >
                    <div
                      v-for="{ product, index } in regularProductsOf(order)"
                      :key="`p-${order.id}-${index}`"
                    >
                      {{ product }}
                    </div>
                  </div>
                  <div
                    v-if="accessoryProductsOf(order).length"
                    class="mt-2 space-y-0.5 text-xs"
                  >
                    <div class="font-semibold tracking-wide text-gray-500 uppercase dark:text-slate-400">
                      {{ t('deliveryRoutes.accessories') }}
                    </div>
                    <div
                      v-for="{ product, index } in accessoryProductsOf(order)"
                      :key="`a-${order.id}-${index}`"
                      class="text-gray-600 dark:text-slate-300"
                    >
                      {{ product }}
                    </div>
                  </div>
                </td>
                <td
                  :data-label="t('deliveryRoutes.duration')"
                  class="whitespace-nowrap font-semibold"
                >
                  <div>{{ order.travelDurationText || '—' }}</div>
                  <div
                    v-if="isNextPendingOrder(order)"
                    class="mt-0.5 text-xs font-normal text-red-600"
                  >
                    {{
                      t('deliveryRoutes.delayMinutesValue', {
                        minutes: routeDelayMinutes,
                      })
                    }}
                  </div>
                </td>
                <td
                  :data-label="t('deliveryRoutes.arrivalTime')"
                  class="whitespace-nowrap font-semibold"
                >
                  {{ order.arrivalTime || '—' }}
                </td>
                <td
                  :data-label="t('deliveryRoutes.unloadTime')"
                  class="whitespace-nowrap"
                  @click.stop
                >
                  <div class="flex items-center gap-0.5">
                    <input
                      class="w-12 rounded border border-gray-300 px-1.5 py-1 text-sm dark:border-slate-600 dark:bg-slate-800 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 dark:disabled:bg-slate-900 dark:disabled:text-slate-500"
                      type="number"
                      :min="UNLOAD_MIN"
                      :max="UNLOAD_MAX"
                      :value="unloadDrafts[order.id] ?? savedUnloadMinutes(order)"
                      :disabled="
                        isOrderFinished(order) || applyingUnloadId === order.id
                      "
                      :aria-label="`${t('deliveryRoutes.unloadTime')} (${t('deliveryRoutes.unloadMinutesUnit')})`"
                      @input="onUnloadDraftInput(order.id, $event)"
                    />
                    <button
                      v-if="!isOrderFinished(order)"
                      type="button"
                      class="inline-flex items-center justify-center rounded p-1 transition-colors"
                      :class="
                        isUnloadDirty(order.id)
                          ? 'text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-slate-700'
                          : 'cursor-default text-gray-300 dark:text-slate-600'
                      "
                      :disabled="
                        applyingUnloadId != null || !isUnloadDirty(order.id)
                      "
                      :title="t('deliveryRoutes.unloadApply')"
                      :aria-label="t('deliveryRoutes.unloadApply')"
                      @click="applyUnload(order)"
                    >
                      <BaseIcon
                        :path="
                          applyingUnloadId === order.id ? mdiLoading : mdiCalculator
                        "
                        :class="{ 'animate-spin': applyingUnloadId === order.id }"
                        size="20"
                      />
                    </button>
                  </div>
                </td>
                <td
                  :data-label="t('deliveryRoutes.comment')"
                  class="max-w-xs cursor-pointer text-sm"
                  :title="t('deliveryRoutes.commentsAddHint')"
                  @click.stop="openCommentsModal(order)"
                >
                  <div
                    v-if="order.comments?.length || order.courierComment"
                    class="space-y-1"
                  >
                    <div
                      v-for="comment in order.comments"
                      :key="comment.id"
                      class="rounded border-l-4 px-2 py-1 text-xs"
                      :class="commentRowClass(comment.color)"
                    >
                      {{ comment.text }}
                    </div>
                    <div
                      v-if="order.courierComment"
                      class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-200"
                      :title="t('deliveryRoutes.courierComment')"
                    >
                      {{ order.courierComment }}
                    </div>
                  </div>
                  <span v-else class="text-gray-400">—</span>
                </td>
                <td
                  :data-label="t('deliveryRoutes.emailNotify')"
                  class="text-center"
                  @click.stop
                >
                  <button
                    type="button"
                    class="inline-flex items-center justify-center rounded p-1.5 transition-colors"
                    :class="
                      order.email
                        ? 'text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-slate-700'
                        : 'cursor-default text-gray-300 dark:text-slate-600'
                    "
                    :disabled="!order.email"
                    :title="
                      order.email
                        ? t('deliveryRoutes.emailNotifyTitle', { email: order.email })
                        : t('deliveryRoutes.emailNotifyMissing')
                    "
                    :aria-label="
                      order.email
                        ? t('deliveryRoutes.emailNotifyTitle', { email: order.email })
                        : t('deliveryRoutes.emailNotifyMissing')
                    "
                  >
                    <BaseIcon :path="mdiEmailOutline" size="20" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </CardBox>

        <OrderCommentsModal
          v-model="commentsModalOpen"
          :route-id="detail?.id"
          :order-id="commentsOrder?.id"
          :comments="commentsOrder?.comments ?? []"
          :client-name="commentsOrder?.clientName ?? ''"
          @saved="onCommentsSaved"
        />

        <CardBoxModal
          v-model="deleteModalOpen"
          :title="t('deliveryRoutes.deleteConfirmTitle')"
          button="danger"
          :button-label="t('deliveryRoutes.delete')"
          has-cancel
          :is-processing="deleting"
          @confirm="confirmDelete"
        >
          <p>
            {{
              t('deliveryRoutes.deleteConfirmBody', {
                name: detail?.name || '',
                date: detail?.scheduledDate || '—',
              })
            }}
          </p>
        </CardBoxModal>
      </template>
    </SectionMain>
  </LayoutAuthenticated>
</template>
