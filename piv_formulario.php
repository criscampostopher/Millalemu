﻿﻿<?php
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

// En tu sistema: admin o usuario pueden llenar PIV
$tipo = $_SESSION['tipo_usuario'] ?? 'usuario';
if (!in_array($tipo, ['admin','usuario'], true)) { header("Location: login.php"); exit; }

$id_usuario  = (int)$_SESSION['id_usuario'];
$nombre_user = $_SESSION['nombre_usuario'] ?? '';
$msg = trim($_GET['msg'] ?? '');

$id_mapa = isset($_GET['id_mapa']) ? (int)$_GET['id_mapa'] : 0;
$captura_src = isset($_GET['captura_src']) ? (int)$_GET['captura_src'] : 0;

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
$zonas = $pdo->query("SELECT id_zona, nombre_zona FROM public.zona ORDER BY nombre_zona ASC")->fetchAll(PDO::FETCH_ASSOC);

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

// --- INICIO: Cargar opciones (Historial BD + Palabras Manuales) ---
$opciones_combo = ['team_equipo'=>[], 'tecnologia'=>[], 'asistencia_tipo'=>[], 'jefe_faena'=>[], 'tipo_suelo'=>[]];
$opciones_manuales = ['team_equipo'=>[], 'tecnologia'=>[], 'asistencia_tipo'=>[], 'jefe_faena'=>[], 'tipo_suelo'=>[]];

