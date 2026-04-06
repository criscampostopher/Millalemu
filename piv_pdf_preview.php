<?php
// piv_pdf_preview.php  (Generador temporal de Vista Previa)
session_start();
require_once __DIR__ . '/Config/db_config.php';

if (!isset($_SESSION['id_usuario'])) { die("Acceso denegado."); }

require_once __DIR__ . '/lib/fpdf/fpdf.php';

function htxt($s) {
  $s = (string)$s;
  if ($s === '') return '';
  if (!preg_match('//u', $s)) { $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1'); }
  $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
  if ($out === false) { $out = @iconv('ISO-8859-1', 'windows-1252//TRANSLIT//IGNORE', $s); }
  return $out === false ? '' : $out;
}
function asNum($value, $dec = 2) {
  if ($value === null || $value === '') return '';
  if (!is_numeric($value)) return (string)$value;
  return number_format((float)$value, $dec, '.', '');
}
function boolSI($v){
  return ($v === true || $v === 't' || $v === 1 || $v === '1') ? 'SI' : 'NO';
}

// ===== ARMAMOS LA DATA DESDE EL POST (Sin guardar en BD) =====
$row = [];
$row['id_piv'] = 'PREVIEW';
$row['fecha'] = $_POST['fecha'] ?? date('Y-m-d');
$row['consideraciones'] = $_POST['consideraciones'] ?? '';
$row['observaciones'] = ''; // Es borrador, no hay observaciones de terreno aún
$row['id_mapa'] = (int)($_POST['id_mapa'] ?? 0);

$row['predio'] = $_POST['predio'] ?? '';
$row['codigo_predio'] = $_POST['codigo_predio'] ?? '';
$row['escenario'] = $_POST['escenario'] ?? '';
$row['temporada'] = $_POST['temporada'] ?? '';
$row['especie'] = $_POST['especie'] ?? '';
$row['superficie_ha'] = $_POST['superficie_ha'] ?? '';
$row['volumen_total_m3'] = $_POST['volumen_total_m3'] ?? '';
$row['arboles_hora'] = $_POST['arboles_hora'] ?? '';
$row['team_equipo'] = $_POST['team_equipo'] ?? '';
$row['tecnologia'] = $_POST['tecnologia'] ?? '';
$row['asistencia_tipo'] = $_POST['asistencia_tipo'] ?? '';
$row['jefe_faena'] = $_POST['jefe_faena'] ?? '';
$row['volteo_cerca_tendido_electrico'] = $_POST['volteo_cerca_tendido_electrico'] ?? 0;
$row['volteo_cerca_camino_publico'] = $_POST['volteo_cerca_camino_publico'] ?? 0;
$row['uso_pivotes'] = $_POST['uso_pivotes'] ?? 0;
$row['tiempo_estimado_dias'] = $_POST['tiempo_estimado_dias'] ?? '';
$row['pendiente_max_pct'] = $_POST['pendiente_max_pct'] ?? '';
$row['tipo_suelo'] = $_POST['tipo_suelo'] ?? '';
$row['verif_permisos'] = $_POST['verif_permisos'] ?? '';
$row['jornada'] = $_POST['jornada'] ?? '';

// Obtener nombre real del mapa para la previsualización
$st_mapa = $pdo->prepare("SELECT nombre_mapa FROM public.mapa WHERE id_mapa = ?");
$st_mapa->execute([$row['id_mapa']]);
$row['nombre_mapa'] = $st_mapa->fetchColumn() ?: 'Mapa Desconocido';

// ===== PDF base =====
$pdf = new FPDF('P','mm','Legal');
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 8);
if (method_exists($pdf, 'SetCellMargin')) { $pdf->SetCellMargin(0.6); }

$BLUE = [0, 70, 140];
$FILL = [220, 230, 245];
$pdf->SetDrawColor($BLUE[0], $BLUE[1], $BLUE[2]);
$pdf->SetLineWidth(0.35);
$pdf->SetTextColor(0,0,0);

