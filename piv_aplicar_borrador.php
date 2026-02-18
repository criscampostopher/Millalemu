<?php
session_start();
require_once __DIR__ . '/Config/db_config.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

$id_mapa = (int)($_GET['id_mapa'] ?? 0);
if ($id_mapa <= 0) {
    die("Falta id_mapa");
}

// 1) Leer borrador
$stmt = $pdo->prepare("SELECT * FROM public.piv_ficha_borrador WHERE id_mapa = ?");
$stmt->execute([$id_mapa]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
    header("Location: piv_formulario.php?id_mapa=" . $id_mapa . "&msg=No%20hay%20borrador%20para%20este%20mapa");
    exit;
}

// 2) Upsert a piv_ficha
$sql = "
INSERT INTO public.piv_ficha
(id_mapa, id_zona, predio, codigo_predio, escenario, temporada, especie, superficie_ha, team_equipo, jefe_faena, updated_at)
VALUES
(:id_mapa, :id_zona, :predio, :codigo_predio, :escenario, :temporada, :especie, :superficie_ha, :team_equipo, :jefe_faena, now())
ON CONFLICT (id_mapa) DO UPDATE SET
id_zona = EXCLUDED.id_zona,
predio = EXCLUDED.predio,
codigo_predio = EXCLUDED.codigo_predio,
escenario = EXCLUDED.escenario,
temporada = EXCLUDED.temporada,
especie = EXCLUDED.especie,
superficie_ha = EXCLUDED.superficie_ha,
team_equipo = EXCLUDED.team_equipo,
jefe_faena = EXCLUDED.jefe_faena,
updated_at = now();
";

$pdo->prepare($sql)->execute([
    'id_mapa' => $id_mapa,
    'id_zona' => $b['id_zona'],
    'predio' => $b['predio'],
    'codigo_predio' => $b['codigo_predio'],
    'escenario' => $b['escenario'],
    'temporada' => $b['temporada'],
    'especie' => $b['especie'],
    'superficie_ha' => $b['superficie_ha'],
    'team_equipo' => $b['team_equipo'],
    'jefe_faena' => $b['jefe_faena'],
]);

header("Location: piv_formulario.php?id_mapa=" . $id_mapa . "&msg=Datos%20del%20mapa%20aplicados");
exit;

