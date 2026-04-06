<?php
// ==========================================================
// Archivo: usuarios.php (Versión ZONAS + Rol Jefe de Faena)
// ==========================================================
session_start();
require_once __DIR__ . '/Config/db_config.php';
require_once __DIR__ . '/Config/roles.php';

if (!usuarioSesionPuedeAdministrar()) { 
    header("Location: login.php"); 
    exit; 
}

// 1. MINI-API INTERNA (ZONAS)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (isset($input['action']) && (strpos($input['action'], 'zona') !== false || $input['action'] === 'get_user_zonas')) {
    header('Content-Type: application/json');
    $res = ['success' => false];
    try {
        if ($input['action'] === 'get_user_zonas') {
            // Traer zonas asignadas
            $stmt = $pdo->prepare("SELECT uz.id_asignacion, z.nombre_zona FROM public.usuario_zona uz JOIN public.zona z ON uz.id_zona = z.id_zona WHERE uz.id_usuario = ? ORDER BY z.nombre_zona ASC");
            $stmt->execute([$input['id_usuario']]);
            $res['zonas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $res['success'] = true;
        } elseif ($input['action'] === 'assign_zona') {
            // Verificar duplicados
            $check = $pdo->prepare("SELECT 1 FROM public.usuario_zona WHERE id_usuario = ? AND id_zona = ?");
            $check->execute([$input['id_usuario'], $input['id_zona']]);
            
            if($check->fetch()) { 
                $res['error'] = "Predio ya asignado."; 
            } else {
                // INSERT CON FECHAS
                $sql = "INSERT INTO public.usuario_zona (id_usuario, id_zona, fecha_inicio, fecha_fin) VALUES (?, ?, ?, ?)";
                
                // Manejo de nulos para fecha fin
                $fin = !empty($input['fin']) ? $input['fin'] : null;
                $inicio = !empty($input['inicio']) ? $input['inicio'] : date('Y-m-d H:i:s');

                $pdo->prepare($sql)->execute([$input['id_usuario'], $input['id_zona'], $inicio, $fin]);
                $res['success'] = true;
            }
        
        } elseif ($input['action'] === 'delete_zona') {
            // Borrar zona
            $pdo->prepare("DELETE FROM public.usuario_zona WHERE id_asignacion = ?")->execute([$input['id_asignacion']]);
            $res['success'] = true;
        }
    } catch (Exception $e) { $res['error'] = $e->getMessage(); }
    echo json_encode($res); exit;
}

// 2. CARGA DE DATOS HTML
if (!usuarioSesionPuedeAdministrar()) { header("Location: login.php"); exit; }

$usuarios = $pdo->query("SELECT id_usuario AS id, nombre_usuario, email, tipo_usuario FROM public.usuario ORDER BY id_usuario ASC")->fetchAll(PDO::FETCH_ASSOC);
// CAMBIO: Cargamos ZONAS en vez de Mapas
$zonas_disponibles = $pdo->query("SELECT id_zona, nombre_zona FROM public.zona WHERE nombre_zona != 'SISTEMA_OCULTO' ORDER BY nombre_zona ASC")->fetchAll(PDO::FETCH_ASSOC);
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
        /* --- AGREGAR ESTO EN EL CSS --- */
