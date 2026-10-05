<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/api/common.php';
require_once dirname(__DIR__,3).'/api/db.php';
require_once dirname(__DIR__,3).'/api/lib/bank_account_store.php';
require_once dirname(__DIR__,3).'/api/lib/oauth_token_manager.php';
$pdo=db();$q=$pdo->prepare('SELECT * FROM payment_accounts WHERE id=? AND company_id=?');$q->execute([$argv[1],$argv[2]]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Conta ausente');$account=cobx_bank_account_hydrate($row);
echo(new CobxOAuthTokenManager())->get($pdo,$account,['token_url'=>$argv[3].'/oauth','grant_type'=>'client_credentials','client_auth'=>'basic','allow_http_for_tests'=>true]);
