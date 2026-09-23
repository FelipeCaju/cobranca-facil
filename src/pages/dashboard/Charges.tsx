import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DateInput } from "@/components/ui/date-input";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Copy, ExternalLink, Plus, Search, FileText, Pencil, Trash2 } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { apiFetch } from "@/lib/api";
import { formatDateBr } from "@/lib/dates";
import { ImportSpreadsheet } from "@/components/ImportSpreadsheet";

interface ClientOpt {
  id: string;
  name: string;
}

interface ProductOpt {
  id: string;
  name: string;
  price: string;
  installments_count: number;
  has_daily_interest?: number;
  daily_interest_percent?: string;
}

interface ChargeRow {
  id: string;
  client_id: string;
  product_id: string | null;
  description: string;
  total_amount: string;
  installments_count: number;
  payment_gateway: string;
  payment_account_id?: string | null;
  payment_account_name?: string | null;
  payment_method?: "pix" | "boleto";
  status: string;
  created_at: string;
  client_name: string;
  product_name: string | null;
}

interface PaymentAccountOpt { id: string; name: string; provider: "asaas" | "mercadopago"; is_default: boolean; is_active: boolean; payment_methods: string[] }

interface InstallmentRow {
  id: string;
  installment_number: number;
  amount: string;
  due_date: string;
  status: string;
  paid_at: string | null;
  external_id?: string | null;
  payment_url?: string | null;
  boleto_digitable_line?: string | null;
  boleto_pdf_url?: string | null;
  payer_portal_url?: string | null;
  pix_qrcode?: string | null;
  pix_copy_paste?: string | null;
}

interface ChargeDetail extends ChargeRow {
  paid_installments_count?: number;
  first_due_date?: string | null;
  installments?: InstallmentRow[];
}

const statusLabel: Record<string, string> = {
  pending: "Pendente",
  paid: "Paga",
  overdue: "Atrasada",
  cancelled: "Cancelada",
};

const statusStyles: Record<string, string> = {
  pending: "bg-warning/10 text-warning",
  paid: "bg-success/10 text-success",
  overdue: "bg-destructive/10 text-destructive",
  cancelled: "bg-muted text-muted-foreground",
};

