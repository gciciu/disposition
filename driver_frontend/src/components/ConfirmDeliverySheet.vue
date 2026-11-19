<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { ProofType } from "../api";

const props = defineProps<{
  /** True when selected products are a proper subset → treat as Teillieferung automatically. */
  canPartial: boolean;
}>();

export type DeliveryMethodChoice =
  | {
      method: "personal" | "left_on_site" | "neighbor" | "partial";
      proofType: ProofType;
      neighborName?: string;
    }
  | { method: "not_delivered" };

const emit = defineEmits<{
  select: [choice: DeliveryMethodChoice];
  cancel: [];
}>();

const { t } = useI18n();

type Step = "method" | "neighbor" | "partialProof";

/** Partial is inferred from unchecked products — no separate method button. */
const step = ref<Step>(props.canPartial ? "partialProof" : "method");
const neighborName = ref("");
const neighborError = ref("");

const methods: Array<{
  id: "personal" | "left_on_site" | "neighbor" | "not_delivered";
  labelKey: string;
  danger?: boolean;
}> = [
  { id: "personal", labelKey: "delivery.methodPersonal" },
  { id: "left_on_site", labelKey: "delivery.methodLeftOnSite" },
  { id: "neighbor", labelKey: "delivery.methodNeighbor" },
  { id: "not_delivered", labelKey: "delivery.methodNotDelivered", danger: true },
];

const title = computed(() => {
  if (step.value === "neighbor") {
    return t("delivery.neighborTitle");
  }
  if (step.value === "partialProof") {
    return t("delivery.partialProofTitle");
  }
  return t("delivery.chooseTitle");
});

const subtitle = computed(() => {
  if (step.value === "neighbor") {
    return t("delivery.neighborSubtitle");
  }
  if (step.value === "partialProof") {
    return t("delivery.partialProofSubtitle");
  }
  return t("delivery.chooseSubtitle");
});

const onMethod = (id: (typeof methods)[number]["id"]) => {
  neighborError.value = "";

  if (id === "not_delivered") {
    emit("select", { method: "not_delivered" });
    return;
  }

  if (id === "personal") {
    emit("select", { method: "personal", proofType: "signature" });
    return;
  }

  if (id === "left_on_site") {
    emit("select", { method: "left_on_site", proofType: "photo" });
    return;
  }

  if (id === "neighbor") {
    step.value = "neighbor";
  }
};

const confirmNeighbor = () => {
  const name = neighborName.value.trim();
  if (!name) {
    neighborError.value = t("delivery.neighborRequired");
    return;
  }
  emit("select", {
    method: "neighbor",
    proofType: "signature",
    neighborName: name,
  });
};

const onPartialProof = (proofType: ProofType) => {
  emit("select", { method: "partial", proofType });
};

const goBack = () => {
  if (step.value === "method") {
    emit("cancel");
    return;
  }
  // Auto-opened partial proof has no prior method step — cancel instead.
  if (step.value === "partialProof" && props.canPartial) {
    emit("cancel");
    return;
  }
  neighborError.value = "";
  step.value = "method";
};
</script>

<template>
  <div class="sheet-backdrop" @click.self="emit('cancel')">
    <div class="sheet" role="dialog" aria-modal="true">
      <p class="sheet__title">{{ title }}</p>
      <p class="sheet__subtitle">{{ subtitle }}</p>

      <p v-if="neighborError" class="sheet__error">{{ neighborError }}</p>

      <template v-if="step === 'method'">
        <button
          v-for="item in methods"
          :key="item.id"
          class="btn sheet__action"
          :class="item.danger ? 'btn--danger' : 'btn--primary'"
          type="button"
          @click="onMethod(item.id)"
        >
          {{ t(item.labelKey) }}
        </button>
      </template>

      <template v-else-if="step === 'neighbor'">
        <label class="sheet__field">
          <span class="sheet__field-label">{{ t("delivery.neighborName") }}</span>
          <input
            v-model="neighborName"
            class="sheet__input"
            type="text"
            autocomplete="name"
            :placeholder="t('delivery.neighborNamePlaceholder')"
            @keydown.enter.prevent="confirmNeighbor"
          />
        </label>
        <button
          class="btn btn--primary sheet__action"
          type="button"
          @click="confirmNeighbor"
        >
          {{ t("delivery.neighborContinue") }}
        </button>
      </template>

      <template v-else>
        <button
          class="btn btn--primary sheet__action"
          type="button"
          @click="onPartialProof('photo')"
        >
          {{ t("delivery.photo") }}
        </button>
        <button
          class="btn btn--primary sheet__action"
          type="button"
          @click="onPartialProof('signature')"
        >
          {{ t("delivery.signature") }}
        </button>
      </template>

      <button class="btn btn--ghost sheet__action" type="button" @click="goBack">
        {{ step === "method" ? t("delivery.cancel") : t("delivery.back") }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.sheet__error {
  margin: 0 0 0.75rem;
  color: #b42318;
  font-size: 0.875rem;
  line-height: 1.35;
}

.sheet__field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 0.85rem;
}

.sheet__field-label {
  font-size: 0.8rem;
  font-weight: 600;
  color: #344054;
}

.sheet__input {
  width: 100%;
  border: 1px solid #d0d5dd;
  border-radius: 0.65rem;
  padding: 0.7rem 0.85rem;
  font-size: 1rem;
  background: #fff;
}

.sheet__input:focus {
  outline: 2px solid #98a2b3;
  outline-offset: 1px;
}
</style>
