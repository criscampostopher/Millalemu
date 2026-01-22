<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-forest">
    <div class="login-card">
        <h2 class="title-forest">Recuperar Acceso</h2>
        <p class="subtitle">Ingresa tu correo para restablecer tu clave</p>
        
        <form id="recuperarForm">
            <div class="input-group">
                <input type="email" id="email" class="form-control" placeholder="Tu correo electrónico" required>
            </div>
            <button type="submit" class="btn-forest">Enviar Enlace</button>
            <div style="margin-top:15px;">
                <a href="login.php" style="color:#666;">Volver al Login</a>
            </div>
        </form>
    </div>

    <script>
        document.getElementById('recuperarForm').onsubmit = function(e) {
            e.preventDefault();
            const btn = document.querySelector('button');
            btn.disabled = true; btn.innerText = "Enviando...";
            
            fetch('Api/api_recuperarContra.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ email: document.getElementById('email').value })
            })
            .then(r => r.json())
            .then(res => {
                alert(res.message);
                if(res.success) window.location.href = 'login.php';
                else { btn.disabled = false; btn.innerText = "Enviar Enlace"; }
            });
        }
    </script>
</body>
</html>