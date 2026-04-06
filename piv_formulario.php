<?php
session_start();
require_once __DIR__ . '/Config/db_config.php';

if (!isset($_SESSION['id_usuario'])) { header("Location: login.php"); exit; }

// --- NUEVO: Procesador AJAX para guardar múltiples palabras de golpe ---
if (isset($_GET['ajax_add_options'])) {
  header('Content-Type: application/json; charset=utf-8');

  $input = json_decode(file_get_contents('php://input'), true);
  $campo = trim((string)($input['campo'] ?? ''));
  $nuevas = trim((string)($input['nuevas'] ?? ''));

  $permitidos = ['team_equipo', 'tecnologia', 'asistencia_tipo', 'jefe_faena', 'tipo_suelo'];
  if (!in_array($campo, $permitidos, true) || $nuevas === '') {
    echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
    exit;
  }

  $archivo = __DIR__ . '/opciones_manuales.json';
  $data = [];
  if (file_exists($archivo)) {
    $parsed = json_decode((string)file_get_contents($archivo), true);
    if (is_array($parsed)) $data = $parsed;
  }
  if (!isset($data[$campo]) || !is_array($data[$campo])) $data[$campo] = [];

  $toLower = static function(string $v): string {
    $v = trim($v);
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
  };

  $added = 0;
  $existLower = array_map(static fn($v) => $toLower((string)$v), $data[$campo]);
  $partes = explode(',', $nuevas);
  foreach ($partes as $p) {
    $p = trim($p);
    if ($p === '') continue;
    $pLow = $toLower($p);
    if (!in_array($pLow, $existLower, true)) {
      $data[$campo][] = $p;
      $existLower[] = $pLow;
      $added++;
    }
  }

  file_put_contents(
    $archivo,
    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
  );

  echo json_encode(['success' => true, 'added' => $added]);
  exit;
}

