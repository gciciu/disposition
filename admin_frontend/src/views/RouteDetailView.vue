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
} from '@mdi/js'
import { useToast } from 'vue-toastification'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import CardBoxComponentEmpty from '@/components/CardBoxComponentEmpty.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import DeliveryProofThumb from '@/components/DeliveryProofThumb.vue'
import api from '@/api.js'
import { useRealtime } from '@/composables/useRealtime.js'
import { withoutCountry } from '@/utils/address.js'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const detail = ref(null)
const loading = ref(false)
const saving = ref(false)
const couriers = ref([])
const warehouses = ref([])
const selectedCourier = ref(null)
const selectedWarehouse = ref(null)

const title = computed(() => {
  if (!detail.value) {
    return t('deliveryRoutes.detailTitle')
  }
  return detail.value.name
})

const isRouteStarted = computed(() => Boolean(detail.value?.startedAt))
const isRouteCompleted = computed(() => Boolean(detail.value?.completedAt))
const canEditAssignment = computed(() => !isRouteStarted.value)

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
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.loadDetailFailed'))
  } finally {
    loading.value = false
  }
}

const saveRoute = async () => {
  if (!detail.value || !canEditAssignment.value) {
    return
  }

  saving.value = true
  try {
    const originId = selectedWarehouse.value?.id ?? COURIER_LOCATION_ORIGIN
    const payload = {
      courierId: selectedCourier.value?.id ?? null,
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
    toast.success(t('deliveryRoutes.saved'))
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.saveFailed'))
  } finally {
    saving.value = false
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

/** European date + time parts for stacked display. */
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

const displayAddress = (order) =>
  withoutCountry(order.formattedAddress || order.address)

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

const applyRealtimePayload = (event, data) => {
  if (!detail.value || !data?.route) {
    return
  }
  if (data.route.id != null && data.route.id !== detail.value.id) {
    return
  }

  if (event === 'route.started') {
    detail.value = data.route
    syncSelectedCourier()
    syncSelectedWarehouse()
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
            v-if="detail && canEditAssignment"
            :label="saving ? t('deliveryRoutes.saving') : t('deliveryRoutes.save')"
            :icon="saving ? mdiLoading : mdiContentSave"
            color="info"
            :disabled="saving || loading"
            @click="saveRoute"
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
                <th>{{ t('deliveryRoutes.products') }}</th>
                <th>{{ t('deliveryRoutes.proof') }}</th>
                <th>{{ t('deliveryRoutes.duration') }}</th>
                <th>{{ t('deliveryRoutes.distance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in detail.orders" :key="order.id">
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
                  <div class="mt-1 text-sm text-gray-600">{{ order.phone || '—' }}</div>
                  <div class="mt-1 flex items-start gap-2 text-sm">
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
                </td>
                <td :data-label="t('deliveryRoutes.products')">
                  <ul class="list-none space-y-1 pl-0">
                    <li
                      v-for="(product, idx) in order.products"
                      :key="idx"
                      class="flex items-start gap-2 text-sm"
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
                            isPartialDelivery(order) &&
                            !isProductDelivered(order, idx),
                          'text-gray-400 line-through':
                            (order.status === 'delivered' ||
                              order.status === 'refused') &&
                            !isProductDelivered(order, idx) &&
                            !isPartialDelivery(order),
                        }"
                      >
                        {{ product }}
                      </span>
                    </li>
                  </ul>
                </td>
                <td :data-label="t('deliveryRoutes.proof')">
                  <div class="flex flex-col items-start gap-1.5">
                    <DeliveryProofThumb
                      v-if="order.hasProof"
                      :route-id="detail.id"
                      :order-id="order.id"
                      :proof-type="order.proofType"
                    />
                    <div
                      v-if="order.completedAt"
                      class="text-xs leading-snug whitespace-nowrap text-gray-600 dark:text-slate-300"
                    >
                      <div>{{ formatDeliveryParts(order.completedAt)?.date }}</div>
                      <div>{{ formatDeliveryParts(order.completedAt)?.time }}</div>
                    </div>
                    <span
                      v-else-if="!order.hasProof"
                      class="text-sm text-gray-400"
                    >
                      —
                    </span>
                  </div>
                </td>
                <td
                  :data-label="t('deliveryRoutes.duration')"
                  class="font-semibold whitespace-nowrap"
                >
                  {{ order.travelDurationText || '—' }}
                </td>
                <td
                  :data-label="t('deliveryRoutes.distance')"
                  class="whitespace-nowrap"
                >
                  {{ order.travelDistanceText || '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </CardBox>
      </template>
    </SectionMain>
  </LayoutAuthenticated>
</template>
