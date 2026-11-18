<?php
// Núcleo compartido: base de datos, configuración y utilidades.
declare(strict_types=1);

date_default_timezone_set('America/Bogota');

// En el servidor (Docker/Dokploy) la base vive fuera de la carpeta web, en un volumen persistente.
define('DATA_DIR', rtrim(getenv('FIESTA_DATA_DIR') ?: __DIR__ . '/data', '/'));

const CONFIG_DEFECTO = [
    'empresa'          => 'Agropecuaria Chiquique',
    'evento_nombre'    => 'Fiesta de Fin de Año',
    'evento_fecha'     => '2026-12-18',
    'evento_hora'      => '19:00',
    'lugar'            => 'Hacienda Campestre Chiquique',
    'salon'            => 'Gran Salón de Recepciones',
    'fecha_limite'     => '2026-12-10',
    'max_acompanantes' => '5',
    'edad_nino'        => '12',
    'contacto'         => 'Gestión Humana (Ext. 104)',
    'registro_abierto' => '1',
];

const PARENTESCOS = ['Cónyuge / Compañero(a)', 'Hijo(a)', 'Padre / Madre', 'Hermano(a)', 'Otro familiar directo'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    if (!is_file(DATA_DIR . '/.htaccess')) file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");

    $pdo = new PDO('sqlite:' . DATA_DIR . '/fiesta.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS empleados (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cedula TEXT NOT NULL UNIQUE,
            nombre TEXT NOT NULL,
            activo INTEGER NOT NULL DEFAULT 1,
            creado_en TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS registros (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empleado_id INTEGER NOT NULL UNIQUE REFERENCES empleados(id) ON DELETE CASCADE,
            asiste INTEGER NOT NULL,
            codigo TEXT NOT NULL UNIQUE,
            creado_en TEXT NOT NULL,
            actualizado_en TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS acompanantes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registro_id INTEGER NOT NULL REFERENCES registros(id) ON DELETE CASCADE,
            tipo TEXT NOT NULL,              -- 'adulto' (edad >= límite) | 'nino'
            nombre TEXT NOT NULL DEFAULT '',
            parentesco TEXT NOT NULL DEFAULT '',
            sexo TEXT NOT NULL DEFAULT '',   -- 'M' | 'F' | ''
            edad INTEGER
        );
        CREATE TABLE IF NOT EXISTS config (clave TEXT PRIMARY KEY, valor TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario TEXT NOT NULL UNIQUE,
            clave_hash TEXT NOT NULL
        );
    ");
    return $pdo;
}

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $c = CONFIG_DEFECTO;
        foreach (db()->query('SELECT clave, valor FROM config') as $r) {
            if (array_key_exists($r['clave'], $c)) $c[$r['clave']] = $r['valor'];
        }
    }
    return $c;
}

function cfg_guardar(array $valores): void
{
    $st = db()->prepare('INSERT INTO config (clave, valor) VALUES (?, ?) ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor');
    foreach ($valores as $k => $v) {
        if (array_key_exists($k, CONFIG_DEFECTO)) $st->execute([$k, (string) $v]);
    }
}

/** Datos del evento que ve el empleado en el registro público. */
function cfg_publica(): array
{
    $c = cfg();
    return [
        'empresa' => $c['empresa'], 'evento' => $c['evento_nombre'], 'fecha' => $c['evento_fecha'], 'hora' => $c['evento_hora'],
        'lugar' => $c['lugar'], 'salon' => $c['salon'], 'limite' => $c['fecha_limite'], 'contacto' => $c['contacto'],
        'max' => (int) $c['max_acompanantes'], 'edadNino' => (int) $c['edad_nino'], 'parentescos' => PARENTESCOS,
    ];
}

