<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
  adjustArrivalRequest,
  completeOrderRequest,
  continueRouteRequest,
  fetchMyRouteRequest,
  fetchMyRoutesRequest,
  finishRouteRequest,
  refuseOrderRequest,
  startRouteRequest,
  type DeliveryMethod,
  type ProofType,
  type RouteDetail,
  type RouteListItem,
  type RouteOrder,
} from "../api";
import { useAuth } from "../auth";
import AdjustArrivalSheet from "../components/AdjustArrivalSheet.vue";
import ConfirmActionSheet from "../components/ConfirmActionSheet.vue";
import ConfirmDeliverySheet, {
  type DeliveryMethodChoice,
} from "../components/ConfirmDeliverySheet.vue";
import OrderStopView from "../components/OrderStopView.vue";
import RefuseDeliverySheet from "../components/RefuseDeliverySheet.vue";
import SignaturePadModal from "../components/SignaturePadModal.vue";
import { useRealtime } from "../composables/useRealtime";
import { GeolocationError, getCurrentPosition } from "../geolocation";
import { toProofJpeg } from "../imageProof";
import { displayApiError } from "../i18n";
import { buildStopMapsUrl, openGoogleMapsUrl } from "../openExternalUrl";
import { withoutCountry } from "../utils/address";

const { t, locale } = useI18n();
const { state } = useAuth();

const loading = ref(true);
const loadingDetail = ref(false);
const submitting = ref(false);
const starting = ref(false);
const finishing = ref(false);
const continuing = ref(false);
const error = ref("");
const routes = ref<RouteListItem[]>([]);
const selectedId = ref<number | null>(null);
const detail = ref<RouteDetail | null>(null);
const selectedOrderId = ref<number | null>(null);
const showClosed = ref(false);

const confirmOrder = ref<RouteOrder | null>(null);
const refuseOrder = ref<RouteOrder | null>(null);
const pendingDeliveredIndexes = ref<number[]>([]);
const pendingCourierComment = ref("");
const pendingDeliveryMethod = ref<Exclude<DeliveryMethod, "not_delivered"> | null>(
  null,
);
const pendingNeighborName = ref("");
const showSignature = ref(false);
const showStartConfirm = ref(false);
const showFinishConfirm = ref(false);
const arrivalEditOrder = ref<RouteOrder | null>(null);
const continueRouteTarget = ref<RouteListItem | null>(null);
const photoInput = ref<HTMLInputElement | null>(null);

const canPartialDelivery = computed(() => {
  const order = confirmOrder.value;
  if (!order) {
    return false;
  }
  const total = order.products.length;
  if (total <= 0) {
    return false;
  }
  const selected = pendingDeliveredIndexes.value.length;
  return selected > 0 && selected < total;
});

const hasRoutes = computed(() => routes.value.length > 0);

const selectedOrder = computed(() => {
  if (!detail.value || selectedOrderId.value === null) {
    return null;
  }
  return detail.value.orders.find((item) => item.id === selectedOrderId.value) ?? null;
});

const localTodayKey = () => {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};

const isTodayScheduled = (scheduledDate: string | null | undefined) =>
  Boolean(scheduledDate) && scheduledDate === localTodayKey();

const canContinueRoute = (route: RouteListItem) =>
  Boolean(route.completedAt) && isTodayScheduled(route.scheduledDate);

const formatScheduledDate = (value: string) => {
  const [year, month, day] = value.split("-").map(Number);
  if (!year || !month || !day) {
    return value;
  }
  try {
    return new Date(year, month - 1, day).toLocaleDateString(locale.value, {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
    });
  } catch {
    return value;
  }
};

const formatClockTime = (value: string) => {
  try {
    return new Date(value).toLocaleTimeString(locale.value, {
      hour: "2-digit",
      minute: "2-digit",
      hour12: false,
    });
  } catch {
    return value;
  }
};

const displayAddress = (order: RouteOrder) =>
  withoutCountry(order.formattedAddress || order.address);

