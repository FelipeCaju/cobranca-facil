<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if($path==='/timeout'){usleep(2500000);header('Content-Type: application/json');echo'{"ok":true}';return;}
if($path==='/invalid-json'){header('Content-Type: application/json');echo'{invalid';return;}
if($path==='/invalid-xml'){header('Content-Type: text/xml');echo'<broken>';return;}
if($path==='/oauth'){$counter=(string)(getenv('COBX_HTTP_MOCK_COUNTER')?:sys_get_temp_dir().'/cobx-http-counter');$h=fopen($counter,'c+');flock($h,LOCK_EX);$value=(int)trim((string)stream_get_contents($h));rewind($h);ftruncate($h,0);fwrite($h,(string)($value+1));fflush($h);flock($h,LOCK_UN);fclose($h);usleep(500000);header('Content-Type: application/json');echo json_encode(['access_token'=>'mock-token-'.($value+1),'expires_in'=>isset($_GET['short'])?1:120]);return;}
if(preg_match('#^/status/(\d{3})$#',$path,$m)){http_response_code((int)$m[1]);header('Content-Type: application/json');echo json_encode(['status'=>(int)$m[1]]);return;}
http_response_code(404);echo'{"status":404}';
