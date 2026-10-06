<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMainStore } from '@/stores/main'
import { mdiEye, mdiTrashCan } from '@mdi/js'
import CardBoxModal from '@/components/CardBoxModal.vue'
import TableCheckboxCell from '@/components/TableCheckboxCell.vue'
import BaseLevel from '@/components/BaseLevel.vue'
import BaseButtons from '@/components/BaseButtons.vue'
import BaseButton from '@/components/BaseButton.vue'
import UserAvatar from '@/components/UserAvatar.vue'

defineProps({
  checkable: Boolean,
})

const { t } = useI18n()
const mainStore = useMainStore()

const items = computed(() => mainStore.clients)

const isModalActive = ref(false)

const isModalDangerActive = ref(false)

const perPage = ref(5)

const currentPage = ref(0)

const checkedRows = ref([])

const itemsPaginated = computed(() =>
  items.value.slice(perPage.value * currentPage.value, perPage.value * (currentPage.value + 1)),
)

const numPages = computed(() => Math.ceil(items.value.length / perPage.value))

const currentPageHuman = computed(() => currentPage.value + 1)

const pagesList = computed(() => {
  const pagesList = []

  for (let i = 0; i < numPages.value; i++) {
    pagesList.push(i)
  }

  return pagesList
})

const remove = (arr, cb) => {
  const newArr = []

  arr.forEach((item) => {
    if (!cb(item)) {
      newArr.push(item)
    }
  })

  return newArr
}

const checked = (isChecked, client) => {
  if (isChecked) {
    checkedRows.value.push(client)
  } else {
    checkedRows.value = remove(checkedRows.value, (row) => row.id === client.id)
  }
}
</script>

<template>
  <CardBoxModal v-model="isModalActive" :title="t('table.sampleModal')">
    <p>Lorem ipsum dolor sit amet <b>adipiscing elit</b></p>
    <p>{{ t('table.sampleModalBody') }}</p>
  </CardBoxModal>

  <CardBoxModal
    v-model="isModalDangerActive"
    :title="t('table.confirm')"
    button="danger"
    has-cancel
  >
    <p>Lorem ipsum dolor sit amet <b>adipiscing elit</b></p>
    <p>{{ t('table.sampleModalBody') }}</p>
  </CardBoxModal>

  <table>
    <thead>
      <tr>
        <th v-if="checkable" />
        <th />
        <th>{{ t('table.name') }}</th>
        <th>{{ t('table.company') }}</th>
        <th>{{ t('table.city') }}</th>
        <th>{{ t('table.progress') }}</th>
        <th>{{ t('table.created') }}</th>
        <th />
      </tr>
    </thead>
    <tbody>
      <tr v-for="client in itemsPaginated" :key="client.id">
        <TableCheckboxCell v-if="checkable" @checked="checked($event, client)" />
        <td class="border-b-0 before:hidden lg:w-6">
          <UserAvatar :username="client.name" class="mx-auto h-24 w-24 lg:h-6 lg:w-6" />
        </td>
        <td :data-label="t('table.name')">
          {{ client.name }}
        </td>
        <td :data-label="t('table.company')">
          {{ client.company }}
        </td>
        <td :data-label="t('table.city')">
          {{ client.city }}
        </td>
        <td :data-label="t('table.progress')" class="lg:w-32">
          <progress class="flex w-2/5 self-center lg:w-full" max="100" :value="client.progress">
            {{ client.progress }}
          </progress>
        </td>
        <td :data-label="t('table.created')" class="whitespace-nowrap lg:w-1">
          <small class="text-gray-500 dark:text-slate-400" :title="client.created">{{
            client.created
          }}</small>
        </td>
        <td class="whitespace-nowrap before:hidden lg:w-1">
          <BaseButtons type="justify-start lg:justify-end" no-wrap>
            <BaseButton color="info" :icon="mdiEye" small @click="isModalActive = true" />
            <BaseButton
              color="danger"
              :icon="mdiTrashCan"
              small
              @click="isModalDangerActive = true"
            />
          </BaseButtons>
        </td>
      </tr>
    </tbody>
  </table>
  <div class="border-t border-gray-100 p-3 lg:px-6 dark:border-slate-800">
    <BaseLevel>
      <BaseButtons>
        <BaseButton
          v-for="page in pagesList"
          :key="page"
          :active="page === currentPage"
          :label="page + 1"
          :color="page === currentPage ? 'lightDark' : 'whiteDark'"
          small
          @click="currentPage = page"
        />
      </BaseButtons>
      <small>{{ t('table.pageOf', { current: currentPageHuman, total: numPages }) }}</small>
    </BaseLevel>
  </div>
</template>