/** ¿Se pueden crear o modificar registros hoy? */
function registro_editable(): bool
{
    $c = cfg();
    return $c['registro_abierto'] === '1' && date('Y-m-d') <= $c['fecha_limite'];
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function ahora(): string
{
    return date('Y-m-d H:i:s');
}

function solo_digitos(string $s): string
{
    return preg_replace('/\D+/', '', $s);
}

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('fiesta_chq');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function registro_de(int $empleadoId): ?array
{
    $st = db()->prepare('SELECT * FROM registros WHERE empleado_id = ?');
    $st->execute([$empleadoId]);
    $r = $st->fetch();
    if (!$r) return null;
    $st = db()->prepare('SELECT tipo, nombre, parentesco, sexo, edad FROM acompanantes WHERE registro_id = ? ORDER BY id');
    $st->execute([$r['id']]);
    $ac = $st->fetchAll();
    return [
        'asiste'  => (bool) $r['asiste'],
        'codigo'  => $r['codigo'],
        'fecha'   => $r['actualizado_en'],
        'adultos' => array_values(array_filter($ac, fn($a) => $a['tipo'] === 'adulto')),
        'ninos'   => array_values(array_filter($ac, fn($a) => $a['tipo'] === 'nino')),
    ];
}

function nuevo_codigo(): string
{
    $anio = substr(cfg()['evento_fecha'], 0, 4);
    $st = db()->prepare('SELECT 1 FROM registros WHERE codigo = ?');
    do {
        $codigo = sprintf('CHQ-%s-%04d', $anio, random_int(0, 9999));
        $st->execute([$codigo]);
    } while ($st->fetch());
    return $codigo;
}

/**
 * Lista plana de asistentes (titulares + acompañantes) con filtros.
 * Filtros: q, tipo (titular|acompanante|nino), sexo (M|F), edad_min, edad_max, menor_de.
 */
function asistentes(array $f): array
{
    $sql = "SELECT * FROM (
        SELECT 'Titular' AS tipo, e.nombre AS nombre, 'Titular' AS parentesco, '' AS sexo, NULL AS edad,
               e.cedula AS cedula_titular, e.nombre AS titular,
               r.codigo AS codigo, r.actualizado_en AS fecha, r.id AS rid, 0 AS orden
        FROM registros r JOIN empleados e ON e.id = r.empleado_id WHERE r.asiste = 1
        UNION ALL
        SELECT CASE a.tipo WHEN 'nino' THEN 'Niño' ELSE 'Acompañante' END, a.nombre, a.parentesco, a.sexo, a.edad,
               e.cedula, e.nombre, r.codigo, r.actualizado_en, r.id, a.id
        FROM acompanantes a JOIN registros r ON r.id = a.registro_id
        JOIN empleados e ON e.id = r.empleado_id WHERE r.asiste = 1
    ) t WHERE 1 = 1";
    $p = [];
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $sql .= ' AND (nombre LIKE :q OR titular LIKE :q OR cedula_titular LIKE :q OR codigo LIKE :q)';
        $p[':q'] = "%$q%";
    }
    $tipos = ['titular' => 'Titular', 'acompanante' => 'Acompañante', 'nino' => 'Niño'];
    if (isset($tipos[$f['tipo'] ?? ''])) {
        $sql .= ' AND tipo = :tipo';
        $p[':tipo'] = $tipos[$f['tipo']];
    }
    if (in_array($f['sexo'] ?? '', ['M', 'F'], true)) {
        $sql .= ' AND sexo = :sexo';
        $p[':sexo'] = $f['sexo'];
    }
    foreach (['edad_min' => '>=', 'edad_max' => '<=', 'menor_de' => '<'] as $k => $op) {
        if (isset($f[$k]) && $f[$k] !== '' && is_numeric($f[$k])) {
            $sql .= " AND edad IS NOT NULL AND edad $op :$k";
            $p[":$k"] = (int) $f[$k];
        }
    }
    $sql .= ' ORDER BY titular COLLATE NOCASE, rid, orden';
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function sexo_texto(string $s, string $tipo = ''): string
{
    if ($s === 'M') return $tipo === 'Niño' ? 'Niño' : 'Masculino';
    if ($s === 'F') return $tipo === 'Niño' ? 'Niña' : 'Femenino';
    return '';
}

/* ---------- Exportación ---------- */

