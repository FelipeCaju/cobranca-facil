import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { DateInput } from "@/components/ui/date-input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { useToast } from "@/hooks/use-toast";
import { apiFetch } from "@/lib/api";
import { formatDateBr } from "@/lib/dates";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Ban, Building2, Pencil, Plus, RefreshCcw, ShieldCheck, Trash2 } from "lucide-react";

export interface AdminCompanyRow {
  id: string;
  name: string;
  email: string;
  phone: string;
  cnpj: string;
  is_active: boolean;
  plan_id: string | null;
  plan_name: string;
  plan_duration_months: number;
  plan_renews_at: string | null;
  subscription_status: "active" | "past_due" | "renewing_soon" | "trialing" | "inactive" | "no_plan";
  subscription_label: string;
  subscription_days_remaining: number | null;
  subscription_is_blocked: boolean;
  owner_email: string;
  owner_full_name: string;
  charges_count: number;
  has_used_system: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

interface PlanOpt {
  id: string;
  name: string;
  duration_months?: number;
}

type SubscriptionAction = "renew" | "trial" | "block";

type CreateCompanyForm = {
  name: string;
  email: string;
  phone: string;
  cnpj: string;
  is_active: boolean;
  plan_id: string;
  plan_renews_at: string;
  owner_full_name: string;
  owner_email: string;
  owner_password: string;
};

const emptyCreateForm = (): CreateCompanyForm => ({
  name: "",
  email: "",
  phone: "",
  cnpj: "",
  is_active: true,
  plan_id: "",
  plan_renews_at: "",
  owner_full_name: "",
  owner_email: "",
  owner_password: "",
});

const subscriptionBadgeClass: Record<AdminCompanyRow["subscription_status"], string> = {
  active: "bg-success/15 text-success border-0",
  renewing_soon: "bg-warning/15 text-warning border-0",
  trialing: "bg-primary/15 text-primary border-0",
  past_due: "bg-destructive/15 text-destructive border-0",
  inactive: "bg-muted text-muted-foreground border-0",
  no_plan: "bg-muted text-muted-foreground border-0",
};

const formatDaysRemaining = (days: number | null) => {
  if (days === null) return "Sem data";
  if (days <= 0) return "0 dias";
  return days === 1 ? "1 dia" : `${days} dias`;
};

const formatSubscriptionSummary = (row: Pick<AdminCompanyRow, "subscription_label" | "subscription_days_remaining">) => {
  if (row.subscription_days_remaining === null) {
    return `${row.subscription_label} · sem data de renovação`;
  }

  return `${row.subscription_label} · ${formatDaysRemaining(row.subscription_days_remaining)} restantes`;
};

const AdminCompanies = () => {
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [createOpen, setCreateOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [editing, setEditing] = useState<AdminCompanyRow | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<AdminCompanyRow | null>(null);
  const [createForm, setCreateForm] = useState<CreateCompanyForm>(emptyCreateForm);
  const [form, setForm] = useState({
    name: "",
    email: "",
    phone: "",
    cnpj: "",
    is_active: true,
    plan_id: "",
    plan_renews_at: "",
    owner_email: "",
    owner_new_password: "",
  });

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["admin-companies"],
    queryFn: () => apiFetch<{ items: AdminCompanyRow[] }>("/api/admin/companies"),
  });

  const { data: plansData } = useQuery({
    queryKey: ["admin-plans"],
    queryFn: () => apiFetch<{ items: PlanOpt[] }>("/api/admin/plans"),
  });

