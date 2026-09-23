import { useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Plus, Pencil, Package } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { apiFetch } from "@/lib/api";

const brl = (n: number) =>
  n.toLocaleString("pt-BR", { style: "currency", currency: "BRL", minimumFractionDigits: 2, maximumFractionDigits: 2 });

interface ProductRow {
  id: string;
  name: string;
  description: string | null;
  price: string;
  installments_count: number;
  is_monthly: number;
  is_recurring_monthly: number;
  has_daily_interest: number;
  daily_interest_percent: string;
  is_active: number;
}

const Products = () => {
  const qc = useQueryClient();
  const { toast } = useToast();
  const [open, setOpen] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [price, setPrice] = useState("");
  const [installments, setInstallments] = useState("1");
  const [isMonthly, setIsMonthly] = useState(true);
  const [isRecurring, setIsRecurring] = useState(false);
  const [hasDailyInterest, setHasDailyInterest] = useState(false);
  const [dailyInterestPercent, setDailyInterestPercent] = useState("");
  const [isActive, setIsActive] = useState(true);

  const { data, isLoading } = useQuery({
    queryKey: ["products"],
    queryFn: async () => {
      const r = await apiFetch<{ items: ProductRow[] }>("/api/company/products");
      return r.items;
    },
  });

  const resetForm = () => {
    setEditId(null);
    setName("");
    setDescription("");
    setPrice("");
    setInstallments("1");
    setIsMonthly(true);
    setIsRecurring(false);
    setHasDailyInterest(false);
    setDailyInterestPercent("");
    setIsActive(true);
  };

  const openNew = () => {
    resetForm();
    setOpen(true);
  };

  const openEdit = (p: ProductRow) => {
    setEditId(p.id);
    setName(p.name);
    setDescription(p.description ?? "");
    const n = Math.max(1, p.installments_count || 1);
    if (p.is_recurring_monthly) {
      const parcel = Number(p.price) / n;
      setPrice(Number.isFinite(parcel) ? String(parcel) : String(p.price));
    } else {
      setPrice(String(p.price));
    }
    setInstallments(String(p.installments_count));
    setIsMonthly(!!p.is_monthly);
    setIsRecurring(!!p.is_recurring_monthly);
    setHasDailyInterest(!!p.has_daily_interest);
    setDailyInterestPercent(String(Number(p.daily_interest_percent ?? 0) || ""));
    setIsActive(!!p.is_active);
    setOpen(true);
  };

  const submitProduct = () => {
    if (!name.trim()) {
      toast({ title: "Nome obrigatório", variant: "destructive" });
      return;
    }
    const n = Math.max(1, Math.min(120, parseInt(String(installments), 10) || 1));
    const parcel = parseFloat(String(price).replace(",", ".")) || 0;
    if (isRecurring) {
      if (parcel <= 0) {
        toast({ title: "Valor da parcela obrigatório", description: "Em recorrente mensal, indique só o valor de cada parcela.", variant: "destructive" });
        return;
      }
    } else if (parcel <= 0) {
      toast({ title: "Valor total obrigatório", variant: "destructive" });
      return;
    }
    saveMutation.mutate();
  };

  const saveMutation = useMutation({
    mutationFn: async () => {
      const n = Math.max(1, Math.min(120, parseInt(String(installments), 10) || 1));
      const parcel = parseFloat(String(price).replace(",", ".")) || 0;
      const body: Record<string, unknown> = {
        name,
        description: description || null,
        installments_count: n,
        is_monthly: isMonthly,
        is_recurring_monthly: isRecurring,
        has_daily_interest: hasDailyInterest,
        daily_interest_percent: hasDailyInterest ? parseFloat(String(dailyInterestPercent).replace(",", ".")) || 0 : 0,
        is_active: isActive,
      };
      if (isRecurring) {
        body.installment_amount = parcel;
        body.price = Math.round(parcel * n * 100) / 100;
      } else {
        body.price = parcel;
      }
      if (editId) {
        await apiFetch(`/api/company/products/${editId}`, { method: "PUT", body: JSON.stringify(body) });
      } else {
        await apiFetch("/api/company/products", { method: "POST", body: JSON.stringify(body) });
      }
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["products"] });
      toast({ title: editId ? "Produto atualizado" : "Produto criado" });
      setOpen(false);
      resetForm();
    },
    onError: (e: Error) => {
      toast({ title: "Erro", description: e.message, variant: "destructive" });
    },
  });

  const installmentPreview = useMemo(() => {
    const input = parseFloat(String(price).replace(",", ".")) || 0;
    const n = Math.max(1, Math.min(120, parseInt(String(installments), 10) || 1));
    if (input <= 0) {
      return { n, total: 0, per: 0, label: "—" as string };
    }
    if (isRecurring) {
      const total = input * n;
      return { n, total, per: input, label: `Total ${brl(total)} (${n} × ${brl(input)})` };
    }
    const per = input / n;
    return { n, total: input, per, label: `${n} × ${brl(per)}` };
  }, [price, installments, isRecurring]);

  const deleteMutation = useMutation({
    mutationFn: (id: string) => apiFetch(`/api/company/products/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["products"] });
      toast({ title: "Produto removido" });
    },
    onError: (e: Error) => {
      toast({ title: "Erro", description: e.message, variant: "destructive" });
    },
  });

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Produtos</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Defina preço, parcelas, se as parcelas são mensais e se a cobrança se repete todos os meses (geração automática de faturas pode ser agendada no servidor).
          </p>
        </div>
        <Dialog open={open} onOpenChange={(v) => { setOpen(v); if (!v) resetForm(); }}>
          <Button variant="hero" size="sm" type="button" onClick={openNew}>
            <Plus className="h-4 w-4" />
            Novo produto
          </Button>
          <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
            <DialogHeader>
              <DialogTitle>{editId ? "Editar produto" : "Novo produto"}</DialogTitle>
            </DialogHeader>
            <div className="space-y-4 mt-2">
              <div className="space-y-2">
                <Label>Nome</Label>
                <Input value={name} onChange={(e) => setName(e.target.value)} required />
              </div>
              <div className="space-y-2">
                <Label>Descrição</Label>
                <Textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2} />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>{isRecurring ? "Valor da parcela (R$)" : "Valor total (R$)"}</Label>
                  <Input
                    value={price}
                    onChange={(e) => setPrice(e.target.value)}
                    type="text"
                    inputMode="decimal"
                    placeholder={isRecurring ? "100,00" : "1200,00"}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label>Número de parcelas</Label>
                  <Input value={installments} onChange={(e) => setInstallments(e.target.value)} type="number" min={1} max={120} />
                </div>
              </div>
              <div className="rounded-lg border border-border bg-muted/30 px-3 py-3 space-y-1">
                <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                  {isRecurring ? "Resumo" : "Valor de cada parcela"}
                </p>
                <p className="text-lg font-semibold text-foreground tabular-nums">{installmentPreview.label}</p>
                <p className="text-xs text-muted-foreground">
                  {parseFloat(String(price).replace(",", ".")) > 0
                    ? isRecurring
                      ? `O valor total enviado ao sistema é ${brl(installmentPreview.total)} (parcela × ${installmentPreview.n}).`
                      : `Total ${brl(installmentPreview.total)} dividido em ${installmentPreview.n} parcela(s) iguais.`
                    : isRecurring
                      ? "Indique o valor da parcela para ver o total do plano."
                      : "Indique o valor total para ver o valor por parcela."}
                </p>
              </div>
              <div className="flex items-center justify-between rounded-lg border border-border p-3">
                <div>
                  <p className="text-sm font-medium">Parcelas mensais</p>
                  <p className="text-xs text-muted-foreground">Vencimento de cada parcela +1 mês</p>
                </div>
                <Switch checked={isMonthly} onCheckedChange={setIsMonthly} />
              </div>
              <div className="flex items-center justify-between rounded-lg border border-border p-3">
                <div>
                  <p className="text-sm font-medium">Recorrente mensal</p>
                  <p className="text-xs text-muted-foreground">Todo mês gera nova cobrança (automático no backend/cron)</p>
                </div>
                <Switch checked={isRecurring} onCheckedChange={setIsRecurring} />
              </div>
              <div className="rounded-lg border border-border p-3 space-y-3">
                <div className="flex items-center justify-between gap-4">
                  <div>
                    <p className="text-sm font-medium">Juros diario apos vencimento</p>
                    <p className="text-xs text-muted-foreground">
                      Se a cobranca for gerada com vencimento em atraso, o valor ja considera os dias vencidos.
                    </p>
                  </div>
                  <Switch checked={hasDailyInterest} onCheckedChange={setHasDailyInterest} />
                </div>
                {hasDailyInterest ? (
                  <div className="space-y-2">
                    <Label>Percentual ao dia (%)</Label>
                    <Input
                      value={dailyInterestPercent}
                      onChange={(e) => setDailyInterestPercent(e.target.value)}
                      type="text"
                      inputMode="decimal"
                      placeholder="Ex.: 1,00"
                    />
                  </div>
                ) : null}
              </div>
              <div className="flex items-center justify-between rounded-lg border border-border p-3">
                <div>
                  <p className="text-sm font-medium">Ativo</p>
                  <p className="text-xs text-muted-foreground">Só produtos ativos aparecem na cobrança</p>
                </div>
                <Switch checked={isActive} onCheckedChange={setIsActive} />
              </div>
              <Button type="button" variant="hero" className="w-full" disabled={saveMutation.isPending} onClick={submitProduct}>
                {saveMutation.isPending ? "A guardar…" : "Guardar"}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>

      <Card className="shadow-card overflow-hidden">
        {isLoading ? (
          <div className="p-8 text-center text-muted-foreground">A carregar…</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border bg-muted/30">
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Produto</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Valor total</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Parcelas</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Regras</th>
                  <th className="px-5 py-3 text-right text-xs font-medium text-muted-foreground uppercase">Ações</th>
                </tr>
              </thead>
              <tbody>
                {(data ?? []).map((p) => (
                  <tr key={p.id} className="border-b border-border last:border-0 hover:bg-muted/50">
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-2">
                        <Package className="h-4 w-4 text-primary shrink-0" />
                        <div>
                          <p className="text-sm font-medium">{p.name}</p>
                          {p.description && <p className="text-xs text-muted-foreground line-clamp-1">{p.description}</p>}
                        </div>
                      </div>
                    </td>
                    <td className="px-5 py-3.5 text-sm">
                      <span className="font-medium">{brl(Number(p.price))}</span>
                      {p.installments_count > 1 && (
                        <span className="block text-xs text-muted-foreground mt-0.5">
                          {p.installments_count} × {brl(Number(p.price) / p.installments_count)}
                        </span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 text-sm">{p.installments_count}×</td>
                    <td className="px-5 py-3.5 text-xs text-muted-foreground">
                      {p.is_monthly ? "Mensal " : "Período fixo "}
                      {p.is_recurring_monthly ? "· Recorrente" : ""}
                      {p.has_daily_interest ? ` · Juros ${Number(p.daily_interest_percent).toLocaleString("pt-BR")} %/dia` : ""}
                      {!p.is_active ? " · Inativo" : ""}
                    </td>
                    <td className="px-5 py-3.5 text-right space-x-2">
                      <Button type="button" size="sm" variant="outline" onClick={() => openEdit(p)}>
                        <Pencil className="h-3 w-3" />
                      </Button>
                      <Button type="button" size="sm" variant="ghost" className="text-destructive" onClick={() => deleteMutation.mutate(p.id)}>
                        Remover
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {data?.length === 0 && <div className="p-8 text-center text-muted-foreground">Nenhum produto ainda.</div>}
          </div>
        )}
      </Card>
    </div>
  );
};

export default Products;
