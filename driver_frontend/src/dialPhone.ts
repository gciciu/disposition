function toTelUrl(phone: string): string {
  const trimmed = phone.trim();
  // Keep digits and dialer symbols; preserve a leading +.
  const cleaned = trimmed.replace(/[^\d+*#]/g, "").replace(/(?!^)\+/g, "");
  return `tel:${cleaned || trimmed}`;
}

export async function dialPhone(phone: string): Promise<void> {
  const url = toTelUrl(phone);

  try {
    const { isTauri } = await import("@tauri-apps/api/core");
    if (isTauri()) {
      const { openUrl } = await import("@tauri-apps/plugin-opener");
      await openUrl(url);
      return;
    }
  } catch {
    // Fall through to browser/WebView navigation.
  }

  window.location.href = url;
}
