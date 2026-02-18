<?php
session_start();
require_once __DIR__ . '/Config/db_config.php';

if (!isset($_SESSION['id_usuario'])) { header("Location: login.php"); exit; }

// En tu sistema: admin o usuario pueden llenar PIV
$tipo = $_SESSION['tipo_usuario'] ?? 'usuario';
if (!in_array($tipo, ['admin','usuario'], true)) { header("Location: login.php"); exit; }

$id_usuario  = (int)$_SESSION['id_usuario'];
$nombre_user = $_SESSION['nombre_usuario'] ?? '';
$msg = trim($_GET['msg'] ?? '');

$id_mapa = isset($_GET['id_mapa']) ? (int)$_GET['id_mapa'] : 0;

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

// 2) Si no viene id_mapa, permitimos elegir
$zonas = $pdo->query("SELECT id_zona, nombre_zona FROM public.zona ORDER BY nombre_zona ASC")->fetchAll(PDO::FETCH_ASSOC);

$id_zona_sel = isset($_GET['id_zona']) ? (int)$_GET['id_zona'] : 0;
$mapas_zona = [];
if ($id_zona_sel > 0) {
  $st = $pdo->prepare("SELECT id_mapa, nombre_mapa, categoria FROM public.mapa WHERE id_zona=:z ORDER BY nombre_mapa ASC");
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
    .btn{border:0;cursor:pointer;padding:12px 14px;border-radius:12px;font-weight:800}
    .btn-primary{background:#2e7d32;color:#fff}
    .btn-secondary{background:#e0e0e0;color:#1b3a2a}
    .btn-warn{background:#ffecb3;color:#6d4c41}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#f1f5f9;border:1px solid #e2e8f0;font-weight:700}
    .muted{color:#64748b;font-weight:600}
    .divider{height:1px;background:#e8e8e8;margin:16px 0}
    .toggle{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e6e6e6;border-radius:12px;background:#fafafa}
    .toggle input{width:auto}
    /* Responsive */
    @media (max-width:900px){
      .grid{ grid-template-columns: 1fr; }
      .step{min-width:140px}
    }
    .hidden{display:none}
    .field {
      position: relative;
    }
    .btn-clear {
      position: absolute;
      right: 10px;
      top: 36px;
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
          <p class="muted">No llegó un mapa activo. Elige zona y mapa para asociar el PIV.</p>

          <div class="grid">
            <div class="row">
              <label>Zona</label>
              <select onchange="location.href='piv_formulario.php?id_zona='+this.value" required>
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
          <div class="actions" style="justify-content:flex-start;margin-top:10px;">
            <a class="btn btn-secondary" href="piv_aplicar_borrador.php?id_mapa=<?php echo (int)$id_mapa; ?>" onclick="return confirm('Se aplicarán los datos detectados del mapa sobre la ficha actual.\\n¿Deseas continuar?');">
              <i class="fas fa-wand-magic-sparkles"></i> Aplicar datos detectados del mapa
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
              Este bloque se autocompleta desde la <b>Ficha del Escenario</b>.
              <?php if(!$ficha): ?>
                <span style="color:#b45309;font-weight:800;">No existe ficha aún: créala una vez.</span>
              <?php endif; ?>
            </p>

            <form method="POST" action="piv_guardar_ficha.php" id="formFicha">
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
                  <label>VMA (m3/arb)</label>
                  <input id="vma_m3_arb" name="vma_m3_arb" inputmode="decimal" value="<?php echo h($ficha['vma_m3_arb'] ?? ''); ?>" placeholder="Ej: 0.65">
                  <button type="button" class="btn-clear" data-clear="vma_m3_arb" title="Limpiar"><i class="fas fa-eraser"></i></button>
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
                  <input id="team_equipo" name="team_equipo" value="<?php echo h($ficha['team_equipo'] ?? ''); ?>" placeholder="Ej: Millalemu 7 - HM05">
                  <button type="button" class="btn-clear" data-clear="team_equipo" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>
                <div class="row field">
                  <label>Tecnología</label>
                  <input id="tecnologia" name="tecnologia" value="<?php echo h($ficha['tecnologia'] ?? ''); ?>" placeholder="Ej: Shovel">
                  <button type="button" class="btn-clear" data-clear="tecnologia" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>

                <div class="row field">
                  <label>Asistencia / Tipo</label>
                  <input id="asistencia_tipo" name="asistencia_tipo" value="<?php echo h($ficha['asistencia_tipo'] ?? ''); ?>" placeholder="Ej: Falcon">
                  <button type="button" class="btn-clear" data-clear="asistencia_tipo" title="Limpiar"><i class="fas fa-eraser"></i></button>
                </div>
                <div class="row field">
                  <label>Jefe de faena</label>
                  <input id="jefe_faena" name="jefe_faena" value="<?php echo h($ficha['jefe_faena'] ?? ''); ?>" placeholder="Ej: Juan Sepúlveda...">
                  <button type="button" class="btn-clear" data-clear="jefe_faena" title="Limpiar"><i class="fas fa-eraser"></i></button>
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
                  <input id="tipo_suelo" name="tipo_suelo" value="<?php echo h($ficha['tipo_suelo'] ?? ''); ?>" placeholder="Ej: Predio con presencia de rocas">
                  <button type="button" class="btn-clear" data-clear="tipo_suelo" title="Limpiar"><i class="fas fa-eraser"></i></button>
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

              <div class="actions">
                <button class="btn btn-warn" type="submit"><i class="fas fa-save"></i> Guardar/Actualizar ficha</button>
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

            <div class="row field">
              <label>Observaciones</label>
              <textarea id="obs" placeholder="Notas generales del PIV..."></textarea>
              <button type="button" class="btn-clear" data-clear="obs" title="Limpiar"><i class="fas fa-eraser"></i></button>
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
              <div class="row">
                <label>Confirmación</label>
                <div class="toggle">
                  <input type="checkbox" id="declaro">
                  <div><b>Confirmar datos</b></div>
                </div>
              </div>
            </div>

            <div class="actions">
              <button class="btn btn-secondary" type="button" onclick="nextStep(2)">Atrás</button>
              <button class="btn btn-primary" type="button" onclick="guardarPIV()">
                <i class="fas fa-check"></i> Guardar PIV
              </button>
            </div>
            <div class="actions hidden" id="pdfActions">
              <a class="btn btn-primary" id="btnPdf" href="#" target="_blank">Descargar PDF</a>
              <a class="btn btn-secondary" id="btnPdfDebug" href="#" target="_blank">Debug PDF</a>
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
  location.href = 'piv_formulario.php?id_mapa=' + id;
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
  if(!document.getElementById('declaro').checked){
    alert("Debes marcar la declaración de conocimiento.");
    return;
  }

  const consideraciones = [
    document.getElementById('consideracion_1').value,
    document.getElementById('consideracion_2').value,
    document.getElementById('consideracion_3').value,
    document.getElementById('consideracion_4').value
  ].map(v => v.trim()).filter(Boolean).join('\n');

  const payload = {
    id_mapa: <?php echo $mapa ? (int)$mapa['id_mapa'] : 0; ?>,
    fecha: document.getElementById('fecha').value,

    consideraciones: consideraciones,
    observaciones: document.getElementById('obs').value,

    firma_cargo: document.getElementById('firma_cargo').value,
    firma_nombre: document.getElementById('firma_nombre').value
  };

  fetch('piv_guardar.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      alert("✅ PIV guardado correctamente.");
      const idPiv = Number(res.id_piv || 0);
      if (!idPiv) {
        alert("Se guardó, pero no llegó id_piv para descarga PDF.");
        return;
      }
      ultimoIdPiv = idPiv;
      const pdf = 'piv_pdf_v2.php?id_piv=' + idPiv;
      const pdfDebug = pdf + '&debug=1';
      document.getElementById('btnPdf').href = pdf;
      document.getElementById('btnPdfDebug').href = pdfDebug;
      document.getElementById('pdfActions').classList.remove('hidden');
    } else {
      alert("Error: " + (res.error || 'No se pudo guardar'));
    }
  })
  .catch(()=>alert("Error de red al guardar PIV."));
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

<?php if($mapa): ?>
setActive(1);
<?php endif; ?>
</script>

</body>
</html>
