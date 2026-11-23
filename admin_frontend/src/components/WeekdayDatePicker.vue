<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseButton from '@/components/BaseButton.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import { getLocaleTag } from '@/i18n'
import { mdiCalendar, mdiChevronLeft, mdiChevronRight } from '@mdi/js'

const DATE_PLACEHOLDER_LETTERS = {
  de: { day: 'TT', month: 'MM', year: 'JJJJ' },
  en: { day: 'DD', month: 'MM', year: 'YYYY' },
}

const buildDatePlaceholder = (localeTag, language) => {
  const letters = DATE_PLACEHOLDER_LETTERS[language] || DATE_PLACEHOLDER_LETTERS.en
  try {
    return new Intl.DateTimeFormat(localeTag, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    })
      .formatToParts(new Date(2020, 0, 31))
      .map((part) => {
        if (part.type === 'day') {
          return letters.day
        }
        if (part.type === 'month') {
          return letters.month
        }
        if (part.type === 'year') {
          return letters.year
        }
        if (part.type === 'literal') {
          return part.value
        }
        return ''
      })
      .join('')
  } catch {
    return language === 'de' ? 'TT.MM.JJJJ' : 'DD/MM/YYYY'
  }
}

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
  /** When true, any occupied day (not only started) cannot be selected. */
  blockOccupied: {
    type: Boolean,
    default: false,
  },
  disabled: Boolean,
})

const emit = defineEmits(['update:modelValue'])

const { t, locale } = useI18n()

const localeTag = computed(() => getLocaleTag(locale.value))
const datePlaceholder = computed(() =>
  buildDatePlaceholder(localeTag.value, locale.value),
)

const rootRef = ref(null)
const inputRef = ref(null)
const panelRef = ref(null)
const open = ref(false)
const viewMonth = ref(new Date())
const draftValue = ref(props.modelValue || '')
const inputResetKey = ref(0)
const validationError = ref(null)
const panelStyle = ref({})

const occupiedMap = computed(() => {
  const map = new Map()
  for (const day of props.occupiedDays ?? []) {
    if (day?.date) {
      map.set(day.date, !!day.started)
    }
  }
  return map
})

const todayKey = () => formatDateKey(new Date())

const formatDateKey = (date) => {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

const isValidDateKey = (key) => {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(key ?? ''))
  if (!match) {
    return false
  }
  const y = Number(match[1])
  const m = Number(match[2])
  const d = Number(match[3])
  const date = new Date(y, m - 1, d)
  return date.getFullYear() === y && date.getMonth() === m - 1 && date.getDate() === d
}

const dateDisableReason = (key) => {
  if (!isValidDateKey(key)) {
    return 'invalid'
  }
  if (props.allowDate === key) {
    return null
  }
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key)
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  const dow = date.getDay()
  if (dow === 0 || dow === 6) {
    return 'weekend'
  }
  if (key < todayKey()) {
    return 'past'
  }
  const started = occupiedMap.value.get(key) === true
  const occupied = occupiedMap.value.has(key)
  if (props.blockOccupied && occupied) {
    return 'occupied'
  }
  if (started) {
    return 'started'
  }
  return null
}

const reasonMessage = (reason) => {
  switch (reason) {
    case 'weekend':
      return t('deliveryRoutes.dateWeekend')
    case 'past':
      return t('deliveryRoutes.datePast')
    case 'occupied':
      return t('deliveryRoutes.dateOccupied')
    case 'started':
      return t('deliveryRoutes.dateStarted')
    case 'invalid':
    default:
      return t('deliveryRoutes.dateInvalid')
  }
}

const monthLabel = computed(() =>
  viewMonth.value.toLocaleDateString(localeTag.value, { month: 'long', year: 'numeric' }),
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
    const started = occupiedMap.value.get(key) === true
    const occupied = occupiedMap.value.has(key)
    const reason = dateDisableReason(key)

    cells.push({
      key,
      empty: false,
      day,
      date: key,
      disabled: props.disabled || reason != null,
      occupied,
      started,
      selected: props.modelValue === key,
      isToday: key === today,
    })
  }

  return cells
})

const syncViewMonth = (value) => {
  if (!value || !isValidDateKey(value)) {
    return
  }
  const [y, m] = value.split('-').map(Number)
  const current = viewMonth.value
  if (current.getFullYear() !== y || current.getMonth() !== m - 1) {
    viewMonth.value = new Date(y, m - 1, 1)
  }
}

watch(
  () => props.modelValue,
  (value) => {
    draftValue.value = value || ''
    validationError.value = null
    syncViewMonth(value)
  },
  { immediate: true },
)

const updatePanelPosition = () => {
  const anchor = rootRef.value
  if (!anchor) {
    return
  }
  const rect = anchor.getBoundingClientRect()
  const width = 288
  const preferredTop = rect.bottom + 4
  const spaceBelow = window.innerHeight - preferredTop
  const estimatedHeight = 360
  const top =
    spaceBelow < estimatedHeight && rect.top > estimatedHeight
      ? Math.max(8, rect.top - estimatedHeight - 4)
      : preferredTop
  let left = rect.left
  if (left + width > window.innerWidth - 8) {
    left = Math.max(8, window.innerWidth - width - 8)
  }
  panelStyle.value = {
    top: `${top}px`,
    left: `${left}px`,
    width: `${width}px`,
  }
}

