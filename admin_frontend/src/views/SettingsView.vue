<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'vue-toastification'
import { mdiCogOutline } from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import api, { showApiError } from '@/api.js'

const { t } = useI18n()
const toast = useToast()

const form = reactive({
  pdfReportRecipientEmail: '',
  confirmationEmailMode: 'test',
  confirmationEmailTestRecipient: '',
})

const loading = ref(false)
const saving = ref(false)

const confirmationModeOptions = computed(() => [
  { id: 'test', label: t('settings.confirmationEmailModeTest') },
  { id: 'client', label: t('settings.confirmationEmailModeClient') },
])

const selectedConfirmationMode = computed({
  get: () =>
    confirmationModeOptions.value.find((option) => option.id === form.confirmationEmailMode)
    ?? confirmationModeOptions.value[0],
  set: (option) => {
    form.confirmationEmailMode = option?.id === 'client' ? 'client' : 'test'
  },
})

const loadSettings = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/api/admin/settings')
    form.pdfReportRecipientEmail = data.settings?.pdfReportRecipientEmail ?? ''
    form.confirmationEmailMode =
      data.settings?.confirmationEmailMode === 'client' ? 'client' : 'test'
    form.confirmationEmailTestRecipient = data.settings?.confirmationEmailTestRecipient ?? ''
  } catch (e) {
    showApiError(toast, e, t('settings.loadFailed'))
  } finally {
    loading.value = false
  }
}

const submit = async () => {
  saving.value = true
  try {
    const { data } = await api.put('/api/admin/settings', {
      pdfReportRecipientEmail: form.pdfReportRecipientEmail.trim() || null,
      confirmationEmailMode: form.confirmationEmailMode === 'client' ? 'client' : 'test',
      confirmationEmailTestRecipient: form.confirmationEmailTestRecipient.trim() || null,
    })
    form.pdfReportRecipientEmail = data.settings?.pdfReportRecipientEmail ?? ''
    form.confirmationEmailMode =
      data.settings?.confirmationEmailMode === 'client' ? 'client' : 'test'
    form.confirmationEmailTestRecipient = data.settings?.confirmationEmailTestRecipient ?? ''
    toast.success(t('settings.saved'))
  } catch (e) {
    showApiError(toast, e, t('settings.saveFailed'))
  } finally {
    saving.value = false
  }
}

onMounted(loadSettings)
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiCogOutline" :title="t('settings.title')" main />

      <CardBox v-if="!loading" is-form @submit.prevent="submit">
        <p class="mb-6 text-sm text-gray-500 dark:text-slate-400">
          {{ t('settings.description') }}
        </p>

        <FormField
          :label="t('settings.pdfReportRecipientEmail')"
          :help="t('settings.pdfReportRecipientEmailHelp')"
        >
          <FormControl
            v-model="form.pdfReportRecipientEmail"
            type="email"
            :placeholder="t('settings.pdfReportRecipientEmailPlaceholder')"
          />
        </FormField>

        <FormField
          :label="t('settings.confirmationEmailMode')"
          :help="t('settings.confirmationEmailModeHelp')"
        >
          <FormControl v-model="selectedConfirmationMode" :options="confirmationModeOptions" />
        </FormField>

        <FormField
          :label="t('settings.confirmationEmailTestRecipient')"
          :help="t('settings.confirmationEmailTestRecipientHelp')"
        >
          <FormControl
            v-model="form.confirmationEmailTestRecipient"
            type="email"
            :placeholder="t('settings.confirmationEmailTestRecipientPlaceholder')"
          />
        </FormField>

        <template #footer>
          <BaseButtons>
            <BaseButton
              type="submit"
              color="info"
              :label="saving ? t('settings.saving') : t('settings.save')"
              :disabled="saving"
            />
          </BaseButtons>
        </template>
      </CardBox>
    </SectionMain>
  </LayoutAuthenticated>
</template>