// ===== Helpers (Idénticos a piv_pdf_v2.php) =====
function rect($pdf, $x, $y, $w, $h, $fill=false){ $pdf->Rect($x, $y, $w, $h, $fill ? 'F' : ''); }
function hline($pdf, $x1,$y,$x2){ $pdf->Line($x1,$y,$x2,$y); }
function vline($pdf, $x,$y1,$y2){ $pdf->Line($x,$y1,$x,$y2); }
function cellText($pdf, $x, $y, $w, $h, $text, $align='L', $style='', $fs=8){ $pdf->SetFont('Helvetica', $style, $fs); $pdf->SetXY($x,$y); $pdf->Cell($w,$h, htxt($text), 0, 0, $align); }
function ensurePageSpace($pdf, &$y, $neededH, $topY=8.0, $bottomMargin=8.0){
  $pageH = $pdf->GetPageHeight();
  if (($y + $neededH) > ($pageH - $bottomMargin)) { $pdf->AddPage(); $y = $topY; }
}
function fitCellText($pdf, $x, $y, $w, $h, $text, $align='L', $style='', $base=8, $min=6){
  $text = trim((string)$text); $fs = $base; $pdf->SetFont('Helvetica', $style, $fs);
  while ($fs > $min && $pdf->GetStringWidth(htxt($text)) > ($w-1.0)) { $fs -= 0.5; $pdf->SetFont('Helvetica', $style, $fs); }
  if ($pdf->GetStringWidth(htxt($text)) > ($w-1.0)) {
    while ($text !== '' && $pdf->GetStringWidth(htxt($text.'...')) > ($w-1.0)) { $text = substr($text, 0, -1); }
    $text .= '...';
  }
  cellText($pdf, $x, $y, $w, $h, $text, $align, $style, $fs);
}
function wrapWordsToLines($pdf, $text, $maxW, $fontFamily='Helvetica', $style='', $fs=7, $maxLines=2){
  $text = trim((string)$text); if ($text === '') return [''];
  $pdf->SetFont($fontFamily, $style, $fs); $words = preg_split('/\s+/', $text); $lines = []; $current = '';
  foreach ($words as $w) {
    $test = ($current === '') ? $w : ($current . ' ' . $w);
    if ($pdf->GetStringWidth(htxt($test)) <= $maxW) { $current = $test; } else {
      if ($current === '') {
        $cut = $w; while ($cut !== '' && $pdf->GetStringWidth(htxt($cut.'...')) > $maxW) { $cut = substr($cut, 0, -1); }
        $lines[] = $cut; $current = '';
      } else { $lines[] = $current; $current = $w; }
    }
    if (count($lines) >= $maxLines) break;
  }
  if (count($lines) < $maxLines && $current !== '') $lines[] = $current;
  if (count($lines) === $maxLines) {
    $last = $lines[$maxLines-1];
    if ($pdf->GetStringWidth(htxt($last)) > $maxW) { while ($last !== '' && $pdf->GetStringWidth(htxt($last.'...')) > $maxW) { $last = substr($last, 0, -1); } }
    $lines[$maxLines-1] = rtrim($last);
  }
  return $lines;
}
function labelCellWrap($pdf, $x0, $y0, $w, $h, $text){
  $base = 7.0; $min  = 5.6;
  for ($fs = $base; $fs >= $min; $fs -= 0.2) {
    $pdf->SetFont('Helvetica', 'B', $fs);
    if ($pdf->GetStringWidth(htxt($text)) <= ($w - 1.0)) { $pdf->SetXY($x0, $y0); $pdf->Cell($w, $h, htxt($text), 0, 0, 'L'); return; }
  }
  $fs = 6.0; $pdf->SetFont('Helvetica', 'B', $fs);
  $lines = wrapWordsToLines($pdf, $text, $w - 1.0, 'Helvetica', 'B', $fs, 2);
  $lineH = $h / 2; $startY = $y0 + 0.2; $pdf->SetXY($x0, $startY);
  $pdf->Cell($w, $lineH, htxt($lines[0] ?? ''), 0, 2, 'L'); $pdf->Cell($w, $lineH, htxt($lines[1] ?? ''), 0, 2, 'L');
}
function headerBar($pdf, $x, $y, $w, $h, $title){
  global $FILL, $BLUE; $pdf->SetFillColor($BLUE[0], $BLUE[1], $BLUE[2]); $pdf->Rect($x, $y, $w, $h, 'DF');
  $pdf->SetTextColor(255, 255, 255); cellText($pdf, $x, $y+0.7, $w, $h, $title, 'C', 'B', 8.5); $pdf->SetTextColor(0, 0, 0);
}
function wrapTextToLines($pdf, $text, $maxW, $font='Helvetica', $style='', $fs=7.2){
  $text = trim((string)$text); if ($text === '') return ['']; $pdf->SetFont($font, $style, $fs);
  $words = preg_split('/\s+/', $text); $lines = []; $line = '';
  foreach ($words as $w) {
    $test = ($line === '') ? $w : ($line.' '.$w);
    if ($pdf->GetStringWidth(htxt($test)) <= $maxW) { $line = $test; } else { if ($line !== '') $lines[] = $line; $line = $w; }
  }
  if ($line !== '') $lines[] = $line; return $lines;
}
function fitMultiTextToBox($pdf, $x, $y, $w, $h, $text, $font='Helvetica', $style='', $baseFs=7.2, $minFs=6.0, $lineH=3.6){
  for ($fs = $baseFs; $fs >= $minFs; $fs -= 0.2) {
    $lines = wrapTextToLines($pdf, $text, $w, $font, $style, $fs); $needH = count($lines) * $lineH;
    if ($needH <= $h) {
      $pdf->SetFont($font, $style, $fs); $pdf->SetXY($x, $y);
      foreach ($lines as $ln) { $pdf->Cell($w, $lineH, htxt($ln), 0, 2, 'L'); }
      return;
    }
  }
  $fs = $minFs; $pdf->SetFont($font, $style, $fs); $lines = wrapTextToLines($pdf, $text, $w, $font, $style, $fs);
  $maxLines = (int)floor($h / $lineH); $lines = array_slice($lines, 0, max(1,$maxLines));
  $last = end($lines); $last = rtrim($last);
  while ($last !== '' && $pdf->GetStringWidth(htxt($last.'...')) > $w) { $last = substr($last, 0, -1); }
  $lines[count($lines)-1] = $last.'...';
  $pdf->SetXY($x, $y); foreach ($lines as $ln) { $pdf->Cell($w, $lineH, htxt($ln), 0, 2, 'L'); }
}
function drawFillCell($pdf, $x, $y, $w, $h, $txt, $fillRGB = null, $border=1, $align='C', $fs=7, $bold=false){
  $pdf->SetXY($x,$y); $pdf->SetDrawColor(0,70,140);
  if ($fillRGB) {
    $pdf->SetFillColor($fillRGB[0], $fillRGB[1], $fillRGB[2]);
    if ($fillRGB[0] < 120 && $fillRGB[1] < 120) $pdf->SetTextColor(255,255,255); else $pdf->SetTextColor(0,0,0);
    $pdf->Rect($x,$y,$w,$h,'DF'); 
  } else { $pdf->SetTextColor(0,0,0); $pdf->Rect($x,$y,$w,$h,'D'); }
  $style = $bold ? 'B' : ''; $pad = 1.2;
  $needsWrap = (strpos($txt, "\n") !== false) || (strlen($txt) > 18);
  if ($needsWrap) {
    fitMultiTextToBox($pdf, $x + $pad, $y + 0.6, $w - ($pad*2), $h - 1.2, $txt, 'Helvetica', $style, $fs, max(5.6, $fs-1.4), 3.2);
  } else {
    $pdf->SetFont('Helvetica', $style, $fs); $pdf->SetXY($x,$y); $pdf->Cell($w,$h, htxt($txt), 0, 0, $align, false);
  }
  $pdf->SetTextColor(0,0,0);
}

