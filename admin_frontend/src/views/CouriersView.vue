<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { mdiTruckDelivery, mdiPlus, mdiEye, mdiTrashCan, mdiContentCopy, mdiAndroid } from '@mdi/js'
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
import api, { showApiError } from '@/api.js'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()

const couriers = ref([])
const loading = ref(false)
const deleteTarget = ref(null)
const deleting = ref(false)
const appRelease = ref(null)

const downloadPageUrl = computed(() => {
  if (appRelease.value?.downloadPageUrl) {
    return appRelease.value.downloadPageUrl
  }
  if (typeof window !== 'undefined') {
    return `${window.location.origin}/download`
  }
  return '/download'
})

const isDeleteModalActive = computed({
  get: () => !!deleteTarget.value,
  set: (value) => {
    if (!value) {
      deleteTarget.value = null
    }
  },
})

const loadCouriers = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/api/admin/couriers')
    couriers.value = data.couriers ?? []
  } catch (e) {
    showApiError(toast, e, t('couriers.loadFailed'))
  } finally {
    loading.value = false
  }
}

const loadAppRelease = async () => {
  try {
    const { data } = await api.get('/api/driver/app-version')
    appRelease.value = data
  } catch {
    appRelease.value = null
  }
}

const copyDownloadLink = async () => {
  try {
    await navigator.clipboard.writeText(downloadPageUrl.value)
    toast.success(t('couriers.appLinkCopied'))
  } catch {
    toast.error(t('couriers.appLinkCopyFailed'))
  }
}

const openCourier = (id) => {
  router.push({ name: 'courier', params: { id } })
}

const askDelete = (courier) => {
  if (!courier.canDelete) {
    toast.warning(t('couriers.deleteBlocked'))
    return
  }
  deleteTarget.value = courier
}

const confirmDelete = async () => {
  const target = deleteTarget.value
  if (!target) {
    return
  }

  deleting.value = true
  try {
    await api.delete(`/api/admin/couriers/${target.id}`)
    toast.success(t('couriers.deleted'))
    deleteTarget.value = null
    await loadCouriers()
  } catch (e) {
    showApiError(toast, e, t('couriers.deleteFailed'))
    deleteTarget.value = null
  } finally {
    deleting.value = false
  }
}

onMounted(() => {
  loadCouriers()
  loadAppRelease()
})
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiTruckDelivery" :title="t('couriers.title')" main>
        <BaseButton
          :label="t('couriers.create')"
          :icon="mdiPlus"
          color="info"
          :to="{ name: 'courier-new' }"
        />
      </SectionTitleLineWithButton>

      <CardBox class="mb-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="flex gap-3">
            <BaseIcon :path="mdiAndroid" class="text-blue-600" size="24" />
            <div>
              <p class="font-semibold">{{ t('couriers.appDownloadTitle') }}</p>
              <p class="text-sm text-gray-500 dark:text-slate-400">
                {{ t('couriers.appDownloadHelp') }}
              </p>
              <p class="mt-2 break-all text-sm text-blue-700 dark:text-blue-300">
                {{ downloadPageUrl }}
              </p>
              <p
                v-if="appRelease"
                class="mt-1 text-xs text-gray-500 dark:text-slate-400"
              >
                {{
                  appRelease.apkAvailable
                    ? t('couriers.appVersion', { version: appRelease.versionName })
                    : t('couriers.appNotPublished')
                }}
              </p>
            </div>
          </div>
          <BaseButtons type="justify-start sm:justify-end" no-wrap>
            <BaseButton
              :label="t('couriers.copyAppLink')"
              :icon="mdiContentCopy"
              color="info"
              outline
              small
              @click="copyDownloadLink"
            />
            <BaseButton
              :label="t('couriers.openAppPage')"
              :icon="mdiAndroid"
              color="info"
              small
              :href="downloadPageUrl"
              target="_blank"
            />
          </BaseButtons>
        </div>
      </CardBox>

      <CardBox v-if="!loading && couriers.length === 0">
        <CardBoxComponentEmpty />
      </CardBox>

      <CardBox v-else class="mb-6" has-table>
        <table>
          <thead>
            <tr>
              <th>{{ t('couriers.name') }}</th>
              <th>{{ t('couriers.login') }}</th>
              <th>{{ t('couriers.phone') }}</th>
              <th>{{ t('couriers.subcontractor') }}</th>
              <th>{{ t('couriers.status') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="courier in couriers" :key="courier.id">
              <td :data-label="t('couriers.name')">
                {{ courier.name }}
              </td>
              <td :data-label="t('couriers.login')">
                {{ courier.login }}
              </td>
              <td :data-label="t('couriers.phone')">
                {{ courier.phone || '—' }}
              </td>
              <td :data-label="t('couriers.subcontractor')">
                {{ courier.subcontractor || '—' }}
              </td>
              <td :data-label="t('couriers.status')">
                {{ courier.isActive ? t('couriers.active') : t('couriers.inactive') }}
              </td>
              <td class="whitespace-nowrap before:hidden lg:w-1">
                <BaseButtons type="justify-start lg:justify-end" no-wrap>
                  <BaseButton
                    color="info"
                    :icon="mdiEye"
                    small
                    @click="openCourier(courier.id)"
                  />
                  <BaseButton
                    color="danger"
                    :icon="mdiTrashCan"
                    small
                    :disabled="!courier.canDelete"
                    @click="askDelete(courier)"
                  />
                </BaseButtons>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="border-t border-gray-100 p-3 lg:px-6 dark:border-slate-800">
          <BaseLevel>
            <small>{{ t('couriers.count', { count: couriers.length }) }}</small>
          </BaseLevel>
        </div>
      </CardBox>

      <CardBoxModal
        v-model="isDeleteModalActive"
        :title="t('couriers.deleteConfirmTitle')"
        button="danger"
        :button-label="t('couriers.delete')"
        has-cancel
        :is-processing="deleting"
        @confirm="confirmDelete"
      >
        <p>
          {{ t('couriers.deleteConfirmBody', { name: deleteTarget?.name || '' }) }}
        </p>
      </CardBoxModal>
    </SectionMain>
  </LayoutAuthenticated>
</template>
