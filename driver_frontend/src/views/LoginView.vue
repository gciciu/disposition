<script setup lang="ts">
import { reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ApiError } from "../api";
import { useAuth } from "../auth";
import { translateApiError } from "../i18n";

const { t } = useI18n();
const { login } = useAuth();

const form = reactive({
  email: "",
  password: "",
  remember: true,
});

const error = ref("");
const isLoading = ref(false);

async function submit() {
  error.value = "";
  isLoading.value = true;

  try {
    await login(form.email.trim(), form.password, form.remember);
  } catch (err) {
    if (err instanceof ApiError) {
      error.value = translateApiError(err.message, t);
    } else if (err instanceof Error) {
      error.value =
        err.message === "driversOnly" ? t("login.driversOnly") : translateApiError(err.message, t);
    } else {
      error.value = t("login.failed");
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
      <h1 class="panel__title">{{ t("login.title") }}</h1>
    </div>

    <div class="card">
      <label class="field">
        <span class="field__label">{{ t("login.email") }}</span>
        <input
          v-model="form.email"
          class="field__input"
          type="email"
          name="email"
          autocomplete="username"
          required
          placeholder="driver@example.com"
        />
      </label>

      <label class="field">
        <span class="field__label">{{ t("login.password") }}</span>
        <input
          v-model="form.password"
          class="field__input"
          type="password"
          name="password"
          autocomplete="current-password"
          required
          placeholder="••••••••"
        />
      </label>

      <label class="check">
        <input v-model="form.remember" type="checkbox" />
        {{ t("login.remember") }}
      </label>

      <p v-if="error" class="alert">{{ error }}</p>

      <button class="btn btn--primary" type="submit" :disabled="isLoading">
        {{ isLoading ? t("login.submitting") : t("login.submit") }}
      </button>
    </div>
  </form>
</template>
