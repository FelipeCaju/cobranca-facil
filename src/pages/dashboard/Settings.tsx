import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Switch } from "@/components/ui/switch";
import { useToast } from "@/hooks/use-toast";
import { Save, Mail, Send, Smartphone, QrCode, Unplug, RefreshCw, Link2, Copy, KeyRound } from "lucide-react";
import { apiFetch, resolveApiUrl } from "@/lib/api";
import { useAuth } from "@/hooks/useAuth";
import { QRCodeSVG } from "qrcode.react";
import { PaymentAccounts } from "@/components/PaymentAccounts";

interface MailSettingsGet {
  smtp_use_custom: boolean;
  smtp_host: string;
  smtp_port: number;
  smtp_encryption: string;
  smtp_username: string;
  smtp_from_email: string;
  smtp_from_name: string;
  smtp_password_set: boolean;
  smtp_password_masked?: string;
}

interface CompanyProfileGet {
  name: string;
  cnpj: string;
  email: string;
  phone: string;
}

interface PaymentSettingsGet {
  payment_gateway: string;
  gateway_api_key_set?: boolean;
  gateway_api_key_masked?: string;
  gateway_public_key: string;
  gateway_environment: string;
}

interface WhatsappConnectionGet {
  instance_name: string | null;
  connection_state: string;
  connected: boolean;
  qrcode_base64: string | null;
  pairing_code: string | null;
  /** String de ligação WhatsApp (Evolution v2); gera o QR no browser se não houver imagem base64. */
  qrcode_connection_code: string | null;
  qr_generated_at?: number | null;
}

