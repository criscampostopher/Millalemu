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
$grupos_payload = $body['grupos'] ?? []; // Mapa grupo => miembros
$id_piv_edit = (int)($body['id_piv'] ?? 0); // Si viene, es edición

// Loguear qué destinatarios encontró PHP
log_me("Destinatarios encontrados (Array): " . print_r($destinatarios, true));
log_me("Grupos recibidos (Array): " . print_r($grupos_payload, true));
if ($id_piv_edit > 0) log_me("MODO EDICIÓN: Actualizando PIV #$id_piv_edit");

// ... (Resto de variables de texto) ...
$consideraciones = trim($body['consideraciones'] ?? '');
$medidas = trim($body['medidas'] ?? '');
$observaciones = trim($body['observaciones'] ?? '');
$firma_cargo = trim($body['firma_cargo'] ?? '');
$firma_nombre = trim($body['firma_nombre'] ?? '');
$firma_rut = trim($body['firma_rut'] ?? '');

// 5. Insertar o Actualizar PIV
try {
    // Buscar ficha (opcional)
    $id_ficha = null;
    $st = $pdo->prepare("SELECT id_ficha FROM public.piv_ficha WHERE id_mapa=:id");
    $st->execute([':id'=>$id_mapa]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) $id_ficha = (int)$row['id_ficha'];

    if ($id_piv_edit > 0) {
        // --- MODO EDICIÓN: UPDATE ---
        $stmt = $pdo->prepare("
          UPDATE public.piv SET
            fecha = :fecha,
            id_ficha = :id_ficha,
            consideraciones = :consideraciones,
            medidas = :medidas,
            firma_cargo = :firma_cargo,
            firma_nombre = :firma_nombre,
            firma_rut = :firma_rut
          WHERE id_piv = :id_piv
        ");
        $stmt->execute([
          ':fecha' => $fecha,
          ':id_ficha' => $id_ficha,
          ':consideraciones' => $consideraciones ?: null,
          ':medidas' => $medidas ?: null,
          ':firma_cargo' => $firma_cargo,
          ':firma_nombre' => $firma_nombre,
          ':firma_rut' => $firma_rut ?: null,
          ':id_piv' => $id_piv_edit
        ]);
        $id_piv = $id_piv_edit;
        log_me("PIV actualizado exitosamente. ID: " . $id_piv);
    } else {
        // --- MODO CREACIÓN: INSERT ---
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
    }

} catch (PDOException $e) {
    log_me("ERROR FATAL AL CREAR/ACTUALIZAR PIV: " . $e->getMessage());
    echo json_encode(['success'=>false, 'error' => $e->getMessage()]);
    exit;
}

// 6. Insertar Destinatarios (Escudo Protector de Firmas)
$avisos = []; // Mensajes informativos para mostrar al usuario

if (!empty($destinatarios) && is_array($destinatarios)) {
    log_me("Iniciando bucle de destinatarios...");

    foreach ($destinatarios as $id_destino) {
        $id_destino = intval($id_destino);

        // Determinar a qué grupo pertenece este destinatario
        $grupo_num = 1;
        if (is_array($grupos_payload)) {
            foreach ($grupos_payload as $num => $miembros) {
                if (
                    is_array($miembros) &&
                    (in_array((string)$id_destino, $miembros, true) || in_array($id_destino, $miembros, true))
                ) {
                    $grupo_num = (int)$num;
                    break;
                }
            }
        }

        // Obtener nombre del destinatario
        $stmt_nombre = $pdo->prepare("SELECT nombre_usuario FROM public.usuario WHERE id_usuario = ?");
        $stmt_nombre->execute([$id_destino]);
        $nombre_destino = $stmt_nombre->fetchColumn() ?: "Usuario #$id_destino";

        // 1. VERIFICAR EL ESTADO ACTUAL DEL TRABAJADOR
        $stmt_check = $pdo->prepare("SELECT estado FROM public.piv_envio WHERE id_piv = ? AND para_usuario = ?");
        $stmt_check->execute([$id_piv, $id_destino]);
        $registro_previo = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($registro_previo) {
            $pdo->prepare("UPDATE public.piv_envio SET grupo_trabajo = ? WHERE id_piv = ? AND para_usuario = ?")
                ->execute([$grupo_num, $id_piv, $id_destino]);
            $estado_actual = $registro_previo['estado'];

            if ($estado_actual === 'aprobado') {
                $avisos[] = "✅ <b>$nombre_destino</b> ya firmó este PIV — su firma se mantiene.";
                log_me("Escudo Activo: Destinatario $id_destino ya firmó el PIV $id_piv. Se omite.");
            } elseif ($estado_actual === 'enviado' || $estado_actual === 'visto') {
                $avisos[] = "📬 <b>$nombre_destino</b> ya tiene el PIV pendiente en su bandeja.";
                log_me("Escudo Activo: Destinatario $id_destino ya tiene pendiente el PIV $id_piv.");
            } elseif ($estado_actual === 'rechazado') {
                $pdo->prepare("UPDATE public.piv_envio SET estado = 'enviado', observacion_firma = NULL, fecha_respuesta = NULL WHERE id_piv = ? AND para_usuario = ?")
                    ->execute([$id_piv, $id_destino]);
                $avisos[] = "🔄 <b>$nombre_destino</b> había rechazado — se le reenvió para que revise la nueva versión.";
                log_me("Re-envío: Destinatario $id_destino había rechazado. Se resetea a 'enviado'.");
            }
        } else {
            $pdo->prepare("INSERT INTO public.piv_envio (id_piv, de_usuario, para_usuario, mensaje, estado, grupo_trabajo) VALUES (?, ?, ?, 'PIV pendiente', 'enviado', ?)")
                ->execute([$id_piv, $id_usuario, $id_destino, $grupo_num]);
            $avisos[] = "📤 <b>$nombre_destino</b> — PIV enviado (Grupo $grupo_num).";
            log_me("--> INSERT EXITOSO: Destinatario $id_destino para PIV $id_piv (Grupo $grupo_num).");
        }
    }
} else {
    log_me("ADVERTENCIA: No se enviaron destinatarios en el JSON.");
}

log_me("FIN DEL PROCESO");
echo json_encode(['success' => true, 'id_piv' => $id_piv, 'avisos' => $avisos]);
?>
