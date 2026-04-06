<?php

function normalizarRolUsuario(?string $rol): string
{
    $rol = strtolower(trim((string)$rol));
    $rol = preg_replace('/[\s-]+/', '_', $rol);
    return $rol ?? '';
}

function rolTieneFuncionesAdmin(?string $rol): bool
{
    return in_array(normalizarRolUsuario($rol), ['admin', 'ingeniero_forestal', 'jefe_operaciones'], true);
}

function esUsuarioEmilianoMachuca($idUsuario): bool
{
    return (int)$idUsuario === 19;
}

function usuarioSesionPuedeAdministrar(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['id_usuario'])) {
        return false;
    }

    return rolTieneFuncionesAdmin($_SESSION['tipo_usuario'] ?? '');
}

function nombreVisibleRol(?string $rol): string
{
    $rol = normalizarRolUsuario($rol);

    $labels = [
        'admin' => 'Administrador',
        'ingeniero_forestal' => 'Ingeniero Forestal',
        'jefe_operaciones' => 'Jefe de Operaciones',
        'jefe_faena' => 'Jefe de Faena',
        'usuario' => 'Operador',
    ];

    return $labels[$rol] ?? ucfirst(str_replace('_', ' ', $rol));
}

function obtenerJerarquiaFirmaPiv(): array
{
    return ['ingeniero_forestal', 'jefe_operaciones', 'jefe_faena', 'usuario'];
}

function obtenerRolBloqueanteFirmaPiv(PDO $pdo, int $idPiv, int $idUsuario): ?string
{
    $stmtGrupo = $pdo->prepare("SELECT grupo_trabajo FROM public.piv_envio WHERE id_piv = ? AND para_usuario = ?");
    $stmtGrupo->execute([$idPiv, $idUsuario]);
    $miGrupo = $stmtGrupo->fetchColumn();

    if ($miGrupo === false) {
        return null;
    }

    $stmtRol = $pdo->prepare("SELECT tipo_usuario FROM public.usuario WHERE id_usuario = ?");
    $stmtRol->execute([$idUsuario]);
    $miRol = normalizarRolUsuario((string)$stmtRol->fetchColumn());

    $jerarquia = obtenerJerarquiaFirmaPiv();
    $miNivel = array_search($miRol, $jerarquia, true);

    if ($miNivel === false || $miNivel === 0) {
        return null;
    }

    $rolesSuperiores = array_slice($jerarquia, 0, $miNivel);
    $placeholders = implode(',', array_fill(0, count($rolesSuperiores), '?'));

    $sql = "SELECT u.tipo_usuario
            FROM public.piv_envio e
            JOIN public.usuario u ON e.para_usuario = u.id_usuario
            WHERE e.id_piv = ?
              AND e.grupo_trabajo = ?
              AND lower(replace(replace(trim(u.tipo_usuario), ' ', '_'), '-', '_')) IN ($placeholders)
              AND e.estado <> 'aprobado'
            ORDER BY array_position(
                ARRAY['ingeniero_forestal','jefe_operaciones','jefe_faena','usuario'],
                lower(replace(replace(trim(u.tipo_usuario), ' ', '_'), '-', '_'))
            )
            LIMIT 1";

    $params = array_merge([$idPiv, $miGrupo], $rolesSuperiores);
    $stmtBloqueo = $pdo->prepare($sql);
    $stmtBloqueo->execute($params);
    $rolBloqueante = $stmtBloqueo->fetchColumn();

    return $rolBloqueante !== false ? normalizarRolUsuario((string)$rolBloqueante) : null;
}
