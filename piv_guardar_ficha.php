<?php
session_start();
require_once __DIR__ . '/Config/db_config.php';

if (!isset($_SESSION['id_usuario'])) { header("Location: login.php"); exit; }
$tipo = $_SESSION['tipo_usuario'] ?? 'usuario';
if (!in_array($tipo, ['admin','usuario'], true)) { header("Location: login.php"); exit; }

$id_mapa = (int)($_POST['id_mapa'] ?? 0);
$id_zona = (int)($_POST['id_zona'] ?? 0);
if ($id_mapa <= 0) { die("Falta id_mapa"); }

$uso_pivotes = isset($_POST['uso_pivotes']) && (
  $_POST['uso_pivotes'] === '1' ||
  $_POST['uso_pivotes'] === 'true' ||
  $_POST['uso_pivotes'] === 'on'
);

$data = [
  'predio' => trim($_POST['predio'] ?? ''),
  'codigo_predio' => trim($_POST['codigo_predio'] ?? ''),
  'escenario' => trim($_POST['escenario'] ?? ''),
  'temporada' => trim($_POST['temporada'] ?? ''),

  'especie' => trim($_POST['especie'] ?? ''),
  'vma_m3_arb' => $_POST['vma_m3_arb'] !== '' ? (float)$_POST['vma_m3_arb'] : null,
  'superficie_ha' => $_POST['superficie_ha'] !== '' ? (float)$_POST['superficie_ha'] : null,
  'volumen_total_m3' => $_POST['volumen_total_m3'] !== '' ? (float)$_POST['volumen_total_m3'] : null,
  'arboles_hora' => $_POST['arboles_hora'] !== '' ? (float)$_POST['arboles_hora'] : null,
  'm3_hora' => ($_POST['m3_hora'] ?? '') !== '' ? (float)$_POST['m3_hora'] : null,

  'team_equipo' => trim($_POST['team_equipo'] ?? ''),
  'tecnologia' => trim($_POST['tecnologia'] ?? ''),
  'asistencia_tipo' => trim($_POST['asistencia_tipo'] ?? ''),
  'jefe_faena' => trim($_POST['jefe_faena'] ?? ''),
  'volteo_cerca_tendido_electrico' => (int)($_POST['volteo_cerca_tendido_electrico'] ?? 0) === 1,
  'volteo_cerca_camino_publico'    => (int)($_POST['volteo_cerca_camino_publico'] ?? 0) === 1,

  'uso_pivotes' => $uso_pivotes,
  'tiempo_estimado_dias' => $_POST['tiempo_estimado_dias'] !== '' ? (float)$_POST['tiempo_estimado_dias'] : null,
  'pendiente_max_pct' => $_POST['pendiente_max_pct'] !== '' ? (float)$_POST['pendiente_max_pct'] : null,
  'tipo_suelo' => trim($_POST['tipo_suelo'] ?? ''),
  'verif_permisos' => trim($_POST['verif_permisos'] ?? ''),
  'jornada' => trim($_POST['jornada'] ?? ''),
];

$sql = "
INSERT INTO public.piv_ficha
(id_mapa, id_zona, predio, codigo_predio, escenario, temporada,
 especie, vma_m3_arb, superficie_ha, volumen_total_m3, arboles_hora, m3_hora,
 team_equipo, tecnologia, asistencia_tipo, jefe_faena,
 volteo_cerca_tendido_electrico, volteo_cerca_camino_publico,
 uso_pivotes, tiempo_estimado_dias, pendiente_max_pct, tipo_suelo, verif_permisos, jornada, updated_at)
VALUES
 (:id_mapa, :id_zona, :predio, :codigo_predio, :escenario, :temporada,
 :especie, :vma_m3_arb, :superficie_ha, :volumen_total_m3, :arboles_hora, :m3_hora,
 :team_equipo, :tecnologia, :asistencia_tipo, :jefe_faena,
 :volteo_cerca_tendido_electrico, :volteo_cerca_camino_publico,
 :uso_pivotes, :tiempo_estimado_dias, :pendiente_max_pct, :tipo_suelo, :verif_permisos, :jornada, now())
