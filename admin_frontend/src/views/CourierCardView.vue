<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { mdiTruckDelivery, mdiArrowLeft } from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import api from '@/api.js'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const isNew = computed(() => route.name === 'courier-new')

const form = reactive({
  name: '',
  login: '',
  password: '',
})

const loading = ref(false)
const saving = ref(false)

const pageTitle = computed(() =>
  isNew.value ? t('couriers.createTitle') : t('couriers.editTitle', { name: form.name || '…' }),
)

const loadCourier = async () => {
  if (isNew.value) {
    form.name = ''
    form.login = ''
    form.password = ''
    return
  }

  loading.value = true
  try {
    const { data } = await api.get(`/api/admin/couriers/${route.params.id}`)
    const courier = data.courier
    form.name = courier.name ?? ''
    form.login = courier.login ?? ''
    form.password = ''
  } catch (e) {
    toast.error(e.response?.data?.error || t('couriers.loadFailed'))
  } finally {
    loading.value = false
  }
}

const submit = async () => {
  saving.value = true

  const payload = {
    name: form.name.trim(),
    login: form.login.trim(),
  }

  if (isNew.value || form.password) {
    payload.password = form.password
  }

  try {
    if (isNew.value) {
      const { data } = await api.post('/api/admin/couriers', payload)
      toast.success(t('couriers.created'))
      await router.replace({ name: 'courier', params: { id: data.courier.id } })
    } else {
      const { data } = await api.put(`/api/admin/couriers/${route.params.id}`, payload)
      form.name = data.courier.name ?? ''
      form.login = data.courier.login ?? ''
      form.password = ''
      toast.success(t('couriers.updated'))
    }
  } catch (e) {
    toast.error(e.response?.data?.error || t('couriers.saveFailed'))
  } finally {
    saving.value = false
  }
}

watch(
  () => route.params.id,
  () => {
    loadCourier()
  },
)

onMounted(loadCourier)
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiTruckDelivery" :title="pageTitle" main>
        <BaseButton
          :label="t('couriers.backToList')"
          :icon="mdiArrowLeft"
          color="whiteDark"
          :to="{ name: 'couriers' }"
        />
      </SectionTitleLineWithButton>

      <CardBox v-if="!loading" is-form @submit.prevent="submit">
        <FormField :label="t('couriers.name')" :help="t('couriers.nameHelp')">
          <FormControl v-model="form.name" :placeholder="t('couriers.namePlaceholder')" required />
        </FormField>

        <FormField :label="t('couriers.login')" :help="t('couriers.loginHelp')">
          <FormControl
            v-model="form.login"
            type="email"
            :placeholder="t('couriers.loginPlaceholder')"
            required
          />
        </FormField>

        <FormField
          :label="t('couriers.password')"
          :help="isNew ? t('couriers.passwordHelpCreate') : t('couriers.passwordHelpEdit')"
        >
          <FormControl
            v-model="form.password"
            type="password"
            :placeholder="
              isNew ? t('couriers.passwordPlaceholderCreate') : t('couriers.passwordPlaceholderEdit')
            "
            :required="isNew"
            autocomplete="new-password"
          />
        </FormField>

        <template #footer>
          <BaseButtons>
            <BaseButton
              type="submit"
              color="info"
              :label="saving ? t('couriers.saving') : t('couriers.save')"
              :disabled="saving"
            />
            <BaseButton
              type="button"
              color="info"
              outline
              :label="t('couriers.cancel')"
              :to="{ name: 'couriers' }"
            />
          </BaseButtons>
        </template>
      </CardBox>
    </SectionMain>
  </LayoutAuthenticated>
</template>
