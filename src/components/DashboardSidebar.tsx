import { Link, useLocation, useNavigate } from "react-router-dom";
import { useAuth, type AppUser } from "@/hooks/useAuth";
import { useBrandTheme } from "@/hooks/useBrandTheme";
import {
  LayoutDashboard,
  Users,
  FileText,
  MessageSquare,
  Settings,
  Zap,
  LogOut,
  CreditCard,
  ReceiptText,
  Package,
  Shield,
  Gem,
  Building2,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { isPlatformAdminWithoutCompany } from "@/lib/platformAdmin";

type MenuItem = { icon: typeof LayoutDashboard; label: string; path: string };

const buildMenuItems = (user: AppUser | null): MenuItem[] => {
  const platformOnly = isPlatformAdminWithoutCompany(user);
  const isAdmin = Boolean(user?.roles?.includes("admin"));

  if (platformOnly) {
    return [
      { icon: LayoutDashboard, label: "Dashboard", path: "/dashboard" },
      { icon: Shield, label: "Config. master", path: "/dashboard/master-settings" },
      { icon: Building2, label: "Clientes", path: "/admin/companies" },
      { icon: Gem, label: "Planos (SaaS)", path: "/admin/plans" },
    ];
  }

  const items: MenuItem[] = [
    { icon: LayoutDashboard, label: "Visão Geral", path: "/dashboard" },
    { icon: Users, label: "Clientes", path: "/dashboard/clients" },
    { icon: Package, label: "Produtos", path: "/dashboard/products" },
    { icon: FileText, label: "Cobranças", path: "/dashboard/charges" },
    { icon: CreditCard, label: "Parcelas", path: "/dashboard/installments" },
    { icon: ReceiptText, label: "Assinatura", path: "/dashboard/subscription" },
    { icon: MessageSquare, label: "Mensagens", path: "/dashboard/messages" },
    { icon: Settings, label: "Configurações", path: "/dashboard/settings" },
  ];
  if (isAdmin) {
    items.push({ icon: Shield, label: "Config. master", path: "/dashboard/master-settings" });
    items.push({ icon: Building2, label: "Empresas (SaaS)", path: "/admin/companies" });
    items.push({ icon: Gem, label: "Planos (SaaS)", path: "/admin/plans" });
  }
  return items;
};

const DashboardSidebar = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { signOut, user } = useAuth();
  const { systemName } = useBrandTheme();
  const menuItems = buildMenuItems(user);

  const handleSignOut = async () => {
    await signOut();
    navigate("/login");
  };

  const linkActive = (path: string) => {
    if (path === "/dashboard") {
      return location.pathname === "/dashboard" || location.pathname === "/dashboard/";
    }
    return location.pathname === path || location.pathname.startsWith(`${path}/`);
  };

  return (
    <aside className="fixed left-0 top-0 bottom-0 z-40 flex w-64 flex-col bg-sidebar border-r border-sidebar-border">
      <div className="flex h-16 items-center gap-2 px-6 border-b border-sidebar-border">
        <div className="flex h-7 w-7 items-center justify-center rounded-lg gradient-accent">
          <Zap className="h-3.5 w-3.5 text-accent-foreground" />
        </div>
        <span className="text-sm font-bold text-sidebar-foreground">{systemName}</span>
      </div>

      <nav className="flex-1 px-3 py-4 space-y-1">
        {menuItems.map((item) => {
          const isActive = linkActive(item.path);
          return (
            <Link
              key={item.path}
              to={item.path}
              className={cn(
                "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors",
                isActive
                  ? "bg-sidebar-accent text-sidebar-primary"
                  : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground"
              )}
            >
              <item.icon className="h-4 w-4" />
              {item.label}
            </Link>
          );
        })}
      </nav>

      <div className="px-3 py-4 border-t border-sidebar-border">
        <button
          type="button"
          onClick={handleSignOut}
          className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-sidebar-foreground/50 hover:text-sidebar-foreground transition-colors"
        >
          <LogOut className="h-4 w-4" />
          Sair
        </button>
      </div>
    </aside>
  );
};

export default DashboardSidebar;
