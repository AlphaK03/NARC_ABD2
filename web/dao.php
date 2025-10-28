<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =========================
// Conexión OCI8 + utilidades
// =========================
function db() {
    static $conn;
    if ($conn) return $conn;

    // Si no hay sesión iniciada, forzar login
    if (empty($_SESSION['db_user']) || empty($_SESSION['db_pass']) || empty($_SESSION['db_conn'])) {
        header("Location: ?login=1");
        exit;
    }

    $user = $_SESSION['db_user'];
    $pass = $_SESSION['db_pass'];
    $connStr = $_SESSION['db_conn']; // Ej: localhost/XE

    $conn = @oci_connect($user, $pass, $connStr, 'AL32UTF8');
    if (!$conn) {
        $e = oci_error();
        session_destroy();
        die('Error de conexión Oracle: ' . htmlentities($e['message']));
    }
    return $conn;
}

function getArchiveMode() {
    $conn = db(); // usa tu función existente de conexión
    $sql = "SELECT LOG_MODE FROM V\$DATABASE";
    $stid = oci_parse($conn, $sql);
    oci_execute($stid);
    $row = oci_fetch_assoc($stid);
    oci_free_statement($stid);
    return $row ? $row['LOG_MODE'] : 'DESCONOCIDO'; // ARCHIVELOG o NOARCHIVELOG
}


function redirect($url) { header("Location: $url"); exit; }

