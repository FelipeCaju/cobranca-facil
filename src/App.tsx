import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Navigate, Outlet, Route, Routes } from "react-router-dom";
import { AuthProvider, useAuth } from "@/hooks/useAuth";
import { BrandThemeProvider } from "@/hooks/useBrandTheme";
import AdminRoute from "@/components/AdminRoute";
import CompanyWorkspaceRoute from "@/components/CompanyWorkspaceRoute";
import Index from "@/pages/Index";
import Login from "@/pages/Login";
import ResetPassword from "@/pages/ResetPassword";
import DashboardLayout from "@/pages/dashboard/DashboardLayout";
import Overview from "@/pages/dashboard/Overview";
import Clients from "@/pages/dashboard/Clients";
import Products from "@/pages/dashboard/Products";
import Charges from "@/pages/dashboard/Charges";
import Installments from "@/pages/dashboard/Installments";
import Messages from "@/pages/dashboard/Messages";
import Subscription from "@/pages/dashboard/Subscription";
import Settings from "@/pages/dashboard/Settings";
import MasterSettings from "@/pages/dashboard/MasterSettings";
import AdminPlans from "@/pages/admin/AdminPlans";
import AdminCompanies from "@/pages/admin/AdminCompanies";
import { NoTextNodes } from "@/components/NoTextNodes";
import { StripDomTextNodes } from "@/components/StripDomTextNodes";
import PayerPortal from "@/pages/PayerPortal";

const queryClient = new QueryClient();
const routerBasename = import.meta.env.BASE_URL.replace(/\/$/, "") || undefined;

function AuthGuard() {
  const { user, loading } = useAuth();
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }
  if (!user) {
    return <Navigate to="/login" replace />;
  }
  return <Outlet />;
}

function HomePage() {
  const { user, loading } = useAuth();
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }
  if (user) {
    return <Navigate to="/dashboard" replace />;
  }
  return <Index />;
}

const App = () => (
  <QueryClientProvider client={queryClient}>
    <BrandThemeProvider>
      <AuthProvider>
        <TooltipProvider>
          <StripDomTextNodes />
          <NoTextNodes>
            <Toaster />
            <Sonner />
            <BrowserRouter
              basename={routerBasename}
              future={{ v7_relativeSplatPath: true, v7_startTransition: true }}
            >
            <Routes>
              <Route path="/" element={<HomePage />} />
              <Route path="/login" element={<Login />} />
              <Route path="/reset-password" element={<ResetPassword />} />
              <Route path="/payer/:id" element={<PayerPortal />} />

              <Route element={<AuthGuard />}>
                <Route element={<DashboardLayout />}>
                  <Route path="/dashboard" element={<Overview />} />
                  <Route
                    path="/dashboard/clients"
                    element={
                      <CompanyWorkspaceRoute>
                        <Clients />
                      </CompanyWorkspaceRoute>
                    }
                  />
                  <Route
                    path="/dashboard/products"
                    element={
                      <CompanyWorkspaceRoute>
                        <Products />
                      </CompanyWorkspaceRoute>
                    }
                  />
                  <Route
                    path="/dashboard/charges"
                    element={
                      <CompanyWorkspaceRoute>
                        <Charges />
                      </CompanyWorkspaceRoute>
                    }
                  />
                  <Route
                    path="/dashboard/installments"
                    element={
                      <CompanyWorkspaceRoute>
                        <Installments />
                      </CompanyWorkspaceRoute>
                    }
                  />
                  <Route
                    path="/dashboard/subscription"
                    element={
                      <CompanyWorkspaceRoute>
                        <Subscription />
                      </CompanyWorkspaceRoute>
                    }
                  />
                  <Route
                    path="/dashboard/messages"
                    element={
                      <CompanyWorkspaceRoute>
                        <Messages />
                      </CompanyWorkspaceRoute>
                    }
                  />
                  <Route
                    path="/dashboard/settings"
                    element={
                      <CompanyWorkspaceRoute>
                        <Settings />
                      </CompanyWorkspaceRoute>
                    }
                  />
                  <Route path="/dashboard/master-settings" element={<MasterSettings />} />
                  <Route path="/admin/plans" element={<AdminRoute><AdminPlans /></AdminRoute>} />
                  <Route path="/admin/companies" element={<AdminRoute><AdminCompanies /></AdminRoute>} />
                </Route>
              </Route>

              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
            </BrowserRouter>
          </NoTextNodes>
        </TooltipProvider>
      </AuthProvider>
    </BrandThemeProvider>
  </QueryClientProvider>
);

export default App;