// ==== TABLAS DINÁMICAS Y MATRIZ ====
function drawTablaRangosPeq($pdf, $x, $y, $w) {
  $cHead = [230, 230, 230];
  $c1  = [75, 110, 35];   $c2  = [120, 155, 45];  $c3  = [100, 220, 50];  $c4  = [240, 230, 50];
  $c5  = [210, 190, 30];  $c6  = [230, 140, 30];  $c7  = [240, 90, 20];   $c8  = [220, 0, 0];
  $c9  = [140, 0, 0];     $c10 = [70, 70, 70];    $c11 = [0, 0, 0];
  $hRow = 3.5; $cw = [$w * 0.25, $w * 0.35, $w * 0.40];
  $pdf->SetFillColor($cHead[0], $cHead[1], $cHead[2]);
  $pdf->Rect($x, $y, $w, $hRow, 'DF'); $pdf->Rect($x, $y, $w, $hRow, 'D');
  cellText($pdf, $x, $y, $w, $hRow, "Rangos de Pendientes", 'C', 'B', 5.8);
  $yy = $y + $hRow;
  drawFillCell($pdf, $x, $yy, $cw[0], $hRow, "Color", $cHead, 1, 'C', 5.8, true);
  drawFillCell($pdf, $x + $cw[0], $yy, $cw[1], $hRow, "Porcentaje (%)", $cHead, 1, 'C', 5.8, true);
  drawFillCell($pdf, $x + $cw[0] + $cw[1], $yy, $cw[2], $hRow, "Grados (°)", $cHead, 1, 'C', 5.8, true);
  $yy += $hRow;
  $rows = [
    [$c1,  "<= 10",    "0,00 a 5,71"], [$c2,  "11 a 20",  "5,71 a 11,31"], [$c3,  "21 a 30",  "11,31 a 16,70"],
    [$c4,  "31 a 40",  "16,70 a 21,80"], [$c5,  "41 a 50",  "21,80 a 26,57"], [$c6,  "51 a 60",  "26,57 a 30,96"],
    [$c7,  "61 a 70",  "30,96 a 34,99"], [$c8,  "71 a 84",  "34,99 a 40,03"], [$c9,  "85 a 98",  "40,03 a 44,42"],
    [$c10, "99 a 130", "44,42 a 52,43"], [$c11, "> 130",    "52,43 +"]
  ];
  foreach ($rows as $r) {
    $pdf->SetFillColor($r[0][0], $r[0][1], $r[0][2]); $pdf->Rect($x, $yy, $cw[0], $hRow, 'DF'); $pdf->Rect($x, $yy, $cw[0], $hRow, 'D');
    drawFillCell($pdf, $x + $cw[0], $yy, $cw[1], $hRow, $r[1], null, 1, 'C', 6.2);
    drawFillCell($pdf, $x + $cw[0] + $cw[1], $yy, $cw[2], $hRow, $r[2], null, 1, 'C', 6.2);
    $yy += $hRow;
  }
}

