<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'vue-toastification'
import { mdiAccount, mdiAsterisk } from '@mdi/js'
import SectionFullScreen from '@/components/SectionFullScreen.vue'
import CardBox from '@/components/CardBox.vue'
import FormCheckRadio from '@/components/FormCheckRadio.vue'
import FormField from '@/components/FormField.vue'
import FormControl from '@/components/FormControl.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import LayoutGuest from '@/layouts/LayoutGuest.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import { useAuthStore } from '@/stores/auth.js'

const { t } = useI18n()
const toast = useToast()

const form = reactive({
  email: '',
  password: '',
  remember: true,
})

const isLoading = ref(false)

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const sessionExpired = computed(() => route.query.expired === '1')

const submit = async () => {
  isLoading.value = true

  try {
    await authStore.login(form.email.trim(), form.password, form.remember)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : ''
    const target = redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/dashboard'
    await router.push(target)
  } catch (error) {
    toast.error(
      error.response?.data?.message ||
        error.message ||
        t('login.failed'),
    )
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  if (sessionExpired.value) {
    toast.warning(t('login.sessionExpired'))
  }
})
</script>

<template>
  <LayoutGuest>
    <div
      class="absolute top-4 right-4 z-20 rounded-lg bg-white/10 px-1 text-white backdrop-blur-sm [&_label]:text-slate-200 [&_select]:text-white"
    >
      <LanguageSwitcher />
    </div>
    <SectionFullScreen v-slot="{ cardClass }" bg="transport">
      <CardBox :class="cardClass" is-form @submit.prevent="submit">
        <FormField :label="t('login.email')" :help="t('login.emailHelp')">
          <FormControl
            v-model="form.email"
            :icon="mdiAccount"
            name="email"
            type="email"
            autocomplete="username"
          />
        </FormField>

        <FormField :label="t('login.password')" :help="t('login.passwordHelp')">
          <FormControl
            v-model="form.password"
            :icon="mdiAsterisk"
            type="password"
            name="password"
            autocomplete="current-password"
          />
        </FormField>

        <FormCheckRadio
          v-model="form.remember"
          name="remember"
          :label="t('login.remember')"
          :input-value="true"
        />

        <template #footer>
          <BaseButtons>
            <BaseButton
              type="submit"
              color="info"
              :label="isLoading ? t('login.submitting') : t('login.submit')"
              :disabled="isLoading"
            />
          </BaseButtons>
        </template>
      </CardBox>
    </SectionFullScreen>
  </LayoutGuest>
</template>
