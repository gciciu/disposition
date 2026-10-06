<script setup>
import { computed } from 'vue'
import { useDarkModeStore } from '@/stores/darkMode.js'
import {
  gradientBgPurplePink,
  gradientBgDark,
  gradientBgPinkRed,
  gradientBgTransport,
} from '@/colors.js'

const props = defineProps({
  bg: {
    type: String,
    required: true,
    validator: (value) => ['purplePink', 'pinkRed', 'transport'].includes(value),
  },
})

const colorClass = computed(() => {
  if (props.bg === 'transport') {
    return gradientBgTransport
  }

  if (useDarkModeStore().isEnabled) {
    return gradientBgDark
  }

  switch (props.bg) {
    case 'purplePink':
      return gradientBgPurplePink
    case 'pinkRed':
      return gradientBgPinkRed
  }

  return ''
})
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden" :class="colorClass">
    <div
      v-if="bg === 'transport'"
      class="pointer-events-none absolute inset-0"
      aria-hidden="true"
    >
      <div class="bg-transport-glow absolute inset-0" />
      <div class="bg-transport-roads absolute inset-0" />
      <div class="bg-transport-horizon absolute inset-x-0 bottom-0 h-2/5" />
    </div>
    <div class="relative z-10 w-full flex justify-center">
      <slot card-class="w-11/12 md:w-7/12 lg:w-6/12 xl:w-4/12 shadow-2xl" />
    </div>
  </div>
</template>
