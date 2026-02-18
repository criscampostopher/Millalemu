<?php
session_start();
require_once __DIR__ . '/Config/db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['success'=>false,'error'=>'No autenticado']); exit;
}

$tipo = $_SESSION['tipo_usuario'] ?? 'usuario';
if (!in_array($tipo, ['admin','usuario'], true)) {
  echo json_encode(['success'=>false,'error'=>'Sin permisos']); exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) { echo json_encode(['success'=>false,'error'=>'JSON inválido']); exit; }

$id_usuario = (int)$_SESSION['id_usuario'];
$id_mapa = (int)($body['id_mapa'] ?? 0);
$fecha = $body['fecha'] ?? date('Y-m-d');

$consideraciones = trim($body['consideraciones'] ?? '');
$medidas = trim($body['medidas'] ?? '');
$observaciones = trim($body['observaciones'] ?? '');

$firma_cargo = trim($body['firma_cargo'] ?? '');
$firma_nombre = trim($body['firma_nombre'] ?? '');
$firma_rut = trim($body['firma_rut'] ?? '');

if ($id_mapa <= 0) { echo json_encode(['success'=>false,'error'=>'Falta id_mapa']); exit; }
if ($firma_nombre === '' || $firma_cargo === '') { echo json_encode(['success'=>false,'error'=>'Falta nombre o cargo en firma']); exit; }

// Buscar ficha si existe
$id_ficha = null;
$st = $pdo->prepare("SELECT id_ficha FROM public.piv_ficha WHERE id_mapa=:id");
$st->execute([':id'=>$id_mapa]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if ($row) $id_ficha = (int)$row['id_ficha'];

$stmt = $pdo->prepare("
  INSERT INTO public.piv
  (fecha, id_usuario, id_mapa, id_ficha, consideraciones, medidas, observaciones, firma_cargo, firma_nombre, firma_rut)
  VALUES
  (:fecha, :id_usuario, :id_mapa, :id_ficha, :consideraciones, :medidas, :observaciones, :firma_cargo, :firma_nombre, :firma_rut)
  RETURNING id_piv
");
$stmt->execute([
  ':fecha' => $fecha,
  ':id_usuario' => $id_usuario,
  ':id_mapa' => $id_mapa,
  ':id_ficha' => $id_ficha,
  ':consideraciones' => $consideraciones ?: null,
  ':medidas' => $medidas ?: null,
  ':observaciones' => $observaciones ?: null,
  ':firma_cargo' => $firma_cargo,
  ':firma_nombre' => $firma_nombre,
  ':firma_rut' => $firma_rut ?: null
]);

$id_piv = (int)$stmt->fetchColumn();

echo json_encode(['success'=>true, 'id_piv'=>$id_piv]);
