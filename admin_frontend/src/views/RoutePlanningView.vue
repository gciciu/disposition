<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  mdiMapMarkerPath,
  mdiPlus,
  mdiDelete,
  mdiArrowUp,
  mdiArrowDown,
  mdiGoogleMaps,
  mdiCheckCircle,
  mdiAlertCircle,
  mdiLoading,
} from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import NotificationBar from '@/components/NotificationBar.vue'
import api from '@/api.js'

const { t } = useI18n()

const VALIDATE_DELAY_MS = 700
const VALIDATE_TIMEOUT_MS = 60000

const defaultAddresses = [
  'Alexanderplatz 1, 10178 Berlin',
  'Potsdamer Straße 58, 14469 Potsdam',
  'Markt 9, 14776 Brandenburg an der Havel',
  'Breite Straße 54, 39104 Magdeburg',
  'Marktplatz 10, 06108 Halle (Saale)',
  'Augustusplatz 1, 04109 Leipzig',
]

const createPoint = (address = '') => ({
  id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
  address,
  status: 'idle', // idle | validating | valid | invalid
  error: '',
  suggestion: null,
  partialMatch: false,
})

const points = ref(defaultAddresses.map((address) => createPoint(address)))
const loading = ref(false)
const error = ref('')
const result = ref(null)
const apiKeyMissing = ref(false)
const directionsDenied = ref(false)
const validateTimers = new Map()
/** @type {Map<string, string>} pointId → latest address waiting for validation */
const pendingAddresses = new Map()
/** @type {Map<string, object>} trimmed address → last geocode payload */
const geocodeCache = new Map()
let validateQueueRunning = false

const isApiKeyError = (message) =>
  typeof message === 'string' && message.includes('GOOGLE_MAPS_API_KEY')

const isDirectionsDeniedError = (message) =>
  typeof message === 'string' &&
  (message.includes('request denied') || message.includes('Directions API') || message.includes('not authorized'))

const markApiKeyMissing = (message) => {
  apiKeyMissing.value = true
  error.value = message || t('routePlanning.apiKeyMissing')
  points.value.forEach((point) => {
    if (point.address.trim()) {
      point.status = 'invalid'
      point.error = t('routePlanning.apiKeyMissing')
      point.suggestion = null
      point.partialMatch = false
    }
  })
}

const canBuild = computed(() => {
  const filled = points.value.filter((p) => p.address.trim() !== '')
  const hasInvalid = points.value.some(
    (p) => p.address.trim() !== '' && (p.status === 'invalid' || p.status === 'validating'),
  )
  return filled.length >= 2 && !loading.value && !hasInvalid
})

/** Travel time for the leg that ends at the given stop index (null for start / missing). */
const legByToIndex = computed(() => {
  const map = {}
  if (!result.value?.ok) {
    return map
  }
  for (const segment of result.value.segments || []) {
    map[segment.toIndex] = segment
  }
  return map
})

const pointBorderClass = (point) => {
  if (point.status === 'invalid') {
    return 'border-red-500 bg-red-50 dark:bg-red-900/20'
  }
  if (point.status === 'valid' && point.suggestion) {
    return 'border-amber-400 bg-amber-50 dark:bg-amber-900/20'
  }
  if (point.status === 'valid') {
    return 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
  }
  if (point.status === 'validating') {
    return 'border-sky-400 bg-sky-50 dark:bg-sky-900/20'
  }
  return 'border-gray-200 dark:border-slate-700'
}

const clearPointValidation = (point) => {
  point.status = 'idle'
  point.error = ''
  point.suggestion = null
  point.partialMatch = false
}

const cancelPointValidation = (pointId) => {
  const timer = validateTimers.get(pointId)
  if (timer) {
    clearTimeout(timer)
    validateTimers.delete(pointId)
  }
  pendingAddresses.delete(pointId)
}

const applyValidationResult = (point, data) => {
  if (!data?.valid) {
    point.status = 'invalid'
    point.error = data?.error || t('routePlanning.addressNotFound')
    point.suggestion = null
    point.partialMatch = false
    return
  }

  point.status = 'valid'
  point.error = ''
  point.partialMatch = Boolean(data.partialMatch)
  // Exact valid hits: no suggestion banner (backend may still send reformatted text).
  point.suggestion = point.partialMatch ? data.suggestion || null : null
}

/**
 * PHP built-in server boots Symfony slowly (~5s/request) and is single-threaded.
 * Batch all pending addresses into one HTTP call so we pay kernel boot once.
 */
