<script setup lang="ts">
import { useI18n } from "vue-i18n";

defineProps<{
  submitting?: boolean;
}>();

const emit = defineEmits<{
  confirm: [];
  cancel: [];
}>();

const { t } = useI18n();
</script>

<template>
  <div class="sheet-backdrop" @click.self="!submitting && emit('cancel')">
    <div class="sheet" role="dialog" aria-modal="true">
      <p class="sheet__title">{{ t("delivery.refuseTitle") }}</p>
      <p class="sheet__subtitle">{{ t("delivery.refuseSubtitle") }}</p>
      <button
        class="btn btn--danger sheet__action"
        type="button"
        :disabled="submitting"
        @click="emit('confirm')"
      >
        {{ submitting ? t("delivery.submitting") : t("delivery.refuseConfirm") }}
      </button>
      <button
        class="btn btn--ghost sheet__action"
        type="button"
        :disabled="submitting"
        @click="emit('cancel')"
      >
        {{ t("delivery.cancel") }}
      </button>
    </div>
  </div>
</template>
