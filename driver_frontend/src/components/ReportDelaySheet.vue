<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";

defineProps<{
  submitting?: boolean;
}>();

const emit = defineEmits<{
  confirm: [minutes: number];
  cancel: [];
}>();

const { t } = useI18n();

const PRESETS = [5, 10, 15, 20, 30, 45, 60] as const;
const selected = ref<number>(15);

const selectMinutes = (minutes: number) => {
  selected.value = minutes;
};

const onConfirm = () => {
  emit("confirm", selected.value);
};
</script>

<template>
  <div class="sheet-backdrop" @click.self="!submitting && emit('cancel')">
    <div class="sheet" role="dialog" aria-modal="true">
      <p class="sheet__title">{{ t("delivery.delayTitle") }}</p>
      <p class="sheet__subtitle">{{ t("delivery.delaySubtitle") }}</p>

      <div class="delay-presets" role="group" :aria-label="t('delivery.delayMinutes')">
        <button
          v-for="minutes in PRESETS"
          :key="minutes"
          class="delay-preset"
          type="button"
          :class="{ 'delay-preset--active': selected === minutes }"
          :disabled="submitting"
          @click="selectMinutes(minutes)"
        >
          {{ t("delivery.delayMinutesValue", { minutes }) }}
        </button>
      </div>

      <button
        class="btn btn--primary sheet__action"
        type="button"
        :disabled="submitting"
        @click="onConfirm"
      >
        {{ submitting ? t("delivery.submitting") : t("delivery.delayConfirm") }}
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
