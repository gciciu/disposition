<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { useAuth } from "../auth";

const { t } = useI18n();
const { logout, state } = useAuth();
</script>

<template>
  <div class="empty-state">
    <div class="panel__header">
      <p class="brand-mark">{{ t("common.brand") }}</p>
      <h1 class="panel__title">{{ t("routes.title") }}</h1>
      <p class="panel__subtitle">{{ t("routes.empty") }}</p>
      <p v-if="state.user?.name" class="empty-state__meta">
        {{ state.user.name }} · {{ state.user.email }}
      </p>
    </div>

    <button class="btn btn--ghost" type="button" style="max-width: 12rem; margin: 0 auto" @click="logout">
      {{ t("common.logout") }}
    </button>
  </div>
</template>
