<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ==========================================================
// piv_aplicar_borrador.php (v3.0 - Soporte total de columnas)
// Lee el contenido del mapa (GeoJSON/KML) y rellena la ficha PIV
// ==========================================================
session_start();
require_once __DIR__ . '/Config/db_config.php';

if (!isset($_SESSION['id_usuario'])) { header("Location: login.php"); exit; }

$id_mapa = isset($_GET['id_mapa']) ? (int)$_GET['id_mapa'] : 0;
if ($id_mapa <= 0) { die("ID Mapa inválido"); }

// 1. Obtener contenido del mapa
$stmt = $pdo->prepare("SELECT contenido_source, tipo_mapa, nombre_mapa, id_zona FROM public.mapa WHERE id_mapa = ?");
$stmt->execute([$id_mapa]);
$mapa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mapa || empty($mapa['contenido_source'])) {
    die("El mapa no tiene contenido legible para extraer datos.");
}

// Variables a extraer
$datos = [
    'superficie_ha' => null,
    'especie'       => '',
    'predio'        => '',
    'rodal'         => '',
    'temporada'     => '',
    'escenario'     => ''
];

// 2. Parsear GeoJSON (Específico para tu archivo Acta Blanco)
if (stripos($mapa['tipo_mapa'], 'json') !== false) {
    $json = json_decode($mapa['contenido_source'], true);
    if (isset($json['features'])) {
        foreach ($json['features'] as $f) {
            $p = $f['properties'] ?? [];
            
            // Variables temporales para prioridad de Especie
            $temp_especie_larga = '';
            $temp_especie_corta = '';
            $temp_especie_alt   = '';

            foreach ($p as $key => $val) {
                $k = strtoupper($key);
                
                // 1. Extraer todos los posibles candidatos para Especie
                if ($k === 'NOM_TIPUSO')  $temp_especie_larga = trim($val);
                if ($k === 'TIPOUSO')     $temp_especie_corta = trim($val);
                if ($k === 'ESPECIE')     $temp_especie_alt   = trim($val);

                // 2. Extraer los demás datos (Predio, Escenario, etc.)
                if ($k === 'NOM_PREDIO' && !$datos['predio'])  $datos['predio'] = trim($val);
                if ($k === 'NUMESCENAR' && !$datos['escenario'])  $datos['escenario'] = trim($val);
                if ($k === 'TEMPORADA' && !$datos['temporada'])   $datos['temporada'] = trim($val);
                if ($k === 'CODIGO' && !$datos['rodal'])      $datos['rodal'] = trim($val);
                if ($k === 'SUPERFICIE' && !$datos['superficie_ha'])  $datos['superficie_ha'] = (float)$val;
            }

            // Prioridad: 1° Nombre Largo, 2° Nombre Corto, 3° Alternativo
            $especie_candidata = $temp_especie_larga ?: ($temp_especie_corta ?: ($temp_especie_alt ?: ''));
            if ($especie_candidata && !$datos['especie']) {
                $datos['especie'] = $especie_candidata;
            }
        }
    }
} 
// 3. Parsear KML (Básico)
elseif (stripos($mapa['tipo_mapa'], 'kml') !== false) {
    // Extracción simple usando Regex para no depender de librerías complejas
    if (preg_match('/<SimpleData name="AREA">([\d\.]+)<\/SimpleData>/i', $mapa['contenido_source'], $m)) {
        $datos['superficie_ha'] = (float)$m[1];
    }
    if (preg_match('/<SimpleData name="ESPECIE">([^<]+)<\/SimpleData>/i', $mapa['contenido_source'], $m)) {
        $datos['especie'] = trim($m[1]);
    }
}

// 4. Actualizar o Insertar en piv_ficha
// Preparamos valores por defecto si no se encontró nada
$codigo_predio = $datos['rodal'] ?: '';
$predio        = $datos['predio'] ?: '';
$especie       = $datos['especie'] ?: '';
$superficie    = $datos['superficie_ha'] ?: null;
$temporada     = $datos['temporada'] ?: '';
$escenario     = $datos['escenario'] ?: '';

// 4. UPSERT (Insertar o Actualizar todas las columnas)
$sql = "INSERT INTO public.piv_ficha 
        (id_mapa, id_zona, codigo_predio, predio, especie, superficie_ha, temporada, escenario, updated_at)
        VALUES 
        (:id_mapa, :id_zona, :codigo, :predio, :especie, :sup, :temp, :esc, NOW())
        ON CONFLICT (id_mapa) DO UPDATE SET
            codigo_predio = COALESCE(NULLIF(EXCLUDED.codigo_predio, ''), public.piv_ficha.codigo_predio),
            predio        = COALESCE(NULLIF(EXCLUDED.predio, ''), public.piv_ficha.predio),
            especie       = COALESCE(NULLIF(EXCLUDED.especie, ''), public.piv_ficha.especie),
            superficie_ha = COALESCE(EXCLUDED.superficie_ha, public.piv_ficha.superficie_ha),
            temporada     = COALESCE(NULLIF(EXCLUDED.temporada, ''), public.piv_ficha.temporada),
            escenario     = COALESCE(NULLIF(EXCLUDED.escenario, ''), public.piv_ficha.escenario),
            id_zona       = EXCLUDED.id_zona,
            updated_at    = NOW()";

$stmtUp = $pdo->prepare($sql);
$stmtUp->execute([
    ':id_mapa' => $id_mapa,
    ':id_zona' => $mapa['id_zona'],
    ':codigo'  => $codigo_predio,
    ':predio'  => $predio,
    ':especie' => $especie,
    ':sup'     => $superficie,
    ':temp'    => $temporada,
    ':esc'     => $escenario
]);

header("Location: piv_formulario.php?id_mapa=$id_mapa&msg=" . urlencode("Datos aplicados correctamente."));
exit;