<?php
// Panel de administración: empleados, asistentes, exportación y configuración.
declare(strict_types=1);
require __DIR__ . '/../lib.php';
iniciar_sesion();

$pdo = db();
$hayAdmin = (bool) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$esAdmin = $hayAdmin && !empty($_SESSION['admin_id']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

function ir(string $destino = ''): never
{
    header('Location: index.php' . ($destino ? '?' . $destino : ''));
    exit;
}
function aviso(string $texto, string $tipo = 'ok'): void
{
    $_SESSION['aviso'] = [$tipo, $texto];
}
function csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"/>';
}
function leer_csv_empleados(string $ruta): array
{
    $txt = (string) file_get_contents($ruta);
    $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $lineas = preg_split('/\r\n|\r|\n/', trim($txt));
    $sep = substr_count($lineas[0] ?? '', ';') >= substr_count($lineas[0] ?? '', ',') ? ';' : ',';
    $filas = [];
    foreach ($lineas as $l) {
        if (trim($l) === '') continue;
        $f = array_map('trim', str_getcsv($l, $sep, '"', '\\'));
        $cedula = solo_digitos($f[0] ?? '');
        if ($cedula === '' || ($f[1] ?? '') === '') continue; // encabezado o fila incompleta
        $filas[] = [$cedula, $f[1]];
    }
    return $filas;
}

