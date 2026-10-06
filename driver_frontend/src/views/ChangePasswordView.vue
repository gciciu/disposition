<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ApiError } from "../api";
import { useAuth } from "../auth";
import { translateApiError } from "../i18n";

const { t } = useI18n();
const { changePassword, logout, state } = useAuth();

const form = reactive({
  password: "",
  passwordConfirmation: "",
});

const error = ref("");
const isLoading = ref(false);

const greeting = computed(() =>
  t("password.greeting", {
    name: state.user?.name ? t("password.greetingName", { name: state.user.name }) : "",
  }),
);

async function submit() {
  error.value = "";

  if (form.password.length < 8) {
    error.value = t("password.tooShort");
    return;
  }

  if (form.password !== form.passwordConfirmation) {
    error.value = t("password.mismatch");
    return;
  }

  isLoading.value = true;

  try {
    await changePassword(form.password, form.passwordConfirmation);
  } catch (err) {
    if (err instanceof ApiError) {
      error.value = translateApiError(err.message, t);
    } else if (err instanceof Error) {
      error.value = translateApiError(err.message, t);
    } else {
      error.value = t("password.failed");
    }
  } finally {
    isLoading.value = false;
  }
}
</script>

<template>
  <form class="panel" @submit.prevent="submit">
    <div class="panel__header">
      <p class="brand-mark">{{ t("common.brand") }}</p>
      <h1 class="panel__title">{{ t("password.title") }}</h1>
      <p class="panel__subtitle">{{ greeting }}</p>
    </div>

    <div class="card">
      <label class="field">
        <span class="field__label">{{ t("password.newPassword") }}</span>
        <input
          v-model="form.password"
          class="field__input"
          type="password"
          name="password"
          autocomplete="new-password"
          required
          minlength="8"
          :placeholder="t('password.placeholderMin')"
        />
      </label>

      <label class="field">
        <span class="field__label">{{ t("password.confirmPassword") }}</span>
        <input
          v-model="form.passwordConfirmation"
          class="field__input"
          type="password"
          name="password_confirmation"
          autocomplete="new-password"
          required
          minlength="8"
          :placeholder="t('password.placeholderConfirm')"
        />
      </label>

      <p v-if="error" class="alert">{{ error }}</p>

      <button class="btn btn--primary" type="submit" :disabled="isLoading">
        {{ isLoading ? t("password.submitting") : t("password.submit") }}
      </button>

      <button class="btn btn--ghost" type="button" :disabled="isLoading" @click="logout">
        {{ t("common.logout") }}
      </button>
    </div>
  </form>
</template>
