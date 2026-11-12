<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import {
  downloadAndInstallDriverApk,
  type DriverAppVersionInfo,
} from "../appUpdate";

const props = defineProps<{
  remote: DriverAppVersionInfo;
  localVersion: string;
}>();

const emit = defineEmits<{
  skip: [];
}>();

const { t } = useI18n();
const busy = ref(false);
const progress = ref(0);
const error = ref("");

const canSkip = computed(() => !props.remote.force && !busy.value);

async function onUpdate(): Promise<void> {
  if (!props.remote.apkUrl || busy.value) return;

  busy.value = true;
  error.value = "";
  progress.value = 0;

  try {
    await downloadAndInstallDriverApk(props.remote.apkUrl, (percent) => {
      progress.value = percent;
    });
  } catch (e) {
    error.value =
      e instanceof Error && e.message === "Install permission denied"
        ? t("update.permissionDenied")
        : t("update.failed");
    busy.value = false;
  }
}
</script>

<template>
  <div
    class="sheet-backdrop"
    @click.self="canSkip && emit('skip')"
  >
    <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="app-update-title">
      <p id="app-update-title" class="sheet__title">{{ t("update.title") }}</p>
      <p class="sheet__subtitle">
        {{
          t("update.subtitle", {
            local: localVersion,
            remote: remote.versionName,
          })
        }}
      </p>
      <p v-if="remote.changelog" class="sheet__changelog">{{ remote.changelog }}</p>
      <p v-if="remote.force" class="sheet__force">{{ t("update.required") }}</p>

      <div v-if="busy" class="update-progress" aria-live="polite">
        <div class="update-progress__track">
          <div class="update-progress__bar" :style="{ width: `${progress}%` }" />
        </div>
        <p class="update-progress__label">{{ t("update.downloading", { percent: progress }) }}</p>
      </div>

      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <button
        class="btn btn--primary sheet__action"
        type="button"
        :disabled="busy || !remote.apkUrl"
        @click="onUpdate"
      >
        {{ busy ? t("update.updating") : t("update.confirm") }}
      </button>
      <button
        v-if="!remote.force"
        class="btn btn--ghost sheet__action"
        type="button"
        :disabled="busy"
        @click="emit('skip')"
      >
        {{ t("update.later") }}
      </button>
    </div>
  </div>
</template>
