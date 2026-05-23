<?php
// planes.php  -  Devuelve el catálogo de planes para la pantalla de bienvenida.
// El frontend lo consume con fetch al cargar la página.

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

try {
    $rows = getDB()
        ->query("SELECT id, aseguradora, nombre FROM planes ORDER BY aseguradora, id")
        ->fetchAll();

    $planes = array_map(fn($p) => [
        'id'          => (int) $p['id'],
        'aseguradora' => $p['aseguradora'],
        'nombre'      => $p['nombre'],
        'etiqueta'    => $p['aseguradora'] . ' - ' . $p['nombre'],
    ], $rows);

    echo json_encode(['ok' => true, 'planes' => $planes], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
