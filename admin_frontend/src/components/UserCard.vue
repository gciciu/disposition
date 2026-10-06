<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMainStore } from '@/stores/main'
import { mdiCheckDecagram } from '@mdi/js'
import BaseLevel from '@/components/BaseLevel.vue'
import UserAvatarCurrentUser from '@/components/UserAvatarCurrentUser.vue'
import CardBox from '@/components/CardBox.vue'
import FormCheckRadio from '@/components/FormCheckRadio.vue'
import PillTag from '@/components/PillTag.vue'

const { t } = useI18n()
const mainStore = useMainStore()

const userName = computed(() => mainStore.userName)

const userSwitchVal = ref(false)
</script>

<template>
  <CardBox>
    <BaseLevel type="justify-around lg:justify-center">
      <UserAvatarCurrentUser class="lg:mx-12" />
      <div class="space-y-3 text-center md:text-left lg:mx-12">
        <div class="flex justify-center md:block">
          <FormCheckRadio
            v-model="userSwitchVal"
            name="notifications-switch"
            type="switch"
            :label="t('profile.notifications')"
            :input-value="true"
          />
        </div>
        <h1 class="text-2xl">
          <i18n-t keypath="profile.howdy" tag="span">
            <template #name>
              <b>{{ userName }}</b>
            </template>
          </i18n-t>
        </h1>
        <p>
          <i18n-t keypath="profile.lastLogin" tag="span">
            <template #time>
              <b>{{ t('profile.lastLoginTime') }}</b>
            </template>
            <template #ip>
              <b>127.0.0.1</b>
            </template>
          </i18n-t>
        </p>
        <div class="flex justify-center md:block">
          <PillTag :label="t('profile.verified')" color="info" :icon="mdiCheckDecagram" />
        </div>
      </div>
    </BaseLevel>
  </CardBox>
</template>
