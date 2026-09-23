<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../db.php';
$pdo=db(); $changed=0;
$targets=[
  ['payment_accounts','id',['api_key','webhook_secret']],
  ['companies','id',['gateway_api_key','whatsapp_token','smtp_password']],
  ['master_settings','id',['mercadopago_access_token','evolution_master_api_key','smtp_password','cron_secret','cronjob_api_key']],
];
foreach($targets as [$table,$pk,$columns]){
  $existing=[]; $q=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);$existing=$q->fetchAll(PDO::FETCH_COLUMN);
  $columns=array_values(array_intersect($columns,$existing)); if($columns===[])continue;
  $rows=$pdo->query('SELECT '.$pk.','.implode(',',$columns).' FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $row)foreach($columns as $column){$value=(string)($row[$column]??'');if($value===''||str_starts_with($value,COBX_SECRET_PREFIX))continue;$pdo->prepare("UPDATE $table SET $column=? WHERE $pk=?")->execute([cobx_secret_encrypt($value),$row[$pk]]);$changed++;}
}
fwrite(STDOUT,"Segredos migrados: $changed\n");
