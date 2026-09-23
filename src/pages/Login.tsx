import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Zap, Mail, Lock, Building2, User, Smartphone } from "lucide-react";
import { Link, useSearchParams, useNavigate } from "react-router-dom";
import { useToast } from "@/hooks/use-toast";
import { useAuth } from "@/hooks/useAuth";
import { useBrandTheme } from "@/hooks/useBrandTheme";
import { apiFetch } from "@/lib/api";
import type { AppUser } from "@/hooks/useAuth";
import { toast as sonnerToast } from "sonner";
import { NoTextNodes } from "@/components/NoTextNodes";

const Login = () => {
  const [searchParams] = useSearchParams();
  const defaultTab = searchParams.get("tab") === "register" ? "register" : "login";
  const navigate = useNavigate();
  const { toast } = useToast();
  const { establishSession } = useAuth();
  const { systemName } = useBrandTheme();
  const [loading, setLoading] = useState(false);

  const [loginEmail, setLoginEmail] = useState("");
  const [loginPassword, setLoginPassword] = useState("");

  const [regCompany, setRegCompany] = useState("");
  const [regName, setRegName] = useState("");
  const [regEmail, setRegEmail] = useState("");
  const [regPassword, setRegPassword] = useState("");
  const [regPhone, setRegPhone] = useState("");

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setLoading(true);
    try {
      const data = await apiFetch<{ token: string; user: AppUser }>("/api/auth/login", {
        method: "POST",
        body: JSON.stringify({ email: loginEmail, password: loginPassword }),
      });
      establishSession(data.token, data.user);
      navigate("/dashboard");
    } catch (err) {
      const message = err instanceof Error ? err.message : "Erro ao entrar";
      toast({ title: "Erro ao entrar", description: message, variant: "destructive" });
      sonnerToast.error(message);
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setLoading(true);
    try {
      const data = await apiFetch<{ token: string; user: AppUser }>("/api/auth/register", {
        method: "POST",
        body: JSON.stringify({
          email: regEmail,
          password: regPassword,
          full_name: regName,
          company_name: regCompany,
          phone: regPhone.replace(/\D/g, ""),
        }),
      });
      establishSession(data.token, data.user);
      toast({
        title: "Conta criada!",
        description: `Bem-vindo ao ${systemName}.`,
      });
      sonnerToast.success("Conta criada!");
      navigate("/dashboard");
    } catch (err) {
      const message = err instanceof Error ? err.message : "Erro ao criar conta";
      toast({ title: "Erro ao criar conta", description: message, variant: "destructive" });
      sonnerToast.error(message);
    } finally {
      setLoading(false);
    }
  };

  const handleForgotPassword = async () => {
    if (!loginEmail) {
      toast({ title: "Informe seu email", description: "Preencha o campo de email primeiro.", variant: "destructive" });
      return;
    }
    try {
      await apiFetch("/api/auth/forgot-password", {
        method: "POST",
        body: JSON.stringify({ email: loginEmail }),
      });
      toast({
        title: "Email enviado!",
        description: "Se o email existir, você receberá instruções. Em desenvolvimento, o link também é registrado no log do PHP.",
      });
    } catch (err) {
      const message = err instanceof Error ? err.message : "Erro ao solicitar redefinição";
      toast({ title: "Erro", description: message, variant: "destructive" });
    }
  };

  return (
    <div className="min-h-screen flex">
      <NoTextNodes>
        <div className="flex flex-1 flex-col items-center justify-center px-4 py-12">
          <NoTextNodes>
            <Link to="/" className="flex items-center gap-2 mb-8">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg gradient-accent">
                <Zap className="h-4 w-4 text-accent-foreground" />
              </div>
              <span className="text-lg font-bold text-foreground">{systemName}</span>
            </Link>
            <div className="w-full max-w-sm">
              <Tabs defaultValue={defaultTab}>
                <TabsList className="grid w-full grid-cols-2 mb-6">
                  <TabsTrigger value="login">Entrar</TabsTrigger>
                  <TabsTrigger value="register">Criar Conta</TabsTrigger>
                </TabsList>
                <TabsContent value="login">
              <form onSubmit={handleLogin} className="space-y-4" method="post" action="#">
                <div className="space-y-2">
                  <Label htmlFor="email">Email</Label>
                  <div className="relative">
                    <Mail className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                    <Input id="email" type="email" placeholder="seu@email.com" className="pl-9" required value={loginEmail} onChange={(e) => setLoginEmail(e.target.value)} />
                  </div>
                </div>
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <Label htmlFor="password">Senha</Label>
                    <button type="button" onClick={handleForgotPassword} className="text-xs text-primary hover:underline">Esqueci minha senha</button>
                  </div>
                  <div className="relative">
                    <Lock className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                    <Input id="password" type="password" placeholder="••••••••" className="pl-9" required value={loginPassword} onChange={(e) => setLoginPassword(e.target.value)} />
                  </div>
                </div>
                <Button type="submit" className="w-full" disabled={loading}>
                  {loading ? "Entrando..." : "Entrar"}
                </Button>
              </form>
            </TabsContent>

            <TabsContent value="register">
              <form onSubmit={handleRegister} className="space-y-4 max-h-[min(70vh,520px)] overflow-y-auto pr-1" method="post" action="#">
                <div className="space-y-2">
                  <Label htmlFor="company">Nome da Empresa</Label>
                  <div className="relative">
                    <Building2 className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                    <Input id="company" placeholder="Sua Empresa Ltda" className="pl-9" required value={regCompany} onChange={(e) => setRegCompany(e.target.value)} />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="name">Seu Nome</Label>
                  <div className="relative">
                    <User className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                    <Input id="name" placeholder="João Silva" className="pl-9" required value={regName} onChange={(e) => setRegName(e.target.value)} />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="reg-email">Email</Label>
                  <div className="relative">
                    <Mail className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                    <Input id="reg-email" type="email" placeholder="seu@email.com" className="pl-9" required value={regEmail} onChange={(e) => setRegEmail(e.target.value)} />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="reg-phone">WhatsApp (com DDI)</Label>
                  <div className="relative">
                    <Smartphone className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                    <Input
                      id="reg-phone"
                      type="tel"
                      inputMode="numeric"
                      placeholder="5511999999999"
                      className="pl-9"
                      required
                      value={regPhone}
                      onChange={(e) => setRegPhone(e.target.value)}
                    />
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Usado para enviar a mensagem de boas-vindas e futuros lembretes. Apenas números (DDI + DDD + número).
                  </p>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="reg-password">Senha</Label>
                  <div className="relative">
                    <Lock className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                    <Input id="reg-password" type="password" placeholder="••••••••" className="pl-9" required value={regPassword} onChange={(e) => setRegPassword(e.target.value)} />
                  </div>
                </div>
                <Button type="submit" variant="hero" className="w-full" disabled={loading}>
                  {loading ? "Criando..." : "Criar Conta"}
                </Button>
              </form>
                </TabsContent>
              </Tabs>
            </div>
          </NoTextNodes>
        </div>
        <div className="hidden lg:flex flex-1 gradient-hero items-center justify-center p-12 relative overflow-hidden">
          <div className="absolute inset-0 bg-gradient-to-br from-accent/10 via-transparent to-primary/20 pointer-events-none" />
          <div className="max-w-md text-center relative z-10">
            <h2 className="text-3xl font-bold text-primary-foreground mb-4">Automatize suas cobranças</h2>
            <p className="text-primary-foreground/80 text-lg">
              Reduza inadimplência em até 70% com cobranças automáticas via PIX, notificações por WhatsApp e muito mais.
            </p>
          </div>
        </div>
      </NoTextNodes>
    </div>
  );
};

export default Login;
