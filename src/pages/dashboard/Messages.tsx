import { useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useToast } from "@/hooks/use-toast";
import { MessageSquare, Mail, Save, Info } from "lucide-react";
import { apiFetch, getStoredToken } from "@/lib/api";
import { useAuth } from "@/hooks/useAuth";

interface TemplateVar {
  key: string;
  label: string;
}

interface TemplateRow {
  id: string;
  channel: string;
  trigger_type: string;
  days_offset: number;
  subject: string | null;
  body: string;
}

interface MessageSettingsGet {
  send_payment_link: boolean;
  send_qrcode: boolean;
  send_copy_paste_key: boolean;
  billing_reminder_start_time: string;
  billing_reminder_gap_seconds: number;
  templates: TemplateRow[];
  available_variables: TemplateVar[];
}

type TriggerKey = "before_due" | "on_due" | "after_due";

interface TriggerForm {
  days_offset: number;
  whatsapp_body: string;
  email_subject: string;
  email_body: string;
}

const emptyTrigger = (defaults: Partial<TriggerForm> = {}): TriggerForm => ({
  days_offset: defaults.days_offset ?? 0,
  whatsapp_body: defaults.whatsapp_body ?? "",
  email_subject: defaults.email_subject ?? "",
  email_body: defaults.email_body ?? "",
});

const defaultsFromApi: Record<TriggerKey, TriggerForm> = {
  before_due: emptyTrigger({
    days_offset: 3,
    whatsapp_body:
      "Olá {nome_cliente}! 👋\n\nLembramos que sua fatura de {valor} vence em {data_vencimento}.\n\nEvite juros, pague no prazo!\n\n{nome_empresa}",
    email_subject: "Lembrete: Sua fatura vence em breve",
    email_body:
      "Prezado(a) {nome_cliente},\n\nGostaríamos de lembrar que sua fatura no valor de {valor} tem vencimento em {data_vencimento}.\n\nAtenciosamente,\n{nome_empresa}",
  }),
  on_due: emptyTrigger({
    days_offset: 0,
    whatsapp_body:
      "Olá {nome_cliente}! 📋\n\nSua fatura de {valor} vence HOJE ({data_vencimento}).\n\nPague via PIX para evitar juros!\n\n{nome_empresa}",
    email_subject: "Sua fatura vence hoje!",
    email_body:
      "Prezado(a) {nome_cliente},\n\nSua fatura no valor de {valor} vence hoje, {data_vencimento}.\n\nEfetue o pagamento para evitar encargos.\n\nAtenciosamente,\n{nome_empresa}",
  }),
  after_due: emptyTrigger({
    days_offset: 1,
    whatsapp_body:
      "Olá {nome_cliente},\n\nIdentificamos que sua fatura de {valor} com vencimento em {data_vencimento} ainda não foi paga.\n\nRegularize para evitar encargos adicionais.\n\n{nome_empresa}",
    email_subject: "Atenção: Fatura em atraso",
    email_body:
      "Prezado(a) {nome_cliente},\n\nSua fatura no valor de {valor}, com vencimento em {data_vencimento}, encontra-se em atraso.\n\nPor favor, regularize o pagamento o mais breve possível.\n\nAtenciosamente,\n{nome_empresa}",
  }),
};

function mergeTemplatesFromApi(rows: TemplateRow[] | undefined): Record<TriggerKey, TriggerForm> {
  const out: Record<TriggerKey, TriggerForm> = {
    before_due: { ...defaultsFromApi.before_due },
    on_due: { ...defaultsFromApi.on_due },
    after_due: { ...defaultsFromApi.after_due },
  };
  if (!rows?.length) return out;
  for (const t of rows) {
    const k = t.trigger_type as TriggerKey;
    if (k !== "before_due" && k !== "on_due" && k !== "after_due") continue;
    const d = Math.max(0, Number(t.days_offset) || 0);
    if (t.channel === "whatsapp") {
      out[k].whatsapp_body = t.body;
      out[k].days_offset = k === "on_due" ? 0 : d;
    }
    if (t.channel === "email") {
      out[k].email_body = t.body;
      out[k].email_subject = t.subject ?? "";
      if (k !== "on_due") out[k].days_offset = d;
    }
  }
  return out;
}