.zona-item-card { 
    display: flex; justify-content: space-between; align-items: center; 
    background: #f9f9f9; border: 1px solid #eee; 
    padding: 10px; margin-bottom: 5px; border-radius: 4px; 
    border-left: 4px solid #2ecc71; 
}
.scroll-container {
    max-height: 250px;       /* Altura máxima */
    overflow-y: auto;        /* Scroll automático */
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 5px;
    background: #fff;
    margin-top: 10px;
}
.scroll-container::-webkit-scrollbar { width: 8px; }
.scroll-container::-webkit-scrollbar-thumb { background: #bdc3c7; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="leaves-container"><div class="leaf"></div></div>
    
    <aside class="sidebar">
        <h2 style="text-align:center; padding:10px; color:#fdd835;">Millalemu</h2>
        <a href="menuadmin.php"><i class="fas fa-home"></i> Inicio</a>
        <a href="index.php"><i class="fas fa-eye"></i> <b>Visor Global</b></a>
        <a href="mapas.php" class="active"><i class="fas fa-layer-group"></i> Gestión de Mapas</a>
        <a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a>
        <a href="auditoria.php"><i class="fas fa-shield-alt"></i> Auditoría de Seguridad</a>
        <a href="piv_formulario.php" class="piv-btn"><i class="fas fa-clipboard-list"></i> PIV Formulario</a>
        <div style="margin-top:auto; padding-bottom:20px;">
            <a href="logout.php" style="color:#ef5350;"><i class="fas fa-sign-out-alt"></i> Salir</a>
        </div>
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
                    <?php
                    $nombreUsuarioActual = trim((string)($u['nombre_usuario'] ?? ''));
                    if (function_exists('mb_strtolower')) {
                        $es_emiliano_protegido = mb_strtolower($nombreUsuarioActual, 'UTF-8') === 'emiliano machuca';
                    } else {
                        $es_emiliano_protegido = strtolower($nombreUsuarioActual) === 'emiliano machuca';
                    }
                    ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['nombre_usuario']) ?></td>
                        <td><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                        <td>
                            <?php 
                            $roles_display = [
                                'admin' => 'ADMINISTRADOR',
                                'ingeniero_forestal' => 'INGENIERO FORESTAL',
                                'jefe_operaciones' => 'JEFE DE OPERACIONES',
                                'jefe_faena' => 'JEFE DE FAENA',
                                'usuario' => 'OPERADOR / TRABAJADOR'
                            ];
                            echo $roles_display[$u['tipo_usuario']] ?? strtoupper($u['tipo_usuario']);
                            ?>
                        </td>
                        <td>
                            <?php if($u['id'] != 1): ?>
                            <button class="btn-action btn-assign" onclick="gestionarZonas(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nombre_usuario']) ?>')" title="Asignar Predio"><i class="fas fa-map-marked-alt"></i></button>
                            <button class="btn-action btn-edit" onclick="editar(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nombre_usuario']) ?>', '<?= htmlspecialchars($u['email']) ?>', '<?= $u['tipo_usuario'] ?>')" title="Editar Usuario"><i class="fas fa-edit"></i></button>
                            <?php if($u['id'] != $_SESSION['id_usuario'] && !$es_emiliano_protegido): ?>
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
                        <option value="usuario">Operador / Trabajador</option>
                        <option value="jefe_faena">Jefe de Faena</option>
                        <option value="jefe_operaciones">Jefe de Operaciones</option>
                        <option value="ingeniero_forestal">Ingeniero Forestal</option>
                        <option value="admin">Administrador del Sistema</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-new" style="width:100%; margin-top:10px;">Guardar Datos</button>
            </form>
        </div>
    </div>

    <div id="assignModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="cerrarModal('assignModal')">&times;</span>
        <h2 style="color:#f1c40f;">Asignar Predio</h2>
        <p>Usuario: <b id="assignUserName"></b></p>
        
        <form id="assignForm" style="background:#fffbe6; padding:15px; border-radius:5px; margin-bottom:15px;">
            <input type="hidden" id="assignUserId">
            <div class="form-group">
                <label>Seleccionar Predio:</label>
                <select id="assignZoneId" required style="width:100%;">
                    <option value="">-- Seleccionar Predio --</option>
                    <?php foreach($zonas_disponibles as $z): ?>
                        <option value="<?= $z['id_zona'] ?>"><?= $z['nombre_zona'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;">
                    <label>Desde:</label>
                    <input type="datetime-local" id="assignStart" value="<?= date('Y-m-d\TH:i') ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Hasta (Opcional):</label>
                    <input type="datetime-local" id="assignEnd">
                </div>
            </div>
            <button type="submit" class="btn-new" style="width:100%; background:#f1c40f; color:#333;">Asignar</button>
        </form>

        <h4 style="margin-bottom:5px;">Predios Asignados:</h4>
        <div id="contenedorZonasUsuario" class="scroll-container">
            <small>Cargando...</small>
        </div>
    </div>
</div>

    <script>
        let isEditingUser = false;
        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }
        
        // --- FUNCIONES DE USUARIOS ---

function abrirModal() { 
    isEditingUser = false; 
    document.getElementById('modalTitle').innerText = 'Nuevo Usuario'; 
    document.getElementById('userId').value = ''; 
    document.getElementById('userName').value = ''; 
    document.getElementById('userEmail').value = ''; 
    document.getElementById('userPass').value = ''; 
    document.getElementById('userRol').value = 'usuario'; 
    document.getElementById('userModal').style.display = 'block'; 
}

function editar(id, nombre, email, rol) { 
    isEditingUser = true; 
    document.getElementById('modalTitle').innerText = 'Editar Usuario'; 
    document.getElementById('userId').value = id; 
    document.getElementById('userName').value = nombre; 
    document.getElementById('userEmail').value = (email && email !== 'null') ? email : '';
    document.getElementById('userRol').value = rol; 
    document.getElementById('userPass').value = ''; 
    document.getElementById('userModal').style.display = 'block'; 
}

