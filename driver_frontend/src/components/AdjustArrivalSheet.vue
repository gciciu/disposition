<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
  currentArrival: string;
  submitting?: boolean;
}>();

const emit = defineEmits<{
  confirm: [arrivalTime: string];
  cancel: [];
}>();

const { t } = useI18n();

const selected = ref(props.currentArrival);

watch(
  () => props.currentArrival,
  (value) => {
    selected.value = value;
  },
);

const canConfirm = computed(() => {
  if (props.submitting) {
    return false;
  }
  const next = selected.value.trim();
  if (!/^\d{2}:\d{2}$/.test(next)) {
    return false;
  }
  // Same calendar day: must be strictly later. Crossing midnight
  // (earlier clock after a late current) is also treated as an increase.
  return next > props.currentArrival || (props.currentArrival >= "20:00" && next < "06:00");
});

const onConfirm = () => {
  if (!canConfirm.value) {
    return;
  }
  emit("confirm", selected.value.trim());
};
</script>

<template>
  <div class="sheet-backdrop" @click.self="!submitting && emit('cancel')">
    <div class="sheet" role="dialog" aria-modal="true">
      <p class="sheet__title">{{ t("delivery.adjustArrivalTitle") }}</p>
      <p class="sheet__subtitle">
        {{ t("delivery.adjustArrivalSubtitle", { time: currentArrival }) }}
      </p>

      <label class="arrival-field">
        <span class="arrival-field__label">{{ t("delivery.adjustArrivalTime") }}</span>
        <input
          v-model="selected"
          class="arrival-field__input"
          type="time"
          step="60"
          :min="currentArrival"
          :disabled="submitting"
        />
      </label>

      <button
        class="btn btn--primary sheet__action"
        type="button"
        :disabled="!canConfirm"
        @click="onConfirm"
      >
        {{ submitting ? t("delivery.submitting") : t("delivery.adjustArrivalConfirm") }}
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
