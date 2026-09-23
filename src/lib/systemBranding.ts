export const DEFAULT_SYSTEM_NAME = "CobrançaFácil";

export function applyDocumentBranding(systemName: string, description: string): void {
  document.title = systemName;

  const setMeta = (attr: "name" | "property", key: string, content: string) => {
    let el = document.querySelector(`meta[${attr}="${key}"]`);
    if (!el) {
      el = document.createElement("meta");
      el.setAttribute(attr, key);
      document.head.appendChild(el);
    }
    el.setAttribute("content", content);
  };

  setMeta("name", "description", description);
  setMeta("property", "og:title", systemName);
  setMeta("property", "og:description", description);
  setMeta("property", "og:type", "website");
  setMeta("name", "twitter:card", "summary");
  setMeta("name", "twitter:title", systemName);
  setMeta("name", "twitter:description", description);
}
