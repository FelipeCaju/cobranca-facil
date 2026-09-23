<?php

declare(strict_types=1);

function company_imports(PDO $pdo, string $method, string $companyId, ?string $type): void
{
    if ($method !== 'POST' || !in_array($type, ['clients', 'charges'], true)) json_response(405, ['error' => 'Método não permitido']);
    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) json_response(422, ['error' => 'Envie um arquivo CSV ou XLSX no campo file.']);
    $name = strtolower((string) $_FILES['file']['name']);
    $rows = str_ends_with($name, '.xlsx') ? cobx_import_xlsx($_FILES['file']['tmp_name']) : cobx_import_csv($_FILES['file']['tmp_name']);
    if ($rows === []) json_response(422, ['error' => 'Arquivo vazio ou sem cabeçalho válido.']);
    $dryRun = ($_POST['dry_run'] ?? '0') === '1';
    $ok = 0; $errors = [];
    foreach ($rows as $index => $row) {
        try {
            $type === 'clients'
                ? cobx_import_client($pdo, $companyId, $row, $dryRun)
                : cobx_import_charge($pdo, $companyId, $row, $dryRun);
            $ok++;
        } catch (Throwable $e) {
            $errors[] = ['line' => $index + 2, 'error' => $e->getMessage()];
        }
    }
    json_response(200, ['dry_run' => $dryRun, 'total' => count($rows), 'valid' => $ok, 'imported' => $dryRun ? 0 : $ok, 'errors' => $errors]);
}

/** @return list<array<string,string>> */
function cobx_import_csv(string $path): array
{
    $h = fopen($path, 'rb'); if ($h === false) return [];
    $first = fgets($h); if ($first === false) { fclose($h); return []; }
    $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    rewind($h); $headers = fgetcsv($h, 0, $delimiter); if (!$headers) { fclose($h); return []; }
    $headers = array_map('cobx_import_header', $headers); $out = [];
    while (($values = fgetcsv($h, 0, $delimiter)) !== false) {
        if (count(array_filter($values, static fn($v) => trim((string)$v) !== '')) === 0) continue;
        $values = array_pad($values, count($headers), '');
        $out[] = array_combine($headers, array_map(static fn($v) => trim((string)$v), array_slice($values, 0, count($headers))));
    }
    fclose($h); return $out;
}

/** @return list<array<string,string>> */
function cobx_import_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão ZIP do PHP é necessária para importar XLSX.');
    $zip = new ZipArchive(); if ($zip->open($path) !== true) throw new RuntimeException('Arquivo XLSX inválido.');
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml)) {
        $doc=new DOMDocument(); if($doc->loadXML($sharedXml)){ $xp=new DOMXPath($doc); foreach($xp->query('//*[local-name()="si"]') as $si){$parts=[];foreach($xp->query('.//*[local-name()="t"]',$si) as $t)$parts[]=$t->textContent;$shared[]=trim(implode('',$parts));} }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
    if (!is_string($sheetXml)) throw new RuntimeException('A primeira planilha não foi encontrada.');
    $doc=new DOMDocument(); if(!$doc->loadXML($sheetXml)) throw new RuntimeException('Planilha XLSX inválida.'); $xp=new DOMXPath($doc);
    $matrix = [];
    foreach ($xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
        $line = [];
        foreach ($xp->query('./*[local-name()="c"]',$row) as $cell) {
            $ref = $cell->attributes?->getNamedItem('r')?->nodeValue ?? 'A'; preg_match('/^[A-Z]+/', $ref, $m); $col = cobx_import_col_index($m[0] ?? 'A');
            $type=$cell->attributes?->getNamedItem('t')?->nodeValue ?? ''; $v=$xp->query('./*[local-name()="v"]',$cell)->item(0); $value=$v?->textContent ?? '';
            if ($type === 's') $value = $shared[(int)$value] ?? '';
            if ($type === 'inlineStr') {$parts=[];foreach($xp->query('.//*[local-name()="t"]',$cell) as $t)$parts[]=$t->textContent;$value=trim(implode('',$parts));}
            $line[$col] = trim($value);
        }
        if ($line !== []) { ksort($line); $matrix[] = array_replace(array_fill(0, max(array_keys($line)) + 1, ''), $line); }
    }
    if (count($matrix) < 2) return [];
    $headers = array_map('cobx_import_header', array_shift($matrix)); $out=[];
    foreach ($matrix as $values) { $values=array_pad($values,count($headers),''); $row=array_combine($headers,array_slice($values,0,count($headers))); foreach(['vencimento','due_date'] as $dateKey) if(isset($row[$dateKey])&&is_numeric($row[$dateKey]))$row[$dateKey]=(new DateTimeImmutable('1899-12-30'))->modify('+'.(int)$row[$dateKey].' days')->format('Y-m-d'); $out[]=$row; }
    return $out;
}

