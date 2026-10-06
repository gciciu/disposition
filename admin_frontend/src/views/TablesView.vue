<script setup>
import { useI18n } from 'vue-i18n'
import { mdiMonitorCellphone, mdiTableBorder, mdiTableOff } from '@mdi/js'
import SectionMain from '@/components/SectionMain.vue'
import NotificationBar from '@/components/NotificationBar.vue'
import TableSampleClients from '@/components/TableSampleClients.vue'
import CardBox from '@/components/CardBox.vue'
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue'
import SectionTitleLineWithButton from '@/components/SectionTitleLineWithButton.vue'
import CardBoxComponentEmpty from '@/components/CardBoxComponentEmpty.vue'

const { t } = useI18n()
</script>

<template>
  <LayoutAuthenticated>
    <SectionMain>
      <SectionTitleLineWithButton :icon="mdiTableBorder" :title="t('tables.title')" main />
      <NotificationBar color="info" :icon="mdiMonitorCellphone">
        <b>{{ t('dashboard.responsiveTable') }}</b> {{ t('dashboard.responsiveTableHint') }}
      </NotificationBar>

      <CardBox class="mb-6" has-table>
        <TableSampleClients checkable />
      </CardBox>

      <SectionTitleLineWithButton :icon="mdiTableOff" :title="t('tables.emptyVariation')" />

      <NotificationBar color="danger" :icon="mdiTableOff">
        <b>{{ t('tables.emptyTable') }}</b> {{ t('tables.emptyTableHint') }}
      </NotificationBar>

      <CardBox>
        <CardBoxComponentEmpty />
      </CardBox>
    </SectionMain>
  </LayoutAuthenticated>
</template>
