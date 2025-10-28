<?php if (!isset($__layout_started)): $__layout_started = true; ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Estilos separados -->
  <link rel="stylesheet" href="assets/dark-theme.css">
</head>

<body>
  <nav class="navbar navbar-expand-lg mb-2">
    <div class="container-fluid">
      <a class="navbar-brand d-flex align-items-center gap-2" href="?action=list">
        <img src="assets/img/cran_logo.png" alt="CRAN Logo" 
             style="height:150px; width:auto; border-radius:4px;">
        CRAN BACKUP
      </a>
    </div>
  </nav>

  <main class="main-container">

    <!-- Flash -->
   <?php if (!empty($flash)): [$type,$msg] = $flash; ?>
  <div class="alert alert-<?= ($type === 'ok') ? 'success' : 'warning' ?> shadow-sm mb-3 fade show" id="autoAlert">
    <?= htmlspecialchars($msg) ?>
  </div>
  <script>
    setTimeout(() => {
      const a = document.getElementById('autoAlert');
      if (a) a.classList.add('fade');
      setTimeout(() => a?.remove(), 1500);
    }, 2000);
  </script>
<?php endif; ?>


    <!-- ARCHIVELOG / NOARCHIVELOG -->
    <?php if (!empty($archiveMsg)): ?>
      <div class="archive-status <?= htmlspecialchars($archiveColor ?? '') ?> mb-3">
        <?= htmlspecialchars($archiveMsg) ?>
      </div>
    <?php endif; ?>

<?php endif; ?>
