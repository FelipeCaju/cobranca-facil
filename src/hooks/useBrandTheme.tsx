import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { apiFetch } from "@/lib/api";
import { applyBrandAccentToDocument, normalizeBrandAccentKey, type BrandAccentKey } from "@/lib/brandAccent";
import { applyDocumentBranding, DEFAULT_SYSTEM_NAME } from "@/lib/systemBranding";
import { NoTextNodes } from "@/components/NoTextNodes";

interface BrandThemeContextValue {
  accent: BrandAccentKey;
  systemName: string;
  systemDescription: string;
  refresh: () => Promise<void>;
  applyLocal: (key: BrandAccentKey) => void;
}

const BrandThemeContext = createContext<BrandThemeContextValue>({
  accent: "amber",
  systemName: DEFAULT_SYSTEM_NAME,
  systemDescription: `${DEFAULT_SYSTEM_NAME} — plataforma de cobranças automatizadas com PIX, lembretes por WhatsApp e email.`,
  refresh: async () => {},
  applyLocal: () => {},
});

export function BrandThemeProvider({ children }: { children: ReactNode }) {
  const [accent, setAccent] = useState<BrandAccentKey>("amber");
  const [systemName, setSystemName] = useState(DEFAULT_SYSTEM_NAME);
  const [systemDescription, setSystemDescription] = useState(
    `${DEFAULT_SYSTEM_NAME} — plataforma de cobranças automatizadas com PIX, lembretes por WhatsApp e email.`,
  );

  const applyLocal = useCallback((key: BrandAccentKey) => {
    const k = normalizeBrandAccentKey(key);
    setAccent(k);
    applyBrandAccentToDocument(k);
  }, []);

  const refresh = useCallback(async () => {
    try {
      const d = await apiFetch<{
        brand_accent: string;
        system_name?: string;
        system_description?: string;
      }>("/api/theme");
      applyLocal(normalizeBrandAccentKey(d.brand_accent));
      const name = (d.system_name ?? "").trim() || DEFAULT_SYSTEM_NAME;
      const desc =
        (d.system_description ?? "").trim() ||
        `${name} — plataforma de cobranças automatizadas com PIX, lembretes por WhatsApp e email.`;
      setSystemName(name);
      setSystemDescription(desc);
      applyDocumentBranding(name, desc);
    } catch {
      applyLocal("amber");
      setSystemName(DEFAULT_SYSTEM_NAME);
      const desc = `${DEFAULT_SYSTEM_NAME} — plataforma de cobranças automatizadas com PIX, lembretes por WhatsApp e email.`;
      setSystemDescription(desc);
      applyDocumentBranding(DEFAULT_SYSTEM_NAME, desc);
    }
  }, [applyLocal]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  return (
    <BrandThemeContext.Provider value={{ accent, systemName, systemDescription, refresh, applyLocal }}>
      <NoTextNodes>{children}</NoTextNodes>
    </BrandThemeContext.Provider>
  );
}

export function useBrandTheme() {
  return useContext(BrandThemeContext);
}