function drawDecisionMatrix($pdf, $x, $y, $w, $h, $tipo_matriz = 'TWINCH'){
  $cGreen = [118, 190, 70]; $cYellow = [255, 235, 90]; $cRed = [235, 55, 55]; $cHead = [230, 230, 230]; $cPend = [235, 235, 120]; 
  if ($tipo_matriz === 'FALCON') {
      $tit_1 = "Propuesta nueva Matriz de decisiones para equipo FALCON WINCH y TIMBER MAX (Modelo T-20) (TON)";
      $tit_2 = "Tonelajes necesarios en volteo según condiciones de suelo, humedad y roca para equipo FALCON WINCH (TON)";
      $rows = [
        ['0','10','0','6',    ['No asistir',$cGreen],  ['No asistir',$cGreen],  ['No asistir',$cGreen]],
        ['11','20','6','11',  ['No asistir',$cGreen],  ['No asistir',$cGreen],  ['Evaluar',$cYellow]],
        ['21','30','12','16', ['No asistir',$cGreen],  ['No asistir',$cGreen],  ['Asistir 5',$cRed]],
        ['31','40','17','22', ['No asistir',$cGreen],  ['Asistir 5',$cRed],    ['Asistir 7',$cRed]],
        ['41','50','23','26', ['Asistir 5',$cRed],    ['Asistir 9',$cRed],    ['Asistir 12',$cRed]],
        ['51','60','27','30', ['Asistir 8',$cRed],    ['Asistir 12',$cRed],   ['Asistir 14',$cRed]],
        ['61','70','31','35', ['Asistir 14',$cRed],   ['Asistir 14',$cRed],   ['Asistir 15',$cRed]],
        ['71','84','35','40', ['Asistir 15',$cRed],   ['Asistir 15',$cRed],   ['Asistir 15',$cRed]],
        ['85','98','40','44', ['Asistir 15',$cRed],   ['Asistir 15',$cRed],   ['Asistir 15',$cRed]],
        ['100','130','45','52',['Asistir 15',$cRed],  ['Asistir 15',$cRed],   ['Asistir 15',$cRed]],
      ];
  } else {
      $tit_1 = "Propuesta nueva Matriz de decisiones para equipo T-WINCH 30.2 (kN)";
      $tit_2 = "kN necesarios en volteo según condiciones de suelo, humedad y roca para equipo T-WINCH 30.2 (kN)";
      $rows = [
        ['0','10','0','6',    ['No asistir',$cGreen],  ['No asistir',$cGreen],  ['No asistir',$cGreen]],
        ['11','20','6','11',  ['No asistir',$cGreen],  ['No asistir',$cGreen],  ['Evaluar',$cYellow]],
        ['21','30','12','16', ['No asistir',$cGreen],  ['No asistir',$cGreen],  ['Asistir 50',$cRed]],
        ['31','40','17','22', ['No asistir',$cGreen],  ['Asistir 50',$cRed],    ['Asistir 70',$cRed]],
        ['41','50','23','26', ['Asistir 50',$cRed],    ['Asistir 85',$cRed],    ['Asistir 120',$cRed]],
        ['51','60','27','30', ['Asistir 80',$cRed],    ['Asistir 120',$cRed],   ['Asistir 140',$cRed]],
        ['61','70','31','35', ['Asistir 135',$cRed],   ['Asistir 140',$cRed],   ['Asistir 150',$cRed]],
        ['71','84','35','40', ['Asistir 150',$cRed],   ['Asistir 150',$cRed],   ['Asistir 150',$cRed]],
        ['85','98','40','44', ['Asistir 150',$cRed],   ['Asistir 150',$cRed],   ['Asistir 150',$cRed]],
        ['100','130','45','52',['Asistir 150',$cRed],  ['Asistir 150',$cRed],   ['Asistir 150',$cRed]],
      ];
  }
  $col = [0.08, 0.08, 0.08, 0.08, 0.23, 0.23, 0.22]; $cw = array_map(function($p) use ($w){ return $w * $p; }, $col);
  $hTitle1 = $h * 0.12; $hHeader = $h * 0.18; $hSub = $h * 0.14; $dataH = $h - ($hTitle1+$hHeader+$hSub); $rh = $dataH / max(1, count($rows));
  drawFillCell($pdf, $x, $y, $w, $hTitle1, $tit_1, $cHead, 1, 'C', 7.1, true); $yy = $y + $hTitle1; $xx = $x;
  drawFillCell($pdf, $xx, $yy, $cw[0]+$cw[1]+$cw[2]+$cw[3], $hHeader, "Pendiente", $cPend, 1, 'C', 7.2, true); $xx += $cw[0]+$cw[1]+$cw[2]+$cw[3];
  drawFillCell($pdf, $xx, $yy, $cw[4]+$cw[5]+$cw[6], $hHeader, $tit_2, $cPend, 1, 'C', 6.4, true); $yy = $yy + $hHeader; $xx = $x;
  drawFillCell($pdf, $xx, $yy, $cw[0]+$cw[1], $hSub, "Rangos de porcentaje\n%", null, 1, 'C', 6.8, false); $xx += ($cw[0]+$cw[1]);
  drawFillCell($pdf, $xx, $yy, $cw[2]+$cw[3], $hSub, "Grados", null, 1, 'C', 6.8, false); $xx += ($cw[2]+$cw[3]);
  drawFillCell($pdf, $xx, $yy, $cw[4], $hSub, "Suelo Seco", $cHead, 1, 'C', 6.6, true); $xx += $cw[4];
  drawFillCell($pdf, $xx, $yy, $cw[5], $hSub, "Suelo húmedo", $cHead, 1, 'C', 6.6, true); $xx += $cw[5];
  drawFillCell($pdf, $xx, $yy, $cw[6], $hSub, "Suelo saturado/Manto\nrocoso", $cHead, 1, 'C', 6.1, true); $yy = $yy + $hSub;
  foreach($rows as $r){
    $xx = $x;
    drawFillCell($pdf, $xx, $yy, $cw[0], $rh, $r[0], null, 1, 'C', 6.8); $xx += $cw[0];
    drawFillCell($pdf, $xx, $yy, $cw[1], $rh, $r[1], null, 1, 'C', 6.8); $xx += $cw[1];
    drawFillCell($pdf, $xx, $yy, $cw[2], $rh, $r[2], null, 1, 'C', 6.8); $xx += $cw[2];
    drawFillCell($pdf, $xx, $yy, $cw[3], $rh, $r[3], null, 1, 'C', 6.8); $xx += $cw[3];
    drawFillCell($pdf, $xx, $yy, $cw[4], $rh, $r[4][0], $r[4][1], 1, 'C', 6.8, true); $xx += $cw[4];
    drawFillCell($pdf, $xx, $yy, $cw[5], $rh, $r[5][0], $r[5][1], 1, 'C', 6.8, true); $xx += $cw[5];
    drawFillCell($pdf, $xx, $yy, $cw[6], $rh, $r[6][0], $r[6][1], 1, 'C', 6.8, true); $yy += $rh;
  }
  $pdf->SetTextColor(0,0,0);
}