/* ================= Acciones POST ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        aviso('La sesión del formulario expiró. Inténtalo de nuevo.', 'error');
        ir();
    }
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_admin' && !$hayAdmin) {
        $u = trim((string) ($_POST['usuario'] ?? ''));
        $c = (string) ($_POST['clave'] ?? '');
        if (strlen($u) < 3 || strlen($c) < 8) {
            aviso('El usuario debe tener al menos 3 caracteres y la contraseña al menos 8.', 'error');
        } elseif ($c !== ($_POST['clave2'] ?? '')) {
            aviso('Las contraseñas no coinciden.', 'error');
        } else {
            $pdo->prepare('INSERT INTO admins (usuario, clave_hash) VALUES (?, ?)')->execute([$u, password_hash($c, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $pdo->lastInsertId();
            aviso('Cuenta de administrador creada. ¡Bienvenido!');
        }
        ir();
    }

    if ($accion === 'entrar' && $hayAdmin) {
        $st = $pdo->prepare('SELECT * FROM admins WHERE usuario = ?');
        $st->execute([trim((string) ($_POST['usuario'] ?? ''))]);
        $a = $st->fetch();
        if ($a && password_verify((string) ($_POST['clave'] ?? ''), $a['clave_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $a['id'];
        } else {
            sleep(1);
            aviso('Usuario o contraseña incorrectos.', 'error');
        }
        ir();
    }

    if (!$esAdmin) ir();

    switch ($accion) {
        case 'salir':
            unset($_SESSION['admin_id']);
            session_regenerate_id(true);
            ir();

        case 'emp_guardar':
            $id = (int) ($_POST['id'] ?? 0);
            $cedula = solo_digitos((string) ($_POST['cedula'] ?? ''));
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $datos = [$cedula, $nombre, isset($_POST['activo']) ? 1 : 0];
            if (strlen($cedula) < 5 || $nombre === '') {
                aviso('Cédula (mínimo 5 dígitos) y nombre son obligatorios.', 'error');
                ir('p=empleados' . ($id ? "&editar=$id" : ''));
            }
            try {
                if ($id) {
                    $pdo->prepare('UPDATE empleados SET cedula = ?, nombre = ?, activo = ? WHERE id = ?')->execute([...$datos, $id]);
                    aviso('Empleado actualizado.');
                } else {
                    $pdo->prepare('INSERT INTO empleados (cedula, nombre, activo, creado_en) VALUES (?, ?, ?, ?)')->execute([...$datos, ahora()]);
                    aviso('Empleado registrado.');
                }
            } catch (PDOException $e) {
                aviso('Ya existe un empleado con la cédula ' . $cedula . '.', 'error');
                ir('p=empleados' . ($id ? "&editar=$id" : ''));
            }
            ir('p=empleados');

        case 'emp_eliminar':
            $pdo->prepare('DELETE FROM empleados WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            aviso('Empleado eliminado junto con su registro.');
            ir('p=empleados');

        case 'reg_reiniciar':
            $pdo->prepare('DELETE FROM registros WHERE empleado_id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            aviso('Registro reiniciado: el empleado puede volver a responder.');
            ir('p=empleados');

        case 'emp_importar':
            if (!is_uploaded_file($_FILES['archivo']['tmp_name'] ?? '')) {
                aviso('Selecciona un archivo CSV.', 'error');
                ir('p=empleados');
            }
            $filas = leer_csv_empleados($_FILES['archivo']['tmp_name']);
            $existe = $pdo->prepare('SELECT 1 FROM empleados WHERE cedula = ?');
            $ins = $pdo->prepare('INSERT INTO empleados (cedula, nombre, activo, creado_en) VALUES (?, ?, 1, ?)');
            $act = $pdo->prepare('UPDATE empleados SET nombre = ? WHERE cedula = ?');
            $nuevos = $actualizados = 0;
            $pdo->beginTransaction();
            foreach ($filas as [$ced, $nom]) {
                $existe->execute([$ced]);
                if ($existe->fetch()) {
                    $act->execute([$nom, $ced]);
                    $actualizados++;
                } else {
                    $ins->execute([$ced, $nom, ahora()]);
                    $nuevos++;
                }
            }
            $pdo->commit();
            aviso($filas ? "Importación lista: $nuevos nuevos, $actualizados actualizados." : 'No se encontraron filas válidas. Usa las columnas: cedula;nombre', $filas ? 'ok' : 'error');
            ir('p=empleados');

        case 'config_guardar':
            $v = array_intersect_key($_POST, CONFIG_DEFECTO);
            $v['registro_abierto'] = isset($_POST['registro_abierto']) ? '1' : '0';
            $fechaOk = fn($f) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f);
            if (!$fechaOk($v['evento_fecha'] ?? '') || !$fechaOk($v['fecha_limite'] ?? '') || !preg_match('/^\d{2}:\d{2}$/', (string) ($v['evento_hora'] ?? ''))) {
                aviso('Revisa las fechas y la hora.', 'error');
                ir('p=config');
            }
            $v['max_acompanantes'] = (string) max(0, min(20, (int) ($v['max_acompanantes'] ?? 5)));
            $v['edad_nino'] = (string) max(1, min(18, (int) ($v['edad_nino'] ?? 12)));
            cfg_guardar(array_map(fn($x) => trim((string) $x), $v));
            aviso('Configuración guardada.');
            ir('p=config');

        case 'clave_cambiar':
            $st = $pdo->prepare('SELECT clave_hash FROM admins WHERE id = ?');
            $st->execute([$_SESSION['admin_id']]);
            $nueva = (string) ($_POST['nueva'] ?? '');
            if (!password_verify((string) ($_POST['actual'] ?? ''), (string) $st->fetchColumn())) {
                aviso('La contraseña actual no es correcta.', 'error');
            } elseif (strlen($nueva) < 8) {
                aviso('La nueva contraseña debe tener al menos 8 caracteres.', 'error');
            } else {
                $pdo->prepare('UPDATE admins SET clave_hash = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
                aviso('Contraseña actualizada.');
            }
            ir('p=config');
    }
    ir();
}

/* ================= Consultas compartidas ================= */
function empleados_lista(array $f): array
{
    $sql = "SELECT e.*, r.asiste, r.codigo, r.actualizado_en AS respondio,
                   (SELECT COUNT(*) FROM acompanantes a WHERE a.registro_id = r.id AND a.tipo = 'adulto') AS n_adultos,
                   (SELECT COUNT(*) FROM acompanantes a WHERE a.registro_id = r.id AND a.tipo = 'nino') AS n_ninos
            FROM empleados e LEFT JOIN registros r ON r.empleado_id = e.id WHERE 1 = 1";
    $p = [];
    if (trim((string) ($f['q'] ?? '')) !== '') {
        $sql .= ' AND (e.nombre LIKE :q OR e.cedula LIKE :q)';
        $p[':q'] = '%' . trim($f['q']) . '%';
    }
    $sql .= match ($f['estado'] ?? '') {
        'pendiente' => ' AND r.id IS NULL',
        'asiste' => ' AND r.asiste = 1',
        'no_asiste' => ' AND r.asiste = 0',
        default => '',
    };
    $st = db()->prepare($sql . ' ORDER BY e.nombre COLLATE NOCASE');
    $st->execute($p);
    return $st->fetchAll();
}
function estado_texto(array $e): string
{
    return $e['asiste'] === null ? 'Pendiente' : ($e['asiste'] ? 'Asiste' : 'No asiste');
}

