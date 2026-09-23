import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
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
import { Plus, Edit, Trash2, CalendarDays } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { apiFetch } from "@/lib/api";
import { Badge } from "@/components/ui/badge";

export interface AdminPlanRow {
  id: string;
  name: string;
  price: number;
  charges_limit: number;
  duration_months: number;
  users_limit: number;
  is_active: number;
  companies_count: number;
  created_at?: string | null;
  updated_at?: string | null;
}

const brl = (n: number) =>
  n.toLocaleString("pt-BR", { style: "currency", currency: "BRL", minimumFractionDigits: 2, maximumFractionDigits: 2 });

const planMonths = (raw: number) => {
  if (raw > 60) {
    if (raw <= 100) return 1;
    if (raw <= 1000) return 2;
    return 12;
  }
  return Math.max(1, Math.min(60, raw));
};

const formatMonths = (n: number) => {
  const months = planMonths(n);
  return months === 1 ? "1 mês de acesso" : `${months.toLocaleString("pt-BR")} meses de acesso`;
};

type PlanFormState = {
  name: string;
  price: string;
  duration_months: string;
  is_active: boolean;
};

const emptyForm = (): PlanFormState => ({
  name: "",
  price: "",
  duration_months: "1",
  is_active: true,
});

const rowToForm = (p: AdminPlanRow): PlanFormState => ({
  name: p.name,
  price: String(p.price),
  duration_months: String(p.duration_months || planMonths(p.charges_limit)),
  is_active: p.is_active === 1,
});

const parseBody = (f: PlanFormState) => {
  const price = Number(String(f.price).replace(",", "."));
  const duration_months = Math.floor(Number(f.duration_months));
  return {
    name: f.name.trim(),
    price,
    duration_months,
    is_active: f.is_active,
  };
};