function drawWarningBox($pdf, $x, $y, $w, $h, $tipo_matriz = 'TWINCH'){
  $pdf->SetFillColor(243, 233, 219); $pdf->Rect($x, $y, $w, $h, 'F');
  $orange = [226, 135, 15]; $gray = [95,95,95]; $pad = 3.0; $triW = min(16, $w * 0.22); $triH = $triW * 0.92;
  $cx = $x + $pad + ($triW/2); $cy = $y + ($h * 0.42);
  $pdf->SetDrawColor($orange[0], $orange[1], $orange[2]); $pdf->SetLineWidth(1.0);
  $x1 = $cx; $y1 = $cy - ($triH/2); $x2 = $cx - ($triW/2); $y2 = $cy + ($triH/2); $x3 = $cx + ($triW/2); $y3 = $cy + ($triH/2);
  $pdf->Line($x1,$y1,$x2,$y2); $pdf->Line($x2,$y2,$x3,$y3); $pdf->Line($x3,$y3,$x1,$y1);
  $pdf->SetTextColor($orange[0], $orange[1], $orange[2]); $pdf->SetFont('Helvetica','B',14); $pdf->SetXY($cx-2.2, $cy-6.0); $pdf->Cell(5,10,'!',0,0,'C');
  $titleX = $x + $pad + $triW + 3.5; $titleY = $y + $pad + 1.0;
  $pdf->SetFont('Helvetica','B',8.8); $pdf->SetXY($titleX, $titleY); $pdf->Cell($w - ($titleX - $x) - $pad, 4.2, htxt("Advertencia"), 0, 0, 'L');
  if ($tipo_matriz === 'FALCON') {
      $txt = "El uso adecuado de las tensiones debe estar relacionado con la pendiente y los puntos de contacto del cable de acero. Cabe destacar que el equipo es capaz de tensar el cable hasta un límite de 15 toneladas. A partir de este punto, si se requiere mayor tracción, la tecnología del sistema de asistencia ajusta automáticamente la tensión según las necesidades operativas del equipo, especialmente cuando se supera una fuerza de 15 toneladas, asegurando así la seguridad del sistema.";
  } else {
      $txt = "El uso adecuado de las tensiones debe estar relacionado con la pendiente y los puntos de contacto del cable de acero. Cabe destacar que el equipo es capaz de tensar el cable hasta un límite de 150 kN (aproximadamente 15 toneladas). A partir de este punto, si se requiere mayor tracción, la tecnología del sistema de asistencia ajusta automáticamente la tensión según las necesidades operativas del equipo, especialmente cuando se supera una fuerza de 150 kN, asegurando así la seguridad del sistema.";
  }
  $textX = $titleX; $textY = $titleY + 4.8; $textW = $w - ($textX - $x) - $pad; $mainH = 20.5;
  $pdf->SetTextColor(0, 0, 0); fitMultiTextToBox($pdf, $textX, $textY, $textW-1.5, $mainH, $txt, 'Helvetica', '', 5.9, 4.7, 2.5);
  $paramsY = $textY + $mainH + 0.6; $paramsH = $h - ($paramsY - $y) - 1.6;
  if ($paramsH > 8) {
      $paramsTxt = "Parametros a Considerar\n".
        "- Suelo seco: firme, con buena traccion y sin presencia barro.\n".
        "- Suelo humedo: cuando la maquina puede perder capacidad de adherencia/traccion producto de lluvias.\n".
        "- Suelo saturado y Manto Rocoso (Evaluacion): en pendiente entre 10 y 20% se debe asistir si la pendiente se va incrementando (torres y asistidos) y no se debe asistir si la pendiente va disminuyendo o se mantiene.\n".
        "El responsable de la evaluacion sera el operador del equipo, en caso de tener dudas con la decision sumar al Jefe de faena o lider.";
      $pdf->SetTextColor(0,0,0); fitMultiTextToBox($pdf, $textX, $paramsY, $textW-1.5, $paramsH, $paramsTxt, 'Helvetica', '', 5.2, 4.2, 2.28);
  }
  $pdf->SetTextColor(0,0,0); $pdf->SetDrawColor(0,70,140); $pdf->SetLineWidth(0.35);
}

function wrapLines($text){
  $text = trim((string)$text); if ($text === '') return []; $raw = preg_split('/\r\n|\r|\n/', $text); $out = [];
  foreach ($raw as $l){ $l = trim(preg_replace('/^[\-\*\x{2022}]+\s*/u', '', (string)$l)); if ($l !== '') $out[] = $l; }
  return $out;
}

// ===== Layout (mm) =====
$L = 6.5; $R = 209.5; $W = $R - $L;

// CINTA DE VISTA PREVIA (Alerta Roja)
$pdf->SetFillColor(231, 76, 60);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->SetXY(0, 0);
$pdf->Cell(216, 10, htxt("VISTA PREVIA DEL DOCUMENTO - NO VÁLIDO PARA FIRMA"), 0, 0, 'C', true);
$y0 = 13.0; // Lo bajamos un poco por la cinta

// ====== ENCABEZADO ======
$headerH = 18.0; $logoW = 50.0; $titleX = $L + $logoW; $titleW = $W - $logoW - 55.0; $metaW = 55.0; $metaX = $R - $metaW;
$pdf->SetFillColor(255, 255, 255); $pdf->Rect($L, $y0, $logoW, $headerH, 'F'); 
$pdf->SetFillColor($BLUE[0], $BLUE[1], $BLUE[2]); $pdf->Rect($titleX, $y0, $titleW, $headerH, 'F'); 
$pdf->SetFillColor($FILL[0], $FILL[1], $FILL[2]); $pdf->Rect($metaX, $y0, $metaW, $headerH, 'F'); 
$pdf->SetDrawColor($BLUE[0], $BLUE[1], $BLUE[2]); $pdf->SetLineWidth(0.5); rect($pdf, $L, $y0, $W, $headerH);                    
$pdf->SetLineWidth(0.35); vline($pdf, $titleX, $y0, $y0+$headerH); vline($pdf, $metaX, $y0, $y0+$headerH);              
hline($pdf, $titleX, $y0+6.0, $R); hline($pdf, $titleX, $y0+12.0, $R); vline($pdf, $metaX+16.0, $y0, $y0+6.0); vline($pdf, $metaX+16.0, $y0+6.0, $y0+12.0); vline($pdf, $metaX+40.0, $y0, $y0+6.0); vline($pdf, $metaX+40.0, $y0+6.0, $y0+12.0);
$pdf->SetTextColor(255, 255, 255); cellText($pdf, $titleX, $y0+1.2, $titleW, 5, "PLAN INTERVENCIÓN DE VOLTEO", 'C', 'B', 10); cellText($pdf, $titleX, $y0+7.3, $titleW, 5, "LÍNEAS DE MADEREO A INTERVENIR", 'C', 'B', 8.5); cellText($pdf, $titleX, $y0+13.3, $titleW, 4, "COSECHA", 'C', 'B', 8.0);
$pdf->SetTextColor(0, 0, 0); cellText($pdf, $metaX, $y0+1.2, 16.0, 5, "DOC:", 'C', 'B', 8); cellText($pdf, $metaX+16.0, $y0+1.2, 24.0, 5, "RE-TL-AP-01", 'C', 'B', 8); cellText($pdf, $metaX, $y0+7.3, 16.0, 5, "VERSION:", 'C', 'B', 8); cellText($pdf, $metaX+40.0, $y0+7.3, 15.0, 5, "2", 'C', 'B', 8);

