<?php
// Solo para el servidor integrado de PHP:  php -S localhost:8080 router.php
// (En Apache/XAMPP la carpeta data/ ya queda protegida por su .htaccess.)
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (preg_match('#^/(data|stitch_src)(/|$)#', $ruta) || str_contains($ruta, '/.') || preg_match('#\.(sqlite|md|csv)$#i', $ruta)) {
    http_response_code(403);
    exit('Acceso denegado');
}
return false;
