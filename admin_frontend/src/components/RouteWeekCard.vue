<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  mdiMap,
  mdiPencil,
  mdiTrashCan,
} from '@mdi/js'
import CardBox from '@/components/CardBox.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'

const props = defineProps({
  week: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['edit', 'delete-week', 'open-maps', 'open-detail'])

const { t } = useI18n()

const daysWithRoute = computed(() =>
  (props.week.days ?? []).filter((day) => day.route?.id),
)

const todayKey = () => {
  const now = new Date()
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, '0')
  const d = String(now.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/** Route used by card actions: today if present, otherwise first day with a route. */
const actionRoute = computed(() => {
  const today = todayKey()
  const todayDay = daysWithRoute.value.find((day) => day.date === today)
  return todayDay?.route ?? daysWithRoute.value[0]?.route ?? null
})

const cardTitle = computed(() => props.week.title || actionRoute.value?.name || '')

const weekdayLabel = (day) => {
  const map = {
    1: t('deliveryRoutes.weekdayShort.mon'),
    2: t('deliveryRoutes.weekdayShort.tue'),
    3: t('deliveryRoutes.weekdayShort.wed'),
    4: t('deliveryRoutes.weekdayShort.thu'),
    5: t('deliveryRoutes.weekdayShort.fri'),
  }
  return map[day.weekday] || day.label
}

const segmentTrackClass = (day) => {
  if (!day.route) {
    return 'bg-gray-200 opacity-40 dark:bg-slate-700'
  }
  if (day.route.completedAt) {
    // Undelivered / refused remainder on a finished route.
    return 'bg-red-200 dark:bg-red-900/45'
  }
  if (day.route.startedAt) {
    return 'bg-green-100 dark:bg-green-900/40'
  }
  return 'bg-sky-100 dark:bg-sky-900/40'
}

/** Green fill: delivered share. On a finished route this leaves refused stops as light red. */
const segmentProgress = (day) => {
  const route = day.route
  if (!route) {
    return 0
  }

  const total = route.orderCount ?? 0
  if (total <= 0) {
    return 0
  }

  if (route.completedAt) {
    const delivered = route.deliveredCount ?? 0
    return Math.min(100, Math.round((delivered / total) * 100))
  }

  return route.progress ?? 0
}

const openMaps = () => {
  if (actionRoute.value?.mapsUrl) {
    emit('open-maps', actionRoute.value)
  }
}

const editSelected = () => {
  if (actionRoute.value) {
    emit('edit', actionRoute.value)
  }
}

const deleteWeek = () => {
  if (daysWithRoute.value.length) {
    emit('delete-week', props.week)
  }
}

const openDetail = (routeId = actionRoute.value?.id) => {
  if (routeId) {
    emit('open-detail', routeId)
  }
}

const openDayDetail = (day) => {
  if (day.route?.id) {
    openDetail(day.route.id)
  }
}
</script>

<template>
  <CardBox class="mb-4">
    <div class="space-y-4 p-4 lg:px-6">
      <button
        type="button"
        class="text-left text-lg font-semibold hover:underline"
        :disabled="!actionRoute"
        @click="openDetail"
      >
        {{ cardTitle }}
      </button>

      <div>
        <div class="mb-1 grid grid-cols-5 gap-1 text-left text-xs font-medium text-gray-500 dark:text-slate-400">
          <button
            v-for="day in week.days"
            :key="`lbl-${day.date}`"
            type="button"
            class="text-left"
            :class="
              day.route
                ? 'cursor-pointer text-gray-900 hover:underline dark:text-slate-100'
                : 'cursor-default'
            "
            :disabled="!day.route"
            :title="day.route?.name || ''"
            @click="openDayDetail(day)"
          >
            {{ weekdayLabel(day) }}
          </button>
        </div>
        <div class="grid grid-cols-5 gap-1">
          <button
            v-for="day in week.days"
            :key="day.date"
            type="button"
            class="relative h-3 overflow-hidden rounded-sm"
            :class="[
              segmentTrackClass(day),
              day.route ? 'cursor-pointer' : 'cursor-default',
            ]"
            :disabled="!day.route"
            :title="day.route?.name || ''"
            @click="openDayDetail(day)"
          >
            <span
              v-if="day.route"
              class="absolute inset-y-0 left-0 transition-[width]"
              :class="day.route.startedAt ? 'bg-green-400 dark:bg-green-500' : 'bg-sky-500'"
              :style="{ width: `${segmentProgress(day)}%` }"
            />
          </button>
        </div>
      </div>

      <div class="text-sm text-gray-600 dark:text-slate-300">
        {{
          t('deliveryRoutes.completedCount', {
            completed: week.completedCount ?? 0,
            total: week.orderCount ?? 0,
          })
        }}
      </div>

      <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <div v-if="week.subcontractor">
          <span class="text-gray-500 dark:text-slate-400">{{ t('deliveryRoutes.subcontractor') }}:</span>
          {{ week.subcontractor }}
        </div>
        <div>
          <span class="text-gray-500 dark:text-slate-400">{{ t('deliveryRoutes.courier') }}:</span>
          {{ week.courier?.name || t('deliveryRoutes.courierUnassigned') }}
        </div>
      </div>

      <BaseButtons type="justify-start" no-wrap>
        <BaseButton
          :icon="mdiMap"
          color="info"
          small
          outline
          :title="t('deliveryRoutes.openMaps')"
          :disabled="!actionRoute?.mapsUrl"
          @click="openMaps"
        />
        <BaseButton
          :icon="mdiPencil"
          color="info"
          small
          :title="t('deliveryRoutes.edit')"
          :disabled="!actionRoute || !!actionRoute.startedAt"
          @click="editSelected"
        />
        <BaseButton
          :icon="mdiTrashCan"
          color="danger"
          small
          :title="t('deliveryRoutes.deleteWeek')"
          :disabled="!daysWithRoute.length"
          @click="deleteWeek"
        />
      </BaseButtons>
    </div>
  </CardBox>
</template>
