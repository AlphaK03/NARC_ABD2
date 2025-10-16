<?php if (!isset($__layout_started)): $__layout_started = true; ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Backup App</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap CDN (sin archivos locales extra) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .badge-pill { border-radius: 10rem; }
    .table-sticky th { position: sticky; top: 0; background: #f8f9fa; z-index: 1; }
    .small-note { font-size: 0.9rem; color:#6c757d; }
  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
  <div class="container">
    <a class="navbar-brand" href="?action=list">Backup Monitor</a>
  </div>
</nav>
<div class="container mb-5">
  <?php if (!empty($flash)): [$type,$msg] = $flash; ?>
    <div class="alert alert-<?= $type==='ok'?'success':'danger' ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

<?php endif; // 🔹 Cierre del if de apertura (necesario para evitar el Parse error) ?>