$logoPath = __DIR__.'/templates/logo_millalemu.png';
if (file_exists($logoPath)) {
  $logoImgW = $logoW - 4.0; $logoImgH = $logoImgW * (175.0 / 600.0); $logoImgX = $L + ($logoW - $logoImgW) / 2; $logoImgY = $y0 + ($headerH - $logoImgH) / 2;          
  $pdf->Image($logoPath, $logoImgX, $logoImgY, $logoImgW, 0);
}

// ====== ANTECEDENTES GENERALES ======
$y = $y0 + $headerH + 2.0;
headerBar($pdf, $L, $y, $W, 6.0, "Antecedentes Generales"); $y += 6.0;
$hAnte = 56.0; $rowH  = 5.6; $xL = $L; $xC1 = $L + 28.0; $xC2 = $L + 83.0; $xC3 = $L + 120.0; $xC4 = $L + 168.0; $xR = $R; $xSub1 = $L + 141.0; $xSub2 = $L + 170.0;
$pdf->SetFillColor($FILL[0], $FILL[1], $FILL[2]); $pdf->Rect($xL, $y, $xC1-$xL, $hAnte, 'F'); $pdf->Rect($xC2, $y, $xC3-$xC2, $hAnte, 'F'); 
rect($pdf, $L, $y, $W, $hAnte); vline($pdf, $xC1, $y, $y+$hAnte); vline($pdf, $xC2, $y, $y+$hAnte); vline($pdf, $xC3, $y, $y+$hAnte); vline($pdf, $xSub1, $y+12.0, $y+24.0); vline($pdf, $xSub2, $y+12.0, $y+24.0);
for($i=1;$i<=10;$i++){ hline($pdf, $xL, $y + $rowH*$i, $xR); } hline($pdf, $xL, $y + $rowH*7, $xR);

$labelsLeft = ["Fecha", "", "Predio- Codigo", "Escenario", "Team-Equipo", "Tipo equipo de volteo", "Asistencia/Tipo", "Jefe de Faena", "Volteo cercano a tendido electrico", "Volteo cercano a camino publico"];
$labelW = ($xC1 - $xL) - 2.4; $labelH = $rowH - 0.4;
for($i=0;$i<count($labelsLeft);$i++){ labelCellWrap($pdf, $xL+1.2, $y + $rowH*$i + 0.4, $labelW, $labelH, $labelsLeft[$i]); }

cellText($pdf, $xC2+1.2, $y + $rowH*0 + 1.0, ($xC3-$xC2)-2.0, $rowH, "Temporada", 'L', 'B', 8); cellText($pdf, $xC2+1.2, $y + $rowH*1 + 1.0, ($xC3-$xC2)-2.0, $rowH, "Especie", 'L', 'B', 8); cellText($pdf, $xC2+1.2, $y + $rowH*3 + 1.0, ($xSub1-$xC2)-2.0, $rowH, "Superficie (ha)", 'L', 'B', 8); fitCellText($pdf, $xSub1+1.2, $y + $rowH*3 + 1.0, ($xSub2-$xSub1)-2.0, $rowH, "VOL TOTAL Esc (m3)", 'L', 'B', 6.8, 5.2); cellText($pdf, $xC2+1.2, $y + $rowH*4 + 1.0, ($xSub1-$xC2)-2.0, $rowH, "Arboles/hora", 'L', 'B', 8); cellText($pdf, $xC2+1.2, $y + $rowH*5 + 1.0, ($xC3-$xC2)-2.0, $rowH, "Uso de Pivotes", 'L', 'B', 8); fitCellText($pdf, $xC2+1.2, $y + $rowH*6 + 1.0, ($xC3-$xC2)-2.0, $rowH, "Tiempo estimado de volteo (Dias)", 'L', 'B', 7, 5.6); cellText($pdf, $xC2+1.2, $y + $rowH*7 + 1.0, ($xC3-$xC2)-2.0, $rowH, "Pendiente maxima (%)", 'L', 'B', 8); cellText($pdf, $xC2+1.2, $y + $rowH*8 + 1.0, ($xC3-$xC2)-2.0, $rowH, "Tipo de Suelo", 'L', 'B', 8); cellText($pdf, $xC2+1.2, $y + $rowH*9 + 1.0, ($xC3-$xC2)-2.0, $rowH, "Verificacion de permisos", 'L', 'B', 8); cellText($pdf, $xC2+1.2, $y + $rowH*10 + 1.0, ($xC3-$xC2)-2.0, $rowH, "Jornada", 'L', 'B', 8);

$fecha = $row['fecha'] ? date('d-m-Y', strtotime($row['fecha'])) : '';
$predioCodigo = trim(($row['predio'] ?? '') . ' // ' . ($row['codigo_predio'] ?? ''));
$escenario = $row['escenario'] ?: ($row['nombre_mapa'] ?? '');

$valsLeft = [$fecha, '', $predioCodigo, $escenario, $row['team_equipo'], $row['tecnologia'], $row['asistencia_tipo'], $row['jefe_faena'], boolSI($row['volteo_cerca_tendido_electrico']), boolSI($row['volteo_cerca_camino_publico'])];
for($i=0;$i<count($valsLeft);$i++){ fitCellText($pdf, $xC1+1.2, $y + $rowH*$i + 1.0, ($xC2-$xC1)-2.4, $rowH, $valsLeft[$i], 'L', '', 8, 6); }

