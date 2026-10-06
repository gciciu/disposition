<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
  ApiError,
  fetchMyRouteRequest,
  fetchMyRoutesRequest,
  type RouteDetail,
  type RouteListItem,
} from "../api";
import { useAuth } from "../auth";

const { t, locale } = useI18n();
const { logout, state } = useAuth();

const loading = ref(true);
const loadingDetail = ref(false);
const error = ref("");
const routes = ref<RouteListItem[]>([]);
const selectedId = ref<number | null>(null);
const detail = ref<RouteDetail | null>(null);

const hasRoutes = computed(() => routes.value.length > 0);

const formatDate = (value: string) => {
  try {
    return new Date(value).toLocaleString(locale.value);
  } catch {
    return value;
  }
};

const displayAddress = (order: RouteDetail["orders"][number]) =>
  order.formattedAddress || order.address;

const loadRoutes = async () => {
  loading.value = true;
  error.value = "";
  selectedId.value = null;
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
  detail.value = null;
  error.value = "";
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
      <div class="driver-routes__actions">
        <button
          v-if="routes.length > 1"
          class="btn btn--ghost btn--compact"
          type="button"
          @click="backToList"
        >
          {{ t("routes.backToList") }}
        </button>
        <a
          v-if="detail?.mapsUrl"
          class="btn btn--primary btn--compact"
          :href="detail.mapsUrl"
          target="_blank"
          rel="noopener noreferrer"
        >
          {{ t("routes.openMaps") }}
        </a>
      </div>

      <div v-if="loadingDetail" class="loading-copy">{{ t("common.loading") }}</div>

      <template v-else-if="detail">
        <div class="route-summary">
          <span>{{ t("routes.ordersCount", { count: detail.orderCount }) }}</span>
          <span v-if="detail.totalDurationText">
            {{ detail.totalDurationText }}
            <template v-if="detail.totalDistanceText">
              · {{ detail.totalDistanceText }}
            </template>
          </span>
        </div>

        <div class="stop-list">
          <article v-for="order in detail.orders" :key="order.id" class="stop-card">
            <div class="stop-card__index">{{ order.position }}</div>
            <div class="stop-card__body">
              <div class="stop-card__name">{{ order.clientName }}</div>
              <a
                v-if="order.phone"
                class="stop-card__phone"
                :href="`tel:${order.phone}`"
              >
                {{ order.phone }}
              </a>
              <div class="stop-card__address">{{ displayAddress(order) }}</div>
              <ul v-if="order.products?.length" class="stop-card__products">
                <li v-for="(product, idx) in order.products" :key="idx">
                  {{ product }}
                </li>
              </ul>
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
  </div>
</template>
