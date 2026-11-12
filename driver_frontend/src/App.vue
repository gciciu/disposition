<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import {
  checkForDriverAppUpdate,
  type DriverAppVersionInfo,
} from "./appUpdate";
import { useAuth } from "./auth";
import AppUpdateSheet from "./components/AppUpdateSheet.vue";
import LanguageSwitcher from "./components/LanguageSwitcher.vue";
import ChangePasswordView from "./views/ChangePasswordView.vue";
import LoginView from "./views/LoginView.vue";
import RoutesHomeView from "./views/RoutesHomeView.vue";

const { t } = useI18n();
const { bootstrap, isAuthenticated, mustChangePassword } = useAuth();
const ready = ref(false);
const updateRemote = ref<DriverAppVersionInfo | null>(null);
const updateLocalVersion = ref("");

onMounted(async () => {
  try {
    const update = await checkForDriverAppUpdate();
    if (update.status === "available") {
      updateRemote.value = update.remote;
      updateLocalVersion.value = update.localVersion;
      if (update.remote.force) {
        return;
      }
    }
  } catch {
    // Offline / server down — continue into the app.
  }

  await bootstrap();
  ready.value = true;
});

async function onSkipUpdate(): Promise<void> {
  if (updateRemote.value?.force) return;
  updateRemote.value = null;
  if (!ready.value) {
    await bootstrap();
    ready.value = true;
  }
}

const screen = computed(() => {
  if (!isAuthenticated.value) {
    return "login";
  }
  if (mustChangePassword.value) {
    return "change-password";
  }
  return "routes";
});
</script>

<template>
  <main class="app-shell" :class="{ 'app-shell--wide': screen === 'routes' }">
    <div class="app-shell__lang">
      <LanguageSwitcher />
    </div>

    <div v-if="!ready" class="loading-copy">{{ t("common.loading") }}</div>
    <LoginView v-else-if="screen === 'login'" />
    <ChangePasswordView v-else-if="screen === 'change-password'" />
    <RoutesHomeView v-else />

    <AppUpdateSheet
      v-if="updateRemote"
      :remote="updateRemote"
      :local-version="updateLocalVersion"
      @skip="onSkipUpdate"
    />
  </main>
</template>