fitCellText($pdf, $xC3+1.2, $y + $rowH*0 + 1.0, ($xR-$xC3)-2.4, $rowH, $row['temporada'], 'L', '', 8, 6); fitCellText($pdf, $xC3+1.2, $y + $rowH*1 + 1.0, ($xR-$xC3)-2.4, $rowH, $row['especie'], 'L', '', 8, 6); fitCellText($pdf, $xC3+1.2,  $y + $rowH*3 + 1.0, ($xSub1-$xC3)-2.4, $rowH, $row['superficie_ha'], 'R', '', 8, 6); fitCellText($pdf, $xSub1+1.2, $y + $rowH*3 + 1.0, ($xR-$xSub1)-2.4, $rowH, $row['volumen_total_m3'], 'R', '', 8, 6); fitCellText($pdf, $xC3+1.2,  $y + $rowH*4 + 1.0, ($xSub1-$xC3)-2.4, $rowH, $row['arboles_hora'], 'R', '', 8, 6); fitCellText($pdf, $xC3+1.2, $y + $rowH*5 + 1.0, ($xR-$xC3)-2.4, $rowH, boolSI($row['uso_pivotes']), 'L', '', 8, 6); fitCellText($pdf, $xC3+1.2, $y + $rowH*6 + 1.0, ($xR-$xC3)-2.4, $rowH, $row['tiempo_estimado_dias'], 'L', '', 8, 6); fitCellText($pdf, $xC3+1.2, $y + $rowH*7 + 1.0, ($xR-$xC3)-2.4, $rowH, $row['pendiente_max_pct'], 'L', '', 8, 6); fitCellText($pdf, $xC3+1.2, $y + $rowH*8 + 1.0, ($xR-$xC3)-2.4, $rowH, $row['tipo_suelo'], 'L', '', 7.5, 6); fitCellText($pdf, $xC3+1.2, $y + $rowH*9 + 1.0, ($xR-$xC3)-2.4, $rowH, $row['verif_permisos'], 'L', '', 7.5, 6); fitCellText($pdf, $xC3+1.2, $y + $rowH*10 + 1.0, ($xR-$xC3)-2.4, $rowH, $row['jornada'], 'L', '', 8, 6);

$y = $y + $hAnte + 2.0;

// ====== EXTRACCIÓN DE LA MATRIZ ======
$cons_raw = (string)($row['consideraciones'] ?? '');
$tipo_matriz = 'TWINCH'; 
if (strpos($cons_raw, '[MATRIZ:FALCON]') !== false) { $tipo_matriz = 'FALCON'; $cons_raw = str_replace('[MATRIZ:FALCON]', '', $cons_raw); } 
elseif (strpos($cons_raw, '[MATRIZ:TWINCH]') !== false) { $tipo_matriz = 'TWINCH'; $cons_raw = str_replace('[MATRIZ:TWINCH]', '', $cons_raw); }
$cons_raw = trim($cons_raw);

$itemsCons = wrapLines($cons_raw);
if (count($itemsCons) === 0) $itemsCons = [''];
$lineHText = 3.6; $padTop = 1.1; $padBottom = 1.1;
ensurePageSpace($pdf, $y, 8.0); headerBar($pdf, $L, $y, $W, 6.0, "Consideraciones importantes y medidas"); $y += 6.0;
foreach ($itemsCons as $i => $txt) {
  $wrapped = wrapTextToLines($pdf, $txt, $W-14, 'Helvetica', '', 7.0); $nLines = max(1, count($wrapped)); $hBox = max(7.0, ($nLines * $lineHText) + $padTop + $padBottom);
  ensurePageSpace($pdf, $y, $hBox + 0.6); rect($pdf, $L, $y, $W, $hBox);
  cellText($pdf, $L+2, $y + 1.2, 8, 4.5, ($i+1).".-", 'L', 'B', 8); fitMultiTextToBox($pdf, $L+10, $y + $padTop, $W-12, $hBox-($padTop+$padBottom), $txt, 'Helvetica', '', 7.0, 6.0, $lineHText); $y += $hBox;
}
$y += 2.0;

// ====== PLANO DE PENDIENTES ======
$hPlano = 72.0;
ensurePageSpace($pdf, $y, 6.0 + $hPlano + 2.0); headerBar($pdf, $L, $y, $W, 6.0, "Plano de Pendientes y Acta de Intervencion"); $y += 6.0;
rect($pdf, $L, $y, $W, $hPlano);
$wTabla = 50.0; $xTablaDer = ($L + $W) - $wTabla - 2.0;
drawTablaRangosPeq($pdf, $xTablaDer, $y + 2.0, $wTabla);

