<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'vue-toastification'
import { mdiEmailFastOutline } from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import { useAuthStore } from '@/stores/auth.js'
import api from '@/api.js'

const { t } = useI18n()
const toast = useToast()
const authStore = useAuthStore()

const form = reactive({
  to: '',
})

const sending = ref(false)
const lastResult = ref(null)

onMounted(() => {
  if (!form.to && authStore.user?.email) {
    form.to = authStore.user.email
  }
})

const sendTest = async () => {
  sending.value = true
  lastResult.value = null

  try {
    const { data } = await api.post('/api/admin/email/test', {
      to: form.to.trim() || undefined,
    })
    lastResult.value = data
    if (data.discarded) {
      toast.warning(t('emailTest.discarded'))
    } else {
      toast.success(t('emailTest.sent', { to: data.to }))
    }
  } catch (e) {
    toast.error(e.response?.data?.error || t('emailTest.failed'))
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiEmailFastOutline" :title="t('emailTest.title')" main />

      <CardBox is-form @submit.prevent="sendTest">
        <p class="mb-6 text-sm text-gray-500 dark:text-slate-400">
          {{ t('emailTest.description') }}
        </p>

        <FormField :label="t('emailTest.to')" :help="t('emailTest.toHelp')">
          <FormControl
            v-model="form.to"
            type="email"
            :placeholder="t('emailTest.toPlaceholder')"
            required
          />
        </FormField>

        <template #footer>
          <BaseButtons>
            <BaseButton
              type="submit"
              color="info"
              :label="sending ? t('emailTest.sending') : t('emailTest.send')"
              :icon="mdiEmailFastOutline"
              :disabled="sending"
            />
          </BaseButtons>
        </template>
      </CardBox>

      <CardBox v-if="lastResult" class="mt-6">
        <p class="text-sm font-medium">{{ t('emailTest.lastResult') }}</p>
        <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">
          {{ lastResult.message }}
        </p>
        <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">
          {{ t('emailTest.to') }}: {{ lastResult.to }}
        </p>
      </CardBox>
    </SectionMain>
  </LayoutAuthenticated>
</template>
