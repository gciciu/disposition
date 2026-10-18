<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
  ApiError,
  completeOrderRequest,
  fetchMyRouteRequest,
  fetchMyRoutesRequest,
  refuseOrderRequest,
  startRouteRequest,
  type ProofType,
  type RouteDetail,
  type RouteListItem,
  type RouteOrder,
} from "../api";
import { useAuth } from "../auth";
import ConfirmDeliverySheet from "../components/ConfirmDeliverySheet.vue";
import OrderStopView from "../components/OrderStopView.vue";
import RefuseDeliverySheet from "../components/RefuseDeliverySheet.vue";
import SignaturePadModal from "../components/SignaturePadModal.vue";
import { dialPhone } from "../dialPhone";
import { GeolocationError, getCurrentPosition } from "../geolocation";
import { toProofJpeg } from "../imageProof";
import { withoutCountry } from "../utils/address";

const { t, locale } = useI18n();
const { logout, state } = useAuth();

const loading = ref(true);
const loadingDetail = ref(false);
const submitting = ref(false);
const starting = ref(false);
const error = ref("");
const routes = ref<RouteListItem[]>([]);
const selectedId = ref<number | null>(null);
const detail = ref<RouteDetail | null>(null);
const selectedOrderId = ref<number | null>(null);
const showClosed = ref(false);

const confirmOrder = ref<RouteOrder | null>(null);
const refuseOrder = ref<RouteOrder | null>(null);
const pendingDeliveredIndexes = ref<number[]>([]);
const showSignature = ref(false);
const photoInput = ref<HTMLInputElement | null>(null);

const hasRoutes = computed(() => routes.value.length > 0);

const selectedOrder = computed(() => {
  if (!detail.value || selectedOrderId.value === null) {
    return null;
  }
  return detail.value.orders.find((item) => item.id === selectedOrderId.value) ?? null;
});

const formatDate = (value: string) => {
  try {
    return new Date(value).toLocaleString(locale.value);
  } catch {
    return value;
  }
};

const displayAddress = (order: RouteOrder) =>
  withoutCountry(order.formattedAddress || order.address);

const isDelivered = (order: RouteOrder) => order.status === "delivered";
const isRefused = (order: RouteOrder) => order.status === "refused";
const isClosed = (order: RouteOrder) => isDelivered(order) || isRefused(order);

const closedStatusLabel = (order: RouteOrder) => {
  if (isDelivered(order)) {
    return t("delivery.delivered");
  }
  if (isRefused(order)) {
    return t("delivery.refused");
  }
  return "";
};

const pendingOrders = computed(
  () => detail.value?.orders.filter((order) => !isClosed(order)) ?? [],
);
const closedOrders = computed(
  () => detail.value?.orders.filter((order) => isClosed(order)) ?? [],
);

const isRouteStarted = computed(() => Boolean(detail.value?.startedAt));
const isRouteCompleted = computed(() => Boolean(detail.value?.completedAt));
const canStartRoute = computed(
  () => Boolean(detail.value) && !isRouteStarted.value && !isRouteCompleted.value,
);
const deliveryActionsEnabled = computed(
  () => isRouteStarted.value && !isRouteCompleted.value,
);

const syncRouteListItem = (route: RouteDetail) => {
  routes.value = routes.value.map((item) =>
    item.id === route.id
      ? {
          ...item,
          orderCount: route.orderCount,
          totalDurationText: route.totalDurationText,
          totalDistanceText: route.totalDistanceText,
          mapsUrl: route.mapsUrl,
          originType: route.originType,
          startedAt: route.startedAt,
          completedAt: route.completedAt,
        }
      : item,
  );
};

const loadRoutes = async () => {
  loading.value = true;
  error.value = "";
  selectedId.value = null;
  selectedOrderId.value = null;
  detail.value = null;

  try {
    const data = await fetchMyRoutesRequest();
    routes.value = data.routes ?? [];
    if (routes.value.length === 1) {
      await openRoute(routes.value[0].id);
    }
  } catch (e) {
    error.value =
      e instanceof ApiError ? e.message : t("routes.loadFailed");
  } finally {
    loading.value = false;
  }
};

