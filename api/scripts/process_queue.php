<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../common.php';
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../routes/cron_reminders.php';
require_once __DIR__.'/../lib/integration_queue.php';
echo json_encode(cobx_queue_process(db(),isset($argv[1])?(int)$argv[1]:25),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
