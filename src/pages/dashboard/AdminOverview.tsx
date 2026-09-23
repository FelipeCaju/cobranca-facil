import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Building2, Users, FileText, DollarSign, Gem, TrendingUp, TrendingDown, Minus } from "lucide-react";
import { apiFetch, getStoredToken } from "@/lib/api";
import { useAuth } from "@/hooks/useAuth";
import { useBrandTheme } from "@/hooks/useBrandTheme";

interface AdminStats {
  companies_total: number;
  companies_active: number;
  companies_with_charges: number;
  companies_change_pct: number | null;
  owners_total: number;
  plans_active: number;
  end_clients_total: number;
  charges_active: number;
  charges_total: number;
  revenue_month: number;
  revenue_change_pct: number | null;
}

interface RecentCompany {
  id: string;
  name: string;
  email: string;
  owner_email: string;
  plan_name: string;
  is_active: boolean;
  charges_count: number;
  created_at: string;
}

const brl = (n: number) =>
  n.toLocaleString("pt-BR", { style: "currency", currency: "BRL", minimumFractionDigits: 2, maximumFractionDigits: 2 });

const formatChange = (v: number | null): { text: string; positive: boolean | null } => {
  if (v === null || Number.isNaN(v)) return { text: "—", positive: null };
  const sign = v > 0 ? "+" : "";
  return { text: `${sign}${v}%`, positive: v >= 0 };
};

const formatDate = (iso: string) => {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString("pt-BR");
};

const AdminOverview = () => {
  const { loading: authLoading, session } = useAuth();
  const { systemName } = useBrandTheme();

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["admin-overview"],
    queryFn: () =>
      apiFetch<{ stats: AdminStats; recent_companies: RecentCompany[] }>("/api/admin/overview"),
    enabled: !authLoading && Boolean(session ?? getStoredToken()),
    retry: 1,
  });

  if (authLoading || isLoading) {
    return (
      <div>
        <h1 className="text-2xl font-bold text-foreground mb-6">Dashboard</h1>
        <div className="flex min-h-[200px] items-center justify-center text-muted-foreground">A carregar métricas…</div>
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div>
        <h1 className="text-2xl font-bold text-foreground mb-6">Dashboard</h1>
        <Card className="p-6 text-destructive">
          {error instanceof Error ? error.message : "Não foi possível carregar os dados da plataforma."}
        </Card>
      </div>
    );
  }

  const { stats, recent_companies } = data;
  const companiesCh = formatChange(stats.companies_change_pct);
  const revenueCh = formatChange(stats.revenue_change_pct);

  const statCards = [
    {
      label: "Empresas",
      value: String(stats.companies_total),
      subtitle: `${stats.companies_active} ativas · ${stats.companies_with_charges} com cobranças`,
      icon: Building2,
      change: companiesCh,
    },
    {
      label: "Donos de empresa",
      value: String(stats.owners_total),
      subtitle: "Utilizadores com papel company_owner",
      icon: Users,
      change: { text: "—", positive: null as boolean | null },
    },
    {
      label: "Clientes finais",
      value: String(stats.end_clients_total),
      subtitle: "CRM de todas as empresas",
      icon: Users,
      change: { text: "—", positive: null as boolean | null },
    },
    {
      label: "Receita do mês",
      value: brl(stats.revenue_month),
      subtitle: "Parcelas pagas (plataforma inteira)",
      icon: DollarSign,
      change: revenueCh,
    },
    {
      label: "Cobranças ativas",
      value: String(stats.charges_active),
      subtitle: `${stats.charges_total} cobranças no total`,
      icon: FileText,
      change: { text: "—", positive: null as boolean | null },
    },
    {
      label: "Planos ativos",
      value: String(stats.plans_active),
      subtitle: "Catálogo SaaS disponível",
      icon: Gem,
      change: { text: "—", positive: null as boolean | null },
    },
  ];

  return (
    <div>
      <h1 className="text-2xl font-bold text-foreground mb-2">Dashboard</h1>
      <p className="text-sm text-muted-foreground mb-6">
        Visão global da plataforma {systemName} — dados em tempo real da base de dados.
      </p>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 mb-8">
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
        <div className="p-5 border-b border-border flex items-center justify-between gap-4">
          <h2 className="text-lg font-semibold text-card-foreground">Empresas recentes</h2>
          <Link to="/admin/companies" className="text-sm text-primary hover:underline font-medium">
            Ver todas
          </Link>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-border">
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Empresa</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Plano</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Dono</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Cobranças</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Estado</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Registo</th>
              </tr>
            </thead>
            <tbody>
              {recent_companies.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-5 py-8 text-center text-sm text-muted-foreground">
                    Ainda não há empresas registadas.
                  </td>
                </tr>
              ) : (
                recent_companies.map((co) => (
                  <tr key={co.id} className="border-b border-border last:border-0 hover:bg-muted/50 transition-colors">
                    <td className="px-5 py-3.5 text-sm font-medium text-card-foreground">{co.name}</td>
                    <td className="px-5 py-3.5 text-sm text-muted-foreground">{co.plan_name || "—"}</td>
                    <td className="px-5 py-3.5 text-sm text-muted-foreground">{co.owner_email}</td>
                    <td className="px-5 py-3.5 text-sm text-card-foreground">
                      {co.charges_count > 0 ? (
                        <span className="text-success font-medium">Com cobranças ({co.charges_count})</span>
                      ) : (
                        <span className="text-muted-foreground">Sem cobranças</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5">
                      <Badge variant={co.is_active ? "default" : "secondary"}>{co.is_active ? "Ativa" : "Inativa"}</Badge>
                    </td>
                    <td className="px-5 py-3.5 text-sm text-muted-foreground">{formatDate(co.created_at)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
};

export default AdminOverview;