const Settings = () => {
  const { toast } = useToast();
  const { user: authUser } = useAuth();
  const [settingsTab, setSettingsTab] = useState("company");

  const [profileLoading, setProfileLoading] = useState(true);
  const [profileSaving, setProfileSaving] = useState(false);
  const [coName, setCoName] = useState("");
  const [coCnpj, setCoCnpj] = useState("");
  const [coEmail, setCoEmail] = useState("");
  const [coPhone, setCoPhone] = useState("");

  const copyToClipboard = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast({ title: "Copiado", description: "Copiado para a área de transferência." });
    } catch {
      toast({ title: "Não foi possível copiar", description: "Permita o acesso à área de transferência no browser.", variant: "destructive" });
    }
  };
  const [mailLoading, setMailLoading] = useState(true);
  const [mailSaving, setMailSaving] = useState(false);
  const [useCustom, setUseCustom] = useState(false);
  const [host, setHost] = useState("");
  const [port, setPort] = useState("587");
  const [enc, setEnc] = useState("tls");
  const [user, setUser] = useState("");
  const [pass, setPass] = useState("");
  const [fromEmail, setFromEmail] = useState("");
  const [fromName, setFromName] = useState("");
  const [maskPass, setMaskPass] = useState("");
  const [testTo, setTestTo] = useState("");
  const [testSending, setTestSending] = useState(false);

  const [waLoading, setWaLoading] = useState(true);
  const [wa, setWa] = useState<WhatsappConnectionGet | null>(null);
  const [waBusy, setWaBusy] = useState<string | null>(null);
  const [qrRenderKey, setQrRenderKey] = useState(0);

  const [paymentLoading, setPaymentLoading] = useState(true);
  const [paymentSaving, setPaymentSaving] = useState(false);
  const [paymentGateway, setPaymentGateway] = useState("mercadopago");
  const [paymentApiKey, setPaymentApiKey] = useState("");
  const [paymentApiKeyMask, setPaymentApiKeyMask] = useState("");
  const [paymentPublicKey, setPaymentPublicKey] = useState("");
  const [paymentEnv, setPaymentEnv] = useState("sandbox");
  const [passwordSaving, setPasswordSaving] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");

  useEffect(() => {
    let ok = true;
    (async () => {
      try {
        const d = await apiFetch<CompanyProfileGet>("/api/company/profile");
        if (!ok) return;
        setCoName(d.name ?? "");
        setCoCnpj(d.cnpj ?? "");
        setCoEmail(d.email ?? "");
        setCoPhone(d.phone ?? "");
      } catch (e) {
        toast({
          title: "Erro ao carregar dados da empresa",
          description: e instanceof Error ? e.message : "Falha na API",
          variant: "destructive",
        });
      } finally {
        if (ok) setProfileLoading(false);
      }
    })();
    return () => {
      ok = false;
    };
  }, [toast]);

  useEffect(() => {
    let ok = true;
    (async () => {
      try {
        const d = await apiFetch<PaymentSettingsGet>("/api/company/payment-settings");
        if (!ok) return;
        setPaymentGateway(d.payment_gateway === "asaas" ? "asaas" : "mercadopago");
        setPaymentApiKey("");
        setPaymentApiKeyMask(d.gateway_api_key_masked ?? "");
        setPaymentPublicKey(d.gateway_public_key ?? "");
        setPaymentEnv(d.gateway_environment === "production" ? "production" : "sandbox");
      } catch (e) {
        toast({
          title: "Erro ao carregar pagamento",
          description: e instanceof Error ? e.message : "Falha na API",
          variant: "destructive",
        });
      } finally {
        if (ok) setPaymentLoading(false);
      }
    })();
    return () => {
      ok = false;
    };
  }, [toast]);

  useEffect(() => {
    let ok = true;
    (async () => {
      try {
        const d = await apiFetch<MailSettingsGet>("/api/company/mail-settings");
        if (!ok) return;
        setUseCustom(Boolean(d.smtp_use_custom));
        setHost(d.smtp_host ?? "");
        setPort(String(d.smtp_port ?? 587));
        setEnc(d.smtp_encryption === "ssl" || d.smtp_encryption === "none" ? d.smtp_encryption : "tls");
        setUser(d.smtp_username ?? "");
        setFromEmail(d.smtp_from_email ?? "");
        setFromName(d.smtp_from_name ?? "");
        setMaskPass(d.smtp_password_masked ?? "");
      } catch (e) {
        toast({
          title: "Erro ao carregar email",
          description: e instanceof Error ? e.message : "Falha na API",
          variant: "destructive",
        });
      } finally {
        if (ok) setMailLoading(false);
      }
    })();
    return () => {
      ok = false;
    };
  }, [toast]);

  useEffect(() => {
    let ok = true;
    (async () => {
      try {
        const d = await apiFetch<WhatsappConnectionGet>("/api/company/whatsapp-connection");
        if (!ok) return;
        setWa(d);
      } catch {
        toast({
          title: "Não foi possível carregar o estado do WhatsApp",
          description: "Atualize a página ou tente mais tarde.",
          variant: "destructive",
        });
      } finally {
        if (ok) setWaLoading(false);
      }
    })();
    return () => {
      ok = false;
    };
  }, [toast]);

  const saveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setProfileSaving(true);
    try {
      const d = await apiFetch<CompanyProfileGet>("/api/company/profile", {
        method: "PUT",
        body: JSON.stringify({
          name: coName.trim(),
          cnpj: coCnpj.trim(),
          email: coEmail.trim(),
          phone: coPhone.replace(/\D/g, ""),
        }),
      });
      setCoName(d.name ?? "");
      setCoCnpj(d.cnpj ?? "");
      setCoEmail(d.email ?? "");
      setCoPhone(d.phone ?? "");
      toast({ title: "Dados da empresa guardados" });
    } catch (err) {
      toast({
        title: "Erro ao guardar",
        description: err instanceof Error ? err.message : "Falha na API",
        variant: "destructive",
      });
    } finally {
      setProfileSaving(false);
    }
  };

  const reloadWa = async () => {
    const d = await apiFetch<WhatsappConnectionGet>("/api/company/whatsapp-connection");
    setWa(d);
    return d;
  };

  const createWa = async () => {
    setWaBusy("create");
    try {
      await apiFetch<WhatsappConnectionGet>("/api/company/whatsapp-connection/create", { method: "POST" });
      const d = await reloadWa();
      if (d.instance_name) {
        toast({ title: "Ligação criada", description: "Leia o código QR com o WhatsApp que envia as cobranças." });
      } else {
        toast({
          title: "Sessão não confirmada",
          description: "Confirme na base de dados a coluna evolution_instance_name (migração) e os logs do PHP.",
          variant: "destructive",
        });
      }
    } catch {
      toast({
        title: "Não foi possível criar a ligação",
        description: "Tente novamente dentro de poucos minutos.",
        variant: "destructive",
      });
      try {
        await reloadWa();
      } catch {
        /* ignore */
      }
    } finally {
      setWaBusy(null);
    }
  };

  const refreshWaQr = async () => {
    setWaBusy("qr");
    try {
      const fresh = await apiFetch<Partial<WhatsappConnectionGet>>(
        `/api/company/whatsapp-connection/qrcode?_=${Date.now()}`,
      );
      if (!fresh.qrcode_base64 && !fresh.qrcode_connection_code && !fresh.pairing_code) {
        throw new Error("A Evolution API não devolveu um QR code novo. Tente desconectar e criar a conexão novamente.");
      }
      setWa((current) => {
        if (!current) {
          return {
            instance_name: fresh.instance_name ?? null,
            connection_state: fresh.connection_state ?? "connecting",
            connected: Boolean(fresh.connected),
            qrcode_base64: fresh.qrcode_base64 ?? null,
            pairing_code: fresh.pairing_code ?? null,
            qrcode_connection_code: fresh.qrcode_connection_code ?? null,
            qr_generated_at: fresh.qr_generated_at ?? Date.now(),
          };
        }

        return {
          ...current,
          connection_state: fresh.connection_state ?? current.connection_state,
          connected: Boolean(fresh.connected ?? current.connected),
          qrcode_base64: fresh.qrcode_base64 ?? current.qrcode_base64,
          pairing_code: fresh.pairing_code ?? current.pairing_code,
          qrcode_connection_code: fresh.qrcode_connection_code ?? current.qrcode_connection_code,
          qr_generated_at: fresh.qr_generated_at ?? Date.now(),
        };
      });
      setQrRenderKey((v) => v + 1);
      toast({ title: "QR code atualizado" });
    } catch (err) {
      toast({
        title: "Não foi possível atualizar o código",
        description: err instanceof Error ? err.message : "Tente novamente dentro de poucos minutos.",
        variant: "destructive",
      });
    } finally {
      setWaBusy(null);
    }
  };

  const disconnectWa = async () => {
    setWaBusy("disconnect");
    try {
      const d = await apiFetch<WhatsappConnectionGet>("/api/company/whatsapp-connection/disconnect", { method: "POST" });
      setWa(d);
      toast({ title: "WhatsApp desligado", description: "Pode criar uma nova conexão quando precisar." });
    } catch {
      toast({
        title: "Não foi possível desligar",
        description: "Tente novamente dentro de poucos minutos.",
        variant: "destructive",
      });
    } finally {
      setWaBusy(null);
    }
  };

  const saveMail = async (e: React.FormEvent) => {
    e.preventDefault();
    setMailSaving(true);
    try {
      const p = Math.max(1, Math.min(65535, parseInt(port, 10) || 587));
      await apiFetch("/api/company/mail-settings", {
        method: "PUT",
        body: JSON.stringify({
          smtp_use_custom: useCustom,
          smtp_host: host,
          smtp_port: p,
          smtp_encryption: enc,
          smtp_username: user,
          smtp_password: pass,
          smtp_from_email: fromEmail,
          smtp_from_name: fromName,
        }),
      });
      toast({ title: "Configuração de email guardada" });
      setPass("");
      const d = await apiFetch<MailSettingsGet>("/api/company/mail-settings");
      setMaskPass(d.smtp_password_masked ?? "");
    } catch (err) {
      toast({
        title: "Erro ao guardar",
        description: err instanceof Error ? err.message : "Falha",
        variant: "destructive",
      });
    } finally {
      setMailSaving(false);
    }
  };

  const savePayment = async (e: React.FormEvent) => {
    e.preventDefault();
    setPaymentSaving(true);
    try {
      await apiFetch("/api/company/payment-settings", {
        method: "PUT",
        body: JSON.stringify({
          payment_gateway: paymentGateway,
          gateway_api_key: paymentApiKey.trim(),
          gateway_public_key: paymentGateway === "mercadopago" ? paymentPublicKey.trim() : "",
          gateway_environment: paymentEnv,
        }),
      });
      toast({ title: "Credenciais de pagamento guardadas" });
      const d = await apiFetch<PaymentSettingsGet>("/api/company/payment-settings");
      setPaymentGateway(d.payment_gateway === "asaas" ? "asaas" : "mercadopago");
      setPaymentApiKey("");
      setPaymentApiKeyMask(d.gateway_api_key_masked ?? "");
      setPaymentPublicKey(d.gateway_public_key ?? "");
      setPaymentEnv(d.gateway_environment === "production" ? "production" : "sandbox");
    } catch (err) {
      toast({
        title: "Erro ao guardar pagamento",
        description: err instanceof Error ? err.message : "Falha",
        variant: "destructive",
      });
    } finally {
      setPaymentSaving(false);
    }
  };

  const sendTest = async () => {
    if (!testTo.trim()) {
      toast({ title: "Indique o email de destino", variant: "destructive" });
      return;
    }
    setTestSending(true);
    try {
      const r = await apiFetch<{ ok: boolean; via?: string }>("/api/company/mail-settings/test", {
        method: "POST",
        body: JSON.stringify({ to: testTo.trim() }),
      });
      toast({
        title: "Email de teste enviado",
        description: r.via ? `Enviado via SMTP ${r.via === "company" ? "próprio" : "da plataforma (master)"}.` : undefined,
      });
    } catch (err) {
      toast({
        title: "Falha no teste",
        description: err instanceof Error ? err.message : "Erro",
        variant: "destructive",
      });
    } finally {
      setTestSending(false);
    }
  };

  const savePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setPasswordSaving(true);
    try {
      await apiFetch("/api/auth/change-password", {
        method: "POST",
        body: JSON.stringify({
          current_password: currentPassword,
          new_password: newPassword,
          confirm_password: confirmPassword,
        }),
      });
      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
      toast({ title: "Senha atualizada" });
    } catch (err) {
      toast({
        title: "Erro ao alterar senha",
        description: err instanceof Error ? err.message : "Falha",
        variant: "destructive",
      });
    } finally {
      setPasswordSaving(false);
    }
  };

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-foreground">Configurações</h1>
      </div>

      <Tabs value={settingsTab} onValueChange={setSettingsTab}>
        <TabsList className="mb-4 flex flex-wrap h-auto gap-1">
          <TabsTrigger value="company">Empresa</TabsTrigger>
          <TabsTrigger value="email">Email (SMTP)</TabsTrigger>
          <TabsTrigger value="payment">Pagamento</TabsTrigger>
          <TabsTrigger value="security">Segurança</TabsTrigger>
          <TabsTrigger value="connection">Conexão WhatsApp</TabsTrigger>
        </TabsList>

        <TabsContent value="company">
          {profileLoading ? (
            <div className="flex min-h-[200px] items-center justify-center text-muted-foreground">A carregar…</div>
          ) : (
            <form onSubmit={saveProfile} className="max-w-xl">
              <Card className="shadow-card p-5">
                <h2 className="text-sm font-semibold text-card-foreground mb-4">Dados da Empresa</h2>
                <p className="text-sm text-muted-foreground mb-4">
                  O telefone/WhatsApp informado no cadastro aparece aqui e é usado em lembretes e comunicações da plataforma.
                </p>
                <div className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="co-name">Nome da Empresa</Label>
                    <Input id="co-name" value={coName} onChange={(e) => setCoName(e.target.value)} required />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="co-cnpj">CNPJ</Label>
                    <Input id="co-cnpj" value={coCnpj} onChange={(e) => setCoCnpj(e.target.value)} placeholder="Opcional" />
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="co-email">Email de Contato</Label>
                      <Input id="co-email" type="email" value={coEmail} onChange={(e) => setCoEmail(e.target.value)} />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="co-phone">Telefone da empresa (WhatsApp)</Label>
                      <Input
                        id="co-phone"
                        type="tel"
                        inputMode="numeric"
                        value={coPhone}
                        onChange={(e) => setCoPhone(e.target.value)}
                        placeholder="5511999999999"
                      />
                    </div>
                  </div>
                  <Button type="submit" variant="hero" disabled={profileSaving}>
                    <Save className="h-4 w-4 mr-2" />
                    {profileSaving ? "A guardar…" : "Guardar dados"}
                  </Button>
                </div>
              </Card>
            </form>
          )}
        </TabsContent>

        <TabsContent value="email">
          {mailLoading ? (
            <div className="flex min-h-[200px] items-center justify-center text-muted-foreground">A carregar…</div>
          ) : (
            <form onSubmit={saveMail} className="space-y-6 max-w-2xl">
              <Card className="shadow-card p-5 border-border">
                <div className="flex items-center gap-2 mb-2">
                  <Mail className="h-4 w-4 text-accent" />
                  <h2 className="font-semibold text-foreground">Envio de cobranças por email</h2>
                </div>
                <p className="text-sm text-muted-foreground mb-4">
                  Por defeito, as empresas usam o <strong className="text-foreground">SMTP configurado na administração master</strong>.
                  Ative a opção abaixo apenas se quiser enviar a partir do seu próprio servidor de email.
                </p>
                <div className="flex items-center justify-between rounded-lg border border-border px-3 py-3 mb-6">
                  <div>
                    <p className="text-sm font-medium text-foreground">Usar SMTP próprio desta empresa</p>
                    <p className="text-xs text-muted-foreground mt-0.5">Quando desligado, usa sempre o servidor master.</p>
                  </div>
                  <Switch checked={useCustom} onCheckedChange={setUseCustom} />
                </div>

                {useCustom ? (
                  <div className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div className="space-y-2 sm:col-span-2">
                        <Label>Servidor SMTP</Label>
                        <Input value={host} onChange={(e) => setHost(e.target.value)} placeholder="smtp.gmail.com" required={useCustom} />
                      </div>
                      <div className="space-y-2">
                        <Label>Porta</Label>
                        <Input type="number" min={1} max={65535} value={port} onChange={(e) => setPort(e.target.value)} />
                      </div>
                      <div className="space-y-2">
                        <Label>Encriptação</Label>
                        <Select value={enc} onValueChange={setEnc}>
                          <SelectTrigger>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="tls">TLS</SelectItem>
                            <SelectItem value="ssl">SSL</SelectItem>
                            <SelectItem value="none">Nenhuma</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                      <div className="space-y-2 sm:col-span-2">
                        <Label>Utilizador SMTP</Label>
                        <Input value={user} onChange={(e) => setUser(e.target.value)} autoComplete="off" />
                      </div>
                      <div className="space-y-2 sm:col-span-2">
                        <Label>Palavra-passe SMTP</Label>
                        <Input
                          type="password"
                          value={pass}
                          onChange={(e) => setPass(e.target.value)}
                          placeholder={maskPass ? `Atual: ${maskPass} — preencha para alterar` : "Obrigatório na primeira vez"}
                          autoComplete="new-password"
                        />
                      </div>
                      <div className="space-y-2 sm:col-span-2">
                        <Label>Email remetente</Label>
                        <Input type="email" value={fromEmail} onChange={(e) => setFromEmail(e.target.value)} required={useCustom} />
                      </div>
                      <div className="space-y-2 sm:col-span-2">
                        <Label>Nome remetente (opcional)</Label>
                        <Input value={fromName} onChange={(e) => setFromName(e.target.value)} />
                      </div>
                    </div>
                  </div>
                ) : null}

                <div className="flex flex-wrap gap-3 mt-8 pt-6 border-t border-border">
                  <Button type="submit" variant="hero" disabled={mailSaving}>
                    <Save className="h-4 w-4 mr-2" />
                    {mailSaving ? "A guardar…" : "Guardar email"}
                  </Button>
                </div>
              </Card>

              <Card className="shadow-card p-5 max-w-2xl">
                <h3 className="text-sm font-semibold text-foreground mb-2">Testar envio</h3>
                <p className="text-xs text-muted-foreground mb-4">
                  Usa o SMTP efetivo (próprio se ativo e válido; caso contrário o master). Confirme que o destino recebe a mensagem.
                </p>
                <div className="flex flex-col sm:flex-row gap-3 sm:items-end">
                  <div className="space-y-2 flex-1">
                    <Label htmlFor="test-to">Email de destino</Label>
                    <Input id="test-to" type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="seu@email.com" />
                  </div>
                  <Button type="button" variant="secondary" onClick={() => void sendTest()} disabled={testSending}>
                    <Send className="h-4 w-4 mr-2" />
                    {testSending ? "A enviar…" : "Enviar teste"}
                  </Button>
                </div>
              </Card>
            </form>
          )}
        </TabsContent>

        <TabsContent value="payment">
          <PaymentAccounts />
          {paymentLoading ? (
            <div className="flex min-h-[200px] items-center justify-center text-muted-foreground">A carregar…</div>
          ) : (
          <form onSubmit={savePayment} className="space-y-6 max-w-xl">
            {authUser?.company_id ? (
              <Card className="shadow-card p-5 border-primary/20 bg-muted/10">
                <h2 className="text-sm font-semibold text-card-foreground mb-2">Webhooks de confirmação automática</h2>
                <p className="text-xs text-muted-foreground mb-4 leading-relaxed">
                  Cada empresa deve configurar no painel do Mercado Pago ou do Asaas (ou no banco que integrar) o URL abaixo
                  correspondente, para que, quando um pagamento for concluído, o sistema receba a confirmação na hora e possa
                  dar baixa nas parcelas. Substitua o domínio se a API estiver noutro host (use o mesmo endereço base que o
                  front-end usa para chamar <code className="text-[11px]">/api/</code>).
                </p>
                <div className="space-y-3 text-xs">
                  <div>
                    <Label className="text-muted-foreground">Mercado Pago</Label>
                    <div className="mt-1 flex gap-2">
                      <Input
                        readOnly
                        className="font-mono text-[11px] min-w-0 flex-1"
                        value={resolveApiUrl(`api/webhooks/payment/mercadopago/${authUser.company_id}`)}
                      />
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="shrink-0"
                        onClick={() =>
                          void copyToClipboard(resolveApiUrl(`api/webhooks/payment/mercadopago/${authUser.company_id}`))
                        }
                      >
                        <Copy className="h-3.5 w-3.5 mr-1.5" />
                        Copiar
                      </Button>
                    </div>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Asaas</Label>
                    <div className="mt-1 flex gap-2">
                      <Input
                        readOnly
                        className="font-mono text-[11px] min-w-0 flex-1"
                        value={resolveApiUrl(`api/webhooks/payment/asaas/${authUser.company_id}`)}
                      />
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="shrink-0"
                        onClick={() =>
                          void copyToClipboard(resolveApiUrl(`api/webhooks/payment/asaas/${authUser.company_id}`))
                        }
                      >
                        <Copy className="h-3.5 w-3.5 mr-1.5" />
                        Copiar
                      </Button>
                    </div>
                  </div>
                </div>
              </Card>
            ) : null}
            <Card className="shadow-card p-5">
              <h2 className="text-sm font-semibold text-card-foreground mb-4">Gateway de pagamento</h2>
              <p className="text-xs text-muted-foreground mb-4">
                Guarde aqui as credenciais usadas para gerar e confirmar cobranças da empresa.
              </p>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label>Gateway</Label>
                  <Select value={paymentGateway} onValueChange={setPaymentGateway}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="mercadopago">Mercado Pago</SelectItem>
                      <SelectItem value="asaas">Asaas</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>{paymentGateway === "mercadopago" ? "Access Token" : "API Key"}</Label>
                  <Input
                    type="password"
                    value={paymentApiKey}
                    onChange={(e) => setPaymentApiKey(e.target.value)}
                    placeholder={
                      paymentApiKeyMask
                        ? `Atual: ${paymentApiKeyMask} - preencha para substituir`
                        : paymentGateway === "mercadopago"
                          ? "APP_USR-..."
                          : "$aact_..."
                    }
                    autoComplete="new-password"
                  />
                  <p className="text-xs text-muted-foreground">Deixe em branco para manter a chave atual.</p>
                </div>
                {paymentGateway === "mercadopago" ? (
                  <div className="space-y-2">
                    <Label>Public Key</Label>
                    <Input value={paymentPublicKey} onChange={(e) => setPaymentPublicKey(e.target.value)} placeholder="APP_USR-..." />
                  </div>
                ) : null}
                <div className="space-y-2">
                  <Label>Ambiente</Label>
                  <Select value={paymentEnv} onValueChange={setPaymentEnv}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="sandbox">Sandbox (Teste)</SelectItem>
                      <SelectItem value="production">Produção</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <Button type="submit" variant="hero" disabled={paymentSaving}>
                  <Save className="h-4 w-4 mr-2" />
                  {paymentSaving ? "A guardar…" : "Guardar credenciais"}
                </Button>
              </div>
            </Card>
          </form>
          )}
        </TabsContent>

        <TabsContent value="connection">
          {settingsTab !== "connection" ? null : waLoading || wa === undefined ? (
            <div className="flex min-h-[200px] items-center justify-center text-muted-foreground">A carregar…</div>
          ) : (
            <Card className="shadow-card p-5 max-w-2xl">
              <div className="flex items-center gap-2 mb-2">
                <Smartphone className="h-4 w-4 text-accent" />
                <h2 className="font-semibold text-foreground">WhatsApp</h2>
              </div>
              <p className="text-sm text-muted-foreground mb-6">
                Ligue o telemóvel que envia lembretes e cobranças: crie a sessão e leia o código QR com o WhatsApp desse número.
              </p>

              {!wa.instance_name ? (
                <div className="space-y-4">
                  <p className="text-sm text-muted-foreground">Ainda não existe sessão para esta empresa.</p>
                  <Button type="button" variant="hero" onClick={() => void createWa()} disabled={waBusy !== null}>
                    <Link2 className="h-4 w-4 mr-2" />
                    {waBusy === "create" ? "A criar…" : "Criar e conectar"}
                  </Button>
                </div>
              ) : (
                <div className="space-y-6">
                  <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-muted-foreground">Estado:</span>
                    {wa.connected ? (
                      <span className="rounded-full bg-success/15 text-success px-2 py-0.5 font-medium">Ligado</span>
                    ) : (
                      <span className="rounded-full bg-warning/15 text-warning px-2 py-0.5 font-medium">
                        {wa.connection_state === "connecting" ? "A ligar…" : "Aguarda leitura do QR"}
                      </span>
                    )}
                    <span className="text-xs text-muted-foreground font-mono">({wa.instance_name})</span>
                  </div>

                  <div className="flex flex-wrap gap-2">
                    {!wa.connected && (
                      <Button type="button" variant="secondary" onClick={() => void refreshWaQr()} disabled={waBusy !== null}>
                        <RefreshCw className={`h-4 w-4 mr-2 ${waBusy === "qr" ? "animate-spin" : ""}`} />
                        {waBusy === "qr" ? "A atualizar…" : "Atualizar QR code"}
                      </Button>
                    )}
                    <Button type="button" variant="outline" onClick={() => void disconnectWa()} disabled={waBusy !== null}>
                      <Unplug className="h-4 w-4 mr-2" />
                      {waBusy === "disconnect" ? "A desligar…" : "Desligar sessão"}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => void reloadWa()} disabled={waBusy !== null}>
                      Atualizar estado
                    </Button>
                  </div>

                  {!wa.connected && (wa.qrcode_base64 || wa.qrcode_connection_code || wa.pairing_code) ? (
                    <div className="rounded-lg border border-border p-4 space-y-4">
                      <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                        <QrCode className="h-4 w-4" />
                        Leia o QR no WhatsApp
                      </div>
                      <p className="text-xs text-muted-foreground">
                        WhatsApp → Definições → Aparelhos ligados → Ligar um aparelho → Lê o código QR.
                      </p>
                      {wa.qrcode_base64 ? (
                        <div className="flex justify-center bg-white rounded-md p-3 w-fit mx-auto">
                          <img
                            key={`${qrRenderKey}-${wa.qr_generated_at ?? ""}-${wa.qrcode_base64.slice(0, 32)}`}
                            alt="QR code WhatsApp"
                            className="max-w-[260px] w-full h-auto"
                            src={
                              wa.qrcode_base64.startsWith("data:")
                                ? wa.qrcode_base64
                                : `data:image/png;base64,${wa.qrcode_base64}`
                            }
                          />
                        </div>
                      ) : wa.qrcode_connection_code ? (
                        <div className="flex justify-center bg-white rounded-md p-3 w-fit mx-auto [&_svg]:max-w-full">
                          <QRCodeSVG
                            key={`${qrRenderKey}-${wa.qr_generated_at ?? ""}-${wa.qrcode_connection_code.slice(0, 32)}`}
                            value={wa.qrcode_connection_code}
                            size={260}
                            level="M"
                            marginSize={2}
                            title="Código QR WhatsApp"
                          />
                        </div>
                      ) : null}
                      {wa.pairing_code ? (
                        <p className="text-center text-sm">
                          Código de emparelhamento: <strong className="font-mono">{wa.pairing_code}</strong>
                        </p>
                      ) : null}
                    </div>
                  ) : null}

                  {wa.connected ? (
                    <p className="text-sm text-muted-foreground">
                      Este número está ligado e pode ser usado para envios automáticos de cobrança, conforme a lógica da aplicação.
                    </p>
                  ) : null}
                </div>
              )}
            </Card>
          )}
        </TabsContent>
        <TabsContent value="security">
          <form onSubmit={savePassword} className="max-w-xl">
            <Card className="shadow-card p-5">
              <div className="flex items-center gap-2 mb-2">
                <KeyRound className="h-4 w-4 text-accent" />
                <h2 className="font-semibold text-foreground">Alterar senha de acesso</h2>
              </div>
              <p className="text-sm text-muted-foreground mb-5">
                Atualize a senha usada para entrar neste painel.
              </p>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="current-password">Senha atual</Label>
                  <Input
                    id="current-password"
                    type="password"
                    autoComplete="current-password"
                    value={currentPassword}
                    onChange={(e) => setCurrentPassword(e.target.value)}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="new-password">Nova senha</Label>
                  <Input
                    id="new-password"
                    type="password"
                    autoComplete="new-password"
                    value={newPassword}
                    onChange={(e) => setNewPassword(e.target.value)}
                    required
                    minLength={6}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="confirm-password">Confirmar nova senha</Label>
                  <Input
                    id="confirm-password"
                    type="password"
                    autoComplete="new-password"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    required
                    minLength={6}
                  />
                </div>
                <Button type="submit" variant="hero" disabled={passwordSaving}>
                  <Save className="h-4 w-4 mr-2" />
                  {passwordSaving ? "A guardar..." : "Alterar senha"}
                </Button>
              </div>
            </Card>
          </form>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default Settings;
