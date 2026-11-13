<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useAuth } from "../auth";
import { displayApiError } from "../i18n";

const { t } = useI18n();
const { changePassword, state } = useAuth();

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
    error.value = displayApiError(err, t, "password.failed");
  } finally {
    isLoading.value = false;
  }
}
</script>

<template>
  <form class="panel" @submit.prevent="submit">
    <div class="panel__header">
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
    </div>
  </form>
</template>