foreach (array_keys($opciones_combo) as $campo) {
  $stOpt = $pdo->query("SELECT DISTINCT $campo FROM public.piv_ficha WHERE $campo IS NOT NULL AND trim($campo) != ''");
  $raw_values = $stOpt ? $stOpt->fetchAll(PDO::FETCH_COLUMN) : [];
  foreach ($raw_values as $val) {
    $partes = explode(',', (string)$val);
    foreach ($partes as $p) {
      $p = trim($p);
      if ($p !== '' && !in_array($p, $opciones_combo[$campo], true)) $opciones_combo[$campo][] = $p;
    }
  }
}

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
    .btn-warn{background:#ffecb3;color:#6d4c41}
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

          <!-- PASO 1: DATOS (Ficha + Acta) -->
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
                  <label>Tecnología</label>
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
              


<div class="dest-section">
    <h3 class="dest-title">4. Destinatarios del PIV (Firmas requeridas)</h3>
    <div class="dest-toolbar">
        <i class="fas fa-search dest-search-icon"></i>
        <input type="text" id="destSearch" class="dest-search" placeholder="Buscar destinatario por nombre o rol...">
    </div>
    <div class="dest-list">
        <?php
        $mi_id = (int)($_SESSION['id_usuario'] ?? 0);
        $sql_users = "SELECT id_usuario, nombre_usuario, tipo_usuario FROM public.usuario WHERE id_usuario != :id ORDER BY nombre_usuario ASC";
        try {
            $stmt = $pdo->prepare($sql_users);
            $stmt->execute([':id' => $mi_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int)$row['id_usuario'];
                $name = htmlspecialchars((string)$row['nombre_usuario'], ENT_QUOTES, 'UTF-8');
                $role = htmlspecialchars((string)$row['tipo_usuario'], ENT_QUOTES, 'UTF-8');
                $search = strtolower(trim((string)$row['nombre_usuario'] . ' ' . (string)$row['tipo_usuario']));
                echo '<div class="dest-item" data-search="' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '">';
                echo '<input type="checkbox" name="destinatarios[]" value="' . $uid . '" id="user_' . $uid . '">';
                echo '<label class="dest-label" for="user_' . $uid . '">';
                echo '<span>' . $name . '</span><span class="dest-role">' . $role . '</span>';
                echo '</label>';
                echo '</div>';
            }
        } catch (PDOException $e) {
            echo '<div class="dest-item"><span>No se pudieron cargar los usuarios.</span></div>';
        }
        ?>
    </div>
    <div id="destEmpty" class="dest-empty">No hay destinatarios que coincidan con la búsqueda.</div>
    <small class="dest-help">Seleccione los usuarios que deben firmar este documento.</small>
</div>
<br>

              <div class="actions actions-step1">
                <button class="btn btn-secondary" type="button" onclick="limpiarFormulario()"><i class="fas fa-eraser"></i> Limpiar formulario</button>
                <button class="btn btn-primary" type="button" onclick="nextStep(2)">Siguiente</button>
              </div>
            </form>

            <div class="divider"></div>

            <div class="grid">
              <div class="row">
                <label>Fecha (del día)</label>
                <input id="fecha" type="date" value="<?php echo date('Y-m-d'); ?>">
              </div>
            </div>
          </div>

          <!-- PASO 2: CONSIDERACIONES (4 CAMPOS) -->
          <div id="paso2" class="hidden">
            <p class="muted"><b>Consideraciones importantes y medidas</b> (4 campos)</p>

            <div class="row field">
              <label>Consideraciones importantes y medidas</label>
              <input id="consideracion_1" placeholder="1) Ej: Presencia de rocas: evitar tránsito por rocas...">
              <button type="button" class="btn-clear" data-clear="consideracion_1" title="Limpiar"><i class="fas fa-eraser"></i></button>
            </div>
            <div class="row field">
              <label>&nbsp;</label>
              <input id="consideracion_2" placeholder="2) Ej: Bosque nativo: demarcar buffer 5-10 m...">
              <button type="button" class="btn-clear" data-clear="consideracion_2" title="Limpiar"><i class="fas fa-eraser"></i></button>
            </div>
            <div class="row field">
              <label>&nbsp;</label>
              <input id="consideracion_3" placeholder="3) Ej: Microrelieves: usar plano georreferenciado...">
              <button type="button" class="btn-clear" data-clear="consideracion_3" title="Limpiar"><i class="fas fa-eraser"></i></button>
            </div>
            <div class="row field">
              <label>&nbsp;</label>
              <input id="consideracion_4" placeholder="4) Ej: Orilla de camino: comunicación efectiva...">
              <button type="button" class="btn-clear" data-clear="consideracion_4" title="Limpiar"><i class="fas fa-eraser"></i></button>
            </div>

            <div class="divider"></div>
            <p class="muted"><b>Planos / Mapas (Opcionales)</b></p>
            <?php
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
              $msgInicial = 'No hay capturas cargadas aun para este mapa.';
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

            <div class="divider"></div>

            <div class="row field">
              <label>Observación 1</label>
              <textarea id="obs_1" placeholder="Notas generales del PIV (1)..."></textarea>
              <button type="button" class="btn-clear" data-clear="obs_1" title="Limpiar"><i class="fas fa-eraser"></i></button>
            </div>
            <div class="row field">
              <label>Observación 2</label>
              <textarea id="obs_2" placeholder="Notas generales del PIV (2)..."></textarea>
              <button type="button" class="btn-clear" data-clear="obs_2" title="Limpiar"><i class="fas fa-eraser"></i></button>
            </div>

            <div class="actions">
              <button class="btn btn-secondary" type="button" onclick="nextStep(1)">Atrás</button>
              <button class="btn btn-primary" type="button" onclick="nextStep(3)">Siguiente</button>
            </div>
          </div>

          <!-- PASO 3: FIRMA + GUARDAR PIV -->
          <div id="paso3" class="hidden">
            <p class="muted"><b>CONFIRMAR DATOS</b></p>

            <div class="grid">
              <div class="row">
                <label>Cargo</label>
                <input id="firma_cargo" placeholder="Ej: Supervisor / Operador">
              </div>
              <div class="row">
                <label>Nombre</label>
                <input id="firma_nombre" value="<?php echo h($nombre_user); ?>" placeholder="Nombre">
              </div>
            </div>

            <div class="actions">
              <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <button class="btn btn-secondary" type="button" onclick="nextStep(2)">Atrás</button>
                <a class="btn btn-secondary" href="menuadmin.php"><i class="fas fa-house"></i> Volver al inicio</a>
              </div>
              <button class="btn btn-primary" type="button" onclick="guardarPIV()">
                <i class="fas fa-check"></i> Guardar PIV
              </button>
            </div>
            <div class="actions hidden" id="pdfActions">
              <a class="btn btn-primary" id="btnPdf" href="#" target="_blank">Descargar PDF</a>
            </div>

          </div>

        <?php endif; ?>
      </div>
    </div>
  </main>

<script>
let ultimoIdPiv = 0;

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
    alert('Debes seleccionar al menos un destinatario en "Destinatarios del PIV (Firmas requeridas)".');
    setActive(1);
    const destSearch = document.getElementById('destSearch');
    if (destSearch) destSearch.focus();
    return;
  }

  // 3. Juntar las consideraciones
  const consideraciones = [
    document.getElementById('consideracion_1').value,
    document.getElementById('consideracion_2').value,
    document.getElementById('consideracion_3').value,
    document.getElementById('consideracion_4').value
  ].map(v => v.trim()).filter(Boolean).join('\n');
  const observaciones = [
    document.getElementById('obs_1').value,
    document.getElementById('obs_2').value
  ].map(v => v.trim()).filter(Boolean).join('\n');

  // 4. Crear el objeto de datos a enviar
  const payload = {
    // Datos básicos (esto ya estaba bien)
    id_mapa: <?php echo $mapa ? (int)$mapa['id_mapa'] : 0; ?>,
    fecha: document.getElementById('fecha').value,
    consideraciones: consideraciones,
    observaciones: observaciones,
    firma_cargo: firmaCargo,
    firma_nombre: firmaNombre,
    
    // Agregamos la lista de destinatarios al JSON
    destinatarios: destinatariosSeleccionados
  };

  // 5. Guardar ficha primero y luego guardar PIV
  const formFicha = document.getElementById('formFicha');
  const formData = new FormData(formFicha);
  
  // --- NUEVO: Capturar las imagenes que estan en el Paso 2 ---
  const fileInput1 = document.getElementById('imagen_plano');
  if (fileInput1 && fileInput1.files.length > 0) {
      formData.append('imagen_plano', fileInput1.files[0]);
  }
  const fileInput2 = document.getElementById('imagen_plano_2');
  if (fileInput2 && fileInput2.files.length > 0) {
      formData.append('imagen_plano_2', fileInput2.files[0]);
  }

  // --- NUEVO: Capturar textos de los mapas ---
  const comp1 = document.getElementById('comentario_plano_1');
  if (comp1) formData.append('comentario_plano_1', comp1.value);

  const comp2 = document.getElementById('comentario_plano_2');
  if (comp2) formData.append('comentario_plano_2', comp2.value);
  // -------------------------------------------
  // -------------------------------------------------------

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
  });
}

