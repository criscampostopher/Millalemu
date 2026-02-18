<?php
session_start();
require_once __DIR__ . '/Config/db_config.php';

if (!isset($_SESSION['id_usuario'])) { header("Location: login.php"); exit; }

$tipo = $_SESSION['tipo_usuario'] ?? 'usuario';
if (!in_array($tipo, ['admin','usuario'], true)) { header("Location: login.php"); exit; }

$nombre_user = $_SESSION['nombre_usuario'] ?? '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PIV Enviadas</title>
  <link rel="stylesheet" href="style-admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .wrap{max-width:980px;margin:0 auto;}
    .card{
      background:rgba(255,255,255,0.96);
      border-radius:14px;
      padding:18px;
      color:#1b3a2a;
      box-shadow:0 8px 20px rgba(0,0,0,.15);
      text-align:left;
    }
    .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#f1f5f9;border:1px solid #e2e8f0;font-weight:700}
    .muted{color:#64748b;font-weight:600}
    .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{border:0;cursor:pointer;padding:10px 12px;border-radius:12px;font-weight:900;}
    .btn-primary{background:#2e7d32;color:#fff}
    .btn-secondary{background:#e2e8f0;color:#0f172a}

    .inbox-card{
      border:1px solid rgba(226,232,240,.9);
      border-radius:16px;
      background:rgba(255,255,255,.95);
      padding:14px;
      margin:12px 0;
      box-shadow:0 8px 18px rgba(15,23,42,.06);
    }
    .inbox-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
    .inbox-title{display:flex;flex-direction:column;gap:6px;}
    .inbox-title .line1{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .tag{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid rgba(226,232,240,.9);background:#f8fafc;font-weight:800;color:#0f172a;}
    .tag.muted{font-weight:700;color:#475569;background:#f1f5f9;}
    .badge-ok{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20;font-weight:900;}
    .inbox-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
  </style>
</head>
<body>

  <aside class="sidebar">
    <h2 style="text-align:center; padding:10px; color:#fdd835;">Millalemu</h2>
    <a href="menuadmin.php"><i class="fas fa-home"></i> Inicio</a>
    <a href="index.php"><i class="fas fa-eye"></i> <b>Visor Global</b></a>
    <a href="mapas.php"><i class="fas fa-layer-group"></i> Gestion de Mapas</a>
    <a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a>
    <a href="piv_formulario.php" class="piv-btn"><i class="fas fa-clipboard-list"></i> PIV Formulario</a>
    <div style="margin-top:auto; padding-bottom:20px;">
      <a href="logout.php" style="color:#ef5350;"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </div>
  </aside>

  <main class="main">
    <div class="wrap">
      <h1>PIV Enviadas</h1>

      <div class="card">
        <div class="pill"><i class="fas fa-user"></i> Usuario: <span><?php echo h($nombre_user); ?></span></div>
        <p class="muted" style="margin-top:10px;">Aqui veras las PIV que tu enviaste.</p>

        <div id="lista"></div>

        <div class="actions" style="margin-top:12px;">
          <button class="btn btn-secondary" type="button" onclick="cargarEnviadas()">
            <i class="fas fa-rotate"></i> Actualizar
          </button>
          <a class="btn btn-secondary" href="menuadmin.php"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
      </div>
    </div>
  </main>

<script>
function fmtFecha(s){
  if(!s) return '';
  return String(s).replace('T',' ').slice(0,16);
}

function escapeHtml(str){
  return String(str)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}

function cargarEnviadas(){
  const cont = document.getElementById('lista');
  cont.innerHTML = '<p class="muted">Cargando...</p>';

  fetch('Api/api_piv_enviadas.php')
    .then(r=>r.json())
    .then(data=>{
      if(!data.success){
        cont.innerHTML = '<p class="muted">Error: '+(data.error||'No se pudo cargar')+'</p>';
        return;
      }

      const items = data.items || [];
      if(items.length === 0){
        cont.innerHTML = '<p class="muted">No tienes PIV enviadas.</p>';
        return;
      }

      cont.innerHTML = '';
      items.forEach(it=>{
        const div = document.createElement('div');
        div.className = 'inbox-card';

        const fecha = fmtFecha(it.creado_en || '');
        const pdfUrl = 'piv_pdf_v2.php?id_piv=' + encodeURIComponent(it.id_piv);

        div.innerHTML = `
          <div class="inbox-head">
            <div class="inbox-title">
              <div class="line1">
                <span class="tag"><i class="fas fa-paper-plane"></i> Para: <b>${escapeHtml(it.para_nombre || '')}</b></span>
                <span class="tag muted"><i class="fas fa-calendar"></i> ${escapeHtml(fecha)}</span>
                <span class="badge-ok"><i class="fas fa-check"></i> Enviada</span>
              </div>
            </div>

            <div class="inbox-actions">
              <a class="btn btn-primary" href="${pdfUrl}" target="_blank">
                <i class="fas fa-file-pdf"></i> Abrir PDF
              </a>
            </div>
          </div>
        `;
        cont.appendChild(div);
      });
    })
    .catch(()=>{
      cont.innerHTML = '<p class="muted">Error de red al cargar.</p>';
    });
}

cargarEnviadas();
</script>

</body>
</html>
