<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseButton from '@/components/BaseButton.vue'
import { mdiChevronLeft, mdiChevronRight } from '@mdi/js'

const props = defineProps({
  modelValue: {
    type: String,
    default: null,
  },
  /** @type {{ date: string, started?: boolean }[]} */
  occupiedDays: {
    type: Array,
    default: () => [],
  },
  /** Allow selecting this date even if it would otherwise be disabled (current edit date). */
  allowDate: {
    type: String,
    default: null,
  },
  disabled: Boolean,
})

const emit = defineEmits(['update:modelValue'])

const { t, locale } = useI18n()

const viewMonth = ref(new Date())

const occupiedMap = computed(() => {
  const map = new Map()
  for (const day of props.occupiedDays ?? []) {
    if (day?.date) {
      map.set(day.date, !!day.started)
    }
  }
  return map
})

const todayKey = () => {
  const now = new Date()
  return formatDateKey(now)
}

const formatDateKey = (date) => {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

const monthLabel = computed(() =>
  viewMonth.value.toLocaleDateString(locale.value, { month: 'long', year: 'numeric' }),
)

const weekdayHeaders = computed(() => [
  t('deliveryRoutes.weekdayShort.mon'),
  t('deliveryRoutes.weekdayShort.tue'),
  t('deliveryRoutes.weekdayShort.wed'),
  t('deliveryRoutes.weekdayShort.thu'),
  t('deliveryRoutes.weekdayShort.fri'),
  t('deliveryRoutes.weekdayShort.sat'),
  t('deliveryRoutes.weekdayShort.sun'),
])

const calendarDays = computed(() => {
  const year = viewMonth.value.getFullYear()
  const month = viewMonth.value.getMonth()
  const first = new Date(year, month, 1)
  // Monday-first index: Mon=0 … Sun=6
  const startOffset = (first.getDay() + 6) % 7
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const today = todayKey()
  const cells = []

  for (let i = 0; i < startOffset; i += 1) {
    cells.push({ key: `pad-${i}`, empty: true })
  }

  for (let day = 1; day <= daysInMonth; day += 1) {
    const date = new Date(year, month, day)
    const key = formatDateKey(date)
    const dow = date.getDay() // 0 Sun … 6 Sat
    const isWeekend = dow === 0 || dow === 6
    const isPast = key < today
    const started = occupiedMap.value.get(key) === true
    const occupied = occupiedMap.value.has(key)
    const isAllowedCurrent = props.allowDate === key
    const disabled =
      props.disabled ||
      isWeekend ||
      (isPast && !isAllowedCurrent) ||
      (started && !isAllowedCurrent)

    cells.push({
      key,
      empty: false,
      day,
      date: key,
      disabled,
      occupied,
      started,
      selected: props.modelValue === key,
      isToday: key === today,
    })
  }

  return cells
})

watch(
  () => props.modelValue,
  (value) => {
    if (!value) {
      return
    }
    const [y, m] = value.split('-').map(Number)
    if (!y || !m) {
      return
    }
    const current = viewMonth.value
    if (current.getFullYear() !== y || current.getMonth() !== m - 1) {
      viewMonth.value = new Date(y, m - 1, 1)
    }
  },
  { immediate: true },
)

const prevMonth = () => {
  const d = new Date(viewMonth.value)
  d.setMonth(d.getMonth() - 1)
  viewMonth.value = d
}

const nextMonth = () => {
  const d = new Date(viewMonth.value)
  d.setMonth(d.getMonth() + 1)
  viewMonth.value = d
}

const selectDay = (cell) => {
  if (cell.empty || cell.disabled) {
    return
  }
  emit('update:modelValue', cell.date)
}

const WEEKDAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']

const selectedDateLabel = computed(() => {
  if (!props.modelValue) {
    return null
  }
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(props.modelValue))
  if (!match) {
    return props.modelValue
  }
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  return t(`deliveryRoutes.weekdayFull.${WEEKDAY_KEYS[date.getDay()]}`)
})
</script>

<template>
  <div class="rounded-sm border border-gray-200 p-3 dark:border-slate-700">
    <div class="mb-3 flex items-center justify-between gap-2">
      <BaseButton :icon="mdiChevronLeft" small color="whiteDark" :disabled="disabled" @click="prevMonth" />
      <div class="text-sm font-semibold capitalize">{{ monthLabel }}</div>
      <BaseButton :icon="mdiChevronRight" small color="whiteDark" :disabled="disabled" @click="nextMonth" />
    </div>

    <div class="mb-1 grid grid-cols-7 gap-1 text-center text-xs text-gray-500 dark:text-slate-400">
      <div v-for="label in weekdayHeaders" :key="label">{{ label }}</div>
    </div>

    <div class="grid grid-cols-7 gap-1">
      <button
        v-for="cell in calendarDays"
        :key="cell.key"
        type="button"
        class="flex h-9 items-center justify-center rounded-sm text-sm transition-colors"
        :class="{
          'invisible': cell.empty,
          'cursor-not-allowed opacity-35': !cell.empty && cell.disabled,
          'bg-amber-200 text-amber-950 dark:bg-amber-700/60 dark:text-amber-50':
            !cell.empty && cell.occupied && !cell.started && !cell.selected,
          'bg-sky-200 text-sky-950 dark:bg-sky-800/70 dark:text-sky-50':
            !cell.empty && cell.started && !cell.selected,
          'bg-blue-600 text-white': !cell.empty && cell.selected,
          'hover:bg-gray-100 dark:hover:bg-slate-700':
            !cell.empty && !cell.disabled && !cell.selected && !cell.occupied,
          'ring-1 ring-blue-400': !cell.empty && cell.isToday && !cell.selected,
        }"
        :disabled="cell.empty || cell.disabled"
        @click="selectDay(cell)"
      >
        <span v-if="!cell.empty">{{ cell.day }}</span>
      </button>
    </div>

    <div
      v-if="selectedDateLabel"
      class="mt-3 text-sm font-medium text-gray-800 dark:text-slate-100"
    >
      {{ selectedDateLabel }}
    </div>

    <div class="mt-3 flex flex-wrap gap-3 text-xs text-gray-500 dark:text-slate-400">
      <span class="inline-flex items-center gap-1.5">
        <span class="inline-block h-3 w-3 rounded-sm bg-amber-200 dark:bg-amber-700/60" />
        {{ t('deliveryRoutes.calendarLegendOccupied') }}
      </span>
      <span class="inline-flex items-center gap-1.5">
        <span class="inline-block h-3 w-3 rounded-sm bg-sky-200 dark:bg-sky-800/70" />
        {{ t('deliveryRoutes.calendarLegendStarted') }}
      </span>
    </div>
  </div>
</template>