const openRoute = async (id: number) => {
  selectedId.value = id;
  selectedOrderId.value = null;
  showClosed.value = false;
  loadingDetail.value = true;
  error.value = "";
  detail.value = null;

  try {
    const data = await fetchMyRouteRequest(id);
    detail.value = data.route;
  } catch (e) {
    error.value =
      e instanceof ApiError ? e.message : t("routes.loadDetailFailed");
    selectedId.value = null;
  } finally {
    loadingDetail.value = false;
  }
};

const backToList = () => {
  selectedId.value = null;
  selectedOrderId.value = null;
  showClosed.value = false;
  detail.value = null;
  error.value = "";
};

const openOrder = (order: RouteOrder) => {
  error.value = "";
  selectedOrderId.value = order.id;
};

const onCall = (event: Event, phone: string) => {
  event.preventDefault();
  event.stopPropagation();
  void dialPhone(phone);
};

const backToStops = () => {
  if (submitting.value) {
    return;
  }
  selectedOrderId.value = null;
  closeConfirm();
  closeRefuse();
};

const startRoute = async () => {
  const routeId = selectedId.value;
  if (routeId === null || !canStartRoute.value || starting.value || submitting.value) {
    return;
  }

  starting.value = true;
  error.value = "";

  try {
    let coords: { lat: number; lng: number } | undefined;
    if (detail.value?.originType === "courier_location") {
      coords = await getCurrentPosition();
    }

    const data = await startRouteRequest(routeId, coords);
    detail.value = data.route;
    syncRouteListItem(data.route);
  } catch (e) {
    if (e instanceof GeolocationError) {
      error.value =
        e.code === "denied"
          ? t("routes.locationDenied")
          : t("routes.locationFailed");
    } else {
      error.value =
        e instanceof ApiError ? e.message : t("routes.startFailed");
    }
  } finally {
    starting.value = false;
  }
};

const openConfirm = (order: RouteOrder, deliveredProductIndexes: number[]) => {
  if (isClosed(order) || submitting.value || !deliveryActionsEnabled.value) {
    return;
  }
  error.value = "";
  refuseOrder.value = null;
  pendingDeliveredIndexes.value = deliveredProductIndexes;
  confirmOrder.value = order;
};

const onOrderConfirm = (deliveredProductIndexes: number[]) => {
  if (!selectedOrder.value) {
    return;
  }
  openConfirm(selectedOrder.value, deliveredProductIndexes);
};

const openRefuse = () => {
  const order = selectedOrder.value;
  if (!order || isClosed(order) || submitting.value || !deliveryActionsEnabled.value) {
    return;
  }
  error.value = "";
  confirmOrder.value = null;
  showSignature.value = false;
  refuseOrder.value = order;
};

const closeConfirm = () => {
  if (submitting.value) {
    return;
  }
  confirmOrder.value = null;
  showSignature.value = false;
  pendingDeliveredIndexes.value = [];
};

const closeRefuse = () => {
  if (submitting.value) {
    return;
  }
  refuseOrder.value = null;
};

const onProofChoice = (type: ProofType) => {
  if (!confirmOrder.value) {
    return;
  }
  if (type === "photo") {
    photoInput.value?.click();
    return;
  }
  showSignature.value = true;
};

const applyRouteMutation = (_order: RouteOrder, route: RouteDetail) => {
  detail.value = route;
  syncRouteListItem(route);
};

const submitProof = async (
  proofType: ProofType,
  file: Blob,
  filename: string,
) => {
  const order = confirmOrder.value;
  const routeId = selectedId.value;
  if (!order || routeId === null) {
    return;
  }

  submitting.value = true;
  error.value = "";

  try {
    const data = await completeOrderRequest(
      routeId,
      order.id,
      proofType,
      file,
      filename,
      pendingDeliveredIndexes.value,
    );
    applyRouteMutation(data.order, data.route);
    confirmOrder.value = null;
    showSignature.value = false;
    pendingDeliveredIndexes.value = [];
    selectedOrderId.value = null;
  } catch (e) {
    error.value =
      e instanceof ApiError ? e.message : t("delivery.submitFailed");
  } finally {
    submitting.value = false;
  }
};