const closeCalendar = () => {
  open.value = false
}

const openCalendar = async () => {
  if (props.disabled) {
    return
  }
  syncViewMonth(props.modelValue || draftValue.value)
  open.value = true
  await nextTick()
  updatePanelPosition()
}

const toggleCalendar = async () => {
  if (open.value) {
    closeCalendar()
    return
  }
  await openCalendar()
}

const commitDate = (raw, { close = true } = {}) => {
  if (props.disabled) {
    return false
  }

  const key = raw ? String(raw) : ''
  if (!key) {
    draftValue.value = ''
    validationError.value = null
    emit('update:modelValue', null)
    if (close) {
      closeCalendar()
    }
    return true
  }

  const reason = dateDisableReason(key)
  if (reason) {
    draftValue.value = props.modelValue || ''
    validationError.value = reasonMessage(reason)
    inputResetKey.value += 1
    return false
  }

  draftValue.value = key
  validationError.value = null
  emit('update:modelValue', key)
  syncViewMonth(key)
  if (close) {
    closeCalendar()
  }
  return true
}

const onNativeChange = (event) => {
  commitDate(event.target.value || null)
}

const onNativeInput = (event) => {
  draftValue.value = event.target.value || ''
  if (validationError.value) {
    validationError.value = null
  }
}

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
  commitDate(cell.date)
}

const WEEKDAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']

const selectedDateLabel = computed(() => {
  const value = props.modelValue
  if (!value || !isValidDateKey(value)) {
    return null
  }
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value))
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  return t(`deliveryRoutes.weekdayFull.${WEEKDAY_KEYS[date.getDay()]}`)
})

const onDocumentPointerDown = (event) => {
  if (!open.value) {
    return
  }
  const target = event.target
  if (rootRef.value?.contains(target) || panelRef.value?.contains(target)) {
    return
  }
  closeCalendar()
}

const onWindowChange = () => {
  if (open.value) {
    updatePanelPosition()
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
  window.addEventListener('resize', onWindowChange)
  window.addEventListener('scroll', onWindowChange, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  window.removeEventListener('resize', onWindowChange)
  window.removeEventListener('scroll', onWindowChange, true)
})
</script>

<template>
  <div ref="rootRef" class="relative">
    <div class="flex items-center gap-3">
      <div class="relative min-w-0 flex-1">
        <input
          :key="`${inputResetKey}-${localeTag}`"
          ref="inputRef"
          type="date"
          class="weekday-date-picker__input h-12 w-full max-w-full rounded-sm border border-gray-700 bg-white px-3 py-2 pr-10 focus:ring-3 focus:outline-hidden dark:bg-slate-800 dark:placeholder-gray-400 [&::-webkit-calendar-picker-indicator]:hidden"
          :class="{ 'weekday-date-picker__input--empty': !draftValue }"
          :lang="localeTag"
          :value="draftValue"
          :disabled="disabled"
          :aria-label="datePlaceholder"
          :title="datePlaceholder"
          :placeholder="datePlaceholder"
          @input="onNativeInput"
          @change="onNativeChange"
          @keydown.enter.prevent="commitDate(draftValue || null)"
        />
        <span
          v-if="!draftValue"
          class="pointer-events-none absolute inset-y-0 left-0 flex items-center px-3 pr-10 text-sm text-gray-400 dark:text-slate-500"
          aria-hidden="true"
        >
          {{ datePlaceholder }}
        </span>
        <button
          type="button"
          class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-gray-500 hover:text-gray-800 disabled:cursor-not-allowed disabled:opacity-40 dark:text-slate-400 dark:hover:text-slate-100"
          :disabled="disabled"
          :aria-expanded="open"
          :aria-label="t('deliveryRoutes.openCalendar')"
          @click="toggleCalendar"
        >
          <BaseIcon :path="mdiCalendar" :size="18" />
        </button>
      </div>
      <div
        v-if="selectedDateLabel"
        class="shrink-0 text-lg font-medium text-gray-800 dark:text-slate-100"
      >
        {{ selectedDateLabel }}
      </div>
    </div>

    <p v-if="validationError" class="mt-1 text-sm text-red-600 dark:text-red-400">
      {{ validationError }}
    </p>

    <Teleport to="body">
      <div
        v-if="open"
        ref="panelRef"
        class="fixed z-[100] rounded-sm border border-gray-200 bg-white p-3 shadow-lg dark:border-slate-700 dark:bg-slate-900"
        :style="panelStyle"
      >
        <div class="mb-3 flex items-center justify-between gap-2">
          <BaseButton
            :icon="mdiChevronLeft"
            small
            color="whiteDark"
            :disabled="disabled"
            @click="prevMonth"
          />
          <div class="text-sm font-semibold capitalize">{{ monthLabel }}</div>
          <BaseButton
            :icon="mdiChevronRight"
            small
            color="whiteDark"
            :disabled="disabled"
            @click="nextMonth"
          />
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
              invisible: cell.empty,
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
    </Teleport>
  </div>
</template>

<style scoped>
/* Hide the browser's native empty-date glyphs (incl. focused "дд.мм.гггг") so only the overlay placeholder shows. */
.weekday-date-picker__input--empty::-webkit-datetime-edit {
  color: transparent;
}

.weekday-date-picker__input--empty::-webkit-datetime-edit-fields-wrapper {
  opacity: 0;
}
</style>