const isDelivered = (order: RouteOrder) => order.status === "delivered";
const isRefused = (order: RouteOrder) => order.status === "refused";
const isClosed = (order: RouteOrder) => isDelivered(order) || isRefused(order);

const deliveryMethodLabel = (order: RouteOrder) => {
  switch (order.deliveryMethod) {
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
      return null;
  }
};

const closedStatusLabel = (order: RouteOrder) => {
  const methodLabel = deliveryMethodLabel(order);
  if (methodLabel) {
    return methodLabel;
  }
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
const nextPendingOrder = computed(() => pendingOrders.value[0] ?? null);

const isRouteStarted = computed(() => Boolean(detail.value?.startedAt));
const isRouteCompleted = computed(() => Boolean(detail.value?.completedAt));
const canOpenMaps = computed(
  () => Boolean(detail.value?.mapsUrl) && !isRouteCompleted.value,
);
const isSelectedRouteToday = computed(() =>
  isTodayScheduled(detail.value?.scheduledDate),
);
const canStartRoute = computed(
  () =>
    Boolean(detail.value) &&
    isSelectedRouteToday.value &&
    !isRouteStarted.value &&
    !isRouteCompleted.value,
);
const deliveryActionsEnabled = computed(
  () =>
    isSelectedRouteToday.value &&
    isRouteStarted.value &&
    !isRouteCompleted.value,
);
const allOrdersFinal = computed(() => {
  if (!detail.value) {
    return false;
  }
  if (typeof detail.value.allOrdersFinal === "boolean") {
    return detail.value.allOrdersFinal;
  }
  return (
    detail.value.orders.length > 0 &&
    detail.value.orders.every((order) => isClosed(order))
  );
});
const canFinishRoute = computed(
  () =>
    isSelectedRouteToday.value &&
    isRouteStarted.value &&
    !isRouteCompleted.value,
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
          scheduledStartTime: route.scheduledStartTime,
          startedAt: route.startedAt,
          completedAt: route.completedAt,
          hasCompletionPdf: route.hasCompletionPdf,
          allOrdersFinal: route.allOrdersFinal,
          delayMinutes: route.delayMinutes,
        }
      : item,
  );
};

type RealtimePayload = {
  route?: RouteDetail;
  order?: RouteOrder;
};

const sameId = (left: unknown, right: unknown) =>
  left != null && right != null && Number(left) === Number(right);

const reloadOpenRoute = async ({ silent = false } = {}) => {
  const id = selectedId.value;
  if (id == null) {
    return;
  }

  if (!silent) {
    loadingDetail.value = true;
  }
  error.value = "";

  try {
    const data = await fetchMyRouteRequest(id);
    if (selectedId.value !== id) {
      return;
    }
    detail.value = data.route;
    syncRouteListItem(data.route);
    if (!silent) {
      showClosed.value = Boolean(data.route.completedAt);
    }
  } catch (e) {
    if (!silent) {
      error.value = displayApiError(e, t, "routes.loadDetailFailed");
      selectedId.value = null;
      detail.value = null;
    }
  } finally {
    if (!silent) {
      loadingDetail.value = false;
    }
  }
};

const applyRealtimePayload = (event: string, raw: unknown) => {
  if (!detail.value || !raw || typeof raw !== "object") {
    return;
  }
  const data = raw as RealtimePayload;
  if (!data.route) {
    return;
  }
  if (data.route.id != null && !sameId(data.route.id, detail.value.id)) {
    return;
  }

  if (
    event === "route.started" ||
    event === "route.completed" ||
    event === "route.continued" ||
    event === "route.delayed"
  ) {
    detail.value = data.route;
    syncRouteListItem(data.route);
    return;
  }

  if (
    event === "order.completed" ||
    event === "order.refused" ||
    event === "order.updated"
  ) {
    const next: RouteDetail = { ...data.route };
    if (data.order?.id != null && Array.isArray(next.orders)) {
      const orderIndex = next.orders.findIndex((item) => sameId(item.id, data.order?.id));
      if (orderIndex !== -1) {
        next.orders = [...next.orders];
        next.orders[orderIndex] = {
          ...next.orders[orderIndex],
          ...data.order,
        };
      }
    }
    detail.value = next;
    syncRouteListItem(next);
  }
};

