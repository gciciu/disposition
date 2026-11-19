export async function openExternalUrl(url: string): Promise<void> {
  const trimmed = url.trim();
  if (!trimmed) {
    return;
  }

  try {
    const { isTauri } = await import("@tauri-apps/api/core");
    if (isTauri()) {
      const { openUrl } = await import("@tauri-apps/plugin-opener");
      await openUrl(trimmed);
      return;
    }
  } catch {
    // Fall through to browser navigation.
  }

  window.open(trimmed, "_blank", "noopener,noreferrer");
}

/**
 * Opens a Google Maps URL.
 * On Android (Tauri) this goes through the system opener so the Maps app can handle it;
 * in the browser it opens a new tab.
 */
export async function openGoogleMapsUrl(url: string): Promise<void> {
  await openExternalUrl(url);
}

/** Google Maps search/pin URL for a single stop. */
export function buildStopMapsUrl(stop: {
  lat?: number | null;
  lng?: number | null;
  placeId?: string | null;
  formattedAddress?: string | null;
  address?: string | null;
}): string | null {
  const lat = stop.lat;
  const lng = stop.lng;
  if (typeof lat === "number" && typeof lng === "number" && Number.isFinite(lat) && Number.isFinite(lng)) {
    const query = `${lat},${lng}`;
    const params = new URLSearchParams({ api: "1", query });
    if (stop.placeId) {
      params.set("query_place_id", stop.placeId);
    }
    return `https://www.google.com/maps/search/?${params.toString()}`;
  }

  const address = (stop.formattedAddress || stop.address || "").trim();
  if (!address) {
    return null;
  }

  const params = new URLSearchParams({ api: "1", query: address });
  return `https://www.google.com/maps/search/?${params.toString()}`;
}
