<?php
session_start();
require __DIR__ . '/dao.php';
require __DIR__ . '/views/layout.php';

// ==== LOGIN SIMPLE ====
if (isset($_GET['login'])) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['db_user'] = trim($_POST['user']);
    $_SESSION['db_pass'] = trim($_POST['pass']);
    $_SESSION['db_conn'] = trim($_POST['conn']); // Ejemplo: localhost/CRANPDB
    header("Location: index.php");
    exit;
  }
  ?>
  <div class="container mt-5" style="max-width:400px;">
    <h4 class="mb-3 text-center">Conectar a Oracle</h4>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Usuario</label>
        <input name="user" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Contraseña</label>
        <input type="password" name="pass" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Conexión (host/SID o servicio)</label>
        <input name="conn" class="form-control" placeholder="localhost/CRANPDB" required>
      </div>
      <button class="btn btn-primary w-100">Ingresar</button>
    </form>
  </div>
  <?php
  exit;
}

if (isset($_GET['logout'])) {
  close_db();        
  session_destroy(); 
  header("Location: ?login=1");
  exit;
}


// ====================
// LÓGICA PRINCIPAL APP
// ====================

$action = $_GET['action'] ?? 'list';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) Validación mínima
    $backup_type = strtoupper(trim($_POST['backup_type'] ?? 'FULL'));
    $objects_json = $_POST['objects_json'] ?? '';

    if ($backup_type === 'FULL') {
        $objects_json = ''; // Regla: FULL => sin objetos
    } else {
        if (empty($objects_json)) {
            flash('error', 'Debe seleccionar al menos un objeto para respaldos no FULL.');
            redirect('?action=list');
        }
    }

    // 2) Upsert de estrategia
    $strategyId = callUpsertStrategy([
        'strategy_id'     => $_POST['strategy_id'] ?: null,
        'client_name'     => trim($_POST['client_name']),
        'db_alias'        => trim($_POST['db_alias']),
        'name_code'       => trim($_POST['name_code']),
        'backup_type'     => $backup_type,
        'include_ctlfile' => (!empty($_POST['include_ctlfile'])) ? 'S' : 'N',
        'include_logfile' => (!empty($_POST['include_logfile'])) ? 'S' : 'N',
    ]);

    // 3) Objetos (solo si no es FULL)
    if ($backup_type !== 'FULL' && !empty($objects_json)) {
        callSetObjects($strategyId, $objects_json);
    }

    // 4) Calendarización
    $scheduleId = callUpsertSchedule([
        'schedule_id' => $_POST['schedule_id'] ?: null,
        'strategy_id' => $strategyId,
        'freq'        => strtoupper($_POST['freq'] ?? 'DAILY'),
        'start_time'  => trim($_POST['start_time'] ?? ''), // 'YYYY-MM-DDTHH:MM'
        'byday'       => trim($_POST['byday'] ?? ''),      // MON,TUE,...
        'byhour'      => trim($_POST['byhour'] ?? ''),     // ej 2 o 2,14
        'byminute'    => trim($_POST['byminute'] ?? ''),   // ej 0 o 0,30
        'enabled'     => (!empty($_POST['enabled'])) ? 'S' : 'N'
    ]);

    // 5) (Re)crear job + generar .rman
    callCreateOrReplaceJob($strategyId);

    flash('ok', 'Estrategia guardada y job programado correctamente.');
    redirect('?action=list');
}

if ($action === 'run' && isset($_GET['id'])) {
    callRunNow((int)$_GET['id']);
    flash('ok', 'Ejecución enviada al Scheduler.');
    redirect('?action=list');
}

if ($action === 'logs' && isset($_GET['id'])) {
    $strategyId = (int)$_GET['id'];
    $logs = fetchLogs($strategyId);
    render('logs', ['logs' => $logs, 'strategyId' => $strategyId]);
    exit;
}

// DEFAULT: listar + formulario
$strategies = fetchStrategies();
$discovery  = fetchDiscovery(); // ['tablespaces'=>[], 'datafiles'=>[]]
render('strategies', [
    'strategies' => $strategies,
    'discovery'  => $discovery,
    'flash'      => get_flash(),
]);