ON CONFLICT (id_mapa) DO UPDATE SET
id_zona = EXCLUDED.id_zona,
predio = EXCLUDED.predio,
codigo_predio = EXCLUDED.codigo_predio,
escenario = EXCLUDED.escenario,
temporada = EXCLUDED.temporada,
especie = EXCLUDED.especie,
vma_m3_arb = EXCLUDED.vma_m3_arb,
superficie_ha = EXCLUDED.superficie_ha,
volumen_total_m3 = EXCLUDED.volumen_total_m3,
arboles_hora = EXCLUDED.arboles_hora,
m3_hora = EXCLUDED.m3_hora,
team_equipo = EXCLUDED.team_equipo,
tecnologia = EXCLUDED.tecnologia,
asistencia_tipo = EXCLUDED.asistencia_tipo,
jefe_faena = EXCLUDED.jefe_faena,
volteo_cerca_tendido_electrico = EXCLUDED.volteo_cerca_tendido_electrico,
volteo_cerca_camino_publico = EXCLUDED.volteo_cerca_camino_publico,
uso_pivotes = EXCLUDED.uso_pivotes,
tiempo_estimado_dias = EXCLUDED.tiempo_estimado_dias,
pendiente_max_pct = EXCLUDED.pendiente_max_pct,
tipo_suelo = EXCLUDED.tipo_suelo,
verif_permisos = EXCLUDED.verif_permisos,
jornada = EXCLUDED.jornada,
updated_at = now();
";

$stmt = $pdo->prepare($sql);

$stmt->bindValue(':id_mapa', $id_mapa, PDO::PARAM_INT);
$stmt->bindValue(':id_zona', $id_zona ?: null, $id_zona ? PDO::PARAM_INT : PDO::PARAM_NULL);

foreach ($data as $k => $v) {
  if (in_array($k, ['uso_pivotes', 'volteo_cerca_tendido_electrico', 'volteo_cerca_camino_publico'], true)) {
    $stmt->bindValue(':'.$k, $v, PDO::PARAM_BOOL);
  } else {
    $stmt->bindValue(':'.$k, $v);
  }
}
$stmt->execute();

// --- INICIO: Guardar imagen del plano ---
$dir_uploads = __DIR__ . '/uploads/';
if (!is_dir($dir_uploads)) {
    mkdir($dir_uploads, 0777, true);
}

if (isset($_FILES['imagen_plano']) && $_FILES['imagen_plano']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['imagen_plano']['name'], PATHINFO_EXTENSION));
    
    // Solo permitimos jpg, jpeg o png
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        // Borramos versiones anteriores si existen para no acumular basura
        @unlink($dir_uploads . 'plano_' . $id_mapa . '.jpg');
        @unlink($dir_uploads . 'plano_' . $id_mapa . '.jpeg');
        @unlink($dir_uploads . 'plano_' . $id_mapa . '.png');
        
        // Guardamos la nueva imagen con el ID del mapa
        $ruta_destino = $dir_uploads . 'plano_' . $id_mapa . '.' . $ext;
        move_uploaded_file($_FILES['imagen_plano']['tmp_name'], $ruta_destino);
    }
}

// --- INICIO: Guardar SEGUNDA imagen del plano ---
if (isset($_FILES['imagen_plano_2']) && $_FILES['imagen_plano_2']['error'] === UPLOAD_ERR_OK) {
    $ext2 = strtolower(pathinfo($_FILES['imagen_plano_2']['name'], PATHINFO_EXTENSION));
    
    if (in_array($ext2, ['jpg', 'jpeg', 'png'], true)) {
        @unlink($dir_uploads . 'plano_2_' . $id_mapa . '.jpg');
        @unlink($dir_uploads . 'plano_2_' . $id_mapa . '.jpeg');
        @unlink($dir_uploads . 'plano_2_' . $id_mapa . '.png');
        
        $ruta_destino_2 = $dir_uploads . 'plano_2_' . $id_mapa . '.' . $ext2;
        move_uploaded_file($_FILES['imagen_plano_2']['tmp_name'], $ruta_destino_2);
    }
}
// --- FIN: Guardar SEGUNDA imagen ---

// --- INICIO: Guardar textos de los planos ---
$textos_mapas = [
    'comentario_1' => $_POST['comentario_plano_1'] ?? '',
    'comentario_2' => $_POST['comentario_plano_2'] ?? ''
];
// Se guarda como JSON para no tener que modificar tablas en la Base de Datos
file_put_contents($dir_uploads . 'textos_planos_' . $id_mapa . '.json', json_encode($textos_mapas));
// --- FIN: Guardar textos ---
// --- FIN: Guardar imagen ---

header("Location: piv_formulario.php?id_mapa=".$id_mapa);
exit;
