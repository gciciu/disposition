import { cpSync, existsSync, mkdirSync, readdirSync, rmSync, statSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath, URL } from "node:url";
import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";
// @ts-expect-error type error without @types/node package
import process from "node:process";

const host = process.env.TAURI_DEV_HOST;
const rootDir = fileURLToPath(new URL(".", import.meta.url));

function copySpaToSymfonyPublic() {
  return {
    name: "copy-spa-to-symfony-public",
    closeBundle() {
      const dist = join(rootDir, "dist");
      const publicDir = join(rootDir, "../backend/public");
      if (!existsSync(dist) || !existsSync(publicDir)) {
        return;
      }

      for (const name of readdirSync(dist)) {
        if (name === "index.html") {
          continue;
        }
        const from = join(dist, name);
        const to = join(publicDir, name);
        if (statSync(from).isDirectory()) {
          rmSync(to, { recursive: true, force: true });
          mkdirSync(to, { recursive: true });
          cpSync(from, to, { recursive: true });
        } else {
          cpSync(from, to);
        }
      }
    },
  };
}

// https://vite.dev/config/
export default defineConfig(() => ({
  plugins: [vue(), tailwindcss(), copySpaToSymfonyPublic()],
  base: "./",
  build: {
    // Avoid Symfony AssetMapper's /assets/ prefix (404 in debug / CloudPanel static rules).
    assetsDir: "spa",
  },
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
  clearScreen: false,
  server: {
    port: 1420,
    strictPort: true,
    host: host || false,
    hmr: host
      ? {
          protocol: "ws",
          host,
          port: 1421,
        }
      : undefined,
    watch: {
      ignored: ["**/src-tauri/**"],
    },
  },
}));
