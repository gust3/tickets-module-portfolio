<?php
// Интеграционные тесты ПОВЕДЕНИЯ Airnet API.
// Проверяем HTTP-ответы и согласованность данных, а не строки кода.
// Запуск:  docker compose exec api php tests/run-tests.php
declare(strict_types=1);

const BASE_URL = 'http://127.0.0.1:8080';
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;

function check(string $name, bool $cond, string $detail = ''): void {
    if ($cond) { $GLOBALS['pass']++; echo "  \033[32mPASS\033[0m  {$name}\n"; }
    else       { $GLOBALS['fail']++; echo "  \033[31mFAIL\033[0m  {$name}  {$detail}\n"; }
}

function http(string $method, string $path, ?array $body = null, ?string $csrf = null, ?string $jar = null): array {
    $ch = curl_init(BASE_URL . $path);
    $h = ['Content-Type: application/json'];
    if ($csrf !== null) $h[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
    ]);
    if ($jar !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = (string) curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, json_decode($raw, true), $raw];
}

function login(string $role): array {
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    [$st, $j] = http('POST', '/api/demo/login', ['role' => $role], null, $jar);
    if ($st !== 200) throw new RuntimeException("login({$role}) failed: {$st}");
    return [$jar, $j['csrf']];
}

function db() { return pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s",
    getenv('PGHOST') ?: 'db', getenv('PGPORT') ?: '5432', getenv('PGDATABASE') ?: 'airnet_assessment',
    getenv('PGUSER') ?: 'assessment', getenv('PGPASSWORD') ?: 'assessment_demo')); }
function dbExec(string $sql, array $p = []): void { pg_query_params(db(), $sql, $p); }
function dbMaxHist(int $ticketId): int { $r = pg_query_params(db(), 'SELECT COALESCE(MAX(id),0) m FROM ticket_history WHERE ticket_id=$1', [$ticketId]);
    return (int) (pg_fetch_assoc($r)['m'] ?? 0); }
function dbMaxTicket(): int { $r = pg_query_params(db(), 'SELECT COALESCE(MAX(id),0) m FROM tickets', []);
    return (int) (pg_fetch_assoc($r)['m'] ?? 0); }

function tickets(): array { [$st,$j] = http('GET','/api/tickets',null,null,$GLOBALS['EJAR']); return $j['tickets'] ?? []; }
function findActiveWithSource(): ?array { foreach (tickets() as $t) if ($t['status']!=='archived' && !empty($t['source_name'])) return $t; return null; }
function findArchived(): ?array { foreach (tickets() as $t) if ($t['status']==='archived') return $t; return null; }
function otherSourceId(int $exclude, string $jar): ?int { [$st,$j]=http('GET','/api/sources',null,null,$jar);
    foreach ($j['sources'] ?? [] as $s) if ($s['id'] !== $exclude) return $s['id']; return null; }

// ---------- ТЕСТЫ ----------

function test_null_source(): void {
    echo "\n[REGRESSION / Баг №2] Карточка заявки без источника открывается, а не падает\n";
    $testId = dbMaxTicket() + 1;
    dbExec('INSERT INTO tickets(id, title, description, status, source_id) VALUES($1, $2, $3, $4, NULL)',
        [$testId, 'Тестовая заявка без источника', 'Для проверки бага №2', 'active']);
    try {
        [$st,$j] = http('GET', '/api/tickets/'.$testId, null, null, $GLOBALS['EJAR']);
        check('HTTP 200 (не 500 — воспроизведение бага)', $st === 200, "got {$st}");
        check('source === null', isset($j['ticket']) && $j['ticket']['source'] === null);
        check('id корректный', ($j['ticket']['id'] ?? null) === $testId);
    } finally {
        dbExec('DELETE FROM tickets WHERE id=$1', [$testId]);
    }
}

function test_error_codes(): void {
    echo "\n[Коды ошибок] 401/404/400 приходят как JSON\n";
    [$st,$j] = http('GET','/api/tickets');
    check('401 без сессии', $st === 401, "got {$st}");
    check('401 — JSON', is_array($j) && ($j['error'] ?? '') === 'unauthorized');
    [$st2,$j2] = http('GET','/api/tickets/999999',null,null,$GLOBALS['EJAR']);
    check('404 нет заявки', $st2 === 404, "got {$st2}");
    check('404 — JSON', ($j2['error'] ?? '') === 'not_found');
    $t = findActiveWithSource();
    if ($t) {
        [$st3,$j3] = http('POST', "/api/tickets/{$t['id']}/source", ['source_id'=>'abc'], $GLOBALS['ECSRF'], $GLOBALS['EJAR']);
        check('400 неверный ввод', $st3 === 400, "got {$st3}");
        check('400 — JSON', ($j3['error'] ?? '') === 'invalid_source');
    }
}