const AdminPlans = () => {
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [createOpen, setCreateOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [editing, setEditing] = useState<AdminPlanRow | null>(null);
  const [createForm, setCreateForm] = useState<PlanFormState>(emptyForm);
  const [editForm, setEditForm] = useState<PlanFormState>(emptyForm);
  const [deleteTarget, setDeleteTarget] = useState<AdminPlanRow | null>(null);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["admin-plans"],
    queryFn: () => apiFetch<{ items: AdminPlanRow[] }>("/api/admin/plans"),
  });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ["admin-plans"] });

  const createMutation = useMutation({
    mutationFn: (body: ReturnType<typeof parseBody>) =>
      apiFetch<AdminPlanRow>("/api/admin/plans", { method: "POST", body: JSON.stringify(body) }),
    onSuccess: () => {
      invalidate();
      setCreateOpen(false);
      setCreateForm(emptyForm());
      toast({ title: "Plano criado" });
    },
    onError: (e: Error) => toast({ variant: "destructive", title: "Erro", description: e.message }),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, body }: { id: string; body: ReturnType<typeof parseBody> }) =>
      apiFetch<AdminPlanRow>(`/api/admin/plans/${id}`, { method: "PUT", body: JSON.stringify(body) }),
    onSuccess: () => {
      invalidate();
      setEditOpen(false);
      setEditing(null);
      toast({ title: "Plano atualizado" });
    },
    onError: (e: Error) => toast({ variant: "destructive", title: "Erro", description: e.message }),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => apiFetch<{ ok: boolean }>(`/api/admin/plans/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      invalidate();
      setDeleteTarget(null);
      toast({ title: "Plano removido" });
    },
    onError: (e: Error) => toast({ variant: "destructive", title: "Erro", description: e.message }),
  });

  const submitCreate = (e: React.FormEvent) => {
    e.preventDefault();
    const body = parseBody(createForm);
    if (!body.name) {
      toast({ variant: "destructive", title: "Nome obrigatório" });
      return;
    }
    if (!Number.isFinite(body.price) || body.price < 0) {
      toast({ variant: "destructive", title: "Preço inválido" });
      return;
    }
    if (!Number.isFinite(body.duration_months) || body.duration_months < 1 || body.duration_months > 60) {
      toast({ variant: "destructive", title: "Quantidade de meses inválida" });
      return;
    }
    createMutation.mutate(body);
  };

  const submitEdit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editing) return;
    const body = parseBody(editForm);
    if (!body.name) {
      toast({ variant: "destructive", title: "Nome obrigatório" });
      return;
    }
    if (!Number.isFinite(body.price) || body.price < 0) {
      toast({ variant: "destructive", title: "Preço inválido" });
      return;
    }
    if (!Number.isFinite(body.duration_months) || body.duration_months < 1 || body.duration_months > 60) {
      toast({ variant: "destructive", title: "Quantidade de meses inválida" });
      return;
    }
    updateMutation.mutate({ id: editing.id, body });
  };

  const openEdit = (plan: AdminPlanRow) => {
    setEditing(plan);
    setEditForm(rowToForm(plan));
    setEditOpen(true);
  };

  return (
    <div>
        <div className="flex items-center justify-between mb-6">
          <h1 className="text-2xl font-bold text-foreground">Planos (Admin)</h1>
          <Dialog open={createOpen} onOpenChange={setCreateOpen}>
            <DialogTrigger asChild>
              <Button variant="hero" size="sm">
                <Plus className="h-4 w-4" />
                Novo plano
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Criar plano</DialogTitle>
              </DialogHeader>
              <form onSubmit={submitCreate} className="space-y-4 mt-2">
                <div className="space-y-2">
                  <Label htmlFor="create-name">Nome</Label>
                  <Input
                    id="create-name"
                    value={createForm.name}
                    onChange={(e) => setCreateForm((s) => ({ ...s, name: e.target.value }))}
                    placeholder="Ex.: Premium"
                    required
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="create-price">Preço (R$)</Label>
                    <Input
                      id="create-price"
                      type="text"
                      inputMode="decimal"
                      value={createForm.price}
                      onChange={(e) => setCreateForm((s) => ({ ...s, price: e.target.value }))}
                      placeholder="49,90"
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="create-duration">Quantidade de meses</Label>
                    <Input
                      id="create-duration"
                      type="number"
                      min={1}
                      max={60}
                      value={createForm.duration_months}
                      onChange={(e) => setCreateForm((s) => ({ ...s, duration_months: e.target.value }))}
                      required
                    />
                  </div>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-border px-3 py-2">
                  <Label htmlFor="create-active" className="cursor-pointer">
                    Plano ativo
                  </Label>
                  <Switch
                    id="create-active"
                    checked={createForm.is_active}
                    onCheckedChange={(v) => setCreateForm((s) => ({ ...s, is_active: v }))}
                  />
                </div>
                <Button type="submit" variant="hero" className="w-full" disabled={createMutation.isPending}>
                  {createMutation.isPending ? "A guardar…" : "Criar plano"}
                </Button>
              </form>
            </DialogContent>
          </Dialog>
        </div>

        <Dialog
          open={editOpen}
          onOpenChange={(open) => {
            setEditOpen(open);
            if (!open) setEditing(null);
          }}
        >
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Editar plano</DialogTitle>
            </DialogHeader>
            {editing ? (
              <form onSubmit={submitEdit} className="space-y-4 mt-2">
                <div className="space-y-2">
                  <Label htmlFor="edit-name">Nome</Label>
                  <Input
                    id="edit-name"
                    value={editForm.name}
                    onChange={(e) => setEditForm((s) => ({ ...s, name: e.target.value }))}
                    required
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="edit-price">Preço (R$)</Label>
                    <Input
                      id="edit-price"
                      type="text"
                      inputMode="decimal"
                      value={editForm.price}
                      onChange={(e) => setEditForm((s) => ({ ...s, price: e.target.value }))}
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="edit-duration">Quantidade de meses</Label>
                    <Input
                      id="edit-duration"
                      type="number"
                      min={1}
                      max={60}
                      value={editForm.duration_months}
                      onChange={(e) => setEditForm((s) => ({ ...s, duration_months: e.target.value }))}
                      required
                    />
                  </div>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-border px-3 py-2">
                  <Label htmlFor="edit-active" className="cursor-pointer">
                    Plano ativo
                  </Label>
                  <Switch
                    id="edit-active"
                    checked={editForm.is_active}
                    onCheckedChange={(v) => setEditForm((s) => ({ ...s, is_active: v }))}
                  />
                </div>
                <Button type="submit" variant="hero" className="w-full" disabled={updateMutation.isPending}>
                  {updateMutation.isPending ? "A guardar…" : "Guardar alterações"}
                </Button>
              </form>
            ) : null}
          </DialogContent>
        </Dialog>

        <AlertDialog open={deleteTarget !== null} onOpenChange={(o) => !o && setDeleteTarget(null)}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Remover plano?</AlertDialogTitle>
              <AlertDialogDescription>
                {deleteTarget
                  ? `O plano «${deleteTarget.name}» será apagado. Empresas que o usam ficam sem plano atribuído (campo plano fica vazio).`
                  : ""}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancelar</AlertDialogCancel>
              <AlertDialogAction
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                disabled={deleteMutation.isPending}
                onClick={() => {
                  if (deleteTarget) deleteMutation.mutate(deleteTarget.id);
                }}
              >
                {deleteMutation.isPending ? "A remover…" : "Remover"}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>

        {isLoading ? (
          <p className="text-muted-foreground">A carregar planos…</p>
        ) : isError ? (
          <Card className="p-6 text-destructive">{error instanceof Error ? error.message : "Erro ao carregar."}</Card>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {(data?.items ?? []).map((plan) => (
              <Card key={plan.id} className="shadow-card p-5">
                <div className="flex items-center justify-between mb-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10">
                    <CalendarDays className="h-5 w-5 text-accent" />
                  </div>
                  <div className="flex items-center gap-1">
                    {plan.is_active !== 1 ? (
                      <Badge variant="secondary" className="mr-1 text-xs">
                        Inativo
                      </Badge>
                    ) : null}
                    <Button type="button" variant="ghost" size="icon" className="h-8 w-8" onClick={() => openEdit(plan)}>
                      <Edit className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8 text-destructive"
                      onClick={() => setDeleteTarget(plan)}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>
                <h3 className="text-lg font-semibold text-card-foreground">{plan.name}</h3>
                <p className="text-2xl font-bold text-foreground mt-1">
                  {brl(plan.price)}
                  <span className="text-sm text-muted-foreground font-normal"> total</span>
                </p>
                <div className="mt-4 space-y-2 text-sm text-muted-foreground">
                  <p>{formatMonths(plan.duration_months || plan.charges_limit)}</p>
                  <p className="text-accent font-medium">
                    {plan.companies_count}{" "}
                    {plan.companies_count === 1 ? "empresa com este plano" : "empresas com este plano"}
                  </p>
                </div>
              </Card>
            ))}
          </div>
        )}
    </div>
  );
};

export default AdminPlans;