const { start: startRealtime, stop: stopRealtime } = useRealtime({
  routeId: () => selectedId.value,
  onEvent: applyRealtimePayload,
  onResync: () => {
    void reloadOpenRoute({ silent: true });
  },
});

const loadRoutes = async () => {
  stopRealtime();
  loading.value = true;
  error.value = "";
  selectedId.value = null;
  selectedOrderId.value = null;
  detail.value = null;

  try {
    const data = await fetchMyRoutesRequest();
    routes.value = data.routes ?? [];
  } catch (e) {
    error.value = displayApiError(e, t, "routes.loadFailed");
  } finally {
    loading.value = false;
  }
};

const openRoute = async (id: number) => {
  stopRealtime();
  selectedId.value = id;
  selectedOrderId.value = null;
  showClosed.value = false;
  loadingDetail.value = true;
  error.value = "";
  detail.value = null;

  try {
    const data = await fetchMyRouteRequest(id);
    detail.value = data.route;
    showClosed.value = Boolean(data.route.completedAt);
    startRealtime();
  } catch (e) {
    error.value = displayApiError(e, t, "routes.loadDetailFailed");
    selectedId.value = null;
  } finally {
    loadingDetail.value = false;
  }
};

const backToList = () => {
  stopRealtime();
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

const backToStops = () => {
  if (submitting.value) {
    return;
  }
  selectedOrderId.value = null;
  closeConfirm();
  closeRefuse();
  closeArrivalEdit();
};

const openStartConfirm = () => {
  if (!canStartRoute.value || starting.value || submitting.value || finishing.value) {
    return;
  }
  error.value = "";
  showFinishConfirm.value = false;
  showStartConfirm.value = true;
};

const closeStartConfirm = () => {
  if (starting.value) {
    return;
  }
  showStartConfirm.value = false;
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
    showStartConfirm.value = false;
  } catch (e) {
    if (e instanceof GeolocationError) {
      error.value =
        e.code === "denied"
          ? t("routes.locationDenied")
          : t("routes.locationFailed");
    } else {
      error.value = displayApiError(e, t, "routes.startFailed");
    }
    showStartConfirm.value = false;
  } finally {
    starting.value = false;
  }
};

const openFinishConfirm = () => {
  if (
    !canFinishRoute.value ||
    finishing.value ||
    starting.value ||
    submitting.value
  ) {
    return;
  }
  error.value = "";
  showStartConfirm.value = false;
  showFinishConfirm.value = true;
};

const closeFinishConfirm = () => {
  if (finishing.value) {
    return;
  }
  showFinishConfirm.value = false;
};

const openContinueConfirm = (route: RouteListItem, event?: Event) => {
  event?.preventDefault();
  event?.stopPropagation();
  if (!canContinueRoute(route) || continuing.value || starting.value || finishing.value) {
    return;
  }
  error.value = "";
  showStartConfirm.value = false;
  showFinishConfirm.value = false;
  continueRouteTarget.value = route;
};

const closeContinueConfirm = () => {
  if (continuing.value) {
    return;
  }
  continueRouteTarget.value = null;
};

const continueRoute = async () => {
  const route = continueRouteTarget.value;
  if (
    !route ||
    !canContinueRoute(route) ||
    continuing.value ||
    starting.value ||
    finishing.value ||
    submitting.value
  ) {
    return;
  }

  continuing.value = true;
  error.value = "";

  try {
    const data = await continueRouteRequest(route.id);
    syncRouteListItem(data.route);
    continueRouteTarget.value = null;
    await openRoute(data.route.id);
  } catch (e) {
    error.value = displayApiError(e, t, "routes.continueFailed");
    continueRouteTarget.value = null;
  } finally {
    continuing.value = false;
  }
};

