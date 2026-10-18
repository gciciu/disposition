<script setup>
import { computed } from 'vue'

const props = defineProps({
  username: {
    type: String,
    required: true,
  },
  avatar: {
    type: String,
    default: null,
  },
})

const hasAvatar = computed(() => !!props.avatar)

const initials = computed(() => {
  const parts = props.username
    .trim()
    .split(/\s+/)
    .filter(Boolean)

  if (parts.length === 0) {
    return '?'
  }

  if (parts.length === 1) {
    return parts[0].slice(0, 2).toUpperCase()
  }

  return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`.toUpperCase()
})

const username = computed(() => props.username)
</script>

<template>
  <div class="relative">
    <img
      v-if="hasAvatar"
      :src="avatar"
      :alt="username"
      class="block h-auto w-full max-w-full rounded-full bg-gray-100 dark:bg-slate-800"
    />
    <div
      v-else
      class="flex aspect-square w-full items-center justify-center rounded-full bg-gray-200 text-[0.55em] font-semibold tracking-wide text-gray-600 uppercase dark:bg-slate-700 dark:text-slate-200"
      :aria-label="username"
      role="img"
    >
      {{ initials }}
    </div>
    <slot />
  </div>
</template>
