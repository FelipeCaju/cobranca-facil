import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { apiFetch } from "@/lib/api";
import { useBrandTheme } from "@/hooks/useBrandTheme";
import {
  Zap,
  ArrowRight,
  Check,
  Star,
  CreditCard,
  MessageSquare,
  Bell,
  BarChart3,
  Users,
  Clock,
} from "lucide-react";

const features = [
  {
    icon: CreditCard,
    title: "Cobranças e parcelas",
    description: "Crie cobranças, acompanhe parcelas, vencimentos e status de pagamento num só painel.",
  },
  {
    icon: MessageSquare,
    title: "WhatsApp automático",
    description: "Lembretes e avisos enviados pela Evolution API, com templates personalizáveis por empresa.",
  },
  {
    icon: Bell,
    title: "Email de cobrança",
    description: "SMTP por empresa ou padrão da plataforma para notificações profissionais.",
  },
  {
    icon: BarChart3,
    title: "Visão do negócio",
    description: "Dashboard com indicadores de recebíveis, inadimplência e desempenho das cobranças.",
  },
  {
    icon: Users,
    title: "Multi-empresa",
    description: "Cada cliente regista a sua empresa e escolhe o periodo de assinatura ideal.",
  },
  {
    icon: Clock,
    title: "Agendamento inteligente",
    description: "Lembretes diários com horário e intervalo entre envios configuráveis por empresa.",
  },
];

type PublicPlan = {
  id: string;
  name: string;
  price: number;
  price_label: string;
  charges_limit: number;
  users_limit: number;
  duration_months?: number;
};

type PlanCard = {
  id: string;
  name: string;
  description: string;
  priceMain: string;
  priceRest: string;
  features: string[];
  highlighted: boolean;
};

const FALLBACK_PLANS: PlanCard[] = [
  {
    id: "starter",
    name: "Mensal",
    description: "Ideal para começar",
    priceMain: "R$ 49,90",
    priceRest: "",
    features: ["1 mês de acesso", "WhatsApp e email", "Painel de cobranças", "Renovação automática após pagamento"],
    highlighted: false,
  },
  {
    id: "pro",
    name: "Bimestral",
    description: "Para renovar com melhor valor",
    priceMain: "R$ 149,90",
    priceRest: "",
    features: ["2 meses de acesso", "WhatsApp e email", "Painel de cobranças", "Renovação automática após pagamento"],
    highlighted: true,
  },
];

function planToCard(row: PublicPlan, index: number, total: number): PlanCard {
  const slash = row.price_label.indexOf("/");
  const priceMain = slash > 0 ? row.price_label.slice(0, slash).trim() : row.price_label;
  const priceRest = slash > 0 ? row.price_label.slice(slash).trim() : "";
  const months = Math.max(1, Math.min(60, Number(row.duration_months ?? row.charges_limit) || 1));
  const duration = months === 1 ? "1 mês de acesso" : `${months} meses de acesso`;
  return {
    id: row.id,
    name: row.name,
    description: months === 1 ? "Ideal para começar" : months <= 3 ? "Para renovar com melhor valor" : "Maior período com economia",
    priceMain,
    priceRest,
    features: [duration, "Lembretes WhatsApp e email", "Painel de cobranças", "Renovação automática após pagamento"],
    highlighted: total >= 2 && index === 1,
  };
}

