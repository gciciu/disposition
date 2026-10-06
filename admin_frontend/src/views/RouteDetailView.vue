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
} from '@mdi/js'
import { useToast } from 'vue-toastification'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import CardBoxComponentEmpty from '@/components/CardBoxComponentEmpty.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import api from '@/api.js'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const detail = ref(null)
const loading = ref(false)
const savingCourier = ref(false)
const couriers = ref([])
const selectedCourier = ref(null)

const title = computed(() => {
  if (!detail.value) {
    return t('deliveryRoutes.detailTitle')
  }
  return detail.value.name
})

const courierOptions = computed(() => [
  { id: null, label: t('deliveryRoutes.courierUnassigned') },
  ...couriers.value.map((courier) => ({
    id: courier.id,
    label: `${courier.name} (${courier.login})`,
  })),
])

const syncSelectedCourier = () => {
  const courierId = detail.value?.courier?.id ?? null
  selectedCourier.value =
    courierOptions.value.find((option) => option.id === courierId) ?? courierOptions.value[0]
}

const loadCouriers = async () => {
  try {
    const { data } = await api.get('/api/admin/couriers')
    couriers.value = data.couriers ?? []
  } catch {
    couriers.value = []
  }
}

const loadRoute = async () => {
  loading.value = true
  detail.value = null
  try {
    const { data } = await api.get(`/api/admin/routes/${route.params.id}`)
    detail.value = data.route
    syncSelectedCourier()
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.loadDetailFailed'))
  } finally {
    loading.value = false
  }
}

const saveCourier = async () => {
  if (!detail.value) {
    return
  }

  savingCourier.value = true
  try {
    const { data } = await api.put(`/api/admin/routes/${detail.value.id}/courier`, {
      courierId: selectedCourier.value?.id ?? null,
    })
    detail.value = data.route
    syncSelectedCourier()
    toast.success(t('deliveryRoutes.courierSaved'))
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.courierSaveFailed'))
  } finally {
    savingCourier.value = false
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

const displayAddress = (order) => order.formattedAddress || order.address

onMounted(async () => {
  await loadCouriers()
  await loadRoute()
})

watch(
  () => route.params.id,
  async () => {
    await loadRoute()
  },
)

watch(courierOptions, () => {
  if (detail.value) {
    syncSelectedCourier()
  }
})
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiRoutes" :title="title" main>
        <BaseButton
          :label="t('deliveryRoutes.backToList')"
          :icon="mdiArrowLeft"
          color="whiteDark"
          @click="router.push({ name: 'routes' })"
        />
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
          <FormField :label="t('deliveryRoutes.courier')" :help="t('deliveryRoutes.courierHelp')">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
              <div class="min-w-0 flex-1">
                <FormControl v-model="selectedCourier" :options="courierOptions" />
              </div>
              <BaseButtons>
                <BaseButton
                  color="info"
                  :label="savingCourier ? t('deliveryRoutes.courierSaving') : t('deliveryRoutes.courierSave')"
                  :disabled="savingCourier"
                  @click="saveCourier"
                />
              </BaseButtons>
            </div>
          </FormField>
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
                <th>{{ t('deliveryRoutes.duration') }}</th>
                <th>{{ t('deliveryRoutes.distance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in detail.orders" :key="order.id">
                <td :data-label="'#'">{{ order.position }}</td>
                <td :data-label="t('deliveryRoutes.clientName')">
                  <div class="font-medium">{{ order.clientName }}</div>
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
                  <ul class="list-disc pl-4">
                    <li v-for="(product, idx) in order.products" :key="idx">
                      {{ product }}
                    </li>
                  </ul>
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