/* ================= Exportaciones ================= */
if ($esAdmin && isset($_GET['exportar'])) {
    $fmt = ($_GET['fmt'] ?? 'xlsx') === 'csv' ? 'exportar_csv' : 'exportar_xlsx';
    $sello = date('Y-m-d_Hi');
    if ($_GET['exportar'] === 'asistentes') {
        $filas = array_map(fn($a) => [
            $a['tipo'], $a['nombre'] !== '' ? $a['nombre'] : '(sin nombre)', $a['parentesco'], sexo_texto($a['sexo'], $a['tipo']),
            $a['edad'] === null ? '' : (int) $a['edad'], $a['titular'], $a['cedula_titular'], $a['codigo'], $a['fecha'],
        ], asistentes($_GET));
        $fmt("asistentes_fiesta_$sello", ['Tipo', 'Nombre', 'Parentesco', 'Sexo', 'Edad', 'Titular (empleado)', 'Cédula titular', 'Código de pase', 'Fecha de registro'], $filas);
    } elseif ($_GET['exportar'] === 'empleados') {
        $filas = array_map(fn($e) => [
            $e['cedula'], $e['nombre'], $e['activo'] ? 'Sí' : 'No', estado_texto($e),
            (int) $e['n_adultos'], (int) $e['n_ninos'], $e['asiste'] ? 1 + $e['n_adultos'] + $e['n_ninos'] : 0, (string) $e['codigo'], (string) $e['respondio'],
        ], empleados_lista($_GET));
        $fmt("empleados_fiesta_$sello", ['Cédula', 'Nombre', 'Activo', 'Estado', 'Acompañantes', 'Niños', 'Total personas', 'Código de pase', 'Fecha de respuesta'], $filas);
    } elseif ($_GET['exportar'] === 'plantilla') {
        exportar_csv('plantilla_empleados', ['cedula', 'nombre'], [['1098765432', 'Nombre Apellido']]);
    }
    exit;
}

