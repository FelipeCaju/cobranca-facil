import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { DateInput } from "@/components/ui/date-input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Copy, ExternalLink, Search, X } from "lucide-react";
import { apiFetch, getStoredToken } from "@/lib/api";
import { formatDateBr } from "@/lib/dates";
import { useAuth } from "@/hooks/useAuth";

interface InstallmentRow {
  id: string;
  charge_id: string;
  installment_number: number;
  amount: string;
  due_date: string;
  status: string;
  paid_at: string | null;
  external_id?: string | null;
  payment_url?: string | null;
  pix_qrcode?: string | null;
  pix_copy_paste?: string | null;
  created_at?: string;
  charge_description: string;
  charge_installments_count: number;
  client_name: string;
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

const brl = (n: number) =>
  n.toLocaleString("pt-BR", { style: "currency", currency: "BRL", minimumFractionDigits: 2, maximumFractionDigits: 2 });

const Installments = () => {
  const { loading: authLoading, session, user } = useAuth();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<string>("");
  const [dueFrom, setDueFrom] = useState("");
  const [dueTo, setDueTo] = useState("");
  const [page, setPage] = useState(1);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["company-installments", search, status, dueFrom, dueTo, page],
    queryFn: async () => {
      const q = new URLSearchParams();
      if (search.trim()) q.set("q", search.trim());
      if (status) q.set("status", status);
      if (dueFrom) q.set("due_from", dueFrom);
      if (dueTo) q.set("due_to", dueTo);
      q.set("page", String(page));
      q.set("limit", "15");
      const qs = q.toString();
      return apiFetch<{ items: InstallmentRow[]; total: number; total_pages: number; limit: number }>(
        `/api/company/installments?${qs}`,
      );
    },
    enabled: !authLoading && Boolean(session ?? getStoredToken()) && Boolean(user?.company_id),
  });

  const clearFilters = () => {
    setSearch("");
    setStatus("");
    setDueFrom("");
    setDueTo("");
    setPage(1);
  };

  const copyText = async (text: string) => {
    await navigator.clipboard.writeText(text);
  };

  const items = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = Math.max(1, data?.total_pages ?? 1);
  const limit = data?.limit ?? 15;

  return (
    <div>
      <h1 className="text-2xl font-bold text-foreground mb-6">Parcelas</h1>

      <Card className="shadow-card p-4 mb-6">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 sm:items-end">
          <div className="space-y-2 sm:col-span-2">
            <Label htmlFor="inst-q">Pesquisar</Label>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                id="inst-q"
                className="pl-9"
                placeholder="Cliente ou descrição da cobrança…"
                value={search}
                onChange={(e) => {
                  setPage(1);
                  setSearch(e.target.value);
                }}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label>Status</Label>
            <Select
              value={status || "__all__"}
              onValueChange={(v) => {
                setPage(1);
                setStatus(v === "__all__" ? "" : v);
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">Todos</SelectItem>
                <SelectItem value="pending">Pendente</SelectItem>
                <SelectItem value="paid">Paga</SelectItem>
                <SelectItem value="overdue">Atrasada</SelectItem>
                <SelectItem value="cancelled">Cancelada</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor="due-from">Vencimento de</Label>
            <DateInput
              id="due-from"
              value={dueFrom}
              onChange={(iso) => {
                setPage(1);
                setDueFrom(iso);
              }}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="due-to">Vencimento até</Label>
            <DateInput
              id="due-to"
              value={dueTo}
              onChange={(iso) => {
                setPage(1);
                setDueTo(iso);
              }}
            />
          </div>
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          <Button type="button" variant="outline" size="sm" onClick={clearFilters}>
            <X className="h-4 w-4 mr-1.5" />
            Limpar filtros
          </Button>
        </div>
      </Card>

      <Card className="shadow-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-border bg-muted/30">
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Cliente</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Cobrança</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Parcela</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Valor</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Vencimento</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Pagamento</th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                <tr>
                  <td colSpan={7} className="px-5 py-10 text-center text-sm text-muted-foreground">
                    A carregar parcelas…
                  </td>
                </tr>
              ) : isError ? (
                <tr>
                  <td colSpan={7} className="px-5 py-10 text-center text-sm text-destructive">
                    {error instanceof Error ? error.message : "Erro ao carregar."}
                  </td>
                </tr>
              ) : items.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-5 py-10 text-center text-sm text-muted-foreground">
                    Nenhuma parcela encontrada com estes filtros. Ajuste a pesquisa ou crie cobranças com parcelas.
                  </td>
                </tr>
              ) : (
                items.map((inst) => {
                  const st = inst.status;
                  const pt = statusLabel[st] ?? st;
                  const totalInst = Math.max(1, Number(inst.charge_installments_count) || 1);
                  const label = `${inst.installment_number}/${totalInst}`;
                  return (
                    <tr key={inst.id} className="border-b border-border last:border-0 hover:bg-muted/50 transition-colors">
                      <td className="px-5 py-3.5 text-sm font-medium text-card-foreground">{inst.client_name}</td>
                      <td className="px-5 py-3.5 text-sm text-muted-foreground">{inst.charge_description}</td>
                      <td className="px-5 py-3.5 text-sm text-card-foreground font-medium">{label}</td>
                      <td className="px-5 py-3.5 text-sm font-medium text-card-foreground">{brl(Number(inst.amount))}</td>
                      <td className="px-5 py-3.5 text-sm text-muted-foreground">{formatDateBr(inst.due_date)}</td>
                      <td className="px-5 py-3.5">
                        <Badge className={`font-medium border-0 ${statusStyles[st] ?? "bg-muted"}`}>{pt}</Badge>
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex flex-wrap gap-1.5">
                          {inst.payment_url ? (
                            <Button type="button" size="sm" variant="outline" asChild>
                              <a href={inst.payment_url} target="_blank" rel="noreferrer">
                                <ExternalLink className="h-3.5 w-3.5" />
                                Link
                              </a>
                            </Button>
                          ) : null}
                          {inst.pix_copy_paste ? (
                            <Button type="button" size="sm" variant="outline" onClick={() => void copyText(inst.pix_copy_paste || "")}>
                              <Copy className="h-3.5 w-3.5" />
                              Pix
                            </Button>
                          ) : null}
                          {!inst.payment_url && !inst.pix_copy_paste ? <span className="text-xs text-muted-foreground">Nao gerado</span> : null}
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        {total > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-4 text-sm text-muted-foreground">
            <span>
              Mostrando {items.length ? (page - 1) * limit + 1 : 0}–{(page - 1) * limit + items.length} de {total}
            </span>
            <div className="flex gap-2">
              <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                Anterior
              </Button>
              <span className="flex items-center px-2 tabular-nums">
                Página {page} de {totalPages}
              </span>
              <Button type="button" variant="outline" size="sm" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>
                Seguinte
              </Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  );
};

export default Installments;