  const createMutation = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      apiFetch<AdminCompanyRow>("/api/admin/companies", {
        method: "POST",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-companies"] });
      setCreateOpen(false);
      setCreateForm(emptyCreateForm());
      toast({ title: "Empresa criada", description: "O utilizador dono jÃ¡ pode entrar com o email e senha cadastrados." });
    },
    onError: (e: Error) => toast({ variant: "destructive", title: "Erro ao criar", description: e.message }),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) =>
      apiFetch<{ ok: boolean }>(`/api/admin/companies/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-companies"] });
      setDeleteTarget(null);
      toast({ title: "Empresa eliminada", description: "Todos os dados associados foram removidos." });
    },
    onError: (e: Error) => toast({ variant: "destructive", title: "Erro ao eliminar", description: e.message }),
  });

  const updateMutation = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      apiFetch<AdminCompanyRow>(`/api/admin/companies/${editing?.id}`, {
        method: "PUT",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-companies"] });
      setEditOpen(false);
      setEditing(null);
      toast({ title: "Empresa atualizada" });
    },
    onError: (e: Error) => toast({ variant: "destructive", title: "Erro", description: e.message }),
  });

  const subscriptionMutation = useMutation({
    mutationFn: ({ id, action }: { id: string; action: SubscriptionAction }) =>
      apiFetch<AdminCompanyRow>(`/api/admin/companies/${id}/subscription`, {
        method: "POST",
        body: JSON.stringify({ action }),
      }),
    onSuccess: (row, vars) => {
      void queryClient.invalidateQueries({ queryKey: ["admin-companies"] });
      setEditing(row);
      setForm((f) => ({
        ...f,
        plan_renews_at: row.plan_renews_at ? String(row.plan_renews_at).slice(0, 10) : "",
      }));
      const labels: Record<SubscriptionAction, string> = {
        renew: "Assinatura renovada",
        trial: "Teste liberado",
        block: "Empresa bloqueada",
      };
      toast({ title: labels[vars.action] });
    },
    onError: (e: Error) => toast({ variant: "destructive", title: "Erro na assinatura", description: e.message }),
  });

  const openEdit = (row: AdminCompanyRow) => {
    setEditing(row);
    setForm({
      name: row.name,
      email: row.email,
      phone: row.phone,
      cnpj: row.cnpj,
      is_active: row.is_active,
      plan_id: row.plan_id ?? "",
      plan_renews_at: row.plan_renews_at ? String(row.plan_renews_at).slice(0, 10) : "",
      owner_email: row.owner_email,
      owner_new_password: "",
    });
    setEditOpen(true);
  };

  const submitCreate = (e: React.FormEvent) => {
    e.preventDefault();
    const body: Record<string, unknown> = {
      name: createForm.name.trim(),
      email: createForm.email.trim(),
      phone: createForm.phone.trim(),
      cnpj: createForm.cnpj.trim(),
      is_active: createForm.is_active,
      plan_id: createForm.plan_id.trim() || null,
      plan_renews_at: createForm.plan_renews_at.trim(),
      owner_full_name: createForm.owner_full_name.trim(),
      owner_email: createForm.owner_email.trim(),
      owner_password: createForm.owner_password,
    };
    if (!String(body.name).trim()) {
      toast({ variant: "destructive", title: "Nome da empresa obrigatÃ³rio" });
      return;
    }
    if (!String(body.owner_email).trim()) {
      toast({ variant: "destructive", title: "Email do dono obrigatÃ³rio" });
      return;
    }
    if (createForm.owner_password.trim().length < 6) {
      toast({ variant: "destructive", title: "Senha invÃ¡lida", description: "Informe pelo menos 6 caracteres." });
      return;
    }
    createMutation.mutate(body);
  };

  const submitEdit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editing) return;
    const body: Record<string, unknown> = {
      name: form.name.trim(),
      email: form.email.trim(),
      phone: form.phone.trim(),
      cnpj: form.cnpj.trim(),
      is_active: form.is_active,
      plan_id: form.plan_id.trim() || null,
      plan_renews_at: form.plan_renews_at.trim(),
      owner_email: form.owner_email.trim(),
    };
    if (form.owner_new_password.trim() !== "") {
      body.owner_new_password = form.owner_new_password;
    }
    updateMutation.mutate(body);
  };

  return (
    <div>
        <div className="flex flex-col gap-4 mb-2 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
            <Building2 className="h-5 w-5 text-primary" />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-foreground">Clientes</h1>
            <p className="text-sm text-muted-foreground">
              Empresas que utilizam ou já utilizaram o sistema. Renovação do plano, estado da conta e redefinição de dados do dono.
            </p>
          </div>
          </div>
          <Button type="button" variant="hero" size="sm" onClick={() => setCreateOpen(true)} className="shrink-0">
            <Plus className="h-4 w-4" />
            Novo
          </Button>
        </div>

        <Dialog
          open={createOpen}
          onOpenChange={(open) => {
            setCreateOpen(open);
            if (!open) setCreateForm(emptyCreateForm());
          }}
        >
          <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
            <DialogHeader>
              <DialogTitle>Nova empresa</DialogTitle>
            </DialogHeader>
            <form onSubmit={submitCreate} className="space-y-4 mt-2">
              <div className="space-y-2">
                <Label htmlFor="new-name">Nome da empresa</Label>
                <Input id="new-name" value={createForm.name} onChange={(e) => setCreateForm((f) => ({ ...f, name: e.target.value }))} required />
              </div>
              <div className="space-y-2">
                <Label htmlFor="new-email">Email da empresa</Label>
                <Input id="new-email" type="email" value={createForm.email} onChange={(e) => setCreateForm((f) => ({ ...f, email: e.target.value }))} />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="new-phone">Telefone</Label>
                  <Input id="new-phone" value={createForm.phone} onChange={(e) => setCreateForm((f) => ({ ...f, phone: e.target.value }))} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="new-cnpj">CNPJ</Label>
                  <Input id="new-cnpj" value={createForm.cnpj} onChange={(e) => setCreateForm((f) => ({ ...f, cnpj: e.target.value }))} />
                </div>
              </div>
              <div className="flex items-center justify-between rounded-lg border border-border px-3 py-2">
                <Label htmlFor="new-active">Conta ativa</Label>
                <Switch id="new-active" checked={createForm.is_active} onCheckedChange={(v) => setCreateForm((f) => ({ ...f, is_active: v }))} />
              </div>
              <div className="space-y-2">
                <Label>Plano</Label>
                <Select value={createForm.plan_id || "__none__"} onValueChange={(v) => setCreateForm((f) => ({ ...f, plan_id: v === "__none__" ? "" : v }))}>
                  <SelectTrigger>
                    <SelectValue placeholder="Sem plano" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">Sem plano</SelectItem>
                    {(plansData?.items ?? []).map((p) => (
                      <SelectItem key={p.id} value={p.id}>
                        {p.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="new-renew">Data de renovaÃ§Ã£o do plano</Label>
                <DateInput id="new-renew" value={createForm.plan_renews_at} onChange={(iso) => setCreateForm((f) => ({ ...f, plan_renews_at: iso }))} />
                <p className="text-xs text-muted-foreground">Se deixar vazio e escolher um plano, o sistema libera 3 dias de teste.</p>
              </div>
              <div className="space-y-2 border-t border-border pt-4">
                <Label htmlFor="new-owner-name">Nome do dono</Label>
                <Input
                  id="new-owner-name"
                  value={createForm.owner_full_name}
                  onChange={(e) => setCreateForm((f) => ({ ...f, owner_full_name: e.target.value }))}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="new-owner-email">Email do dono (login)</Label>
                <Input
                  id="new-owner-email"
                  type="email"
                  value={createForm.owner_email}
                  onChange={(e) => setCreateForm((f) => ({ ...f, owner_email: e.target.value }))}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="new-owner-pass">Senha do dono</Label>
                <Input
                  id="new-owner-pass"
                  type="password"
                  autoComplete="new-password"
                  value={createForm.owner_password}
                  onChange={(e) => setCreateForm((f) => ({ ...f, owner_password: e.target.value }))}
                  required
                />
              </div>
              <Button type="submit" variant="hero" className="w-full" disabled={createMutation.isPending}>
                {createMutation.isPending ? "A criarâ€¦" : "Criar empresa"}
              </Button>
            </form>
          </DialogContent>
        </Dialog>

        <AlertDialog open={deleteTarget !== null} onOpenChange={(open) => !open && setDeleteTarget(null)}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Eliminar empresa?</AlertDialogTitle>
              <AlertDialogDescription>
                A empresa <strong>{deleteTarget?.name}</strong> e todos os dados associados (clientes, cobranças,
                parcelas, templates, ligação WhatsApp da empresa e conta do dono) serão apagados permanentemente. Esta
                ação não pode ser desfeita.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel disabled={deleteMutation.isPending}>Cancelar</AlertDialogCancel>
              <AlertDialogAction
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                disabled={deleteMutation.isPending}
                onClick={(e) => {
                  e.preventDefault();
                  if (deleteTarget) deleteMutation.mutate(deleteTarget.id);
                }}
              >
                {deleteMutation.isPending ? "A eliminar…" : "Eliminar tudo"}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>

        <Dialog
          open={editOpen}
          onOpenChange={(open) => {
            setEditOpen(open);
            if (!open) setEditing(null);
          }}
        >
          <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
            <DialogHeader>
              <DialogTitle>Editar empresa</DialogTitle>
            </DialogHeader>
            {editing ? (
              <form onSubmit={submitEdit} className="space-y-4 mt-2">
                <div className="space-y-2">
                  <Label htmlFor="co-name">Nome da empresa</Label>
                  <Input id="co-name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="co-email">Email da empresa</Label>
                  <Input id="co-email" type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="co-phone">Telefone</Label>
                    <Input id="co-phone" value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="co-cnpj">CNPJ</Label>
                    <Input id="co-cnpj" value={form.cnpj} onChange={(e) => setForm((f) => ({ ...f, cnpj: e.target.value }))} />
                  </div>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-border px-3 py-2">
                  <Label htmlFor="co-active">Conta ativa</Label>
                  <Switch id="co-active" checked={form.is_active} onCheckedChange={(v) => setForm((f) => ({ ...f, is_active: v }))} />
                </div>
                <div className="space-y-2">
                  <Label>Plano</Label>
                  <Select value={form.plan_id || "__none__"} onValueChange={(v) => setForm((f) => ({ ...f, plan_id: v === "__none__" ? "" : v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Sem plano" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="__none__">Sem plano</SelectItem>
                      {(plansData?.items ?? []).map((p) => (
                        <SelectItem key={p.id} value={p.id}>
                          {p.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="co-renew">Data de renovação do plano</Label>
                  <DateInput id="co-renew" value={form.plan_renews_at} onChange={(iso) => setForm((f) => ({ ...f, plan_renews_at: iso }))} />
                  <p className="text-xs text-muted-foreground">Defina a próxima data de renovação / faturação do plano.</p>
                </div>
                <div className="rounded-lg border border-border p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <p className="text-sm font-medium text-foreground">Assinatura</p>
                      <p className="text-xs text-muted-foreground">
                        {formatSubscriptionSummary(editing)}
                      </p>
                    </div>
                    <Badge className={subscriptionBadgeClass[editing.subscription_status]}>
                      {editing.subscription_is_blocked ? "Bloqueada" : editing.subscription_label}
                    </Badge>
                  </div>
                  <div className="mt-3 grid gap-2 sm:grid-cols-3">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={subscriptionMutation.isPending || !form.plan_id}
                      onClick={() => subscriptionMutation.mutate({ id: editing.id, action: "renew" })}
                    >
                      <RefreshCcw className="h-4 w-4" />
                      Renovar
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={subscriptionMutation.isPending || !form.plan_id}
                      onClick={() => subscriptionMutation.mutate({ id: editing.id, action: "trial" })}
                    >
                      <ShieldCheck className="h-4 w-4" />
                      Teste 3 dias
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="text-destructive hover:text-destructive"
                      disabled={subscriptionMutation.isPending}
                      onClick={() => subscriptionMutation.mutate({ id: editing.id, action: "block" })}
                    >
                      <Ban className="h-4 w-4" />
                      Bloquear
                    </Button>
                  </div>
                </div>
                <div className="space-y-2 border-t border-border pt-4">
                  <Label htmlFor="co-owner-email">Email do dono (login)</Label>
                  <Input
                    id="co-owner-email"
                    type="email"
                    value={form.owner_email}
                    onChange={(e) => setForm((f) => ({ ...f, owner_email: e.target.value }))}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="co-owner-pass">Nova palavra-passe do dono (opcional)</Label>
                  <Input
                    id="co-owner-pass"
                    type="password"
                    autoComplete="new-password"
                    value={form.owner_new_password}
                    onChange={(e) => setForm((f) => ({ ...f, owner_new_password: e.target.value }))}
                    placeholder="Deixe vazio para não alterar"
                  />
                </div>
                <Button type="submit" variant="hero" className="w-full" disabled={updateMutation.isPending}>
                  {updateMutation.isPending ? "A guardar…" : "Guardar alterações"}
                </Button>
              </form>
            ) : null}
          </DialogContent>
        </Dialog>

        {isLoading ? (
          <p className="text-muted-foreground mt-6">A carregar…</p>
        ) : isError ? (
          <Card className="p-6 mt-6 text-destructive">{error instanceof Error ? error.message : "Erro"}</Card>
        ) : (
          <Card className="shadow-card mt-6 overflow-hidden">
            <div className="overflow-x-auto">
              <table className="min-w-[860px] w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/30">
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Empresa</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Dono</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Plano</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Estado</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Uso</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Renovação</th>
                    <th className="px-4 py-3 text-right font-medium text-muted-foreground"> </th>
                  </tr>
                </thead>
                <tbody>
                  {(data?.items ?? []).length === 0 ? (
                    <tr>
                      <td colSpan={7} className="px-4 py-10 text-center text-muted-foreground">
                        Nenhuma empresa registada.
                      </td>
                    </tr>
                  ) : (
                    (data?.items ?? []).map((row) => (
                      <tr key={row.id} className="border-b border-border last:border-0 hover:bg-muted/40">
                        <td className="px-4 py-3 min-w-[210px]">
                          <p className="font-medium text-foreground">{row.name}</p>
                          <p className="text-xs text-muted-foreground">{row.email || "—"}</p>
                        </td>
                        <td className="px-4 py-3 min-w-[220px]">
                          <p className="text-foreground">{row.owner_email}</p>
                          <p className="text-xs text-muted-foreground">{row.owner_full_name || "—"}</p>
                        </td>
                        <td className="px-4 py-3 min-w-[150px] text-muted-foreground">{row.plan_name || "—"}</td>
                        <td className="px-4 py-3 min-w-[150px]">
                          <div className="space-y-1">
                            <Badge className={subscriptionBadgeClass[row.subscription_status]}>
                              {row.subscription_is_blocked ? "Bloqueada" : row.subscription_label}
                            </Badge>
                            <p className="text-xs text-muted-foreground">
                              {formatDaysRemaining(row.subscription_days_remaining)}
                            </p>
                          </div>
                        </td>
                        <td className="px-4 py-3 min-w-[150px]">
                          {row.has_used_system ? (
                            <Badge variant="outline" className="font-normal">
                              Com cobranças ({row.charges_count})
                            </Badge>
                          ) : (
                            <span className="text-muted-foreground">Sem cobranças</span>
                          )}
                        </td>
                        <td className="px-4 py-3 min-w-[120px] text-muted-foreground">{formatDateBr(row.plan_renews_at)}</td>
                        <td className="px-4 py-3 text-right min-w-[96px]">
                          <div className="flex items-center justify-end gap-1">
                            <Button type="button" variant="ghost" size="icon" onClick={() => openEdit(row)} aria-label="Editar">
                              <Pencil className="h-4 w-4" />
                            </Button>
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              className="text-destructive hover:text-destructive"
                              onClick={() => setDeleteTarget(row)}
                              aria-label="Eliminar empresa"
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </Card>
        )}
    </div>
  );
};

export default AdminCompanies;
