/** Junta raiz (sem / final) + caminho (/api/...). */
function cobxJoinApiRoot(root: string, path: string): string {
  const r = root.replace(/\/$/, "");
  const p = path.startsWith("/") ? path : `/${path}`;
  return `${r}${p}`;
}

/**
 * Sem VITE_API_URL: em `npm run dev` o pedido nÃ£o pode ir para a porta do Vite (devolve HTML).
 * Usa o mesmo hostname na porta padrÃ£o (80/443) + VITE_BASE_PATH â€” tÃ­pico Laragon com Apache em paralelo.
 */
function cobxDefaultApiRoot(): string | null {
  if (typeof window === "undefined") return null;
  const base = import.meta.env.BASE_URL || "/";
  const basePath = base === "/" ? "" : base.replace(/\/$/, "");
  const { protocol, hostname, port } = window.location;
  const viteDevPorts = new Set(["8080", "5173", "4173"]);
  const originNoDevPort = viteDevPorts.has(port) ? `${protocol}//${hostname}` : window.location.origin;
  return `${originNoDevPort}${basePath}`;
}

/**
 * URL absoluta da API.
 * 1) `VITE_API_URL` no .env (recomendado em cenÃ¡rios ambÃ­guos).
 * 2) Em browser, raiz automÃ¡tica (Apache no mesmo host, fora da porta do Vite).
 * 3) Fallback: BASE_URL do Vite (sÃ³ serve se a API estiver no mesmo origin que o dev server).
 */
export function resolveApiUrl(path: string): string {
  if (typeof window !== "undefined") {
    const { port, origin } = window.location;
    const viteDevPorts = new Set(["8080", "5173", "4173"]);
    // Em npm run dev: pedir /cobx/api/... ao mesmo origin para o proxy do Vite encaminhar ao Apache (evita HTML do React).
    if (viteDevPorts.has(port)) {
      const base = (import.meta.env.BASE_URL || "/cobx/").replace(/\/$/, "") || "/cobx";
      const p = path.startsWith("/") ? path : `/${path}`;
      return `${origin}${base}${p}`;
    }
  }
  const fromEnv = import.meta.env.VITE_API_URL?.trim();
  if (fromEnv) {
    return cobxJoinApiRoot(fromEnv, path);
  }
  const auto = cobxDefaultApiRoot();
  if (auto) {
    return cobxJoinApiRoot(auto, path);
  }
  const viteBase = import.meta.env.BASE_URL;
  const p = path.startsWith("/") ? path.slice(1) : path;
  return `${viteBase}${p}`;
}

export const AUTH_STORAGE_KEY = "cobx_auth_token";

export function getStoredToken(): string | null {
  return localStorage.getItem(AUTH_STORAGE_KEY);
}

export function setStoredToken(token: string | null): void {
  if (token) {
    localStorage.setItem(AUTH_STORAGE_KEY, token);
  } else {
    localStorage.removeItem(AUTH_STORAGE_KEY);
  }
}

export async function apiFetch<T = unknown>(path: string, init: RequestInit = {}): Promise<T> {
  const url = resolveApiUrl(path);
  const headers = new Headers(init.headers);
  const token = getStoredToken();
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }
  const body = init.body;
  if (body !== undefined && !(body instanceof FormData) && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }
  let res: Response;
  try {
    const method = (init.method ?? "GET").toUpperCase();
    const requestInit: RequestInit = { ...init, headers };
    if (method === "GET" && requestInit.cache === undefined) {
      requestInit.cache = "no-store";
    }
    res = await fetch(url, requestInit);
  } catch {
    throw new Error(
      "Sem ligaÃ§Ã£o ao servidor (pedido bloqueado ou API inacessÃ­vel). " +
        "Confirme Apache/MySQL no Laragon, URL http://localhost/cobx/api/ e, se usa npm run dev, que a API permite PUT/DELETE (CORS).",
    );
  }
  const raw = await res.text();
  let data: unknown = {};
  if (raw.trim() !== "") {
    try {
      data = JSON.parse(raw) as unknown;
    } catch {
      const isHtml = /^\s*</.test(raw);
      data = {
        error: isHtml
          ? `Servidor demorou para responder ou retornou erro HTTP ${res.status}. Tente novamente em alguns segundos.`
          : raw.length > 200
            ? `${raw.slice(0, 200)}...`
            : raw,
      };
    }
  }
  if (!res.ok) {
    const msg =
      typeof data === "object" && data !== null && "error" in data && typeof (data as { error: unknown }).error === "string"
        ? (data as { error: string }).error
        : res.statusText || `Erro HTTP ${res.status}`;
    throw new Error(msg);
  }
  const trimmed = raw.trim();
  if (trimmed.startsWith("<") || trimmed.startsWith("<!")) {
    throw new Error(
      `Resposta HTML em vez de JSON (URL pedida: ${url}). A API tem de ser servida pelo PHP. ` +
        "Coloque VITE_API_URL no .env (ex.: http://localhost/cobx), reinicie o Vite, e no Apache confirme mod_rewrite e o .htaccess na raiz.",
    );
  }
  return data as T;
}

export function notifyAuthChanged(): void {
  window.dispatchEvent(new Event("cobx-auth-changed"));
}