function pickValidImagePath($basePath) {
    $candidates = [$basePath . '.jpg', $basePath . '.jpeg', $basePath . '.png'];
    foreach ($candidates as $path) { if (is_file($path)) return $path; }
    return '';
}
function saveUploadedToTemp($fileKey) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return '';
    $ext = strtolower(pathinfo((string)$_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'])) return '';
    $dest = sys_get_temp_dir() . '/piv_prev_' . uniqid() . '.' . $ext;
    return copy($_FILES[$fileKey]['tmp_name'], $dest) ? $dest : '';
}
$img_path_1 = saveUploadedToTemp('imagen_plano') ?: pickValidImagePath(__DIR__ . '/uploads/plano_' . $row['id_mapa']);
$img_path_2 = saveUploadedToTemp('imagen_plano_2') ?: pickValidImagePath(__DIR__ . '/uploads/plano_2_' . $row['id_mapa']);

function drawMapWithComment($pdf, $img_path, $comentario, $x, $y, $w, $h) {
    $pdf->Image($img_path, $x, $y, $w, $h);
    if (trim((string)$comentario) !== '') {
        $capH = 6.0; $pdf->SetFillColor(255, 255, 255); $pdf->SetDrawColor(0, 70, 140); $pdf->Rect($x, $y + $h - $capH, $w, $capH, 'DF');
        fitCellText($pdf, $x + 1, $y + $h - $capH + 0.5, $w - 2, $capH - 1, (string)$comentario, 'C', 'B', 8, 5);
    }
}
$comentario_1 = trim($_POST['comentario_plano_1'] ?? '');
$comentario_2 = trim($_POST['comentario_plano_2'] ?? '');
$margen = 2.0; $wDisponible = $W - $wTabla - ($margen * 3); $hImg = $hPlano - ($margen * 2);
if ($img_path_1 !== '' && $img_path_2 !== '') {
    $wImg = ($wDisponible - $margen) / 2;
    drawMapWithComment($pdf, $img_path_1, $comentario_1, $L + $margen, $y + $margen, $wImg, $hImg);
    drawMapWithComment($pdf, $img_path_2, $comentario_2, $L + $margen + $wImg + $margen, $y + $margen, $wImg, $hImg);
} elseif ($img_path_1 !== '') {
    drawMapWithComment($pdf, $img_path_1, $comentario_1, $L + $margen, $y + $margen, $wDisponible, $hImg);
} elseif ($img_path_2 !== '') {
    drawMapWithComment($pdf, $img_path_2, $comentario_2, $L + $margen, $y + $margen, $wDisponible, $hImg);
} else {
    $pdf->SetTextColor(200, 200, 200); $pdf->SetFont('Helvetica', 'I', 14); $pdf->SetXY($L, $y + ($hPlano/2) - 5); $pdf->Cell($wDisponible, 10, "Sin planos en el servidor", 0, 0, 'C'); $pdf->SetTextColor(0, 0, 0);
}
$y += $hPlano + 2.0;

// ====== MATRIZ DE DECISIONES ======
$hAsis = 60.0;
ensurePageSpace($pdf, $y, 6.0 + $hAsis + 2.0); headerBar($pdf, $L, $y, $W, 6.0, "Tabla de asistencia en matriz de decisiones."); $y += 6.0;
rect($pdf, $L, $y, $W, $hAsis);
$m = 1.5; $ix = $L + $m; $iy = $y + $m; $iw = $W - ($m*2); $ih = $hAsis - ($m*2); $tableW = $iw * 0.58; $gap = 2.0; $warnW = $iw - $tableW - $gap;
drawDecisionMatrix($pdf, $ix, $iy, $tableW, $ih, $tipo_matriz); drawWarningBox($pdf, $ix + $tableW + $gap, $iy, $warnW, $ih, $tipo_matriz);
$y += $hAsis + 2.0;

// ====== TOMA CONOCIMIENTO (Nombres desde el Array POST) ======
$lista_firmas = [];
$supervisores_lectura = [19]; // Emiliano
$dests = $_POST['destinatarios'] ?? [];

if (!empty($dests)) {
    $in  = str_repeat('?,', count($dests) - 1) . '?';
    $st_u = $pdo->prepare("SELECT id_usuario, nombre_usuario, tipo_usuario FROM public.usuario WHERE id_usuario IN ($in) ORDER BY nombre_usuario ASC");
    $st_u->execute($dests);
    $users = $st_u->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $u) {
        $estado_txt = in_array($u['id_usuario'], $supervisores_lectura) ? '' : 'PENDIENTE';
        $lista_firmas[] = [ 'cargo' => ucfirst($u['tipo_usuario']), 'nombre' => $u['nombre_usuario'], 'estado' => $estado_txt ];
    }
}
$num_firmas = count($lista_firmas); if ($num_firmas === 0) { $num_firmas = 1; $lista_firmas[] = ['cargo' => '-', 'nombre' => 'No seleccionaste destinatarios en el borrador', 'estado' => '-']; }
$rowHeight = 6.0; $hToma = 6.0 + ($num_firmas * $rowHeight);
ensurePageSpace($pdf, $y, 6.0 + $hToma + 2.0); headerBar($pdf, $L, $y, $W, 6.0, "TOMA CONOCIMIENTO DE PLANIFICACION"); $y += 6.0;
$pdf->SetFillColor($FILL[0], $FILL[1], $FILL[2]); $pdf->Rect($L, $y, $W, 6.0, 'F'); rect($pdf, $L, $y, $W, $hToma);
$xCargo = $L + 48.0; $xNom = $L + 156.0; vline($pdf, $xCargo, $y, $y + $hToma); vline($pdf, $xNom, $y, $y + $hToma);
hline($pdf, $L, $y + 6.0, $R); cellText($pdf, $L, $y+0.9, ($xCargo-$L), 5, "Cargo", 'C', 'B', 8); cellText($pdf, $xCargo, $y+0.9, ($xNom-$xCargo), 5, "Nombre", 'C', 'B', 8); cellText($pdf, $xNom, $y+0.9, ($R-$xNom), 5, "Firma / Estado", 'C', 'B', 8);
$yFirma = $y + 6.0;
foreach ($lista_firmas as $f) {
  if ($yFirma > ($y + 6.0)) hline($pdf, $L, $yFirma, $R);
  cellText($pdf, $L, $yFirma+0.9, ($xCargo-$L), 5, $f['cargo'], 'C', '', 8); cellText($pdf, $xCargo, $yFirma+0.9, ($xNom-$xCargo), 5, $f['nombre'], 'C', '', 8);
  $pdf->SetTextColor(127, 140, 141); cellText($pdf, $xNom, $yFirma+0.9, ($R-$xNom), 5, $f['estado'], 'C', 'B', 8); $pdf->SetTextColor(0,0,0);
  $yFirma += $rowHeight;
}
$y += $hToma + 2.0;

// ===== OUTPUT =====
$pdf->Output('I', 'Vista_Previa_Borrador.pdf');
exit;