function test_permissions_viewer(): void {
    echo "\n[Баг №3] Viewer получает 403 ДО изменения данных\n";
    $t = findActiveWithSource();
    if (!$t) { check('есть активная заявка с источником', false); return; }
    [$st,$cur] = http('GET','/api/tickets/'.$t['id'],null,null,$GLOBALS['EJAR']);
    $curSrc = $cur['ticket']['source']['id'];
    $newId = otherSourceId($curSrc, $GLOBALS['EJAR']);
    [$vJar,$vCsrf] = login('viewer');
    [$st2,$j2] = http('POST', "/api/tickets/{$t['id']}/source", ['source_id'=>$newId], $vCsrf, $vJar);
    check('viewer: HTTP 403', $st2 === 403, "got {$st2}");
    check('viewer: error=forbidden', ($j2['error'] ?? '') === 'forbidden');
    [$st3,$after] = http('GET','/api/tickets/'.$t['id'],null,null,$GLOBALS['EJAR']);
    check('данные НЕ изменились', ($after['ticket']['source']['id'] ?? null) === $curSrc);
}

function test_validation_order(): void {
    echo "\n[Порядок проверок] Архив проверяется раньше корректности источника\n";
    $t = findArchived();
    if (!$t) { check('в демо есть архивная заявка', false); return; }
    [$st,$j] = http('POST', "/api/tickets/{$t['id']}/source", ['source_id'=>999999], $GLOBALS['ECSRF'], $GLOBALS['EJAR']);
    check('HTTP 403 (архив раньше источника)', $st === 403, "got {$st}");
    check('сообщение про архив', strpos($j['message'] ?? '', 'рхив') !== false);
}

function test_same_source_no_history(): void {
    echo "\n[Повторный выбор] Тот же источник -> успех без новой записи истории\n";
    $t = findActiveWithSource();
    if (!$t) { check('есть активная заявка с источником', false); return; }
    [$st,$cur] = http('GET','/api/tickets/'.$t['id'],null,null,$GLOBALS['EJAR']);
    $curSrc = $cur['ticket']['source']['id']; $histBefore = count($cur['history']);
    [$st2,$after] = http('POST', "/api/tickets/{$t['id']}/source", ['source_id'=>$curSrc], $GLOBALS['ECSRF'], $GLOBALS['EJAR']);
    check('HTTP 200', $st2 === 200, "got {$st2}");
    check('источник тот же', ($after['ticket']['source']['id'] ?? null) === $curSrc);
    check('история НЕ выросла', count($after['history']) === $histBefore, "before={$histBefore} after=".count($after['history']));
}

function test_successful_change_consistency(): void {
    echo "\n[Консистентность] Успешное изменение обновляет и карточку, и историю синхронно\n";
    $t = findActiveWithSource();
    if (!$t) { check('есть активная заявка с источником', false); return; }

    $maxHist = dbMaxHist($t['id']);
    [$st,$cur] = http('GET','/api/tickets/'.$t['id'],null,null,$GLOBALS['EJAR']);
    $oldId = $cur['ticket']['source']['id'];
    $newId = otherSourceId($oldId, $GLOBALS['EJAR']);
    $histBefore = count($cur['history']);

    [$st2,$res] = http('POST', "/api/tickets/{$t['id']}/source", ['source_id'=>$newId], $GLOBALS['ECSRF'], $GLOBALS['EJAR']);

    check('HTTP 200', $st2 === 200, "got {$st2}");
    check('карточка: новый источник', ($res['ticket']['source']['id'] ?? null) === $newId);
    check('история: +1 запись', count($res['history']) === $histBefore + 1);

    $last = $res['history'][0] ?? [];
    check('история: old=прежний', ($last['old_source_id'] ?? null) === $oldId);
    check('история: new=новый', ($last['new_source_id'] ?? null) === $newId);
    check('история: сотрудник из сессии', !empty($last['employee_name']));

    [$st3,$list] = http('GET','/api/tickets',null,null,$GLOBALS['EJAR']);
    $inList = null; foreach ($list['tickets'] as $x) if ($x['id']===$t['id']) $inList=$x;
    check('список обновился', ($inList['source_name'] ?? null) === ($res['ticket']['source']['name'] ?? null));

    // Teardown
    dbExec('UPDATE tickets SET source_id=$1 WHERE id=$2', [$oldId, $t['id']]);
    dbExec('DELETE FROM ticket_history WHERE ticket_id=$1 AND id>$2', [$t['id'], $maxHist]);
}

// ---------- RUNNER ----------
echo "=== Airnet API integration tests ===\n";
[$GLOBALS['EJAR'], $GLOBALS['ECSRF']] = login('editor');
test_null_source();
test_error_codes();
test_permissions_viewer();
test_validation_order();
test_same_source_no_history();
test_successful_change_consistency();
echo "\n=== ИТОГО: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed ===\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);