<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { RouteOrder } from "../api";
import { dialPhone } from "../dialPhone";
import { withoutCountry } from "../utils/address";

const props = defineProps<{
  order: RouteOrder;
  submitting: boolean;
  actionsEnabled: boolean;
}>();

const emit = defineEmits<{
  back: [];
  confirm: [deliveredProductIndexes: number[]];
  refuse: [];
}>();

const { t } = useI18n();

const selected = ref<Set<number>>(new Set());

const isDelivered = computed(() => props.order.status === "delivered");
const isRefused = computed(() => props.order.status === "refused");
const isClosed = computed(() => isDelivered.value || isRefused.value);

const statusLabel = computed(() => {
  if (isDelivered.value) {
    return t("delivery.delivered");
  }
  if (isRefused.value) {
    return t("delivery.refused");
  }
  return "";
});

const displayAddress = computed(() =>
  withoutCountry(props.order.formattedAddress || props.order.address),
);

const syncSelection = () => {
  const products = props.order.products ?? [];
  if (isRefused.value) {
    selected.value = new Set();
    return;
  }
  if (isDelivered.value) {
    const indexes = props.order.deliveredProductIndexes;
    selected.value = new Set(
      indexes ?? (products.length > 0 ? products.map((_, i) => i) : []),
    );
    return;
  }
  selected.value = new Set(products.map((_, i) => i));
};

watch(
  () => [props.order.id, props.order.status, props.order.deliveredProductIndexes] as const,
  syncSelection,
  { immediate: true },
);

const isChecked = (index: number) => selected.value.has(index);

const actionsLocked = computed(
  () => isClosed.value || props.submitting || !props.actionsEnabled,
);

const toggleProduct = (index: number) => {
  if (actionsLocked.value) {
    return;
  }
  const next = new Set(selected.value);
  if (next.has(index)) {
    next.delete(index);
  } else {
    next.add(index);
  }
  selected.value = next;
};

const onConfirm = () => {
  if (actionsLocked.value) {
    return;
  }
  emit(
    "confirm",
    [...selected.value].sort((a, b) => a - b),
  );
};

const onRefuse = () => {
  if (actionsLocked.value) {
    return;
  }
  emit("refuse");
};

const onCall = (event: Event) => {
  event.preventDefault();
  if (!props.order.phone) {
    return;
  }
  void dialPhone(props.order.phone);
};
</script>

<template>
  <div class="order-stop">
    <div class="driver-routes__actions">
      <button class="btn btn--ghost btn--compact" type="button" @click="emit('back')">
        {{ t("routes.backToStops") }}
      </button>
    </div>

    <article
      class="stop-card stop-card--detail"
      :class="{
        'stop-card--done': isDelivered,
        'stop-card--refused': isRefused,
      }"
    >
      <div
        class="stop-card__index"
        :class="{
          'stop-card__index--done': isDelivered,
          'stop-card__index--refused': isRefused,
        }"
      >
        {{ order.position }}
      </div>
      <div class="stop-card__body">
        <div class="stop-card__heading">
          <div class="stop-card__name">{{ order.clientName }}</div>
          <span
            v-if="isClosed"
            class="stop-card__badge"
            :class="{ 'stop-card__badge--refused': isRefused }"
          >
            {{ statusLabel }}
          </span>
        </div>
        <a
          v-if="order.phone"
          class="stop-card__phone"
          :href="`tel:${order.phone}`"
          @click="onCall"
        >
          {{ order.phone }}
        </a>
        <div class="stop-card__address">{{ displayAddress }}</div>
      </div>
    </article>

    <section class="product-panel">
      <h2 class="product-panel__title">{{ t("delivery.products") }}</h2>
      <p v-if="!order.products?.length" class="product-panel__empty">
        {{ t("delivery.noProducts") }}
      </p>
      <ul v-else class="product-list">
        <li v-for="(product, idx) in order.products" :key="idx">
          <label
            class="product-check"
            :class="{
              'product-check--done': isDelivered && isChecked(idx),
              'product-check--missed': isClosed && !isChecked(idx),
              'product-check--disabled': actionsLocked,
            }"
          >
            <input
              class="product-check__input"
              type="checkbox"
              :checked="isChecked(idx)"
              :disabled="actionsLocked"
              @change="toggleProduct(idx)"
            />
            <span class="product-check__box" aria-hidden="true" />
            <span class="product-check__label">{{ product }}</span>
          </label>
        </li>
      </ul>
    </section>

    <p v-if="!isClosed && !actionsEnabled" class="order-stop__hint">
      {{ t("routes.startRequired") }}
    </p>

    <div v-if="!isClosed" class="order-stop__actions">
      <button
        class="btn btn--primary"
        type="button"
        :disabled="actionsLocked"
        @click="onConfirm"
      >
        {{ t("delivery.confirm") }}
      </button>
      <button
        class="btn btn--danger"
        type="button"
        :disabled="actionsLocked"
        @click="onRefuse"
      >
        {{ t("delivery.refuse") }}
      </button>
    </div>
  </div>
</template>
