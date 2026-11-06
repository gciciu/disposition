<script setup lang="ts">
import { useI18n } from "vue-i18n";

defineProps<{
  title: string;
  subtitle: string;
  confirmLabel: string;
  submitting?: boolean;
  submittingLabel?: string;
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
      <p class="sheet__title">{{ title }}</p>
      <p class="sheet__subtitle">{{ subtitle }}</p>
      <button
        class="btn btn--primary sheet__action"
        type="button"
        :disabled="submitting"
        @click="emit('confirm')"
      >
        {{ submitting ? submittingLabel || confirmLabel : confirmLabel }}
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