const finishRoute = async () => {
  const routeId = selectedId.value;
  if (
    routeId === null ||
    !canFinishRoute.value ||
    finishing.value ||
    starting.value ||
    submitting.value
  ) {
    return;
  }

  finishing.value = true;
  error.value = "";

  try {
    const data = await finishRouteRequest(routeId);
    detail.value = data.route;
    syncRouteListItem(data.route);
    selectedOrderId.value = null;
    showClosed.value = true;
    showFinishConfirm.value = false;
  } catch (e) {
    error.value = displayApiError(e, t, "routes.finishFailed");
    showFinishConfirm.value = false;
  } finally {
    finishing.value = false;
  }
};

const openConfirm = (
  order: RouteOrder,
  deliveredProductIndexes: number[],
  courierComment: string,
) => {
  if (isClosed(order) || submitting.value || !deliveryActionsEnabled.value) {
    return;
  }
  error.value = "";
  refuseOrder.value = null;
  pendingDeliveredIndexes.value = deliveredProductIndexes;
  pendingCourierComment.value = courierComment;
  pendingDeliveryMethod.value = null;
  pendingNeighborName.value = "";
  showSignature.value = false;
  confirmOrder.value = order;
};

const onOrderConfirm = (payload: {
  deliveredProductIndexes: number[];
  courierComment: string;
}) => {
  if (!selectedOrder.value) {
    return;
  }
  openConfirm(
    selectedOrder.value,
    payload.deliveredProductIndexes,
    payload.courierComment,
  );
};

const openRefuse = (courierComment: string) => {
  const order = selectedOrder.value;
  if (!order || isClosed(order) || submitting.value || !deliveryActionsEnabled.value) {
    return;
  }
  error.value = "";
  confirmOrder.value = null;
  showSignature.value = false;
  pendingCourierComment.value = courierComment;
  refuseOrder.value = order;
};

const resetPendingDelivery = () => {
  confirmOrder.value = null;
  showSignature.value = false;
  pendingDeliveredIndexes.value = [];
  pendingCourierComment.value = "";
  pendingDeliveryMethod.value = null;
  pendingNeighborName.value = "";
};

const closeConfirm = () => {
  if (submitting.value) {
    return;
  }
  resetPendingDelivery();
};

const closeRefuse = () => {
  if (submitting.value) {
    return;
  }
  refuseOrder.value = null;
  pendingCourierComment.value = "";
};

const canEditArrival = (order: RouteOrder) =>
  deliveryActionsEnabled.value &&
  nextPendingOrder.value?.id === order.id &&
  Boolean(order.arrivalTime);

const openArrivalEdit = (order: RouteOrder, event?: Event) => {
  event?.stopPropagation();
  event?.preventDefault();
  if (!canEditArrival(order) || submitting.value || starting.value || finishing.value) {
    return;
  }
  error.value = "";
  confirmOrder.value = null;
  refuseOrder.value = null;
  showSignature.value = false;
  pendingCourierComment.value = "";
  arrivalEditOrder.value = order;
};

const closeArrivalEdit = () => {
  if (submitting.value) {
    return;
  }
  arrivalEditOrder.value = null;
};

const submitArrivalEdit = async (arrivalTime: string) => {
  const routeId = selectedId.value;
  const order = arrivalEditOrder.value;
  if (
    routeId === null ||
    !order ||
    !canEditArrival(order) ||
    submitting.value ||
    starting.value ||
    finishing.value
  ) {
    return;
  }

  submitting.value = true;
  error.value = "";

  try {
    const data = await adjustArrivalRequest(routeId, order.id, arrivalTime);
    detail.value = data.route;
    syncRouteListItem(data.route);
    arrivalEditOrder.value = null;
  } catch (e) {
    error.value = displayApiError(e, t, "delivery.adjustArrivalFailed");
    arrivalEditOrder.value = null;
  } finally {
    submitting.value = false;
  }
};

const openMaps = async () => {
  const url = detail.value?.mapsUrl;
  if (!url || !canOpenMaps.value) {
    return;
  }
  try {
    await openGoogleMapsUrl(url);
  } catch {
    error.value = t("routes.loadDetailFailed");
  }
};

const stopMapsUrl = (order: RouteOrder) => buildStopMapsUrl(order);