const drainValidateQueue = async () => {
  if (validateQueueRunning) {
    return
  }
  validateQueueRunning = true

  try {
    while (pendingAddresses.size > 0) {
      const batch = []
      for (const [pointId, address] of pendingAddresses.entries()) {
        const point = points.value.find((p) => p.id === pointId)
        if (!point) {
          pendingAddresses.delete(pointId)
          continue
        }
        const current = point.address.trim()
        if (!current || current !== address) {
          pendingAddresses.delete(pointId)
          if (!current) {
            clearPointValidation(point)
          }
          continue
        }
        batch.push({ point, address })
      }
      pendingAddresses.clear()

      if (!batch.length) {
        continue
      }

      const ok = await validatePointsBatch(batch)
      if (!ok) {
        pendingAddresses.clear()
        break
      }
    }
  } finally {
    validateQueueRunning = false
    if (pendingAddresses.size > 0) {
      drainValidateQueue()
    }
  }
}

const enqueueValidate = (point, address, { startDrain = true } = {}) => {
  if (!address) {
    pendingAddresses.delete(point.id)
    clearPointValidation(point)
    return
  }
  pendingAddresses.set(point.id, address)
  point.status = 'validating'
  point.error = ''
  if (startDrain) {
    drainValidateQueue()
  }
}

const validatePointsBatch = async (batch) => {
  if (apiKeyMissing.value) {
    batch.forEach(({ point }) => {
      point.status = 'invalid'
      point.error = t('routePlanning.apiKeyMissing')
    })
    return false
  }

  const uncached = []
  for (const item of batch) {
    const cached = geocodeCache.get(item.address)
    if (cached) {
      if (item.point.address.trim() === item.address) {
        applyValidationResult(item.point, cached)
      }
    } else {
      item.point.status = 'validating'
      item.point.error = ''
      uncached.push(item)
    }
  }

  if (!uncached.length) {
    return true
  }

  try {
    const { data } = await api.post(
      '/api/admin/route-planning/validate-addresses',
      { addresses: uncached.map((item) => item.address) },
      { timeout: VALIDATE_TIMEOUT_MS },
    )
    const results = data?.points || []

    uncached.forEach((item, index) => {
      const pointData = results[index]
      if (!pointData) {
        return
      }
      geocodeCache.set(item.address, pointData)
      if (item.point.address.trim() === item.address) {
        applyValidationResult(item.point, pointData)
      }
    })
    return true
  } catch (err) {
    const message = err.response?.data?.error || err.message || t('routePlanning.validateFailed')
    if (err.response?.status === 503 || isApiKeyError(message)) {
      markApiKeyMissing(message)
      return false
    }

    uncached.forEach(({ point, address }) => {
      if (point.address.trim() !== address) {
        return
      }
      point.status = 'invalid'
      point.error = message
      point.suggestion = null
      point.partialMatch = false
    })
    return true
  }
}

/** Wait until the shared queue has finished every currently pending address. */
const flushValidateQueue = async () => {
  drainValidateQueue()
  while (validateQueueRunning || pendingAddresses.size > 0) {
    await new Promise((r) => setTimeout(r, 40))
  }
}

const scheduleValidate = (point) => {
  const timer = validateTimers.get(point.id)
  if (timer) {
    clearTimeout(timer)
    validateTimers.delete(point.id)
  }
  result.value = null
  if (!apiKeyMissing.value) {
    error.value = ''
  }

  const address = point.address.trim()
  if (!address) {
    cancelPointValidation(point.id)
    clearPointValidation(point)
    return
  }

  // Show cached result immediately; still re-queue after debounce if user keeps typing.
  const cached = geocodeCache.get(address)
  if (cached) {
    applyValidationResult(point, cached)
  } else {
    point.status = 'idle'
    point.error = ''
    point.suggestion = null
    point.partialMatch = false
  }

  // Allow retry after the key was added without reloading the page.
  apiKeyMissing.value = false

  const nextTimer = setTimeout(() => {
    validateTimers.delete(point.id)
    const addr = point.address.trim()
    if (!addr) {
      return
    }
    const hit = geocodeCache.get(addr)
    if (hit) {
      applyValidationResult(point, hit)
      return
    }
    enqueueValidate(point, addr)
  }, VALIDATE_DELAY_MS)
  validateTimers.set(point.id, nextTimer)
}

const onAddressInput = (point) => {
  scheduleValidate(point)
}

const applySuggestion = (point) => {
  if (!point.suggestion) {
    return
  }
  point.address = point.suggestion
  point.suggestion = null
  point.partialMatch = false
  point.status = 'valid'
  point.error = ''
  result.value = null
  error.value = ''
}

