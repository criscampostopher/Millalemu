<?php
// ==========================================================
// Archivo: usuarios.php
// ==========================================================
session_start();
require_once __DIR__ . '/Config/db_config.php';

// Verificar sesión
if (!isset($_SESSION['id_usuario']) || $_SESSION['tipo_usuario'] !== 'admin') { 
    header("Location: login.php"); 
    exit; 
}

$usuarios = []; 
$mapas_disponibles = [];

try {
    $stmt = $pdo->query("SELECT id_usuario AS id, nombre_usuario, email, tipo_usuario FROM public.usuario ORDER BY id_usuario ASC");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $mapas_disponibles = $pdo->query("SELECT id_mapa, nombre_mapa FROM public.mapa ORDER BY id_mapa ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
    $error = "Error: " . $e->getMessage(); 
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios - Millalemu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="style-admin.css">
    <style>
        .table-container { background: white; padding: 25px; border-radius: 12px; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; }
        thead tr { background-color: #2e7d32; color: white; }
        
        .btn-action { padding: 8px; border: none; border-radius: 4px; cursor: pointer; color: white; margin-right: 5px; }
        .btn-edit { background: #3498db; } 
        .btn-del { background: #e74c3c; } 
        .btn-assign { background: #f1c40f; color: #333; }
        
      
        .btn-new { 
            background: #2ecc71; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: 600;
            font-size: 1rem;
        }
        .btn-new:hover { background: #27ae60; }
        
       
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(3px); }
        
        .modal-content { 
            background: white; 
            margin: 5% auto; 
            padding: 30px;
            border-radius: 10px; 
            width: 90%; 
            max-width: 500px; 
            position: relative;
            color: #333 !important; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
        }
        
        .modal-content h2 {
            margin-top: 0;
            color: #2e7d32; 
            border-bottom: 2px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .close-btn { position: absolute; right: 20px; top: 20px; font-size: 24px; cursor: pointer; color: #aaa; transition:0.2s; }
        .close-btn:hover { color: #333; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #555; }
        .form-group input, .form-group select { 
            width: 100%; padding: 12px; 
            border: 1px solid #ccc; border-radius: 6px; 
            box-sizing: border-box; 
            font-size: 1rem;
            background: #fff;
            color: #333;
        }
        
      
        .map-item-card { 
            display: flex; justify-content: space-between; align-items: center; 
            background: #f9f9f9; border: 1px solid #eee; 
            padding: 10px; margin-bottom: 5px; border-radius: 4px; 
            border-left: 4px solid #2ecc71; 
        }
        .btn-mini { padding: 5px 8px; border-radius: 4px; border: none; cursor: pointer; margin-left: 5px; color: white; }
        .btn-mini-del { background: #e74c3c; }
        .btn-mini-edit { background: #3498db; }
    </style>
</head>
<body>
    <div class="leaves-container"><div class="leaf"></div></div>
    
    <aside class="sidebar">
        <h2 style="text-align:center;color:#fdd835;">Millalemu</h2>
        <a href="menuadmin.php"><i class="fas fa-home"></i> Inicio</a>
        <a href="index.php"><i class="fas fa-eye"></i> <b>Visor Global</b></a>
        <a href="mapas.php"><i class="fas fa-layer-group"></i> Gestión de Mapas</a>
        <a href="usuarios.php" class="active"><i class="fas fa-users"></i> Usuarios</a>
        
        <a href="logout.php" style="margin-top:auto; color:#ef5350;"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </aside>

    <main class="main">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h1 style="margin:0;">Gestión de Usuarios</h1>
            <button class="btn-new" onclick="abrirModal()"><i class="fas fa-plus"></i> Nuevo Usuario</button>
        </div>
        
        <div class="table-container">
            <table>
                <thead><tr><th>ID</th><th>Usuario</th><th>Email</th><th>Rol</th><th>Acciones</th></tr></thead>
                <tbody>
                    <?php foreach($usuarios as $u): ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['nombre_usuario']) ?></td>
                        <td><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                        <td><?= strtoupper($u['tipo_usuario']) ?></td>
                        <td>
                            <?php if($u['id'] != 1): ?>
                            <button class="btn-action btn-assign" onclick="gestionarMapas(<?= $u['id'] ?>, '<?= $u['nombre_usuario'] ?>')" title="Asignar Mapa"><i class="fas fa-map-marked-alt"></i></button>
                            <button class="btn-action btn-edit" onclick="editar(<?= $u['id'] ?>, '<?= $u['nombre_usuario'] ?>', '<?= $u['email'] ?>', '<?= $u['tipo_usuario'] ?>')" title="Editar Usuario"><i class="fas fa-edit"></i></button>
                            <?php if($u['id'] != $_SESSION['id_usuario']): ?>
                                <button class="btn-action btn-del" onclick="eliminar(<?= $u['id'] ?>)" title="Eliminar"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="userModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="cerrarModal('userModal')">&times;</span>
            <h2 id="modalTitle">Usuario</h2>
            <form id="userForm">
                <input type="hidden" id="userId">
                <div class="form-group">
                    <label>Usuario:</label>
                    <input type="text" id="userName" required>
                </div>
                <div class="form-group">
                    <label>Email (Recuperación):</label>
                    <input type="email" id="userEmail" placeholder="ejemplo@correo.com">
                </div>
                <div class="form-group">
                    <label>Contraseña:</label>
                    <input type="password" id="userPass" placeholder="Mínimo 4 caracteres">
                    <small style="color:#777;">Dejar en blanco para no cambiar</small>
                </div>
                <div class="form-group">
                    <label>Rol:</label>
                    <select id="userRol">
                        <option value="usuario">Operador</option>
                        <option value="admin">Supervisor</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-new" style="width:100%; margin-top:10px;">Guardar Datos</button>
            </form>
        </div>
    </div>

    <div id="assignModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="cerrarModal('assignModal')">&times;</span>
            <h2 style="color:#f1c40f;"><i class="fas fa-map-marked-alt"></i> Asignar Mapa</h2>
            <p>Usuario: <b id="assignUserName" style="color:#333;"></b></p>
            
            <form id="assignForm" style="background:#fffbe6; padding:20px; border-radius:8px; border:1px solid #f1c40f; margin-bottom:20px;">
                <input type="hidden" id="assignUserId">
                <input type="hidden" id="isUpdate" value="0"> 
                <div class="form-group">
                    <label style="color:#d4ac0d;">Seleccionar Mapa:</label>
                    <select id="assignMapId" required style="width:100%;">
                        <option value="">-- Selecciona Mapa --</option>
                        <?php foreach($mapas_disponibles as $m): ?><option value="<?= $m['id_mapa'] ?>"><?= $m['nombre_mapa'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="display:flex; gap:10px;">
                    <div style="flex:1;"><label style="color:#d4ac0d;">Inicio:</label><input type="datetime-local" id="assignStart" required></div>
                    <div style="flex:1;"><label style="color:#d4ac0d;">Fin:</label><input type="datetime-local" id="assignEnd"></div>
                </div>
                
                <button type="submit" id="btnAssignSubmit" class="btn-new" style="width:100%; background:#f1c40f; color:#333; margin-top:10px;">Asignar Mapa</button>
                <button type="button" id="btnCancelEdit" style="display:none; width:100%; margin-top:10px; padding:10px; background:#ccc; border:none; border-radius:6px; cursor:pointer; color:#333; font-weight:bold;" onclick="resetMapForm()">Cancelar Edición</button>
            </form>

            <div style="border-top:2px dashed #eee; padding-top:15px;">
                <h4 style="color:#555; margin-top:0;">Mapas Asignados:</h4>
                <div id="contenedorMapasUsuario"></div>
            </div>
        </div>
    </div>

    <script>
        let isEditingUser = false;
        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }
        
        // --- USUARIOS ---
        function abrirModal() { 
            isEditingUser=false; document.getElementById('modalTitle').innerText='Nuevo Usuario'; 
            document.getElementById('userId').value=''; document.getElementById('userName').value=''; 
            document.getElementById('userEmail').value=''; document.getElementById('userPass').value=''; 
            document.getElementById('userModal').style.display='block'; 
        }
        function editar(id, nombre, email, rol) { 
            isEditingUser=true; document.getElementById('modalTitle').innerText='Editar Usuario'; 
            document.getElementById('userId').value=id; document.getElementById('userName').value=nombre; 
            document.getElementById('userEmail').value = (email && email !== 'null') ? email : '';
            document.getElementById('userRol').value=rol; document.getElementById('userPass').value=''; 
            document.getElementById('userModal').style.display='block'; 
        }
        document.getElementById('userForm').onsubmit = (e) => { 
            e.preventDefault(); 
            const data={ action: isEditingUser?'update':'create', id:document.getElementById('userId').value, user:document.getElementById('userName').value, email:document.getElementById('userEmail').value, pass:document.getElementById('userPass').value, rol:document.getElementById('userRol').value };
            enviarAPI(data); 
        };
        function eliminar(id) { if(confirm('¿Eliminar usuario?')) enviarAPI({action:'delete',id:id}); }

        // --- MAPAS ---
        function gestionarMapas(idUser, nombreUser) { 
            document.getElementById('assignUserId').value=idUser; document.getElementById('assignUserName').innerText=nombreUser; 
            resetMapForm();
            document.getElementById('assignModal').style.display='block'; 
            cargarMapasUsuario(idUser);
        }

        function resetMapForm() {
            document.getElementById('assignForm').reset();
            document.getElementById('assignMapId').disabled = false; 
            document.getElementById('isUpdate').value = "0";
            document.getElementById('btnAssignSubmit').innerText = "Asignar Mapa";
            document.getElementById('btnCancelEdit').style.display = 'none';
            const idU = document.getElementById('assignUserId').value;
            if(idU) document.getElementById('assignUserId').value = idU; 
        }

        function cargarMapasUsuario(idUser) {
            const div = document.getElementById('contenedorMapasUsuario');
            div.innerHTML = '<div style="color:#777; text-align:center;">Cargando...</div>';
            fetch('Api/api_usuarios.php', { method: 'POST', body: JSON.stringify({action: 'get_user_maps', id_usuario: idUser}) })
            .then(r=>r.json()).then(res=>{
                div.innerHTML = '';
                if(res.maps && res.maps.length > 0) {
                    res.maps.forEach(m => {
                        let inicio = m.fecha_inicio ? m.fecha_inicio.replace(' ', 'T') : '';
                        let fin = m.fecha_fin ? m.fecha_fin.replace(' ', 'T') : '';
                        let textoFechas = m.fecha_inicio.substring(0,16) + ' ➜ ' + (m.fecha_fin ? m.fecha_fin.substring(0,16) : '∞');

                        div.innerHTML += `
                            <div class="map-item-card">
                                <div style="color:#333;"><strong>${m.nombre_mapa}</strong><br><small style="color:#666;">${textoFechas}</small></div>
                                <div>
                                    <button class="btn-mini btn-mini-edit" onclick='prepararEdicion(${m.id_mapa}, "${inicio}", "${fin}")' title="Modificar Fechas"><i class="fas fa-pen"></i></button>
                                    <button class="btn-mini btn-mini-del" onclick="quitarMapa(${idUser}, ${m.id_mapa})" title="Quitar"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>`;
                    });
                } else div.innerHTML = '<small style="color:#777;">Sin mapas asignados.</small>';
            });
        }

        function prepararEdicion(idMapa, inicio, fin) {
            document.getElementById('assignMapId').value = idMapa;
            document.getElementById('assignMapId').disabled = true; 
            document.getElementById('assignStart').value = inicio.substring(0,16);
            document.getElementById('assignEnd').value = fin ? fin.substring(0,16) : '';
            document.getElementById('isUpdate').value = "1";
            document.getElementById('btnAssignSubmit').innerText = "Guardar Nuevas Fechas";
            document.getElementById('btnCancelEdit').style.display = 'block';
        }

        document.getElementById('assignForm').onsubmit = (e) => { 
            e.preventDefault(); 
            const esUpdate = document.getElementById('isUpdate').value === "1";
            const data = {
                action: esUpdate ? 'update_map_assignment' : 'assign_map',
                id_usuario: document.getElementById('assignUserId').value,
                id_mapa: document.getElementById('assignMapId').value,
                inicio: document.getElementById('assignStart').value,
                fin: document.getElementById('assignEnd').value
            };
            
            fetch('Api/api_usuarios.php', { method:'POST', body:JSON.stringify(data) })
            .then(r=>r.json()).then(res=>{
                if(res.success) { 
                    alert(esUpdate ? "✅ Fechas actualizadas" : "✅ Mapa asignado");
                    cargarMapasUsuario(data.id_usuario); 
                    resetMapForm();
                } else alert('⚠️ ' + res.error);
            });
        };

        function quitarMapa(idUser, idMapa) {
            if(confirm("¿Quitar asignación?")) enviarAPI({action:'unassign_map', id_usuario:idUser, id_mapa:idMapa}, true);
        }

        function enviarAPI(data, recargarMapas=false) { 
            fetch('Api/api_usuarios.php', { method:'POST', body:JSON.stringify(data) })
            .then(r=>r.json()).then(res=>{
                if(res.success) { 
                    if(recargarMapas) cargarMapasUsuario(data.id_usuario);
                    else { alert("✅ Éxito"); location.reload(); }
                } else alert('Error: '+res.error);
            }); 
        }
        window.onclick = function(e) { if(e.target.className === 'modal') e.target.style.display='none'; }
    </script>
</body>
</html>