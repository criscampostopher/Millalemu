<?php
require 'lib/fpdf/fpdf.php';
require 'lib/fpdi/src/autoload.php';
$p = new setasign\Fpdi\Fpdi();
$p->setSourceFile('templates/piv_template.pdf');
$i = $p->importPage(1);
$s = $p->getTemplateSize($i);
echo $s['width'] . 'x' . $s['height'] . ' ' . $s['orientation'];
