<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import {
  mdiRoutes,
  mdiUpload,
  mdiPencil,
  mdiTrashCan,
  mdiAlertCircle,
  mdiCheckCircle,
  mdiLoading,
} from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import CardBoxModal from '@/components/CardBoxModal.vue'
import CardBoxComponentEmpty from '@/components/CardBoxComponentEmpty.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import BaseLevel from '@/components/BaseLevel.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import NotificationBar from '@/components/NotificationBar.vue'
import api from '@/api.js'

const { t, locale } = useI18n()
const router = useRouter()

const routes = ref([])
const loading = ref(false)
const importing = ref(false)
const deleting = ref(false)
const error = ref('')
const invalidAddresses = ref([])
const success = ref('')
const fileInput = ref(null)
const deleteTarget = ref(null)

const isDeleteModalActive = computed({
  get: () => !!deleteTarget.value,
  set: (value) => {
    if (!value) {
      deleteTarget.value = null
    }
  },
})

const clearFeedback = () => {
  error.value = ''
  invalidAddresses.value = []
  success.value = ''
}

const loadRoutes = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/api/admin/routes')
    routes.value = data.routes ?? []
  } catch (e) {
    error.value = e.response?.data?.error || t('deliveryRoutes.loadFailed')
  } finally {
    loading.value = false
  }
}

const editRoute = (id) => {
  router.push({ name: 'route-detail', params: { id } })
}

const askDelete = (route) => {
  deleteTarget.value = route
}

const confirmDelete = async () => {
  const target = deleteTarget.value
  if (!target) {
    return
  }

  deleting.value = true
  clearFeedback()
  try {
    await api.delete(`/api/admin/routes/${target.id}`)
    success.value = t('deliveryRoutes.deleted')
    deleteTarget.value = null
    await loadRoutes()
  } catch (e) {
    error.value = e.response?.data?.error || t('deliveryRoutes.deleteFailed')
    deleteTarget.value = null
  } finally {
    deleting.value = false
  }
}

const triggerImport = () => {
  fileInput.value?.click()
}

const onFileSelected = async (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) {
    return
  }

  importing.value = true
  clearFeedback()

  const formData = new FormData()
  formData.append('file', file)

  try {
    const { data } = await api.post('/api/admin/routes/import', formData)
    const route = data.route
    success.value = t('deliveryRoutes.importSuccess', {
      name: route.name,
      count: route.orderCount ?? route.orders?.length ?? 0,
    })
    await loadRoutes()
    if (route?.id) {
      router.push({ name: 'route-detail', params: { id: route.id } })
    }
  } catch (e) {
    const payload = e.response?.data
    error.value = payload?.error || t('deliveryRoutes.importFailed')
    invalidAddresses.value = Array.isArray(payload?.invalidAddresses)
      ? payload.invalidAddresses
      : []
  } finally {
    importing.value = false
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

onMounted(loadRoutes)
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiRoutes" :title="t('deliveryRoutes.title')" main>
        <BaseButton
          :label="importing ? t('deliveryRoutes.importing') : t('deliveryRoutes.import')"
          :icon="importing ? mdiLoading : mdiUpload"
          color="info"
          :disabled="importing"
          @click="triggerImport"
        />
      </SectionTitleLineWithButton>

      <input
        ref="fileInput"
        type="file"
        accept="application/pdf,.pdf"
        class="hidden"
        @change="onFileSelected"
      />

      <NotificationBar v-if="error" color="danger" :icon="mdiAlertCircle" class="mb-6">
        <div>
          <div>{{ error }}</div>
          <ul v-if="invalidAddresses.length" class="mt-2 list-disc pl-5 text-sm">
            <li v-for="(item, idx) in invalidAddresses" :key="idx">
              #{{ item.position }} {{ item.clientName }} — {{ item.address }}
              <span v-if="item.error">({{ item.error }})</span>
            </li>
          </ul>
        </div>
      </NotificationBar>

      <NotificationBar v-if="success" color="success" :icon="mdiCheckCircle" class="mb-6">
        {{ success }}
      </NotificationBar>

      <p class="mb-6 text-sm text-gray-500 dark:text-slate-400">
        {{ t('deliveryRoutes.hint') }}
      </p>

      <CardBox v-if="!loading && routes.length === 0">
        <CardBoxComponentEmpty />
      </CardBox>

      <CardBox v-else-if="loading" class="mb-6">
        <div class="flex items-center gap-2 p-4 text-gray-500">
          <BaseIcon :path="mdiLoading" class="animate-spin" size="20" />
          {{ t('deliveryRoutes.loading') }}
        </div>
      </CardBox>

      <CardBox v-else class="mb-6" has-table>
        <table>
          <thead>
            <tr>
              <th>{{ t('deliveryRoutes.name') }}</th>
              <th>{{ t('deliveryRoutes.orders') }}</th>
              <th>{{ t('deliveryRoutes.sourceFile') }}</th>
              <th>{{ t('deliveryRoutes.created') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="route in routes" :key="route.id">
              <td :data-label="t('deliveryRoutes.name')">
                {{ route.name }}
              </td>
              <td :data-label="t('deliveryRoutes.orders')">
                {{ route.orderCount }}
              </td>
              <td :data-label="t('deliveryRoutes.sourceFile')">
                {{ route.sourceFilename || '—' }}
              </td>
              <td :data-label="t('deliveryRoutes.created')">
                {{ formatDate(route.createdAt) }}
              </td>
              <td class="whitespace-nowrap before:hidden lg:w-1">
                <BaseButtons type="justify-start lg:justify-end" no-wrap>
                  <BaseButton
                    color="info"
                    :icon="mdiPencil"
                    small
                    @click="editRoute(route.id)"
                  />
                  <BaseButton
                    color="danger"
                    :icon="mdiTrashCan"
                    small
                    @click="askDelete(route)"
                  />
                </BaseButtons>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="border-t border-gray-100 p-3 lg:px-6 dark:border-slate-800">
          <BaseLevel>
            <small>{{ t('deliveryRoutes.count', { count: routes.length }) }}</small>
          </BaseLevel>
        </div>
      </CardBox>

      <CardBoxModal
        v-model="isDeleteModalActive"
        :title="t('deliveryRoutes.deleteConfirmTitle')"
        button="danger"
        :button-label="t('deliveryRoutes.delete')"
        has-cancel
        :is-processing="deleting"
        @confirm="confirmDelete"
      >
        <p>
          {{ t('deliveryRoutes.deleteConfirmBody', { name: deleteTarget?.name || '' }) }}
        </p>
      </CardBoxModal>
    </SectionMain>
  </LayoutAuthenticated>
</template>
