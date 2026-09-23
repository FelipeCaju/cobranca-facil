import { useMutation, useQuery } from "@tanstack/react-query";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { useToast } from "@/hooks/use-toast";
import { apiFetch } from "@/lib/api";
import { formatDateBr } from "@/lib/dates";
import { AlertCircle, Check, CreditCard, ExternalLink, Gem, RefreshCw } from "lucide-react";

type Plan = {
  id: string;
  name: string;
  price: number;
  price_label: string;
  charges_limit: number;
  users_limit: number;
  duration_months?: number;
};

type SubscriptionData = {
  company: {
    id: string;
    name: string;
    email: string;
    is_active: boolean;
    plan_id: string | null;
    plan_name: string;
    plan_price: number;
    plan_renews_at: string | null;
    charges_limit: number;
    users_limit: number;
    duration_months?: number;
  };
  subscription: {
    status: "active" | "renew_today" | "renew_soon" | "overdue" | "inactive" | "no_plan";
    days_remaining: number | null;
    is_blocked: boolean;
    message: string;
  };
  plans: Plan[];
  checkout_gateway: "mercadopago";
  support?: {
    whatsapp_phone?: string;
  };
};

type CheckoutResponse = {
  checkout_url: string;
  preference_id: string;
  external_reference: string;
};

const brl = (n: number) =>
  n.toLocaleString("pt-BR", { style: "currency", currency: "BRL", minimumFractionDigits: 2, maximumFractionDigits: 2 });

const planMonths = (plan: { duration_months?: number; charges_limit: number }) => {
  const raw = Number(plan.duration_months ?? plan.charges_limit) || 1;
  if (raw > 60) {
    if (raw <= 100) return 1;
    if (raw <= 1000) return 2;
    return 12;
  }
  return Math.max(1, Math.min(60, raw));
};

const formatDuration = (months: number) => (months === 1 ? "1 mês de acesso" : `${months} meses de acesso`);

const effectiveStatus = (data: SubscriptionData) => {
  if (data.subscription.is_blocked) {
    return "overdue";
  }
  return data.subscription.status;
};

const statusCopy: Record<SubscriptionData["subscription"]["status"], { label: string; badge: string; title: string; description: string }> = {
  active: {
    label: "Ativa",
    badge: "bg-success/15 text-success border-0",
    title: "Sua assinatura está ativa",
    description: "Você pode renovar antecipadamente ou trocar de plano quando precisar.",
  },
  renew_soon: {
    label: "Renovar em breve",
    badge: "bg-warning/15 text-warning border-0",
    title: "Sua assinatura está perto da renovação",
    description: "Renove agora para manter o acesso sem interrupções.",
  },
  renew_today: {
    label: "Vence hoje",
    badge: "bg-warning/15 text-warning border-0",
    title: "Sua assinatura vence hoje",
    description: "Renove hoje para evitar bloqueio do acesso ao sistema.",
  },
  overdue: {
    label: "Vencida",
    badge: "bg-destructive/15 text-destructive border-0",
    title: "Sua assinatura venceu",
    description: "Realize o pagamento para renovar o plano. As demais áreas ficam bloqueadas até a confirmação do pagamento.",
  },
  inactive: {
    label: "Inativa",
    badge: "bg-muted text-muted-foreground border-0",
    title: "Conta inativa",
    description: "Fale com o suporte para regularizar o acesso da empresa.",
  },
  no_plan: {
    label: "Sem plano",
    badge: "bg-muted text-muted-foreground border-0",
    title: "Escolha um plano para ativar a assinatura",
    description: "Selecione um dos planos disponíveis e conclua o pagamento pelo Mercado Pago.",
  },
};

