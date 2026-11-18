<?php
// API pública del flujo de registro (JSON).
declare(strict_types=1);
require __DIR__ . '/lib.php';
iniciar_sesion();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function fallo(string $mensaje, int $codigo = 400): never
{
    responder(['error' => $mensaje], $codigo);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fallo('Método no permitido.', 405);

$entrada = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($entrada)) fallo('Solicitud inválida.');
$accion = $_GET['accion'] ?? '';
$cfg = cfg();

try {
    switch ($accion) {
        case 'consultar': {
            // Freno simple contra adivinación masiva de cédulas
            $_SESSION['intentos'] = array_filter($_SESSION['intentos'] ?? [], fn($t) => $t > time() - 300);
            if (count($_SESSION['intentos']) >= 15) fallo('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.', 429);
            $_SESSION['intentos'][] = time();

            $cedula = solo_digitos((string) ($entrada['cedula'] ?? ''));
            if (strlen($cedula) < 5) fallo('Ingresa un número de cédula válido.');
            $st = db()->prepare('SELECT id, cedula, nombre FROM empleados WHERE cedula = ? AND activo = 1');
            $st->execute([$cedula]);
            $emp = $st->fetch();
            if (!$emp) {
                unset($_SESSION['empleado_id']);
                fallo('No encontramos esta cédula en la lista de colaboradores. Verifica el número o comunícate con ' . $cfg['contacto'] . '.', 404);
            }
            $_SESSION['empleado_id'] = (int) $emp['id'];
            $registro = registro_de((int) $emp['id']);
            if (!$registro && !registro_editable()) {
                fallo('El plazo de registro ya cerró. Para cualquier novedad comunícate con ' . $cfg['contacto'] . '.', 403);
            }
            unset($emp['id']);
            responder(['empleado' => $emp, 'registro' => $registro, 'editable' => registro_editable()]);
        }

        case 'guardar': {
            $empId = (int) ($_SESSION['empleado_id'] ?? 0);
            if (!$empId) fallo('Tu sesión expiró. Vuelve a ingresar tu cédula.', 401);
            if (!registro_editable()) fallo('El plazo para registrar o modificar ya cerró. Comunícate con ' . $cfg['contacto'] . '.', 403);

            $asiste = !empty($entrada['asiste']);
            $limite = (int) $cfg['edad_nino'];
            $max = (int) $cfg['max_acompanantes'];
            $personas = [];

            if ($asiste) {
                $adultos = is_array($entrada['adultos'] ?? null) ? $entrada['adultos'] : [];
                $ninos = is_array($entrada['ninos'] ?? null) ? $entrada['ninos'] : [];
                if (count($adultos) + count($ninos) > $max) fallo("Puedes registrar máximo $max acompañantes.");

                foreach ($adultos as $i => $a) {
                    $n = $i + 1;
                    $nombre = trim(mb_substr((string) ($a['nombre'] ?? ''), 0, 80));
                    $edad = filter_var($a['edad'] ?? null, FILTER_VALIDATE_INT);
                    $parentesco = (string) ($a['parentesco'] ?? '');
                    $sexo = in_array($a['sexo'] ?? '', ['M', 'F'], true) ? $a['sexo'] : '';
                    if ($nombre === '') fallo("Falta el nombre del acompañante $n.");
                    if (!in_array($parentesco, PARENTESCOS, true)) fallo("Selecciona el parentesco del acompañante $n.");
                    if ($edad === false || $edad < $limite || $edad > 110) fallo("La edad del acompañante $n debe estar entre $limite y 110 años. Los menores de $limite se registran como niños.");
                    $personas[] = ['adulto', $nombre, $parentesco, $sexo, $edad];
                }
                foreach ($ninos as $i => $a) {
                    $n = $i + 1;
                    $nombre = trim(mb_substr((string) ($a['nombre'] ?? ''), 0, 80));
                    $edad = filter_var($a['edad'] ?? null, FILTER_VALIDATE_INT);
                    if (!in_array($a['sexo'] ?? '', ['M', 'F'], true)) fallo("Indica si el niño $n es niño o niña.");
                    if ($edad === false || $edad < 0 || $edad >= $limite) fallo("Selecciona la edad del niño $n (menor de $limite años).");
                    $personas[] = ['nino', $nombre, 'Niño(a) del núcleo familiar', $a['sexo'], $edad];
                }
            }

            $pdo = db();
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT id FROM registros WHERE empleado_id = ?');
            $st->execute([$empId]);
            $regId = $st->fetchColumn();
            if ($regId) {
                $pdo->prepare('UPDATE registros SET asiste = ?, actualizado_en = ? WHERE id = ?')->execute([(int) $asiste, ahora(), $regId]);
                $pdo->prepare('DELETE FROM acompanantes WHERE registro_id = ?')->execute([$regId]);
            } else {
                $pdo->prepare('INSERT INTO registros (empleado_id, asiste, codigo, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$empId, (int) $asiste, nuevo_codigo(), ahora(), ahora()]);
                $regId = $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO acompanantes (registro_id, tipo, nombre, parentesco, sexo, edad) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($personas as $p) $ins->execute([$regId, ...$p]);
            $pdo->commit();

            responder(['registro' => registro_de($empId), 'editable' => true]);
        }

        case 'salir':
            unset($_SESSION['empleado_id']);
            responder(['ok' => true]);

        default:
            fallo('Acción desconocida.', 404);
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[fiesta] ' . $e->getMessage());
    fallo('Ocurrió un error inesperado. Inténtalo de nuevo.', 500);
}
