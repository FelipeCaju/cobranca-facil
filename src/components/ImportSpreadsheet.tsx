import { useRef, useState } from "react";
import { Upload } from "lucide-react";
import { Button } from "@/components/ui/button";
import { apiFetch } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";

type Result = { total: number; valid: number; imported: number; errors: { line: number; error: string }[] };

export function ImportSpreadsheet({ type, onImported }: { type: "clients" | "charges"; onImported: () => void }) {
  const input = useRef<HTMLInputElement>(null); const [busy, setBusy] = useState(false); const { toast } = useToast();
  const upload = async (file: File) => {
    setBusy(true);
    try {
      const preview = new FormData(); preview.append("file", file); preview.append("dry_run", "1");
      const checked = await apiFetch<Result>(`/api/company/imports/${type}`, { method: "POST", body: preview });
      if (!checked.valid) throw new Error(checked.errors[0]?.error || "Nenhuma linha válida.");
      const warning = checked.errors.length ? ` ${checked.errors.length} linha(s) serão ignoradas.` : "";
      if (!window.confirm(`${checked.valid} de ${checked.total} linha(s) válidas.${warning} Continuar?`)) return;
      const data = new FormData(); data.append("file", file); data.append("dry_run", "0");
      const result = await apiFetch<Result>(`/api/company/imports/${type}`, { method: "POST", body: data });
      toast({ title: `${result.imported} registro(s) importado(s)`, description: result.errors.length ? `${result.errors.length} linha(s) com erro.` : undefined });
      onImported();
    } catch (e) { toast({ title: "Falha na importação", description: e instanceof Error ? e.message : "Arquivo inválido", variant: "destructive" }); }
    finally { setBusy(false); if (input.current) input.current.value = ""; }
  };
  return <div className="flex items-center gap-2"><input ref={input} className="hidden" type="file" accept=".csv,.xlsx" onChange={(e) => e.target.files?.[0] && void upload(e.target.files[0])} />
    <a className="text-xs text-primary underline" href={`${import.meta.env.BASE_URL}modelos/${type === "clients" ? "clientes" : "cobrancas"}.csv`} download>Modelo</a>
    <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => input.current?.click()}><Upload className="h-4 w-4" />{busy ? "Importando…" : "Importar CSV/Excel"}</Button></div>;
}