const Subscription = () => {
  const { toast } = useToast();
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ["company-subscription"],
    queryFn: () => apiFetch<SubscriptionData>("/api/company/subscription"),
  });

  const checkoutMutation = useMutation({
    mutationFn: (planId: string) =>
      apiFetch<CheckoutResponse>("/api/company/subscription/checkout", {
        method: "POST",
        body: JSON.stringify({ plan_id: planId }),
      }),
    onSuccess: (res) => {
      if (!res.checkout_url) {
        toast({ variant: "destructive", title: "Link indisponível", description: "O Mercado Pago não retornou uma URL de pagamento." });
        return;
      }
      const opened = window.open(res.checkout_url, "_blank", "noopener,noreferrer");
      if (!opened) {
        window.location.assign(res.checkout_url);
      }
      toast({ title: "Checkout aberto", description: "Após o pagamento aprovado, a assinatura será renovada automaticamente." });
    },
    onError: (e: Error) => toast({ variant: "destructive", title: "Erro ao gerar pagamento", description: e.message }),
  });

  if (isLoading) {
    return (
      <div>
        <h1 className="mb-6 text-2xl font-bold text-foreground">Assinatura</h1>
        <div className="flex min-h-[220px] items-center justify-center text-muted-foreground">Carregando assinatura...</div>
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div>
        <h1 className="mb-6 text-2xl font-bold text-foreground">Assinatura</h1>
        <Card className="p-6 text-destructive">{error instanceof Error ? error.message : "Não foi possível carregar a assinatura."}</Card>
      </div>
    );
  }

  const current = data.company;
  const subscriptionStatus = effectiveStatus(data);
  const status = statusCopy[subscriptionStatus];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Assinatura</h1>
          <p className="text-sm text-muted-foreground">Pagamento, renovação e troca do plano da empresa.</p>
        </div>
        <Button type="button" variant="outline" size="sm" onClick={() => void refetch()}>
          <RefreshCw className="h-4 w-4" />
          Atualizar
        </Button>
      </div>

      <Alert className={subscriptionStatus === "overdue" ? "border-destructive/35" : ""}>
        <AlertCircle className="h-4 w-4" />
        <AlertTitle>{status.title}</AlertTitle>
        <AlertDescription>{status.description}</AlertDescription>
      </Alert>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,360px)]">
        <Card className="p-5 shadow-card">
          <div className="flex items-start justify-between gap-4">
            <div className="flex items-start gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                <CreditCard className="h-5 w-5 text-primary" />
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Plano atual</p>
                <h2 className="mt-1 text-xl font-semibold text-foreground">{current.plan_name || "Nenhum plano ativo"}</h2>
              </div>
            </div>
            <Badge className={status.badge}>{status.label}</Badge>
          </div>

          <div className="mt-6 grid gap-4 sm:grid-cols-3">
            <div className="rounded-lg border border-border p-4">
              <p className="text-xs text-muted-foreground">Valor do plano</p>
              <p className="mt-1 text-lg font-semibold text-foreground">{current.plan_price > 0 ? brl(current.plan_price) : "--"}</p>
            </div>
            <div className="rounded-lg border border-border p-4">
              <p className="text-xs text-muted-foreground">Próxima renovação</p>
              <p className="mt-1 text-lg font-semibold text-foreground">{formatDateBr(current.plan_renews_at)}</p>
            </div>
            <div className="rounded-lg border border-border p-4">
              <p className="text-xs text-muted-foreground">Dias restantes</p>
              <p className="mt-1 text-lg font-semibold text-foreground">
                {data.subscription.days_remaining === null ? "--" : data.subscription.days_remaining}
              </p>
            </div>
          </div>

          <div className="mt-5 grid gap-3 text-sm text-muted-foreground sm:grid-cols-2">
            <div className="flex items-center gap-2">
              <Check className="h-4 w-4 text-success" />
              {current.plan_id ? formatDuration(planMonths(current)) : "--"}
            </div>
          </div>
        </Card>

        <Card className="p-5 shadow-card">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10">
            <Gem className="h-5 w-5 text-accent" />
          </div>
          <h2 className="mt-4 text-lg font-semibold text-foreground">Pagamento seguro</h2>
          <p className="mt-2 text-sm text-muted-foreground">
            O checkout abre no Mercado Pago. Quando o pagamento for aprovado, o webhook atualiza a assinatura automaticamente.
          </p>
        </Card>
      </div>

      <div>
        <h2 className="mb-4 text-lg font-semibold text-foreground">Planos disponíveis</h2>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data.plans.map((plan) => {
            const isCurrent = plan.id === current.plan_id;
            const pending = checkoutMutation.isPending && checkoutMutation.variables === plan.id;
            return (
              <Card key={plan.id} className={`p-5 shadow-card ${isCurrent ? "border-primary/50" : ""}`}>
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <h3 className="text-lg font-semibold text-foreground">{plan.name}</h3>
                    <p className="mt-1 text-2xl font-bold text-foreground">
                      {brl(plan.price)}
                      <span className="text-sm font-normal text-muted-foreground"> total</span>
                    </p>
                  </div>
                  {isCurrent ? <Badge variant="outline">Atual</Badge> : null}
                </div>
                <div className="mt-5 space-y-2 text-sm text-muted-foreground">
                  <p className="flex items-center gap-2">
                    <Check className="h-4 w-4 text-success" />
                    {formatDuration(planMonths(plan))}
                  </p>
                </div>
                <Button
                  type="button"
                  className="mt-5 w-full"
                  variant={isCurrent ? "outline" : "hero"}
                  disabled={checkoutMutation.isPending}
                  onClick={() => checkoutMutation.mutate(plan.id)}
                >
                  {pending ? "Gerando..." : isCurrent ? "Renovar plano" : "Assinar este plano"}
                  {!pending ? <ExternalLink className="h-4 w-4" /> : null}
                </Button>
              </Card>
            );
          })}
        </div>
      </div>
    </div>
  );
};

export default Subscription;
