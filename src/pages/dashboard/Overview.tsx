import { useQuery } from "@tanstack/react-query";
import { Card } from "@/components/ui/card";
import { Users, FileText, DollarSign, AlertTriangle, TrendingUp, TrendingDown, Minus } from "lucide-react";
import { apiFetch, getStoredToken } from "@/lib/api";
import { useAuth } from "@/hooks/useAuth";
import { isPlatformAdminWithoutCompany } from "@/lib/platformAdmin";
import AdminOverview from "@/pages/dashboard/AdminOverview";

interface OverviewStats {
  clients_total: number;
  clients_change_pct: number | null;
  charges_active: number;
  charges_active_change_pct: number | null;
  revenue_month: number;
  revenue_change_pct: number | null;
  delinquency_pct: number | null;
  delinquency_change_pp: number | null;
}

interface RecentChargeRow {
  id: string;
  client_name: string;
  total_amount: string;
  status: string;
  created_at: string;
  next_due: string | null;
}

const brl = (n: number) =>
  n.toLocaleString("pt-BR", { style: "currency", currency: "BRL", minimumFractionDigits: 2, maximumFractionDigits: 2 });

const chargeStatusPt: Record<string, string> = {
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

const formatChange = (v: number | null): { text: string; positive: boolean | null } => {
  if (v === null || Number.isNaN(v)) {
    return { text: "—", positive: null };
  }
  const sign = v > 0 ? "+" : "";
  return { text: `${sign}${v}%`, positive: v >= 0 };
};

const formatChangePp = (v: number | null): { text: string; positive: boolean | null } => {
  if (v === null || Number.isNaN(v)) {
    return { text: "—", positive: null };
  }
  const sign = v > 0 ? "+" : "";
  return { text: `${sign}${v} p.p.`, positive: v <= 0 };
};

const formatDue = (createdAt: string, nextDue: string | null) => {
  const d = nextDue || (createdAt ? String(createdAt).slice(0, 10) : "");
  if (!d) return "—";
  const [y, m, day] = d.split("-").map(Number);
  if (!y || !m || !day) return d;
  return new Date(y, m - 1, day).toLocaleDateString("pt-BR");
};

const CompanyOverview = () => {
  const { loading: authLoading, session } = useAuth();

  const { data, isLoading, isError, error, fetchStatus } = useQuery({
    queryKey: ["company-overview"],
    queryFn: () =>
      apiFetch<{ stats: OverviewStats; recent_charges: RecentChargeRow[] }>("/api/company/overview"),
    enabled: !authLoading && Boolean(session ?? getStoredToken()),
    retry: 1,
  });

  const showLoader = authLoading || (isLoading && fetchStatus !== "idle");

  if (showLoader) {
    return (
      <div>
        <h1 className="text-2xl font-bold text-foreground mb-6">Visão Geral</h1>
        <div className="flex min-h-[200px] items-center justify-center text-muted-foreground">A carregar métricas…</div>
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div>
        <h1 className="text-2xl font-bold text-foreground mb-6">Visão Geral</h1>
        <Card className="p-6 text-destructive">
          {error instanceof Error ? error.message : "Não foi possível carregar os dados."}
        </Card>
      </div>
    );
  }

  const { stats, recent_charges } = data;

  const clientsCh = formatChange(stats.clients_change_pct);
  const chargesCh = formatChange(stats.charges_active_change_pct);
  const revenueCh = formatChange(stats.revenue_change_pct);
  const delinqCh = formatChangePp(stats.delinquency_change_pp);

  const statCards = [
    {
      label: "Clientes",
      value: String(stats.clients_total),
      icon: Users,
      change: clientsCh,
      subtitle: "Total na empresa",
    },
    {
      label: "Cobranças ativas",
      value: String(stats.charges_active),
      icon: FileText,
      change: chargesCh,
      subtitle: "Pendentes ou em atraso",
    },
    {
      label: "Receita do mês",
      value: brl(stats.revenue_month),
      icon: DollarSign,
      change: revenueCh,
      subtitle: "Parcelas pagas (mês corrente)",
    },
    {
      label: "Inadimplência (parcelas)",
      value: stats.delinquency_pct !== null ? `${stats.delinquency_pct}%` : "—",
      icon: AlertTriangle,
      change: delinqCh,
      subtitle: "Atrasadas / (pendentes + atrasadas + pagas)",
    },
  ];

  return (
    <div>
      <h1 className="text-2xl font-bold text-foreground mb-6">Visão Geral</h1>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">
        {statCards.map((stat) => (
          <Card key={stat.label} className="p-5 shadow-card">
            <div className="flex items-center justify-between mb-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent/10">
                <stat.icon className="h-4 w-4 text-accent" />
              </div>
              {stat.change.positive === null ? (
                <span className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                  <Minus className="h-3 w-3" />
                  {stat.change.text}
                </span>
              ) : (
                <span
                  className={`flex items-center gap-1 text-xs font-medium ${stat.change.positive ? "text-success" : "text-destructive"}`}
                >
                  {stat.change.positive ? <TrendingUp className="h-3 w-3" /> : <TrendingDown className="h-3 w-3" />}
                  {stat.change.text}
                </span>
              )}
            </div>
            <p className="text-2xl font-bold text-card-foreground">{stat.value}</p>
            <p className="text-xs text-muted-foreground mt-1">{stat.label}</p>
            <p className="text-[11px] text-muted-foreground/80 mt-0.5">{stat.subtitle}</p>
          </Card>
        ))}
      </div>

      <Card className="shadow-card">
        <div className="p-5 border-b border-border">
          <h2 className="text-lg font-semibold text-card-foreground">Cobranças recentes</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-border">
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Cliente</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Valor</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Vencimento</th>
              </tr>
            </thead>
            <tbody>
              {recent_charges.length === 0 ? (
                <tr>
                  <td colSpan={4} className="px-5 py-8 text-center text-sm text-muted-foreground">
                    Ainda não há cobranças registadas.
                  </td>
                </tr>
              ) : (
                recent_charges.map((charge) => {
                  const st = charge.status;
                  const pt = chargeStatusPt[st] ?? st;
                  return (
                    <tr key={charge.id} className="border-b border-border last:border-0 hover:bg-muted/50 transition-colors">
                      <td className="px-5 py-3.5 text-sm font-medium text-card-foreground">{charge.client_name}</td>
                      <td className="px-5 py-3.5 text-sm text-card-foreground">{brl(Number(charge.total_amount))}</td>
                      <td className="px-5 py-3.5">
                        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusStyles[st] ?? "bg-muted"}`}>
                          {pt}
                        </span>
                      </td>
                      <td className="px-5 py-3.5 text-sm text-muted-foreground">
                        {formatDue(charge.created_at, charge.next_due)}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
};

const Overview = () => {
  const { user, loading } = useAuth();
  if (loading) {
    return (
      <div className="flex min-h-[200px] items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }
  if (isPlatformAdminWithoutCompany(user)) {
    return <AdminOverview />;
  }
  return <CompanyOverview />;
};

export default Overview;
