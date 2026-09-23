import { MessageCircle } from "lucide-react";
import { useQuery } from "@tanstack/react-query";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import DashboardSidebar from "@/components/DashboardSidebar";
import { useAuth } from "@/hooks/useAuth";
import { apiFetch } from "@/lib/api";
import { isPlatformAdminWithoutCompany } from "@/lib/platformAdmin";

type SubscriptionGateData = {
  company: {
    name: string;
  };
  subscription: {
    status: "active" | "renew_today" | "renew_soon" | "overdue" | "inactive" | "no_plan";
    days_remaining: number | null;
    is_blocked: boolean;
  };
  support?: {
    whatsapp_phone?: string;
  };
};

const supportMessage = (companyName: string) =>
  `Olá, preciso de suporte com a assinatura da empresa ${companyName || ""}.`;

const normalizeSubscriptionStatus = (data: SubscriptionGateData | undefined) => {
  if (!data) return null;
  if (data.subscription.is_blocked) {
    return "overdue";
  }
  return data.subscription.status;
};

const FloatingSupportButton = ({ phone, companyName }: { phone: string; companyName: string }) => {
  const cleanPhone = phone.replace(/\D/g, "");
  if (!cleanPhone) return null;

  const href = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(supportMessage(companyName))}`;

  return (
    <a
      href={href}
      target="_blank"
      rel="noreferrer"
      aria-label="Falar com suporte pelo WhatsApp"
      className="fixed bottom-6 right-6 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-[hsl(142_70%_45%)] text-white shadow-lg transition-transform hover:scale-105 hover:bg-[hsl(142_70%_40%)]"
    >
      <MessageCircle className="h-7 w-7" />
    </a>
  );
};

const DashboardLayout = () => {
  const { user, loading } = useAuth();
  const location = useLocation();
  const platformOnly = isPlatformAdminWithoutCompany(user);
  const isCompany = !loading && Boolean(user) && !platformOnly;

  const { data, isLoading } = useQuery({
    queryKey: ["company-subscription-gate"],
    queryFn: () => apiFetch<SubscriptionGateData>("/api/company/subscription"),
    enabled: isCompany,
    retry: 1,
    refetchOnWindowFocus: true,
  });

  const subscriptionStatus = normalizeSubscriptionStatus(data);
  const blocked = Boolean(data?.subscription.is_blocked) || subscriptionStatus === "overdue" || subscriptionStatus === "no_plan";
  if (isCompany && isLoading && location.pathname !== "/dashboard/subscription") {
    return (
      <div className="min-h-screen bg-background">
        <DashboardSidebar />
        <main className="ml-64 flex min-h-screen items-center justify-center p-6 lg:p-8">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
        </main>
      </div>
    );
  }

  if (blocked && location.pathname !== "/dashboard/subscription") {
    return <Navigate to="/dashboard/subscription" replace />;
  }

  return (
    <div className="min-h-screen bg-background">
      <DashboardSidebar />
      <main className="ml-64 p-6 lg:p-8">
        <Outlet />
      </main>
      {isCompany ? (
        <FloatingSupportButton phone={data?.support?.whatsapp_phone ?? ""} companyName={data?.company.name ?? ""} />
      ) : null}
    </div>
  );
};

export default DashboardLayout;