/* ================= Vista ================= */
$aviso = $_SESSION['aviso'] ?? null;
unset($_SESSION['aviso']);
$c = cfg();
$p = $esAdmin ? ($_GET['p'] ?? 'resumen') : '';
$qs = fn(array $extra = []) => h(http_build_query(array_filter(array_merge($_GET, $extra), fn($v) => $v !== '' && $v !== null)));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php html_head('Administración · ' . $c['evento_nombre']); ?>
<style>
  th { text-align: left; font-size: .6875rem; text-transform: uppercase; letter-spacing: .05em; color: #414844; padding: .625rem .75rem; white-space: nowrap; }
  td { padding: .625rem .75rem; border-top: 1px solid rgba(27,67,50,.06); font-size: .875rem; vertical-align: middle; }
  .btn { height: 40px; padding: 0 1rem; border-radius: .5rem; font-size: .875rem; font-weight: 600; display: inline-flex; align-items: center; gap: .375rem; white-space: nowrap; }
  .btn-p { background: #1b4332; color: #fff; } .btn-p:hover { background: #012d1d; }
  .btn-s { border: 1.5px solid #D4AF37; color: #1b4332; } .btn-s:hover { background: rgba(212,175,55,.08); }
  .btn-t { background: #ebeef3; color: #1b4332; }
  .chip { display: inline-block; padding: .125rem .625rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; white-space: nowrap; }
  .chip-v { background: rgba(27,67,50,.08); color: #1b4332; } .chip-d { background: rgba(212,175,55,.18); color: #735c00; } .chip-r { background: rgba(158,42,43,.1); color: #9E2A2B; } .chip-g { background: #ebeef3; color: #414844; }
  label.et { display: block; font-size: .75rem; font-weight: 600; color: #414844; }
  label.et > .campo { margin-top: .25rem; }
</style>
</head>
<body class="bg-surface text-on-surface min-h-screen">

<?php if (!$esAdmin): ?>
<main class="min-h-screen flex items-center justify-center p-4">
  <form method="post" class="tarjeta w-full max-w-sm p-6 space-y-4">
    <?= csrf() ?>
    <div class="text-center space-y-1">
      <div class="w-14 h-14 mx-auto rounded-2xl bg-primary-container text-secondary-fixed flex items-center justify-center"><span class="material-symbols-outlined text-3xl">admin_panel_settings</span></div>
      <h1 class="font-display text-2xl font-bold text-primary"><?= $hayAdmin ? 'Administración' : 'Crear administrador' ?></h1>
      <p class="text-sm text-on-surface-variant"><?= $hayAdmin ? h($c['evento_nombre'] . ' · ' . $c['empresa']) : 'Primera vez: define el usuario y la contraseña con los que entrarás al panel.' ?></p>
    </div>
    <?php if ($aviso): ?><p class="rounded-lg p-3 text-sm font-medium <?= $aviso[0] === 'error' ? 'bg-error-container text-on-error-container' : 'bg-primary-fixed text-on-primary-fixed' ?>"><?= h($aviso[1]) ?></p><?php endif; ?>
    <input type="hidden" name="accion" value="<?= $hayAdmin ? 'entrar' : 'crear_admin' ?>"/>
    <label class="et">Usuario<input class="campo" name="usuario" required autocomplete="username" autofocus/></label>
    <label class="et">Contraseña<input class="campo" type="password" name="clave" required <?= $hayAdmin ? 'autocomplete="current-password"' : 'minlength="8" autocomplete="new-password"' ?>/></label>
    <?php if (!$hayAdmin): ?><label class="et">Repite la contraseña<input class="campo" type="password" name="clave2" required minlength="8" autocomplete="new-password"/></label><?php endif; ?>
    <button class="btn btn-p w-full justify-center"><?= $hayAdmin ? 'Entrar' : 'Crear cuenta y entrar' ?></button>
    <a href="../" class="block text-center text-sm text-on-surface-variant underline">Volver al registro</a>
  </form>
</main>

<?php else: ?>
<header class="bg-primary text-on-primary">
  <div class="max-w-7xl mx-auto px-4 lg:px-8 h-16 flex items-center gap-4">
    <span class="material-symbols-outlined text-secondary-fixed">celebration</span>
    <div class="leading-tight mr-auto"><p class="font-display font-bold"><?= h($c['evento_nombre']) ?></p><p class="text-xs text-primary-fixed-dim"><?= h($c['empresa']) ?> · Administración</p></div>
    <a href="../" target="_blank" class="text-sm text-primary-fixed hover:underline hidden sm:inline">Ver registro público ↗</a>
    <form method="post"><?= csrf() ?><input type="hidden" name="accion" value="salir"/><button class="text-sm font-semibold text-secondary-fixed">Salir</button></form>
  </div>
  <nav class="max-w-7xl mx-auto px-4 lg:px-8 flex gap-1 overflow-x-auto">
    <?php foreach (['resumen' => ['dashboard', 'Resumen'], 'empleados' => ['badge', 'Empleados'], 'asistentes' => ['groups', 'Asistentes'], 'config' => ['settings', 'Configuración']] as $k => [$icono, $t]): ?>
      <a href="?p=<?= $k ?>" class="px-4 py-2.5 text-sm font-semibold rounded-t-lg flex items-center gap-1.5 whitespace-nowrap <?= $p === $k ? 'bg-surface text-primary' : 'text-primary-fixed hover:bg-white/10' ?>"><span class="material-symbols-outlined text-lg"><?= $icono ?></span><?= $t ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<main class="max-w-7xl mx-auto px-4 lg:px-8 py-6 space-y-6">
<?php if ($aviso): ?><p role="status" class="rounded-lg p-3 text-sm font-medium <?= $aviso[0] === 'error' ? 'bg-error-container text-on-error-container' : 'bg-primary-fixed text-on-primary-fixed' ?>"><?= h($aviso[1]) ?></p><?php endif; ?>

<?php if ($p === 'resumen'):
    $t = $pdo->query("SELECT
        (SELECT COUNT(*) FROM empleados WHERE activo = 1) AS empleados,
        (SELECT COUNT(*) FROM registros r JOIN empleados e ON e.id = r.empleado_id WHERE r.asiste = 1) AS asisten,
        (SELECT COUNT(*) FROM registros r JOIN empleados e ON e.id = r.empleado_id WHERE r.asiste = 0) AS no_asisten,
        (SELECT COUNT(*) FROM empleados e WHERE activo = 1 AND NOT EXISTS (SELECT 1 FROM registros r WHERE r.empleado_id = e.id)) AS pendientes,
        (SELECT COUNT(*) FROM acompanantes a JOIN registros r ON r.id = a.registro_id WHERE r.asiste = 1 AND a.tipo = 'adulto') AS acomp,
        (SELECT COUNT(*) FROM acompanantes a JOIN registros r ON r.id = a.registro_id WHERE r.asiste = 1 AND a.tipo = 'nino') AS ninos")->fetch();
    $totalPersonas = $t['asisten'] + $t['acomp'] + $t['ninos'];
    $porEdad = $pdo->query("SELECT a.edad, SUM(a.sexo = 'M') AS m, SUM(a.sexo = 'F') AS f FROM acompanantes a JOIN registros r ON r.id = a.registro_id WHERE r.asiste = 1 AND a.tipo = 'nino' GROUP BY a.edad ORDER BY a.edad")->fetchAll();
    $respondieron = $t['empleados'] ? round(100 * ($t['empleados'] - $t['pendientes']) / $t['empleados']) : 0;
    $tarjetas = [
        ['Total de asistentes', $totalPersonas, 'Titulares + acompañantes + niños', 'groups', '?p=asistentes'],
        ['Empleados que asisten', $t['asisten'], "de {$t['empleados']} empleados activos", 'how_to_reg', '?p=empleados&estado=asiste'],
        ['No asisten', $t['no_asisten'], 'Respondieron que no', 'event_busy', '?p=empleados&estado=no_asiste'],
        ['Sin responder', $t['pendientes'], "$respondieron% ya respondió", 'pending', '?p=empleados&estado=pendiente'],
        ['Acompañantes', $t['acomp'], "De {$c['edad_nino']} años o más", 'group', '?p=asistentes&tipo=acompanante'],
        ['Niños', $t['ninos'], "Menores de {$c['edad_nino']} años", 'child_care', '?p=asistentes&tipo=nino'],
    ];
?>
  <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($tarjetas as [$tit, $num, $sub, $icono, $url]): ?>
      <a href="<?= $url ?>" class="tarjeta p-5 hover:shadow-md transition block">
        <div class="flex justify-between items-start"><span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant"><?= $tit ?></span><span class="material-symbols-outlined text-secondary"><?= $icono ?></span></div>
        <p class="font-display text-4xl font-bold text-primary mt-1"><?= (int) $num ?></p>
        <p class="text-xs text-on-surface-variant mt-1"><?= h($sub) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="grid lg:grid-cols-2 gap-6">
    <section class="tarjeta overflow-hidden">
      <div class="p-5 pb-3"><h2 class="font-display text-lg font-semibold">Niños por edad y sexo</h2><p class="text-sm text-on-surface-variant">Para la compra de obsequios.</p></div>
      <?php if ($porEdad): ?>
      <table class="w-full"><thead class="bg-surface-container-low"><tr><th>Edad</th><th>Niños</th><th>Niñas</th><th>Total</th><th></th></tr></thead><tbody>
        <?php foreach ($porEdad as $f): ?><tr><td class="font-semibold"><?= (int) $f['edad'] ?> <?= $f['edad'] == 1 ? 'año' : 'años' ?></td><td><?= (int) $f['m'] ?></td><td><?= (int) $f['f'] ?></td><td class="font-semibold"><?= $f['m'] + $f['f'] ?></td><td><a class="text-secondary font-semibold text-xs underline" href="?p=asistentes&tipo=nino&edad_min=<?= (int) $f['edad'] ?>&edad_max=<?= (int) $f['edad'] ?>">Ver</a></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php else: ?><p class="px-5 pb-5 text-sm text-on-surface-variant">Aún no hay niños registrados.</p><?php endif; ?>
    </section>
    <section class="tarjeta p-5 space-y-3">
      <h2 class="font-display text-lg font-semibold">Evento</h2>
      <dl class="text-sm grid grid-cols-3 gap-y-2">
        <dt class="text-on-surface-variant">Fecha</dt><dd class="col-span-2 font-semibold"><?= h($c['evento_fecha'] . ' · ' . $c['evento_hora']) ?></dd>
        <dt class="text-on-surface-variant">Lugar</dt><dd class="col-span-2 font-semibold"><?= h($c['lugar']) ?></dd>
        <dt class="text-on-surface-variant">Cierre de registro</dt><dd class="col-span-2 font-semibold"><?= h($c['fecha_limite']) ?> <span class="chip <?= registro_editable() ? 'chip-v' : 'chip-r' ?>"><?= registro_editable() ? 'Abierto' : 'Cerrado' ?></span></dd>
      </dl>
      <div class="flex flex-wrap gap-2 pt-2">
        <a class="btn btn-p" href="?exportar=asistentes"><span class="material-symbols-outlined text-lg">download</span>Exportar asistentes (Excel)</a>
        <a class="btn btn-s" href="?p=config">Editar evento</a>
      </div>
    </section>
  </div>

<?php elseif ($p === 'empleados'):
    $lista = empleados_lista($_GET);
    $editar = null;
    if (!empty($_GET['editar'])) {
        $st = $pdo->prepare('SELECT * FROM empleados WHERE id = ?');
        $st->execute([(int) $_GET['editar']]);
        $editar = $st->fetch() ?: null;
    }
?>
  <div class="grid lg:grid-cols-3 gap-6">
    <form method="post" class="tarjeta p-5 space-y-3 lg:col-span-2" id="form-empleado">
      <?= csrf() ?><input type="hidden" name="accion" value="emp_guardar"/><input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>"/>
      <h2 class="font-display text-lg font-semibold"><?= $editar ? 'Editar empleado' : 'Registrar empleado' ?></h2>
      <div class="grid sm:grid-cols-2 gap-3">
        <label class="et">Cédula<input class="campo" name="cedula" inputmode="numeric" required pattern="[0-9.\s]{5,15}" value="<?= h($editar['cedula'] ?? '') ?>" placeholder="Sin puntos ni comas"/></label>
        <label class="et">Nombre completo<input class="campo" name="nombre" required maxlength="100" value="<?= h($editar['nombre'] ?? '') ?>"/></label>
      </div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="activo" class="w-4 h-4 accent-[#1b4332]" <?= ($editar['activo'] ?? 1) ? 'checked' : '' ?>/> Activo (puede registrarse en la fiesta)</label>
      <div class="flex gap-2"><button class="btn btn-p"><span class="material-symbols-outlined text-lg">save</span><?= $editar ? 'Guardar cambios' : 'Registrar empleado' ?></button><?php if ($editar): ?><a class="btn btn-t" href="?p=empleados">Cancelar</a><?php endif; ?></div>
    </form>
    <form method="post" enctype="multipart/form-data" class="tarjeta p-5 space-y-3">
      <?= csrf() ?><input type="hidden" name="accion" value="emp_importar"/>
      <h2 class="font-display text-lg font-semibold">Importar desde Excel (CSV)</h2>
      <p class="text-sm text-on-surface-variant">Columnas: <code class="text-xs bg-surface-container px-1 rounded">cedula;nombre</code>. Si la cédula ya existe, se actualizan sus datos. <a class="underline text-secondary font-semibold" href="?exportar=plantilla">Descargar plantilla</a></p>
      <input type="file" name="archivo" accept=".csv,text/csv" required class="block w-full text-sm file:mr-3 file:h-10 file:px-3 file:rounded-lg file:border-0 file:bg-surface-container file:font-semibold file:text-primary"/>
      <button class="btn btn-s"><span class="material-symbols-outlined text-lg">upload</span>Importar</button>
    </form>
  </div>

  <section class="tarjeta overflow-hidden">
    <form method="get" class="p-4 flex flex-wrap gap-3 items-end">
      <input type="hidden" name="p" value="empleados"/>
      <label class="et grow min-w-[12rem]">Buscar<input class="campo" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Nombre o cédula"/></label>
      <label class="et">Estado<select class="campo" name="estado"><?php foreach (['' => 'Todos', 'pendiente' => 'Pendientes', 'asiste' => 'Asisten', 'no_asiste' => 'No asisten'] as $k => $t): ?><option value="<?= $k ?>" <?= ($_GET['estado'] ?? '') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-p">Filtrar</button><a class="btn btn-t" href="?p=empleados">Limpiar</a>
      <span class="ml-auto flex gap-2"><a class="btn btn-s" href="?<?= $qs(['exportar' => 'empleados', 'p' => null]) ?>"><span class="material-symbols-outlined text-lg">download</span>Excel</a><a class="btn btn-t" href="?<?= $qs(['exportar' => 'empleados', 'fmt' => 'csv', 'p' => null]) ?>">CSV</a></span>
    </form>
    <p class="px-4 pb-2 text-sm text-on-surface-variant"><strong class="text-on-surface"><?= count($lista) ?></strong> empleados</p>
    <div class="overflow-x-auto"><table class="w-full"><thead class="bg-surface-container-low"><tr><th>Cédula</th><th>Nombre</th><th>Estado</th><th>Grupo</th><th>Pase</th><th class="text-right">Acciones</th></tr></thead><tbody>
      <?php foreach ($lista as $e): ?>
      <tr class="<?= $e['activo'] ? '' : 'opacity-50' ?>">
        <td class="font-mono text-xs"><?= h($e['cedula']) ?></td>
        <td class="font-semibold"><?= h($e['nombre']) ?><?= $e['activo'] ? '' : ' <span class="chip chip-g">Inactivo</span>' ?></td>
        <td><span class="chip <?= $e['asiste'] === null ? 'chip-d' : ($e['asiste'] ? 'chip-v' : 'chip-r') ?>"><?= estado_texto($e) ?></span></td>
        <td class="whitespace-nowrap"><?php if ($e['asiste']): ?><a class="underline" href="?p=asistentes&q=<?= h($e['cedula']) ?>"><?= 1 + $e['n_adultos'] + $e['n_ninos'] ?> pers.</a> <span class="text-xs text-on-surface-variant">(<?= (int) $e['n_ninos'] ?> niños)</span><?php else: ?>—<?php endif; ?></td>
        <td class="font-mono text-xs"><?= $e['asiste'] ? h($e['codigo']) : '—' ?></td>
        <td><div class="flex justify-end gap-1">
          <a class="btn btn-t !h-8 !px-2" title="Editar" href="?p=empleados&editar=<?= (int) $e['id'] ?>#form-empleado"><span class="material-symbols-outlined text-lg">edit</span></a>
          <?php if ($e['asiste'] !== null): ?><form method="post" onsubmit="return confirm('¿Borrar la respuesta de <?= h(addslashes($e['nombre'])) ?>? Podrá registrarse de nuevo.')"><?= csrf() ?><input type="hidden" name="accion" value="reg_reiniciar"/><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"/><button class="btn btn-t !h-8 !px-2" title="Reiniciar registro"><span class="material-symbols-outlined text-lg">restart_alt</span></button></form><?php endif; ?>
          <form method="post" onsubmit="return confirm('¿Eliminar a <?= h(addslashes($e['nombre'])) ?> y su registro? Esta acción no se puede deshacer.')"><?= csrf() ?><input type="hidden" name="accion" value="emp_eliminar"/><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"/><button class="btn btn-t !h-8 !px-2 !text-[#9E2A2B]" title="Eliminar"><span class="material-symbols-outlined text-lg">delete</span></button></form>
        </div></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="6" class="text-center text-on-surface-variant !py-10">No hay empleados<?= empty($_GET['q']) && empty($_GET['estado']) ? ' todavía. Registra el primero arriba o importa un CSV.' : ' con ese filtro.' ?></td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

<?php elseif ($p === 'asistentes'):
    $lista = asistentes($_GET);
    $n = ['Titular' => 0, 'Acompañante' => 0, 'Niño' => 0];
    foreach ($lista as $a) $n[$a['tipo']]++;
    $rapidos = ["Menores de {$c['edad_nino']}" => ['menor_de' => $c['edad_nino']], 'Menores de 18' => ['menor_de' => 18], 'Menores de 5' => ['menor_de' => 5], 'Mayores de edad (acompañantes)' => ['edad_min' => 18], 'Solo niñas' => ['tipo' => 'nino', 'sexo' => 'F'], 'Solo niños' => ['tipo' => 'nino', 'sexo' => 'M']];
?>
  <section class="tarjeta overflow-hidden">
    <form method="get" class="p-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 items-end">
      <input type="hidden" name="p" value="asistentes"/>
      <label class="et col-span-2">Buscar<input class="campo" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Nombre, titular, cédula o código"/></label>
      <label class="et">Tipo<select class="campo" name="tipo"><?php foreach (['' => 'Todos', 'titular' => 'Titulares', 'acompanante' => 'Acompañantes', 'nino' => 'Niños'] as $k => $t): ?><option value="<?= $k ?>" <?= ($_GET['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
      <label class="et">Sexo<select class="campo" name="sexo"><?php foreach (['' => 'Todos', 'M' => 'Masculino / niño', 'F' => 'Femenino / niña'] as $k => $t): ?><option value="<?= $k ?>" <?= ($_GET['sexo'] ?? '') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
      <label class="et">Edad desde<input class="campo" type="number" min="0" max="110" name="edad_min" value="<?= h($_GET['edad_min'] ?? '') ?>"/></label>
      <label class="et">Edad hasta<input class="campo" type="number" min="0" max="110" name="edad_max" value="<?= h($_GET['edad_max'] ?? '') ?>"/></label>
      <label class="et">Menores de<input class="campo" type="number" min="1" max="110" name="menor_de" value="<?= h($_GET['menor_de'] ?? '') ?>" placeholder="años"/></label>
      <div class="col-span-2 md:col-span-4 lg:col-span-7 flex flex-wrap gap-2 items-center">
        <button class="btn btn-p"><span class="material-symbols-outlined text-lg">filter_alt</span>Filtrar</button><a class="btn btn-t" href="?p=asistentes">Limpiar</a>
        <?php foreach ($rapidos as $t => $f): ?><a class="chip chip-d hover:brightness-95" href="?p=asistentes&<?= h(http_build_query($f)) ?>"><?= h($t) ?></a><?php endforeach; ?>
        <span class="ml-auto flex gap-2"><a class="btn btn-s" href="?<?= $qs(['exportar' => 'asistentes', 'p' => null]) ?>"><span class="material-symbols-outlined text-lg">download</span>Exportar Excel</a><a class="btn btn-t" href="?<?= $qs(['exportar' => 'asistentes', 'fmt' => 'csv', 'p' => null]) ?>">CSV</a></span>
      </div>
    </form>
    <p class="px-4 pb-2 text-sm text-on-surface-variant"><strong class="text-on-surface"><?= count($lista) ?></strong> personas · <?= $n['Titular'] ?> titulares · <?= $n['Acompañante'] ?> acompañantes · <?= $n['Niño'] ?> niños <span class="text-xs">(los filtros de edad no incluyen a los titulares, que no registran edad)</span></p>
    <div class="overflow-x-auto"><table class="w-full"><thead class="bg-surface-container-low"><tr><th>Tipo</th><th>Nombre</th><th>Edad</th><th>Sexo</th><th>Parentesco</th><th>Titular</th><th>Cédula titular</th><th>Pase</th></tr></thead><tbody>
      <?php foreach ($lista as $a): ?>
      <tr>
        <td><span class="chip <?= ['Titular' => 'chip-v', 'Acompañante' => 'chip-g', 'Niño' => 'chip-d'][$a['tipo']] ?>"><?= $a['tipo'] ?></span></td>
        <td class="font-semibold"><?= $a['nombre'] !== '' ? h($a['nombre']) : '<span class="font-normal text-on-surface-variant">(sin nombre)</span>' ?></td>
        <td><?= $a['edad'] === null ? '—' : (int) $a['edad'] ?></td>
        <td><?= h(sexo_texto($a['sexo'], $a['tipo'])) ?: '—' ?></td>
        <td><?= h($a['parentesco']) ?></td>
        <td><?= h($a['titular']) ?></td>
        <td class="font-mono text-xs"><?= h($a['cedula_titular']) ?></td>
        <td class="font-mono text-xs"><?= h($a['codigo']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="8" class="text-center text-on-surface-variant !py-10">No hay asistentes con ese filtro.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

<?php elseif ($p === 'config'): ?>
  <div class="grid lg:grid-cols-3 gap-6">
    <form method="post" class="tarjeta p-5 space-y-3 lg:col-span-2">
      <?= csrf() ?><input type="hidden" name="accion" value="config_guardar"/>
      <h2 class="font-display text-lg font-semibold">Datos del evento</h2>
      <div class="grid sm:grid-cols-2 gap-3">
        <label class="et">Empresa<input class="campo" name="empresa" required value="<?= h($c['empresa']) ?>"/></label>
        <label class="et">Nombre del evento<input class="campo" name="evento_nombre" required value="<?= h($c['evento_nombre']) ?>"/></label>
        <label class="et">Fecha del evento<input class="campo" type="date" name="evento_fecha" required value="<?= h($c['evento_fecha']) ?>"/></label>
        <label class="et">Hora<input class="campo" type="time" name="evento_hora" required value="<?= h($c['evento_hora']) ?>"/></label>
        <label class="et">Lugar<input class="campo" name="lugar" value="<?= h($c['lugar']) ?>"/></label>
        <label class="et">Salón / detalle<input class="campo" name="salon" value="<?= h($c['salon']) ?>"/></label>
        <label class="et">Fecha límite de registro y cambios<input class="campo" type="date" name="fecha_limite" required value="<?= h($c['fecha_limite']) ?>"/></label>
        <label class="et">Contacto de ayuda<input class="campo" name="contacto" value="<?= h($c['contacto']) ?>"/></label>
        <label class="et">Máximo de acompañantes por empleado<input class="campo" type="number" min="0" max="20" name="max_acompanantes" value="<?= h($c['max_acompanantes']) ?>"/></label>
        <label class="et">Se considera niño al menor de (años)<input class="campo" type="number" min="1" max="18" name="edad_nino" value="<?= h($c['edad_nino']) ?>"/></label>
      </div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="registro_abierto" class="w-4 h-4 accent-[#1b4332]" <?= $c['registro_abierto'] === '1' ? 'checked' : '' ?>/> Registro abierto (desmárcalo para cerrarlo antes de la fecha límite)</label>
      <button class="btn btn-p"><span class="material-symbols-outlined text-lg">save</span>Guardar configuración</button>
    </form>
    <form method="post" class="tarjeta p-5 space-y-3 h-fit">
      <?= csrf() ?><input type="hidden" name="accion" value="clave_cambiar"/>
      <h2 class="font-display text-lg font-semibold">Cambiar contraseña</h2>
      <label class="et">Contraseña actual<input class="campo" type="password" name="actual" required autocomplete="current-password"/></label>
      <label class="et">Nueva contraseña<input class="campo" type="password" name="nueva" required minlength="8" autocomplete="new-password"/></label>
      <button class="btn btn-s">Actualizar contraseña</button>
    </form>
  </div>
<?php else: ir(); endif; ?>
</main>
<?php endif; ?>
</body>
</html>
