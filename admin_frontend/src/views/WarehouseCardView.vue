<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { mdiWarehouse, mdiArrowLeft } from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import CardBox from '@/components/CardBox.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import FormCheckRadio from '@/components/FormCheckRadio.vue'
import AddressAutocomplete from '@/components/AddressAutocomplete.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import api from '@/api.js'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const isNew = computed(() => route.name === 'warehouse-new')

const form = reactive({
  name: '',
  address: '',
  placeId: null,
  isDefault: false,
})

const loading = ref(false)
const saving = ref(false)

const pageTitle = computed(() =>
  isNew.value ? t('warehouses.createTitle') : t('warehouses.editTitle', { name: form.name || '…' }),
)

const loadWarehouse = async () => {
  if (isNew.value) {
    form.name = ''
    form.address = ''
    form.placeId = null
    form.isDefault = false
    return
  }

  loading.value = true
  try {
    const { data } = await api.get(`/api/admin/warehouses/${route.params.id}`)
    const warehouse = data.warehouse
    form.name = warehouse.name ?? ''
    form.address = warehouse.formattedAddress || warehouse.address || ''
    form.placeId = warehouse.placeId ?? null
    form.isDefault = !!warehouse.isDefault
  } catch (e) {
    toast.error(e.response?.data?.error || t('warehouses.loadFailed'))
  } finally {
    loading.value = false
  }
}

const submit = async () => {
  saving.value = true

  const payload = {
    name: form.name.trim(),
    address: form.address.trim(),
    isDefault: !!form.isDefault,
  }

  if (form.placeId) {
    payload.placeId = form.placeId
  }

  try {
    if (isNew.value) {
      const { data } = await api.post('/api/admin/warehouses', payload)
      toast.success(t('warehouses.created'))
      await router.replace({ name: 'warehouse', params: { id: data.warehouse.id } })
    } else {
      const { data } = await api.put(`/api/admin/warehouses/${route.params.id}`, payload)
      form.name = data.warehouse.name ?? ''
      form.address = data.warehouse.formattedAddress || data.warehouse.address || ''
      form.placeId = data.warehouse.placeId ?? null
      form.isDefault = !!data.warehouse.isDefault
      toast.success(t('warehouses.updated'))
    }
  } catch (e) {
    toast.error(e.response?.data?.error || t('warehouses.saveFailed'))
  } finally {
    saving.value = false
  }
}

watch(
  () => route.params.id,
  () => {
    loadWarehouse()
  },
)

onMounted(loadWarehouse)
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiWarehouse" :title="pageTitle" main>
        <BaseButton
          :label="t('warehouses.backToList')"
          :icon="mdiArrowLeft"
          color="whiteDark"
          :to="{ name: 'warehouses' }"
        />
      </SectionTitleLineWithButton>

      <CardBox v-if="!loading" is-form @submit.prevent="submit">
        <FormField :label="t('warehouses.name')" :help="t('warehouses.nameHelp')">
          <FormControl
            v-model="form.name"
            :placeholder="t('warehouses.namePlaceholder')"
            required
          />
        </FormField>

        <FormField :label="t('warehouses.address')" :help="t('warehouses.addressHelp')">
          <AddressAutocomplete
            v-model="form.address"
            v-model:place-id="form.placeId"
            :placeholder="t('warehouses.addressPlaceholder')"
            required
          />
        </FormField>

        <FormField :help="t('warehouses.isDefaultHelp')">
          <FormCheckRadio
            v-model="form.isDefault"
            name="warehouse-is-default"
            :label="t('warehouses.isDefault')"
            :input-value="true"
          />
        </FormField>

        <template #footer>
          <BaseButtons>
            <BaseButton
              type="submit"
              color="info"
              :label="saving ? t('warehouses.saving') : t('warehouses.save')"
              :disabled="saving"
            />
            <BaseButton
              type="button"
              color="info"
              outline
              :label="t('warehouses.cancel')"
              :to="{ name: 'warehouses' }"
            />
          </BaseButtons>
        </template>
      </CardBox>
    </SectionMain>
  </LayoutAuthenticated>
</template>
