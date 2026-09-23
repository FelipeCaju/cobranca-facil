import { useEffect, useState } from "react";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useToast } from "@/hooks/use-toast";
import { apiFetch, resolveApiUrl } from "@/lib/api";
import { Shield, CreditCard, MessageSquare, Mail, Clock, ExternalLink, RefreshCw, Palette, Smartphone, Link2, Unplug, QrCode, Copy, KeyRound, Save } from "lucide-react";
import { BRAND_ACCENT_PRESETS, normalizeBrandAccentKey, type BrandAccentKey } from "@/lib/brandAccent";
import { useBrandTheme } from "@/hooks/useBrandTheme";
import { cn } from "@/lib/utils";
import { QRCodeSVG } from "qrcode.react";

interface MasterGet {
  mercadopago_public_key: string;
  mercadopago_access_token_set: boolean;
  mercadopago_access_token_masked?: string;
  evolution_master_url: string;
  evolution_master_api_key_set: boolean;
  evolution_master_api_key_masked?: string;
  smtp_host: string;
  smtp_port: number;
  smtp_encryption: string;
  smtp_username: string;
  smtp_from_email: string;
  smtp_from_name: string;
  smtp_password_set: boolean;
  smtp_password_masked?: string;
  cron_secret_set?: boolean;
  cron_secret_masked?: string;
  cron_endpoint_url?: string | null;
  cronjob_api_key_set?: boolean;
  cronjob_api_key_masked?: string;
  cronjob_job_id?: number | null;
  cron_schedule_hour?: number;
  cron_schedule_minute?: number;
  mercadopago_plans_webhook_url?: string | null;
  mercadopago_plans_external_reference_hint?: string;
  brand_accent?: string;
  system_name?: string;
  master_whatsapp_instance_name?: string;
  notification_phone?: string;
}

interface MasterWhatsappState {
  instance_name: string | null;
  connection_state: string;
  connected: boolean;
  qrcode_base64: string | null;
  pairing_code: string | null;
  qrcode_connection_code: string | null;
  master_configured?: boolean;
  smtp_configured?: boolean;
}

const CRON_JOB_CONSOLE = "https://console.cron-job.org/jobs";
const CRON_JOB_API_DOCS = "https://docs.cron-job.org/rest-api.html";

