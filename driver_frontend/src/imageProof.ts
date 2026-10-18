/** Compress a camera/gallery image to JPEG for reliable mobile uploads. */
export async function toProofJpeg(
  source: Blob,
  options: { maxEdge?: number; quality?: number } = {},
): Promise<{ blob: Blob; filename: string }> {
  const maxEdge = options.maxEdge ?? 1600;
  const quality = options.quality ?? 0.82;

  const bitmap = await createImageBitmap(source);
  try {
    const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
    const width = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;

    const ctx = canvas.getContext("2d");
    if (!ctx) {
      throw new Error("Canvas unavailable");
    }
    ctx.drawImage(bitmap, 0, 0, width, height);

    const blob = await new Promise<Blob>((resolve, reject) => {
      canvas.toBlob(
        (result) => {
          if (result) {
            resolve(result);
          } else {
            reject(new Error("Could not encode JPEG"));
          }
        },
        "image/jpeg",
        quality,
      );
    });

    return { blob, filename: "delivery.jpg" };
  } finally {
    bitmap.close();
  }
}
