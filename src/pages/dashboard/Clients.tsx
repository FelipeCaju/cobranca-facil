import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Plus, Search, Phone, Mail, User, Pencil, Eye, Send } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { apiFetch } from "@/lib/api";
import { formatDateBr } from "@/lib/dates";
import { ImportSpreadsheet } from "@/components/ImportSpreadsheet";

interface Category {
  id: string;
  name: string;
}

interface ClientRow {
  id: string;
  name: string;
  email: string | null;
  phone: string | null;
  document: string | null;
  category_id: string | null;
  category_name: string | null;
  address_street: string | null;
  address_number: string | null;
  address_complement: string | null;
  address_neighborhood: string | null;
  address_city: string | null;
  address_state: string | null;
  address_postal_code: string | null;
  address_country: string | null;
  active_charges: number;
}

interface ClientBillingInstallment {
  id: string;
  charge_id: string;
  installment_number: number;
  amount: string;
  due_date: string;
  status: string;
  paid_at: string | null;
  external_id: string | null;
  charge_description: string;
  payment_gateway: string;
  charge_installments_count: number;
}

interface ClientBillingCharge {
  id: string;
  description: string;
  total_amount: string;
  installments_count: number;
  payment_gateway: string;
  status: string;
  product_name: string | null;
}

const emptyForm = {
  category_id: "" as string,
  name: "",
  email: "",
  phone: "",
  document: "",
  address_street: "",
  address_number: "",
  address_complement: "",
  address_neighborhood: "",
  address_city: "",
  address_state: "",
  address_postal_code: "",
  address_country: "Brasil",
};

