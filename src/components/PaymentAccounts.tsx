import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Pencil, Plus, Star, Trash2, Wifi } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";

type ProviderField = { key: string; label: string; type: "text" | "password"; secret: boolean; required: boolean; storage: "credentials" | "config" | "public" };
type Provider = { id: string; label: string; enabled: boolean; capabilities: string[]; fields: ProviderField[] };
type Account = { id: string; name: string; provider: string; environment: "sandbox" | "production"; is_default: boolean; is_active: boolean; public_key?: string; credential_presence: Record<string, boolean>; payment_methods: string[] };
type Form = { name: string; provider: string; environment: "sandbox" | "production"; values: Record<string, string>; is_default: boolean; is_active: boolean };
const empty = (provider = "asaas"): Form => ({ name: "", provider, environment: "sandbox", values: {}, is_default: false, is_active: true });

export function PaymentAccounts() {
  const qc = useQueryClient(); const { toast } = useToast();
  const [editing, setEditing] = useState<Account | null>(null); const [open, setOpen] = useState(false); const [form, setForm] = useState<Form>(empty());
  const { data: catalog = [] } = useQuery({ queryKey: ["payment-providers"], queryFn: async () => (await apiFetch<{ items: Provider[] }>("/api/company/payment-providers")).items });
  const providers = useMemo(() => catalog.filter((p) => p.enabled), [catalog]);
  const definition = providers.find((p) => p.id === form.provider);
  const { data: accounts = [], isLoading } = useQuery({ queryKey: ["payment-accounts"], queryFn: async () => (await apiFetch<{ items: Account[] }>("/api/company/payment-settings")).items });
  const refresh = () => void qc.invalidateQueries({ queryKey: ["payment-accounts"] });
  const save = useMutation({
    mutationFn: async () => {
      const credentials: Record<string, string> = {}; const provider_config: Record<string, string> = {}; const body: Record<string, unknown> = { name: form.name, provider: form.provider, environment: form.environment, is_default: form.is_default, is_active: form.is_active };
      for (const field of definition?.fields ?? []) { const value = form.values[field.key]?.trim() ?? ""; if (value === "" && editing) continue; if (["api_key", "webhook_secret", "public_key"].includes(field.key)) body[field.key] = value; if (field.storage === "credentials" && value !== "") credentials[field.key] = value; if (field.storage === "config") provider_config[field.key] = value; }
      body.credentials = credentials; body.provider_config = provider_config;
      return apiFetch(editing ? `/api/company/payment-settings/${editing.id}` : "/api/company/payment-settings", { method: editing ? "PUT" : "POST", body: JSON.stringify(body) });
    },
    onSuccess: () => { refresh(); setOpen(false); toast({ title: editing ? "Conta atualizada" : "Conta adicionada" }); },
    onError: (e: Error) => toast({ title: "Não foi possível salvar", description: e.message, variant: "destructive" }),
  });
  const remove = useMutation({ mutationFn: (id: string) => apiFetch(`/api/company/payment-settings/${id}`, { method: "DELETE" }), onSuccess: () => { refresh(); toast({ title: "Conta removida" }); }, onError: (e: Error) => toast({ title: "Não foi possível remover", description: e.message, variant: "destructive" }) });
  const test = useMutation({ mutationFn: (id: string) => apiFetch<{ ok: boolean; detail: string }>(`/api/company/payment-settings/${id}/test`, { method: "POST" }), onSuccess: (r) => toast({ title: r.ok ? "Conexão validada" : "Teste não concluído", description: r.detail }), onError: (e: Error) => toast({ title: "Falha no teste", description: e.message, variant: "destructive" }) });
  const begin = (a?: Account) => { setEditing(a ?? null); setForm(a ? { name: a.name, provider: a.provider, environment: a.environment, values: { public_key: a.public_key ?? "" }, is_default: a.is_default, is_active: a.is_active } : empty(providers[0]?.id ?? "asaas")); setOpen(true); };

  return <Card className="shadow-card p-5 max-w-3xl mb-6">
    <div className="flex items-start justify-between gap-4 mb-4"><div><h2 className="font-semibold">Contas de recebimento</h2><p className="text-sm text-muted-foreground mt-1">Os campos e capacidades são definidos centralmente por conector.</p></div><Button type="button" onClick={() => begin()}><Plus className="h-4 w-4 mr-2" />Adicionar</Button></div>
    {isLoading ? <p className="text-sm text-muted-foreground">A carregar…</p> : <div className="space-y-3">{accounts.map((a) => <div key={a.id} className="rounded-lg border p-3 flex flex-wrap items-center justify-between gap-3"><div><div className="flex items-center gap-2 font-medium">{a.name}{a.is_default ? <span className="inline-flex items-center text-xs text-amber-600"><Star className="h-3 w-3 mr-1 fill-current" />Padrão</span> : null}{!a.is_active ? <span className="text-xs text-muted-foreground">Inativa</span> : null}</div><p className="text-xs text-muted-foreground">{catalog.find((p) => p.id === a.provider)?.label ?? a.provider} · {a.environment} · {a.payment_methods.join(" + ").toUpperCase()}</p></div><div className="flex gap-2"><Button type="button" size="sm" variant="outline" onClick={() => test.mutate(a.id)} disabled={test.isPending}><Wifi className="h-3.5 w-3.5" />Testar</Button><Button type="button" size="sm" variant="outline" onClick={() => begin(a)}><Pencil className="h-3.5 w-3.5" />Editar</Button>{!a.is_default ? <Button type="button" size="sm" variant="outline" className="text-destructive" onClick={() => { if (window.confirm(`Excluir a conta ${a.name}?`)) remove.mutate(a.id); }}><Trash2 className="h-3.5 w-3.5" /></Button> : null}</div></div>)}{accounts.length === 0 ? <p className="text-sm text-muted-foreground">Nenhuma conta cadastrada.</p> : null}</div>}
    <Dialog open={open} onOpenChange={setOpen}><DialogContent><DialogHeader><DialogTitle>{editing ? "Editar conta" : "Nova conta de recebimento"}</DialogTitle></DialogHeader><form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}><div><Label>Nome</Label><Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Conta Matriz" /></div><div><Label>Provedor</Label><Select value={form.provider} disabled={Boolean(editing)} onValueChange={(provider) => setForm({ ...form, provider, values: {} })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{providers.map((p) => <SelectItem key={p.id} value={p.id}>{p.label}</SelectItem>)}</SelectContent></Select></div>{(definition?.fields ?? []).map((field) => <div key={field.key}><Label>{field.label}</Label><Input type={field.type} required={!editing && field.required} value={form.values[field.key] ?? ""} onChange={(e) => setForm({ ...form, values: { ...form.values, [field.key]: e.target.value } })} placeholder={editing && field.secret && editing.credential_presence[field.key] ? "Deixe vazio para manter" : ""} /></div>)}<div><Label>Ambiente</Label><Select value={form.environment} onValueChange={(environment: "sandbox" | "production") => setForm({ ...form, environment })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="sandbox">Sandbox</SelectItem><SelectItem value="production">Produção</SelectItem></SelectContent></Select></div><div className="flex items-center justify-between"><Label>Conta padrão</Label><Switch checked={form.is_default} onCheckedChange={(is_default) => setForm({ ...form, is_default })} /></div><div className="flex items-center justify-between"><Label>Conta ativa</Label><Switch checked={form.is_active} onCheckedChange={(is_active) => setForm({ ...form, is_active })} /></div><Button type="submit" disabled={save.isPending}>{save.isPending ? "Salvando…" : "Salvar conta"}</Button></form></DialogContent></Dialog>
  </Card>;
}