const addPoint = () => {
  points.value.push(createPoint())
  result.value = null
  error.value = ''
}

const removePoint = (index) => {
  if (points.value.length <= 2) {
    return
  }
  const [removed] = points.value.splice(index, 1)
  cancelPointValidation(removed.id)
  result.value = null
  error.value = ''
}

const movePoint = (index, direction) => {
  const target = index + direction
  if (target < 0 || target >= points.value.length) {
    return
  }
  const list = points.value
  const [item] = list.splice(index, 1)
  list.splice(target, 0, item)
  result.value = null
  error.value = ''
}

const buildRoute = async () => {
  error.value = ''
  directionsDenied.value = false
  result.value = null
  loading.value = true

  try {
    const addresses = points.value.map((p) => p.address.trim())
    const { data } = await api.post('/api/admin/route-planning/build', { addresses })
    result.value = data

    if (data.points?.length === points.value.length) {
      data.points.forEach((pointData, index) => {
        const point = points.value[index]
        const key = point.address.trim()
        if (key && pointData) {
          geocodeCache.set(key, pointData)
          if (pointData.valid && pointData.formattedAddress) {
            geocodeCache.set(pointData.formattedAddress, pointData)
          }
        }
        applyValidationResult(point, pointData)
        if (pointData.valid && pointData.formattedAddress) {
          point.address = pointData.formattedAddress
          point.suggestion = null
        }
      })
    }
  } catch (err) {
    const data = err.response?.data
    if (data?.points?.length === points.value.length) {
      result.value = data
      data.points.forEach((pointData, index) => {
        applyValidationResult(points.value[index], pointData)
      })
    }
    const message = data?.error || err.message || t('routePlanning.buildFailed')
    error.value = message
    if (isDirectionsDeniedError(message)) {
      directionsDenied.value = true
    }
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  for (const point of points.value) {
    const address = point.address.trim()
    if (!address) {
      continue
    }
    // Queue all first, then one batch HTTP call (avoids N Symfony boots).
    enqueueValidate(point, address, { startDrain: false })
  }
  await flushValidateQueue()
})

