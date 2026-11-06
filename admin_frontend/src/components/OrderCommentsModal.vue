<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { mdiDelete, mdiPencil, mdiPlus, mdiCheck, mdiClose } from '@mdi/js'
import { useToast } from 'vue-toastification'
import CardBoxModal from '@/components/CardBoxModal.vue'
import CardBoxModalBody from '@/components/CardBoxModalBody.vue'
import CardBoxComponentFooter from '@/components/CardBoxComponentFooter.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import BaseIcon from '@/components/BaseIcon.vue'
import api from '@/api.js'
import { COMMENT_COLORS, commentRowClass } from '@/utils/commentColors.js'

const COLOR_SWATCH = {
  amber: 'bg-amber-400',
  red: 'bg-red-500',
  green: 'bg-emerald-500',
  blue: 'bg-sky-500',
  orange: 'bg-orange-500',
  gray: 'bg-gray-400',
}

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  routeId: {
    type: [Number, String],
    default: null,
  },
  orderId: {
    type: [Number, String],
    default: null,
  },
  comments: {
    type: Array,
    default: () => [],
  },
  clientName: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:modelValue', 'saved'])

const { t } = useI18n()
const toast = useToast()

const draft = ref([])
const draftText = ref('')
const draftColor = ref('amber')
const editingId = ref(null)
const saving = ref(false)

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

const title = computed(() => {
  if (props.clientName) {
    return t('deliveryRoutes.commentsModalTitleWithClient', { client: props.clientName })
  }
  return t('deliveryRoutes.commentsModalTitle')
})

const newCommentId = () => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID().replaceAll('-', '')
  }
  return `${Date.now().toString(16)}${Math.random().toString(16).slice(2, 10)}`
}

const cloneComments = (list) =>
  (Array.isArray(list) ? list : []).map((item) => ({
    id: String(item.id ?? newCommentId()),
    text: String(item.text ?? ''),
    color: COMMENT_COLORS.includes(item.color) ? item.color : 'amber',
  }))

const resetEditor = () => {
  draftText.value = ''
  draftColor.value = 'amber'
  editingId.value = null
}

const syncFromProps = () => {
  draft.value = cloneComments(props.comments)
  resetEditor()
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      syncFromProps()
    }
  },
)

const startEdit = (comment) => {
  editingId.value = comment.id
  draftText.value = comment.text
  draftColor.value = comment.color
}

const cancelEdit = () => {
  resetEditor()
}

const submitEditor = () => {
  const text = draftText.value.trim()
  if (!text) {
    return
  }
  const color = COMMENT_COLORS.includes(draftColor.value) ? draftColor.value : 'amber'

  if (editingId.value) {
    draft.value = draft.value.map((item) =>
      item.id === editingId.value ? { ...item, text, color } : item,
    )
  } else {
    draft.value = [...draft.value, { id: newCommentId(), text, color }]
  }
  resetEditor()
}

const removeComment = (id) => {
  draft.value = draft.value.filter((item) => item.id !== id)
  if (editingId.value === id) {
    resetEditor()
  }
}

const save = async () => {
  if (!props.routeId || !props.orderId || saving.value) {
    return
  }

  // Commit in-progress editor text before save.
  if (draftText.value.trim()) {
    submitEditor()
  }

  saving.value = true
  try {
    const { data } = await api.put(`/api/admin/routes/${props.routeId}`, {
      orders: [
        {
          id: Number(props.orderId),
          comments: draft.value.map((item) => ({
            id: item.id,
            text: item.text,
            color: item.color,
          })),
        },
      ],
    })
    emit('saved', data.route)
    toast.success(t('deliveryRoutes.commentsSaved'))
    isOpen.value = false
  } catch (e) {
    toast.error(e.response?.data?.error || t('deliveryRoutes.commentsSaveFailed'))
  } finally {
    saving.value = false
  }
}

const cancel = () => {
  isOpen.value = false
}

const rowClass = (color) => commentRowClass(color)
const swatchClass = (color) => COLOR_SWATCH[color] || COLOR_SWATCH.amber
</script>