const Index = () => {
  const { systemName } = useBrandTheme();
  const [planCards, setPlanCards] = useState<PlanCard[]>(FALLBACK_PLANS);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ items: PublicPlan[] }>("/api/public/plans")
      .then((res) => {
        const rows = res.items ?? [];
        if (cancelled || rows.length === 0) return;
        setPlanCards(rows.map((row, i, arr) => planToCard(row, i, arr.length)));
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="min-h-screen bg-background">
      <nav className="fixed top-0 left-0 right-0 z-50 border-b bg-background/80 backdrop-blur-xl">
        <div className="container flex h-16 items-center justify-between">
          <Link to="/" className="flex items-center gap-2.5">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg gradient-accent">
              <Zap className="h-4 w-4 text-accent-foreground" />
            </div>
            <span className="font-display text-xl font-bold">{systemName}</span>
          </Link>
          <div className="hidden items-center gap-8 md:flex">
            <a href="#features" className="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors">
              Funcionalidades
            </a>
            <a href="#pricing" className="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors">
              Planos
            </a>
          </div>
          <div className="flex items-center gap-3">
            <Link to="/login">
              <Button variant="ghost" size="sm">
                Entrar
              </Button>
            </Link>
            <Link to="/login?tab=register">
              <Button size="sm" variant="accent" className="shadow-accent">
                Criar conta
              </Button>
            </Link>
          </div>
        </div>
      </nav>

      <section className="relative overflow-hidden gradient-hero pt-32 pb-20">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_hsl(38_92%_50%_/_0.12),_transparent_60%)]" />
        <div className="container relative">
          <div className="mx-auto max-w-3xl text-center animate-slide-up">
            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-accent/30 bg-accent/10 px-4 py-1.5 text-sm font-medium text-accent">
              <Zap className="h-3.5 w-3.5" />
              Cobranças automáticas para o seu negócio
            </div>
            <h1 className="font-display text-4xl font-extrabold tracking-tight text-primary-foreground sm:text-5xl md:text-6xl">
              Reduza a inadimplência com{" "}
              <span className="text-gradient-accent">cobrança inteligente</span>
            </h1>
            <p className="mt-6 text-lg text-primary-foreground/70 max-w-2xl mx-auto">
              PIX, lembretes por WhatsApp e email, parcelas e templates — tudo numa plataforma simples para a sua empresa.
            </p>
            <div className="mt-8 flex flex-col items-center gap-4 sm:flex-row sm:justify-center">
              <Link to="/login?tab=register">
                <Button size="lg" variant="accent" className="shadow-accent font-semibold px-8">
                  Começar agora <ArrowRight className="ml-2 h-4 w-4" />
                </Button>
              </Link>
              <a href="#features">
                <Button
                  variant="outline"
                  size="lg"
                  className="border-white/35 bg-white/10 text-white hover:border-white/50 hover:bg-white/20 hover:text-white"
                >
                  Ver funcionalidades
                </Button>
              </a>
            </div>
          </div>

          <div className="mt-16 grid grid-cols-2 gap-8 md:grid-cols-4 animate-fade-in">
            {[
              { value: "70%", label: "Menos atrasos*" },
              { value: "24/7", label: "Lembretes automáticos" },
              { value: "PIX", label: "Mercado Pago" },
              { value: "Multi", label: "Empresas e planos" },
            ].map((stat) => (
              <div key={stat.label} className="text-center">
                <p className="font-display text-3xl font-bold text-accent">{stat.value}</p>
                <p className="mt-1 text-sm text-primary-foreground/50">{stat.label}</p>
              </div>
            ))}
          </div>
          <p className="mt-6 text-center text-xs text-primary-foreground/40">* Resultados variam conforme uso e segmento.</p>
        </div>
      </section>

      <section id="features" className="py-20">
        <div className="container">
          <div className="mx-auto max-w-2xl text-center">
            <h2 className="font-display text-3xl font-bold">Tudo para cobrar com menos esforço</h2>
            <p className="mt-4 text-muted-foreground">Ferramentas pensadas para PMEs que não podem perder tempo com planilhas.</p>
          </div>
          <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {features.map((feature) => (
              <div
                key={feature.title}
                className="rounded-xl border bg-card p-6 shadow-card transition-all duration-300 hover:shadow-card-hover"
              >
                <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-accent/10">
                  <feature.icon className="h-5 w-5 text-accent" />
                </div>
                <h3 className="mt-4 font-display text-lg font-semibold">{feature.title}</h3>
                <p className="mt-2 text-sm text-muted-foreground leading-relaxed">{feature.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section id="pricing" className="bg-muted/50 py-20">
        <div className="container">
          <div className="mx-auto max-w-2xl text-center">
            <h2 className="font-display text-3xl font-bold">Planos transparentes</h2>
            <p className="mt-4 text-muted-foreground">Escolha o periodo de acesso que faz sentido para a sua empresa.</p>
          </div>
          <div
            className={`mt-14 grid gap-8 ${
              planCards.length <= 1 ? "max-w-md mx-auto" : planCards.length === 2 ? "md:grid-cols-2 max-w-4xl mx-auto" : "md:grid-cols-3"
            }`}
          >
            {planCards.map((plan) => (
              <div
                key={plan.id}
                className={`relative rounded-2xl border p-7 transition-all duration-300 ${
                  plan.highlighted ? "border-accent bg-card shadow-accent scale-[1.02]" : "bg-card shadow-card hover:shadow-card-hover"
                }`}
              >
                {plan.highlighted && (
                  <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                    <span className="inline-flex items-center gap-1 rounded-full bg-accent px-3 py-1 text-xs font-bold text-accent-foreground">
                      <Star className="h-3 w-3" /> Mais popular
                    </span>
                  </div>
                )}
                <h3 className="font-display text-xl font-bold">{plan.name}</h3>
                <p className="mt-1 text-sm text-muted-foreground">{plan.description}</p>
                <div className="mt-5 flex items-baseline gap-1 flex-wrap">
                  <span className="font-display text-4xl font-extrabold">{plan.priceMain}</span>
                  {plan.priceRest ? <span className="text-muted-foreground">{plan.priceRest}</span> : null}
                </div>
                <ul className="mt-6 space-y-3">
                  {plan.features.map((feature) => (
                    <li key={feature} className="flex items-center gap-2.5 text-sm">
                      <Check className="h-4 w-4 text-success shrink-0" />
                      {feature}
                    </li>
                  ))}
                </ul>
                <Button
                  asChild
                  className={`mt-7 w-full ${plan.highlighted ? "bg-accent text-accent-foreground hover:bg-accent/90 shadow-accent" : ""}`}
                  variant={plan.highlighted ? "default" : "outline"}
                >
                  <Link to="/login?tab=register">Começar agora</Link>
                </Button>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="py-20">
        <div className="container">
          <div className="mx-auto max-w-3xl rounded-2xl gradient-hero p-10 text-center md:p-14">
            <h2 className="font-display text-3xl font-bold text-primary-foreground">Pronto para automatizar as cobranças?</h2>
            <p className="mt-4 text-primary-foreground/70">Crie a sua conta em minutos e comece a enviar lembretes hoje.</p>
            <Link to="/login?tab=register">
              <Button size="lg" variant="accent" className="mt-8 shadow-accent font-semibold px-8">
                Criar conta grátis <ArrowRight className="ml-2 h-4 w-4" />
              </Button>
            </Link>
          </div>
        </div>
      </section>

      <footer className="border-t py-10">
        <div className="container flex flex-col items-center justify-between gap-4 md:flex-row">
          <div className="flex items-center gap-2">
            <div className="flex h-7 w-7 items-center justify-center rounded-md gradient-accent">
              <Zap className="h-3.5 w-3.5 text-accent-foreground" />
            </div>
            <span className="font-display font-bold">{systemName}</span>
          </div>
          <p className="text-sm text-muted-foreground">© {new Date().getFullYear()} {systemName}. Todos os direitos reservados.</p>
        </div>
      </footer>
    </div>
  );
};

export default Index;