const openStopMaps = async (order: RouteOrder, event?: Event) => {
  event?.stopPropagation();
  event?.preventDefault();
  const url = stopMapsUrl(order);
  if (!url) {
    return;
  }
  try {
    await openGoogleMapsUrl(url);
  } catch {
    error.value = t("routes.loadDetailFailed");
  }
};

const beginProofCapture = (choice: Extract<DeliveryMethodChoice, { proofType: ProofType }>) => {
  pendingDeliveryMethod.value = choice.method;
  pendingNeighborName.value = choice.neighborName?.trim() ?? "";
  if (choice.proofType === "photo") {
    photoInput.value?.click();
    return;
  }
  showSignature.value = true;
};

const onMethodChoice = (choice: DeliveryMethodChoice) => {
  if (!confirmOrder.value) {
    return;
  }

  if (choice.method === "not_delivered") {
    const order = confirmOrder.value;
    confirmOrder.value = null;
    showSignature.value = false;
    pendingDeliveryMethod.value = null;
    pendingNeighborName.value = "";
    refuseOrder.value = order;
    return;
  }

  beginProofCapture(choice);
};

const applyRouteMutation = (_order: RouteOrder, route: RouteDetail) => {
  detail.value = route;
  syncRouteListItem(route);
  if (route.completedAt) {
    selectedOrderId.value = null;
    showClosed.value = true;
  }
};

