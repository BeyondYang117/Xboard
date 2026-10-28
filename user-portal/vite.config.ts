import { defineConfig, loadEnv } from "vite";
import vue from "@vitejs/plugin-vue";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), "VITE_");
    return {
        plugins: [vue()],
        base: "./",
        server: {
            proxy: {
                "/api": {
                    target: env.VITE_API_PROXY || "http://127.0.0.1:8000",
                    changeOrigin: true,
                },
            },
        },
        build: {
            manifest: true,
            cssCodeSplit: false,
            rollupOptions: {
                output: {
                    entryFileNames: "assets/portal-[hash].js",
                    chunkFileNames: "assets/[name]-[hash].js",
                    assetFileNames: (asset) =>
                        asset.name?.endsWith(".css")
                            ? "assets/portal-[hash].css"
                            : "assets/[name]-[hash][extname]",
                },
            },
        },
    };
});