const Messages = () => {
  const { toast } = useToast();
  const qc = useQueryClient();
  const { loading: authLoading, session, user } = useAuth();

  const [sendPaymentLink, setSendPaymentLink] = useState(false);
  const [sendQrCode, setSendQrCode] = useState(true);
  const [sendCopyPaste, setSendCopyPaste] = useState(true);
  const [startTime, setStartTime] = useState("08:00");
  const [gapSeconds, setGapSeconds] = useState(60);
  const [form, setForm] = useState<Record<TriggerKey, TriggerForm>>(() => ({
    before_due: { ...defaultsFromApi.before_due },
    on_due: { ...defaultsFromApi.on_due },
    after_due: { ...defaultsFromApi.after_due },
  }));

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["message-settings"],
    queryFn: () => apiFetch<MessageSettingsGet>("/api/company/message-settings"),
    enabled: !authLoading && Boolean(session ?? getStoredToken()) && Boolean(user?.company_id),
  });

  useEffect(() => {
    if (!data) return;
    const linkEnabled = data.send_payment_link ?? false;
    setSendPaymentLink(Boolean(linkEnabled));
    setSendQrCode(!linkEnabled && Boolean(data.send_qrcode));
    setSendCopyPaste(!linkEnabled && Boolean(data.send_copy_paste_key));
    setStartTime(data.billing_reminder_start_time || "08:00");
    setGapSeconds(Math.max(5, Math.min(3600, Number(data.billing_reminder_gap_seconds) || 60)));
    setForm(mergeTemplatesFromApi(data.templates));
  }, [data]);

  const variables = useMemo(() => data?.available_variables ?? [], [data?.available_variables]);

  const saveMutation = useMutation({
    mutationFn: async () => {
      const templates = {
        before_due: {
          days_offset: form.before_due.days_offset,
          whatsapp: { body: form.before_due.whatsapp_body },
          email: { subject: form.before_due.email_subject, body: form.before_due.email_body },
        },
        on_due: {
          days_offset: 0,
          whatsapp: { body: form.on_due.whatsapp_body },
          email: { subject: form.on_due.email_subject, body: form.on_due.email_body },
        },
        after_due: {
          days_offset: form.after_due.days_offset,
          whatsapp: { body: form.after_due.whatsapp_body },
          email: { subject: form.after_due.email_subject, body: form.after_due.email_body },
        },
      };
      await apiFetch("/api/company/message-settings", {
        method: "PUT",
        body: JSON.stringify({
          send_payment_link: sendPaymentLink,
          send_qrcode: sendQrCode,
          send_copy_paste_key: sendCopyPaste,
          billing_reminder_start_time: startTime,
          billing_reminder_gap_seconds: gapSeconds,
          templates,
        }),
      });
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["message-settings"] });
      toast({ title: "Configurações guardadas" });
    },
    onError: (e: Error) => toast({ title: "Erro", description: e.message, variant: "destructive" }),
  });

  const updateTrigger = (key: TriggerKey, patch: Partial<TriggerForm>) => {
    setForm((f) => ({ ...f, [key]: { ...f[key], ...patch } }));
  };

  const togglePaymentLink = (checked: boolean) => {
    setSendPaymentLink(checked);
    if (checked) {
      setSendQrCode(false);
      setSendCopyPaste(false);
    }
  };

  const toggleQrCode = (checked: boolean) => {
    setSendQrCode(checked);
    if (checked) setSendPaymentLink(false);
  };

  const toggleCopyPaste = (checked: boolean) => {
    setSendCopyPaste(checked);
    if (checked) setSendPaymentLink(false);
  };

  const triggerTab = (key: TriggerKey) => (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card className="shadow-card p-5">
        <div className="flex items-center gap-2 mb-4">
          <MessageSquare className="h-4 w-4 text-accent" />
          <h3 className="text-sm font-semibold text-card-foreground">WhatsApp</h3>
        </div>
        <div className="space-y-3">
          {key === "before_due" ? (
            <div className="space-y-2">
              <Label>Dias antes do vencimento</Label>
              <Input
                type="number"
                min={key === "before_due" ? 1 : 1}
                max={120}
                value={form[key].days_offset}
                onChange={(e) =>
                  updateTrigger(key, {
                    days_offset: Math.max(1, Math.min(120, parseInt(e.target.value, 10) || 1)),
                  })
                }
              />
              <p className="text-xs text-muted-foreground">
                {key === "before_due"
                  ? "Ex.: 3 envia 3, 2 e 1 dia antes. No dia do vencimento usa a mensagem própria."
                  : "Envia todos os dias após o vencimento até a parcela ser marcada como paga."}
              </p>
            </div>
          ) : null}
          {key === "after_due" ? (
            <p className="rounded-md border border-border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
              Esta mensagem e enviada todos os dias apos o vencimento ate a parcela ser marcada como paga.
            </p>
          ) : null}
          <div className="space-y-2">
            <Label>Mensagem</Label>
            <Textarea
              rows={6}
              className="text-sm font-mono"
              value={form[key].whatsapp_body}
              onChange={(e) => updateTrigger(key, { whatsapp_body: e.target.value })}
            />
          </div>
        </div>
      </Card>
      <Card className="shadow-card p-5">
        <div className="flex items-center gap-2 mb-4">
          <Mail className="h-4 w-4 text-accent" />
          <h3 className="text-sm font-semibold text-card-foreground">Email</h3>
        </div>
        <div className="space-y-3">
          <div className="space-y-2">
            <Label>Assunto</Label>
            <Input value={form[key].email_subject} onChange={(e) => updateTrigger(key, { email_subject: e.target.value })} />
          </div>
          <div className="space-y-2">
            <Label>Corpo</Label>
            <Textarea
              rows={6}
              className="text-sm font-mono"
              value={form[key].email_body}
              onChange={(e) => updateTrigger(key, { email_body: e.target.value })}
            />
          </div>
        </div>
      </Card>
    </div>
  );

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-foreground">Templates de mensagem</h1>
        <Button variant="hero" size="sm" type="button" disabled={saveMutation.isPending || isLoading} onClick={() => saveMutation.mutate()}>
          <Save className="h-4 w-4" />
          {saveMutation.isPending ? "A guardar…" : "Guardar alterações"}
        </Button>
      </div>

      {isError && (
        <p className="text-sm text-destructive mb-4">{error instanceof Error ? error.message : "Erro ao carregar."}</p>
      )}

      <Card className="shadow-card p-5 mb-6 border-border/80">
        <div className="flex items-start gap-2 mb-3">
          <Info className="h-4 w-4 text-primary shrink-0 mt-0.5" />
          <div>
            <h2 className="text-sm font-semibold text-foreground">Variáveis disponíveis (WhatsApp e email)</h2>
            <p className="text-xs text-muted-foreground mt-1">
              Utilize o mesmo texto entre chaves no assunto ou no corpo do email e na mensagem do WhatsApp. Variáveis não reconhecidas mantêm-se literais.
            </p>
          </div>
        </div>
        <div className="rounded-md border border-border bg-muted/20 max-h-48 overflow-y-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border bg-muted/40 text-left text-muted-foreground">
                <th className="px-3 py-2 font-medium">Variável</th>
                <th className="px-3 py-2 font-medium">Significado</th>
              </tr>
            </thead>
            <tbody>
              {variables.map((v) => (
                <tr key={v.key} className="border-b border-border/60 last:border-0">
                  <td className="px-3 py-1.5 font-mono text-[11px] text-foreground whitespace-nowrap">{v.key}</td>
                  <td className="px-3 py-1.5 text-muted-foreground">{v.label}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {variables.length === 0 && !isLoading && (
            <p className="p-3 text-xs text-muted-foreground">Carregue a página para ver a lista de variáveis.</p>
          )}
        </div>
      </Card>

      <Card className="shadow-card p-5 mb-6">
        <h2 className="text-sm font-semibold text-card-foreground mb-4">Configurações de envio (cron)</h2>
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-sm font-medium text-foreground">Enviar link para pagamento</p>
              <p className="text-xs text-muted-foreground">Link de checkout em mensagem separada</p>
            </div>
            <Switch checked={sendPaymentLink} onCheckedChange={togglePaymentLink} />
          </div>
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-sm font-medium text-foreground">Enviar QR Code PIX</p>
              <p className="text-xs text-muted-foreground">Quando integrado, envia o QR em mensagem separada</p>
            </div>
            <Switch checked={sendQrCode} disabled={sendPaymentLink} onCheckedChange={toggleQrCode} />
          </div>
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-sm font-medium text-foreground">Enviar chave copia e cola</p>
              <p className="text-xs text-muted-foreground">Chave PIX em mensagem separada</p>
            </div>
            <Switch checked={sendCopyPaste} disabled={sendPaymentLink} onCheckedChange={toggleCopyPaste} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2 pt-2 border-t border-border">
            <div className="space-y-2">
              <Label htmlFor="rem-start">Horário de início dos envios</Label>
              <Input
                id="rem-start"
                type="time"
                value={startTime.length > 5 ? startTime.slice(0, 5) : startTime}
                onChange={(e) => setStartTime(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                O cron só dispara lembretes depois desta hora (relógio do servidor onde corre o PHP). Ajuste o agendamento externo ou o fuso do servidor se necessário.
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="rem-gap">Intervalo entre clientes (segundos)</Label>
              <Input
                id="rem-gap"
                type="number"
                min={5}
                max={3600}
                value={gapSeconds}
                onChange={(e) => setGapSeconds(Math.max(5, Math.min(3600, parseInt(e.target.value, 10) || 60)))}
              />
              <p className="text-xs text-muted-foreground">
                Entre o envio para um cliente e o seguinte (evita picos e bloqueios no WhatsApp ou SMTP).
              </p>
            </div>
          </div>
        </div>
      </Card>

      {isLoading ? (
        <div className="text-sm text-muted-foreground py-8">A carregar…</div>
      ) : (
        <Tabs defaultValue="before">
          <TabsList className="mb-4">
            <TabsTrigger value="before">Antes do vencimento</TabsTrigger>
            <TabsTrigger value="due">No vencimento</TabsTrigger>
            <TabsTrigger value="after">Após vencimento</TabsTrigger>
          </TabsList>

          <TabsContent value="before">{triggerTab("before_due")}</TabsContent>
          <TabsContent value="due">{triggerTab("on_due")}</TabsContent>
          <TabsContent value="after">{triggerTab("after_due")}</TabsContent>
        </Tabs>
      )}
    </div>
  );
};

export default Messages;
