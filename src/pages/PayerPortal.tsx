import { useEffect, useState } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import { Copy, ExternalLink } from "lucide-react";
import { resolveApiUrl } from "@/lib/api";
import { Button } from "@/components/ui/button";

type PublicInstallment = { company_name:string; description:string; amount:number|string; due_date:string; status:string; paid_at?:string|null; receipt_url?:string|null; payment_url?:string|null; pix_copy_paste?:string|null; boleto_digitable_line?:string|null };

export default function PayerPortal() {
  const { id = "" } = useParams(); const [params] = useSearchParams(); const [data, setData] = useState<PublicInstallment|null>(null); const [error, setError] = useState("");
  useEffect(() => { fetch(resolveApiUrl(`/api/public/charge/${id}?${params}`)).then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.error); setData(d.installment); }).catch(e => setError(e.message)); }, [id, params]);
  if (error) return <main className="min-h-screen grid place-items-center p-6"><p className="text-destructive">{error}</p></main>;
  if (!data) return <main className="min-h-screen grid place-items-center">Carregando cobrança…</main>;
  const copy = () => navigator.clipboard.writeText(data.pix_copy_paste || data.boleto_digitable_line || "");
  return <main className="min-h-screen bg-muted p-5 grid place-items-center"><section className="w-full max-w-lg rounded-xl bg-background border p-6 shadow-card space-y-5"><div><p className="text-sm text-muted-foreground">{data.company_name}</p><h1 className="text-2xl font-bold">Sua cobrança</h1></div><div className="rounded-lg bg-muted p-4"><p>{data.description}</p><strong className="text-xl">R$ {Number(data.amount).toFixed(2).replace('.', ',')}</strong><p className="text-sm">Vencimento: {data.due_date}</p></div><p>Status: <strong>{data.status === 'paid' ? 'Pago' : data.status === 'overdue' ? 'Em atraso' : 'Pendente'}</strong></p>{data.status === 'paid' ? <div className="rounded-lg border border-emerald-300 bg-emerald-50 p-4"><strong>Comprovante de pagamento</strong><p className="text-sm">Pagamento confirmado em {data.paid_at || 'data registrada pelo recebedor'}.</p><Button className="mt-3" variant="outline" onClick={() => data.receipt_url ? window.open(data.receipt_url,'_blank') : window.print()}>{data.receipt_url ? 'Abrir comprovante bancário' : 'Imprimir comprovante'}</Button></div> : null}{data.status !== 'paid' && data.payment_url ? <Button asChild className="w-full"><a href={data.payment_url} target="_blank" rel="noreferrer"><ExternalLink className="h-4 w-4 mr-2" />Pagar / abrir boleto</a></Button> : null}{data.status !== 'paid' && (data.pix_copy_paste || data.boleto_digitable_line) ? <Button variant="outline" className="w-full" onClick={copy}><Copy className="h-4 w-4 mr-2" />Copiar código de pagamento</Button> : null}</section></main>;
}