function exportar_csv(string $archivo, array $encabezados, array $filas): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel respete las tildes
    fputcsv($out, $encabezados, ';', '"', '\\');
    foreach ($filas as $f) {
        // Evita que Excel interprete el contenido como fórmula
        $f = array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $f);
        fputcsv($out, $f, ';', '"', '\\');
    }
    fclose($out);
}

function exportar_xlsx(string $archivo, array $encabezados, array $filas): void
{
    if (!class_exists('ZipArchive')) {
        exportar_csv($archivo, $encabezados, $filas);
        return;
    }
    $col = function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    };
    $x = fn($v) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach (array_merge([$encabezados], $filas) as $r => $fila) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($fila) as $c => $v) {
            $ref = $col($c) . ($r + 1);
            if ($r > 0 && is_int($v)) {
                $xml .= "<c r=\"$ref\"><v>$v</v></c>";
            } elseif ($v !== null && $v !== '') {
                $xml .= "<c r=\"$ref\" t=\"inlineStr\"" . ($r === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Datos" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $archivo . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
}

/* ---------- Plantilla HTML compartida (tokens del diseño) ---------- */

function html_head(string $titulo): void
{
    ?>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
<title><?= h($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Manrope:wght@600;700&display=swap"/>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"/>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { theme: { extend: {
  colors: { "on-tertiary-fixed": "#410004", "on-secondary-fixed": "#241a00", "on-surface": "#181c20", "background": "#f7f9ff", "inverse-on-surface": "#eef1f6", "error-container": "#ffdad6", "on-secondary-container": "#745c00", "on-primary-container": "#86af99", "on-primary-fixed": "#002114", "secondary-container": "#fed65b", "primary-fixed-dim": "#a5d0b9", "outline": "#717973", "on-error-container": "#93000a", "secondary-fixed-dim": "#e9c349", "surface-container-highest": "#e0e3e8", "on-secondary-fixed-variant": "#574500", "secondary": "#735c00", "inverse-surface": "#2d3135", "surface-variant": "#e0e3e8", "outline-variant": "#c1c8c2", "primary-container": "#1b4332", "on-tertiary-fixed-variant": "#8a1b1e", "tertiary-fixed": "#ffdad7", "primary": "#012d1d", "surface": "#f7f9ff", "surface-container-low": "#f1f4f9", "surface-dim": "#d7dadf", "tertiary-fixed-dim": "#ffb3ae", "on-tertiary-container": "#ff807a", "inverse-primary": "#a5d0b9", "surface-bright": "#f7f9ff", "on-tertiary": "#ffffff", "surface-container": "#ebeef3", "on-surface-variant": "#414844", "tertiary-container": "#7a0d15", "error": "#ba1a1a", "surface-tint": "#3f6653", "surface-container-high": "#e5e8ee", "secondary-fixed": "#ffe088", "on-secondary": "#ffffff", "tertiary": "#550008", "on-error": "#ffffff", "on-primary-fixed-variant": "#274e3d", "on-primary": "#ffffff", "primary-fixed": "#c1ecd4", "on-background": "#181c20", "surface-container-lowest": "#ffffff" },
  borderRadius: { DEFAULT: "0.25rem", lg: "0.5rem", xl: "0.75rem", "2xl": "1rem", full: "9999px" },
  fontFamily: { sans: ["Inter", "system-ui", "sans-serif"], display: ["Manrope", "Inter", "sans-serif"] }
} } };
</script>
<style>
  body { -webkit-tap-highlight-color: transparent; }
  .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400; vertical-align: middle; }
  .ms-fill { font-variation-settings: 'FILL' 1, 'wght' 400; }
  .campo { height: 44px; width: 100%; background: #fff; border: 1px solid rgba(27,67,50,.2); border-radius: .5rem; padding: 0 .75rem; font-size: .9375rem; color: #181c20; }
  .campo:focus { outline: 2px solid #D4AF37; box-shadow: 0 0 0 3px rgba(212,175,55,.15); border-color: transparent; }
  .tarjeta { background: #fff; border: 1px solid rgba(27,67,50,.08); border-radius: 1rem; box-shadow: 0 4px 16px -2px rgba(27,67,50,.05), 0 1px 3px 0 rgba(0,0,0,.02); }
</style>
    <?php
}
