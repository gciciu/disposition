<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";

const emit = defineEmits<{
  confirm: [blob: Blob];
  cancel: [];
}>();

const { t } = useI18n();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const empty = ref(true);
const submitting = ref(false);

let drawing = false;
let ctx: CanvasRenderingContext2D | null = null;

const resizeCanvas = () => {
  const canvas = canvasRef.value;
  if (!canvas) {
    return;
  }

  const rect = canvas.getBoundingClientRect();
  const ratio = window.devicePixelRatio || 1;
  canvas.width = Math.max(1, Math.floor(rect.width * ratio));
  canvas.height = Math.max(1, Math.floor(rect.height * ratio));
  ctx = canvas.getContext("2d");
  if (!ctx) {
    return;
  }
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.lineWidth = 2.5;
  ctx.lineCap = "round";
  ctx.lineJoin = "round";
  ctx.strokeStyle = "#0b1220";
  clearPad(false);
};

const pointFromEvent = (event: PointerEvent) => {
  const canvas = canvasRef.value;
  if (!canvas) {
    return null;
  }
  const rect = canvas.getBoundingClientRect();
  return {
    x: event.clientX - rect.left,
    y: event.clientY - rect.top,
  };
};

const onPointerDown = (event: PointerEvent) => {
  const canvas = canvasRef.value;
  if (!canvas || !ctx) {
    return;
  }
  canvas.setPointerCapture(event.pointerId);
  drawing = true;
  const point = pointFromEvent(event);
  if (!point) {
    return;
  }
  ctx.beginPath();
  ctx.moveTo(point.x, point.y);
};

const onPointerMove = (event: PointerEvent) => {
  if (!drawing || !ctx) {
    return;
  }
  const point = pointFromEvent(event);
  if (!point) {
    return;
  }
  ctx.lineTo(point.x, point.y);
  ctx.stroke();
  empty.value = false;
};

const onPointerUp = (event: PointerEvent) => {
  const canvas = canvasRef.value;
  if (canvas?.hasPointerCapture(event.pointerId)) {
    canvas.releasePointerCapture(event.pointerId);
  }
  drawing = false;
};

const clearPad = (markEmpty = true) => {
  const canvas = canvasRef.value;
  if (!canvas || !ctx) {
    return;
  }
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  if (markEmpty) {
    empty.value = true;
  }
};

const confirm = async () => {
  const canvas = canvasRef.value;
  if (!canvas || empty.value || submitting.value) {
    return;
  }
  submitting.value = true;
  try {
    const blob = await new Promise<Blob | null>((resolve) => {
      canvas.toBlob((result) => resolve(result), "image/png");
    });
    if (!blob) {
      return;
    }
    emit("confirm", blob);
  } finally {
    submitting.value = false;
  }
};

onMounted(async () => {
  await nextTick();
  resizeCanvas();
  window.addEventListener("resize", resizeCanvas);
});

onUnmounted(() => {
  window.removeEventListener("resize", resizeCanvas);
});
</script>

<template>
  <div class="signature-modal" role="dialog" aria-modal="true">
    <div class="signature-modal__header">
      <h2 class="signature-modal__title">{{ t("delivery.signatureTitle") }}</h2>
      <p class="signature-modal__hint">{{ t("delivery.signatureHint") }}</p>
    </div>
    <canvas
      ref="canvasRef"
      class="signature-modal__canvas"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
    />
    <div class="signature-modal__actions">
      <button class="btn btn--ghost" type="button" @click="clearPad()">
        {{ t("delivery.clear") }}
      </button>
      <button class="btn btn--ghost" type="button" @click="emit('cancel')">
        {{ t("delivery.cancel") }}
      </button>
      <button
        class="btn btn--primary"
        type="button"
        :disabled="empty || submitting"
        @click="confirm"
      >
        {{ submitting ? t("delivery.submitting") : t("delivery.confirmSignature") }}
      </button>
    </div>
  </div>
</template>
