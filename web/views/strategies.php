<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-4">
      <div class="card-header"><strong>Catálogo de estrategias</strong></div>
      <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 420px;">
          <table class="table table-sm align-middle mb-0 table-sticky">
            <thead>
              <tr>
                <th>ID</th>
                <th>Cliente / Alias</th>
                <th>Nombre</th>
                <th>Tipo</th>
                <th>Inicio</th>
                <th>Freq</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($strategies as $s): ?>
              <tr>
                <td><?= (int)$s['STRATEGY_ID'] ?></td>
                <td><div><?= htmlspecialchars($s['CLIENT_NAME']) ?></div>
                    <div class="small-note"><?= htmlspecialchars($s['DB_ALIAS']) ?></div></td>
                <td><?= htmlspecialchars($s['NAME_CODE']) ?></td>
                <td><span class="badge bg-primary badge-pill"><?= htmlspecialchars($s['BACKUP_TYPE']) ?></span></td>
                <td><?= htmlspecialchars($s['START_TIME']) ?></td>
                <td><?= htmlspecialchars($s['FREQ']) ?></td>
                <td>
                  <a class="btn btn-sm btn-outline-secondary" href="?action=logs&id=<?= (int)$s['STRATEGY_ID'] ?>">Logs</a>
                  <a class="btn btn-sm btn-success" href="?action=run&id=<?= (int)$s['STRATEGY_ID'] ?>">Ejecutar ahora</a>
                </td>
              </tr>
            <?php endforeach; if (empty($strategies)): ?>
              <tr><td colspan="7" class="text-center text-muted p-4">Sin estrategias aún.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-footer small-note">
        * Si el tipo es <strong>FULL</strong>, no se permiten objetos seleccionados.
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <form class="card" method="post" action="?action=save" onsubmit="return buildObjectsJson();">
      <div class="card-header"><strong>Nueva / Editar estrategia</strong></div>
      <div class="card-body">
        <input type="hidden" name="strategy_id" value="">
        <input type="hidden" name="schedule_id" value="">
        <input type="hidden" id="objects_json" name="objects_json" value="">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Cliente</label>
            <input class="form-control" name="client_name" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">DB Alias</label>
            <input class="form-control" name="db_alias" required>
          </div>
          <div class="col-md-8">
            <label class="form-label">Nombre estrategia (name_code)</label>
            <input class="form-control" name="name_code" placeholder="rmadb0101.rma" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Tipo de respaldo</label>
            <select class="form-select" name="backup_type" id="backup_type" onchange="toggleObjectsByType()">
              <option value="FULL">FULL</option>
              <option value="PARCIAL">PARCIAL</option>
              <option value="INCREMENTAL">INCREMENTAL</option>
              <option value="INCOMPLETO">INCOMPLETO</option>
            </select>
          </div>

          <div class="col-12">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="include_ctlfile" id="include_ctlfile">
              <label class="form-check-label" for="include_ctlfile">Incluir Control Files</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="include_logfile" id="include_logfile">
              <label class="form-check-label" for="include_logfile">Incluir Archive Logfiles</label>
            </div>
          </div>

          <!-- Objetos: Tablespaces / Datafiles -->
          <div class="col-12">
            <div class="border rounded p-2">
              <div class="d-flex justify-content-between align-items-center">
                <strong>Objetos</strong>
                <span class="small-note">Deshabilitado si el tipo es FULL</span>
              </div>
              <div class="row mt-2">
                <div class="col-md-6">
                  <div class="small fw-bold">Tablespaces</div>
                  <div class="border rounded p-2" style="max-height:180px; overflow:auto;">
                    <?php foreach ($discovery['tablespaces'] as $t): ?>
                      <div class="form-check">
                        <input class="form-check-input obj-ts" type="checkbox" value="<?= htmlspecialchars($t['TABLESPACE_NAME']) ?>" id="ts_<?= htmlspecialchars($t['TABLESPACE_NAME']) ?>">
                        <label class="form-check-label" for="ts_<?= htmlspecialchars($t['TABLESPACE_NAME']) ?>">
                          <?= htmlspecialchars($t['TABLESPACE_NAME']) ?>
                        </label>
                      </div>
                    <?php endforeach; ?>
                    <?php if (empty($discovery['tablespaces'])): ?>
                      <div class="text-muted">No visible.</div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="small fw-bold">Datafiles</div>
                  <div class="border rounded p-2" style="max-height:180px; overflow:auto;">
                    <?php foreach ($discovery['datafiles'] as $d): ?>
                      <div class="form-check">
                        <input class="form-check-input obj-df" type="checkbox" value="<?= htmlspecialchars($d['DATAFILE_PATH']) ?>" id="df_<?= md5($d['DATAFILE_PATH']) ?>">
                        <label class="form-check-label" for="df_<?= md5($d['DATAFILE_PATH']) ?>">
                          <div><?= htmlspecialchars($d['DATAFILE_PATH']) ?></div>
                          <div class="small-note"><?= htmlspecialchars($d['TABLESPACE_NAME']) ?> — <?= (int)$d['SIZE_MB'] ?> MB</div>
                        </label>
                      </div>
                    <?php endforeach; ?>
                    <?php if (empty($discovery['datafiles'])): ?>
                      <div class="text-muted">No visible.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Calendarización -->
          <div class="col-12">
            <div class="border rounded p-2">
              <strong>Calendarización</strong>
              <div class="row mt-2 g-2">
                <div class="col-md-4">
                  <label class="form-label">Frecuencia</label>
                  <select class="form-select" name="freq">
                    <option value="DAILY">DAILY</option>
                    <option value="WEEKLY">WEEKLY</option>
                    <option value="MONTHLY">MONTHLY</option>
                    <option value="ONCE">ONCE</option>
                  </select>
                </div>
                <div class="col-md-8">
                  <label class="form-label">Inicio</label>
                  <input class="form-control" type="datetime-local" name="start_time" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">BYDAY (WEEKLY)</label>
                  <input class="form-control" name="byday" placeholder="MON,TUE,WED">
                </div>
                <div class="col-md-4">
                  <label class="form-label">BYHOUR</label>
                  <input class="form-control" name="byhour" placeholder="2 o 2,14">
                </div>
                <div class="col-md-4">
                  <label class="form-label">BYMINUTE</label>
                  <input class="form-control" name="byminute" placeholder="0 o 0,30">
                </div>
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="enabled" id="enabled" checked>
                    <label class="form-check-label" for="enabled">Habilitar Job</label>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
      <div class="card-footer d-flex justify-content-end gap-2">
        <button class="btn btn-primary" type="submit">Guardar y programar</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleObjectsByType() {
  const type = document.getElementById('backup_type').value;
  const disabled = (type === 'FULL');
  document.querySelectorAll('.obj-ts, .obj-df').forEach(el => el.disabled = disabled);
}
function buildObjectsJson() {
  const type = document.getElementById('backup_type').value;
  if (type === 'FULL') return true;

  const items = [];
  document.querySelectorAll('.obj-ts:checked').forEach(chk => {
    items.push({ tablespace: chk.value, datafile: null, size_mb: null, selected: 'S' });
  });
  document.querySelectorAll('.obj-df:checked').forEach(chk => {
    // el tamaño no es necesario para RMAN, es informativo
    const label = chk.closest('label');
    items.push({ tablespace: null, datafile: chk.value, size_mb: null, selected: 'S' });
  });

  if (items.length === 0) {
    alert('Para respaldos no FULL, seleccione al menos un tablespace o datafile.');
    return false;
  }
  document.getElementById('objects_json').value = JSON.stringify(items);
  return true;
}
toggleObjectsByType();
</script>
