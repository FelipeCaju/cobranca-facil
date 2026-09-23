/** Converte ISO (yyyy-mm-dd) para exibição dd/mm/yyyy */
export function isoToDisplay(iso: string): string {
  if (!iso) return "";
  const part = iso.slice(0, 10);
  const [y, m, d] = part.split("-");
  if (!y || !m || !d) return "";
  return `${d.padStart(2, "0")}/${m.padStart(2, "0")}/${y}`;
}

/** Converte dd/mm/yyyy para ISO; retorna null se inválido */
export function displayToIso(display: string): string | null {
  const trimmed = display.trim();
  if (!trimmed) return "";
  const m = trimmed.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  if (!m) return null;
  const day = Number(m[1]);
  const month = Number(m[2]);
  const year = Number(m[3]);
  if (month < 1 || month > 12 || day < 1 || day > 31 || year < 1900 || year > 2100) return null;
  const date = new Date(year, month - 1, day);
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

/** Aplica máscara dd/mm/yyyy a partir só de dígitos (máx. 8) */
export function maskDateDigits(digits: string): string {
  const d = digits.replace(/\D/g, "").slice(0, 8);
  if (d.length <= 2) return d;
  if (d.length <= 4) return `${d.slice(0, 2)}/${d.slice(2)}`;
  return `${d.slice(0, 2)}/${d.slice(2, 4)}/${d.slice(4)}`;
}

/** Formata data ISO para listagens (pt-BR) */
export function formatDateBr(iso: string | null | undefined): string {
  if (!iso) return "—";
  const display = isoToDisplay(String(iso));
  return display || String(iso);
}
