<script setup lang="ts">
import { useI18n } from "vue-i18n";
import type { ProofType } from "../api";

const emit = defineEmits<{
  select: [type: ProofType];
  cancel: [];
}>();

const { t } = useI18n();
</script>

<template>
  <div class="sheet-backdrop" @click.self="emit('cancel')">
    <div class="sheet" role="dialog" aria-modal="true">
      <p class="sheet__title">{{ t("delivery.chooseTitle") }}</p>
      <p class="sheet__subtitle">{{ t("delivery.chooseSubtitle") }}</p>
      <button
        class="btn btn--primary sheet__action"
        type="button"
        @click="emit('select', 'photo')"
      >
        {{ t("delivery.photo") }}
      </button>
      <button
        class="btn btn--primary sheet__action"
        type="button"
        @click="emit('select', 'signature')"
      >
        {{ t("delivery.signature") }}
      </button>
      <button
        class="btn btn--ghost sheet__action"
        type="button"
        @click="emit('cancel')"
      >
        {{ t("delivery.cancel") }}
      </button>
    </div>
  </div>
</template>