onBeforeUnmount(() => {
  points.value.forEach((point) => cancelPointValidation(point.id))
  pendingAddresses.clear()
})
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton
        :icon="mdiMapMarkerPath"
        :title="t('routePlanning.title')"
        main
      />

      <NotificationBar color="info" :icon="mdiMapMarkerPath">
        {{ t('routePlanning.hint') }}
      </NotificationBar>

      <NotificationBar v-if="apiKeyMissing" color="danger" :icon="mdiAlertCircle">
        {{ t('routePlanning.apiKeyMissingHint') }}
      </NotificationBar>

      <NotificationBar v-else-if="directionsDenied" color="danger" :icon="mdiAlertCircle">
        {{ t('routePlanning.directionsDeniedHint') }}
      </NotificationBar>

      <CardBox class="mb-6" is-form @submit.prevent="buildRoute">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="text-xl font-semibold">{{ t('routePlanning.stops') }}</h2>
          <BaseButton
            type="button"
            color="info"
            :icon="mdiPlus"
            :label="t('routePlanning.addStop')"
            small
            @click="addPoint"
          />
        </div>

        <div class="space-y-4">
          <div
            v-for="(point, index) in points"
            :key="point.id"
            class="rounded-sm border p-3"
            :class="pointBorderClass(point)"
          >
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
              <div class="flex items-center gap-2 text-sm font-medium">
                <span
                  class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900"
                >
                  {{ index + 1 }}
                </span>
                <span v-if="index === 0">{{ t('routePlanning.start') }}</span>
                <span v-else-if="index === points.length - 1">{{ t('routePlanning.end') }}</span>
                <span v-else>{{ t('routePlanning.waypoint') }}</span>

                <BaseIcon
                  v-if="point.status === 'validating'"
                  :path="mdiLoading"
                  class="animate-spin text-sky-600"
                  size="18"
                />
                <BaseIcon
                  v-else-if="point.status === 'valid'"
                  :path="mdiCheckCircle"
                  class="text-emerald-600"
                  size="18"
                />
                <BaseIcon
                  v-else-if="point.status === 'invalid'"
                  :path="mdiAlertCircle"
                  class="text-red-600"
                  size="18"
                />
              </div>

              <BaseButtons>
                <BaseButton
                  type="button"
                  color="whiteDark"
                  :icon="mdiArrowUp"
                  small
                  :disabled="index === 0"
                  @click="movePoint(index, -1)"
                />
                <BaseButton
                  type="button"
                  color="whiteDark"
                  :icon="mdiArrowDown"
                  small
                  :disabled="index === points.length - 1"
                  @click="movePoint(index, 1)"
                />
                <BaseButton
                  type="button"
                  color="danger"
                  :icon="mdiDelete"
                  small
                  outline
                  :disabled="points.length <= 2"
                  @click="removePoint(index)"
                />
              </BaseButtons>
            </div>

            <FormField
              :label="t('routePlanning.address')"
              :error="point.status === 'invalid' ? point.error : undefined"
              :help="
                point.status === 'validating'
                  ? t('routePlanning.validating')
                  : point.status === 'valid' && !point.suggestion
                    ? t('routePlanning.addressOk')
                    : t('routePlanning.addressHelp')
              "
            >
              <FormControl
                v-model="point.address"
                :placeholder="t('routePlanning.addressPlaceholder')"
                @update:model-value="onAddressInput(point)"
              />
            </FormField>

            <p
              v-if="legByToIndex[index]"
              class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-700 dark:text-slate-200"
            >
              <span class="font-medium text-sky-700 dark:text-sky-300">
                {{ t('routePlanning.legDuration', { from: index }) }}:
              </span>
              <span class="font-semibold whitespace-nowrap">
                {{ legByToIndex[index].durationText }}
              </span>
              <span
                v-if="legByToIndex[index].distanceText"
                class="text-gray-500 dark:text-slate-400 whitespace-nowrap"
              >
                · {{ legByToIndex[index].distanceText }}
              </span>
            </p>

            <div
              v-if="point.status === 'valid' && point.suggestion"
              class="mt-2 rounded-sm border border-amber-300 bg-white/70 p-3 text-sm dark:border-amber-700 dark:bg-slate-900/40"
            >
              <p class="mb-1 font-medium text-amber-800 dark:text-amber-200">
                {{
                  point.partialMatch
                    ? t('routePlanning.partialMatchHint')
                    : t('routePlanning.suggestionHint')
                }}
              </p>
              <p class="mb-2 text-gray-700 dark:text-slate-200">{{ point.suggestion }}</p>
              <BaseButton
                type="button"
                color="warning"
                small
                :label="t('routePlanning.applySuggestion')"
                @click="applySuggestion(point)"
              />
            </div>
          </div>
        </div>

        <p v-if="error" class="mt-4 text-sm text-red-600 dark:text-red-400">
          {{ error }}
        </p>

        <template #footer>
          <BaseButtons>
            <BaseButton
              type="submit"
              color="info"
              :label="loading ? t('routePlanning.building') : t('routePlanning.build')"
              :disabled="!canBuild"
            />
            <BaseButton
              v-if="result?.mapsUrl"
              color="success"
              :icon="mdiGoogleMaps"
              :label="t('routePlanning.openMaps')"
              :href="result.mapsUrl"
              target="_blank"
            />
          </BaseButtons>
        </template>
      </CardBox>

      <CardBox v-if="result?.ok && result.segments?.length" has-table>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3 px-1">
          <div>
            <h2 class="text-xl font-semibold">{{ t('routePlanning.segmentsTitle') }}</h2>
            <p class="text-sm text-gray-500 dark:text-slate-400">
              {{ t('routePlanning.total') }}:
              {{ result.totalDurationText }} · {{ result.totalDistanceText }}
            </p>
          </div>
          <BaseButton
            color="success"
            :icon="mdiGoogleMaps"
            :label="t('routePlanning.openMaps')"
            :href="result.mapsUrl"
            target="_blank"
          />
        </div>

        <div class="overflow-x-auto">
          <table>
            <thead>
              <tr>
                <th>{{ t('routePlanning.colSegment') }}</th>
                <th>{{ t('routePlanning.colFrom') }}</th>
                <th>{{ t('routePlanning.colTo') }}</th>
                <th>{{ t('routePlanning.colDuration') }}</th>
                <th>{{ t('routePlanning.colDistance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="segment in result.segments" :key="`${segment.fromIndex}-${segment.toIndex}`">
                <td data-label="Segment">
                  {{ segment.fromIndex + 1 }} → {{ segment.toIndex + 1 }}
                </td>
                <td data-label="From">{{ segment.fromAddress }}</td>
                <td data-label="To">{{ segment.toAddress }}</td>
                <td data-label="Duration" class="font-semibold whitespace-nowrap">
                  {{ segment.durationText }}
                </td>
                <td data-label="Distance" class="whitespace-nowrap">
                  {{ segment.distanceText }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </CardBox>
    </SectionMain>
  </LayoutAuthenticated>
</template>
