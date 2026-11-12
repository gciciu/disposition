import { getVersion } from "@tauri-apps/api/app";
import { appCacheDir, join } from "@tauri-apps/api/path";
import { platform } from "@tauri-apps/plugin-os";
import { download } from "@tauri-apps/plugin-upload";
import {
  canInstall,
  install,
  requestInstallPermission,
} from "tauri-plugin-android-installer-api";

export type DriverAppVersionInfo = {
  versionCode: number;
  versionName: string;
  force: boolean;
  changelog: string;
  apkAvailable: boolean;
  apkUrl: string | null;
  downloadPageUrl?: string;
  downloadUrl?: string | null;
};

export type UpdateCheckResult =
  | { status: "skipped" }
  | { status: "up-to-date"; localVersion: string; remote: DriverAppVersionInfo }
  | { status: "available"; localVersion: string; remote: DriverAppVersionInfo };

const API_BASE_URL =
  import.meta.env.VITE_API_URL || (import.meta.env.DEV ? "http://127.0.0.1:8080" : "");

function parseVersionParts(version: string): number[] {
  return version
    .trim()
    .replace(/^v/i, "")
    .split(/[.+_-]/)
    .map((part) => Number.parseInt(part.replace(/^\D+/, ""), 10))
    .map((n) => (Number.isFinite(n) ? n : 0));
}

/** True when remote versionName is newer than local (semver-ish). */
export function isRemoteNewer(remoteVersion: string, localVersion: string): boolean {
  const remote = parseVersionParts(remoteVersion);
  const local = parseVersionParts(localVersion);
  const len = Math.max(remote.length, local.length);

  for (let i = 0; i < len; i += 1) {
    const r = remote[i] ?? 0;
    const l = local[i] ?? 0;
    if (r > l) return true;
    if (r < l) return false;
  }

  return false;
}

export async function isAndroidRuntime(): Promise<boolean> {
  try {
    return (await platform()) === "android";
  } catch {
    return false;
  }
}

export async function fetchDriverAppVersion(): Promise<DriverAppVersionInfo> {
  const response = await fetch(`${API_BASE_URL}/api/driver/app-version`, {
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    throw new Error(`Version check failed (${response.status})`);
  }

  return (await response.json()) as DriverAppVersionInfo;
}

export async function checkForDriverAppUpdate(): Promise<UpdateCheckResult> {
  if (!(await isAndroidRuntime())) {
    return { status: "skipped" };
  }

  const [localVersion, remote] = await Promise.all([getVersion(), fetchDriverAppVersion()]);

  if (!remote.apkAvailable || !remote.apkUrl) {
    return { status: "up-to-date", localVersion, remote };
  }

  if (!isRemoteNewer(remote.versionName, localVersion)) {
    return { status: "up-to-date", localVersion, remote };
  }

  return { status: "available", localVersion, remote };
}

export async function downloadAndInstallDriverApk(
  apkUrl: string,
  onProgress?: (percent: number) => void,
): Promise<void> {
  const path = await join(await appCacheDir(), "driver-update.apk");

  await download(apkUrl, path, ({ progressTotal, total }) => {
    if (!onProgress) return;
    if (!total || total <= 0) {
      onProgress(0);
      return;
    }
    onProgress(Math.min(100, Math.round((progressTotal / total) * 100)));
  });

  if (!(await canInstall())) {
    await requestInstallPermission();
    if (!(await canInstall())) {
      throw new Error("Install permission denied");
    }
  }

  await install(path);
}
