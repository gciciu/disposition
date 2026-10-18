<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { mdiWarehouse, mdiPlus, mdiPencil, mdiTrashCan } from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import CardBoxModal from '@/components/CardBoxModal.vue'
import CardBoxComponentEmpty from '@/components/CardBoxComponentEmpty.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import BaseLevel from '@/components/BaseLevel.vue'
import api from '@/api.js'
import { withoutCountry } from '@/utils/address.js'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()

const warehouses = ref([])
const loading = ref(false)
const deleteTarget = ref(null)
const deleting = ref(false)

const isDeleteModalActive = computed({
  get: () => !!deleteTarget.value,
  set: (value) => {
    if (!value) {
      deleteTarget.value = null
    }
  },
})

const loadWarehouses = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/api/admin/warehouses')
    warehouses.value = data.warehouses ?? []
  } catch (e) {
    toast.error(e.response?.data?.error || t('warehouses.loadFailed'))
  } finally {
    loading.value = false
  }
}

const openWarehouse = (id) => {
  router.push({ name: 'warehouse', params: { id } })
}

const askDelete = (warehouse) => {
  deleteTarget.value = warehouse
}

const confirmDelete = async () => {
  const target = deleteTarget.value
  if (!target) {
    return
  }

  deleting.value = true
  try {
    await api.delete(`/api/admin/warehouses/${target.id}`)
    toast.success(t('warehouses.deleted'))
    deleteTarget.value = null
    await loadWarehouses()
  } catch (e) {
    toast.error(e.response?.data?.error || t('warehouses.deleteFailed'))
    deleteTarget.value = null
  } finally {
    deleting.value = false
  }
}

onMounted(loadWarehouses)
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiWarehouse" :title="t('warehouses.title')" main>
        <BaseButton
          :label="t('warehouses.create')"
          :icon="mdiPlus"
          color="info"
          :to="{ name: 'warehouse-new' }"
        />
      </SectionTitleLineWithButton>

      <CardBox v-if="!loading && warehouses.length === 0">
        <CardBoxComponentEmpty />
      </CardBox>

      <CardBox v-else class="mb-6" has-table>
        <table>
          <thead>
            <tr>
              <th>{{ t('warehouses.name') }}</th>
              <th>{{ t('warehouses.address') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="warehouse in warehouses" :key="warehouse.id">
              <td :data-label="t('warehouses.name')">
                <span class="inline-flex flex-wrap items-center gap-2">
                  {{ warehouse.name }}
                  <span
                    v-if="warehouse.isDefault"
                    class="rounded bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-slate-700 dark:text-slate-200"
                  >
                    {{ t('warehouses.defaultBadge') }}
                  </span>
                </span>
              </td>
              <td :data-label="t('warehouses.address')">
                {{ withoutCountry(warehouse.formattedAddress || warehouse.address) }}
              </td>
              <td class="whitespace-nowrap before:hidden lg:w-1">
                <BaseButtons type="justify-start lg:justify-end" no-wrap>
                  <BaseButton
                    color="info"
                    :icon="mdiPencil"
                    small
                    @click="openWarehouse(warehouse.id)"
                  />
                  <BaseButton
                    color="danger"
                    :icon="mdiTrashCan"
                    small
                    @click="askDelete(warehouse)"
                  />
                </BaseButtons>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="border-t border-gray-100 p-3 lg:px-6 dark:border-slate-800">
          <BaseLevel>
            <small>{{ t('warehouses.count', { count: warehouses.length }) }}</small>
          </BaseLevel>
        </div>
      </CardBox>

      <CardBoxModal
        v-model="isDeleteModalActive"
        :title="t('warehouses.deleteConfirmTitle')"
        button="danger"
        :button-label="t('warehouses.delete')"
        has-cancel
        :is-processing="deleting"
        @confirm="confirmDelete"
      >
        <p>
          {{ t('warehouses.deleteConfirmBody', { name: deleteTarget?.name || '' }) }}
        </p>
      </CardBoxModal>
    </SectionMain>
  </LayoutAuthenticated>
</template>
