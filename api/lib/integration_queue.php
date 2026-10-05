<?php
declare(strict_types=1);

function cobx_queue_dedupe_key(PDO $pdo,string $companyId,string $type,array $payload):?string
{
    $target='';
    foreach(['installment_id','charge_id','event_key']as$key)if(trim((string)($payload[$key]??''))!==''){$target=$key.':'.trim((string)$payload[$key]);break;}
    if($target==='')return null;
    $provider=trim((string)($payload['provider']??''));
    if($provider===''&&isset($payload['charge_id'])){$q=$pdo->prepare('SELECT COALESCE(pa.provider,ch.payment_gateway) FROM charges ch LEFT JOIN payment_accounts pa ON pa.id=ch.payment_account_id AND pa.company_id=ch.company_id WHERE ch.id=? AND ch.company_id=?');$q->execute([$payload['charge_id'],$companyId]);$provider=trim((string)($q->fetchColumn()?:''));}
    if($provider===''&&isset($payload['installment_id'])){$q=$pdo->prepare('SELECT COALESCE(pa.provider,ch.payment_gateway) FROM installments i JOIN charges ch ON ch.id=i.charge_id LEFT JOIN payment_accounts pa ON pa.id=ch.payment_account_id AND pa.company_id=ch.company_id WHERE i.id=? AND i.company_id=?');$q->execute([$payload['installment_id'],$companyId]);$provider=trim((string)($q->fetchColumn()?:''));}
    return hash('sha256',$companyId.'|'.$type.'|'.$provider.'|'.$target);
}

function cobx_queue_enqueue(PDO $pdo, string $companyId, string $type, array $payload, int $maxAttempts=5): string
{
    // Até a homologação comprovar idempotência/consulta pós-timeout por provider,
    // criação financeira nunca é repetida automaticamente pela fila.
    if($type==='generate_charge')$maxAttempts=1;
    $id=uuid_v4();$dedupe=cobx_queue_dedupe_key($pdo,$companyId,$type,$payload);$json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    try{$pdo->prepare('INSERT INTO integration_jobs (id,company_id,job_type,payload,dedupe_key,max_attempts) VALUES (?,?,?,?,?,?)')->execute([$id,$companyId,$type,$json,$dedupe,max(1,min(10,$maxAttempts))]);return$id;}
    catch(PDOException $e){if($dedupe===null||!in_array((string)$e->getCode(),['23000','23505'],true))throw$e;$q=$pdo->prepare("SELECT id FROM integration_jobs WHERE company_id=? AND dedupe_key=? AND status IN ('pending','processing') LIMIT 1");$q->execute([$companyId,$dedupe]);$existing=$q->fetchColumn();if($existing!==false)return(string)$existing;throw$e;}
}

