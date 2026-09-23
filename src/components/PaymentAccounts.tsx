import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Pencil, Trash2, Star } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";

type Provider = "asaas" | "mercadopago";
type Account = { id: string; name: string; provider: Provider; environment: "sandbox" | "production"; is_default: boolean; is_active: boolean; public_key?: string; api_key_masked?: string; webhook_secret_set?: boolean; payment_methods: string[] };
type Form = { name: string; provider: Provider; environment: "sandbox" | "production"; api_key: string; public_key: string; webhook_secret: string; is_default: boolean; is_active: boolean };
const empty = (): Form => ({ name: "", provider: "asaas", environment: "sandbox", api_key: "", public_key: "", webhook_secret: "", is_default: false, is_active: true });

export function PaymentAccounts() {
  const qc = useQueryClient(); const { toast } = useToast();
  const [editing, setEditing] = useState<Account | null>(null); const [open, setOpen] = useState(false); const [form, setForm] = useState<Form>(empty());
  const { data: accounts = [], isLoading } = useQuery({ queryKey: ["payment-accounts"], queryFn: async () => (await apiFetch<{ items: Account[] }>("/api/company/payment-settings")).items });
  const refresh = () => void qc.invalidateQueries({ queryKey: ["payment-accounts"] });
  const save = useMutation({
    mutationFn: async () => {
      const body: Record<string, unknown> = { ...form };
      if (editing && !form.api_key) delete body.api_key;
      if (editing && !form.webhook_secret) delete body.webhook_secret;
      return apiFetch(editing ? `/api/company/payment-settings/${editing.id}` : "/api/company/payment-settings", { method: editing ? "PUT" : "POST", body: JSON.stringify(body) });
    },
    onSuccess: () => { refresh(); setOpen(false); toast({ title: editing ? "Conta atualizada" : "Conta adicionada" }); },
    onError: (e: Error) => toast({ title: "Não foi possível salvar", description: e.message, variant: "destructive" }),
  });
  const remove = useMutation({ mutationFn: (id: string) => apiFetch(`/api/company/payment-settings/${id}`, { method: "DELETE" }), onSuccess: () => { refresh(); toast({ title: "Conta removida" }); }, onError: (e: Error) => toast({ title: "Não foi possível remover", description: e.message, variant: "destructive" }) });
  const begin = (account?: Account) => { setEditing(account ?? null); setForm(account ? { name: account.name, provider: account.provider, environment: account.environment, api_key: "", public_key: account.public_key ?? "", webhook_secret: "", is_default: account.is_default, is_active: account.is_active } : empty()); setOpen(true); };
  return <Card className="shadow-card p-5 max-w-3xl mb-6">
    <div className="flex items-start justify-between gap-4 mb-4"><div><h2 className="font-semibold">Contas de recebimento</h2><p className="text-sm text-muted-foreground mt-1">Cadastre uma conta para cada Asaas, Mercado Pago, matriz ou filial. A conta é escolhida na criação da cobrança.</p></div><Button type="button" onClick={() => begin()}><Plus className="h-4 w-4 mr-2" />Adicionar</Button></div>
    {isLoading ? <p className="text-sm text-muted-foreground">A carregar…</p> : <div className="space-y-3">{accounts.map((a) => <div key={a.id} className="rounded-lg border p-3 flex flex-wrap items-center justify-between gap-3"><div><div className="flex items-center gap-2 font-medium">{a.name}{a.is_default ? <span className="inline-flex items-center text-xs text-amber-600"><Star className="h-3 w-3 mr-1 fill-current" />Padrão</span> : null}{!a.is_active ? <span className="text-xs text-muted-foreground">Inativa</span> : null}</div><p className="text-xs text-muted-foreground capitalize">{a.provider === "mercadopago" ? "Mercado Pago" : "Asaas"} · {a.environment} · {a.payment_methods.join(" + ").toUpperCase()}</p></div><div className="flex gap-2"><Button type="button" size="sm" variant="outline" onClick={() => begin(a)}><Pencil className="h-3.5 w-3.5" />Editar</Button>{!a.is_default ? <Button type="button" size="sm" variant="outline" className="text-destructive" onClick={() => { if (window.confirm(`Excluir a conta ${a.name}?`)) remove.mutate(a.id); }}><Trash2 className="h-3.5 w-3.5" /></Button> : null}</div></div>)}{accounts.length === 0 ? <p className="text-sm text-muted-foreground">Nenhuma conta cadastrada.</p> : null}</div>}
    <Dialog open={open} onOpenChange={setOpen}><DialogContent><DialogHeader><DialogTitle>{editing ? "Editar conta" : "Nova conta de recebimento"}</DialogTitle></DialogHeader><form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}><div><Label>Nome</Label><Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Asaas Matriz" /></div><div><Label>Provedor</Label><Select value={form.provider} onValueChange={(provider: Provider) => setForm({ ...form, provider })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="asaas">Asaas</SelectItem><SelectItem value="mercadopago">Mercado Pago</SelectItem></SelectContent></Select></div><div><Label>{form.provider === "asaas" ? "API Key" : "Access Token"}</Label><Input type="password" required={!editing} value={form.api_key} onChange={(e) => setForm({ ...form, api_key: e.target.value })} placeholder={editing ? "Deixe vazio para manter a credencial" : "Credencial da conta"} /></div>{form.provider === "mercadopago" ? <div><Label>Public Key</Label><Input value={form.public_key} onChange={(e) => setForm({ ...form, public_key: e.target.value })} /></div> : null}<div><Label>{form.provider === "asaas" ? "Token de autenticação do webhook" : "Assinatura secreta do webhook"}</Label><Input type="password" value={form.webhook_secret} onChange={(e) => setForm({ ...form, webhook_secret: e.target.value })} placeholder={editing && editing.webhook_secret_set ? "Deixe vazio para manter" : "Configure também no provedor"} /></div><div><Label>Ambiente</Label><Select value={form.environment} onValueChange={(environment: "sandbox" | "production") => setForm({ ...form, environment })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="sandbox">Sandbox</SelectItem><SelectItem value="production">Produção</SelectItem></SelectContent></Select></div><div className="flex items-center justify-between"><Label>Conta padrão</Label><Switch checked={form.is_default} onCheckedChange={(is_default) => setForm({ ...form, is_default })} /></div><div className="flex items-center justify-between"><Label>Conta ativa</Label><Switch checked={form.is_active} onCheckedChange={(is_active) => setForm({ ...form, is_active })} /></div><Button type="submit" disabled={save.isPending}>{save.isPending ? "Salvando…" : "Salvar conta"}</Button></form></DialogContent></Dialog>
  </Card>;
}