const MasterSettings = () => {
  const { toast } = useToast();
  const { applyLocal, refresh: refreshBrandTheme } = useBrandTheme();
  const [loading, setLoading] = useState(true);
  const [brandAccent, setBrandAccent] = useState<BrandAccentKey>("amber");
  const [systemName, setSystemName] = useState("CobrançaFácil");
  const [saving, setSaving] = useState(false);
  const [mpPublic, setMpPublic] = useState("");
  const [mpToken, setMpToken] = useState("");
  const [evoUrl, setEvoUrl] = useState("");
  const [evoKey, setEvoKey] = useState("");
  const [maskMp, setMaskMp] = useState("");
  const [maskEvo, setMaskEvo] = useState("");

  const [smtpHost, setSmtpHost] = useState("");
  const [smtpPort, setSmtpPort] = useState("587");
  const [smtpEnc, setSmtpEnc] = useState("tls");
  const [smtpUser, setSmtpUser] = useState("");
  const [smtpPass, setSmtpPass] = useState("");
  const [smtpFrom, setSmtpFrom] = useState("");
  const [smtpFromName, setSmtpFromName] = useState("");
  const [maskSmtp, setMaskSmtp] = useState("");

  const [cronSecret, setCronSecret] = useState("");
  const [maskCron, setMaskCron] = useState("");
  const [cronEndpoint, setCronEndpoint] = useState<string | null>(null);
  const [cronjobApiKey, setCronjobApiKey] = useState("");
  const [maskCronjob, setMaskCronjob] = useState("");
  const [cronJobId, setCronJobId] = useState<number | null>(null);
  const [cronHour, setCronHour] = useState("8");
  const [cronMinute, setCronMinute] = useState("0");
  const [notificationPhone, setNotificationPhone] = useState("");
  const [masterTab, setMasterTab] = useState("appearance");
  const [wa, setWa] = useState<MasterWhatsappState | null>(null);
  const [waLoading, setWaLoading] = useState(false);
  const [waBusy, setWaBusy] = useState<string | null>(null);
  const [mpPlansWebhook, setMpPlansWebhook] = useState<string | null>(null);
  const [mpRefHint, setMpRefHint] = useState("cobx:{company_id}:{plan_id}");
  const [passwordSaving, setPasswordSaving] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");

  const mpPlansWebhookDisplay =
    mpPlansWebhook ?? resolveApiUrl("api/webhooks/plans/mercadopago");

  const copyMpWebhook = async () => {
    try {
      await navigator.clipboard.writeText(mpPlansWebhookDisplay);
      toast({ title: "Webhook copiado" });
    } catch {
      toast({ title: "Não foi possível copiar", variant: "destructive" });
    }
  };

  useEffect(() => {
    let ok = true;
    (async () => {
      try {
        const d = await apiFetch<MasterGet>("/api/admin/master-settings");
        if (!ok) return;
        setMpPublic(d.mercadopago_public_key ?? "");
        setEvoUrl(d.evolution_master_url ?? "");
        setMaskMp(d.mercadopago_access_token_masked ?? "");
        setMaskEvo(d.evolution_master_api_key_masked ?? "");
        setSmtpHost(d.smtp_host ?? "");
        setSmtpPort(String(d.smtp_port ?? 587));
        setSmtpEnc(d.smtp_encryption === "ssl" || d.smtp_encryption === "none" ? d.smtp_encryption : "tls");
        setSmtpUser(d.smtp_username ?? "");
        setSmtpFrom(d.smtp_from_email ?? "");
        setSmtpFromName(d.smtp_from_name ?? "");
        setMaskSmtp(d.smtp_password_masked ?? "");
        setMaskCron(d.cron_secret_masked ?? "");
        setCronEndpoint(d.cron_endpoint_url ?? null);
        setMaskCronjob(d.cronjob_api_key_masked ?? "");
        setCronJobId(d.cronjob_job_id ?? null);
        setCronHour(String(d.cron_schedule_hour ?? 8));
        setCronMinute(String(d.cron_schedule_minute ?? 0));
        setNotificationPhone(d.notification_phone ?? "");
        setMpPlansWebhook(d.mercadopago_plans_webhook_url ?? null);
        setMpRefHint(d.mercadopago_plans_external_reference_hint ?? "cobx:{company_id}:{plan_id}");
        const accent = normalizeBrandAccentKey(d.brand_accent);
        setBrandAccent(accent);
        applyLocal(accent);
        setSystemName((d.system_name ?? "").trim() || "CobrançaFácil");
      } catch (e) {
        toast({
          title: "Erro ao carregar",
          description: e instanceof Error ? e.message : "Falha na API",
          variant: "destructive",
        });
      } finally {
        if (ok) setLoading(false);
      }
    })();
    return () => {
      ok = false;
    };
  }, [toast, applyLocal]);

  useEffect(() => {
    if (masterTab !== "whatsapp") return;
    let cancelled = false;
    setWaLoading(true);
    (async () => {
      try {
        const d = await apiFetch<MasterWhatsappState>("/api/admin/master-whatsapp");
        if (!cancelled) setWa(d);
      } catch (e) {
        if (!cancelled) {
          setWa(null);
          toast({
            title: "Erro ao carregar WhatsApp",
            description: e instanceof Error ? e.message : "Verifique a Evolution API em Integrações.",
            variant: "destructive",
          });
        }
      } finally {
        if (!cancelled) setWaLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [masterTab, toast]);

  useEffect(() => {
    if (masterTab !== "whatsapp" || !wa?.instance_name || wa.connected) return;
    const timer = window.setInterval(() => {
      void reloadWa();
    }, 5000);
    return () => window.clearInterval(timer);
  }, [masterTab, wa?.instance_name, wa?.connected]);

  const generateCronSecret = () => {
    const a = new Uint8Array(24);
    crypto.getRandomValues(a);
    const s = Array.from(a, (b) => b.toString(16).padStart(2, "0")).join("");
    setCronSecret(s);
    toast({ title: "Novo segredo gerado", description: "Guarde e clique em Guardar configurações." });
  };

  const reloadWa = async () => {
    const d = await apiFetch<MasterWhatsappState>("/api/admin/master-whatsapp");
    setWa(d);
    return d;
  };

  const createWa = async () => {
    setWaBusy("create");
    try {
      const d = await apiFetch<MasterWhatsappState>("/api/admin/master-whatsapp/create", { method: "POST" });
      setWa(d);
      toast({
        title: "Sessão master criada",
        description: d.qrcode_base64 || d.qrcode_connection_code ? "Leia o QR code abaixo com o WhatsApp." : "Atualize o QR se não aparecer.",
      });
    } catch (e) {
      toast({ title: "Falha ao criar sessão", description: e instanceof Error ? e.message : "Erro", variant: "destructive" });
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
      await apiFetch("/api/admin/master-whatsapp/qrcode");
      await reloadWa();
      toast({ title: "QR atualizado" });
    } catch (e) {
      toast({ title: "Falha ao atualizar QR", description: e instanceof Error ? e.message : "Erro", variant: "destructive" });
    } finally {
      setWaBusy(null);
    }
  };

  const disconnectWa = async () => {
    setWaBusy("disconnect");
    try {
      const d = await apiFetch<MasterWhatsappState>("/api/admin/master-whatsapp/disconnect", { method: "POST" });
      setWa(d);
      toast({ title: "Sessão master desconectada" });
    } catch (e) {
      toast({ title: "Falha ao desconectar", description: e instanceof Error ? e.message : "Erro", variant: "destructive" });
    } finally {
      setWaBusy(null);
    }
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      const port = Math.max(1, Math.min(65535, parseInt(smtpPort, 10) || 587));
      const hour = Math.max(0, Math.min(23, parseInt(cronHour, 10) || 8));
      const minute = Math.max(0, Math.min(59, parseInt(cronMinute, 10) || 0));
      const res = await apiFetch<{ ok: boolean; cronjob_sync?: { ok: boolean; message?: string; job_id?: number; action?: string } }>(
        "/api/admin/master-settings",
        {
          method: "PUT",
          body: JSON.stringify({
            mercadopago_public_key: mpPublic,
            mercadopago_access_token: mpToken,
            evolution_master_url: evoUrl,
            evolution_master_api_key: evoKey,
            smtp_host: smtpHost,
            smtp_port: port,
            smtp_encryption: smtpEnc,
            smtp_username: smtpUser,
            smtp_password: smtpPass,
            smtp_from_email: smtpFrom,
            smtp_from_name: smtpFromName,
            cron_secret: cronSecret,
            cronjob_api_key: cronjobApiKey,
            cron_schedule_hour: hour,
            cron_schedule_minute: minute,
            brand_accent: brandAccent,
            system_name: systemName.trim(),
            notification_phone: notificationPhone,
            master_whatsapp_instance_name: wa?.instance_name ?? "",
          }),
        },
      );
      const syncMsg = res.cronjob_sync?.message;
      toast({
        title: "Configurações guardadas",
        description: syncMsg ?? (cronjobApiKey.trim() ? undefined : "Cron-job.org: adicione a API key para criar o job automaticamente."),
      });
      applyLocal(brandAccent);
      void refreshBrandTheme();
      setMpToken("");
      setEvoKey("");
      setSmtpPass("");
      setCronSecret("");
      setCronjobApiKey("");
      const d = await apiFetch<MasterGet>("/api/admin/master-settings");
      setSystemName((d.system_name ?? "").trim() || "CobrançaFácil");
      setMaskMp(d.mercadopago_access_token_masked ?? "");
      setMaskEvo(d.evolution_master_api_key_masked ?? "");
      setMaskSmtp(d.smtp_password_masked ?? "");
      setMaskCron(d.cron_secret_masked ?? "");
      setCronEndpoint(d.cron_endpoint_url ?? null);
      setMaskCronjob(d.cronjob_api_key_masked ?? "");
      setCronJobId(d.cronjob_job_id ?? null);
      setCronHour(String(d.cron_schedule_hour ?? 8));
      setCronMinute(String(d.cron_schedule_minute ?? 0));
      setNotificationPhone(d.notification_phone ?? "");
      setMpPlansWebhook(d.mercadopago_plans_webhook_url ?? null);
      setMpRefHint(d.mercadopago_plans_external_reference_hint ?? "cobx:{company_id}:{plan_id}");
      setBrandAccent(normalizeBrandAccentKey(d.brand_accent));
      await reloadWa();
    } catch (e) {
      toast({
        title: "Erro ao guardar",
        description: e instanceof Error ? e.message : "Falha",
        variant: "destructive",
      });
    } finally {
      setSaving(false);
    }
  };

  const savePassword = async () => {
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

  if (loading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }

  return (
    <div>
      <div className="flex items-center gap-3 mb-6">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
          <Shield className="h-5 w-5 text-primary" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-foreground">Configurações master</h1>
          <p className="text-sm text-muted-foreground">
            Integrações globais, SMTP e agendamento HTTP para cobranças automáticas.
          </p>
        </div>
      </div>

      <form onSubmit={handleSave}>
        <Tabs value={masterTab} onValueChange={setMasterTab} className="max-w-2xl">
          <TabsList className="mb-4 flex flex-wrap h-auto gap-1">
            <TabsTrigger value="appearance">Aparência</TabsTrigger>
            <TabsTrigger value="whatsapp">WhatsApp</TabsTrigger>
            <TabsTrigger value="integrations">Integrações</TabsTrigger>
            <TabsTrigger value="smtp">Email (SMTP)</TabsTrigger>
            <TabsTrigger value="crons">Crons</TabsTrigger>
            <TabsTrigger value="security">Segurança</TabsTrigger>
          </TabsList>

          <TabsContent value="appearance" className="space-y-6">
            <Card className="p-6 shadow-card">
              <div className="flex items-center gap-2 mb-4">
                <Shield className="h-4 w-4 text-muted-foreground" />
                <h2 className="font-semibold text-foreground">Nome do sistema</h2>
              </div>
              <p className="text-sm text-muted-foreground mb-4">
                Aparece no login, painel, página inicial, emails e mensagens de boas-vindas enviadas aos novos clientes.
              </p>
              <div className="space-y-2 max-w-md">
                <Label htmlFor="system-name">Nome exibido</Label>
                <Input
                  id="system-name"
                  value={systemName}
                  onChange={(e) => setSystemName(e.target.value)}
                  placeholder="CobrançaFácil"
                  maxLength={120}
                  required
                />
              </div>
            </Card>
            <Card className="p-6 shadow-card">
              <div className="flex items-center gap-2 mb-4">
                <Palette className="h-4 w-4 text-muted-foreground" />
                <h2 className="font-semibold text-foreground">Cor de destaque da plataforma</h2>
              </div>
              <p className="text-sm text-muted-foreground mb-6">
                Define a cor dos botões de destaque, ícones do menu lateral e gradientes. Aplica-se a todo o painel (incluindo login após recarregar).
              </p>
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                {BRAND_ACCENT_PRESETS.map((preset) => {
                  const selected = brandAccent === preset.key;
                  return (
                    <button
                      key={preset.key}
                      type="button"
                      onClick={() => {
                        setBrandAccent(preset.key);
                        applyLocal(preset.key);
                      }}
                      className={cn(
                        "flex items-center gap-3 rounded-lg border-2 p-3 text-left transition-all",
                        selected ? "border-primary bg-primary/5 ring-2 ring-primary/20" : "border-border hover:border-primary/40"
                      )}
                    >
                      <span
                        className="h-8 w-8 shrink-0 rounded-full border border-border shadow-sm"
                        style={{ background: preset.swatch }}
                        aria-hidden
                      />
                      <span className="text-sm font-medium text-foreground">{preset.label}</span>
                    </button>
                  );
                })}
              </div>
              <p className="text-xs text-muted-foreground mt-4">
                Clique em <strong className="text-foreground">Guardar configurações</strong> abaixo para persistir a escolha na base de dados.
              </p>
            </Card>
          </TabsContent>

          <TabsContent value="whatsapp" className="space-y-6">
            {masterTab !== "whatsapp" ? null : waLoading && wa === null ? (
              <div className="flex min-h-[240px] items-center justify-center text-muted-foreground">A carregar WhatsApp…</div>
            ) : (
              <Card className="p-6 shadow-card max-w-2xl">
                <div className="flex items-center gap-2 mb-2">
                  <Smartphone className="h-4 w-4 text-accent" />
                  <h2 className="font-semibold text-foreground">WhatsApp master (notificações)</h2>
                </div>
                <p className="text-sm text-muted-foreground mb-4">
                  Ligue o número que envia alertas da plataforma (cadastro, recuperação de senha, pagamentos de planos, etc.).
                  Os emails usam automaticamente o SMTP configurado na aba <strong className="text-foreground">Email (SMTP)</strong>.
                </p>

                {wa && !wa.master_configured && (
                  <p className="text-sm text-warning mb-4 rounded-md border border-warning/30 bg-warning/10 px-3 py-2">
                    Configure primeiro a <strong>Evolution API</strong> em Integrações (URL e API key) e guarde as configurações.
                  </p>
                )}

                {wa?.smtp_configured === false && (
                  <p className="text-xs text-muted-foreground mb-4 rounded-md border border-border bg-muted/30 px-3 py-2">
                    SMTP master ainda incompleto — os alertas por email só funcionam após preencher a aba Email (SMTP).
                  </p>
                )}

                <div className="space-y-2 mb-6 max-w-md">
                  <Label>Telefone para receber alertas no WhatsApp (opcional, com DDI)</Label>
                  <Input
                    value={notificationPhone}
                    onChange={(e) => setNotificationPhone(e.target.value)}
                    placeholder="5511999999999"
                  />
                  <p className="text-xs text-muted-foreground">
                    Se preenchido, cópias dos alertas importantes podem ser enviadas para este número pela instância ligada abaixo.
                    Guarde com o botão no final da página.
                  </p>
                </div>

                {!wa?.instance_name ? (
                  <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">Ainda não existe sessão Evolution para a conta master.</p>
                    <Button
                      type="button"
                      variant="hero"
                      onClick={() => void createWa()}
                      disabled={waBusy !== null || wa?.master_configured === false}
                    >
                      <Link2 className="h-4 w-4 mr-2" />
                      {waBusy === "create" ? "A criar…" : "Criar e mostrar QR code"}
                    </Button>
                  </div>
                ) : (
                  <div className="space-y-6">
                    <div className="rounded-lg border border-border bg-muted/20 p-4 space-y-2 text-sm">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground">Estado:</span>
                        {wa.connected ? (
                          <span className="rounded-full bg-success/15 text-success px-2 py-0.5 font-medium">Ligado</span>
                        ) : (
                          <span className="rounded-full bg-warning/15 text-warning px-2 py-0.5 font-medium">
                            {wa.connection_state === "connecting" ? "A ligar…" : "Aguarda leitura do QR"}
                          </span>
                        )}
                      </div>
                      <p>
                        <span className="text-muted-foreground">Instância Evolution:</span>{" "}
                        <code className="text-xs font-mono bg-muted px-1.5 py-0.5 rounded">{wa.instance_name}</code>
                      </p>
                      {wa.connected ? (
                        <p className="text-muted-foreground">
                          Esta sessão está ativa e será usada para enviar notificações automáticas da plataforma.
                        </p>
                      ) : (
                        <p className="text-muted-foreground">
                          Abra o WhatsApp neste telemóvel → Definições → Aparelhos ligados → Ligar um aparelho → Leia o QR abaixo.
                        </p>
                      )}
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

                    {!wa.connected && (
                      <div className="rounded-lg border border-border p-4 space-y-4">
                        <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                          <QrCode className="h-4 w-4" />
                          QR code para ligar o WhatsApp
                        </div>
                        {wa.qrcode_base64 || wa.qrcode_connection_code || wa.pairing_code ? (
                          <>
                            {wa.qrcode_base64 ? (
                              <div className="flex justify-center bg-white rounded-md p-3 w-fit mx-auto">
                                <img
                                  alt="QR code WhatsApp master"
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
                                  value={wa.qrcode_connection_code}
                                  size={260}
                                  level="M"
                                  marginSize={2}
                                  title="QR code WhatsApp master"
                                />
                              </div>
                            ) : null}
                            {wa.pairing_code ? (
                              <p className="text-center text-sm">
                                Código de emparelhamento: <strong className="font-mono">{wa.pairing_code}</strong>
                              </p>
                            ) : null}
                          </>
                        ) : (
                          <p className="text-sm text-muted-foreground text-center py-4">
                            QR ainda não disponível. Clique em <strong className="text-foreground">Atualizar QR code</strong>.
                          </p>
                        )}
                      </div>
                    )}
                  </div>
                )}
              </Card>
            )}
          </TabsContent>

          <TabsContent value="integrations" className="space-y-6">
            <Card className="p-6 shadow-card">
              <div className="flex items-center gap-2 mb-4">
                <CreditCard className="h-4 w-4 text-muted-foreground" />
                <h2 className="font-semibold text-foreground">Mercado Pago (planos)</h2>
              </div>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label>Chave pública</Label>
                  <Input value={mpPublic} onChange={(e) => setMpPublic(e.target.value)} placeholder="APP_USR-..." />
                </div>
                <div className="space-y-2">
                  <Label>Access token / credencial de produção</Label>
                  <Input
                    type="password"
                    value={mpToken}
                    onChange={(e) => setMpToken(e.target.value)}
                    placeholder={maskMp ? `Atual: ${maskMp} — preencha para substituir` : "Cole o token"}
                    autoComplete="off"
                  />
                  <p className="text-xs text-muted-foreground">Deixe em branco para manter o valor atual.</p>
                </div>

                <div className="rounded-lg border border-border bg-muted/20 p-4 space-y-3">
                  <div>
                    <Label className="text-foreground">Webhook de assinaturas (planos)</Label>
                    <p className="text-xs text-muted-foreground mt-1 leading-relaxed">
                      Cadastre este URL no painel do Mercado Pago (Suas integrações → Webhooks → modo produção e teste).
                      Quando um assinante pagar ou renovar um plano, o Mercado Pago notifica o Cobx e a empresa é ativada
                      automaticamente (plano + data de renovação +1 mês).
                    </p>
                  </div>
                  {!mpPlansWebhook && (
                    <p className="text-xs text-warning rounded-md border border-warning/30 bg-warning/10 px-3 py-2">
                      Defina <code className="bg-muted px-1 rounded text-[11px]">APP_URL</code> no <code className="bg-muted px-1 rounded text-[11px]">.env</code> da API
                      para o URL oficial; abaixo usa o endereço atual do browser.
                    </p>
                  )}
                  <div className="flex flex-col sm:flex-row gap-2">
                    <Input readOnly value={mpPlansWebhookDisplay} className="font-mono text-[11px] min-w-0 flex-1" />
                    <Button type="button" variant="secondary" size="sm" className="shrink-0" onClick={() => void copyMpWebhook()}>
                      <Copy className="h-4 w-4 mr-2" />
                      Copiar
                    </Button>
                  </div>
                  <div className="text-xs text-muted-foreground space-y-2">
                    <p>
                      <strong className="text-foreground">Eventos recomendados:</strong> pagamentos (<code className="bg-muted px-1 rounded">payment</code>
                      ), atualizações de pagamento.
                    </p>
                    <p>
                      <strong className="text-foreground">Referência no checkout:</strong> defina{" "}
                      <code className="bg-muted px-1 rounded font-mono">{mpRefHint}</code> em{" "}
                      <code className="bg-muted px-1 rounded">external_reference</code> (substitua pelos UUID reais da empresa e do plano)
                      ou envie <code className="bg-muted px-1 rounded">metadata.company_id</code> e{" "}
                      <code className="bg-muted px-1 rounded">metadata.plan_id</code>.
                    </p>
                    <p>
                      <a
                        href="https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primary inline-flex items-center gap-1 hover:underline"
                      >
                        Documentação Mercado Pago — Webhooks
                        <ExternalLink className="h-3 w-3" />
                      </a>
                    </p>
                  </div>
                </div>
              </div>
            </Card>

            <Card className="p-6 shadow-card">
              <div className="flex items-center gap-2 mb-4">
                <MessageSquare className="h-4 w-4 text-muted-foreground" />
                <h2 className="font-semibold text-foreground">Evolution API (master)</h2>
              </div>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label>URL base da API</Label>
                  <Input value={evoUrl} onChange={(e) => setEvoUrl(e.target.value)} placeholder="https://api.seudominio.com" />
                </div>
                <div className="space-y-2">
                  <Label>API key global</Label>
                  <Input
                    type="password"
                    value={evoKey}
                    onChange={(e) => setEvoKey(e.target.value)}
                    placeholder={maskEvo ? `Atual: ${maskEvo} — preencha para substituir` : "Chave master"}
                    autoComplete="off"
                  />
                  <p className="text-xs text-muted-foreground">
                    Os utilizadores da empresa só configuram a instância / conexão; esta chave serve de base quando não houver token próprio na empresa.
                  </p>
                </div>
              </div>
            </Card>
          </TabsContent>

          <TabsContent value="smtp" className="space-y-6">
            <Card className="p-6 shadow-card">
              <div className="flex items-center gap-2 mb-4">
                <Mail className="h-4 w-4 text-muted-foreground" />
                <h2 className="font-semibold text-foreground">SMTP (emails de cobrança — padrão)</h2>
              </div>
              <p className="text-sm text-muted-foreground mb-4">
                Utilizado automaticamente pelas empresas que não ativarem SMTP próprio nas configurações da empresa.
              </p>
              <div className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-2 sm:col-span-2">
                    <Label>Servidor SMTP (host)</Label>
                    <Input value={smtpHost} onChange={(e) => setSmtpHost(e.target.value)} placeholder="smtp.seuprovedor.com" />
                  </div>
                  <div className="space-y-2">
                    <Label>Porta</Label>
                    <Input type="number" min={1} max={65535} value={smtpPort} onChange={(e) => setSmtpPort(e.target.value)} />
                  </div>
                  <div className="space-y-2">
                    <Label>Encriptação</Label>
                    <Select value={smtpEnc} onValueChange={setSmtpEnc}>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="tls">TLS (STARTTLS)</SelectItem>
                        <SelectItem value="ssl">SSL (SMTPS)</SelectItem>
                        <SelectItem value="none">Nenhuma</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-2 sm:col-span-2">
                    <Label>Utilizador SMTP</Label>
                    <Input value={smtpUser} onChange={(e) => setSmtpUser(e.target.value)} placeholder="conta@dominio.com" autoComplete="off" />
                  </div>
                  <div className="space-y-2 sm:col-span-2">
                    <Label>Palavra-passe SMTP</Label>
                    <Input
                      type="password"
                      value={smtpPass}
                      onChange={(e) => setSmtpPass(e.target.value)}
                      placeholder={maskSmtp ? `Atual: ${maskSmtp} — preencha para substituir` : "Palavra-passe"}
                      autoComplete="new-password"
                    />
                    <p className="text-xs text-muted-foreground">Deixe em branco para manter a palavra-passe atual.</p>
                  </div>
                  <div className="space-y-2 sm:col-span-2">
                    <Label>Email remetente (From)</Label>
                    <Input type="email" value={smtpFrom} onChange={(e) => setSmtpFrom(e.target.value)} placeholder="noreply@seudominio.com" />
                  </div>
                  <div className="space-y-2 sm:col-span-2">
                    <Label>Nome remetente (opcional)</Label>
                    <Input value={smtpFromName} onChange={(e) => setSmtpFromName(e.target.value)} placeholder="CobrançaFácil" />
                  </div>
                </div>
              </div>
            </Card>
          </TabsContent>

          <TabsContent value="crons" className="space-y-6">
            <Card className="p-6 shadow-card">
              <div className="flex items-center gap-2 mb-4">
                <Clock className="h-4 w-4 text-muted-foreground" />
                <h2 className="font-semibold text-foreground">Agendamento (cron-job.org)</h2>
              </div>
              <p className="text-sm text-muted-foreground mb-4">
                Cole a API key da{" "}
                <a
                  href={CRON_JOB_CONSOLE}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-primary font-medium inline-flex items-center gap-1 hover:underline"
                >
                  cron-job.org
                  <ExternalLink className="h-3 w-3" />
                </a>{" "}
                (Settings → API) e guarde. O Cobx cria ou atualiza o job remoto automaticamente via{" "}
                <a href={CRON_JOB_API_DOCS} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                  REST API
                </a>
                . Não é preciso criar o job manualmente na consola.
              </p>

              {!cronEndpoint && (
                <p className="text-sm text-warning mb-4 rounded-md border border-warning/30 bg-warning/10 px-3 py-2">
                  Defina <code className="text-xs bg-muted px-1 rounded">APP_URL</code> no <code className="text-xs bg-muted px-1 rounded">.env</code> da API
                  (ex.: <code className="text-xs bg-muted px-1 rounded">http://localhost/cobx</code>) antes de sincronizar.
                </p>
              )}

              {cronJobId != null && (
                <p className="text-sm text-foreground mb-4 rounded-md border border-border bg-muted/20 px-3 py-2">
                  Job remoto: <strong>#{cronJobId}</strong>
                  {cronEndpoint ? (
                    <>
                      {" "}
                      · endpoint <code className="text-xs font-mono">{cronEndpoint}</code>
                    </>
                  ) : null}
                </p>
              )}

              <div className="space-y-4">
                <div className="space-y-2">
                  <Label>API key cron-job.org</Label>
                  <Input
                    type="password"
                    value={cronjobApiKey}
                    onChange={(e) => setCronjobApiKey(e.target.value)}
                    placeholder={maskCronjob ? `Atual: ${maskCronjob} — preencha para substituir` : "Bearer token da consola"}
                    autoComplete="off"
                  />
                  <p className="text-xs text-muted-foreground">Deixe em branco ao guardar para manter a chave atual.</p>
                </div>

                <div className="grid grid-cols-2 gap-4 max-w-xs">
                  <div className="space-y-2">
                    <Label>Hora (diário)</Label>
                    <Input type="number" min={0} max={23} value={cronHour} onChange={(e) => setCronHour(e.target.value)} />
                  </div>
                  <div className="space-y-2">
                    <Label>Minuto</Label>
                    <Input type="number" min={0} max={59} value={cronMinute} onChange={(e) => setCronMinute(e.target.value)} />
                  </div>
                </div>
                <p className="text-xs text-muted-foreground">
                  Fuso horário: variável opcional <code className="bg-muted px-1 rounded">CRON_TIMEZONE</code> no .env (padrão America/Sao_Paulo).
                </p>

                <div className="space-y-2 pt-2 border-t border-border">
                  <div className="flex items-center justify-between gap-2">
                    <Label>Segredo do endpoint Cobx (opcional)</Label>
                    <Button type="button" variant="outline" size="sm" onClick={generateCronSecret}>
                      <RefreshCw className="h-3.5 w-3.5 mr-1.5" />
                      Gerar
                    </Button>
                  </div>
                  <Input
                    type="password"
                    value={cronSecret}
                    onChange={(e) => setCronSecret(e.target.value)}
                    placeholder={maskCron ? `Atual: ${maskCron} — vazio mantém; vazio + API key gera automaticamente` : "Gerado automaticamente ao sincronizar"}
                    autoComplete="new-password"
                  />
                  <p className="text-xs text-muted-foreground">
                    Protege <code className="bg-muted px-1 rounded">/api/cron/run</code>. Se estiver vazio, é criado ao sincronizar com cron-job.org.
                  </p>
                </div>
              </div>
            </Card>
          </TabsContent>
          <TabsContent value="security" className="space-y-6">
            <Card className="p-6 shadow-card">
              <div className="flex items-center gap-2 mb-4">
                <KeyRound className="h-4 w-4 text-muted-foreground" />
                <h2 className="font-semibold text-foreground">Alterar senha do administrador</h2>
              </div>
              <p className="text-sm text-muted-foreground mb-5">
                Atualize a senha usada para entrar no painel super admin.
              </p>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="master-current-password">Senha atual</Label>
                  <Input
                    id="master-current-password"
                    type="password"
                    autoComplete="current-password"
                    value={currentPassword}
                    onChange={(e) => setCurrentPassword(e.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="master-new-password">Nova senha</Label>
                  <Input
                    id="master-new-password"
                    type="password"
                    autoComplete="new-password"
                    value={newPassword}
                    onChange={(e) => setNewPassword(e.target.value)}
                    minLength={6}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="master-confirm-password">Confirmar nova senha</Label>
                  <Input
                    id="master-confirm-password"
                    type="password"
                    autoComplete="new-password"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    minLength={6}
                  />
                </div>
                <Button type="button" variant="hero" disabled={passwordSaving} onClick={() => void savePassword()}>
                  <Save className="h-4 w-4 mr-2" />
                  {passwordSaving ? "A guardar..." : "Alterar senha"}
                </Button>
              </div>
            </Card>
          </TabsContent>
        </Tabs>

        <div className="mt-8 max-w-2xl">
          <Button type="submit" variant="hero" disabled={saving}>
            {saving ? "A guardar…" : "Guardar configurações"}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default MasterSettings;
