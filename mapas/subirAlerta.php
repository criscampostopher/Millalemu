<?php
$directorio = __DIR__ . "/uploads_alertas/"; // carpeta para imágenes y JSON
if (!file_exists($directorio)) mkdir($directorio, 0777, true);

$response = ["success" => false, "message" => "", "alert" => null];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre = $_POST["nombre"] ?? "";
    $descripcion = $_POST["descripcion"] ?? "";
    $lat = $_POST["lat"] ?? "";
    $lng = $_POST["lng"] ?? "";

    // Subir imagen si existe
    $imagenRuta = "";
    if (isset($_FILES["imagen"])) {
        $imagenNombre = uniqid() . "_" . basename($_FILES["imagen"]["name"]);
        $destinoImagen = $directorio . $imagenNombre;
        if (move_uploaded_file($_FILES["imagen"]["tmp_name"], $destinoImagen)) {
            $imagenRuta = "mapas/uploads_alertas/" . $imagenNombre; // ruta relativa correcta para HTML
        }
    }

    // Crear ID único para la alerta
    $id = uniqid("alerta_");

    // Guardar objeto alerta en JSON
    $alerta = [
        "id" => $id,
        "nombre" => $nombre,
        "descripcion" => $descripcion,
        "lat" => $lat,
        "lng" => $lng,
        "imagen" => $imagenRuta,
        "created_at" => date("Y-m-d H:i:s")
    ];

    $jsonArchivo = $directorio . $id . ".json";
    file_put_contents($jsonArchivo, json_encode($alerta, JSON_PRETTY_PRINT));

    $response["success"] = true;
    $response["message"] = "Alerta creada correctamente";
    $response["alert"] = $alerta;
}

header("Content-Type: application/json");
echo json_encode($response);
