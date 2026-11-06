<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import {
  mdiRoutes,
  mdiUpload,
  mdiPencil,
  mdiTrashCan,
  mdiMap,
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
import BaseIcon from '@/components/BaseIcon.vue'
import RouteWeekCard from '@/components/RouteWeekCard.vue'
import RouteAssignModal from '@/components/RouteAssignModal.vue'
import api from '@/api.js'
import { applyRouteListPatch, useRealtime } from '@/composables/useRealtime.js'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()

const weeks = ref([])
const unscheduled = ref([])
const loading = ref(false)
const deleting = ref(false)
const deleteTarget = ref(null)
const assignOpen = ref(false)
const assignMode = ref('import')
const assignRoute = ref(null)

const isDeleteModalActive = computed({
  get: () => !!deleteTarget.value,
  set: (value) => {
    if (!value) {
      deleteTarget.value = null
    }
  },
})

const isEmpty = computed(
  () => !loading.value && weeks.value.length === 0 && unscheduled.value.length === 0,
)

const loadRoutes = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/api/admin/routes')
    weeks.value = data.weeks ?? []
    unscheduled.value = data.unscheduled ?? []
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.loadFailed'))
  } finally {
    loading.value = false
  }
}

const openImport = () => {
  assignMode.value = 'import'
  assignRoute.value = null
  assignOpen.value = true
}

const openEdit = (route) => {
  assignMode.value = 'edit'
  assignRoute.value = route
  assignOpen.value = true
}

const openDetail = (id) => {
  router.push({ name: 'route-detail', params: { id } })
}

const openMaps = (route) => {
  if (route?.mapsUrl) {
    window.open(route.mapsUrl, '_blank', 'noopener,noreferrer')
  }
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
  try {
    await api.delete(`/api/admin/routes/${target.id}`)
    toast.success(t('deliveryRoutes.deleted'))
    deleteTarget.value = null
    await loadRoutes()
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.deleteFailed'))
    deleteTarget.value = null
  } finally {
    deleting.value = false
  }
}

const recalculateWeekTotals = (week) => {
  let orderCount = 0
  let completedCount = 0
  for (const day of week.days ?? []) {
    if (!day.route) {
      continue
    }
    orderCount += day.route.orderCount ?? 0
    completedCount += day.route.completedCount ?? day.route.deliveredCount ?? 0
  }
  week.orderCount = orderCount
  week.completedCount = completedCount
}

const patchRouteFromEvent = (routePayload) => {
  if (!routePayload?.id) {
    return
  }

  for (const week of weeks.value) {
    for (const day of week.days ?? []) {
      if (day.route?.id === routePayload.id) {
        day.route = applyRouteListPatch(day.route, routePayload)
        recalculateWeekTotals(week)
        return
      }
    }
  }

  const index = unscheduled.value.findIndex((item) => item.id === routePayload.id)
  if (index !== -1) {
    unscheduled.value[index] = applyRouteListPatch(unscheduled.value[index], routePayload)
  }
}

const { start: startRealtime } = useRealtime({
  onEvent: (event, data) => {
    if (
      event === 'route.started' ||
      event === 'route.completed' ||
      event === 'route.continued' ||
      event === 'route.delayed' ||
      event === 'order.completed' ||
      event === 'order.refused'
    ) {
      patchRouteFromEvent(data?.route)
    }
  },
})

onMounted(() => {
  void loadRoutes()
  startRealtime()
})
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiRoutes" :title="t('deliveryRoutes.title')" main>
        <BaseButton
          :label="t('deliveryRoutes.import')"
          :icon="mdiUpload"
          color="info"
          @click="openImport"
        />
      </SectionTitleLineWithButton>

      <CardBox v-if="isEmpty">
        <CardBoxComponentEmpty />
      </CardBox>

      <CardBox v-else-if="loading" class="mb-6">
        <div class="flex items-center gap-2 p-4 text-gray-500">
          <BaseIcon :path="mdiLoading" class="animate-spin" size="20" />
          {{ t('deliveryRoutes.loading') }}
        </div>
      </CardBox>

      <template v-else>
        <RouteWeekCard
          v-for="week in weeks"
          :key="`${week.courier?.id}-${week.weekStart}`"
          :week="week"
          @edit="openEdit"
          @delete="askDelete"
          @open-maps="openMaps"
          @open-detail="openDetail"
        />

        <div v-if="unscheduled.length" class="mt-6 mb-2">
          <h3 class="mb-3 text-base font-semibold">{{ t('deliveryRoutes.unscheduledTitle') }}</h3>
          <CardBox
            v-for="route in unscheduled"
            :key="route.id"
            class="mb-4"
          >
            <div class="space-y-3 p-4 lg:px-6">
              <div class="text-lg font-semibold">{{ route.name }}</div>
              <div class="text-sm text-gray-600 dark:text-slate-300">
                {{
                  t('deliveryRoutes.completedCount', {
                    completed: route.completedCount ?? route.deliveredCount ?? 0,
                    total: route.orderCount ?? 0,
                  })
                }}
              </div>
              <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <div v-if="route.subcontractor">
                  <span class="text-gray-500 dark:text-slate-400">{{ t('deliveryRoutes.subcontractor') }}:</span>
                  {{ route.subcontractor }}
                </div>
                <div>
                  <span class="text-gray-500 dark:text-slate-400">{{ t('deliveryRoutes.courier') }}:</span>
                  {{ route.courier?.name || t('deliveryRoutes.courierUnassigned') }}
                </div>
              </div>
              <BaseButtons type="justify-start" no-wrap>
                <BaseButton
                  :icon="mdiMap"
                  color="info"
                  small
                  outline
                  :title="t('deliveryRoutes.openMaps')"
                  :disabled="!route.mapsUrl"
                  @click="openMaps(route)"
                />
                <BaseButton
                  :icon="mdiPencil"
                  color="info"
                  small
                  :title="t('deliveryRoutes.edit')"
                  :disabled="!!route.startedAt"
                  @click="openEdit(route)"
                />
                <BaseButton
                  :icon="mdiTrashCan"
                  color="danger"
                  small
                  :title="t('deliveryRoutes.delete')"
                  @click="askDelete(route)"
                />
              </BaseButtons>
            </div>
          </CardBox>
        </div>
      </template>

      <RouteAssignModal
        v-model="assignOpen"
        :mode="assignMode"
        :route="assignRoute"
        @saved="loadRoutes"
      />

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
