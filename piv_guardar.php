<?php
// ==========================================
// ARCHIVO: piv_guardar.php (Versión Debug)
// ==========================================

// 1. Iniciamos sesión y configuración
session_start();
require_once __DIR__ . '/Config/db_config.php';

// Función para escribir en el archivo de texto "log_errores.txt"
function log_me($msg) {
    $fecha = date('Y-m-d H:i:s');
    file_put_contents('log_errores.txt', "[$fecha] $msg" . PHP_EOL, FILE_APPEND);
}

header('Content-Type: application/json');

log_me("---------------------------------------------------");
log_me("INICIO DE SOLICITUD DE GUARDADO");

// 2. Validaciones de Sesión
if (!isset($_SESSION['id_usuario'])) {
    log_me("ERROR: Usuario no logueado.");
    echo json_encode(['success'=>false,'error'=>'No autenticado']); exit;
}
$id_usuario = (int)$_SESSION['id_usuario'];
log_me("Usuario ID detectado: " . $id_usuario);

// 3. Obtener el JSON
$raw = file_get_contents('php://input');
log_me("JSON Recibido (RAW): " . $raw); // ¡Aquí veremos qué envía el frontend!

$body = json_decode($raw, true);
if (!$body) { 
    log_me("ERROR: JSON inválido o vacío.");
    echo json_encode(['success'=>false,'error'=>'JSON inválido']); exit; 
}

// 4. Datos del PIV
$id_mapa = (int)($body['id_mapa'] ?? 0);
$fecha = $body['fecha'] ?? date('Y-m-d');
$destinatarios = $body['destinatarios'] ?? []; // Array de IDs

// Loguear qué destinatarios encontró PHP
log_me("Destinatarios encontrados (Array): " . print_r($destinatarios, true));

// ... (Resto de variables de texto) ...
$consideraciones = trim($body['consideraciones'] ?? '');
$medidas = trim($body['medidas'] ?? '');
$observaciones = trim($body['observaciones'] ?? '');
$firma_cargo = trim($body['firma_cargo'] ?? '');
$firma_nombre = trim($body['firma_nombre'] ?? '');
$firma_rut = trim($body['firma_rut'] ?? '');

// 5. Insertar PIV
try {
    // Buscar ficha (opcional)
    $id_ficha = null;
    $st = $pdo->prepare("SELECT id_ficha FROM public.piv_ficha WHERE id_mapa=:id");
    $st->execute([':id'=>$id_mapa]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) $id_ficha = (int)$row['id_ficha'];

    // INSERT PIV
    $stmt = $pdo->prepare("
      INSERT INTO public.piv
      (fecha, id_usuario, id_mapa, id_ficha, consideraciones, medidas, observaciones, firma_cargo, firma_nombre, firma_rut)
      VALUES
      (:fecha, :id_usuario, :id_mapa, :id_ficha, :consideraciones, :medidas, :observaciones, :firma_cargo, :firma_nombre, :firma_rut)
      RETURNING id_piv
    ");

    $stmt->execute([
      ':fecha' => $fecha,
      ':id_usuario' => $id_usuario,
      ':id_mapa' => $id_mapa,
      ':id_ficha' => $id_ficha,
      ':consideraciones' => $consideraciones ?: null,
      ':medidas' => $medidas ?: null,
      ':observaciones' => $observaciones ?: null,
      ':firma_cargo' => $firma_cargo,
      ':firma_nombre' => $firma_nombre,
      ':firma_rut' => $firma_rut ?: null
    ]);

    $id_piv = (int)$stmt->fetchColumn();
    log_me("PIV creado exitosamente. ID: " . $id_piv);

} catch (PDOException $e) {
    log_me("ERROR FATAL AL CREAR PIV: " . $e->getMessage());
    echo json_encode(['success'=>false, 'error' => $e->getMessage()]);
    exit;
}

// 6. Insertar Destinatarios (AQUÍ ESTÁ EL PROBLEMA)
if (!empty($destinatarios) && is_array($destinatarios)) {
    log_me("Iniciando bucle de destinatarios...");
    
    $sql_envio = "INSERT INTO public.piv_envio (id_piv, de_usuario, para_usuario, mensaje, estado) 
                  VALUES (:id_piv, :de_usuario, :para_usuario, 'PIV pendiente', 'enviado')";
    
    try {
        $stmt_envio = $pdo->prepare($sql_envio);

        foreach ($destinatarios as $id_destino) {
            $id_destino = intval($id_destino);
            log_me("Intentando insertar destino: De $id_usuario Para $id_destino en PIV $id_piv");

            if ($id_destino === $id_usuario) {
                log_me("Saltando: El destino es el mismo usuario.");
                continue;
            }

            $stmt_envio->execute([
                ':id_piv' => $id_piv,
                ':de_usuario' => $id_usuario,
                ':para_usuario' => $id_destino
            ]);
            log_me("--> INSERT EXITOSO en piv_envio.");
        }
    } catch (PDOException $ex) {
        log_me("ERROR SQL EN DESTINATARIOS: " . $ex->getMessage());
        // No detenemos el script, pero ya sabemos qué pasó
    }
} else {
    log_me("ADVERTENCIA: No se entró al bucle de destinatarios. ¿El array estaba vacío?");
}

log_me("FIN DEL PROCESO");
echo json_encode(['success'=>true, 'id_piv'=>$id_piv]);
?>