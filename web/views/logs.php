<?php
// =====================================================
// logs.php — Bitácora de estrategias con acceso a logs RMAN (vía PHP seguro)
// =====================================================

// === CONFIG ===
function logsBaseDir(): string {
  // Ruta base donde el .bat genera los logs
  return 'C:\\oracle19c\\rman_app\\logs';
}
function stratLogDir(int $strategyId): string {
  return logsBaseDir() . '\\strat_' . $strategyId;
}

// Lista todos los .txt en subcarpetas (YYYY-MM-DD)
function listAllLogFiles(int $strategyId): array {
  $base = stratLogDir($strategyId);
  if (!is_dir($base)) return [];
  $files = [];
  $rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($rii as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'txt') {
      $files[] = $f->getPathname();
    }
  }
  usort($files, fn($a,$b) => filemtime($b) <=> filemtime($a));
  return $files;
}

function findLatestLogFile(int $strategyId): ?string {
  $files = listAllLogFiles($strategyId);
  return $files[0] ?? null;
}

// Seguridad: valida path dentro de su carpeta
function safeLogPath(string $path, int $strategyId): ?string {
  $path = str_replace(['/', '\\\\'], '\\', $path);
  $base = realpath(stratLogDir($strategyId));
  $real = realpath($path);
  if (!$base || !$real) return null;
  if (stripos($real, $base) !== 0) return null;
  if (pathinfo($real, PATHINFO_EXTENSION) !== 'txt') return null;
  return $real;
}

// === ENDPOINTS DEDICADOS DE ESTE ARCHIVO ===
// Usamos logAction en lugar de action para NO chocar con el router principal.
if (isset($_GET['logAction'], $_GET['strategyId'])) {
  $logAction = $_GET['logAction'];
  $sid = (int)$_GET['strategyId'];

  // Abrir/descargar el último log
  if ($logAction === 'latest') {
    $file = findLatestLogFile($sid);
    if (!$file || !is_file($file)) {
      http_response_code(404);
      echo "No hay logs aún.";
      exit;
    }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: inline; filename="'.basename($file).'"');
    readfile($file);
    exit;
  }

  // Abrir/descargar un log específico
  if ($logAction === 'open' && isset($_GET['path'])) {
    $path = safeLogPath($_GET['path'], $sid);
    if (!$path || !is_file($path)) {
      http_response_code(404);
      echo "Log no encontrado.";
      exit;
    }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: inline; filename="'.basename($path).'"');
    readfile($path);
    exit;
  }
}

// =====================================================
// SUPONIENDO QUE YA EXISTEN $logs y $strategyId
// =====================================================

$self = 'views/logs.php'; // ✅ ruta real desde el navegador
?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Bitácora de estrategia #<?= (int)$strategyId ?></strong>
    <a class="btn btn-sm btn-outline-secondary" href="?action=list">Volver</a>
  </div>

  <?php
  $latestLogFile = findLatestLogFile((int)$strategyId);
  ?>
  <div class="p-2 border-bottom d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-primary"
       href="<?= htmlspecialchars($self) ?>?logAction=latest&strategyId=<?= (int)$strategyId ?>"
       target="_blank" rel="noopener">
       📄 Ver último log
    </a>
    <?php if ($latestLogFile): ?>
      <button class="btn btn-sm btn-outline-secondary" id="copyPath"
        data-path="<?= htmlspecialchars($latestLogFile) ?>">
  ⬇️ Descargar log
</button>

      <span class="text-muted small ms-2">
        Último: <code><?= htmlspecialchars(basename($latestLogFile)) ?></code>
      </span>
    <?php endif; ?>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive" style="max-height: 540px;">
      <table class="table table-sm align-middle mb-0 table-sticky">
        <thead>
          <tr>
            <th>ID</th>
            <th>Inicio</th>
            <th>Fin</th>
            <th>Estado</th>
            <th>Mensaje (parcial)</th>
            <th>Creado</th>
            <th>Archivo Log</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($logs)): ?>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td><?= (int)($l['LOG_ID'] ?? 0) ?></td>
              <td><?= htmlspecialchars($l['STARTED_AT'] ?? '') ?></td>
              <td><?= htmlspecialchars($l['FINISHED_AT'] ?? '') ?></td>
              <td>
                <?php
                  $status = strtoupper($l['STATUS'] ?? '');
                  $cls = match($status) {
                    'SUCCESS' => 'success',
                    'FAILED'  => 'danger',
                    'WARNING' => 'warning',
                    default   => 'secondary'
                  };
                ?>
                <span class="badge bg-<?= $cls ?>"><?= htmlspecialchars($status) ?></span>
              </td>
              <td><code style="white-space:pre-wrap"><?= htmlspecialchars($l['MESSAGE'] ?? '') ?></code></td>
              <td><?= htmlspecialchars($l['CREATED_AT'] ?? '') ?></td>
              <td>
                <?php
                  $sid = (int)$strategyId;
                  $latest = findLatestLogFile($sid);
                  if ($latest) {
                    $url = htmlspecialchars($self) . '?logAction=open&strategyId='.$sid.'&path='.urlencode($latest);
                    echo '<a class="btn btn-sm btn-outline-primary" href="'.$url.'" target="_blank" rel="noopener">📄 Ver log</a>';
                  } else {
                    echo '<span class="text-muted small">—</span>';
                  }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7" class="text-center text-muted p-4">Sin registros.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
// =====================================================
// Listado de todos los archivos de log por fecha
// =====================================================
$files = listAllLogFiles((int)$strategyId);
if ($files) {
  echo '<div class="mt-3"><h6>📂 Logs almacenados</h6><ul class="list-unstyled small">';
  foreach ($files as $f) {
    $u = htmlspecialchars($self) . '?logAction=open&strategyId='.(int)$strategyId.'&path='.urlencode($f);
    echo '<li>📄 <a href="'.$u.'" target="_blank" rel="noopener">'.htmlspecialchars(basename($f)).'</a>'
       . ' <span class="text-muted">('.date('Y-m-d H:i:s', filemtime($f)).')</span>'
       . ' — <code>'.htmlspecialchars(dirname($f)).'</code>'
       . '</li>';
  }
  echo '</ul></div>';
}
?>

<script>
document.getElementById('copyPath')?.addEventListener('click', e => {
  const path = e.target.dataset.path;
  const sid  = <?= (int)$strategyId ?>;
  
  // Construimos la URL PHP que devuelve el archivo
  const url = `views/logs.php?logAction=open&strategyId=${sid}&path=${encodeURIComponent(path)}`;
  
  // Forzamos la descarga del archivo
  const link = document.createElement('a');
  link.href = url;
  link.download = ''; // indica al navegador que es una descarga
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
});
</script>


