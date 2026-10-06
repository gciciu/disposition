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
} from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import CardBoxComponentEmpty from '@/components/CardBoxComponentEmpty.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import NotificationBar from '@/components/NotificationBar.vue'
import api from '@/api.js'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const detail = ref(null)
const loading = ref(false)
const error = ref('')

const title = computed(() => {
  if (!detail.value) {
    return t('deliveryRoutes.detailTitle')
  }
  return detail.value.name
})

const loadRoute = async () => {
  loading.value = true
  error.value = ''
  detail.value = null
  try {
    const { data } = await api.get(`/api/admin/routes/${route.params.id}`)
    detail.value = data.route
  } catch (e) {
    error.value = e.response?.data?.error || t('deliveryRoutes.loadDetailFailed')
  } finally {
    loading.value = false
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

onMounted(loadRoute)
watch(() => route.params.id, loadRoute)
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

      <NotificationBar v-if="error" color="danger" :icon="mdiAlertCircle" class="mb-6">
        {{ error }}
      </NotificationBar>

      <CardBox v-if="loading" class="mb-6">
        <div class="flex items-center gap-2 p-4 text-gray-500">
          <BaseIcon :path="mdiLoading" class="animate-spin" size="20" />
          {{ t('deliveryRoutes.loading') }}
        </div>
      </CardBox>

      <template v-else-if="detail">
        <CardBox class="mb-6">
          <div class="grid gap-3 sm:grid-cols-3">
            <div>
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.sourceFile') }}</div>
              <div>{{ detail.sourceFilename || '—' }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.orders') }}</div>
              <div>{{ detail.orderCount }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 uppercase">{{ t('deliveryRoutes.created') }}</div>
              <div>{{ formatDate(detail.createdAt) }}</div>
            </div>
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
                <th>{{ t('deliveryRoutes.phone') }}</th>
                <th>{{ t('deliveryRoutes.address') }}</th>
                <th>{{ t('deliveryRoutes.products') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in detail.orders" :key="order.id">
                <td :data-label="'#'">{{ order.position }}</td>
                <td :data-label="t('deliveryRoutes.clientName')">{{ order.clientName }}</td>
                <td :data-label="t('deliveryRoutes.phone')">{{ order.phone || '—' }}</td>
                <td :data-label="t('deliveryRoutes.address')">
                  <div class="flex items-start gap-2">
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
              </tr>
            </tbody>
          </table>
        </CardBox>
      </template>
    </SectionMain>
  </LayoutAuthenticated>
</template>