const submitProof = async (
  proofType: ProofType,
  file: Blob,
  filename: string,
) => {
  const order = confirmOrder.value;
  const routeId = selectedId.value;
  const deliveryMethod = pendingDeliveryMethod.value;
  if (!order || routeId === null || !deliveryMethod) {
    return;
  }

  submitting.value = true;
  error.value = "";

  try {
    const data = await completeOrderRequest(
      routeId,
      order.id,
      deliveryMethod,
      proofType,
      file,
      filename,
      pendingDeliveredIndexes.value,
      pendingCourierComment.value,
      pendingNeighborName.value || null,
    );
    applyRouteMutation(data.order, data.route);
    resetPendingDelivery();
    selectedOrderId.value = null;
  } catch (e) {
    error.value = displayApiError(e, t, "delivery.submitFailed");
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
    error.value = displayApiError(e, t, "delivery.submitFailed");
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
    const data = await refuseOrderRequest(
      routeId,
      order.id,
      pendingCourierComment.value,
    );
    applyRouteMutation(data.order, data.route);
    refuseOrder.value = null;
    pendingCourierComment.value = "";
    selectedOrderId.value = null;
  } catch (e) {
    error.value = displayApiError(e, t, "delivery.refuseFailed");
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
      <div class="driver-routes__heading">
        <h1 class="panel__title">
          {{ detail?.name || t("routes.title") }}
        </h1>
        <p v-if="state.user?.name" class="driver-routes__meta">
          {{ state.user.name }}
        </p>
      </div>
      <button
        v-if="canOpenMaps"
        class="driver-routes__maps-btn"
        type="button"
        :aria-label="t('routes.openMaps')"
        :title="t('routes.openMaps')"
        :disabled="submitting || starting || finishing"
        @click="openMaps"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none">
          <path
            d="M9 20l-5.447-2.724A1 1 0 0 1 3 16.382V5.618a1 1 0 0 1 1.447-.894L9 7"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
          <path
            d="M9 7v13l6-3V4L9 7z"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
          <path
            d="M15 4l4.553 2.276A1 1 0 0 1 21 7.118v10.764a1 1 0 0 1-1.447.894L15 16"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
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
        <div
          v-for="route in routes"
          :key="route.id"
          class="route-card"
          :class="{ 'route-card--today': isTodayScheduled(route.scheduledDate) }"
          role="button"
          tabindex="0"
          @click="openRoute(route.id)"
          @keydown.enter.prevent="openRoute(route.id)"
        >
          <div v-if="route.scheduledDate" class="route-card__date">
            <span>{{ formatScheduledDate(route.scheduledDate) }}</span>
            <span
              v-if="isTodayScheduled(route.scheduledDate)"
              class="route-card__today-badge"
            >
              {{ t("routes.today") }}
            </span>
          </div>
          <div class="route-card__title">{{ route.name }}</div>
          <div class="route-card__meta">
            <span>{{ t("routes.ordersCount", { count: route.orderCount }) }}</span>
            <span v-if="route.scheduledStartTime">
              {{ t("routes.scheduledStartTime", { time: route.scheduledStartTime }) }}
            </span>
            <span v-if="route.startedAt">
              {{ t("routes.startedAt", { time: formatClockTime(route.startedAt) }) }}
            </span>
            <span v-if="route.totalDurationText">
              {{ route.totalDurationText }}
              <template v-if="route.totalDistanceText">
                · {{ route.totalDistanceText }}
              </template>
            </span>
            <span v-if="route.completedAt" class="route-card__status">
              {{ t("routes.completed") }}
            </span>
          </div>
          <button
            v-if="canContinueRoute(route)"
            class="btn btn--primary btn--compact route-card__continue"
            type="button"
            :disabled="continuing || starting || finishing || submitting"
            @click="openContinueConfirm(route, $event)"
          >
            {{ continuing && continueRouteTarget?.id === route.id
              ? t("routes.continuing")
              : t("routes.continue") }}
          </button>
        </div>
      </div>
    </template>

    <template v-else>
      <div v-if="loadingDetail" class="loading-copy">{{ t("common.loading") }}</div>

      <OrderStopView
        v-else-if="selectedOrder"
        :order="selectedOrder"
        :submitting="submitting"
        :actions-enabled="deliveryActionsEnabled"
        :read-only="isRouteCompleted"
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
            :disabled="starting || submitting || finishing"
            @click="openStartConfirm"
          >
            {{ starting ? t("routes.starting") : t("routes.start") }}
          </button>
          <button
            v-if="canFinishRoute"
            class="btn btn--danger btn--compact driver-routes__finish"
            type="button"
            :disabled="finishing || starting || submitting"
            @click="openFinishConfirm"
          >
            {{ finishing ? t("routes.finishing") : t("routes.finish") }}
          </button>
        </div>

        <p v-if="isRouteCompleted" class="route-status route-status--done">
          {{ t("routes.completed") }}
        </p>
        <p v-else-if="!isSelectedRouteToday" class="route-status">
          {{ t("routes.todayOnlyHint") }}
        </p>
        <p v-else-if="!isRouteStarted" class="route-status">
          {{ t("routes.startHint") }}
        </p>
        <p v-else-if="allOrdersFinal" class="route-status route-status--done">
          {{ t("routes.finishHint") }}
        </p>

        <div class="route-summary">
          <span>{{ t("routes.ordersCount", { count: detail.orderCount }) }}</span>
          <span v-if="detail.scheduledStartTime">
            {{ t("routes.scheduledStartTime", { time: detail.scheduledStartTime }) }}
          </span>
          <span v-if="detail.startedAt">
            {{ t("routes.startedAt", { time: formatClockTime(detail.startedAt) }) }}
          </span>
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
                  <div class="stop-card__aside">
                    <button
                      v-if="stopMapsUrl(order)"
                      class="stop-card__map-btn"
                      type="button"
                      :aria-label="t('routes.openStopMaps')"
                      :title="t('routes.openStopMaps')"
                      @click="openStopMaps(order, $event)"
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
                      <span
                        v-if="order.isExchange"
                        class="stop-card__badge stop-card__badge--exchange"
                      >
                        {{ t("delivery.exchange") }}
                      </span>
                      <span
                        class="stop-card__badge"
                        :class="{ 'stop-card__badge--refused': isRefused(order) }"
                      >
                        {{ closedStatusLabel(order) }}
                      </span>
                    </div>
                  </div>
                </div>
                <div v-if="order.phone" class="stop-card__phone stop-card__phone--plain">
                  {{ order.phone }}
                </div>
                <div class="stop-card__address">{{ displayAddress(order) }}</div>
                <div
                  v-if="order.travelDurationText || order.travelDistanceText"
                  class="stop-card__travel"
                >
                  <span v-if="order.travelDurationText">{{ order.travelDurationText }}</span>
                  <span v-if="order.travelDistanceText">{{ order.travelDistanceText }}</span>
                </div>
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
            :class="{
              'stop-card--current':
                isRouteStarted && nextPendingOrder?.id === order.id,
            }"
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
                <div class="stop-card__aside">
                  <button
                    v-if="stopMapsUrl(order)"
                    class="stop-card__map-btn"
                    type="button"
                    :aria-label="t('routes.openStopMaps')"
                    :title="t('routes.openStopMaps')"
                    @click="openStopMaps(order, $event)"
                  >
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none">
                      <path
                        d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      />
                      <circle
                        cx="12"
                        cy="10"
                        r="2.5"
                        stroke="currentColor"
                        stroke-width="1.8"
                      />
                    </svg>
                  </button>
                  <span
                    v-if="order.isExchange"
                    class="stop-card__badge stop-card__badge--exchange"
                  >
                    {{ t("delivery.exchange") }}
                  </span>
                </div>
              </div>
              <div v-if="order.phone" class="stop-card__phone stop-card__phone--plain">
                {{ order.phone }}
              </div>
              <div class="stop-card__address">{{ displayAddress(order) }}</div>
              <div
                v-if="order.travelDurationText || order.travelDistanceText"
                class="stop-card__travel"
              >
                <span v-if="order.travelDurationText">{{ order.travelDurationText }}</span>
                <span v-if="order.travelDistanceText">{{ order.travelDistanceText }}</span>
              </div>
              <div class="stop-card__footer">
                <div class="stop-card__arrival-line">
                  <button
                    v-if="canEditArrival(order)"
                    class="stop-card__edit-btn"
                    type="button"
                    :aria-label="t('routes.editArrival')"
                    :title="t('routes.editArrival')"
                    :disabled="submitting || starting || finishing"
                    @click="openArrivalEdit(order, $event)"
                  >
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none">
                      <path
                        d="M12 20h9"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                      />
                      <path
                        d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      />
                    </svg>
                  </button>
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
      :can-partial="canPartialDelivery"
      @select="onMethodChoice"
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

    <AdjustArrivalSheet
      v-if="arrivalEditOrder?.arrivalTime"
      :current-arrival="arrivalEditOrder.arrivalTime"
      :submitting="submitting"
      @confirm="submitArrivalEdit"
      @cancel="closeArrivalEdit"
    />

    <ConfirmActionSheet
      v-if="showStartConfirm"
      :title="t('routes.startConfirmTitle')"
      :subtitle="t('routes.startConfirmSubtitle')"
      :confirm-label="t('routes.startConfirm')"
      :submitting="starting"
      :submitting-label="t('routes.starting')"
      @confirm="startRoute"
      @cancel="closeStartConfirm"
    />

    <ConfirmActionSheet
      v-if="showFinishConfirm"
      :title="t('routes.finishConfirmTitle')"
      :subtitle="
        allOrdersFinal
          ? t('routes.finishConfirmSubtitle')
          : t('routes.finishConfirmPending')
      "
      :confirm-label="t('routes.finishConfirm')"
      :submitting="finishing"
      :submitting-label="t('routes.finishing')"
      @confirm="finishRoute"
      @cancel="closeFinishConfirm"
    />

    <ConfirmActionSheet
      v-if="continueRouteTarget"
      :title="t('routes.continueConfirmTitle')"
      :subtitle="t('routes.continueConfirmSubtitle')"
      :confirm-label="t('routes.continueConfirm')"
      :submitting="continuing"
      :submitting-label="t('routes.continuing')"
      @confirm="continueRoute"
      @cancel="closeContinueConfirm"
    />

    <div
      v-if="submitting || starting || finishing || continuing"
      class="submit-overlay"
      aria-live="polite"
    >
      {{
        continuing
          ? t("routes.continuing")
          : finishing
            ? t("routes.finishing")
            : starting
              ? t("routes.starting")
              : t("delivery.submitting")
      }}
    </div>
  </div>
</template>