const Charges = () => {
  const qc = useQueryClient();
  const { toast } = useToast();
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [clientFilter, setClientFilter] = useState("");
  const [gatewayFilter, setGatewayFilter] = useState("");
  const [open, setOpen] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [paidInstallments, setPaidInstallments] = useState(0);
  const [editInstallments, setEditInstallments] = useState<InstallmentRow[]>([]);
  const [clientId, setClientId] = useState("");
  const [productId, setProductId] = useState("");
  const [paymentAccountId, setPaymentAccountId] = useState("");
  const [paymentMethod, setPaymentMethod] = useState<"pix" | "boleto">("pix");
  const [firstDue, setFirstDue] = useState("");

  const filterQs = () => {
    const p = new URLSearchParams();
    if (search.trim()) p.set("q", search.trim());
    if (statusFilter) p.set("status", statusFilter);
    if (clientFilter) p.set("client_id", clientFilter);
    if (gatewayFilter) p.set("gateway", gatewayFilter);
    const s = p.toString();
    return s ? `?${s}` : "";
  };

  const { data: clientList = [] } = useQuery({
    queryKey: ["clients-charges"],
    queryFn: async () => {
      const r = await apiFetch<{ items: ClientOpt[] }>("/api/company/clients");
      return r.items;
    },
  });

  const { data: productList = [] } = useQuery({
    queryKey: ["products-charges"],
    queryFn: async () => {
      const r = await apiFetch<{ items: ProductOpt[] }>("/api/company/products");
      return r.items.filter((p) => p.id);
    },
  });
  const { data: paymentAccounts = [] } = useQuery({
    queryKey: ["payment-accounts-charges"],
    queryFn: async () => (await apiFetch<{ items: PaymentAccountOpt[] }>("/api/company/payment-settings")).items.filter((a) => a.is_active),
  });

  const { data: charges = [], isLoading } = useQuery({
    queryKey: ["charges", search, statusFilter, clientFilter, gatewayFilter],
    queryFn: async () => {
      const r = await apiFetch<{ items: ChargeRow[] }>(`/api/company/charges${filterQs()}`);
      return r.items;
    },
  });

  const loadChargeDetail = async (id: string) => {
    const row = await apiFetch<ChargeDetail>(`/api/company/charges/${id}`);
    setEditId(id);
    setPaidInstallments(Number(row.paid_installments_count ?? 0));
    setEditInstallments(row.installments ?? []);
    setClientId(row.client_id);
    setProductId(row.product_id ?? "");
    setPaymentAccountId(row.payment_account_id ?? "");
    setPaymentMethod(row.payment_method ?? "pix");
    setFirstDue(row.first_due_date ? String(row.first_due_date).slice(0, 10) : "");
    setOpen(true);
  };

  const saveMutation = useMutation({
    mutationFn: async () => {
      const body = {
        client_id: clientId,
        product_id: productId,
        payment_account_id: paymentAccountId,
        payment_method: paymentMethod,
        first_due_date: firstDue || undefined,
      };
      if (editId) {
        return apiFetch(`/api/company/charges/${editId}`, { method: "PUT", body: JSON.stringify(body) });
      } else {
        return apiFetch<{ payment_generation?: { ok: boolean; created: number; failed: number; details: string[] } }>("/api/company/charges", {
          method: "POST",
          body: JSON.stringify(body),
        });
      }
    },
    onSuccess: async (data) => {
      void qc.invalidateQueries({ queryKey: ["charges"] });
      void qc.invalidateQueries({ queryKey: ["clients"] });
      void qc.invalidateQueries({ queryKey: ["company-installments"] });
      void qc.invalidateQueries({ queryKey: ["company-overview"] });
      toast({ title: editId ? "Cobrança atualizada" : "Cobrança criada" });
      if (editId) {
        await loadChargeDetail(editId);
      } else {
        closeDialog();
      }
    },
    onError: (e: Error) => toast({ title: "Erro", description: e.message, variant: "destructive" }),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => apiFetch(`/api/company/charges/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["charges"] });
      void qc.invalidateQueries({ queryKey: ["company-installments"] });
      toast({ title: "Cobrança excluída" });
    },
    onError: (e: Error) => toast({ title: "Erro", description: e.message, variant: "destructive" }),
  });

  const markPaidMutation = useMutation({
    mutationFn: (installmentId: string) =>
      apiFetch(`/api/company/installments/${installmentId}/mark-paid`, { method: "POST" }),
    onSuccess: async () => {
      void qc.invalidateQueries({ queryKey: ["charges"] });
      void qc.invalidateQueries({ queryKey: ["company-installments"] });
      if (editId) await loadChargeDetail(editId);
      toast({ title: "Baixa manual registada" });
    },
    onError: (e: Error) => toast({ title: "Erro", description: e.message, variant: "destructive" }),
  });

  const generatePaymentMutation = useMutation({
    mutationFn: (installmentId: string) =>
      apiFetch<{ created: number; failed: number; details: string[] }>(`/api/company/installments/${installmentId}/generate-payment`, {
        method: "POST",
      }),
    onSuccess: async (result) => {
      void qc.invalidateQueries({ queryKey: ["charges"] });
      void qc.invalidateQueries({ queryKey: ["company-installments"] });
      if (editId) await loadChargeDetail(editId);
      toast({
        title: result.created > 0 ? "Link de pagamento gerado" : "Link nao gerado",
        description: result.details?.[0],
        variant: result.failed > 0 && result.created === 0 ? "destructive" : undefined,
      });
    },
    onError: (e: Error) => toast({ title: "Erro", description: e.message, variant: "destructive" }),
  });

  const closeDialog = () => {
    setOpen(false);
    setEditId(null);
    setPaidInstallments(0);
    setEditInstallments([]);
    setClientId("");
    setProductId("");
    setPaymentAccountId("");
    setPaymentMethod("pix");
    setFirstDue("");
  };

  const openNew = () => {
    closeDialog();
    const defaultAccount = paymentAccounts.find((a) => a.is_default) ?? paymentAccounts[0];
    if (defaultAccount) setPaymentAccountId(defaultAccount.id);
    setOpen(true);
  };

  const openEdit = async (charge: ChargeRow) => {
    try {
      await loadChargeDetail(charge.id);
    } catch (e) {
      toast({
        title: "Erro",
        description: e instanceof Error ? e.message : "Não foi possível carregar a cobrança",
        variant: "destructive",
      });
    }
  };

  const submitCharge = () => {
    if (!clientId || !productId || !paymentAccountId) {
      toast({ title: "Preencha cliente, produto e conta de recebimento", variant: "destructive" });
      return;
    }
    saveMutation.mutate();
  };

  const copyText = async (text: string, title = "Copiado") => {
    try {
      await navigator.clipboard.writeText(text);
      toast({ title });
    } catch {
      toast({ title: "Nao foi possivel copiar", variant: "destructive" });
    }
  };

  const confirmDelete = (charge: ChargeRow) => {
    if (!window.confirm(`Excluir a cobrança de ${charge.client_name}? Todas as parcelas serão removidas.`)) return;
    deleteMutation.mutate(charge.id);
  };

  const productLocked = Boolean(editId && paidInstallments > 0);
  const brl = (n: number) => n.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-foreground">Cobranças</h1>
        <div className="flex gap-2"><ImportSpreadsheet type="charges" onImported={() => void qc.invalidateQueries({ queryKey: ["charges"] })} /><Button variant="hero" size="sm" type="button" onClick={openNew}>
          <Plus className="h-4 w-4" />
          Nova Cobrança
        </Button></div>
      </div>

      <Card className="shadow-card p-4 mb-4">
        <h2 className="text-sm font-medium mb-3">Filtrar cobranças</h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="space-y-2 sm:col-span-2">
            <Label>Pesquisar</Label>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Cliente, produto ou descrição…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9"
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label>Status</Label>
            <Select value={statusFilter || "__all__"} onValueChange={(v) => setStatusFilter(v === "__all__" ? "" : v)}>
              <SelectTrigger>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">Todos</SelectItem>
                {Object.entries(statusLabel).map(([k, l]) => (
                  <SelectItem key={k} value={k}>
                    {l}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>Cliente</Label>
            <Select value={clientFilter || "__all__"} onValueChange={(v) => setClientFilter(v === "__all__" ? "" : v)}>
              <SelectTrigger>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">Todos</SelectItem>
                {clientList.map((c) => (
                  <SelectItem key={c.id} value={c.id}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>Gateway</Label>
            <Select value={gatewayFilter || "__all__"} onValueChange={(v) => setGatewayFilter(v === "__all__" ? "" : v)}>
              <SelectTrigger>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">Todos</SelectItem>
                <SelectItem value="mercadopago">Mercado Pago</SelectItem>
                <SelectItem value="asaas">Asaas</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
      </Card>

      <Dialog open={open} onOpenChange={(v) => { if (!v) closeDialog(); else setOpen(true); }}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editId ? "Editar cobrança" : "Criar cobrança"}</DialogTitle>
          </DialogHeader>
          {productLocked && (
            <p className="text-sm text-muted-foreground">
              Há parcelas pagas — só pode alterar cliente e gateway.
            </p>
          )}
          <div className="space-y-4 mt-2">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Cliente *</Label>
                <Select value={clientId || undefined} onValueChange={setClientId}>
                  <SelectTrigger>
                    <SelectValue placeholder="Selecione o cliente" />
                  </SelectTrigger>
                  <SelectContent>
                    {clientList.map((c) => (
                      <SelectItem key={c.id} value={c.id}>
                        {c.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Produto *</Label>
                <Select value={productId || undefined} onValueChange={setProductId} disabled={productLocked}>
                  <SelectTrigger>
                    <SelectValue placeholder="Selecione o produto" />
                  </SelectTrigger>
                  <SelectContent>
                    {productList.map((p) => (
                      <SelectItem key={p.id} value={p.id}>
                        {p.name} — {brl(Number(p.price))} ({p.installments_count}x)
                        {p.has_daily_interest ? ` · juros ${Number(p.daily_interest_percent ?? 0).toLocaleString("pt-BR")} %/dia` : ""}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Conta de recebimento *</Label>
                <Select value={paymentAccountId || undefined} onValueChange={(id) => { setPaymentAccountId(id); const a=paymentAccounts.find((x)=>x.id===id); if (a && !a.payment_methods.includes(paymentMethod)) setPaymentMethod("pix"); }}>
                  <SelectTrigger>
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    {paymentAccounts.map((a) => <SelectItem key={a.id} value={a.id}>{a.name}{a.is_default ? " — padrão" : ""} ({a.provider})</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Forma de pagamento *</Label>
                <Select value={paymentMethod} onValueChange={(v) => setPaymentMethod(v as "pix" | "boleto")}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="pix">PIX</SelectItem>
                    {paymentAccounts.find((a) => a.id === paymentAccountId)?.payment_methods.includes("boleto") ? <SelectItem value="boleto">Boleto</SelectItem> : null}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Primeiro vencimento</Label>
                <DateInput value={firstDue} onChange={setFirstDue} disabled={productLocked} />
              </div>
            </div>
            <Button type="button" variant="hero" className="w-full sm:w-auto" disabled={saveMutation.isPending} onClick={submitCharge}>
              {saveMutation.isPending ? "A guardar…" : editId ? "Guardar alterações" : "Criar cobrança"}
            </Button>

            {editId && editInstallments.length > 0 && (
              <div className="border-t border-border pt-4 mt-4">
                <h3 className="text-sm font-medium mb-3">Parcelas desta cobrança</h3>
                <div className="overflow-x-auto rounded-md border border-border">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b bg-muted/30">
                        <th className="px-3 py-2 text-left font-medium text-muted-foreground">#</th>
                        <th className="px-3 py-2 text-left font-medium text-muted-foreground">Valor</th>
                        <th className="px-3 py-2 text-left font-medium text-muted-foreground">Vencimento</th>
                        <th className="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                        <th className="px-3 py-2 text-left font-medium text-muted-foreground">Pagamento</th>
                        <th className="px-3 py-2 text-left font-medium text-muted-foreground">Ação</th>
                      </tr>
                    </thead>
                    <tbody>
                      {editInstallments.map((ins) => {
                        const canPay = ins.status === "pending" || ins.status === "overdue";
                        return (
                          <tr key={ins.id} className="border-b last:border-0">
                            <td className="px-3 py-2">{ins.installment_number}</td>
                            <td className="px-3 py-2">{brl(Number(ins.amount))}</td>
                            <td className="px-3 py-2">{formatDateBr(ins.due_date)}</td>
                            <td className="px-3 py-2">
                              <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusStyles[ins.status] ?? ""}`}>
                                {statusLabel[ins.status] ?? ins.status}
                              </span>
                            </td>
                            <td className="px-3 py-2">
                              <div className="flex flex-wrap gap-1.5">
                                {ins.payment_url ? (
                                  <Button type="button" size="sm" variant="outline" asChild>
                                    <a href={ins.payment_url} target="_blank" rel="noreferrer">
                                      <ExternalLink className="h-3.5 w-3.5" />
                                      Link
                                    </a>
                                  </Button>
                                ) : null}
                                {ins.pix_copy_paste ? (
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void copyText(ins.pix_copy_paste || "", "Pix copia e cola copiado")}
                                  >
                                    <Copy className="h-3.5 w-3.5" />
                                    Pix
                                  </Button>
                                ) : null}
                                {ins.boleto_digitable_line ? (
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void copyText(ins.boleto_digitable_line || "", "Linha digitável copiada")}
                                  >
                                    <Copy className="h-3.5 w-3.5" />
                                    Linha
                                  </Button>
                                ) : null}
                                {ins.boleto_pdf_url ? (
                                  <Button type="button" size="sm" variant="outline" asChild>
                                    <a href={ins.boleto_pdf_url} target="_blank" rel="noreferrer">
                                      <ExternalLink className="h-3.5 w-3.5" />
                                      PDF
                                    </a>
                                  </Button>
                                ) : null}
                                {ins.payer_portal_url ? (
                                  <Button type="button" size="sm" variant="outline" onClick={() => void copyText(ins.payer_portal_url || "", "Link do portal copiado")}>
                                    <Copy className="h-3.5 w-3.5" />
                                    Portal
                                  </Button>
                                ) : null}
                                {!ins.payment_url && !ins.pix_copy_paste && !ins.boleto_digitable_line ? <span className="text-xs text-muted-foreground">Nao gerado</span> : null}
                                {!ins.payment_url && !ins.pix_copy_paste && !ins.boleto_digitable_line && canPay ? (
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={generatePaymentMutation.isPending}
                                    onClick={() => generatePaymentMutation.mutate(ins.id)}
                                  >
                                    Gerar
                                  </Button>
                                ) : null}
                              </div>
                            </td>
                            <td className="px-3 py-2">
                              {canPay ? (
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  disabled={markPaidMutation.isPending}
                                  onClick={() => {
                                    if (!window.confirm("Marcar parcela como paga (baixa manual)?")) return;
                                    markPaidMutation.mutate(ins.id);
                                  }}
                                >
                                  Baixa manual
                                </Button>
                              ) : (
                                <span className="text-muted-foreground text-xs">
                                  {ins.paid_at ? `Paga ${formatDateBr(ins.paid_at.slice(0, 10))}` : "—"}
                                </span>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <Card className="shadow-card overflow-hidden">
        {isLoading ? (
          <div className="p-8 text-center text-muted-foreground">A carregar…</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border bg-muted/30">
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Cliente</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Produto</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Valor</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Parcelas</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Status</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Gateway</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Ações</th>
                </tr>
              </thead>
              <tbody>
                {charges.map((charge) => (
                  <tr key={charge.id} className="border-b border-border last:border-0 hover:bg-muted/50">
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10">
                          <FileText className="h-4 w-4 text-primary" />
                        </div>
                        <span className="text-sm font-medium text-card-foreground">{charge.client_name}</span>
                      </div>
                    </td>
                    <td className="px-5 py-3.5 text-sm text-muted-foreground">{charge.product_name || charge.description}</td>
                    <td className="px-5 py-3.5 text-sm font-medium">{brl(Number(charge.total_amount))}</td>
                    <td className="px-5 py-3.5 text-sm text-muted-foreground">{charge.installments_count}x</td>
                    <td className="px-5 py-3.5">
                      <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusStyles[charge.status] ?? "bg-muted"}`}>
                        {statusLabel[charge.status] ?? charge.status}
                      </span>
                    </td>
                    <td className="px-5 py-3.5 text-sm text-muted-foreground capitalize">{charge.payment_gateway}</td>
                    <td className="px-5 py-3.5">
                      <div className="flex gap-1.5">
                        <Button type="button" variant="outline" size="sm" onClick={() => void openEdit(charge)}>
                          <Pencil className="h-3.5 w-3.5" />
                          Editar
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="text-destructive hover:text-destructive"
                          disabled={deleteMutation.isPending}
                          onClick={() => confirmDelete(charge)}
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                          Excluir
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {charges.length === 0 && <div className="p-8 text-center text-muted-foreground">Nenhuma cobrança com estes filtros.</div>}
          </div>
        )}
      </Card>
    </div>
  );
};

export default Charges;
