import type { AppUser } from "@/hooks/useAuth";

/**
 * Igual à regra em `api/routes/company.php`: utilizador com role `admin` e sem
 * empresa como dono — consola da plataforma; rotas `/api/company/*` (exceto overview) devolvem 403.
 */
export function isPlatformAdminWithoutCompany(user: AppUser | null | undefined): boolean {
  if (!user?.roles?.includes("admin")) {
    return false;
  }
  return user.company_id == null || user.company_id === "";
}
