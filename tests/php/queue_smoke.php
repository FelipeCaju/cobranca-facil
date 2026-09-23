<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/common.php';
require_once __DIR__.'/../../api/db.php';
require_once __DIR__.'/../../api/routes/cron_reminders.php';
$pdo=db();$company=(string)$pdo->query('SELECT id FROM companies LIMIT 1')->fetchColumn();
if($company==='')throw new RuntimeException('Nenhuma empresa para teste.');
$id=cobx_queue_enqueue($pdo,$company,'send_installment',['installment_id'=>'00000000-0000-4000-8000-000000000000'],3);
$result=cobx_queue_process($pdo,10);$st=$pdo->prepare('SELECT status,attempts,last_error FROM integration_jobs WHERE id=?');$st->execute([$id]);$job=$st->fetch(PDO::FETCH_ASSOC);
if(($job['status']??'')!=='pending'||(int)($job['attempts']??0)!==1||empty($job['last_error']))throw new RuntimeException('Retentativa da fila não foi reagendada.');
echo json_encode(['ok'=>true,'worker'=>$result,'job'=>$job],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
