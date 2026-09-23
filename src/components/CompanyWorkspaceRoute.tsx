import { Navigate } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { isPlatformAdminWithoutCompany } from "@/lib/platformAdmin";

const CompanyWorkspaceRoute = ({ children }: { children: React.ReactNode }) => {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-[40vh] flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }

  if (isPlatformAdminWithoutCompany(user)) {
    return <Navigate to="/dashboard" replace />;
  }

  return children;
};

export default CompanyWorkspaceRoute;