const Clients = () => {
  const qc = useQueryClient();
  const { toast } = useToast();
  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState<string>("");
  const [open, setOpen] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [newCat, setNewCat] = useState("");
  const [form, setForm] = useState(emptyForm);
  const [page, setPage] = useState(1);
  const [viewOpen, setViewOpen] = useState(false);
  const [viewClientId, setViewClientId] = useState<string | null>(null);

  const { data: categories = [] } = useQuery({
    queryKey: ["client-categories"],
    queryFn: async () => {
      const r = await apiFetch<{ items: Category[] }>("/api/company/client-categories");
      return r.items;
    },
  });

  const { data: clientsData, isLoading } = useQuery({
    queryKey: ["clients", search, categoryFilter, page],
    queryFn: async () => {
      const q = new URLSearchParams();
      if (search.trim()) q.set("q", search.trim());
      if (categoryFilter) q.set("category_id", categoryFilter);
      q.set("page", String(page));
      q.set("limit", "15");
      const qs = q.toString();
      return apiFetch<{ items: ClientRow[]; total: number; total_pages: number; limit: number }>(
        `/api/company/clients?${qs}`,
      );
    },
  });
  const clients = clientsData?.items ?? [];
  const clientsTotal = clientsData?.total ?? 0;
  const clientsLimit = clientsData?.limit ?? 15;
  const clientsTotalPages = Math.max(1, clientsData?.total_pages ?? 1);

  const { data: viewClient } = useQuery({
    queryKey: ["client-detail", viewClientId],
    queryFn: async () => apiFetch<ClientRow & Record<string, unknown>>(`/api/company/clients/${viewClientId}`),
    enabled: Boolean(viewOpen && viewClientId),
  });

  const { data: viewBilling, isLoading: viewBillingLoading } = useQuery({
    queryKey: ["client-billing", viewClientId],
    queryFn: async () =>
      apiFetch<{ charges: ClientBillingCharge[]; installments: ClientBillingInstallment[] }>(
        `/api/company/clients/${viewClientId}/billing`,
      ),
    enabled: Boolean(viewOpen && viewClientId),
  });

  const markPaidMutation = useMutation({
    mutationFn: async (installmentId: string) =>
      apiFetch<{ ok: boolean; gateway_notified: boolean; gateway_detail: string }>(
        `/api/company/installments/${installmentId}/mark-paid`,
        { method: "POST", body: "{}" },
      ),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ["client-billing", viewClientId] });
      void qc.invalidateQueries({ queryKey: ["clients"] });
      void qc.invalidateQueries({ queryKey: ["company-installments"] });
      void qc.invalidateQueries({ queryKey: ["charges"] });
      void qc.invalidateQueries({ queryKey: ["company-overview"] });
      toast({
        title: "Baixa registada",
        description: res.gateway_detail || (res.gateway_notified ? "Gateway notificado." : undefined),
      });
    },
    onError: (e: Error) => toast({ title: "Erro", description: e.message, variant: "destructive" }),
  });

  const sendNowMutation = useMutation({
    mutationFn: async (installmentId: string) =>
      apiFetch<{ sent_whatsapp: number; sent_email: number; failed: number }>(
        `/api/company/installments/${installmentId}/send-now`,
        { method: "POST", body: "{}" },
      ),
    onSuccess: (res) => {
      toast({
        title: "Cobrança enviada",
        description: `WhatsApp: ${res.sent_whatsapp} · Email: ${res.sent_email}${res.failed ? ` · Falhas: ${res.failed}` : ""}`,
      });
    },
    onError: (e: Error) => toast({ title: "Erro ao enviar", description: e.message, variant: "destructive" }),
  });

  const addCategoryMutation = useMutation({
    mutationFn: async () => {
      const name = newCat.trim();
      if (!name) {
        throw new Error("Informe o nome da categoria");
      }
      await apiFetch("/api/company/client-categories", { method: "POST", body: JSON.stringify({ name }) });
    },
    onSuccess: () => {
      setNewCat("");
      void qc.invalidateQueries({ queryKey: ["client-categories"] });
      toast({ title: "Categoria criada" });
    },
    onError: (e: Error) => toast({ title: "Erro", description: e.message, variant: "destructive" }),
  });

  const saveMutation = useMutation({
    mutationFn: async () => {
      const body = {
        name: form.name.trim(),
        email: form.email.trim() || null,
        phone: form.phone.trim() || null,
        document: form.document.trim() || null,
        category_id: form.category_id || null,
        address_street: form.address_street.trim() || null,
        address_number: form.address_number.trim() || null,
        address_complement: form.address_complement.trim() || null,
        address_neighborhood: form.address_neighborhood.trim() || null,
        address_city: form.address_city.trim() || null,
        address_state: form.address_state.trim() || null,
        address_postal_code: form.address_postal_code.trim() || null,
        address_country: form.address_country.trim() || null,
      };
      if (editId) {
        await apiFetch(`/api/company/clients/${editId}`, { method: "PUT", body: JSON.stringify(body) });
      } else {
        await apiFetch("/api/company/clients", { method: "POST", body: JSON.stringify(body) });
      }
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["clients"] });
      void qc.invalidateQueries({ queryKey: ["company-overview"] });
      toast({ title: editId ? "Cliente atualizado" : "Cliente criado" });
      setOpen(false);
      setEditId(null);
      setForm(emptyForm);
    },
    onError: (e: Error) => toast({ title: "Erro", description: e.message, variant: "destructive" }),
  });

  const openNew = () => {
    setEditId(null);
    setForm(emptyForm);
    setOpen(true);
  };

  const openEdit = (c: ClientRow) => {
    setEditId(c.id);
    setForm({
      category_id: c.category_id ?? "",
      name: c.name,
      email: c.email ?? "",
      phone: c.phone ?? "",
      document: c.document ?? "",
      address_street: c.address_street ?? "",
      address_number: c.address_number ?? "",
      address_complement: c.address_complement ?? "",
      address_neighborhood: c.address_neighborhood ?? "",
      address_city: c.address_city ?? "",
      address_state: c.address_state ?? "",
      address_postal_code: c.address_postal_code ?? "",
      address_country: c.address_country ?? "Brasil",
    });
    setOpen(true);
  };

  const submitClient = () => {
    if (!form.name.trim()) {
      toast({ title: "Nome obrigatório", variant: "destructive" });
      return;
    }
    saveMutation.mutate();
  };

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-foreground">Clientes</h1>
        <div className="flex gap-2"><ImportSpreadsheet type="clients" onImported={() => void qc.invalidateQueries({ queryKey: ["clients"] })} /><Button variant="hero" size="sm" type="button" onClick={openNew}>
          <Plus className="h-4 w-4" />
          Novo Cliente
        </Button></div>
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-end mb-4">
        <div className="relative flex-1 max-w-md">
          <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Buscar por nome, email, documento, cidade…"
            value={search}
            onChange={(e) => {
              setPage(1);
              setSearch(e.target.value);
            }}
            className="pl-9"
          />
        </div>
        <div className="w-full sm:w-56 space-y-1">
          <Label className="text-xs text-muted-foreground">Categoria</Label>
          <Select
            value={categoryFilter || "__all__"}
            onValueChange={(v) => {
              setPage(1);
              setCategoryFilter(v === "__all__" ? "" : v);
            }}
          >
            <SelectTrigger>
              <SelectValue placeholder="Todas" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">Todas as categorias</SelectItem>
              {categories.map((c) => (
                <SelectItem key={c.id} value={c.id}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <Dialog
        open={viewOpen}
        onOpenChange={(v) => {
          setViewOpen(v);
          if (!v) setViewClientId(null);
        }}
      >
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Cliente e cobranças ativas</DialogTitle>
          </DialogHeader>
          {viewClient && (
            <div className="space-y-4 text-sm">
              <div className="rounded-lg border border-border bg-muted/20 p-4 space-y-1">
                <p className="font-semibold text-foreground">{viewClient.name}</p>
                <p className="text-muted-foreground">{viewClient.document || "—"}</p>
                <p className="text-muted-foreground">{viewClient.email || "—"} · {viewClient.phone || "—"}</p>
                <p className="text-xs text-muted-foreground">
                  {[viewClient.address_street, viewClient.address_number, viewClient.address_city, viewClient.address_state]
                    .filter(Boolean)
                    .join(", ") || "—"}
                </p>
              </div>
              <div>
                <p className="text-xs font-medium text-muted-foreground uppercase mb-2">Cobranças pendentes ou em atraso</p>
                {viewBillingLoading ? (
                  <p className="text-muted-foreground">A carregar…</p>
                ) : (viewBilling?.charges?.length ?? 0) === 0 ? (
                  <p className="text-muted-foreground">Nenhuma cobrança ativa.</p>
                ) : (
                  <ul className="space-y-2">
                    {(viewBilling?.charges ?? []).map((ch) => (
                      <li key={ch.id} className="rounded border border-border px-3 py-2 text-xs">
                        <span className="font-medium">{ch.product_name || ch.description}</span>
                        <span className="text-muted-foreground ml-2">
                          {Number(ch.total_amount).toLocaleString("pt-BR", { style: "currency", currency: "BRL" })} ·{" "}
                          {ch.installments_count}x · {ch.payment_gateway}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <div>
                <p className="text-xs font-medium text-muted-foreground uppercase mb-2">Parcelas em aberto (baixa manual)</p>
                {viewBillingLoading ? (
                  <p className="text-muted-foreground">A carregar…</p>
                ) : (viewBilling?.installments?.length ?? 0) === 0 ? (
                  <p className="text-muted-foreground">Nenhuma parcela pendente.</p>
                ) : (
                  <div className="space-y-2">
                    {(viewBilling?.installments ?? []).map((ins) => {
                      const totalI = Math.max(1, Number(ins.charge_installments_count) || 1);
                      return (
                        <div
                          key={ins.id}
                          className="flex flex-col gap-2 rounded border border-border px-3 py-2 sm:flex-row sm:items-center sm:justify-between"
                        >
                          <div className="text-xs">
                            <p className="font-medium text-foreground">{ins.charge_description}</p>
                            <p className="text-muted-foreground">
                              Parcela {ins.installment_number}/{totalI} · venc.{" "}
                              {formatDateBr(ins.due_date)} ·{" "}
                              {Number(ins.amount).toLocaleString("pt-BR", { style: "currency", currency: "BRL" })} ·{" "}
                              {ins.payment_gateway}
                            </p>
                          </div>
                          <div className="flex flex-wrap gap-2">
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={sendNowMutation.isPending}
                              onClick={() => sendNowMutation.mutate(ins.id)}
                            >
                              <Send className="h-3.5 w-3.5 mr-1.5" />
                              Enviar agora
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="secondary"
                              disabled={markPaidMutation.isPending}
                              onClick={() => markPaidMutation.mutate(ins.id)}
                            >
                              Dar baixa (notificar gateway)
                            </Button>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={open} onOpenChange={(v) => { setOpen(v); if (!v) { setEditId(null); setForm(emptyForm); } }}>
        <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editId ? "Editar cliente" : "Cadastrar cliente"}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 mt-2">
            <div className="space-y-2">
              <Label>Categoria</Label>
              <Select value={form.category_id || "__none__"} onValueChange={(v) => setForm((f) => ({ ...f, category_id: v === "__none__" ? "" : v }))}>
                <SelectTrigger>
                  <SelectValue placeholder="Opcional" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">Sem categoria</SelectItem>
                  {categories.map((c) => (
                    <SelectItem key={c.id} value={c.id}>
                      {c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex gap-2">
              <Input placeholder="Nova categoria" value={newCat} onChange={(e) => setNewCat(e.target.value)} />
              <Button type="button" variant="secondary" onClick={() => addCategoryMutation.mutate()} disabled={addCategoryMutation.isPending}>
                Adicionar
              </Button>
            </div>
            <div className="space-y-2">
              <Label>Nome completo *</Label>
              <Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required />
            </div>
            <div className="space-y-2">
              <Label>CPF/CNPJ</Label>
              <Input value={form.document} onChange={(e) => setForm((f) => ({ ...f, document: e.target.value }))} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Email</Label>
                <Input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} />
              </div>
              <div className="space-y-2">
                <Label>WhatsApp</Label>
                <Input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} />
              </div>
            </div>
            <p className="text-xs font-medium text-muted-foreground pt-2">Endereço</p>
            <div className="grid grid-cols-3 gap-2">
              <div className="col-span-2 space-y-2">
                <Label>Rua / logradouro</Label>
                <Input value={form.address_street} onChange={(e) => setForm((f) => ({ ...f, address_street: e.target.value }))} />
              </div>
              <div className="space-y-2">
                <Label>Nº</Label>
                <Input value={form.address_number} onChange={(e) => setForm((f) => ({ ...f, address_number: e.target.value }))} />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Complemento</Label>
              <Input value={form.address_complement} onChange={(e) => setForm((f) => ({ ...f, address_complement: e.target.value }))} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Bairro</Label>
                <Input value={form.address_neighborhood} onChange={(e) => setForm((f) => ({ ...f, address_neighborhood: e.target.value }))} />
              </div>
              <div className="space-y-2">
                <Label>Cidade</Label>
                <Input value={form.address_city} onChange={(e) => setForm((f) => ({ ...f, address_city: e.target.value }))} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>UF</Label>
                <Input value={form.address_state} onChange={(e) => setForm((f) => ({ ...f, address_state: e.target.value }))} maxLength={2} />
              </div>
              <div className="space-y-2">
                <Label>CEP</Label>
                <Input value={form.address_postal_code} onChange={(e) => setForm((f) => ({ ...f, address_postal_code: e.target.value }))} />
              </div>
            </div>
            <div className="space-y-2">
              <Label>País</Label>
              <Input value={form.address_country} onChange={(e) => setForm((f) => ({ ...f, address_country: e.target.value }))} />
            </div>
            <Button type="button" variant="hero" className="w-full" disabled={saveMutation.isPending} onClick={submitClient}>
              {saveMutation.isPending ? "A guardar…" : "Guardar"}
            </Button>
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
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Categoria</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Local</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Contato</th>
                  <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Cobranças</th>
                  <th className="px-5 py-3 text-right text-xs font-medium text-muted-foreground uppercase">Ações</th>
                </tr>
              </thead>
              <tbody>
                {clients.map((client) => (
                  <tr key={client.id} className="border-b border-border last:border-0 hover:bg-muted/50">
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10">
                          <User className="h-4 w-4 text-primary" />
                        </div>
                        <div>
                          <span className="text-sm font-medium text-card-foreground block">{client.name}</span>
                          <span className="text-xs text-muted-foreground">{client.document || "—"}</span>
                        </div>
                      </div>
                    </td>
                    <td className="px-5 py-3.5 text-sm text-muted-foreground">{client.category_name || "—"}</td>
                    <td className="px-5 py-3.5 text-xs text-muted-foreground max-w-[180px]">
                      {[client.address_city, client.address_state].filter(Boolean).join("/") || "—"}
                    </td>
                    <td className="px-5 py-3.5">
                      <div className="space-y-1">
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                          <Mail className="h-3 w-3 shrink-0" /> {client.email || "—"}
                        </div>
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                          <Phone className="h-3 w-3 shrink-0" /> {client.phone || "—"}
                        </div>
                      </div>
                    </td>
                    <td className="px-5 py-3.5">
                      <span className="inline-flex items-center rounded-full bg-accent/10 px-2.5 py-0.5 text-xs font-medium text-accent">
                        {client.active_charges} ativas
                      </span>
                    </td>
                    <td className="px-5 py-3.5 text-right space-x-2">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          setViewClientId(client.id);
                          setViewOpen(true);
                        }}
                      >
                        <Eye className="h-3 w-3 sm:mr-1" />
                        <span className="hidden sm:inline">Ver</span>
                      </Button>
                      <Button type="button" size="sm" variant="outline" onClick={() => openEdit(client)}>
                        <Pencil className="h-3 w-3" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {clients.length === 0 && <div className="p-8 text-center text-muted-foreground">Nenhum cliente encontrado.</div>}
          </div>
        )}
        {clientsTotal > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-4 text-sm text-muted-foreground">
            <span>
              Mostrando {clients.length ? (page - 1) * clientsLimit + 1 : 0}–{(page - 1) * clientsLimit + clients.length} de{" "}
              {clientsTotal}
            </span>
            <div className="flex gap-2">
              <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                Anterior
              </Button>
              <span className="flex items-center px-2 tabular-nums">
                Página {page} de {clientsTotalPages}
              </span>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={page >= clientsTotalPages}
                onClick={() => setPage((p) => p + 1)}
              >
                Seguinte
              </Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  );
};

export default Clients;
