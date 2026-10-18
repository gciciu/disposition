<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api.js'
import { withoutCountry } from '@/utils/address.js'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  placeId: {
    type: String,
    default: null,
  },
  placeholder: String,
  required: Boolean,
  disabled: Boolean,
})

const emit = defineEmits(['update:modelValue', 'update:placeId', 'selected'])

const { t } = useI18n()

const inputValue = ref(props.modelValue ?? '')
const predictions = ref([])
const open = ref(false)
const loading = ref(false)
const highlightIndex = ref(-1)
const rootEl = ref(null)
const skipNextSearch = ref(false)
let debounceTimer = null
let requestSeq = 0

const hasPredictions = computed(() => predictions.value.length > 0)

const syncFromProps = () => {
  if (inputValue.value !== (props.modelValue ?? '')) {
    skipNextSearch.value = true
    inputValue.value = props.modelValue ?? ''
  }
}

watch(
  () => props.modelValue,
  () => {
    syncFromProps()
  },
)

watch(inputValue, (value) => {
  emit('update:modelValue', value)

  if (skipNextSearch.value) {
    skipNextSearch.value = false
    return
  }

  emit('update:placeId', null)
  scheduleSearch(value)
})

const scheduleSearch = (value) => {
  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }

  const query = value.trim()
  if (query.length < 3) {
    predictions.value = []
    open.value = false
    highlightIndex.value = -1
    return
  }

  debounceTimer = setTimeout(() => {
    search(query)
  }, 280)
}

const search = async (query) => {
  const seq = ++requestSeq
  loading.value = true
  try {
    const { data } = await api.get('/api/admin/maps/autocomplete', {
      params: { input: query },
    })
    if (seq !== requestSeq) {
      return
    }
    predictions.value = data.predictions ?? []
    open.value = predictions.value.length > 0
    highlightIndex.value = predictions.value.length > 0 ? 0 : -1
  } catch {
    if (seq !== requestSeq) {
      return
    }
    predictions.value = []
    open.value = false
    highlightIndex.value = -1
  } finally {
    if (seq === requestSeq) {
      loading.value = false
    }
  }
}

const selectPrediction = async (prediction) => {
  skipNextSearch.value = true
  const description = withoutCountry(prediction.description)
  inputValue.value = description
  emit('update:modelValue', description)
  emit('update:placeId', prediction.placeId)
  emit('selected', { ...prediction, description })
  predictions.value = []
  open.value = false
  highlightIndex.value = -1
  await nextTick()
}

const onFocus = () => {
  if (hasPredictions.value) {
    open.value = true
  } else if (inputValue.value.trim().length >= 3 && !props.placeId) {
    scheduleSearch(inputValue.value)
  }
}

const onKeydown = (event) => {
  if (!open.value || !hasPredictions.value) {
    return
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    highlightIndex.value = Math.min(highlightIndex.value + 1, predictions.value.length - 1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    highlightIndex.value = Math.max(highlightIndex.value - 1, 0)
  } else if (event.key === 'Enter' && highlightIndex.value >= 0) {
    event.preventDefault()
    selectPrediction(predictions.value[highlightIndex.value])
  } else if (event.key === 'Escape') {
    open.value = false
  }
}

const onDocumentClick = (event) => {
  if (!rootEl.value?.contains(event.target)) {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onDocumentClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocumentClick)
  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }
})
</script>

<template>
  <div ref="rootEl" class="relative w-full">
    <input
      v-model="inputValue"
      type="text"
      class="px-3 py-2 max-w-full focus:ring-3 focus:outline-hidden border border-gray-700 rounded-sm w-full h-12 bg-white dark:bg-slate-800 dark:placeholder-gray-400"
      :placeholder="placeholder"
      :required="required"
      :disabled="disabled"
      autocomplete="off"
      @focus="onFocus"
      @keydown="onKeydown"
    />

    <div
      v-if="open && (hasPredictions || loading)"
      class="absolute z-30 mt-1 w-full overflow-hidden rounded-sm border border-gray-700 bg-white shadow-lg dark:bg-slate-800"
    >
      <p
        v-if="loading && !hasPredictions"
        class="px-3 py-2 text-sm text-gray-500 dark:text-slate-400"
      >
        {{ t('warehouses.addressSearching') }}
      </p>
      <button
        v-for="(prediction, index) in predictions"
        :key="prediction.placeId"
        type="button"
        class="block w-full px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-slate-700"
        :class="{ 'bg-gray-100 dark:bg-slate-700': index === highlightIndex }"
        @mousedown.prevent="selectPrediction(prediction)"
      >
        <span class="block text-sm font-medium">{{ prediction.mainText }}</span>
        <span v-if="prediction.secondaryText" class="block text-xs text-gray-500 dark:text-slate-400">
          {{ withoutCountry(prediction.secondaryText) }}
        </span>
      </button>
    </div>
  </div>
</template>
