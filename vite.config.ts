import { defineConfig, loadEnv } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";
import { componentTagger } from "lovable-tagger";

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");
  const base = env.VITE_BASE_PATH || "/cobx/";
  const apiTarget = env.VITE_API_PROXY_TARGET || "http://localhost";

  const faviconHref = `${base.endsWith("/") ? base : `${base}/`}favicon.png`;

  return {
    base,
    server: {
      host: "::",
      port: 8080,
      hmr: { overlay: false },
      proxy: {
        "/cobx/api": { target: apiTarget, changeOrigin: true },
        "/api": { target: apiTarget, changeOrigin: true },
      },
    },
    plugins: [
      react(),
      {
        name: "inject-favicon",
        transformIndexHtml(html) {
          const tags =
            `    <link rel="icon" type="image/png" href="${faviconHref}" />\n` +
            `    <link rel="apple-touch-icon" href="${faviconHref}" />\n`;
          if (html.includes('rel="icon"')) {
            return html;
          }
          return html.replace("</head>", `${tags}  </head>`);
        },
      },
      mode === "development" && componentTagger(),
    ].filter(Boolean),
    resolve: {
      alias: { "@": path.resolve(__dirname, "./src") },
    },
  };
});
