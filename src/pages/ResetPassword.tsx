import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Zap, Lock } from "lucide-react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { useToast } from "@/hooks/use-toast";
import { apiFetch, setStoredToken, notifyAuthChanged } from "@/lib/api";
import { useBrandTheme } from "@/hooks/useBrandTheme";

const ResetPassword = () => {
  const { systemName } = useBrandTheme();
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const { toast } = useToast();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get("token") ?? "";

  useEffect(() => {
    if (!token) {
      toast({ title: "Link inválido", description: "Use o link enviado por email.", variant: "destructive" });
    }
  }, [token, toast]);

  const handleReset = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) {
      toast({ title: "Link inválido", description: "Token ausente na URL.", variant: "destructive" });
      return;
    }
    setLoading(true);
    try {
      await apiFetch("/api/auth/reset-password", {
        method: "POST",
        body: JSON.stringify({ token, password }),
      });
      toast({ title: "Senha atualizada!", description: "Faça login com a nova senha." });
      setStoredToken(null);
      notifyAuthChanged();
      navigate("/login");
    } catch (err) {
      const message = err instanceof Error ? err.message : "Erro ao redefinir";
      toast({ title: "Erro", description: message, variant: "destructive" });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background px-4">
      <div className="w-full max-w-sm">
        <Link to="/" className="flex items-center justify-center gap-2 mb-8">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg gradient-accent">
            <Zap className="h-4 w-4 text-accent-foreground" />
          </div>
          <span className="text-lg font-bold text-foreground">{systemName}</span>
        </Link>
        <h1 className="text-xl font-bold text-foreground text-center mb-6">Redefinir Senha</h1>
        <form onSubmit={handleReset} className="space-y-4">
          <div className="space-y-2">
            <Label>Nova Senha</Label>
            <div className="relative">
              <Lock className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
              <Input type="password" placeholder="••••••••" className="pl-9" required minLength={6} value={password} onChange={(e) => setPassword(e.target.value)} />
            </div>
          </div>
          <Button type="submit" className="w-full" disabled={loading || !token}>
            {loading ? "Salvando..." : "Redefinir Senha"}
          </Button>
        </form>
      </div>
    </div>
  );
};

export default ResetPassword;