function enviarDatosAlServidor(payload){
  fetch('piv_guardar.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      alert("✅ Ficha y PIV guardados correctamente. Notificaciones enviadas.");
      const idPiv = Number(res.id_piv || 0);
      
      if (!idPiv) {
        // Si se guardó pero no devolvió ID, avisamos pero no bloqueamos
        console.warn("Se guardó, pero no llegó id_piv para descarga PDF.");
      } else {
        ultimoIdPiv = idPiv;
        // Configurar botones de PDF
        const pdf = 'piv_pdf_v2.php?id_piv=' + idPiv;
        document.getElementById('btnPdf').href = pdf;
        document.getElementById('pdfActions').classList.remove('hidden');
      }
    } else {
      alert("Error: " + (res.error || 'No se pudo guardar'));
    }
  })
  .catch((err)=>{
    console.error(err);
    alert("Error de red al guardar PIV.");
  });
}

function limpiarFormulario() {
  if (!confirm('Esto limpiará todos los campos del formulario de ficha.\n¿Deseas continuar?')) {
    return;
  }

  // Limpia inputs y textareas
  document.querySelectorAll(
    '#paso1 input:not([type=hidden]), #paso1 textarea'
  ).forEach(el => {
    if (el.type === 'checkbox' || el.type === 'radio') {
      el.checked = false;
    } else {
      el.value = '';
    }
  });

  // Limpia selects
  document.querySelectorAll('#paso1 select').forEach(sel => {
    sel.selectedIndex = 0;
  });

  alert('Formulario limpiado.');
}

// Limpia un campo por ID (solo texto/numero/textarea/select)
function clearFieldById(id) {
  const el = document.getElementById(id);
  if (!el) return;

  // NO limpiar booleanos ni fecha
  if (el.type === 'date') return;
  if (el.type === 'checkbox' || el.type === 'radio') return;

  // Si es select, lo resetea
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

// Delegacion para botones de limpieza por campo
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

const destSearch = document.getElementById('destSearch');
const destEmpty = document.getElementById('destEmpty');
if (destSearch) {
  const normalize = (v) => (v || '')
    .toString()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim();

  destSearch.addEventListener('input', () => {
    const q = normalize(destSearch.value);
    const items = Array.from(document.querySelectorAll('.dest-item[data-search]'));
    let shown = 0;

    items.forEach((item) => {
      const text = normalize(item.getAttribute('data-search'));
      const ok = !q || text.includes(q);
      item.style.display = ok ? 'flex' : 'none';
      if (ok) shown++;
    });

    if (destEmpty) destEmpty.style.display = shown === 0 ? 'block' : 'none';
  });
}

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
    if (text) text.textContent = 'No hay capturas cargadas aun para este mapa.';
  }
}

const fileStatus1 = document.getElementById('imagen_plano');
const fileStatus2 = document.getElementById('imagen_plano_2');
if (fileStatus1) fileStatus1.addEventListener('change', updateCaptureStatusPill);
if (fileStatus2) fileStatus2.addEventListener('change', updateCaptureStatusPill);
updateCaptureStatusPill();

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
  .then(r => r.json())
  .then(res => {
    if (res.success) location.reload();
    else alert('No se pudo eliminar la opción.');
  })
  .catch(() => alert('Error de red al eliminar opción.'));
});

function agregarPalabrasMasivas(campo) {
  let texto = prompt("Ingresa todas las palabras que quieras guardar, separadas por coma:\n(Ejemplo: Opción 1, Opción 2, Opción 3)");
  if (texto && texto.trim() !== "") {
    fetch('piv_formulario.php?ajax_add_options=1', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ campo: campo, nuevas: texto })
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        alert("Palabras agregadas correctamente.");
        location.reload();
      } else {
        alert("Hubo un error al guardar las palabras.");
      }
    })
    .catch(() => alert("Error de red al guardar palabras."));
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
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        alert("Palabras eliminadas correctamente.");
        location.reload();
      } else {
        alert("Hubo un error al eliminar palabras.");
      }
    })
    .catch(() => alert("Error de red al eliminar palabras."));
  }
}

<?php if($mapa): ?>
setActive(1);
<?php endif; ?>
</script>

</body>
</html>
