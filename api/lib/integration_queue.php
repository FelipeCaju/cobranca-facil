<?php
declare(strict_types=1);

function cobx_queue_enqueue(PDO $pdo, string $companyId, string $type, array $payload, int $maxAttempts=5): string
{
    $id=uuid_v4();
    $pdo->prepare('INSERT INTO integration_jobs (id,company_id,job_type,payload,max_attempts) VALUES (?,?,?,?,?)')
        ->execute([$id,$companyId,$type,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),max(1,min(10,$maxAttempts))]);
    return $id;
}

/** @return array{processed:int,completed:int,retried:int,failed:int} */
function cobx_queue_process(PDO $pdo, int $limit=25): array
{
    require_once __DIR__.'/gateway_payments.php';
    $out=['processed'=>0,'completed'=>0,'retried'=>0,'failed'=>0];
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
                $q=$pdo->prepare('SELECT i.*,ch.payment_account_id FROM installments i JOIN charges ch ON ch.id=i.charge_id WHERE i.id=? AND i.company_id=?');$q->execute([$payload['installment_id']??'', $job['company_id']]);$i=$q->fetch(PDO::FETCH_ASSOC);if(!$i||empty($i['external_id']))throw new RuntimeException('Parcela remota não encontrada.');$a=cobx_gateway_account($pdo,(string)$job['company_id'],(string)$i['payment_account_id']);if(!$a)throw new RuntimeException('Conta bancária não encontrada.');$result=cobx_connector_fetch_payment($a,(string)$i['external_id']);if(!$result['ok'])throw new RuntimeException($result['detail']??'Falha na consulta.');$n=$result['normalized'];$pdo->prepare('UPDATE installments SET status=?,paid_at=COALESCE(?,paid_at),receipt_url=COALESCE(?,receipt_url),updated_at=NOW(3) WHERE id=?')->execute([$n['status'],$n['paid_at'],$n['receipt_url']??null,$i['id']]);cobx_charge_refresh_status($pdo,(string)$i['charge_id'],(string)$job['company_id']);
            } elseif($job['job_type']==='cancel_charge'){
                $q=$pdo->prepare('SELECT ch.payment_account_id,i.external_id FROM charges ch JOIN installments i ON i.charge_id=ch.id WHERE ch.id=? AND ch.company_id=?');$q->execute([$payload['charge_id']??'',$job['company_id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $i)if(!empty($i['external_id'])){$a=cobx_gateway_account($pdo,(string)$job['company_id'],(string)$i['payment_account_id']);$r=$a?cobx_connector_cancel_payment($a,(string)$i['external_id']):['ok'=>false,'detail'=>'Conta não encontrada'];if(!$r['ok'])throw new RuntimeException($r['detail']);}$pdo->prepare("UPDATE installments SET status='cancelled' WHERE charge_id=? AND company_id=? AND status<>'paid'")->execute([$payload['charge_id'],$job['company_id']]);
            } else throw new RuntimeException('Tipo de tarefa desconhecido.');
        }catch(Throwable $e){$error=$e->getMessage();}
        if($error===null){$pdo->prepare("UPDATE integration_jobs SET status='completed',locked_at=NULL,last_error=NULL WHERE id=?")->execute([$job['id']]);$out['completed']++;continue;}
        $attempt=(int)$job['attempts']+1; $max=(int)$job['max_attempts'];
        if($attempt >= $max){$pdo->prepare("UPDATE integration_jobs SET status='failed',locked_at=NULL,last_error=? WHERE id=?")->execute([$error,$job['id']]);$out['failed']++;}
        else{$delay=min(3600,30*(2**max(0,$attempt-1)));$pdo->prepare("UPDATE integration_jobs SET status='pending',locked_at=NULL,last_error=?,available_at=DATE_ADD(NOW(3),INTERVAL ? SECOND) WHERE id=?")->execute([$error,$delay,$job['id']]);$out['retried']++;}
    }
    return $out;
}
