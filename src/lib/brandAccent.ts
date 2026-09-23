export type BrandAccentKey = "amber" | "teal" | "blue" | "purple" | "rose" | "emerald" | "navy";

export interface BrandAccentPreset {
  key: BrandAccentKey;
  label: string;
  /** Amostra visual (CSS) */
  swatch: string;
  accent: string;
  accentForeground: string;
  accentLight: string;
  gradientAccent: string;
  sidebarPrimary: string;
}

export const BRAND_ACCENT_PRESETS: BrandAccentPreset[] = [
  {
    key: "amber",
    label: "Âmbar (padrão)",
    swatch: "hsl(38 92% 50%)",
    accent: "38 92% 50%",
    accentForeground: "222 60% 12%",
    accentLight: "38 92% 58%",
    gradientAccent: "linear-gradient(135deg, hsl(38 92% 50%), hsl(28 90% 55%))",
    sidebarPrimary: "38 92% 50%",
  },
  {
    key: "teal",
    label: "Verde-água",
    swatch: "hsl(168 80% 35%)",
    accent: "168 80% 35%",
    accentForeground: "0 0% 100%",
    accentLight: "168 70% 45%",
    gradientAccent: "linear-gradient(135deg, hsl(168 80% 35%), hsl(168 70% 45%))",
    sidebarPrimary: "168 80% 35%",
  },
  {
    key: "blue",
    label: "Azul",
    swatch: "hsl(217 91% 55%)",
    accent: "217 91% 55%",
    accentForeground: "0 0% 100%",
    accentLight: "217 91% 65%",
    gradientAccent: "linear-gradient(135deg, hsl(217 91% 55%), hsl(210 90% 62%))",
    sidebarPrimary: "217 91% 55%",
  },
  {
    key: "purple",
    label: "Roxo",
    swatch: "hsl(262 83% 58%)",
    accent: "262 83% 58%",
    accentForeground: "0 0% 100%",
    accentLight: "262 75% 65%",
    gradientAccent: "linear-gradient(135deg, hsl(262 83% 58%), hsl(280 70% 60%))",
    sidebarPrimary: "262 83% 58%",
  },
  {
    key: "rose",
    label: "Rosa",
    swatch: "hsl(346 77% 50%)",
    accent: "346 77% 50%",
    accentForeground: "0 0% 100%",
    accentLight: "346 70% 58%",
    gradientAccent: "linear-gradient(135deg, hsl(346 77% 50%), hsl(350 75% 58%))",
    sidebarPrimary: "346 77% 50%",
  },
  {
    key: "emerald",
    label: "Esmeralda",
    swatch: "hsl(142 71% 45%)",
    accent: "142 71% 45%",
    accentForeground: "0 0% 100%",
    accentLight: "142 65% 52%",
    gradientAccent: "linear-gradient(135deg, hsl(142 71% 45%), hsl(152 65% 42%))",
    sidebarPrimary: "142 71% 45%",
  },
  {
    key: "navy",
    label: "Azul-marinho",
    swatch: "hsl(222 60% 45%)",
    accent: "222 60% 45%",
    accentForeground: "40 30% 96%",
    accentLight: "222 50% 55%",
    gradientAccent: "linear-gradient(135deg, hsl(222 60% 35%), hsl(222 50% 48%))",
    sidebarPrimary: "222 60% 45%",
  },
];

export function normalizeBrandAccentKey(key: string | null | undefined): BrandAccentKey {
  const k = (key ?? "amber").toLowerCase();
  return BRAND_ACCENT_PRESETS.some((p) => p.key === k) ? (k as BrandAccentKey) : "amber";
}

export function applyBrandAccentToDocument(key: BrandAccentKey): void {
  const preset = BRAND_ACCENT_PRESETS.find((p) => p.key === key) ?? BRAND_ACCENT_PRESETS[0];
  const root = document.documentElement;
  root.style.setProperty("--accent", preset.accent);
  root.style.setProperty("--accent-foreground", preset.accentForeground);
  root.style.setProperty("--accent-light", preset.accentLight);
  root.style.setProperty("--gradient-accent", preset.gradientAccent);
  root.style.setProperty("--sidebar-primary", preset.sidebarPrimary);
  root.dataset.brandAccent = preset.key;
}
