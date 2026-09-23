<?php
declare(strict_types=1);
function cobx_charge_audit(PDO $pdo,string $companyId,string $chargeId,string $action,?array $before=null,?array $after=null,array $meta=[]):void{
 $pdo->prepare('INSERT INTO charge_audit_log(id,company_id,charge_id,action,before_data,after_data,metadata) VALUES(?,?,?,?,?,?,?)')->execute([uuid_v4(),$companyId,$chargeId,$action,$before?json_encode($before,JSON_UNESCAPED_UNICODE):null,$after?json_encode($after,JSON_UNESCAPED_UNICODE):null,$meta?json_encode($meta,JSON_UNESCAPED_UNICODE):null]);
}