// --- AJAX: Agregar condición al catálogo ---
if (isset($_GET['ajax_add_condicion'])) {
  header('Content-Type: application/json; charset=utf-8');
  $input = json_decode(file_get_contents('php://input'), true);
  $titulo = trim((string)($input['titulo'] ?? ''));
  $medida = trim((string)($input['medida'] ?? ''));

  if ($titulo === '' || $medida === '') {
    echo json_encode(['success' => false, 'error' => 'Título y medida son obligatorios']);
    exit;
  }

  $archivo = __DIR__ . '/catalogo_condiciones.json';
  $data = [];
  if (file_exists($archivo)) {
    $parsed = json_decode((string)file_get_contents($archivo), true);
    if (is_array($parsed)) $data = $parsed;
  }

  $tituloLower = function_exists('mb_strtolower') ? mb_strtolower($titulo, 'UTF-8') : strtolower($titulo);
  foreach ($data as $item) {
    $existLower = function_exists('mb_strtolower') ? mb_strtolower((string)($item['titulo'] ?? ''), 'UTF-8') : strtolower((string)($item['titulo'] ?? ''));
    if ($existLower === $tituloLower) {
      echo json_encode(['success' => false, 'error' => 'Ya existe una condición con ese título']);
      exit;
    }
  }

  $data[] = ['titulo' => $titulo, 'medida' => $medida];
  file_put_contents($archivo, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
  echo json_encode(['success' => true, 'total' => count($data)]);
  exit;
}

// --- AJAX: Eliminar condición del catálogo ---
if (isset($_GET['ajax_remove_condicion'])) {
  header('Content-Type: application/json; charset=utf-8');
  $input = json_decode(file_get_contents('php://input'), true);
  $titulo = trim((string)($input['titulo'] ?? ''));

  if ($titulo === '') {
    echo json_encode(['success' => false, 'error' => 'Falta título']);
    exit;
  }

  $archivo = __DIR__ . '/catalogo_condiciones.json';
  if (!file_exists($archivo)) {
    echo json_encode(['success' => true, 'removed' => 0]);
    exit;
  }

  $data = json_decode((string)file_get_contents($archivo), true);
  if (!is_array($data)) $data = [];

  $tituloLower = function_exists('mb_strtolower') ? mb_strtolower($titulo, 'UTF-8') : strtolower($titulo);
  $before = count($data);
  $data = array_values(array_filter($data, function($item) use ($tituloLower) {
    $existLower = function_exists('mb_strtolower') ? mb_strtolower((string)($item['titulo'] ?? ''), 'UTF-8') : strtolower((string)($item['titulo'] ?? ''));
    return $existLower !== $tituloLower;
  }));
  $removed = $before - count($data);

  file_put_contents($archivo, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
  echo json_encode(['success' => true, 'removed' => $removed]);
  exit;
}

// --- NUEVO: Procesador AJAX para eliminar múltiples palabras manuales ---
if (isset($_GET['ajax_remove_options'])) {
  header('Content-Type: application/json; charset=utf-8');

  $input = json_decode(file_get_contents('php://input'), true);
  $campo = trim((string)($input['campo'] ?? ''));
  $quitar = trim((string)($input['quitar'] ?? ''));

  $permitidos = ['team_equipo', 'tecnologia', 'asistencia_tipo', 'jefe_faena', 'tipo_suelo'];
  if (!in_array($campo, $permitidos, true) || $quitar === '') {
    echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
    exit;
  }

  $archivo = __DIR__ . '/opciones_manuales.json';
  if (!file_exists($archivo)) {
    echo json_encode(['success' => true, 'removed' => 0]);
    exit;
  }

  $data = json_decode((string)file_get_contents($archivo), true);
  if (!is_array($data)) $data = [];
  if (!isset($data[$campo]) || !is_array($data[$campo])) $data[$campo] = [];

  $toLower = static function(string $v): string {
    $v = trim($v);
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
  };

  $targets = [];
  foreach (explode(',', $quitar) as $p) {
    $p = trim($p);
    if ($p !== '') $targets[] = $toLower($p);
  }
  $targets = array_values(array_unique($targets));

  $before = count($data[$campo]);
  $data[$campo] = array_values(array_filter($data[$campo], function($item) use ($toLower, $targets) {
    return !in_array($toLower((string)$item), $targets, true);
  }));
  $removed = $before - count($data[$campo]);

  file_put_contents(
    $archivo,
    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
  );

  echo json_encode(['success' => true, 'removed' => $removed]);
  exit;
}

// En tu sistema: roles autorizados pueden llenar PIV
$tipo = $_SESSION['tipo_usuario'] ?? 'usuario';
if (!in_array($tipo, ['admin', 'usuario', 'jefe_faena', 'jefe_operaciones', 'ingeniero_forestal'], true)) { header("Location: login.php"); exit; }

$id_usuario  = (int)$_SESSION['id_usuario'];
$nombre_user = $_SESSION['nombre_usuario'] ?? '';
$msg = trim($_GET['msg'] ?? '');

$id_mapa = isset($_GET['id_mapa']) ? (int)$_GET['id_mapa'] : 0;
$captura_src = isset($_GET['captura_src']) ? (int)$_GET['captura_src'] : 0;

// --- MODO EDICIÓN: Cargar PIV existente ---
$edit_piv = null;
$edit_piv_id = isset($_GET['edit_piv']) ? (int)$_GET['edit_piv'] : 0;
$edit_destinatarios_ids = [];
if ($edit_piv_id > 0) {
  $st_edit = $pdo->prepare("SELECT * FROM public.piv WHERE id_piv = :id");
  $st_edit->execute([':id' => $edit_piv_id]);
  $edit_piv = $st_edit->fetch(PDO::FETCH_ASSOC);
  if ($edit_piv) {
    // Usar el id_mapa de la PIV existente
    $id_mapa = (int)$edit_piv['id_mapa'];
    // Cargar destinatarios actuales
    $st_dest = $pdo->prepare("SELECT para_usuario FROM public.piv_envio WHERE id_piv = :id");
    $st_dest->execute([':id' => $edit_piv_id]);
    $edit_destinatarios_ids = $st_dest->fetchAll(PDO::FETCH_COLUMN);
  }
}

// 1) Cargar mapa + zona si viene id_mapa
$mapa = null;
if ($id_mapa > 0) {
  $stmt = $pdo->prepare("
    SELECT m.id_mapa, m.nombre_mapa, m.id_zona, z.nombre_zona
    FROM public.mapa m
    JOIN public.zona z ON z.id_zona = m.id_zona
    WHERE m.id_mapa = :id_mapa
  ");
  $stmt->execute([':id_mapa' => $id_mapa]);
  $mapa = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Si venimos desde el visor con capturas, al seleccionar mapa Acta las copiamos al mapa elegido.
if ($mapa && $captura_src > 0) {
  $dstMapa = (int)$mapa['id_mapa'];
  $srcMapa = $captura_src;
  if ($srcMapa !== $dstMapa) {
    $uploadsDir = __DIR__ . '/uploads/';
    $copySlot = static function(string $srcPrefix, string $dstPrefix) use ($uploadsDir, $srcMapa, $dstMapa): bool {
      $exts = ['jpg', 'jpeg', 'png'];
      $src = '';
      foreach ($exts as $e) {
        $candidate = $uploadsDir . $srcPrefix . $srcMapa . '.' . $e;
        if (is_file($candidate)) { $src = $candidate; break; }
      }
      if ($src === '') return false;
      $info = @getimagesize($src);
      if (!$info || empty($info['mime'])) return false;
      $mime = strtolower((string)$info['mime']);
      $ext = (strpos($mime, 'png') !== false) ? 'png' : 'jpg';
      @unlink($uploadsDir . $dstPrefix . $dstMapa . '.jpg');
      @unlink($uploadsDir . $dstPrefix . $dstMapa . '.jpeg');
      @unlink($uploadsDir . $dstPrefix . $dstMapa . '.png');
      return @copy($src, $uploadsDir . $dstPrefix . $dstMapa . '.' . $ext);
    };

    $ok1 = $copySlot('plano_', 'plano_');
    $ok2 = $copySlot('plano_2_', 'plano_2_');
    $srcJson = $uploadsDir . 'textos_planos_' . $srcMapa . '.json';
    $dstJson = $uploadsDir . 'textos_planos_' . $dstMapa . '.json';
    if (is_file($srcJson)) { @copy($srcJson, $dstJson); }
    if ($ok1 || $ok2) {
      $msg = 'Capturas del visor asociadas al mapa Acta seleccionado.';
    }
  }
}

// 2) Si no viene id_mapa, permitimos elegir
$zonas = $pdo->query("SELECT id_zona, nombre_zona FROM public.zona WHERE nombre_zona != 'SISTEMA_OCULTO' ORDER BY nombre_zona ASC")->fetchAll(PDO::FETCH_ASSOC);

$id_zona_sel = isset($_GET['id_zona']) ? (int)$_GET['id_zona'] : 0;
$mapas_zona = [];
if ($id_zona_sel > 0) {
  // Filtrar solo mapas clasificados como Acta
  $st = $pdo->prepare("SELECT id_mapa, nombre_mapa, categoria FROM public.mapa WHERE id_zona=:z AND categoria ILIKE '%Acta%' ORDER BY nombre_mapa ASC");
  $st->execute([':z'=>$id_zona_sel]);
  $mapas_zona = $st->fetchAll(PDO::FETCH_ASSOC);
}

// 3) Cargar ficha si existe para el id_mapa
$ficha = null;
if ($mapa) {
  $stf = $pdo->prepare("SELECT * FROM public.piv_ficha WHERE id_mapa=:id");
  $stf->execute([':id'=>$mapa['id_mapa']]);
  $ficha = $stf->fetch(PDO::FETCH_ASSOC);
}

// --- INICIO: Cargar opciones (solo Palabras Manuales) ---
$opciones_combo = ['team_equipo'=>[], 'tecnologia'=>[], 'asistencia_tipo'=>[], 'jefe_faena'=>[], 'tipo_suelo'=>[]];
$opciones_manuales = ['team_equipo'=>[], 'tecnologia'=>[], 'asistencia_tipo'=>[], 'jefe_faena'=>[], 'tipo_suelo'=>[]];

$archivo_manual = __DIR__ . '/opciones_manuales.json';
if (file_exists($archivo_manual)) {
  $manuales = json_decode((string)file_get_contents($archivo_manual), true);
  if (is_array($manuales)) {
    foreach ($manuales as $campo => $lista) {
      if (isset($opciones_combo[$campo]) && is_array($lista)) {
        foreach ($lista as $item) {
          $item = trim((string)$item);
          if ($item !== '') {
            if (!in_array($item, $opciones_combo[$campo], true)) $opciones_combo[$campo][] = $item;
            if (!in_array($item, $opciones_manuales[$campo], true)) $opciones_manuales[$campo][] = $item;
          }
        }
      }
    }
  }
}

foreach (array_keys($opciones_combo) as $campo) {
  natcasesort($opciones_combo[$campo]);
  $opciones_combo[$campo] = array_values($opciones_combo[$campo]);
}
// --- FIN Cargar Opciones ---

// --- Cargar catálogo de condiciones peligrosas ---
$catalogo_condiciones = [];
$archivo_catalogo = __DIR__ . '/catalogo_condiciones.json';
if (file_exists($archivo_catalogo)) {
  $parsed_cat = json_decode((string)file_get_contents($archivo_catalogo), true);
  if (is_array($parsed_cat)) $catalogo_condiciones = $parsed_cat;
}

// Helper
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PIV - Formulario</title>
  <link rel="stylesheet" href="style-admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    .wrap{max-width:980px;margin:0 auto;}
    /* Card del PIV (no choca con style-admin.css) */
    .piv-card{
      background:rgba(255,255,255,0.96);
      border-radius:14px;
      padding:18px;
      color:#1b3a2a;
      box-shadow:0 8px 20px rgba(0,0,0,.15);
      text-align:left;
    }
    .steps{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0;}
    .step{flex:1;min-width:160px;padding:10px 12px;border-radius:12px;border:1px solid #e6e6e6;background:#f7f7f7;font-weight:700;display:flex;align-items:center;gap:8px}
    .step.active{background:#e8f5e9;border-color:#a5d6a7}
    /* Grid más estable y bonito */
    .grid{
      display:grid;
      grid-template-columns: repeat(2, minmax(260px, 1fr));
      gap:16px 18px;
    }

    /* Cada campo */
    .row{margin:0;}
    label{
      font-weight:800;
      display:block;
      margin:0 0 6px;
      color:#1b3a2a;
      text-align:left;
      font-size:14px;
    }

    /* Inputs consistentes */
    input, select, textarea{
      width:100%;
      height:44px;
      padding:10px 12px;
      border:1px solid #d9d9d9;
      border-radius:12px;
      font-family:inherit;
      font-size:14px;
      box-sizing:border-box;
      background:#fff;
    }

    textarea{
      height:auto;
      min-height:110px;
      resize:vertical;
    }

    /* Placeholders más suaves */
    input::placeholder, textarea::placeholder{
      color:#9aa4b2;
    }

    /* Focus bonito */
    input:focus, select:focus, textarea:focus{
      outline:none;
      border-color:#2e7d32;
      box-shadow:0 0 0 3px rgba(46,125,50,0.15);
    }
    .actions{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:12px;flex-wrap:wrap}
    .actions.actions-step1{justify-content:flex-end}
    .btn{border:0;cursor:pointer;padding:12px 14px;border-radius:12px;font-weight:800}
    .btn-primary{background:#2e7d32;color:#fff}
    .btn-secondary{background:#e0e0e0;color:#1b3a2a}
    
    /* Estilos nuevos para botón Vista Previa */
    .btn-warn{background:#f39c12;color:#fff; box-shadow: 0 4px 6px rgba(243,156,18,0.3);}
    .btn-warn:hover{background:#e67e22;}

    /* Estilo visual para botón bloqueado/deshabilitado */
    button:disabled, .btn:disabled {
        background-color: #95a5a6 !important;
        color: #fff !important;
        cursor: not-allowed !important;
    }
    .apply-detected{
      margin-top:10px;
      padding:12px;
      border:1px solid #d6e4dc;
      border-radius:14px;
      background:linear-gradient(180deg, #f7fbf8 0%, #f1f8f4 100%);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
    }
    .apply-detected-copy{
      display:flex;
      flex-direction:column;
      gap:3px;
      min-width:260px;
      flex:1;
    }
    .apply-detected-title{
      font-size:14px;
      font-weight:800;
      color:#163c2a;
      line-height:1.2;
    }
    .apply-detected-note{
      font-size:12px;
      color:#4f6b5d;
      font-weight:600;
      line-height:1.25;
    }
    .btn-apply-map{
      background:#2e7d32;
      color:#fff;
      display:inline-flex;
      align-items:center;
      gap:8px;
      border-radius:10px;
      padding:11px 14px;
      text-decoration:none;
      box-shadow:0 2px 6px rgba(46,125,50,0.20);
      transition:all .15s ease;
    }
    .btn-apply-map:hover{
      background:#27682a;
      transform:translateY(-1px);
      box-shadow:0 4px 10px rgba(46,125,50,0.25);
    }
    .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#f1f5f9;border:1px solid #e2e8f0;font-weight:700}
    .muted{color:#64748b;font-weight:600}
    .divider{height:1px;background:#e8e8e8;margin:16px 0}
    .toggle{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e6e6e6;border-radius:12px;background:#fafafa}
    .toggle input{width:auto}
    
    /* Estilos nueva Galeria Visual */
    .preview-gallery { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; margin: 15px 0; }
    .preview-gallery h4 { margin: 0 0 10px 0; color: #334155; font-size: 1rem; }
    .img-box { background: #fff; border: 1px solid #cbd5e1; padding: 5px; border-radius: 8px; text-align: center; width: 180px; }
    .img-box img { max-width: 100%; height: auto; border-radius: 4px; border: 1px solid #e2e8f0; cursor: pointer; transition: 0.2s; }
    .img-box img:hover { transform: scale(1.05); }
    .img-box span { display: block; font-size: 0.8rem; font-weight: bold; color: #475569; margin-top: 5px; }

    /* Responsive */
    @media (max-width:900px){
      .grid{ grid-template-columns: 1fr; }
      .step{min-width:140px}
      .apply-detected{align-items:stretch}
      .btn-apply-map{width:100%;justify-content:center}
    }
    .hidden{display:none}
    .field {
      position: relative;
    }
    .btn-clear {
      position: absolute;
      right: 10px;
      top: 33px;
      width: 34px;
      height: 34px;
      border: 0;
      border-radius: 10px;
      cursor: pointer;
      background: #f1f5f9;
      color: #334155;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 1px 2px rgba(0,0,0,.10);
    }
    .btn-clear:hover {
      background: #e2e8f0;
    }
    .field input, .field select, .field textarea {
      padding-right: 46px;
    }
    .dest-section { margin-top: 8px; }
    .dest-title { margin: 0 0 10px; font-size: 1.05rem; color:#163c2a; }
    .dest-toolbar{
      margin-bottom: 10px;
      position: relative;
    }
    .dest-search{
      width: 100%;
      height: 40px;
      border: 1px solid #d9e3dd;
      border-radius: 10px;
      background: #ffffff;
      padding: 8px 12px 8px 36px;
      font-size: 0.95rem;
      color: #1f3f31;
    }
    .dest-search:focus{
      outline: none;
      border-color:#2e7d32;
      box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
    }
    .dest-search-icon{
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #5f7b6d;
      pointer-events: none;
      font-size: 0.9rem;
    }
    .dest-list {
      max-height: 220px;
      overflow-y: auto;
      border: 1px solid #d9e3dd;
      border-radius: 12px;
      background: #f8fbf9;
      padding: 10px;
      display: grid;
      gap: 8px;
    }
    .dest-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 10px;
      border: 1px solid #e2ece6;
      border-radius: 10px;
      background: #ffffff;
    }
    .dest-item:hover { border-color:#b8d2c4; background:#f6fbf8; }
    .dest-item input[type="checkbox"]{
      width: 18px;
      height: 18px;
      margin: 0;
      flex: 0 0 auto;
      accent-color: #2e7d32;
      box-shadow: none;
    }
    .dest-label {
      margin: 0;
      font-weight: 700;
      color: #1f3f31;
      display: flex;
      align-items: baseline;
      gap: 6px;
      cursor: pointer;
      font-size: 0.98rem;
    }
    .dest-role {
      font-size: 0.82rem;
      font-weight: 800;
      color: #4f6b5d;
      background: #eaf3ee;
      border: 1px solid #d4e5db;
      border-radius: 999px;
      padding: 2px 8px;
      text-transform: lowercase;
    }
    .dest-help {
      display:block;
      margin-top:8px;
      color:#4f6b5d;
    }
    .dest-empty{
      display:none;
      text-align:center;
      color:#5f7b6d;
      font-weight:700;
      padding:10px 0 2px;
    }
    /* === Grupos de Trabajo === */
    .grupos-section { margin-top: 8px; }
    .grupos-title { margin: 0 0 10px; font-size: 1.05rem; color:#163c2a; }
    .grupos-container { display: flex; flex-direction: column; gap: 14px; }
    .grupo-card {
      border: 2px solid #d9e3dd;
      border-radius: 12px;
      background: #f8fbf9;
      overflow: hidden;
      transition: border-color 0.2s;
    }
    .grupo-card:focus-within { border-color: #2e7d32; }
    .grupo-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 14px;
      background: #eaf3ee;
      border-bottom: 1px solid #d9e3dd;
    }
    .grupo-header-left {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 800;
      color: #163c2a;
      font-size: 0.95rem;
    }
    .grupo-header-left i { color: #2e7d32; }
    .grupo-badge {
      background: #2e7d32;
      color: #fff;
      font-size: 0.75rem;
      font-weight: 800;
      padding: 2px 8px;
      border-radius: 999px;
    }
    .btn-remove-grupo {
      background: #fee2e2;
      color: #b91c1c;
      border: none;
      width: 30px;
      height: 30px;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 900;
      font-size: 1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.2s;
    }
    .btn-remove-grupo:hover { background: #fecaca; }
    .grupo-body { padding: 12px 14px; }
    .grupo-search {
      width: 100%;
      height: 36px;
      border: 1px solid #d9e3dd;
      border-radius: 8px;
      background: #fff;
      padding: 6px 10px 6px 32px;
      font-size: 0.9rem;
      color: #1f3f31;
      margin-bottom: 8px;
      box-sizing: border-box;
    }
    .grupo-search:focus { outline: none; border-color: #2e7d32; }
    .grupo-search-wrap { position: relative; }
    .grupo-search-wrap i {
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      color: #5f7b6d;
      font-size: 0.85rem;
      pointer-events: none;
    }
    .grupo-members {
      max-height: 180px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .grupo-member {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 7px 10px;
      border: 1px solid #e2ece6;
      border-radius: 8px;
      background: #fff;
      transition: border-color 0.15s;
    }
    .grupo-member:hover { border-color: #b8d2c4; background: #f6fbf8; }
    .grupo-member input[type="checkbox"] {
      width: 17px;
      height: 17px;
      margin: 0;
      accent-color: #2e7d32;
      flex-shrink: 0;
    }
    .grupo-member label {
      margin: 0;
      font-weight: 700;
      color: #1f3f31;
      display: flex;
      align-items: baseline;
      gap: 6px;
      cursor: pointer;
      font-size: 0.92rem;
    }
    .grupo-member .dest-role {
      font-size: 0.78rem;
    }
    .grupo-empty-search {
      text-align: center;
      color: #5f7b6d;
      font-weight: 600;
      padding: 8px 0;
      font-size: 0.88rem;
      display: none;
    }
    .btn-add-grupo {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 12px;
      border: 2px dashed #b8d2c4;
      border-radius: 12px;
      background: transparent;
      color: #2e7d32;
      font-weight: 800;
      font-size: 0.95rem;
      cursor: pointer;
      transition: all 0.2s;
    }
    .btn-add-grupo:hover {
      background: #e8f5e9;
      border-color: #2e7d32;
    }
    .grupos-help {
      display: block;
      margin-top: 8px;
      color: #4f6b5d;
      font-size: 0.85rem;
    }
    .grupo-selected-summary {
      margin-top: 6px;
      font-size: 0.82rem;
      color: #2e7d32;
      font-weight: 700;
    }
    .custom-combo { position: relative; }
    .custom-combo input { padding-right: 82px; }
    .custom-combo .btn-clear {
      top: 50%;
      transform: translateY(-50%);
      right: 40px;
      width: 28px;
      height: 28px;
      border-radius: 8px;
      box-shadow: none;
    }
    .combo-toggle {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      width: 28px;
      height: 28px;
      border: 0;
      border-radius: 8px;
      background: #edf2f7;
      color: #334155;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .combo-toggle:hover { background: #e2e8f0; }
    .combo-menu {
      display: none;
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      right: 0;
      z-index: 50;
      background: #fff;
      border: 1px solid #d9e3dd;
      border-radius: 10px;
      box-shadow: 0 8px 18px rgba(0,0,0,0.12);
      padding: 6px;
      max-height: 160px;
      overflow-y: auto;
    }
    .custom-combo.open .combo-menu { display: block; }
    .combo-option {
      width: 100%;
      text-align: left;
      border: 0;
      background: transparent;
      padding: 8px 10px;
      border-radius: 8px;
      cursor: pointer;
      color: #1f3f31;
      font-size: 0.95rem;
    }
    .combo-option:hover { background: #f1f5f9; }
    .combo-option-row {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .combo-option-row .combo-option {
      flex: 1;
      margin: 0;
    }
    .combo-option-remove {
      width: 30px;
      min-width: 30px;
      height: 30px;
      border: 0;
      border-radius: 8px;
      cursor: pointer;
      background: #fee2e2;
      color: #b91c1c;
      font-weight: 900;
      line-height: 1;
    }
    .combo-option-remove:hover { background: #fecaca; }
    .combo-empty {
      padding: 8px 10px;
      color: #64748b;
      font-size: 0.9rem;
      font-style: italic;
    }
    .combo-add-btn {
      width: 100%;
      text-align: center;
      border: 0;
      background: #e8f5e9;
      padding: 8px 10px;
      border-radius: 8px;
      cursor: pointer;
      color: #2e7d32;
      font-size: 0.90rem;
      font-weight: bold;
      margin-top: 5px;
      transition: background 0.3s;
    }
    .combo-add-btn:hover { background: #c8e6c9; }
    .combo-remove-btn {
      width: 100%;
      text-align: center;
      border: 0;
      background: #fee2e2;
      padding: 8px 10px;
      border-radius: 8px;
      cursor: pointer;
      color: #991b1b;
      font-size: 0.90rem;
      font-weight: bold;
      margin-top: 5px;
      transition: background 0.3s;
    }
    .combo-remove-btn:hover { background: #fecaca; }
    /* === Combo Condiciones Peligrosas === */
    .cond-combo {
      position: relative;
    }
    .cond-combo input {
      padding-right: 82px;
      cursor: pointer;
    }
    .cond-combo .btn-clear {
      top: 50%;
      transform: translateY(-50%);
      right: 40px;
      width: 28px;
      height: 28px;
      border-radius: 8px;
      box-shadow: none;
    }
    .cond-combo .combo-toggle {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      width: 28px;
      height: 28px;
      border: 0;
      border-radius: 8px;
      background: #edf2f7;
      color: #334155;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .cond-combo .combo-toggle:hover { background: #e2e8f0; }
    .cond-menu {
      display: none;
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      right: 0;
      z-index: 60;
      background: #fff;
      border: 1px solid #d9e3dd;
      border-radius: 10px;
      box-shadow: 0 8px 18px rgba(0,0,0,0.15);
      padding: 6px;
      max-height: 300px;
      overflow-y: auto;
    }
    .cond-combo.open .cond-menu { display: block; }
    .cond-option {
      width: 100%;
      text-align: left;
      border: 0;
      background: transparent;
      padding: 8px 10px;
      border-radius: 8px;
      cursor: pointer;
      color: #1f3f31;
      font-size: 0.92rem;
      line-height: 1.3;
    }
    .cond-option:hover { background: #e8f5e9; }
    .cond-option .cond-title {
      font-weight: 800;
      color: #1b3a2a;
      display: block;
      font-size: 0.93rem;
    }
    .cond-option .cond-preview {
      font-weight: 400;
      color: #64748b;
      font-size: 0.8rem;
      display: block;
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }
    .cond-search-box {
      width: 100%;
      padding: 8px 10px;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      font-size: 0.9rem;
      margin-bottom: 6px;
      box-sizing: border-box;
    }
    .cond-search-box:focus {
      outline: none;
      border-color: #2e7d32;
    }
    .cond-empty {
      padding: 10px;
      color: #64748b;
      font-size: 0.9rem;
      font-style: italic;
      text-align: center;
      display: none;
    }
    .cond-option-row {
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .cond-option-row .cond-option { flex: 1; }
    .cond-option-remove {
      width: 26px;
      min-width: 26px;
      height: 26px;
      border: 0;
      border-radius: 8px;
      cursor: pointer;
      background: #fee2e2;
      color: #b91c1c;
      font-weight: 900;
      font-size: 0.8rem;
      line-height: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .cond-option-remove:hover { background: #fecaca; }
    .cond-actions-bar {
      display: flex;
      gap: 4px;
      margin-top: 4px;
      border-top: 1px solid #e2e8f0;
      padding-top: 6px;
    }
    .cond-add-btn {
      flex: 1;
      text-align: center;
      border: 0;
      background: #e8f5e9;
      padding: 8px 10px;
      border-radius: 8px;
      cursor: pointer;
      color: #2e7d32;
      font-size: 0.88rem;
      font-weight: bold;
      transition: background 0.3s;
    }
    .cond-add-btn:hover { background: #c8e6c9; }
  </style>
</head>
<body>

  <aside class="sidebar">
    <h2 style="text-align:center; padding:10px; color:#fdd835;">Millalemu</h2>
    <a href="menuadmin.php"><i class="fas fa-home"></i> Inicio</a>
    <a href="index.php"><i class="fas fa-eye"></i> <b>Visor Global</b></a>
    <a href="mapas.php"><i class="fas fa-layer-group"></i> Gestión de Mapas</a>
    <a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a>
    <a href="piv_formulario.php" class="piv-btn"><i class="fas fa-clipboard-list"></i> PIV Formulario</a>
    <div style="margin-top:auto; padding-bottom:20px;">
      <a href="logout.php" style="color:#ef5350;"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </div>
  </aside>

  <main class="main">
    <div class="wrap">
      <h1>PIV - Plan Intervención de Volteo</h1>

      <div class="piv-card">
        <?php if ($edit_piv): ?>
          <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600; color:#856404;">
            <i class="fas fa-pen"></i> Editando PIV #<?php echo (int)$edit_piv['id_piv']; ?> — Los cambios se aplicarán sobre la misma PIV.
          </div>
        <?php endif; ?>
        <div class="actions">
          <div class="pill"><i class="fas fa-user"></i> Responsable: <span><?php echo h($nombre_user); ?></span></div>
          <div class="pill"><i class="fas fa-calendar"></i> Fecha: <span><?php echo date('d-m-Y'); ?></span></div>
          <div class="pill"><i class="fas fa-shield"></i> Rol: <span><?php echo h($tipo); ?></span></div>
        </div>

        <?php if(!$mapa): ?>
          <div class="divider"></div>
          <p class="muted">No llegó un mapa activo de Acta. Elige zona y un mapa de Acta para asociar el PIV.</p>

          <div class="grid">
            <div class="row">
              <label>Zona</label>
              <select onchange="location.href='piv_formulario.php?id_zona='+this.value+'<?php echo ($captura_src>0 ? '&captura_src=' . (int)$captura_src : ''); ?>'" required>
                <option value="">-- Seleccionar zona --</option>
                <?php foreach($zonas as $z): ?>
                  <option value="<?php echo (int)$z['id_zona']; ?>" <?php echo ($id_zona_sel==(int)$z['id_zona'])?'selected':''; ?>>
                    <?php echo h($z['nombre_zona']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row">
              <label>Mapa / Escenario</label>
              <select id="selMapa" <?php echo ($id_zona_sel>0?'':'disabled'); ?>>
                <option value=""><?php echo ($id_zona_sel>0?'-- Seleccionar mapa --':'Primero elige una zona'); ?></option>
                <?php foreach($mapas_zona as $m): ?>
                  <option value="<?php echo (int)$m['id_mapa']; ?>">
                    <?php echo h($m['nombre_mapa']); ?> (<?php echo h($m['categoria'] ?? ''); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="actions">
            <a class="btn btn-secondary" href="menuadmin.php">Volver</a>
            <button class="btn btn-primary" type="button" onclick="irMapa()">Continuar</button>
          </div>
        <?php else: ?>

          <div class="divider"></div>

          <div class="pill"><i class="fas fa-map"></i> Mapa: <b><?php echo h($mapa['nombre_mapa']); ?></b> <span class="muted">| Zona: <?php echo h($mapa['nombre_zona']); ?></span></div>

          <div class="apply-detected">
            <div class="apply-detected-copy">
              <div class="apply-detected-title"><i class="fas fa-wand-magic-sparkles"></i> Aplicar datos detectados del mapa</div>
              <div class="apply-detected-note">Autocompleta campos de la ficha usando los datos ya detectados en el mapa actual.</div>
            </div>
            <a class="btn btn-apply-map" href="piv_aplicar_borrador.php?id_mapa=<?php echo (int)$id_mapa; ?>" onclick="return confirm('Se aplicarán los datos detectados del mapa sobre la ficha actual.\\n¿Deseas continuar?');">
              <i class="fas fa-bolt"></i> Aplicar ahora
            </a>
          </div>
          <?php if($msg !== ''): ?>
            <p class="muted" style="margin:6px 0 0 0;"><?php echo h($msg); ?></p>
          <?php endif; ?>

          <div class="steps">
            <div class="step active" id="st1"><i class="fas fa-file-alt"></i> 1) Datos</div>
            <div class="step" id="st2"><i class="fas fa-list-check"></i> 2) Medidas</div>
            <div class="step" id="st3"><i class="fas fa-pen-nib"></i> 3) Confirmar datos</div>
          </div>

          <div id="paso1">
            <p class="muted">
              Algunos campos se autocompletarán desde el botón <b>Aplicar datos detectados del mapa</b>.
              <?php if(!$ficha): ?>
                <span style="color:#b45309;font-weight:800;">No existe ficha aún: créala una vez.</span>
              <?php endif; ?>
            </p>

            <form method="POST" action="piv_guardar_ficha.php" id="formFicha" enctype="multipart/form-data">
              <input type="hidden" name="id_mapa" value="<?php echo (int)$mapa['id_mapa']; ?>">
              <input type="hidden" name="id_zona" value="<?php echo (int)$mapa['id_zona']; ?>">

              <div class="grid">
                <div class="row field">
                  <label>Predio - Código</label>
                  <input id="codigo_predio" name="codigo_predio" value="<?php echo h($ficha['codigo_predio'] ?? ''); ?>" placeholder="Ej: EL SAUCE 2 // 11229">
                  <button type="button" class="btn-clear" data-clear="codigo_predio" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>
                <div class="row field">
                  <label>Predio (nombre)</label>
                  <input id="predio" name="predio" value="<?php echo h($ficha['predio'] ?? ''); ?>" placeholder="Ej: EL SAUCE 2">
                  <button type="button" class="btn-clear" data-clear="predio" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>

                <div class="row field">
                  <label>Escenario</label>
                  <input id="escenario" name="escenario" value="<?php echo h($ficha['escenario'] ?? $mapa['nombre_mapa']); ?>" placeholder="Ej: 2">
                  <button type="button" class="btn-clear" data-clear="escenario" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>
                <div class="row field">
                  <label>Temporada</label>
                  <input id="temporada" name="temporada" value="<?php echo h($ficha['temporada'] ?? 'VERANO 2025'); ?>">
                  <button type="button" class="btn-clear" data-clear="temporada" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>

                <div class="row field">
                  <label>Especie</label>
                  <input id="especie" name="especie" value="<?php echo h($ficha['especie'] ?? ''); ?>" placeholder="Ej: PIRA">
                  <button type="button" class="btn-clear" data-clear="especie" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>
                <div class="row field">
                  <label>Superficie (ha)</label>
                  <input id="superficie_ha" name="superficie_ha" inputmode="decimal" value="<?php echo h($ficha['superficie_ha'] ?? ''); ?>" placeholder="Ej: 4.39">
                  <button type="button" class="btn-clear" data-clear="superficie_ha" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>
                <div class="row field">
                  <label>Volumen total (m3)</label>
                  <input id="volumen_total_m3" name="volumen_total_m3" inputmode="decimal" value="<?php echo h($ficha['volumen_total_m3'] ?? ''); ?>" placeholder="Ej: 2247">
                  <button type="button" class="btn-clear" data-clear="volumen_total_m3" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>

                <div class="row field">
                  <label>Árboles/hora</label>
                  <input id="arboles_hora" name="arboles_hora" inputmode="decimal" value="<?php echo h($ficha['arboles_hora'] ?? ''); ?>" placeholder="Ej: 33">
                  <button type="button" class="btn-clear" data-clear="arboles_hora" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>

                <div class="row field">
                  <label>Team / Equipo</label>
                  <div class="custom-combo" data-combo="team_equipo">
                    <input id="team_equipo" name="team_equipo" value="<?php echo h($ficha['team_equipo'] ?? ''); ?>" placeholder="Seleccione o escriba..." autocomplete="off">
                    <button type="button" class="btn-clear" data-clear="team_equipo" title="Limpiar"><i class="fas fa-eraser"></i></button>
                    <button type="button" class="combo-toggle"><i class="fas fa-chevron-down"></i></button>
                    <div class="combo-menu">
                      <?php foreach($opciones_combo['team_equipo'] as $opt): ?>
                        <div class="combo-option-row">
                          <button type="button" class="combo-option" data-value="<?php echo h($opt); ?>"><?php echo h($opt); ?></button>
                          <?php if (in_array($opt, $opciones_manuales['team_equipo'], true)): ?>
                            <button type="button" class="combo-option-remove" data-campo="team_equipo" data-value="<?php echo h($opt); ?>" title="Eliminar opción">✕</button>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                      <hr style="margin: 5px 0; border: 0; border-top: 1px solid #d9e3dd;">
                      <button type="button" class="combo-add-btn" onclick="agregarPalabrasMasivas('team_equipo')">
                        <i class="fas fa-plus"></i> Agregar palabras a la lista
                      </button>
                      <button type="button" class="combo-remove-btn" onclick="eliminarPalabrasMasivas('team_equipo')">
                        <i class="fas fa-trash"></i> Eliminar palabras de la lista
                      </button>
                    </div>
                  </div>
                </div>
                <div class="row field">
                  <label>Tipo equipo de volteo</label>
                  <div class="custom-combo" data-combo="tecnologia">
                    <input id="tecnologia" name="tecnologia" value="<?php echo h($ficha['tecnologia'] ?? ''); ?>" placeholder="Seleccione o escriba..." autocomplete="off">
                    <button type="button" class="btn-clear" data-clear="tecnologia" title="Limpiar"><i class="fas fa-eraser"></i></button>
                    <button type="button" class="combo-toggle"><i class="fas fa-chevron-down"></i></button>
                    <div class="combo-menu">
                      <?php foreach($opciones_combo['tecnologia'] as $opt): ?>
                        <div class="combo-option-row">
                          <button type="button" class="combo-option" data-value="<?php echo h($opt); ?>"><?php echo h($opt); ?></button>
                          <?php if (in_array($opt, $opciones_manuales['tecnologia'], true)): ?>
                            <button type="button" class="combo-option-remove" data-campo="tecnologia" data-value="<?php echo h($opt); ?>" title="Eliminar opción">✕</button>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                      <hr style="margin: 5px 0; border: 0; border-top: 1px solid #d9e3dd;">
                      <button type="button" class="combo-add-btn" onclick="agregarPalabrasMasivas('tecnologia')">
                        <i class="fas fa-plus"></i> Agregar palabras a la lista
                      </button>
                      <button type="button" class="combo-remove-btn" onclick="eliminarPalabrasMasivas('tecnologia')">
                        <i class="fas fa-trash"></i> Eliminar palabras de la lista
                      </button>
                    </div>
                  </div>
                </div>

                <div class="row field">
                  <label>Asistencia / Tipo</label>
                  <div class="custom-combo" data-combo="asistencia_tipo">
                    <input id="asistencia_tipo" name="asistencia_tipo" value="<?php echo h($ficha['asistencia_tipo'] ?? ''); ?>" placeholder="Seleccione o escriba..." autocomplete="off">
                    <button type="button" class="btn-clear" data-clear="asistencia_tipo" title="Limpiar"><i class="fas fa-eraser"></i></button>
                    <button type="button" class="combo-toggle"><i class="fas fa-chevron-down"></i></button>
                    <div class="combo-menu">
                      <?php foreach($opciones_combo['asistencia_tipo'] as $opt): ?>
                        <div class="combo-option-row">
                          <button type="button" class="combo-option" data-value="<?php echo h($opt); ?>"><?php echo h($opt); ?></button>
                          <?php if (in_array($opt, $opciones_manuales['asistencia_tipo'], true)): ?>
                            <button type="button" class="combo-option-remove" data-campo="asistencia_tipo" data-value="<?php echo h($opt); ?>" title="Eliminar opción">✕</button>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                      <hr style="margin: 5px 0; border: 0; border-top: 1px solid #d9e3dd;">
                      <button type="button" class="combo-add-btn" onclick="agregarPalabrasMasivas('asistencia_tipo')">
                        <i class="fas fa-plus"></i> Agregar palabras a la lista
                      </button>
                      <button type="button" class="combo-remove-btn" onclick="eliminarPalabrasMasivas('asistencia_tipo')">
                        <i class="fas fa-trash"></i> Eliminar palabras de la lista
                      </button>
                    </div>
                  </div>
                </div>
                <div class="row field">
                  <label>Jefe de faena</label>
                  <div class="custom-combo" data-combo="jefe_faena">
                    <input id="jefe_faena" name="jefe_faena" value="<?php echo h($ficha['jefe_faena'] ?? ''); ?>" placeholder="Seleccione o escriba..." autocomplete="off">
                    <button type="button" class="btn-clear" data-clear="jefe_faena" title="Limpiar"><i class="fas fa-eraser"></i></button>
                    <button type="button" class="combo-toggle"><i class="fas fa-chevron-down"></i></button>
                    <div class="combo-menu">
                      <?php foreach($opciones_combo['jefe_faena'] as $opt): ?>
                        <div class="combo-option-row">
                          <button type="button" class="combo-option" data-value="<?php echo h($opt); ?>"><?php echo h($opt); ?></button>
                          <?php if (in_array($opt, $opciones_manuales['jefe_faena'], true)): ?>
                            <button type="button" class="combo-option-remove" data-campo="jefe_faena" data-value="<?php echo h($opt); ?>" title="Eliminar opción">✕</button>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                      <hr style="margin: 5px 0; border: 0; border-top: 1px solid #d9e3dd;">
                      <button type="button" class="combo-add-btn" onclick="agregarPalabrasMasivas('jefe_faena')">
                        <i class="fas fa-plus"></i> Agregar palabras a la lista
                      </button>
                      <button type="button" class="combo-remove-btn" onclick="eliminarPalabrasMasivas('jefe_faena')">
                        <i class="fas fa-trash"></i> Eliminar palabras de la lista
                      </button>
                    </div>
                  </div>
                </div>
                <div class="row">
                  <label>Volteo cercano a tendido eléctrico</label>
                  <select name="volteo_cerca_tendido_electrico">
                    <option value="0" <?php echo (!($ficha['volteo_cerca_tendido_electrico'] ?? false))?'selected':''; ?>>NO</option>
                    <option value="1" <?php echo (($ficha['volteo_cerca_tendido_electrico'] ?? false))?'selected':''; ?>>SÍ</option>
                  </select>
                </div>
                <div class="row">
                  <label>Volteo cercano a camino público</label>
                  <select name="volteo_cerca_camino_publico">
                    <option value="0" <?php echo (!($ficha['volteo_cerca_camino_publico'] ?? false))?'selected':''; ?>>NO</option>
                    <option value="1" <?php echo (($ficha['volteo_cerca_camino_publico'] ?? false))?'selected':''; ?>>SÍ</option>
                  </select>
                </div>

                <div class="row">
                  <label>Uso de pivotes</label>
                  <select name="uso_pivotes">
                    <option value="0" <?php echo (!($ficha['uso_pivotes'] ?? false))?'selected':''; ?>>NO</option>
                    <option value="1" <?php echo (($ficha['uso_pivotes'] ?? false))?'selected':''; ?>>SÍ</option>
                  </select>
                </div>

                <div class="row field">
                  <label>Tiempo estimado de volteo (días)</label>
                  <input id="tiempo_estimado_dias" name="tiempo_estimado_dias" inputmode="decimal" value="<?php echo h($ficha['tiempo_estimado_dias'] ?? ''); ?>" placeholder="Ej: 10">
                  <button type="button" class="btn-clear" data-clear="tiempo_estimado_dias" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>

                <div class="row field">
                  <label>Pendiente máxima (%)</label>
                  <input id="pendiente_max_pct" name="pendiente_max_pct" inputmode="decimal" value="<?php echo h($ficha['pendiente_max_pct'] ?? ''); ?>" placeholder="Ej: 150">
                  <button type="button" class="btn-clear" data-clear="pendiente_max_pct" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>

                <div class="row field">
                  <label>Tipo de suelo</label>
                  <div class="custom-combo" data-combo="tipo_suelo">
                    <input id="tipo_suelo" name="tipo_suelo" value="<?php echo h($ficha['tipo_suelo'] ?? ''); ?>" placeholder="Seleccione o escriba..." autocomplete="off">
                    <button type="button" class="btn-clear" data-clear="tipo_suelo" title="Limpiar"><i class="fas fa-eraser"></i></button>
                    <button type="button" class="combo-toggle"><i class="fas fa-chevron-down"></i></button>
                    <div class="combo-menu">
                      <?php foreach($opciones_combo['tipo_suelo'] as $opt): ?>
                        <div class="combo-option-row">
                          <button type="button" class="combo-option" data-value="<?php echo h($opt); ?>"><?php echo h($opt); ?></button>
                          <?php if (in_array($opt, $opciones_manuales['tipo_suelo'], true)): ?>
                            <button type="button" class="combo-option-remove" data-campo="tipo_suelo" data-value="<?php echo h($opt); ?>" title="Eliminar opción">✕</button>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                      <hr style="margin: 5px 0; border: 0; border-top: 1px solid #d9e3dd;">
                      <button type="button" class="combo-add-btn" onclick="agregarPalabrasMasivas('tipo_suelo')">
                        <i class="fas fa-plus"></i> Agregar palabras a la lista
                      </button>
                      <button type="button" class="combo-remove-btn" onclick="eliminarPalabrasMasivas('tipo_suelo')">
                        <i class="fas fa-trash"></i> Eliminar palabras de la lista
                      </button>
                    </div>
                  </div>
                </div>

                <div class="row field">
                  <label>Verificación de permisos</label>
                  <input id="verif_permisos" name="verif_permisos" value="<?php echo h($ficha['verif_permisos'] ?? 'ok'); ?>" placeholder="Ej: ok">
                  <button type="button" class="btn-clear" data-clear="verif_permisos" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>

                <div class="row field">
                  <label>Jornada</label>
                  <input id="jornada" name="jornada" value="<?php echo h($ficha['jornada'] ?? '7X7'); ?>" placeholder="Ej: 7X7">
                  <button type="button" class="btn-clear" data-clear="jornada" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>
              </div>
              
              <div class="grupos-section">
                  <h3 class="grupos-title"><i class="fas fa-users-rectangle"></i> Grupos de Trabajo (Destinatarios)</h3>
                  <div class="grupos-container" id="gruposContainer">
                    <!-- Los grupos se generan dinámicamente -->
                  </div>
                  <button type="button" class="btn-add-grupo" id="btnAddGrupo" onclick="agregarGrupo()">
                    <i class="fas fa-plus-circle"></i> Agregar Grupo de Trabajo
                  </button>
                  <small class="grupos-help">Crea grupos de trabajo y selecciona los miembros de cada uno. La PIV se enviará a todos los miembros seleccionados.</small>
              </div>
              <br>

              <div class="actions actions-step1">
                <button class="btn btn-secondary" type="button" onclick="limpiarFormulario()"><i class="fas fa-eraser"></i> Limpiar formulario</button>
                <button class="btn btn-primary" type="button" onclick="nextStep(2)">Siguiente</button>
              </div>
            </form>

          </div>

          <div id="paso2" class="hidden">
            
            <div class="row field" style="background: #fff8e1; border: 1px solid #ffe082; border-radius: 12px; padding: 15px; margin-bottom: 20px;">
                <label style="color: #b08d00; font-size: 1.05rem;"><i class="fas fa-table"></i> ¿Qué Tabla de Matriz de Decisiones usar en el PDF?</label>
                <?php
                  // Detectamos si en la edición ya había sido marcada como FALCON
                  $matriz_sel = 'TWINCH';
                  if ($edit_piv && strpos((string)$edit_piv['consideraciones'], '[MATRIZ:FALCON]') !== false) {
                      $matriz_sel = 'FALCON';
                  }
                ?>
                <select id="tipo_matriz" style="font-weight: bold; color: #1b3a2a; margin-top: 10px; border: 2px solid #b08d00;">
                    <option value="TWINCH" <?php echo $matriz_sel === 'TWINCH' ? 'selected' : ''; ?>>Tabla T-WINCH 30.2 (Valores en kN)</option>
                    <option value="FALCON" <?php echo $matriz_sel === 'FALCON' ? 'selected' : ''; ?>>Tabla FALCON WINCH / TIMBER MAX (Valores en Toneladas)</option>
                </select>
                <small style="color: #8a6d00; margin-top:8px; display:block;">Esta opción asegura que el PDF se dibuje con la tabla correcta sin importar el nombre de la máquina.</small>
            </div>
            <p class="muted"><b>Consideraciones importantes y medidas</b> (7 campos)</p>

            <?php
              $cons_text = $edit_piv ? (string)$edit_piv['consideraciones'] : '';
              // Limpiar la etiqueta secreta para que no salga escrita en las cajas de texto
              $cons_text = str_replace(['[MATRIZ:FALCON]', '[MATRIZ:TWINCH]'], '', $cons_text);
              $cons = explode("\n", trim($cons_text));
            ?>

            <?php for ($ci = 1; $ci <= 7; $ci++): ?>
            <div class="row field">
              <label><?php echo $ci === 1 ? 'Consideraciones importantes y medidas' : '&nbsp;'; ?></label>
              <div class="cond-combo" data-cond-idx="<?php echo $ci; ?>">
                <input id="consideracion_<?php echo $ci; ?>"
                       value="<?php echo h($cons[$ci-1] ?? ''); ?>"
                       placeholder="<?php echo $ci; ?>) Seleccione o escriba..."
                       autocomplete="off" readonly>
                <button type="button" class="btn-clear" data-clear="consideracion_<?php echo $ci; ?>" title="Limpiar"><i class="fas fa-eraser"></i></button>
                <button type="button" class="combo-toggle"><i class="fas fa-chevron-down"></i></button>
                <div class="cond-menu">
                  <input type="text" class="cond-search-box" placeholder="Buscar condición...">
                  <div class="cond-options-list"></div>
                  <div class="cond-empty">No se encontraron coincidencias.</div>
                  <div class="cond-actions-bar">
                    <button type="button" class="cond-add-btn" data-action="add">
                      <i class="fas fa-plus"></i> Agregar nueva condición
                    </button>
                  </div>
                </div>
              </div>
            </div>
            <?php endfor; ?>

            <div class="divider"></div>
            <p class="muted"><b>Planos / Mapas (Carga Manual Opcional)</b></p>
                
                <?php
                  // LÓGICA PARA BUSCAR IMÁGENES PREVIAS MOVIDA AQUÍ PARA LA GALERÍA
                  $uploads = __DIR__ . '/uploads/';
                  $exts = ['jpg','jpeg','png'];
                  $mapaIdAviso = (int)$mapa['id_mapa'];
                  $foto1 = '';
                  $foto2 = '';
                  foreach ($exts as $e) {
                    $p1 = $uploads . 'plano_' . $mapaIdAviso . '.' . $e;
                    if ($foto1 === '' && is_file($p1)) $foto1 = $p1;
                    $p2 = $uploads . 'plano_2_' . $mapaIdAviso . '.' . $e;
                    if ($foto2 === '' && is_file($p2)) $foto2 = $p2;
                  }
                  $has1 = ($foto1 !== '');
                  $has2 = ($foto2 !== '');
                ?>

                <div class="preview-gallery" style="margin-top: 10px; margin-bottom: 20px;">
                    <h4><i class="fas fa-camera-retro"></i> Fotografías actuales en el servidor</h4>
                    <p id="galleryEmpty" class="muted" style="margin: 0; font-size: 0.9rem;<?php echo ($has1 || $has2) ? 'display:none;' : ''; ?>">Aún no se han capturado fotos para este mapa en el visor.</p>
                    <div id="galleryFlex" style="display:<?php echo ($has1 || $has2) ? 'flex' : 'none'; ?>; gap:15px; flex-wrap:wrap;">
                        <div id="imgBox1" class="img-box" style="<?php echo $has1 ? '' : 'display:none;'; ?>">
                            <a id="imgLink1" href="<?php echo $has1 ? 'uploads/'.basename($foto1).'?v='.time() : '#'; ?>" target="_blank">
                                <img id="previewImg1" src="<?php echo $has1 ? 'uploads/'.basename($foto1).'?v='.time() : ''; ?>" alt="Foto 1">
                            </a>
                            <span>Foto 1 (General)</span>
                        </div>
                        <div id="imgBox2" class="img-box" style="<?php echo $has2 ? '' : 'display:none;'; ?>">
                            <a id="imgLink2" href="<?php echo $has2 ? 'uploads/'.basename($foto2).'?v='.time() : '#'; ?>" target="_blank">
                                <img id="previewImg2" src="<?php echo $has2 ? 'uploads/'.basename($foto2).'?v='.time() : ''; ?>" alt="Foto 2">
                            </a>
                            <span>Foto 2 (Detalle)</span>
                        </div>
                    </div>
                    <div style="margin-top: 12px;">
                        <a href="index.php?focus_map=<?php echo $mapaIdAviso; ?>" class="btn btn-secondary" style="padding: 8px 12px; font-size: 0.85rem;">
                            <i class="fas fa-crosshairs"></i> Ir al visor a tomar/repetir fotos
                        </a>
                    </div>
                </div>
                <?php
                  $msgInicial = 'No hay nuevas capturas manuales seleccionadas.';
                  if ($has1 && $has2) $msgInicial = 'Mapa 1 cargado | Mapa 2 cargado';
                  elseif ($has1) $msgInicial = 'Mapa 1 cargado';
                  elseif ($has2) $msgInicial = 'Mapa 2 cargado';
                  $styleInicial = ($has1 || $has2)
                    ? 'background:#e8f5e9; color:#2e7d32; border-color:#a5d6a7;'
                    : 'background:#fff8e1; color:#8a6d00; border-color:#ffe082;';
                  $iconInicial = ($has1 || $has2) ? 'fa-check-circle' : 'fa-circle-info';
                ?>
                <div
                  id="captureStatusPill"
                  class="pill"
                  data-server-map1="<?php echo $has1 ? '1' : '0'; ?>"
                  data-server-map2="<?php echo $has2 ? '1' : '0'; ?>"
                  style="<?php echo $styleInicial; ?> margin-bottom: 15px; display:inline-flex;"
                >
                    <i id="captureStatusIcon" class="fas <?php echo $iconInicial; ?>"></i>
                    <span id="captureStatusText"><?php echo h($msgInicial); ?></span>
                </div>
                <div class="grid">
                <div class="row field">
                  <label>Subir captura del mapa 1 (JPG o PNG)</label>
                  <input type="file" id="imagen_plano" name="imagen_plano" accept="image/png, image/jpeg" style="padding: 8px;">
                  <input type="text" id="comentario_plano_1" name="comentario_plano_1" placeholder="Ej: Sector norte - Precaucion carcavas" style="margin-top: 6px;">
                </div>
                <div class="row field">
                  <label>Subir captura del mapa 2 (Opcional)</label>
                  <input type="file" id="imagen_plano_2" name="imagen_plano_2" accept="image/png, image/jpeg" style="padding: 8px;">
                  <input type="text" id="comentario_plano_2" name="comentario_plano_2" placeholder="Ej: Detalle microrelieve y limite predial" style="margin-top: 6px;">
                </div>
            </div>
            <small class="muted" style="display:block; margin-top:5px;">Los textos se imprimiran como etiquetas sobre las imagenes en el PDF.</small>

            <div class="actions">
              <button class="btn btn-secondary" type="button" onclick="nextStep(1)">Atrás</button>
              <button class="btn btn-primary" type="button" onclick="nextStep(3)">Siguiente</button>
            </div>
          </div>

          <div id="paso3" class="hidden">
            <p class="muted"><b>CONFIRMAR DATOS</b></p>

            <div class="grid">
              <div class="row">
                <label>Cargo</label>
                <input id="firma_cargo" value="<?php echo $edit_piv ? h($edit_piv['firma_cargo']) : ''; ?>" placeholder="Ej: Supervisor / Operador">
              </div>
              <div class="row">
                <label>Nombre</label>
                <input id="firma_nombre" value="<?php echo $edit_piv ? h($edit_piv['firma_nombre']) : h($nombre_user); ?>" placeholder="Nombre">
              </div>
            </div>

            <div class="actions" style="margin-top: 25px;">
              <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <button class="btn btn-secondary" type="button" onclick="nextStep(2)">Atrás</button>
                <button class="btn btn-warn" type="button" id="btnPreview" onclick="abrirVistaPrevia()">
                    <i class="fas fa-eye"></i> Vista Previa del PDF
                </button>
              </div>
              <button class="btn btn-primary" type="button" id="btnGuardar" onclick="guardarPIV()">
                <i class="fas fa-paper-plane"></i> <?php echo $edit_piv ? 'Actualizar PIV' : 'Guardar y Enviar a Firma'; ?>
              </button>
            </div>
            <div class="actions hidden" id="pdfActions">
              <a class="btn btn-primary" id="btnPdf" href="#" target="_blank">Descargar PDF Final</a>
            </div>

          </div>

        <?php endif; ?>
      </div>
    </div>
  </main>

<script>
let ultimoIdPiv = 0;
const editPivId = <?php echo $edit_piv ? (int)$edit_piv['id_piv'] : 0; ?>;
// Usuarios disponibles para grupos de trabajo
const USUARIOS_DISPONIBLES = <?php
  $sql_users_js = "SELECT id_usuario, nombre_usuario, tipo_usuario FROM public.usuario ORDER BY tipo_usuario ASC, nombre_usuario ASC";
  $stmt_js = $pdo->prepare($sql_users_js);
  $stmt_js->execute();
  $users_js = $stmt_js->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($users_js, JSON_UNESCAPED_UNICODE);
?>;

// Destinatarios pre-seleccionados (modo edición)
const EDIT_DESTINATARIOS = <?php echo json_encode(array_map('intval', $edit_destinatarios_ids)); ?>;

function irMapa(){
  const id = document.getElementById('selMapa').value;
  if(!id){ alert("Selecciona un mapa."); return; }
  let url = 'piv_formulario.php?id_mapa=' + id;
  <?php if ($captura_src > 0): ?>
  url += '&captura_src=<?php echo (int)$captura_src; ?>';
  <?php endif; ?>
  location.href = url;
}

function setActive(step){
  document.getElementById('st1').classList.toggle('active', step===1);
  document.getElementById('st2').classList.toggle('active', step===2);
  document.getElementById('st3').classList.toggle('active', step===3);
  document.getElementById('paso1').classList.toggle('hidden', step!==1);
  document.getElementById('paso2').classList.toggle('hidden', step!==2);
  document.getElementById('paso3').classList.toggle('hidden', step!==3);
  window.scrollTo({top:0, behavior:'smooth'});
}
function nextStep(step){ setActive(step); }

// ==========================================
// NUEVA FUNCION: VISTA PREVIA PDF
// ==========================================
// ==========================================
// NUEVA FUNCION: VISTA PREVIA PDF
// ==========================================
function abrirVistaPrevia() {
  const btnPreview = document.getElementById('btnPreview');
  if (btnPreview) { btnPreview.disabled = true; btnPreview.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando...'; }

  const fd = new FormData();

  // Campos del formulario
  document.querySelectorAll('#paso1 input:not([type=file]):not([type=checkbox]), #paso1 select, #paso3 input').forEach(inpt => {
    if (inpt.name || inpt.id) fd.append(inpt.name || inpt.id, inpt.value);
  });
  fd.append('fecha', '<?php echo date('Y-m-d'); ?>');

  // Consideraciones
  let consideraciones = [
    document.getElementById('consideracion_1')?.value ?? '',
    document.getElementById('consideracion_2')?.value ?? '',
    document.getElementById('consideracion_3')?.value ?? '',
    document.getElementById('consideracion_4')?.value ?? '',
    document.getElementById('consideracion_5')?.value ?? '',
    document.getElementById('consideracion_6')?.value ?? '',
    document.getElementById('consideracion_7')?.value ?? ''
  ].map(v => v.trim()).filter(Boolean).join('\n');
  const tipoMatriz = document.getElementById('tipo_matriz')?.value ?? '';
  consideraciones += '\n[MATRIZ:' + tipoMatriz + ']';
  fd.append('consideraciones', consideraciones);

  // Imágenes (si hay archivos seleccionados localmente)
  const f1 = document.getElementById('imagen_plano');
  if (f1 && f1.files[0]) fd.append('imagen_plano', f1.files[0]);
  const f2 = document.getElementById('imagen_plano_2');
  if (f2 && f2.files[0]) fd.append('imagen_plano_2', f2.files[0]);

  // Comentarios de los planos
  const c1 = document.getElementById('comentario_plano_1');
  if (c1) fd.append('comentario_plano_1', c1.value);
  const c2 = document.getElementById('comentario_plano_2');
  if (c2) fd.append('comentario_plano_2', c2.value);

  // Destinatarios
  document.querySelectorAll('input[name="destinatarios[]"]:checked').forEach(cb => fd.append('destinatarios[]', cb.value));

  fetch('piv_pdf_preview.php', { method: 'POST', body: fd })
    .then(r => r.blob())
    .then(blob => {
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank');
    })
    .catch(() => alert('Error al generar la vista previa.'))
    .finally(() => {
      if (btnPreview) { btnPreview.disabled = false; btnPreview.innerHTML = '<i class="fas fa-eye"></i> Vista Previa del PDF'; }
    });
}

function guardarPIV(){
  // 1. Validaciones obligatorias de firma
  const firmaCargoEl = document.getElementById('firma_cargo');
  const firmaNombreEl = document.getElementById('firma_nombre');
  const firmaCargo = (firmaCargoEl?.value || '').trim();
  const firmaNombre = (firmaNombreEl?.value || '').trim();

  if (!firmaCargo) {
    alert('Debes ingresar el Cargo antes de guardar.');
    if (firmaCargoEl) firmaCargoEl.focus();
    return;
  }
  if (!firmaNombre) {
    alert('Debes ingresar el Nombre antes de guardar.');
    if (firmaNombreEl) firmaNombreEl.focus();
    return;
  }

  // 2. Recolectar los destinatarios seleccionados
  const destinatariosSeleccionados = [];
  document.querySelectorAll('input[name="destinatarios[]"]:checked').forEach((checkbox) => {
      destinatariosSeleccionados.push(checkbox.value);
  });

  if (destinatariosSeleccionados.length === 0) {
    alert('Debes seleccionar al menos un miembro en los Grupos de Trabajo.');
    setActive(1);
    return;
  }

  // 1. CAPTURAMOS EL BOTÓN Y LO BLOQUEAMOS AL INSTANTE
  const btnGuardar = document.getElementById('btnGuardar');
  if(btnGuardar) {
      btnGuardar.disabled = true;
      btnGuardar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
  }

  // 3. Juntar las consideraciones
  let consideraciones = [
    document.getElementById('consideracion_1')?.value ?? '',
    document.getElementById('consideracion_2')?.value ?? '',
    document.getElementById('consideracion_3')?.value ?? '',
    document.getElementById('consideracion_4')?.value ?? '',
    document.getElementById('consideracion_5')?.value ?? '',
    document.getElementById('consideracion_6')?.value ?? '',
    document.getElementById('consideracion_7')?.value ?? ''
  ].map(v => v.trim()).filter(Boolean).join('\n');

  // Inyectamos la etiqueta secreta de la matriz elegida
  const tipoMatriz = document.getElementById('tipo_matriz')?.value ?? '';
  consideraciones += '\n[MATRIZ:' + tipoMatriz + ']';

  const observaciones = [
    (document.getElementById('obs_1') || {}).value || '',
    (document.getElementById('obs_2') || {}).value || ''
  ].map(v => v.trim()).filter(Boolean).join('\n');

  // Recolectar destinatarios organizados por grupo
  const gruposData = {};
  document.querySelectorAll('.grupo-card').forEach((card, idx) => {
    const grupoNum = idx + 1;
    const miembros = [];
    card.querySelectorAll('input[name="destinatarios[]"]:checked').forEach(cb => {
      miembros.push(cb.value);
    });
    if (miembros.length > 0) {
      gruposData[grupoNum] = miembros;
    }
  });

  // 4. Crear el objeto de datos a enviar
  const payload = {
    id_mapa: <?php echo $mapa ? (int)$mapa['id_mapa'] : 0; ?>,
    fecha: document.getElementById('fecha')?.value ?? '<?php echo date('Y-m-d'); ?>',
    consideraciones: consideraciones,
    observaciones: observaciones,
    firma_cargo: firmaCargo,
    firma_nombre: firmaNombre,
    destinatarios: destinatariosSeleccionados,
    grupos: gruposData
  };

  if (editPivId > 0) {
    payload.id_piv = editPivId;
  }

  console.log('PIV payload a enviar:', payload);

  // 5. Guardar ficha primero y luego guardar PIV
  const formFicha = document.getElementById('formFicha');
  const formData = new FormData(formFicha);
  
  const fileInput1 = document.getElementById('imagen_plano');
  if (fileInput1 && fileInput1.files.length > 0) {
      formData.append('imagen_plano', fileInput1.files[0]);
  }
  const fileInput2 = document.getElementById('imagen_plano_2');
  if (fileInput2 && fileInput2.files.length > 0) {
      formData.append('imagen_plano_2', fileInput2.files[0]);
  }

  const comp1 = document.getElementById('comentario_plano_1');
  if (comp1) formData.append('comentario_plano_1', comp1.value);

  const comp2 = document.getElementById('comentario_plano_2');
  if (comp2) formData.append('comentario_plano_2', comp2.value);

  fetch('piv_guardar_ficha.php', {
    method: 'POST',
    body: formData
  })
  .then(() => {
    enviarDatosAlServidor(payload);
  })
  .catch((err) => {
    console.error(err);
    alert("Hubo un problema al guardar los datos de la ficha previa.");
    if(btnGuardar) {
        btnGuardar.disabled = false;
        btnGuardar.innerHTML = '<i class="fas fa-paper-plane"></i> Guardar y Enviar a Firma';
    }
  });
}

function enviarDatosAlServidor(payload){
  const btnGuardar = document.getElementById('btnGuardar');
  fetch('piv_guardar.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      alert(editPivId > 0 ? "✅ PIV actualizado correctamente." : "✅ Ficha y PIV guardados correctamente. Notificaciones enviadas.");
      if (typeof limpiarBorradorPIV === 'function') limpiarBorradorPIV();
      const idPiv = Number(res.id_piv || 0);
      
      if (!idPiv) {
        console.warn("Se guardó, pero no llegó id_piv para descarga PDF.");
      } else {
        ultimoIdPiv = idPiv;
        const pdf = 'piv_pdf_v2.php?id_piv=' + idPiv;
        document.getElementById('btnPdf').href = pdf;
        document.getElementById('pdfActions').classList.remove('hidden');
      }
      if(btnGuardar) {
          if (editPivId > 0) {
              btnGuardar.disabled = false;
              btnGuardar.innerHTML = '<i class="fas fa-paper-plane"></i> Actualizar y Re-enviar PIV';
          } else {
              btnGuardar.disabled = true;
              btnGuardar.innerHTML = '<i class="fas fa-check"></i> ¡Guardado y Enviado!';
          }
      }
    } else {
      alert("Error: " + (res.error || 'No se pudo guardar'));
      if(btnGuardar) {
          btnGuardar.disabled = false;
          btnGuardar.innerHTML = '<i class="fas fa-paper-plane"></i> Guardar y Enviar a Firma';
      }
    }
  })
  .catch((err)=>{
    console.error(err);
    alert("Error de red al guardar PIV.");
    if(btnGuardar) {
        btnGuardar.disabled = false;
        btnGuardar.innerHTML = '<i class="fas fa-paper-plane"></i> Guardar y Enviar a Firma';
    }
  });
}

function limpiarFormulario() {
  if (!confirm('Esto limpiará todos los campos del formulario de ficha.\n¿Deseas continuar?')) {
    return;
  }
  document.querySelectorAll(
    '#paso1 input:not([type=hidden]), #paso1 textarea'
  ).forEach(el => {
    if (el.type === 'checkbox' || el.type === 'radio') {
      el.checked = false;
    } else {
      el.value = '';
    }
  });
  document.querySelectorAll('#paso1 select').forEach(sel => {
    sel.selectedIndex = 0;
  });
  document.querySelectorAll('.grupo-card').forEach(card => {
    const gid = card.dataset.grupoId;
    if (gid) updateGrupoCount(parseInt(gid, 10));
  });
  alert('Formulario limpiado.');
  if (typeof limpiarBorradorPIV === 'function') limpiarBorradorPIV();
}

function clearFieldById(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (el.type === 'date') return;
  if (el.type === 'checkbox' || el.type === 'radio') return;
  if (el.tagName === 'SELECT') {
    el.selectedIndex = 0;
    return;
  }
  el.value = '';
}

function getFieldLabelById(id) {
  const el = document.getElementById(id);
  if (!el) return 'este campo';
  const row = el.closest('.row');
  const label = row ? row.querySelector('label') : null;
  const text = (label ? label.textContent : '').trim();
  return text && text !== '\u00A0' ? text : 'este campo';
}

const formFicha = document.getElementById('formFicha');
if (formFicha) {
  formFicha.addEventListener('submit', (e) => {
    const ok = confirm('Se guardarán/actualizarán los datos de la ficha.\n¿Deseas continuar?');
    if (!ok) e.preventDefault();
  });
}

document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-clear');
  if (!btn) return;
  const id = btn.getAttribute('data-clear');
  if (!id) return;
  const fieldLabel = getFieldLabelById(id);
  const ok = confirm('Se limpiará el campo: ' + fieldLabel + '.\n¿Deseas continuar?');
  if (!ok) return;
  clearFieldById(id);
});

// --- RESTAURADO: SCRIPT DE LA PILDORA ORIGINAL DE ESTADO ---
function updateCaptureStatusPill() {
  const pill = document.getElementById('captureStatusPill');
  if (!pill) return;

  const icon = document.getElementById('captureStatusIcon');
  const text = document.getElementById('captureStatusText');
  const in1 = document.getElementById('imagen_plano');
  const in2 = document.getElementById('imagen_plano_2');

  const server1 = pill.getAttribute('data-server-map1') === '1';
  const server2 = pill.getAttribute('data-server-map2') === '1';
  const pending1 = !!(in1 && in1.files && in1.files.length > 0);
  const pending2 = !!(in2 && in2.files && in2.files.length > 0);

  const has1 = server1 || pending1;
  const has2 = server2 || pending2;

  if (has1 || has2) {
    pill.style.background = '#e8f5e9';
    pill.style.color = '#2e7d32';
    pill.style.borderColor = '#a5d6a7';
    if (icon) icon.className = 'fas fa-check-circle';

    const parts = [];
    if (has1) parts.push(pending1 && !server1 ? 'Mapa 1 seleccionado (pendiente guardar)' : 'Mapa 1 cargado');
    if (has2) parts.push(pending2 && !server2 ? 'Mapa 2 seleccionado (pendiente guardar)' : 'Mapa 2 cargado');
    if (text) text.textContent = parts.join(' | ');
  } else {
    pill.style.background = '#fff8e1';
    pill.style.color = '#8a6d00';
    pill.style.borderColor = '#ffe082';
    if (icon) icon.className = 'fas fa-circle-info';
    if (text) text.textContent = 'No hay nuevas capturas manuales seleccionadas.';
  }
}

const fileStatus1 = document.getElementById('imagen_plano');
const fileStatus2 = document.getElementById('imagen_plano_2');
function previewLocalImage(inputId, boxId, imgId, linkId) {
  const input = document.getElementById(inputId);
  if (!input || !input.files || !input.files[0]) return;
  const url = URL.createObjectURL(input.files[0]);
  const box = document.getElementById(boxId);
  const img = document.getElementById(imgId);
  const link = document.getElementById(linkId);
  const galleryFlex = document.getElementById('galleryFlex');
  const galleryEmpty = document.getElementById('galleryEmpty');
  if (img) img.src = url;
  if (link) link.href = url;
  if (box) box.style.display = '';
  if (galleryFlex) galleryFlex.style.display = 'flex';
  if (galleryEmpty) galleryEmpty.style.display = 'none';
}

if (fileStatus1) fileStatus1.addEventListener('change', () => {
  updateCaptureStatusPill();
  previewLocalImage('imagen_plano', 'imgBox1', 'previewImg1', 'imgLink1');
});
if (fileStatus2) fileStatus2.addEventListener('change', () => {
  updateCaptureStatusPill();
  previewLocalImage('imagen_plano_2', 'imgBox2', 'previewImg2', 'imgLink2');
});
updateCaptureStatusPill();
// --- FIN RESTAURACIÓN ---

// ==========================================
// GRUPOS DE TRABAJO
// ==========================================
let grupoCounter = 0;

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[char]));
}

function agregarGrupo(miembrosPreseleccionados = []) {
  grupoCounter++;
  const grupoId = grupoCounter;
  const container = document.getElementById('gruposContainer');
  if (!container) return;

  const seleccionados = miembrosPreseleccionados.map((id) => Number(id));
  const card = document.createElement('div');
  card.className = 'grupo-card';
  card.dataset.grupoId = grupoId;

  let membersHtml = '';
  USUARIOS_DISPONIBLES.forEach(u => {
    const uid = Number(u.id_usuario);
    const checked = seleccionados.includes(uid) ? ' checked' : '';
    const name = escapeHtml(u.nombre_usuario);
    const role = escapeHtml(u.tipo_usuario);
    const search = escapeHtml((String(u.nombre_usuario || '') + ' ' + String(u.tipo_usuario || '')).toLowerCase());
    membersHtml += `
      <div class="grupo-member" data-search="${search}">
        <input type="checkbox" name="destinatarios[]" value="${uid}" id="g${grupoId}_u${uid}"${checked}
               onchange="updateGrupoCount(${grupoId})">
        <label class="dest-label" for="g${grupoId}_u${uid}">
          <span>${name}</span><span class="dest-role">${role}</span>
        </label>
      </div>`;
  });

  card.innerHTML = `
    <div class="grupo-header">
      <div class="grupo-header-left">
        <i class="fas fa-users"></i>
        <span class="grupo-title-text">Grupo de Trabajo ${grupoId}</span>
        <span class="grupo-badge" id="grupoBadge${grupoId}">0 seleccionados</span>
      </div>
      <button type="button" class="btn-remove-grupo" onclick="eliminarGrupo(${grupoId})" title="Eliminar grupo">✕</button>
    </div>
    <div class="grupo-body">
      <div class="grupo-search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" class="grupo-search" placeholder="Buscar miembro..." oninput="filtrarGrupo(${grupoId}, this.value)">
      </div>
      <div class="grupo-members" id="grupoMembers${grupoId}">
        ${membersHtml}
      </div>
      <div class="grupo-empty-search" id="grupoEmpty${grupoId}">No se encontraron miembros.</div>
      <div class="grupo-selected-summary" id="grupoSummary${grupoId}"></div>
    </div>`;

  container.appendChild(card);
  updateGrupoCount(grupoId);
}

function eliminarGrupo(grupoId) {
  const card = document.querySelector(`.grupo-card[data-grupo-id="${grupoId}"]`);
  if (!card) return;
  const checkedCount = card.querySelectorAll('input[type="checkbox"]:checked').length;
  if (checkedCount > 0 && !confirm(`Este grupo tiene ${checkedCount} miembro(s) seleccionado(s).\n¿Eliminar el grupo?`)) return;
  card.remove();
  renumerarGrupos();
  if (typeof window.guardarBorrador === 'function') window.guardarBorrador();
}

function renumerarGrupos() {
  document.querySelectorAll('.grupo-card').forEach((card, idx) => {
    const title = card.querySelector('.grupo-title-text');
    if (title) title.textContent = `Grupo de Trabajo ${idx + 1}`;
  });
}

function filtrarGrupo(grupoId, query) {
  const norm = (v) => (v || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
  const q = norm(query);
  const members = document.querySelectorAll(`#grupoMembers${grupoId} .grupo-member`);
  const emptyEl = document.getElementById(`grupoEmpty${grupoId}`);
  let shown = 0;
  members.forEach(m => {
    const text = norm(m.getAttribute('data-search'));
    const ok = !q || text.includes(q);
    m.style.display = ok ? 'flex' : 'none';
    if (ok) shown++;
  });
  if (emptyEl) emptyEl.style.display = shown === 0 ? 'block' : 'none';
}

function updateGrupoCount(grupoId) {
  const card = document.querySelector(`.grupo-card[data-grupo-id="${grupoId}"]`);
  if (!card) return;
  const checked = card.querySelectorAll('input[type="checkbox"]:checked');
  const badge = document.getElementById(`grupoBadge${grupoId}`);
  const summary = document.getElementById(`grupoSummary${grupoId}`);
  if (badge) badge.textContent = checked.length + ' seleccionado' + (checked.length !== 1 ? 's' : '');
  if (summary) {
    const names = Array.from(checked).map(cb => {
      const label = card.querySelector(`label[for="${cb.id}"] span:first-child`);
      return label ? label.textContent : '';
    }).filter(Boolean);
    summary.textContent = names.length > 0 ? names.join(', ') : '';
  }
  if (typeof window.guardarBorrador === 'function') window.guardarBorrador();
}

// Inicializar: Si estamos editando, crear un grupo con los destinatarios pre-seleccionados
(function initGrupos() {
  if (EDIT_DESTINATARIOS.length > 0) {
    agregarGrupo(EDIT_DESTINATARIOS);
  } else {
    agregarGrupo([]);
  }
})();

const normCombo = (v) => (v || '')
  .toString()
  .toLowerCase()
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .trim();

function closeAllCombos() {
  document.querySelectorAll('.custom-combo.open').forEach((c) => c.classList.remove('open'));
}

document.querySelectorAll('.custom-combo').forEach((wrap) => {
  const input = wrap.querySelector('input');
  const toggle = wrap.querySelector('.combo-toggle');
  const menu = wrap.querySelector('.combo-menu');
  const options = Array.from(wrap.querySelectorAll('.combo-option'));

  if (!input || !toggle || !menu) return;

  if (options.length === 0 && !menu.querySelector('.combo-empty')) {
    const empty = document.createElement('div');
    empty.className = 'combo-empty';
    empty.textContent = 'Sin opciones disponibles.';
    menu.prepend(empty);
  }

  const showAll = () => options.forEach((opt) => { opt.style.display = 'block'; });
  const open = () => { wrap.classList.add('open'); };
  const close = () => { wrap.classList.remove('open'); };

  const filter = () => {
    if (options.length === 0) return;
    const q = normCombo(input.value);
    let visible = 0;
    options.forEach((opt) => {
      const ok = !q || normCombo(opt.dataset.value).includes(q);
      opt.style.display = ok ? 'block' : 'none';
      if (ok) visible++;
    });
    const emptyEl = menu.querySelector('.combo-empty');
    if (emptyEl) emptyEl.style.display = visible === 0 ? 'block' : 'none';
  };

  toggle.addEventListener('click', (e) => {
    e.preventDefault();
    if (wrap.classList.contains('open')) {
      close();
    } else {
      closeAllCombos();
      open();
      showAll();
    }
  });

  input.addEventListener('focus', () => {
    closeAllCombos();
    open();
    showAll();
  });
  input.addEventListener('input', () => {
    open();
    filter();
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  options.forEach((opt) => {
    opt.addEventListener('click', () => {
      input.value = opt.dataset.value || '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      close();
    });
  });
});

document.addEventListener('click', (e) => {
  if (!e.target.closest('.custom-combo')) closeAllCombos();
});

document.addEventListener('click', (e) => {
  const btn = e.target.closest('.combo-option-remove');
  if (!btn) return;
  e.preventDefault();
  e.stopPropagation();

  const campo = btn.getAttribute('data-campo') || '';
  const value = btn.getAttribute('data-value') || '';
  if (!campo || !value) return;

  const ok = confirm('¿Eliminar esta opción de la lista manual?\n' + value);
  if (!ok) return;

  fetch('piv_formulario.php?ajax_remove_options=1', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ campo: campo, quitar: value })
  })
  .then(r => r.text())
  .then(resText => { location.reload(); })
  .catch(() => { location.reload(); });
});

function agregarPalabrasMasivas(campo) {
  let texto = prompt("Ingresa todas las palabras que quieras guardar, separadas por coma:\n(Ejemplo: Opción 1, Opción 2, Opción 3)");
  if (texto && texto.trim() !== "") {
    fetch('piv_formulario.php?ajax_add_options=1', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ campo: campo, nuevas: texto })
    })
    .then(r => r.text())
    .then(resText => {
      if (resText.includes('"success":true') || resText.includes('"success": true')) {
        alert("Palabras agregadas correctamente.");
      }
      location.reload();
    })
    .catch(() => { location.reload(); });
  }
}

function eliminarPalabrasMasivas(campo) {
  let texto = prompt("Ingresa las palabras que quieres eliminar de la lista manual, separadas por coma:\n(Ejemplo: Opción 1, Opción 2)");
  if (texto && texto.trim() !== "") {
    fetch('piv_formulario.php?ajax_remove_options=1', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ campo: campo, quitar: texto })
    })
    .then(r => r.text())
    .then(resText => {
      if (resText.includes('"success":true') || resText.includes('"success": true')) {
        alert("Palabras eliminadas correctamente.");
      }
      location.reload();
    })
    .catch(() => { location.reload(); });
  }
}

// ==========================================
// CATÁLOGO CONDICIONES / EVENTOS PELIGROSOS
// ==========================================
let CATALOGO_CONDICIONES = <?php echo json_encode($catalogo_condiciones, JSON_UNESCAPED_UNICODE); ?>;

const normCond = (v) => (v || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();

function closeAllCondCombos() {
  document.querySelectorAll('.cond-combo.open').forEach(c => c.classList.remove('open'));
}

function initCondCombos() {
  document.querySelectorAll('.cond-combo').forEach(wrap => {
    const input = wrap.querySelector('input[id^="consideracion_"]');
    const toggle = wrap.querySelector('.combo-toggle');
    const menu = wrap.querySelector('.cond-menu');
    const searchBox = menu ? menu.querySelector('.cond-search-box') : null;
    const listContainer = menu ? menu.querySelector('.cond-options-list') : null;
    const emptyMsg = menu ? menu.querySelector('.cond-empty') : null;
    const addBtn = menu ? menu.querySelector('.cond-add-btn') : null;

    if (!input || !toggle || !menu || !listContainer) return;

    function renderOptions(filter) {
      listContainer.innerHTML = '';
      const q = normCond(filter);
      let count = 0;

      CATALOGO_CONDICIONES.forEach((item, idx) => {
        const tNorm = normCond(item.titulo);
        const mNorm = normCond(item.medida);
        if (q && !tNorm.includes(q) && !mNorm.includes(q)) return;

        const row = document.createElement('div');
        row.className = 'cond-option-row';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cond-option';
        btn.dataset.idx = idx;

        const titleSpan = document.createElement('span');
        titleSpan.className = 'cond-title';
        titleSpan.textContent = item.titulo;

        const previewSpan = document.createElement('span');
        previewSpan.className = 'cond-preview';
        previewSpan.textContent = item.medida.substring(0, 80) + (item.medida.length > 80 ? '...' : '');

        btn.appendChild(titleSpan);
        btn.appendChild(previewSpan);
        btn.addEventListener('click', () => {
          input.value = item.titulo + ': ' + item.medida;
          wrap.classList.remove('open');
          input.dispatchEvent(new Event('change'));
        });

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'cond-option-remove';
        removeBtn.title = 'Eliminar esta condición';
        removeBtn.innerHTML = '✕';
        removeBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          eliminarCondicion(item.titulo, wrap);
        });

        row.appendChild(btn);
        row.appendChild(removeBtn);
        listContainer.appendChild(row);
        count++;
      });

      if (emptyMsg) emptyMsg.style.display = count === 0 ? 'block' : 'none';
    }

    function openCombo() {
      closeAllCondCombos();
      wrap.classList.add('open');
      renderOptions('');
      if (searchBox) { searchBox.value = ''; searchBox.focus(); }
    }

    toggle.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      wrap.classList.contains('open') ? wrap.classList.remove('open') : openCombo();
    });

    input.addEventListener('click', () => {
      if (!wrap.classList.contains('open')) openCombo();
    });

    if (searchBox) {
      searchBox.addEventListener('input', () => {
        renderOptions(searchBox.value);
      });
      searchBox.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') wrap.classList.remove('open');
      });
      searchBox.addEventListener('click', (e) => e.stopPropagation());
    }

    if (addBtn) {
      addBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        agregarCondicion(wrap);
      });
    }
  });
}

function agregarCondicion(wrap) {
  const titulo = prompt('Ingresa el TÍTULO de la nueva condición/evento peligroso:\n(Ej: Presencia de cables aéreos)');
  if (!titulo || titulo.trim() === '') return;

  const medida = prompt('Ahora ingresa la MEDIDA DE GESTIÓN para:\n"' + titulo.trim() + '"\n\n(Ej: Mantener distancia mínima de seguridad...)');
  if (!medida || medida.trim() === '') return;

  fetch('piv_formulario.php?ajax_add_condicion=1', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ titulo: titulo.trim(), medida: medida.trim() })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      CATALOGO_CONDICIONES.push({ titulo: titulo.trim(), medida: medida.trim() });
      alert('✅ Condición agregada correctamente.');
      wrap.classList.remove('open');
      setTimeout(() => {
        const toggle = wrap.querySelector('.combo-toggle');
        if (toggle) toggle.click();
      }, 100);
    } else {
      alert('Error: ' + (res.error || 'No se pudo agregar'));
    }
  })
  .catch(() => alert('Error de red al agregar la condición.'));
}

function eliminarCondicion(titulo, wrap) {
  if (!confirm('¿Eliminar esta condición del catálogo?\n\n"' + titulo + '"\n\nEsta acción es permanente.')) return;

  fetch('piv_formulario.php?ajax_remove_condicion=1', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ titulo: titulo })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const tituloNorm = normCond(titulo);
      CATALOGO_CONDICIONES = CATALOGO_CONDICIONES.filter(c => normCond(c.titulo) !== tituloNorm);
      alert('✅ Condición eliminada.');
      wrap.classList.remove('open');
      setTimeout(() => {
        const toggle = wrap.querySelector('.combo-toggle');
        if (toggle) toggle.click();
      }, 100);
    } else {
      alert('Error: ' + (res.error || 'No se pudo eliminar'));
    }
  })
  .catch(() => alert('Error de red al eliminar.'));
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.cond-combo')) closeAllCondCombos();
});

document.querySelectorAll('.cond-combo input[id^="consideracion_"]').forEach(input => {
  input.addEventListener('dblclick', () => {
    input.readOnly = false;
    input.focus();
    input.style.cursor = 'text';
  });
  input.addEventListener('blur', () => {
    input.readOnly = true;
    input.style.cursor = 'pointer';
  });
});

initCondCombos();

// ==========================================
// AUTOGUARDADO EN LOCALSTORAGE
// ==========================================
(function() {
  const MAPA_ID = <?php echo $mapa ? (int)$mapa['id_mapa'] : 0; ?>;
  if (!MAPA_ID) return;

  const STORAGE_KEY = 'piv_borrador_mapa_' + MAPA_ID;
  const EDIT_KEY = editPivId > 0 ? 'piv_edit_' + editPivId : null;
  const KEY = EDIT_KEY || STORAGE_KEY;

  const FIELD_IDS = [
    'codigo_predio', 'predio', 'escenario', 'temporada', 'especie',
    'superficie_ha', 'volumen_total_m3', 'arboles_hora',
    'team_equipo', 'tecnologia', 'asistencia_tipo', 'jefe_faena',
    'tiempo_estimado_dias', 'pendiente_max_pct', 'tipo_suelo',
    'verif_permisos', 'jornada',
    'consideracion_1', 'consideracion_2', 'consideracion_3',
    'consideracion_4', 'consideracion_5', 'consideracion_6', 'consideracion_7',
    'firma_cargo', 'firma_nombre'
  ];

  const SELECT_NAMES = [
    'volteo_cerca_tendido_electrico', 'volteo_cerca_camino_publico',
    'uso_pivotes'
  ];

  function getDestinatarios() {
    const checked = [];
    document.querySelectorAll('input[name="destinatarios[]"]:checked').forEach(cb => {
      checked.push(cb.value);
    });
    return checked;
  }

  function setDestinatarios(ids) {
    if (!Array.isArray(ids)) return;
    document.querySelectorAll('input[name="destinatarios[]"]').forEach(cb => {
      cb.checked = ids.includes(cb.value);
    });
  }

  const MATRIZ_ID = 'tipo_matriz';

  function guardarBorrador() {
    const data = {};

    FIELD_IDS.forEach(id => {
      const el = document.getElementById(id);
      if (el) data[id] = el.value;
    });

    SELECT_NAMES.forEach(name => {
      const el = document.querySelector('select[name="' + name + '"]');
      if (el) data['sel_' + name] = el.value;
    });

    const matrizEl = document.getElementById(MATRIZ_ID);
    if (matrizEl) data[MATRIZ_ID] = matrizEl.value;

    data._destinatarios = getDestinatarios();
    data._guardado_en = new Date().toLocaleString('es-CL');

    try {
      localStorage.setItem(KEY, JSON.stringify(data));
    } catch(e) {
      // localStorage lleno o no disponible, silenciar
    }
  }
  window.guardarBorrador = guardarBorrador;

  function restaurarBorrador() {
    let raw;
    try {
      raw = localStorage.getItem(KEY);
    } catch(e) {
      return false;
    }
    if (!raw) return false;

    let data;
    try {
      data = JSON.parse(raw);
    } catch(e) {
      return false;
    }

    const tieneData = FIELD_IDS.some(id => {
      const value = data[id];
      return typeof value === 'string' && value.trim() !== '';
    });
    if (!tieneData) return false;

    FIELD_IDS.forEach(id => {
      const el = document.getElementById(id);
      const savedValue = data[id];
      if (!el || typeof savedValue !== 'string') return;

      if (el.value.trim() === '' && savedValue.trim() !== '') {
        el.value = savedValue;
      }
    });

    SELECT_NAMES.forEach(name => {
      const el = document.querySelector('select[name="' + name + '"]');
      if (el && data['sel_' + name] !== undefined) {
        el.value = data['sel_' + name];
      }
    });

    const matrizEl = document.getElementById(MATRIZ_ID);
    if (matrizEl && data[MATRIZ_ID]) {
      matrizEl.value = data[MATRIZ_ID];
    }

    if (data._destinatarios && data._destinatarios.length > 0) {
      // Si ya hay un grupo creado, seleccionar los destinatarios en él
      setDestinatarios(data._destinatarios);
      // Actualizar contadores
      document.querySelectorAll('.grupo-card').forEach(card => {
        const gid = card.dataset.grupoId;
        if (gid) updateGrupoCount(parseInt(gid, 10));
      });
    }

    return true;
  }

  window.limpiarBorradorPIV = function() {
    try {
      localStorage.removeItem(KEY);
    } catch(e) {}
  };

  const restaurado = restaurarBorrador();
  if (restaurado) {
    console.log('[PIV] Borrador restaurado desde localStorage');
  }

  function attachListeners() {
    FIELD_IDS.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', guardarBorrador);
      el.addEventListener('change', guardarBorrador);
    });

    SELECT_NAMES.forEach(name => {
      const el = document.querySelector('select[name="' + name + '"]');
      if (el) el.addEventListener('change', guardarBorrador);
    });

    const matrizEl = document.getElementById(MATRIZ_ID);
    if (matrizEl) matrizEl.addEventListener('change', guardarBorrador);

    document.querySelectorAll('input[name="destinatarios[]"]').forEach(cb => {
      cb.addEventListener('change', guardarBorrador);
    });

    document.addEventListener('click', (e) => {
      if (e.target.closest('.btn-clear')) {
        setTimeout(guardarBorrador, 100);
      }
    });
  }

  attachListeners();
})();

<?php if($mapa): ?>
setActive(1);
<?php endif; ?>
</script>

</body>
</html>
