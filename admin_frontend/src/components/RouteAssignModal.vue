<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'vue-toastification'
import { mdiFilePdfBox, mdiUpload } from '@mdi/js'
import CardBoxModal from '@/components/CardBoxModal.vue'
import CardBoxModalBody from '@/components/CardBoxModalBody.vue'
import CardBoxComponentFooter from '@/components/CardBoxComponentFooter.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import WeekdayDatePicker from '@/components/WeekdayDatePicker.vue'
import api, { showApiError } from '@/api.js'

const props = defineProps({
  modelValue: Boolean,
  mode: {
    type: String,
    default: 'import', // import | edit
  },
  /** Existing route for edit mode */
  route: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['update:modelValue', 'saved'])

const { t } = useI18n()
const toast = useToast()

const COURIER_LOCATION_ORIGIN = 'courier_location'

const open = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

const couriers = ref([])
const warehouses = ref([])
const selectedCourier = ref(null)
const selectedOrigin = ref(null)
const scheduledDate = ref(null)
const scheduledStartTime = ref('')
const selectedFile = ref(null)
const fileInput = ref(null)
const isDragging = ref(false)
const dragDepth = ref(0)
const occupiedDays = ref([])
const saving = ref(false)
const replaceConfirmOpen = ref(false)
const pendingReplaceForm = ref(null)

const isEdit = computed(() => props.mode === 'edit')
const isStarted = computed(() => !!props.route?.startedAt)

const title = computed(() =>
  isEdit.value ? t('deliveryRoutes.editTitle') : t('deliveryRoutes.importTitle'),
)

const courierPlaceholder = computed(() => ({
  id: null,
  label: t('deliveryRoutes.courierSelect'),
}))

const courierOptions = computed(() => [
  courierPlaceholder.value,
  ...couriers.value.map((courier) => ({
    id: courier.id,
    label: `${courier.name} (${courier.login})`,
  })),
])

const originOptions = computed(() => [
  { id: COURIER_LOCATION_ORIGIN, label: t('deliveryRoutes.warehouseCourierLocation') },
  ...warehouses.value.map((warehouse) => ({
    id: warehouse.id,
    label: warehouse.isDefault
      ? `${warehouse.name} (${t('deliveryRoutes.warehouseDefault')})`
      : warehouse.name,
  })),
])

const selectedCourierId = computed(() => selectedCourier.value?.id ?? null)

const canSubmit = computed(() => {
  if (saving.value || (isEdit.value && isStarted.value)) {
    return false
  }
  if (!selectedCourierId.value || !scheduledDate.value || !selectedOrigin.value) {
    return false
  }
  if (!isEdit.value && !selectedFile.value) {
    return false
  }
  return true
})

const loadCouriers = async () => {
  try {
    const { data } = await api.get('/api/admin/couriers')
    couriers.value = data.couriers ?? []
  } catch {
    couriers.value = []
  }
}

const loadWarehouses = async () => {
  try {
    const { data } = await api.get('/api/admin/warehouses')
    warehouses.value = data.warehouses ?? []
  } catch {
    warehouses.value = []
  }
}

const scheduleRange = () => {
  const now = new Date()
  const from = new Date(now.getFullYear(), now.getMonth() - 1, 1)
  const to = new Date(now.getFullYear(), now.getMonth() + 4, 0)
  const fmt = (d) => {
    const y = d.getFullYear()
    const m = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${y}-${m}-${day}`
  }
  return { from: fmt(from), to: fmt(to) }
}

const loadSchedule = async () => {
  if (!selectedCourierId.value) {
    occupiedDays.value = []
    return
  }
  const { from, to } = scheduleRange()
  try {
    const params = { from, to }
    if (isEdit.value && props.route?.id) {
      params.excludeRouteId = props.route.id
    }
    const { data } = await api.get(`/api/admin/couriers/${selectedCourierId.value}/schedule`, {
      params,
    })
    occupiedDays.value = data.days ?? []
  } catch {
    occupiedDays.value = []
  }
}

const resetForm = () => {
  selectedFile.value = null
  isDragging.value = false
  dragDepth.value = 0
  if (fileInput.value) {
    fileInput.value.value = ''
  }
  replaceConfirmOpen.value = false
  pendingReplaceForm.value = null

  if (isEdit.value && props.route) {
    scheduledDate.value = props.route.scheduledDate ?? null
    scheduledStartTime.value = props.route.scheduledStartTime ?? ''
    selectedCourier.value =
      courierOptions.value.find((option) => option.id === props.route.courier?.id) ??
      courierPlaceholder.value

    let originId = COURIER_LOCATION_ORIGIN
    if (props.route.originType === 'warehouse' && props.route.warehouse?.id != null) {
      originId = props.route.warehouse.id
    } else if (props.route.originType === COURIER_LOCATION_ORIGIN) {
      originId = COURIER_LOCATION_ORIGIN
    } else if (props.route.warehouse?.id != null) {
      originId = props.route.warehouse.id
    }
    selectedOrigin.value =
      originOptions.value.find((option) => option.id === originId) ?? originOptions.value[0] ?? null
  } else {
    scheduledDate.value = null
    scheduledStartTime.value = ''
    selectedCourier.value = courierPlaceholder.value
    const defaultWarehouse = warehouses.value.find((w) => w.isDefault)
    selectedOrigin.value =
      originOptions.value.find((option) => option.id === defaultWarehouse?.id) ??
      originOptions.value[0] ??
      null
  }
}

watch(
  () => props.modelValue,
  async (isOpen) => {
    if (!isOpen) {
      return
    }
    await Promise.all([loadCouriers(), loadWarehouses()])
    resetForm()
    await loadSchedule()
  },
)

watch(selectedCourierId, () => {
  void loadSchedule()
})

const buildOriginPayload = () => {
  const originId = selectedOrigin.value?.id ?? COURIER_LOCATION_ORIGIN
  if (originId === COURIER_LOCATION_ORIGIN) {
    return { originType: COURIER_LOCATION_ORIGIN, warehouseId: null }
  }
  return { originType: 'warehouse', warehouseId: originId }
}

const showImportError = (e) => {
  const payload = e.response?.data
  const invalidAddresses = Array.isArray(payload?.invalidAddresses) ? payload.invalidAddresses : []
  const timedOut =
    e.code === 'ECONNABORTED' ||
    /timeout/i.test(String(e.message || '')) ||
    !e.response
  let message =
    payload?.error ||
    (timedOut ? t('deliveryRoutes.importTimeout') : t('deliveryRoutes.importFailed'))
  if (invalidAddresses.length) {
    const details = invalidAddresses
      .map(
        (item) =>
          `#${item.position} ${item.clientName} — ${item.address}${
            item.error ? ` (${item.error})` : ''
          }`,
      )
      .join('\n')
    message = `${message}\n${details}`
  }
  toast.error(message, { timeout: invalidAddresses.length ? 8000 : 4000 })
}

const submitImport = async (replaceExisting = false) => {
  const formData = new FormData()
  formData.append('file', selectedFile.value)
  formData.append('courierId', String(selectedCourierId.value))
  formData.append('scheduledDate', scheduledDate.value)
  if (scheduledStartTime.value) {
    formData.append('scheduledStartTime', scheduledStartTime.value)
  }
  const origin = buildOriginPayload()
  formData.append('originType', origin.originType)
  if (origin.warehouseId != null) {
    formData.append('warehouseId', String(origin.warehouseId))
  }
  if (replaceExisting) {
    formData.append('replaceExisting', '1')
  }

  saving.value = true
  try {
    // Geocoding every stop can take well over the default 15s axios timeout.
    const { data } = await api.post('/api/admin/routes/import', formData, {
      timeout: 300000,
    })
    toast.success(
      t('deliveryRoutes.importSuccess', {
        name: data.route.name,
        count: data.route.orderCount ?? data.route.orders?.length ?? 0,
      }),
    )
    open.value = false
    emit('saved', data.route)
  } catch (e) {
    if (e.response?.status === 409 && e.response?.data?.code === 'ROUTE_EXISTS' && !replaceExisting) {
      pendingReplaceForm.value = true
      replaceConfirmOpen.value = true
      return
    }
    showImportError(e)
  } finally {
    saving.value = false
  }
}

const submitEdit = async () => {
  if (!props.route?.id) {
    return
  }
  saving.value = true
  try {
    const origin = buildOriginPayload()
    const payload = {
      courierId: selectedCourierId.value,
      scheduledDate: scheduledDate.value,
      scheduledStartTime: scheduledStartTime.value || null,
      ...origin,
    }
    const { data } = await api.put(`/api/admin/routes/${props.route.id}`, payload)
    toast.success(t('deliveryRoutes.saved'))
    open.value = false
    emit('saved', data.route)
  } catch (e) {
    showApiError(toast, e, t('deliveryRoutes.saveFailed'))
  } finally {
    saving.value = false
  }
}

const onConfirm = async () => {
  if (!canSubmit.value) {
    return
  }
  if (isEdit.value) {
    await submitEdit()
  } else {
    await submitImport(false)
  }
}

const confirmReplace = async () => {
  replaceConfirmOpen.value = false
  pendingReplaceForm.value = null
  await submitImport(true)
}

const isPdfFile = (file) => {
  if (!file) {
    return false
  }
  const byType = file.type === 'application/pdf'
  const byName = /\.pdf$/i.test(file.name || '')
  return byType || byName
}

const setSelectedFile = (file) => {
  if (!file) {
    selectedFile.value = null
    if (fileInput.value) {
      fileInput.value.value = ''
    }
    return
  }
  if (!isPdfFile(file)) {
    toast.error(t('deliveryRoutes.pdfOnly'))
    return
  }
  selectedFile.value = file
}

const onFileChange = (event) => {
  setSelectedFile(event.target.files?.[0] ?? null)
}

const onDragEnter = (event) => {
  if (saving.value) {
    return
  }
  event.preventDefault()
  dragDepth.value += 1
  isDragging.value = true
}

const onDragOver = (event) => {
  if (saving.value) {
    return
  }
  event.preventDefault()
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'copy'
  }
  isDragging.value = true
}

const onDragLeave = (event) => {
  event.preventDefault()
  dragDepth.value = Math.max(0, dragDepth.value - 1)
  if (dragDepth.value === 0) {
    isDragging.value = false
  }
}

const onDrop = (event) => {
  if (saving.value) {
    return
  }
  event.preventDefault()
  dragDepth.value = 0
  isDragging.value = false
  const file = event.dataTransfer?.files?.[0] ?? null
  setSelectedFile(file)
}

const openFilePicker = () => {
  if (saving.value) {
    return
  }
  fileInput.value?.click()
}

const clearSelectedFile = () => {
  setSelectedFile(null)
}
</script>

<template>
  <CardBoxModal
    v-model="open"
    :title="title"
    has-cancel
    is-form
    has-custom-layout
    wide
    :is-processing="saving"
    :button-label="isEdit ? t('deliveryRoutes.save') : t('deliveryRoutes.import')"
    @confirm="onConfirm"
  >
    <CardBoxModalBody>
      <p v-if="isEdit && isStarted" class="mb-4 text-sm text-amber-700 dark:text-amber-300">
        {{ t('deliveryRoutes.assignmentLockedHelp') }}
      </p>

      <FormField v-if="!isEdit" :label="t('deliveryRoutes.pdfFile')">
        <div
          class="relative flex min-h-32 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors"
          :class="[
            saving ? 'cursor-not-allowed opacity-60' : '',
            isDragging
              ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950/40'
              : selectedFile
                ? 'border-emerald-500 bg-emerald-50 dark:border-emerald-400 dark:bg-emerald-950/30'
                : 'border-gray-300 bg-gray-50 hover:border-blue-400 hover:bg-blue-50/60 dark:border-slate-600 dark:bg-slate-800/60 dark:hover:border-blue-400',
          ]"
          @click="openFilePicker"
          @dragenter="onDragEnter"
          @dragover="onDragOver"
          @dragleave="onDragLeave"
          @drop="onDrop"
        >
          <input
            ref="fileInput"
            type="file"
            accept="application/pdf,.pdf"
            class="hidden"
            :disabled="saving"
            @change="onFileChange"
            @click.stop
          />
          <BaseIcon
            :path="selectedFile ? mdiFilePdfBox : mdiUpload"
            :size="32"
            class="text-gray-500 dark:text-slate-300"
          />
          <template v-if="selectedFile">
            <p class="max-w-full truncate text-sm font-medium text-gray-800 dark:text-slate-100">
              {{ selectedFile.name }}
            </p>
            <p class="text-xs text-gray-500 dark:text-slate-400">
              {{ t('deliveryRoutes.dropReplaceHint') }}
            </p>
            <button
              type="button"
              class="mt-1 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
              :disabled="saving"
              @click.stop="clearSelectedFile"
            >
              {{ t('deliveryRoutes.clearFile') }}
            </button>
          </template>
          <template v-else>
            <p class="text-sm font-medium text-gray-700 dark:text-slate-200">
              {{ isDragging ? t('deliveryRoutes.dropHere') : t('deliveryRoutes.dropOrBrowse') }}
            </p>
            <p class="text-xs text-gray-500 dark:text-slate-400">
              {{ t('deliveryRoutes.pdfOnlyHint') }}
            </p>
          </template>
        </div>
      </FormField>

      <FormField :label="t('deliveryRoutes.courier')" :help="t('deliveryRoutes.courierHelp')">
        <FormControl
          v-model="selectedCourier"
          :options="courierOptions"
          :disabled="saving || (isEdit && isStarted)"
        />
      </FormField>

      <FormField :label="t('deliveryRoutes.scheduledDate')">
        <WeekdayDatePicker
          v-model="scheduledDate"
          :occupied-days="occupiedDays"
          :allow-date="isEdit ? route?.scheduledDate : null"
          :disabled="saving || (isEdit && isStarted)"
        />
      </FormField>

      <FormField :label="t('deliveryRoutes.scheduledStartTime')" :help="t('deliveryRoutes.scheduledStartTimeHelp')">
        <FormControl
          v-model="scheduledStartTime"
          type="time"
          :disabled="saving || (isEdit && isStarted)"
        />
      </FormField>

      <FormField :label="t('deliveryRoutes.warehouse')" :help="t('deliveryRoutes.warehouseHelp')">
        <FormControl
          v-model="selectedOrigin"
          :options="originOptions"
          :disabled="saving || (isEdit && isStarted)"
        />
      </FormField>
    </CardBoxModalBody>

    <CardBoxComponentFooter>
      <BaseButtons>
        <BaseButton
          :label="isEdit ? (saving ? t('deliveryRoutes.saving') : t('deliveryRoutes.save')) : (saving ? t('deliveryRoutes.importing') : t('deliveryRoutes.import'))"
          color="info"
          type="submit"
          :disabled="!canSubmit"
          :class="{ 'opacity-25': !canSubmit }"
        />
        <BaseButton
          :label="t('deliveryRoutes.cancel')"
          color="info"
          outline
          type="button"
          :disabled="saving"
          @click="open = false"
        />
      </BaseButtons>
    </CardBoxComponentFooter>
  </CardBoxModal>

  <CardBoxModal
    v-model="replaceConfirmOpen"
    :title="t('deliveryRoutes.replaceConfirmTitle')"
    button="danger"
    :button-label="t('deliveryRoutes.replaceConfirm')"
    has-cancel
    :is-processing="saving"
    @confirm="confirmReplace"
  >
    <p>{{ t('deliveryRoutes.replaceConfirmBody') }}</p>
  </CardBoxModal>
</template>
