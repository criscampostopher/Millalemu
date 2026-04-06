<?php
// ==========================================================
// Archivo: menu_usuario.php (Bloqueo Estricto por Defecto y Modo Offline)
// ==========================================================
session_start();

// Evitar cacheo
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Verificar Sesión
if (!isset($_SESSION['id_usuario'])) { 
    header("Location: login.php"); 
    exit; 
}

require_once __DIR__ . '/Config/db_config.php'; 
require_once __DIR__ . '/Config/roles.php';

$id_usuario = $_SESSION['id_usuario'];
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Operador';
$tipo_usuario_actual = normalizarRolUsuario($_SESSION['tipo_usuario'] ?? 'usuario'); // Para saber si es jefe u operador
$mapas = [];
$pivs_pendientes = []; 
$pivs_historial = []; 
$error = "";
$pdf_cache_bust = time();

// Perfil especial con revisión libre de la escala jerárquica
$es_supervisor_lectura = esUsuarioEmilianoMachuca($id_usuario);

// Array para guardar qué mapas YA ESTÁN FIRMADOS (Nuestra "Lista Blanca")
$mapas_aprobados_ids = []; 

try {
    // 1. CARGAR MAPAS ASIGNADOS (Solo Actas)
    $sql_mapas = "SELECT m.* FROM public.mapa m
            JOIN public.zona z ON m.id_zona = z.id_zona
            JOIN public.usuario_zona uz ON z.id_zona = uz.id_zona
            WHERE uz.id_usuario = ?
            AND (uz.fecha_inicio <= NOW())
            AND (uz.fecha_fin IS NULL OR uz.fecha_fin >= NOW())
            AND LOWER(m.categoria) = 'acta'
            ORDER BY m.fecha_creacion DESC";

    $stmt = $pdo->prepare($sql_mapas);
    $stmt->execute([$id_usuario]);
    $mapas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. CARGAR PIVs PENDIENTES DE FIRMA
    $sql_piv = "SELECT e.id_envio, p.id_piv, p.fecha, m.nombre_mapa, u.nombre_usuario as remitente, e.estado, p.id_mapa
                FROM public.piv_envio e
                JOIN public.piv p ON e.id_piv = p.id_piv
                LEFT JOIN public.mapa m ON p.id_mapa = m.id_mapa
                JOIN public.usuario u ON e.de_usuario = u.id_usuario
                WHERE e.para_usuario = ? 
                AND e.estado IN ('enviado', 'visto')
                ORDER BY p.fecha DESC";
    
    $stmt_piv = $pdo->prepare($sql_piv);
    $stmt_piv->execute([$id_usuario]);
    $pivs_pendientes = $stmt_piv->fetchAll(PDO::FETCH_ASSOC);

    // 3. CARGAR HISTORIAL DE PIVs (AQUÍ AGREGAMOS p.id_mapa PARA SABER CUÁLES YA FIRMÓ)
    $sql_historial = "SELECT e.id_envio, p.id_piv, p.fecha, m.nombre_mapa, f.predio, f.escenario, u.nombre_usuario as remitente, e.estado, e.fecha_respuesta, p.id_mapa
                FROM public.piv_envio e
                JOIN public.piv p ON e.id_piv = p.id_piv
                LEFT JOIN public.mapa m ON p.id_mapa = m.id_mapa
                LEFT JOIN public.piv_ficha f ON p.id_ficha = f.id_ficha
                JOIN public.usuario u ON e.de_usuario = u.id_usuario
                WHERE e.para_usuario = ?
                AND e.estado IN ('aprobado', 'rechazado')
                ORDER BY e.fecha_respuesta DESC";
    
    $stmt_hist = $pdo->prepare($sql_historial);
    $stmt_hist->execute([$id_usuario]);
    $pivs_historial = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================
    // LÓGICA: CREAR LA LISTA BLANCA DE MAPAS DESBLOQUEADOS
    // =========================================================
    // Recorremos el historial. Si el documento está "aprobado", guardamos el ID de ese mapa.
    foreach ($pivs_historial as $h) {
        if ($h['estado'] === 'aprobado' && !empty($h['id_mapa'])) {
            $mapas_aprobados_ids[] = $h['id_mapa'];
        }
    }

} catch (PDOException $e) {
    die("Error SQL real: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Operaciones - Millalemu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#2e7d32">
    
    <link rel="stylesheet" href="style_usuario.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .piv-container { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border-left: 5px solid #e67e22; }
        .piv-header { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; color: #d35400; font-weight: bold; font-size: 1.1rem; }
        .piv-item { background: #fdfefe; border: 1px solid #eee; border-radius: 8px; padding: 15px; margin-bottom: 10px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; }
        .piv-info strong { color: #2c3e50; display: block; }
        .piv-info span { color: #7f8c8d; font-size: 0.9rem; }
        
        .piv-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn-sign { background: #27ae60; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-sign:hover { background: #219150; }
        .btn-reject { background: #c0392b; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s; }
        .btn-reject:hover { background: #a93226; }

        .msg-box { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; text-align: center; }
        .msg-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-warn { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }

        .btn-pdf { background: #3498db; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-pdf:hover { background: #2980b9; }

        .btn-historial { background: #34495e; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; margin-right: 15px; }
        .btn-historial:hover { background: #2c3e50; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: #fff; border-radius: 12px; width: 95%; max-width: 900px; max-height: 80vh; overflow-y: auto; padding: 25px; position: relative; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .btn-close-modal { position: absolute; top: 15px; right: 20px; background: none; border: none; font-size: 1.5rem; color: #7f8c8d; cursor: pointer; transition: 0.2s; }
        .btn-close-modal:hover { color: #e74c3c; transform: scale(1.1); }
        
        .historial-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .historial-table th, .historial-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .historial-table th { background-color: #f8f9fa; color: #2c3e50; font-weight: bold; position: sticky; top: 0; }
        .badge { padding: 5px 10px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; color: white; }
        .badge-aprobado { background: #27ae60; }
        .badge-rechazado { background: #c0392b; }

        /* Estilo para tarjetas bloqueadas */
        .map-card-bloqueada {
            border-left: 5px solid #e74c3c !important;
            background: #fdf2f2 !important;
            opacity: 0.85;
        }
        .btn-bloqueado {
            background:#bdc3c7; cursor:not-allowed; border:none; padding:10px 15px; 
            border-radius:6px; color:white; font-weight:bold; width: 100%; text-align: center;
        }
    </style>
</head>
<body>

<div class="leaves-container">
    <div class="leaf"></div><div class="leaf"></div>
    <div class="leaf"></div><div class="leaf"></div>
</div>

<div class="container">

    <div class="header-bar">
        <div>
            <h2><i class="fas fa-map-marked-alt"></i> Panel de Operaciones</h2>
            <div class="user-welcome">
                Bienvenido, <b><?= htmlspecialchars($nombre_usuario) ?></b>
            </div>
        </div>
        <div style="display: flex; align-items: center;">
            <?php if (!empty($pivs_historial)): ?>
                <button onclick="abrirHistorial()" class="btn-historial">
                    <i class="fas fa-history"></i> Historial
                </button>
            <?php endif; ?>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'firmado_ok'): ?>
            <div class="msg-box msg-success"><i class="fas fa-check-circle"></i> Documento procesado correctamente.</div>
        <?php elseif ($_GET['msg'] == 'observacion_guardada'): ?>
            <div class="msg-box msg-warn"><i class="fas fa-exclamation-triangle"></i> Observación guardada.</div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if(!empty($error)): ?>
        <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
    <?php endif; ?>


    <?php if (!empty($pivs_pendientes)): ?>
        <div class="piv-container">
            <div class="piv-header">
                <i class="fas fa-file-signature fa-lg"></i> Documentos Pendientes
            </div>
            <p style="margin-bottom: 15px; color: #666; font-size: 0.9rem;">
                Tienes los siguientes documentos asignados para tu revisión o firma.
            </p>

            <?php foreach ($pivs_pendientes as $piv): ?>
                <div class="piv-item">
                    <div class="piv-info">
                        <strong>Documento #<?= $piv['id_piv'] ?> - <?= htmlspecialchars($piv['nombre_mapa']) ?></strong>
                        <span>Enviado por: <?= htmlspecialchars($piv['remitente']) ?></span><br>
                    </div>
                    
                    <div class="piv-actions">
                        <a href="piv_pdf_v2.php?id_piv=<?= $piv['id_piv'] ?>&v=<?= $pdf_cache_bust ?>" target="_blank" class="btn-pdf" title="Ver documento original">
                            <i class="fas fa-file-pdf"></i> Ver PIV
                        </a>

                        <?php if ($es_supervisor_lectura): ?>
                            <!-- BOTONES PARA EMILIANO (SUPERVISOR LECTURA) -->
                            <form action="procesar_firma.php" method="POST" style="margin:0; display:flex; gap:10px;">
                                <input type="hidden" name="id_envio" value="<?= $piv['id_envio'] ?>">
                                <input type="hidden" name="id_piv" value="<?= $piv['id_piv'] ?>">
                                <input type="hidden" name="accion" id="act_<?= $piv['id_envio'] ?>">
                                <input type="hidden" name="observacion" id="obs_<?= $piv['id_envio'] ?>">

                                <button type="button" class="btn-sign" style="background: #34495e;" onclick="marcarLeido(<?= $piv['id_envio'] ?>)">
                                    <i class="fas fa-check-double"></i> Visto
                                </button>
                                <button type="button" class="btn-reject" onclick="rechazar(<?= $piv['id_envio'] ?>)">
                                    <i class="fas fa-exclamation-circle"></i> Observar
                                </button>
                            </form>
                        <?php else: ?>
                            <!-- BOTONES NORMALES (OPERADORES Y JEFE FAENA) -->
                            <?php
                              // Cadena jerárquica de firmas dentro del grupo
                              $rol_bloqueante = obtenerRolBloqueanteFirmaPiv($pdo, (int)$piv['id_piv'], (int)$id_usuario);
                              $bloqueado_por_jerarquia = $rol_bloqueante !== null;
                              $nombre_bloqueante = $rol_bloqueante ? nombreVisibleRol($rol_bloqueante) : '';
                            ?>

                            <?php if ($bloqueado_por_jerarquia): ?>
                                <button class="btn-bloqueado" disabled style="width:auto;">
                                    <i class="fas fa-lock"></i> Esperando firma de <?= htmlspecialchars($nombre_bloqueante) ?>
                                </button>
                            <?php else: ?>
                                <form action="procesar_firma.php" method="POST" style="margin:0; display:flex; gap:10px;">
                                    <input type="hidden" name="id_envio" value="<?= $piv['id_envio'] ?>">
                                    <input type="hidden" name="id_piv" value="<?= $piv['id_piv'] ?>">
                                    <input type="hidden" name="accion" id="act_<?= $piv['id_envio'] ?>">
                                    <input type="hidden" name="observacion" id="obs_<?= $piv['id_envio'] ?>">

                                    <button type="button" class="btn-sign" onclick="firmar(<?= $piv['id_envio'] ?>)">
                                        <i class="fas fa-check"></i> Firmar
                                    </button>
                                    <button type="button" class="btn-reject" onclick="rechazar(<?= $piv['id_envio'] ?>)">
                                        <i class="fas fa-times"></i> Observar
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


    <?php if (!empty($mapas)): ?>
        <h3 style="color:#f39c12; margin-bottom:15px;"><i class="fas fa-layer-group"></i> Tus Predios Asignados</h3>
        
        <div class="grid-mapas">
            <?php foreach ($mapas as $m): ?>
                <?php 
                    // REGLA ESTRICTA: Asumimos que TODOS los mapas están bloqueados
                    $esta_bloqueado = true; 
                    
                    // Si el usuario es Emiliano (Supervisor) o si es el Jefe de Faena, NO se bloquean
                    if ($es_supervisor_lectura || $tipo_usuario_actual !== 'usuario') {
                        $esta_bloqueado = false;
                    } else {
                        // Si el mapa SÍ aparece en la lista blanca de "Mapas Aprobados", lo desbloqueamos
                        if (in_array($m['id_mapa'], $mapas_aprobados_ids)) {
                            $esta_bloqueado = false;
                        }
                    }
                ?>
                
                <div class="map-card <?= $esta_bloqueado ? 'map-card-bloqueada' : '' ?>">
                    <div>
                        <div class="map-title"><?= htmlspecialchars($m["nombre_mapa"]) ?></div>
                        <div class="map-type"><?= htmlspecialchars($m["tipo_mapa"]) ?></div>
                        
                        <?php if ($esta_bloqueado): ?>
                            <div class="map-date" style="color:#c0392b; font-size:0.8rem; margin-top: 5px;">
                                <i class="fas fa-lock"></i> Requiere Firma PIV
                            </div>
                        <?php else: ?>
                            <div class="map-date" style="color:#27ae60; font-size:0.8rem; margin-top: 5px;">
                                <i class="fas fa-check-circle"></i> Disponible ahora
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($esta_bloqueado): ?>
                        <button class="btn-bloqueado" disabled>
                            Bloqueado <i class="fas fa-lock"></i>
                        </button>
                    <?php else: ?>
                        <a href="index.php?focus_map=<?= $m['id_mapa']; ?>" class="btn-ver">
                            Ingresar al Mapa <i class="fas fa-arrow-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div> 

    <?php else: ?>
        <div class="sin-mapas">
            <i class="fas fa-clock fa-3x" style="margin-bottom:15px; display:block; color:#f39c12;"></i>
            <p>No tienes mapas asignados para este horario.</p>
            <small>Contacta a tu supervisor.</small>
        </div>
    <?php endif; ?>

</div>

<?php if (!empty($pivs_historial)): ?>
    <div class="modal-overlay" id="modalHistorial">
        <div class="modal-content">
            <button class="btn-close-modal" onclick="cerrarHistorial()"><i class="fas fa-times"></i></button>
            <h3 style="color:#2c3e50; margin-top:0;"><i class="fas fa-history"></i> Historial de Documentos</h3>
            <p style="color:#666; font-size:0.9rem; margin-bottom:15px;">Aquí puedes ver y descargar los documentos que ya has gestionado.</p>
            <div style="overflow-x: auto;">
                <table class="historial-table">
                    <thead>
                        <tr>
                            <th>ID</th><th>Mapa / Escenario</th><th>Enviado por</th><th>Fecha</th><th>Estado</th><th>PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pivs_historial as $hist): ?>
                            <tr>
                                <td><b>#<?= $hist['id_piv'] ?></b></td>
                                <td>
                                    <?php if (!empty($hist['escenario'])): ?>
                                        <span style="font-weight:bold; color:#2c3e50;">Escenario <?= htmlspecialchars($hist['escenario']) ?></span><br>
                                        <small style="color:#7f8c8d;"><?= htmlspecialchars($hist['predio'] ?: $hist['nombre_mapa'] ?: '') ?></small>
                                    <?php else: ?>
                                        <?= htmlspecialchars($hist['predio'] ?: ($hist['nombre_mapa'] ?: '-')) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($hist['remitente']) ?></td>
                                <td><?= $hist['fecha_respuesta'] ? date('d-m-Y H:i', strtotime($hist['fecha_respuesta'])) : '-' ?></td>
                                <td>
                                    <?php if ($hist['estado'] === 'aprobado'): ?>
                                        <!-- CAMBIO PARA QUE EMILIANO VEA 'REVISADO' Y NO 'FIRMADO' -->
                                        <span class="badge badge-aprobado"><i class="fas fa-check"></i> <?= $es_supervisor_lectura ? 'Revisado' : 'Firmado' ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-rechazado"><i class="fas fa-times"></i> Observado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px;">
                                        <a href="piv_pdf_v2.php?id_piv=<?= $hist['id_piv'] ?>&v=<?= $pdf_cache_bust ?>" target="_blank" class="btn-pdf btn-sm" style="padding: 6px 10px; font-size: 0.8rem;" title="Ver PDF">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <?php if ($hist['estado'] === 'aprobado'): ?>
                                            <!-- SE QUITÓ EL CANDADO !$es_supervisor_lectura PARA QUE TODOS PUEDAN REPORTAR -->
                                            <button onclick="agregarObs(<?= $hist['id_piv'] ?>)" style="background: #f39c12; color: white; border: none; padding: 6px 10px; font-size: 0.8rem; border-radius: 6px; cursor: pointer; transition: 0.2s;" title="Agregar hallazgo u observacion en terreno">
                                                <i class="fas fa-exclamation-triangle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    function abrirHistorial() { document.getElementById('modalHistorial').classList.add('active'); }
    function cerrarHistorial() { document.getElementById('modalHistorial').classList.remove('active'); }
    window.onclick = function(event) { if (event.target == document.getElementById('modalHistorial')) cerrarHistorial(); }
</script>

<script>
    // 1. INSTALAR EL GUARDIÁN OFFLINE (Service Worker)
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').then(reg => {
                console.log('Modo Offline Activado');
            }).catch(err => console.log('Error en Modo Offline', err));
        });
    }

    // ==========================================
    // LÓGICA DE FIRMAS Y OBSERVACIONES SIN CONEXIÓN
    // ==========================================
    let firmasPendientes = JSON.parse(localStorage.getItem('firmas_offline')) || [];
    let obsPendientes = JSON.parse(localStorage.getItem('obs_offline')) || [];

    function actualizarBotonSincronizar() {
        let totalPendientes = firmasPendientes.length + obsPendientes.length;
        let btn = document.getElementById('btnSyncOffline');
        if (totalPendientes > 0) {
            if (!btn) {
                let headerMenu = document.querySelector('.header-bar > div:last-child');
                headerMenu.insertAdjacentHTML('afterbegin',
                    `<button id="btnSyncOffline" onclick="sincronizarDatos()" style="background: #e67e22; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; margin-right: 15px; animation: pulse 2s infinite;">
                        <i class="fas fa-wifi"></i> Sincronizar (${totalPendientes})
                    </button>`
                );
            } else {
                btn.innerHTML = `<i class="fas fa-wifi"></i> Sincronizar (${totalPendientes})`;
                btn.style.display = 'inline-flex';
            }
        } else if (btn) {
            btn.style.display = 'none';
        }
    }

    // NUEVA FUNCIÓN PARA EMILIANO (SUPERVISOR LECTURA)
    function marcarLeido(idEnvio) {
        if(confirm("¿Archivar este documento en tu historial de leídos?")) {
            procesarAccion(idEnvio, 'aprobar', 'Tomado en conocimiento (Solo Lectura)');
        }
    }

    function firmar(idEnvio) {
        if(confirm("¿Confirmas que has revisado el documento y procedes con tu firma?")) {
            procesarAccion(idEnvio, 'aprobar', '');
        }
    }

    function rechazar(idEnvio) {
        let motivo = prompt("Por favor indica el motivo de la observación:");
        if(motivo && motivo.trim() !== "") {
            procesarAccion(idEnvio, 'rechazar', motivo);
        } else if (motivo !== null) {
            alert("Debes escribir un motivo.");
        }
    }

    function procesarAccion(idEnvio, accion, observacion) {
        let idPiv = document.querySelector(`#act_${idEnvio}`).previousElementSibling.value;
        let datosAEnviar = new URLSearchParams();
        datosAEnviar.append('id_envio', idEnvio);
        datosAEnviar.append('id_piv', idPiv);
        datosAEnviar.append('accion', accion);
        datosAEnviar.append('observacion', observacion);

        if (navigator.onLine) {
            fetch('procesar_firma.php', { method: 'POST', body: datosAEnviar })
            .then(() => location.reload())
            .catch(() => guardarFirmaEnTelefono(idEnvio, idPiv, accion, observacion));
        } else {
            guardarFirmaEnTelefono(idEnvio, idPiv, accion, observacion);
        }
    }

    function guardarFirmaEnTelefono(idEnvio, idPiv, accion, observacion) {
        firmasPendientes.push({ id_envio: idEnvio, id_piv: idPiv, accion: accion, observacion: observacion });
        localStorage.setItem('firmas_offline', JSON.stringify(firmasPendientes));
        alert("📵 Sin conexión. Tu acción se guardó en el teléfono.");
        let formulario = document.getElementById('act_' + idEnvio)?.closest('.piv-item');
        if (formulario) formulario.style.display = 'none';
        
        // Truco visual para destrabar la tarjeta del mapa temporalmente si firma offline
        let botonesBloqueados = document.querySelectorAll('.btn-bloqueado');
        botonesBloqueados.forEach(btn => {
            btn.innerHTML = "Procesado Offline <i class='fas fa-sync fa-spin'></i>";
            btn.style.background = "#e67e22";
        });

        actualizarBotonSincronizar();
    }

    function agregarObs(idPiv) {
        let textoObs = prompt("Escribe la observación o peligro encontrado en terreno:");
        if (textoObs && textoObs.trim() !== "") {
            let params = new URLSearchParams();
            params.append('id_piv', idPiv);
            params.append('observacion', textoObs.trim());

            if (navigator.onLine) {
                fetch('guardar_obs_terreno.php', { method: 'POST', body: params })
                .then(r => r.json())
                .then(d => d.success ? alert("¡Observación guardada!") : alert("Error: " + d.error))
                .catch(() => guardarObsEnTelefono(idPiv, textoObs.trim()));
            } else {
                guardarObsEnTelefono(idPiv, textoObs.trim());
            }
        }
    }

    function guardarObsEnTelefono(idPiv, texto) {
        let idTemporal = Date.now().toString();
        obsPendientes.push({ id_temp: idTemporal, id_piv: idPiv, observacion: texto });
        localStorage.setItem('obs_offline', JSON.stringify(obsPendientes));
        alert("📵 Sin conexión. Tu observación/hallazgo se ha guardado en el teléfono y se subirá al sincronizar.");
        actualizarBotonSincronizar();
    }

    async function sincronizarDatos() {
        if (!navigator.onLine) {
            alert("Aún no detecto conexión a Internet. Busca un lugar con mejor señal.");
            return;
        }
        let btn = document.getElementById('btnSyncOffline');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Subiendo...';
        btn.disabled = true;
        let exitos = 0;

        for (let item of [...firmasPendientes]) {
            let datos = new URLSearchParams();
            datos.append('id_envio', item.id_envio);
            datos.append('id_piv', item.id_piv);
            datos.append('accion', item.accion);
            datos.append('observacion', item.observacion);
            try {
                let r = await fetch('procesar_firma.php', { method: 'POST', body: datos });
                if (r.ok) {
                    firmasPendientes = firmasPendientes.filter(f => f.id_envio !== item.id_envio);
                    localStorage.setItem('firmas_offline', JSON.stringify(firmasPendientes));
                    exitos++;
                }
            } catch(e) { console.error("Fallo firma ID", item.id_envio); }
        }

        for (let item of [...obsPendientes]) {
            let datos = new URLSearchParams();
            datos.append('id_piv', item.id_piv);
            datos.append('observacion', item.observacion);
            try {
                let r = await fetch('guardar_obs_terreno.php', { method: 'POST', body: datos });
                if (r.ok) {
                    obsPendientes = obsPendientes.filter(o => o.id_temp !== item.id_temp);
                    localStorage.setItem('obs_offline', JSON.stringify(obsPendientes));
                    exitos++;
                }
            } catch(e) { console.error("Fallo obs PIV", item.id_piv); }
        }

        actualizarBotonSincronizar();
        if (exitos > 0) {
            alert("✅ Sincronización completa. Se enviaron " + exitos + " datos al servidor.");
            location.reload();
        } else {
            alert("No se pudo conectar con el servidor. Intenta de nuevo más tarde.");
            btn.disabled = false;
            btn.innerHTML = `<i class="fas fa-wifi"></i> Sincronizar (${firmasPendientes.length + obsPendientes.length})`;
        }
    }

    document.addEventListener('DOMContentLoaded', () => actualizarBotonSincronizar());
    window.addEventListener('online', () => {
        if (firmasPendientes.length > 0 || obsPendientes.length > 0) sincronizarDatos();
    });

    // ==========================================
    // MODO ASPIRADORA: DESCARGAR PDFs EN SECRETO
    // ==========================================
    window.addEventListener('load', () => {
        if (navigator.onLine) {
            const urlsPdf = [
                <?php
                $todas_las_piv = array_merge($pivs_pendientes, $pivs_historial);
                foreach($todas_las_piv as $doc) {
                    echo "'piv_pdf_v2.php?id_piv=" . $doc['id_piv'] . "&v=" . $pdf_cache_bust . "',\n";
                }
                ?>
            ];
            
            if (urlsPdf.length > 0) {
                console.log("🌪️ Modo Aspiradora activado: Precargando PDFs...");
                const urlsUnicas = [...new Set(urlsPdf)];
                
                urlsUnicas.forEach(url => {
                    fetch(url).catch(() => console.log("Fallo al cachear PDF:", url)); 
                });
            }
        }
    });
</script>

</body>
</html>