/** @return array{processed:int,completed:int,retried:int,failed:int} */
function cobx_queue_process(PDO $pdo, int $limit=25): array
{
    require_once __DIR__.'/gateway_payments.php';
    $out=['processed'=>0,'completed'=>0,'retried'=>0,'failed'=>0];
    // Recupera trabalho abandonado após queda do worker. O maior timeout HTTP
    // permitido é 120 s; 30 minutos evita disputar um worker ainda ativo.
    $pdo->exec("UPDATE integration_jobs SET status='pending',locked_at=NULL,last_error=COALESCE(last_error,'Worker interrompido; tarefa recuperada.') WHERE status='processing' AND locked_at<DATE_SUB(NOW(3),INTERVAL 30 MINUTE)");
    $st=$pdo->query("SELECT * FROM integration_jobs WHERE status='pending' AND available_at<=NOW(3) ORDER BY available_at,id LIMIT ".max(1,min(100,$limit)));
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $job){
        $claim=$pdo->prepare("UPDATE integration_jobs SET status='processing',locked_at=NOW(3),attempts=attempts+1 WHERE id=? AND status='pending'"); $claim->execute([$job['id']]); if(!$claim->rowCount())continue;
        $out['processed']++; $error=null;
        try{
            $payload=json_decode((string)$job['payload'],true,512,JSON_THROW_ON_ERROR);
            if($job['job_type']==='send_installment'){
                $result=cobx_reminder_send_installment_now($pdo,(string)$job['company_id'],(string)($payload['installment_id']??''));
                if(!$result['ok']) throw new RuntimeException(implode('; ',$result['details']??['Falha de envio']));
            } elseif($job['job_type']==='generate_charge'){
                $result=cobx_gateway_generate_charge_payments($pdo,(string)$job['company_id'],(string)($payload['charge_id']??'')); if(!$result['ok'])throw new RuntimeException(implode('; ',$result['details']??['Falha bancária']));
            } elseif($job['job_type']==='sync_installment'){
                $q=$pdo->prepare('SELECT i.*,ch.payment_account_id FROM installments i JOIN charges ch ON ch.id=i.charge_id WHERE i.id=? AND i.company_id=?');$q->execute([$payload['installment_id']??'', $job['company_id']]);$i=$q->fetch(PDO::FETCH_ASSOC);if(!$i||empty($i['external_id']))throw new RuntimeException('Parcela remota não encontrada.');$a=cobx_gateway_account($pdo,(string)$job['company_id'],(string)$i['payment_account_id']);if(!$a)throw new RuntimeException('Conta bancária não encontrada.');$result=cobx_connector_fetch_payment($a,(string)$i['external_id'],$pdo);if(!$result['ok'])throw new RuntimeException($result['detail']??'Falha na consulta.');$n=$result['normalized'];$pdo->prepare('UPDATE installments SET status=?,provider_status=?,provider_event=?,payment_origin=?,txid=COALESCE(?,txid),provider_reference=COALESCE(?,provider_reference),paid_at=COALESCE(?,paid_at),receipt_url=COALESCE(?,receipt_url),boleto_digitable_line=COALESCE(?,boleto_digitable_line),boleto_pdf_url=COALESCE(?,boleto_pdf_url),pix_copy_paste=COALESCE(?,pix_copy_paste),updated_at=NOW(3) WHERE id=?')->execute([$n['status'],$n['provider_status']??null,$n['provider_event']??null,$n['payment_origin']??null,$n['txid']??null,$n['provider_reference']??null,$n['paid_at'],$n['receipt_url']??null,$n['boleto_digitable_line']??null,$n['boleto_pdf_url']??null,$n['pix_copy_paste']??null,$i['id']]);cobx_charge_refresh_status($pdo,(string)$i['charge_id'],(string)$job['company_id']);
            } elseif($job['job_type']==='cancel_charge'){
                $q=$pdo->prepare('SELECT ch.payment_account_id,i.external_id FROM charges ch JOIN installments i ON i.charge_id=ch.id WHERE ch.id=? AND ch.company_id=?');$q->execute([$payload['charge_id']??'',$job['company_id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $i)if(!empty($i['external_id'])){$a=cobx_gateway_account($pdo,(string)$job['company_id'],(string)$i['payment_account_id']);$r=$a?cobx_connector_cancel_payment($a,(string)$i['external_id'],$pdo):['ok'=>false,'detail'=>'Conta não encontrada'];if(!$r['ok'])throw new RuntimeException($r['detail']);}$pdo->prepare("UPDATE installments SET status='cancelled' WHERE charge_id=? AND company_id=? AND status<>'paid'")->execute([$payload['charge_id'],$job['company_id']]);
            } elseif($job['job_type']==='payment_webhook'){
                require_once __DIR__.'/../routes/webhooks.php';
                cobx_payment_process_verified_webhook($pdo,(string)$job['company_id'],(string)($payload['provider']??''),(string)($payload['event_key']??''),is_array($payload['query']??null)?$payload['query']:[]);
            } else throw new RuntimeException('Tipo de tarefa desconhecido.');
        }catch(Throwable $e){$error=$e->getMessage();}
        if($error===null){$pdo->prepare("UPDATE integration_jobs SET status='completed',locked_at=NULL,last_error=NULL,dedupe_key=NULL WHERE id=?")->execute([$job['id']]);$out['completed']++;continue;}
        $attempt=(int)$job['attempts']+1; $max=(int)$job['max_attempts'];
        if($attempt >= $max){$pdo->prepare("UPDATE integration_jobs SET status='failed',locked_at=NULL,last_error=?,dedupe_key=NULL WHERE id=?")->execute([$error,$job['id']]);$out['failed']++;}
        else{$delay=min(3600,30*(2**max(0,$attempt-1)));$pdo->prepare("UPDATE integration_jobs SET status='pending',locked_at=NULL,last_error=?,available_at=DATE_ADD(NOW(3),INTERVAL ? SECOND) WHERE id=?")->execute([$error,$delay,$job['id']]);$out['retried']++;}
    }
    return $out;
}
