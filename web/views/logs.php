<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Bitácora de estrategia #<?= (int)$strategyId ?></strong>
    <a class="btn btn-sm btn-outline-secondary" href="?action=list">Volver</a>
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
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6" class="text-center text-muted p-4">Sin registros.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