function flash($type, $msg) { $_SESSION ?? session_start(); $_SESSION['flash'] = [$type, $msg]; }
function get_flash() { $_SESSION ?? session_start(); $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

function render($view, $params = []) {
    extract($params);
    include __DIR__ . "/views/$view.php"; // solo la vista
}


function refcursor_to_array($stmt) {
    $rows = [];
    while ($r = oci_fetch_assoc($stmt)) $rows[] = $r;
    return $rows;
}
function close_db() {
    global $conn;
    if ($conn && oci_close($conn)) {
        $conn = null;
    }
}

// ==========================
// Llamadas al paquete BK_PKG
// ==========================
function callUpsertStrategy(array $in): int {
    $conn = db();
    $sql = "BEGIN bk_pkg.upsert_strategy(:p_id,:p_client,:p_alias,:p_name,:p_type,:p_ctl,:p_log); END;";
    $st = oci_parse($conn, $sql);

    $id = $in['strategy_id'];
    oci_bind_by_name($st, ':p_id',    $id, 40);
    oci_bind_by_name($st, ':p_client',$in['client_name']);
    oci_bind_by_name($st, ':p_alias', $in['db_alias']);
    oci_bind_by_name($st, ':p_name',  $in['name_code']);
    oci_bind_by_name($st, ':p_type',  $in['backup_type']);
    oci_bind_by_name($st, ':p_ctl',   $in['include_ctlfile']);
    oci_bind_by_name($st, ':p_log',   $in['include_logfile']);

    if (!oci_execute($st)) { $e = oci_error($st); die($e['message']); }
    return (int)$id;
}

function callSetObjects(int $strategyId, string $objectsJson): void {
    $conn = db();
    $sql = "BEGIN bk_pkg.set_strategy_objects(:p_sid,:p_json); END;";
    $st = oci_parse($conn, $sql);

    $clob = oci_new_descriptor($conn, OCI_D_LOB);
    $clob->writeTemporary($objectsJson, OCI_TEMP_CLOB);

    oci_bind_by_name($st, ':p_sid',  $strategyId);
    oci_bind_by_name($st, ':p_json', $clob, -1, OCI_B_CLOB);

    if (!oci_execute($st)) { $e = oci_error($st); die($e['message']); }
    $clob->close();
}

function callUpsertSchedule(array $in): int {
    $conn = db();
    $ts = $in['start_time'];
    $sql = "BEGIN bk_pkg.upsert_schedule(:p_sched_id,:p_sid,:p_freq,to_timestamp(:p_start_str,'YYYY-MM-DD\"T\"HH24:MI'),:p_byday,:p_byhour,:p_byminute,:p_enabled); END;";
    $st = oci_parse($conn, $sql);

    $schedId = $in['schedule_id'];
    oci_bind_by_name($st, ':p_sched_id', $schedId, 40);
    oci_bind_by_name($st, ':p_sid',      $in['strategy_id']);
    oci_bind_by_name($st, ':p_freq',     $in['freq']);
    oci_bind_by_name($st, ':p_start_str',$ts);
    oci_bind_by_name($st, ':p_byday',    $in['byday']);
    oci_bind_by_name($st, ':p_byhour',   $in['byhour']);
    oci_bind_by_name($st, ':p_byminute', $in['byminute']);
    oci_bind_by_name($st, ':p_enabled',  $in['enabled']);

    if (!oci_execute($st)) { $e = oci_error($st); die($e['message']); }
    return (int)$schedId;
}

function callCreateOrReplaceJob(int $strategyId): void {
    $conn = db();
    $st = oci_parse($conn, "BEGIN bk_pkg.create_or_replace_job(:p_id); END;");
    oci_bind_by_name($st, ':p_id', $strategyId);
    if (!oci_execute($st)) { $e = oci_error($st); die($e['message']); }
}

function callRunNow(int $strategyId): void {
    $conn = db();
    $st = oci_parse($conn, "BEGIN bk_pkg.run_now(:p_id); END;");
    oci_bind_by_name($st, ':p_id', $strategyId);
    if (!oci_execute($st)) { $e = oci_error($st); die($e['message']); }
}

// ==========================
// Consultas de apoyo (SELECT)
// ==========================
function fetchStrategies(): array {
    $conn = db();
    $sql = "
      SELECT s.strategy_id, s.client_name, s.db_alias, s.name_code, s.backup_type,
             s.include_ctlfile, s.include_logfile, s.created_at,
             (SELECT MIN(start_time) FROM BK_SCHEDULE sch WHERE sch.strategy_id = s.strategy_id) AS start_time,
             (SELECT freq FROM BK_SCHEDULE sch WHERE sch.strategy_id = s.strategy_id AND ROWNUM=1) AS freq
      FROM BK_STRATEGY s
      ORDER BY s.strategy_id DESC";
    $st = oci_parse($conn, $sql);
    oci_execute($st);
    return refcursor_to_array($st);
}

function fetchLogs(int $strategyId): array {
    $conn = db();
    $rc = oci_new_cursor($conn);
    $st = oci_parse($conn, "BEGIN :rc := bk_pkg.get_logs(:p_id); END;");
    oci_bind_by_name($st, ':p_id', $strategyId);
    oci_bind_by_name($st, ':rc', $rc, -1, OCI_B_CURSOR);
    if (!oci_execute($st)) { $e = oci_error($st); die($e['message']); }
    oci_execute($rc);
    $rows = refcursor_to_array($rc);
    oci_free_statement($rc);
    return $rows;
}

function fetchDiscovery(): array {
    $conn = db();

    // Tablespaces
    $rc1 = oci_new_cursor($conn);
    $st1 = oci_parse($conn, "BEGIN :rc := bk_pkg.list_tablespaces; END;");
    oci_bind_by_name($st1, ':rc', $rc1, -1, OCI_B_CURSOR);
    oci_execute($st1); oci_execute($rc1);
    $tablespaces = refcursor_to_array($rc1);
    oci_free_statement($rc1);

    // Datafiles
    $rc2 = oci_new_cursor($conn);
    $st2 = oci_parse($conn, "BEGIN :rc := bk_pkg.list_datafiles; END;");
    oci_bind_by_name($st2, ':rc', $rc2, -1, OCI_B_CURSOR);
    oci_execute($st2); oci_execute($rc2);
    $datafiles = refcursor_to_array($rc2);
    oci_free_statement($rc2);

    return ['tablespaces' => $tablespaces, 'datafiles' => $datafiles];
}
function getNextStrategyName(): string {
    $conn = db();
    $user = strtoupper($_SESSION['db_user'] ?? 'USER');
    $sql = "SELECT LPAD(NVL(MAX(strategy_id)+1,1),3,'0') AS nextnum FROM BK_STRATEGY";
    $st = oci_parse($conn, $sql);
    oci_execute($st);
    $row = oci_fetch_assoc($st);
    $num = $row['NEXTNUM'] ?? '001';
    return "rman_{$user}_{$num}.rma";
}