function cobx_import_header(string $value): string
{
    $value = mb_strtolower(trim($value)); $ascii = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value) ?: $value;
    return trim((string)preg_replace('/[^a-z0-9]+/','_',$ascii),'_');
}
function cobx_import_col_index(string $letters): int { $n=0; foreach(str_split($letters) as $c) $n=$n*26+(ord($c)-64); return $n-1; }

function cobx_import_client(PDO $pdo, string $companyId, array $r, bool $dry): void
{
    $name=trim((string)($r['nome'] ?? $r['name'] ?? '')); if($name==='') throw new RuntimeException('Nome é obrigatório.');
    $email=trim((string)($r['email'] ?? '')); if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email inválido.');
    if($dry) return;
    $pdo->prepare('INSERT INTO clients (id,company_id,name,email,phone,document,address_city,address_state,address_country) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([uuid_v4(),$companyId,$name,$email?:null,trim((string)($r['telefone']??$r['phone']??''))?:null,trim((string)($r['documento']??$r['document']??''))?:null,trim((string)($r['cidade']??''))?:null,trim((string)($r['estado']??''))?:null,'BR']);
}

function cobx_import_charge(PDO $pdo, string $companyId, array $r, bool $dry): void
{
    $clientRef=trim((string)($r['cliente_email']??$r['client_email']??$r['cliente_documento']??''));
    $productName=trim((string)($r['produto']??$r['product']??'')); $accountName=trim((string)($r['conta']??$r['account']??''));
    $method=mb_strtolower(trim((string)($r['metodo']??$r['payment_method']??'pix'))); $due=trim((string)($r['vencimento']??$r['due_date']??''));
    if($clientRef===''||$productName===''||$due==='') throw new RuntimeException('cliente_email/documento, produto e vencimento são obrigatórios.');
    if(!in_array($method,['pix','boleto'],true)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)) throw new RuntimeException('Método ou vencimento inválido.');
    $q=$pdo->prepare('SELECT id FROM clients WHERE company_id=? AND (email=? OR document=?) LIMIT 1'); $q->execute([$companyId,$clientRef,$clientRef]); $clientId=$q->fetchColumn();
    $q=$pdo->prepare('SELECT * FROM products WHERE company_id=? AND name=? AND is_active=1 LIMIT 1'); $q->execute([$companyId,$productName]); $product=$q->fetch(PDO::FETCH_ASSOC);
    $sql='SELECT * FROM payment_accounts WHERE company_id=? AND is_active=1'.($accountName!==''?' AND name=?':'').' ORDER BY is_default DESC LIMIT 1'; $q=$pdo->prepare($sql); $q->execute($accountName!==''?[$companyId,$accountName]:[$companyId]); $account=$q->fetch(PDO::FETCH_ASSOC);
    if(!$clientId||!$product||!$account) throw new RuntimeException('Cliente, produto ou conta não encontrado.');
    if($method==='boleto' && $account['provider']!=='asaas') throw new RuntimeException('Boleto requer conta Asaas.');
    if($dry) return;
    $id=uuid_v4(); $pdo->beginTransaction();
    try { $pdo->prepare('INSERT INTO charges (id,company_id,client_id,product_id,description,total_amount,installments_count,payment_gateway,payment_account_id,payment_method,status) VALUES (?,?,?,?,?,?,?,?,?,?,\'pending\')')->execute([$id,$companyId,$clientId,$product['id'],$product['name'],$product['price'],$product['installments_count'],$account['provider'],$account['id'],$method]); cobx_charge_insert_installments($pdo,$id,$companyId,$product,new DateTimeImmutable($due)); $pdo->commit(); }
    catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    $jobId=cobx_queue_enqueue($pdo,$companyId,'generate_charge',['charge_id'=>$id]);
    cobx_charge_audit($pdo,$companyId,$id,'imported',null,['client_id'=>$clientId,'product_id'=>$product['id'],'payment_account_id'=>$account['id'],'payment_method'=>$method],['job_id'=>$jobId]);
}