document.getElementById('userForm').onsubmit = async (e) => { 
    e.preventDefault(); 
    
    const action = isEditingUser ? 'update' : 'create';
    const data = { 
        action: action, 
        id: document.getElementById('userId').value, 
        user: document.getElementById('userName').value, 
        email: document.getElementById('userEmail').value, 
        pass: document.getElementById('userPass').value, 
        rol: document.getElementById('userRol').value 
    };

    const res = await enviarAPI(action, data); 

    if (res.success) {
        alert(isEditingUser ? "✅ Usuario actualizado con éxito" : "✅ Usuario creado con éxito");
        cerrarModal('userModal'); 
        location.reload(); 
    } else {
        alert("⚠️ Error: " + (res.error || "No se pudo procesar la solicitud"));
    }
};

async function eliminar(id) { 
    if (confirm('¿Está seguro de eliminar este usuario? Esta acción no se puede deshacer.')) {
        const res = await enviarAPI('delete', { action: 'delete', id: id });
        if (res.success) {
            alert("✅ Usuario eliminado");
            location.reload();
        } else {
            alert("⚠️ Error: " + res.error);
        }
    } 
}

        // --- MAPAS ---
       function gestionarZonas(idUser, nombreUser) { 
        document.getElementById('assignUserId').value = idUser; 
        document.getElementById('assignUserName').innerText = nombreUser; 
        document.getElementById('assignModal').style.display = 'block'; 
        cargarZonasUsuario(idUser);
    }

    function cargarZonasUsuario(idUser) {
        const div = document.getElementById('contenedorZonasUsuario');
        div.innerHTML = '<div style="text-align:center;color:#888;">Cargando...</div>';
        
        fetch('usuarios.php', { 
            method: 'POST', 
            body: JSON.stringify({action: 'get_user_zonas', id_usuario: idUser}) 
        })
        .then(r => r.json()).then(res => {
            div.innerHTML = '';
            if(res.zonas && res.zonas.length > 0) {
                res.zonas.forEach(z => {
                    div.innerHTML += `
                        <div class="zona-item-card">
                            <span>📍 <strong>${z.nombre_zona}</strong></span>
                            <button class="btn-action btn-del" onclick="quitarZona(${idUser}, ${z.id_asignacion})" title="Quitar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>`;
                });
            } else {
                div.innerHTML = '<small style="display:block;padding:10px;color:#777;">Sin predios asignados.</small>';
            }
        });
    }

    document.getElementById('assignForm').onsubmit = (e) => { 
        e.preventDefault(); 
        const data = {
            action: 'assign_zona',
            id_usuario: document.getElementById('assignUserId').value,
            id_zona: document.getElementById('assignZoneId').value,
            inicio: document.getElementById('assignStart').value,
            fin: document.getElementById('assignEnd').value
        };
        fetch('usuarios.php', { method:'POST', body:JSON.stringify(data) })
        .then(r=>r.json()).then(res => {
            if(res.success) { alert("✅ Predio asignado"); cargarZonasUsuario(data.id_usuario); } 
            else alert('⚠️ ' + res.error);
        });
    };

    function quitarZona(idUser, idAsignacion) {
        if(confirm("¿Quitar Predio?")) {
            fetch('usuarios.php', { method:'POST', body:JSON.stringify({action:'delete_zona', id_asignacion: idAsignacion}) })
            .then(r=>r.json()).then(res => {
                if(res.success) cargarZonasUsuario(idUser);
                else alert('Error: '+res.error);
            });
        }
    }
    
    // Cierre de modal al clic fuera
    window.onclick = function(e) { 
        if(e.target.className === 'modal') e.target.style.display='none'; 
    }

    // Función auxiliar para enviar datos a la API
async function enviarAPI(action, data = {}) {
    if (typeof action === 'object') {
        data = action;
        action = data.action || '';
    }

    const url = (action.includes('zona') || action.includes('get_user_zonas')) 
                ? 'usuarios.php' 
                : 'Api/api_usuarios.php';
    
    data.action = action;

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        if (!response.ok) throw new Error('Error en la respuesta del servidor');
        return await response.json();
    } catch (error) {
        console.error('Error en la petición:', error);
        return { success: false, error: 'Error de comunicación con el servidor.' };
    }
}
</script>
</body>
</html>
