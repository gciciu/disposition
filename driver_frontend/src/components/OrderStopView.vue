<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { RouteOrder } from "../api";
import { dialPhone } from "../dialPhone";
import { buildStopMapsUrl, openGoogleMapsUrl } from "../openExternalUrl";
import { withoutCountry } from "../utils/address";

const props = defineProps<{
  order: RouteOrder;
  submitting: boolean;
  actionsEnabled: boolean;
  readOnly?: boolean;
}>();

const emit = defineEmits<{
  back: [];
  confirm: [payload: { deliveredProductIndexes: number[]; courierComment: string }];
  refuse: [courierComment: string];
}>();

const { t } = useI18n();

const selected = ref<Set<number>>(new Set());
const courierComment = ref("");

const isDelivered = computed(() => props.order.status === "delivered");
const isRefused = computed(() => props.order.status === "refused");
const isClosed = computed(() => isDelivered.value || isRefused.value);

const statusLabel = computed(() => {
  switch (props.order.deliveryMethod) {
    case "personal":
      return t("delivery.methodPersonal");
    case "left_on_site":
      return t("delivery.methodLeftOnSite");
    case "neighbor":
      return t("delivery.methodNeighbor");
    case "partial":
      return t("delivery.methodPartial");
    case "not_delivered":
      return t("delivery.methodNotDelivered");
    default:
      break;
  }
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

watch(
  () => [props.order.id, props.order.courierComment] as const,
  () => {
    courierComment.value = props.order.courierComment ?? "";
  },
  { immediate: true },
);

const isChecked = (index: number) => selected.value.has(index);

const accessoryIndexSet = computed(
  () =>
    new Set(
      (props.order.accessoryProductIndexes ?? [])
        .map((index) => Number(index))
        .filter((index) => Number.isInteger(index) && index >= 0),
    ),
);

const regularProducts = computed(() => {
  const products = props.order.products ?? [];
  const accessories = accessoryIndexSet.value;
  return products
    .map((product, index) => ({ product, index }))
    .filter(({ index }) => !accessories.has(index));
});

const accessoryProducts = computed(() => {
  const products = props.order.products ?? [];
  const accessories = accessoryIndexSet.value;
  return products
    .map((product, index) => ({ product, index }))
    .filter(({ index }) => accessories.has(index));
});

const productBackgroundStyle = (index: number) => {
  const color = props.order.productBackgroundColors?.[index];
  if (!color) {
    return undefined;
  }
  return { backgroundColor: color };
};

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
  emit("confirm", {
    deliveredProductIndexes: [...selected.value].sort((a, b) => a - b),
    courierComment: courierComment.value.trim(),
  });
};

const onRefuse = () => {
  if (actionsLocked.value) {
    return;
  }
  emit("refuse", courierComment.value.trim());
};

const onCall = (event: Event) => {
  event.preventDefault();
  if (!props.order.phone) {
    return;
  }
  void dialPhone(props.order.phone);
};

const stopMapsUrl = computed(() => buildStopMapsUrl(props.order));

const openStopMaps = async (event: Event) => {
  event.preventDefault();
  event.stopPropagation();
  const url = stopMapsUrl.value;
  if (!url) {
    return;
  }
  await openGoogleMapsUrl(url);
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
          <div class="stop-card__aside">
            <button
              v-if="stopMapsUrl"
              class="stop-card__map-btn"
              type="button"
              :aria-label="t('routes.openStopMaps')"
              :title="t('routes.openStopMaps')"
              @click="openStopMaps"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true" fill="none">
                <path
                  d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11z"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.8" />
              </svg>
            </button>
            <div class="stop-card__badges">
              <span v-if="order.isExchange" class="stop-card__badge stop-card__badge--exchange">
                {{ t("delivery.exchange") }}
              </span>
              <span
                v-if="isClosed"
                class="stop-card__badge"
                :class="{ 'stop-card__badge--refused': isRefused }"
              >
                {{ statusLabel }}
              </span>
            </div>
          </div>
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
        <div class="stop-card__footer">
          <div class="stop-card__arrival-line">
            <div
              class="stop-card__arrival"
              :title="t('routes.arrivalTime', { time: order.arrivalTime || '—' })"
            >
              {{ order.arrivalTime || "—" }}
            </div>
          </div>
        </div>
      </div>
    </article>

    <section class="product-panel">
      <template v-if="order.comments?.length">
        <h2 class="product-panel__title">{{ t("delivery.comments") }}</h2>
        <ul class="office-comment-list">
          <li
            v-for="comment in order.comments"
            :key="comment.id"
            class="office-comment"
            :class="`office-comment--${comment.color || 'amber'}`"
          >
            {{ comment.text }}
          </li>
        </ul>
      </template>
      <h2
        class="product-panel__title"
        :class="{ 'product-panel__title--accessory': Boolean(order.comments?.length) }"
      >
        {{ t("delivery.courierComment") }}
      </h2>
      <textarea
        v-if="!isClosed && !readOnly"
        v-model="courierComment"
        class="order-stop__comment"
        rows="3"
        maxlength="2000"
        :disabled="actionsLocked"
        :placeholder="t('delivery.courierCommentPlaceholder')"
      />
      <p v-else-if="courierComment" class="order-stop__comment-text">
        {{ courierComment }}
      </p>
      <p v-else class="product-panel__empty">{{ t("delivery.courierCommentEmpty") }}</p>
    </section>

    <section class="product-panel">
      <h2 class="product-panel__title">{{ t("delivery.products") }}</h2>
      <p
        v-if="!regularProducts.length && !accessoryProducts.length"
        class="product-panel__empty"
      >
        {{ t("delivery.noProducts") }}
      </p>
      <ul v-else-if="regularProducts.length" class="product-list">
        <li v-for="{ product, index: idx } in regularProducts" :key="idx">
          <label
            class="product-check"
            :class="{
              'product-check--done': isDelivered && isChecked(idx),
              'product-check--missed': isClosed && !isChecked(idx),
              'product-check--disabled': actionsLocked,
            }"
            :style="productBackgroundStyle(idx)"
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

    <section v-if="accessoryProducts.length" class="product-panel">
      <h2 class="product-panel__title">{{ t("delivery.accessories") }}</h2>
      <ul class="product-list">
        <li v-for="{ product, index: idx } in accessoryProducts" :key="`acc-${idx}`">
          <label
            class="product-check"
            :class="{
              'product-check--done': isDelivered && isChecked(idx),
              'product-check--missed': isClosed && !isChecked(idx),
              'product-check--disabled': actionsLocked,
            }"
            :style="productBackgroundStyle(idx)"
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

    <p v-if="!isClosed && readOnly" class="order-stop__hint">
      {{ t("routes.completed") }}
    </p>
    <p v-else-if="!isClosed && !actionsEnabled" class="order-stop__hint">
      {{ t("routes.startRequired") }}
    </p>

    <div v-if="!isClosed && !readOnly" class="order-stop__actions">
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