const onPhotoSelected = async (event: Event) => {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = "";
  if (!file) {
    return;
  }

  try {
    const { blob, filename } = await toProofJpeg(file);
    await submitProof("photo", blob, filename);
  } catch (e) {
    error.value =
      e instanceof Error && e.message ? e.message : t("delivery.submitFailed");
  }
};

const onSignatureConfirm = async (blob: Blob) => {
  await submitProof("signature", blob, "signature.png");
};

const submitRefuse = async () => {
  const order = refuseOrder.value;
  const routeId = selectedId.value;
  if (!order || routeId === null || submitting.value) {
    return;
  }

  submitting.value = true;
  error.value = "";

  try {
    const data = await refuseOrderRequest(routeId, order.id);
    applyRouteMutation(data.order, data.route);
    refuseOrder.value = null;
    selectedOrderId.value = null;
  } catch (e) {
    error.value =
      e instanceof ApiError ? e.message : t("delivery.refuseFailed");
  } finally {
    submitting.value = false;
  }
};

onMounted(loadRoutes);

watch(
  () => state.user?.id,
  () => {
    if (state.user) {
      void loadRoutes();
    }
  },
);
</script>

<template>
  <div class="driver-routes">
    <div class="driver-routes__top">
      <div>
        <p class="brand-mark">{{ t("common.brand") }}</p>
        <h1 class="panel__title">
          {{ detail?.name || t("routes.title") }}
        </h1>
        <p v-if="state.user?.name" class="driver-routes__meta">
          {{ state.user.name }}
        </p>
      </div>
      <button class="btn btn--ghost btn--compact" type="button" @click="logout">
        {{ t("common.logout") }}
      </button>
    </div>

    <p v-if="error" class="alert">{{ error }}</p>

    <div v-if="loading" class="loading-copy">{{ t("common.loading") }}</div>

    <template v-else-if="!hasRoutes">
      <div class="empty-state empty-state--inline">
        <p class="panel__subtitle">{{ t("routes.empty") }}</p>
      </div>
    </template>

    <template v-else-if="selectedId === null">
      <div class="route-list">
        <button
          v-for="route in routes"
          :key="route.id"
          class="route-card"
          type="button"
          @click="openRoute(route.id)"
        >
          <div class="route-card__title">{{ route.name }}</div>
          <div class="route-card__meta">
            <span>{{ t("routes.ordersCount", { count: route.orderCount }) }}</span>
            <span v-if="route.totalDurationText">
              {{ route.totalDurationText }}
              <template v-if="route.totalDistanceText">
                · {{ route.totalDistanceText }}
              </template>
            </span>
            <span>{{ formatDate(route.createdAt) }}</span>
          </div>
        </button>
      </div>
    </template>

    <template v-else>
      <div v-if="loadingDetail" class="loading-copy">{{ t("common.loading") }}</div>

      <OrderStopView
        v-else-if="selectedOrder"
        :order="selectedOrder"
        :submitting="submitting"
        :actions-enabled="deliveryActionsEnabled"
        @back="backToStops"
        @confirm="onOrderConfirm"
        @refuse="openRefuse"
      />

      <template v-else-if="detail">
        <div class="driver-routes__actions">
          <button
            v-if="routes.length > 1"
            class="btn btn--ghost btn--compact"
            type="button"
            @click="backToList"
          >
            {{ t("routes.backToList") }}
          </button>
          <button
            v-if="canStartRoute"
            class="btn btn--primary btn--compact"
            type="button"
            :disabled="starting || submitting"
            @click="startRoute"
          >
            {{ starting ? t("routes.starting") : t("routes.start") }}
          </button>
          <a
            v-if="detail.mapsUrl && isRouteStarted"
            class="btn btn--primary btn--compact"
            :href="detail.mapsUrl"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ t("routes.openMaps") }}
          </a>
        </div>

        <p v-if="isRouteCompleted" class="route-status route-status--done">
          {{ t("routes.completed") }}
        </p>
        <p v-else-if="!isRouteStarted" class="route-status">
          {{ t("routes.startHint") }}
        </p>

        <div class="route-summary">
          <span>{{ t("routes.ordersCount", { count: detail.orderCount }) }}</span>
          <span v-if="detail.totalDurationText">
            {{ detail.totalDurationText }}
            <template v-if="detail.totalDistanceText">
              · {{ detail.totalDistanceText }}
            </template>
          </span>
        </div>

        <template v-if="closedOrders.length">
          <div v-if="showClosed" class="stop-list">
            <article
              v-for="order in closedOrders"
              :key="order.id"
              class="stop-card stop-card--link"
              :class="{
                'stop-card--done': isDelivered(order),
                'stop-card--refused': isRefused(order),
              }"
              role="button"
              tabindex="0"
              @click="openOrder(order)"
              @keydown.enter.prevent="openOrder(order)"
            >
              <div
                class="stop-card__index"
                :class="{
                  'stop-card__index--done': isDelivered(order),
                  'stop-card__index--refused': isRefused(order),
                }"
              >
                {{ order.position }}
              </div>
              <div class="stop-card__body">
                <div class="stop-card__heading">
                  <div class="stop-card__name">{{ order.clientName }}</div>
                  <span
                    class="stop-card__badge"
                    :class="{ 'stop-card__badge--refused': isRefused(order) }"
                  >
                    {{ closedStatusLabel(order) }}
                  </span>
                </div>
                <a
                  v-if="order.phone"
                  class="stop-card__phone"
                  :href="`tel:${order.phone}`"
                  @click="onCall($event, order.phone)"
                >
                  {{ order.phone }}
                </a>
                <div class="stop-card__address">{{ displayAddress(order) }}</div>
                <div
                  v-if="order.travelDurationText || order.travelDistanceText"
                  class="stop-card__travel"
                >
                  <span v-if="order.travelDurationText">{{ order.travelDurationText }}</span>
                  <span v-if="order.travelDistanceText">{{ order.travelDistanceText }}</span>
                </div>
              </div>
            </article>
          </div>

          <button
            class="btn btn--ghost btn--compact closed-toggle"
            type="button"
            :aria-expanded="showClosed"
            @click="showClosed = !showClosed"
          >
            {{
              showClosed
                ? t("routes.hideDelivered")
                : t("routes.deliveredToggle", { count: closedOrders.length })
            }}
          </button>
        </template>

        <div v-if="pendingOrders.length" class="stop-list">
          <article
            v-for="order in pendingOrders"
            :key="order.id"
            class="stop-card stop-card--link"
            role="button"
            tabindex="0"
            @click="openOrder(order)"
            @keydown.enter.prevent="openOrder(order)"
          >
            <div class="stop-card__index">
              {{ order.position }}
            </div>
            <div class="stop-card__body">
              <div class="stop-card__heading">
                <div class="stop-card__name">{{ order.clientName }}</div>
              </div>
              <a
                v-if="order.phone"
                class="stop-card__phone"
                :href="`tel:${order.phone}`"
                @click="onCall($event, order.phone)"
              >
                {{ order.phone }}
              </a>
              <div class="stop-card__address">{{ displayAddress(order) }}</div>
              <div
                v-if="order.travelDurationText || order.travelDistanceText"
                class="stop-card__travel"
              >
                <span v-if="order.travelDurationText">{{ order.travelDurationText }}</span>
                <span v-if="order.travelDistanceText">{{ order.travelDistanceText }}</span>
              </div>
            </div>
          </article>
        </div>
      </template>
    </template>

    <input
      ref="photoInput"
      class="sr-only"
      type="file"
      accept="image/*"
      capture="environment"
      @change="onPhotoSelected"
    />

    <ConfirmDeliverySheet
      v-if="confirmOrder && !showSignature"
      @select="onProofChoice"
      @cancel="closeConfirm"
    />

    <SignaturePadModal
      v-if="confirmOrder && showSignature"
      @confirm="onSignatureConfirm"
      @cancel="closeConfirm"
    />

    <RefuseDeliverySheet
      v-if="refuseOrder"
      :submitting="submitting"
      @confirm="submitRefuse"
      @cancel="closeRefuse"
    />

    <div v-if="submitting || starting" class="submit-overlay" aria-live="polite">
      {{ starting ? t("routes.starting") : t("delivery.submitting") }}
    </div>
  </div>
</template>
