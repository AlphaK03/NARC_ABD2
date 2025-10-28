<?php
if (!isset($_GET['path'])) {
    http_response_code(400);
    exit('Falta parámetro path');
}

$path = urldecode($_GET['path']);
$path = str_replace('/', '\\', $path);

$baseDir = 'C:\\oracle19c\\rman_app\\logs\\';
$realBase = realpath($baseDir);
if ($realBase === false) {
    http_response_code(500);
    exit('No se encontró el directorio base.');
}

if (strpos($path, $realBase) !== 0) {
    http_response_code(403);
    exit('Ruta fuera del directorio permitido.');
}

if (!file_exists($path)) {
    // buscar un archivo parecido: mismo prefijo, sin coma, etc.
    $dir = dirname($path);
    $file = pathinfo($path, PATHINFO_FILENAME);
    $file = preg_replace('/[,\.]\d*$/', '', $file);
    $pattern = $dir . '\\' . $file . '*.txt';
    $matches = glob($pattern);
    if ($matches && count($matches) > 0) {
        $path = $matches[0];
    } else {
        http_response_code(404);
        exit('Archivo no encontrado: ' . htmlspecialchars($path));
    }
}

header('Content-Type: text/plain; charset=UTF-8');
readfile($path);
?>