<template>
  <CardBoxModal
    v-model="isOpen"
    :title="title"
    wide
    has-cancel
    has-custom-layout
    :is-processing="saving"
    @cancel="cancel"
  >
    <CardBoxModalBody>
      <div v-if="!draft.length" class="mb-4 text-sm text-gray-500 dark:text-slate-400">
        {{ t('deliveryRoutes.commentsEmpty') }}
      </div>

      <ul v-else class="mb-4 list-none space-y-2 pl-0">
        <li
          v-for="comment in draft"
          :key="comment.id"
          class="flex items-start gap-2 rounded border-l-4 px-3 py-2 text-sm"
          :class="rowClass(comment.color)"
        >
          <div class="min-w-0 flex-1 whitespace-pre-wrap break-words">
            {{ comment.text }}
          </div>
          <div class="flex shrink-0 items-center gap-1">
            <button
              type="button"
              class="rounded p-1 text-gray-600 hover:bg-black/5 dark:text-slate-300 dark:hover:bg-white/10"
              :title="t('deliveryRoutes.commentsEdit')"
              :aria-label="t('deliveryRoutes.commentsEdit')"
              :disabled="saving"
              @click="startEdit(comment)"
            >
              <BaseIcon :path="mdiPencil" size="18" />
            </button>
            <button
              type="button"
              class="rounded p-1 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
              :title="t('deliveryRoutes.commentsDelete')"
              :aria-label="t('deliveryRoutes.commentsDelete')"
              :disabled="saving"
              @click="removeComment(comment.id)"
            >
              <BaseIcon :path="mdiDelete" size="18" />
            </button>
          </div>
        </li>
      </ul>

      <div class="rounded border border-gray-200 p-3 dark:border-slate-700">
        <div class="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">
          {{
            editingId
              ? t('deliveryRoutes.commentsEdit')
              : t('deliveryRoutes.commentsAdd')
          }}
        </div>
        <textarea
          v-model="draftText"
          rows="3"
          class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"
          :placeholder="t('deliveryRoutes.commentsPlaceholder')"
          :disabled="saving"
        />
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <span class="text-xs text-gray-500">{{ t('deliveryRoutes.commentsColor') }}</span>
          <button
            v-for="color in COMMENT_COLORS"
            :key="color"
            type="button"
            class="h-6 w-6 rounded-full border-2 transition"
            :class="[
              swatchClass(color),
              draftColor === color
                ? 'border-gray-900 ring-2 ring-offset-1 dark:border-white'
                : 'border-transparent',
            ]"
            :title="t(`deliveryRoutes.commentColor.${color}`)"
            :aria-label="t(`deliveryRoutes.commentColor.${color}`)"
            :disabled="saving"
            @click="draftColor = color"
          />
          <div class="ml-auto flex items-center gap-1">
            <button
              v-if="editingId"
              type="button"
              class="inline-flex items-center gap-1 rounded px-2 py-1 text-sm text-gray-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-700"
              :disabled="saving"
              @click="cancelEdit"
            >
              <BaseIcon :path="mdiClose" size="16" />
              {{ t('deliveryRoutes.commentsCancelEdit') }}
            </button>
            <button
              type="button"
              class="inline-flex items-center gap-1 rounded bg-blue-600 px-2.5 py-1 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              :disabled="saving || !draftText.trim()"
              @click="submitEditor"
            >
              <BaseIcon :path="editingId ? mdiCheck : mdiPlus" size="16" />
              {{
                editingId
                  ? t('deliveryRoutes.commentsUpdate')
                  : t('deliveryRoutes.commentsAdd')
              }}
            </button>
          </div>
        </div>
      </div>
    </CardBoxModalBody>

    <CardBoxComponentFooter>
      <BaseButtons>
        <BaseButton
          :label="t('deliveryRoutes.commentsSave')"
          color="info"
          :disabled="saving"
          :class="{ 'opacity-25': saving }"
          @click="save"
        />
        <BaseButton
          :label="t('deliveryRoutes.commentsClose')"
          color="info"
          outline
          :disabled="saving"
          @click="cancel"
        />
      </BaseButtons>
    </CardBoxComponentFooter>
  </CardBoxModal>
</template>
