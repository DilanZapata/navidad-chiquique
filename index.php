<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$c = cfg();
$anio = substr($c['evento_fecha'], 0, 4);
$cfgPublica = cfg_publica();
// Que el navegador nunca muestre una copia vieja con datos del evento desactualizados
header('Cache-Control: no-store, max-age=0');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php html_head($c['evento_nombre'] . ' ' . $anio . ' · ' . $c['empresa']); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
  ::-webkit-scrollbar { display: none; }
  @media print {
    body { background: #fff !important; }
    .no-print { display: none !important; }
    #app { padding: 0 !important; max-width: 420px !important; }
    .tarjeta { box-shadow: none !important; }
  }
</style>
</head>
<body class="bg-surface text-on-surface min-h-screen selection:bg-secondary-fixed">
<main id="app" class="w-full max-w-lg mx-auto px-4 py-4 space-y-4"></main>
<noscript><p style="padding:2rem;text-align:center">Esta página necesita JavaScript para funcionar.</p></noscript>

<script>
const CFG = <?= json_encode($cfgPublica, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
let ANIO = CFG.fecha.slice(0, 4);
// El servidor reenvía los datos del evento en cada consulta: así un cambio hecho en el panel se ve sin recargar.
function actualizarCFG(c) { if (c) { Object.assign(CFG, c); ANIO = CFG.fecha.slice(0, 4); } }
const S = { paso: 1, emp: null, reg: null, editable: true, asiste: true, adultos: [], ninos: [], certifico: false, error: '', enviando: false };
const app = document.getElementById('app');

/* ---------- Utilidades ---------- */
const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
const ic = (n, cls = '', fill = false) => `<span class="material-symbols-outlined ${fill ? 'ms-fill' : ''} ${cls}">${n}</span>`;
const cc = c => 'C.C. ' + Number(c).toLocaleString('es-CO');
const primerNombre = n => String(n).trim().split(/\s+/).slice(0, 2).join(' ');
const iniciales = n => String(n).trim().split(/\s+/).slice(0, 2).map(p => p[0] || '').join('').toUpperCase();
const plural = (n, s, p) => `${n} ${n === 1 ? s : p}`;
const mayus = s => s.charAt(0).toUpperCase() + s.slice(1);
function fechaLarga(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return mayus(new Date(y, m - 1, d).toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' }));
}
function horaTexto(hm) {
  const [h, m] = hm.split(':').map(Number);
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}
async function api(accion, datos = {}) {
  let r;
  try {
    r = await fetch('api.php?accion=' + accion, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos) });
  } catch (e) { throw new Error('No hay conexión con el servidor. Revisa tu internet e inténtalo de nuevo.'); }
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(j.error || 'Ocurrió un error. Inténtalo de nuevo.');
  return j;
}
const total = () => 1 + S.adultos.length + S.ninos.length;

/* ---------- Piezas comunes ---------- */
const logo = `<svg class="h-9 w-9 shrink-0" viewBox="0 0 40 40" fill="none"><rect width="40" height="40" rx="10" fill="#1b4332"/><path d="M20 31V18m0 0c0-5 3-9 9-10 0 6-3 10-9 10Zm0 4c0-4-3-7-8-8 0 5 3 8 8 8Z" stroke="#ffe088" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

function cabecera(titulo, atras) {
  return `<header class="no-print sticky top-0 z-40 -mx-4 px-4 bg-surface/90 backdrop-blur-xl shadow-[0_1px_8px_rgba(27,67,50,0.06)]">
    <div class="h-16 flex items-center gap-2">
      ${atras ? `<button type="button" aria-label="Volver" onclick="${atras}" class="w-11 h-11 -ml-2 flex items-center justify-center rounded-lg text-primary hover:bg-surface-container active:scale-95 transition">${ic('arrow_back')}</button>` : ''}
      ${logo}
      <div class="flex flex-col min-w-0">
        <span class="text-[11px] font-semibold tracking-wider uppercase text-primary-container">${esc(CFG.empresa)}</span>
        <span class="font-display text-lg font-semibold leading-tight truncate">${titulo}</span>
      </div>
      <span class="ml-auto inline-flex items-center px-2.5 py-1 rounded-full bg-secondary-container/40 text-secondary text-[11px] font-semibold">fiesta ${ANIO}</span>
    </div>
  </header>`;
}

function pasos(n) {
  const nombres = ['Identidad', 'Asistencia', 'Familia', 'Resumen'];
  return `<div class="no-print tarjeta p-4">
    <div class="flex justify-between text-xs font-semibold uppercase tracking-wider text-on-surface-variant mb-2">
      <span>Paso ${n} de 4</span><span>${n * 25}% completado</span>
    </div>
    <div class="h-1.5 rounded-full bg-surface-variant overflow-hidden"><div class="h-full rounded-full bg-primary-container transition-all" style="width:${n * 25}%"></div></div>
    <div class="flex justify-between mt-2 text-[11px] font-medium">
      ${nombres.map((t, i) => `<span class="${i + 1 === n ? 'text-secondary font-semibold' : i + 1 < n ? 'text-primary-container' : 'text-outline'}">${i + 1 < n ? '✓' : i + 1 + '.'} ${t}</span>`).join('')}
    </div>
  </div>`;
}

const pieHtml = () => `<p class="no-print text-center text-[11px] text-outline py-2">${ic('verified_user', 'text-sm')} ${esc(CFG.empresa)} • Exclusivo para colaboradores</p>`;
const cajaError = () => S.error ? `<div role="alert" class="rounded-xl bg-error-container text-on-error-container p-3 text-sm font-medium flex gap-2">${ic('error', 'text-lg')}<span>${esc(S.error)}</span></div>` : '';
const btnPrimario = (texto, onclick, extra = '') => `<button type="button" onclick="${onclick}" ${S.enviando ? 'disabled' : ''} class="w-full h-12 bg-gradient-to-r from-primary-container via-primary to-primary-container text-on-primary text-sm font-semibold rounded-lg shadow-md active:scale-[0.99] transition flex items-center justify-center gap-2 disabled:opacity-60 ${extra}">${S.enviando ? 'Guardando…' : texto}</button>`;
const btnSecundario = (texto, onclick) => `<button type="button" onclick="${onclick}" class="w-full h-12 bg-surface-container text-primary text-sm font-semibold rounded-lg active:scale-[0.99] transition flex items-center justify-center gap-2">${texto}</button>`;

/* ---------- Paso 1: bienvenida ---------- */
function vista1() {
  return `
  <div class="flex items-center justify-between bg-surface-container-low px-4 py-1.5 rounded-full">
    <span class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wider text-on-surface-variant"><span class="w-2 h-2 rounded-full bg-secondary"></span>Paso 1 de 4: Identificación</span>
    <span class="flex items-center gap-1"><span class="w-6 h-1.5 rounded-full bg-primary-container"></span><span class="w-2 h-1.5 rounded-full bg-surface-variant"></span><span class="w-2 h-1.5 rounded-full bg-surface-variant"></span><span class="w-2 h-1.5 rounded-full bg-surface-variant"></span></span>
  </div>
  <div class="relative overflow-hidden rounded-xl bg-gradient-to-br from-primary-container via-primary to-primary-container p-6 text-on-primary shadow-md">
    <div class="absolute -right-8 -top-8 w-36 h-36 rounded-full bg-secondary-fixed opacity-15 blur-2xl"></div>
    <div class="absolute -left-6 -bottom-6 w-28 h-28 rounded-full bg-secondary-container opacity-20 blur-xl"></div>
    <div class="relative flex flex-col items-center text-center gap-2">
      <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-secondary-fixed/20">${ic('star', 'text-secondary-fixed text-base', true)}<span class="text-[11px] text-secondary-fixed font-semibold tracking-wide uppercase">${esc(CFG.evento)} ${ANIO}</span></div>
      <svg class="w-20 h-20 my-1" fill="none" viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" fill="#ffe088" fill-opacity=".08"/><path d="M50 14 62 32H38L50 14Z" fill="#FFE088" fill-opacity=".95"/><path d="M50 28 68 50H32L50 28Z" fill="#C1ECD4" fill-opacity=".75"/><path d="M50 44 74 72H26L50 44Z" fill="#FFE088" fill-opacity=".85"/><rect x="46" y="72" width="8" height="12" rx="2" fill="#E9C349"/><polygon fill="#FED65B" points="50,9 52,14 57,14 53,17 55,22 50,19 45,22 47,17 43,14 48,14"/><circle cx="44" cy="38" r="2.5" fill="#fff"/><circle cx="58" cy="46" r="2" fill="#FED65B"/><circle cx="40" cy="58" r="3" fill="#C1ECD4"/><circle cx="59" cy="64" r="2.5" fill="#fff"/></svg>
      <h1 class="font-display text-[1.75rem] leading-9 font-bold tracking-tight">¡Celebremos juntos!</h1>
      <p class="text-primary-fixed max-w-xs leading-relaxed">Estamos preparando nuestra fiesta de fin de año y queremos contar contigo y con tu núcleo familiar.</p>
      <p class="text-secondary-fixed text-sm font-semibold mt-1">${ic('event', 'text-base')} ${fechaLarga(CFG.fecha)} • ${horaTexto(CFG.hora)}</p>
    </div>
  </div>
  <div class="bg-surface-container-high rounded-xl p-4 flex items-start gap-2 shadow-sm">
    <div class="w-8 h-8 rounded-full bg-secondary-container/40 flex items-center justify-center shrink-0">${ic('info', 'text-secondary text-lg', true)}</div>
    <p class="text-sm font-medium leading-snug"><strong class="font-semibold">Nota importante:</strong> La invitación contempla exclusivamente al trabajador y su núcleo familiar directo debidamente registrado.</p>
  </div>
  <div class="bg-surface-container-lowest rounded-xl p-6 shadow-sm space-y-4">
    <form class="space-y-4" onsubmit="event.preventDefault(); consultar();" novalidate>
      <div class="space-y-1">
        <label class="block text-sm font-semibold" for="cedula">Ingresa tu número de documento de identidad</label>
        <span class="block text-sm text-on-surface-variant">Digita tu cédula sin puntos ni comas para validar tu invitación.</span>
        <div class="relative mt-2">
          <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-outline">${ic('badge', 'text-xl')}</div>
          <input id="cedula" type="tel" inputmode="numeric" autocomplete="off" maxlength="12" placeholder="Ej. 1098765432"
            oninput="this.value = this.value.replace(/\\D/g, '')"
            class="w-full h-12 pl-11 pr-4 bg-surface-container-low font-display text-xl font-semibold rounded-lg focus:outline-none focus:ring-2 focus:ring-[#D4AF37] focus:bg-white transition placeholder:text-outline/60 placeholder:text-base placeholder:font-normal"/>
        </div>
      </div>
      ${cajaError()}
      <button type="submit" ${S.enviando ? 'disabled' : ''} class="w-full h-12 bg-gradient-to-r from-primary-container via-primary to-primary-container text-on-primary text-sm font-semibold rounded-lg shadow-md active:scale-[0.99] transition flex items-center justify-center gap-2 disabled:opacity-70">
        ${S.enviando ? 'Verificando…' : `Consultar y continuar ${ic('arrow_forward', 'text-lg')}`}
      </button>
    </form>
    <p class="text-center text-sm text-on-surface-variant">${ic('support_agent', 'text-secondary text-base')} ¿Problemas con tu documento? <strong>${esc(CFG.contacto)}</strong></p>
  </div>
  <div class="text-center py-2 space-y-1">
    <p class="text-[11px] text-on-surface-variant/70 uppercase tracking-widest">${esc(CFG.empresa)}</p>
    <p class="text-[11px] text-outline">${esc(CFG.evento)} ${ANIO} · <a class="underline" href="admin/">Administración</a></p>
  </div>`;
}

async function consultar() {
  const cedula = document.getElementById('cedula').value.trim();
  if (cedula.length < 5) { S.error = 'Por favor ingresa un número de cédula válido.'; return pintar(); }
  S.error = ''; S.enviando = true; pintar();
  document.getElementById('cedula').value = cedula;
  try {
    const r = await api('consultar', { cedula });
    actualizarCFG(r.config);
    S.emp = r.empleado; S.reg = r.registro; S.editable = r.editable;
    cargarDesdeRegistro();
    S.paso = S.reg ? 5 : 2;
  } catch (e) { S.error = e.message; }
  S.enviando = false; pintar();
  if (S.error) { const i = document.getElementById('cedula'); i.value = cedula; i.focus(); }
}

function cargarDesdeRegistro() {
  const r = S.reg;
  S.asiste = r ? r.asiste : true;
  S.adultos = r ? r.adultos.map(a => ({ nombre: a.nombre, parentesco: a.parentesco, sexo: a.sexo, edad: a.edad ?? '' })) : [];
  S.ninos = r ? r.ninos.map(a => ({ nombre: a.nombre, sexo: a.sexo, edad: a.edad })) : [];
  S.certifico = false;
}

/* ---------- Paso 2: asistencia ---------- */
function vista2() {
  const op = (si, titulo, texto, extra) => {
    const act = S.asiste === si;
    return `<button type="button" onclick="S.asiste=${si}; pintar(true)" aria-pressed="${act}" class="w-full text-left rounded-2xl p-5 transition border ${act ? (si ? 'bg-primary-fixed/30 border-primary-container shadow-md' : 'bg-tertiary-fixed/40 border-tertiary-container shadow-md') : 'bg-white border-transparent shadow-sm'}">
      <div class="flex items-start gap-3">
        <span class="mt-0.5 w-6 h-6 rounded-full flex items-center justify-center shrink-0 ${act ? (si ? 'bg-primary-container text-white' : 'bg-tertiary-container text-white') : 'bg-surface-variant'}">${act ? ic('check', 'text-base') : ''}</span>
        <div class="flex-1">
          <div class="flex items-center justify-between gap-2"><span class="font-display text-xl font-semibold">${titulo}</span>${extra || ''}</div>
          <p class="text-sm text-on-surface-variant mt-1 leading-relaxed">${texto}</p>
        </div>
      </div>
    </button>`;
  };
  return cabecera('Asistencia', 'cambiarCedula()') + pasos(2) + `
  <div class="tarjeta overflow-hidden">
    <div class="h-1.5 bg-gradient-to-r from-[#D4AF37] via-secondary-fixed to-primary-container"></div>
    <div class="p-5 space-y-4">
      <div class="flex items-center gap-4">
        <div class="w-16 h-16 rounded-full bg-primary-container text-secondary-fixed font-display text-xl font-bold flex items-center justify-center shrink-0">${esc(iniciales(S.emp.nombre))}</div>
        <div class="min-w-0">
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-primary-fixed text-on-primary-fixed-variant text-[11px] font-semibold">${ic('badge', 'text-sm')} Colaborador oficial</span>
          <h2 class="font-display text-xl font-semibold leading-tight mt-1">¡Hola, ${esc(primerNombre(S.emp.nombre))}!</h2>
          <p class="text-sm font-semibold">${cc(S.emp.cedula)}</p>
        </div>
      </div>
      <p class="bg-surface-container-low rounded-lg p-3 text-sm text-on-surface-variant leading-relaxed">${ic('sentiment_satisfied', 'text-secondary text-lg')} ¡Queremos saber si contaremos contigo para compartir esta noche tan especial de agradecimiento y unión en ${esc(CFG.empresa)}!</p>
    </div>
  </div>
  <div class="text-center space-y-1 pt-2">
    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-secondary-fixed/60 text-on-secondary-fixed-variant text-xs font-semibold uppercase tracking-wide">${ic('event', 'text-sm')} fiesta de fin de año</span>
    <h2 class="font-display text-2xl font-bold tracking-tight text-primary">¿Contaremos contigo en nuestra fiesta de fin de año?</h2>
    <p class="text-sm text-on-surface-variant">Tu presencia hace que nuestros logros cobren sentido.</p>
  </div>
  ${op(true, 'Sí, asistiré 🎉', '¡Excelente! En el siguiente paso podrás registrar a los integrantes de tu núcleo familiar para asegurar sus puestos en la mesa.', '<span class="px-2 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed-variant text-[11px] font-semibold">Recomendado</span>')}
  ${op(false, 'No podré asistir 😔', 'Lamentamos que no puedas acompañarnos. Podrás confirmar y finalizar tu registro directamente, sin pasar por las siguientes fases.')}
  <div class="bg-surface-container-low rounded-xl p-4 flex gap-3 items-center">
    <div class="w-10 h-10 rounded-full bg-secondary-fixed flex items-center justify-center shrink-0">${ic('stars', 'text-secondary')}</div>
    <p class="text-sm"><strong class="font-semibold block">Detalles de la velada</strong>${fechaLarga(CFG.fecha)}, ${horaTexto(CFG.hora)} en ${esc(CFG.lugar)}. Incluye cena especial, sorpresas navideñas.</p>
  </div>
  ${cajaError()}
  ${S.asiste ? btnPrimario(`Continuar al registro familiar ${ic('arrow_forward', 'text-lg')}`, 'ir(3)') : btnPrimario(`Confirmar que no asistiré ${ic('check', 'text-lg')}`, 'guardar()')}
  ${btnSecundario(`${ic('arrow_back', 'text-lg')} Cambiar cédula`, 'cambiarCedula()')}
  ${pieHtml()}`;
}

/* ---------- Paso 3: núcleo familiar ---------- */
function contador(valor, etiqueta, lista, dorado) {
  const lleno = total() - 1 >= CFG.max;
  return `<div class="bg-surface-container-low rounded-xl p-3 flex items-center justify-between">
    <button type="button" aria-label="Quitar" onclick="quitar('${lista}')" ${valor === 0 ? 'disabled' : ''} class="w-11 h-11 rounded-full bg-white shadow-sm flex items-center justify-center text-primary disabled:opacity-40 active:scale-95 transition">${ic('remove')}</button>
    <div class="text-center"><div class="font-display text-4xl font-bold ${dorado ? 'text-secondary' : 'text-primary'}">${valor}</div><div class="text-[11px] font-semibold uppercase tracking-wider text-on-surface-variant">${etiqueta}</div></div>
    <button type="button" aria-label="Agregar" onclick="agregar('${lista}')" ${lleno ? 'disabled' : ''} class="w-11 h-11 rounded-full ${dorado ? 'bg-secondary' : 'bg-primary-container'} text-white shadow-sm flex items-center justify-center disabled:opacity-40 active:scale-95 transition">${ic('add')}</button>
  </div>`;
}

function vista3() {
  const L = CFG.edadNino;
  const adultos = S.adultos.map((a, i) => `
    <div class="tarjeta p-4 space-y-3 border-t-2 !border-t-primary-container">
      <div class="flex items-center gap-2 font-display text-lg font-semibold"><span class="w-6 h-6 rounded-full bg-primary-fixed text-primary text-xs flex items-center justify-center">${i + 1}</span>Acompañante ${i + 1}</div>
      <label class="block text-xs font-semibold">Nombre completo
        <input class="campo mt-1" maxlength="80" value="${esc(a.nombre)}" oninput="S.adultos[${i}].nombre=this.value" placeholder="Nombre y apellido"/></label>
      <div class="grid grid-cols-5 gap-3">
        <label class="block text-xs font-semibold col-span-3">Parentesco
          <select class="campo mt-1" onchange="S.adultos[${i}].parentesco=this.value">
            <option value="">Selecciona…</option>${CFG.parentescos.map(p => `<option ${a.parentesco === p ? 'selected' : ''}>${esc(p)}</option>`).join('')}
          </select></label>
        <label class="block text-xs font-semibold col-span-2">Edad (años)
          <input class="campo mt-1" type="number" inputmode="numeric" min="${L}" max="110" value="${esc(a.edad)}" oninput="S.adultos[${i}].edad=this.value" placeholder="${L}+"/></label>
      </div>
    </div>`).join('');

  const ninos = S.ninos.map((n, i) => `
    <div class="tarjeta p-4 space-y-3 border-t-2 !border-t-secondary">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2 font-display text-lg font-semibold"><span class="w-6 h-6 rounded-full bg-secondary-container text-on-secondary-fixed text-xs flex items-center justify-center">${i + 1}</span>Niño ${i + 1}</div>
        <span class="px-2 py-0.5 rounded-full bg-secondary-fixed/60 text-on-secondary-fixed-variant text-[11px] font-semibold">${ic('redeem', 'text-sm')} Regalo asignado</span>
      </div>
      <label class="block text-xs font-semibold">Nombre del niño <span class="font-normal text-on-surface-variant">(opcional)</span>
        <input class="campo mt-1" maxlength="80" value="${esc(n.nombre)}" oninput="S.ninos[${i}].nombre=this.value" placeholder="Nombre"/></label>
      <div><span class="block text-xs font-semibold mb-1">Sexo <span class="font-normal text-on-surface-variant">(para selección del obsequio)</span></span>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" onclick="S.ninos[${i}].sexo='M'; pintar(true)" class="h-11 rounded-lg text-sm font-semibold transition ${n.sexo === 'M' ? 'bg-primary-container text-white' : 'bg-surface-container-low'}">${ic('boy', 'text-lg')} Niño</button>
          <button type="button" onclick="S.ninos[${i}].sexo='F'; pintar(true)" class="h-11 rounded-lg text-sm font-semibold transition ${n.sexo === 'F' ? 'bg-tertiary-container text-white' : 'bg-surface-container-low'}">${ic('girl', 'text-lg')} Niña</button>
        </div></div>
      <div><div class="flex justify-between text-xs font-semibold mb-1"><span>Edad al día de la fiesta</span><span class="text-on-surface-variant">${n.edad === null ? 'Selecciona' : plural(n.edad, 'año', 'años')}</span></div>
        <div class="flex flex-wrap gap-1.5">${Array.from({ length: L }, (_, e) => `<button type="button" onclick="S.ninos[${i}].edad=${e}; pintar(true)" class="w-9 h-9 rounded-full text-sm font-semibold transition ${n.edad === e ? 'bg-secondary text-white' : 'bg-surface-container-low'}">${e}</button>`).join('')}</div></div>
    </div>`).join('');

  return cabecera('Acompañantes', 'ir(2)') + pasos(3) + `
  <div class="rounded-xl bg-secondary-fixed/30 p-4 flex gap-3">
    <div class="w-10 h-10 rounded-full bg-secondary-container flex items-center justify-center shrink-0">${ic('family_restroom', 'text-on-secondary-fixed')}</div>
    <div><h2 class="font-display text-xl font-semibold leading-tight">¡Queremos compartir contigo y tu familia!</h2>
    <p class="text-sm text-on-surface-variant mt-1 leading-relaxed">La invitación incluye al colaborador y a los integrantes de su <strong>núcleo familiar directo</strong>. No está destinada a amigos ni personas ajenas al hogar.</p></div>
  </div>
  <div class="tarjeta p-5 space-y-3">
    <div><span class="text-[11px] font-semibold uppercase tracking-wider text-secondary">Grupo directo</span>
    <h3 class="font-display text-xl font-semibold leading-tight">¿Cuántos acompañantes de ${L} años o más?</h3>
    <p class="text-sm text-on-surface-variant mt-1">Sin incluirte a ti. Jóvenes y adultos de tu núcleo familiar.</p></div>
    ${contador(S.adultos.length, 'Acompañantes', 'adultos', false)}
  </div>
  ${adultos}
  <div class="tarjeta p-5 space-y-3">
    <div><div class="flex justify-between items-center"><span class="text-[11px] font-semibold uppercase tracking-wider text-secondary">Catering especial</span><span class="px-2 py-0.5 rounded-full bg-secondary-fixed/60 text-on-secondary-fixed-variant text-[11px] font-semibold">Menores de ${L} años</span></div>
    <h3 class="font-display text-xl font-semibold leading-tight">¿Cuántos niños asistirán?</h3>
    <p class="text-sm text-on-surface-variant mt-1">Recibirán sorpresa durante la fiesta.</p></div>
    ${contador(S.ninos.length, 'Niños', 'ninos', true)}
  </div>
  ${ninos}
  <div class="tarjeta p-5 space-y-2 bg-surface-container-low">
    <h3 class="font-display text-lg font-semibold flex items-center gap-2">${ic('calculate')} Cálculo del grupo familiar</h3>
    ${[['person', 'Empleado (titular)', 1], ['group', `Acompañantes (${L}+ años)`, S.adultos.length], ['child_care', 'Niños registrados', S.ninos.length]].map(f => `<div class="flex justify-between bg-white rounded-lg px-3 py-2 text-sm"><span>${ic(f[0], 'text-base text-on-surface-variant')} ${f[1]}</span><strong>${f[2]}</strong></div>`).join('')}
    <div class="flex justify-between items-center bg-primary text-white rounded-xl px-4 py-3 mt-2"><span class="font-display font-bold uppercase text-sm tracking-wide">Total asistentes</span><span><span class="font-display text-3xl font-bold text-secondary-fixed">${total()}</span> <span class="text-xs">${total() === 1 ? 'persona' : 'personas'}</span></span></div>
  </div>
  ${cajaError()}
  ${btnPrimario(`Revisar resumen ${ic('arrow_forward', 'text-lg')}`, 'revisar()')}
  ${btnSecundario(`${ic('arrow_back', 'text-lg')} Volver`, 'ir(2)')}
  ${pieHtml()}`;
}

function agregar(lista) {
  if (total() - 1 >= CFG.max) return;
  S[lista].push(lista === 'adultos' ? { nombre: '', parentesco: '', sexo: '', edad: '' } : { nombre: '', sexo: '', edad: null });
  S.error = ''; pintar(true);
}
function quitar(lista) { S[lista].pop(); S.error = ''; pintar(true); }

function validarFamilia() {
  const L = CFG.edadNino;
  for (let i = 0; i < S.adultos.length; i++) {
    const a = S.adultos[i], e = Number(a.edad);
    if (!a.nombre.trim()) return `Escribe el nombre del acompañante ${i + 1}.`;
    if (!a.parentesco) return `Selecciona el parentesco del acompañante ${i + 1}.`;
    if (a.edad === '' || !Number.isInteger(e)) return `Escribe la edad del acompañante ${i + 1}.`;
    if (e < L) return `El acompañante ${i + 1} tiene menos de ${L} años: regístralo en la sección de niños.`;
    if (e > 110) return `Revisa la edad del acompañante ${i + 1}.`;
  }
  for (let i = 0; i < S.ninos.length; i++) {
    if (!S.ninos[i].sexo) return `Indica si el niño ${i + 1} es niño o niña.`;
    if (S.ninos[i].edad === null) return `Selecciona la edad del niño ${i + 1}.`;
  }
  return '';
}
function revisar() {
  S.error = validarFamilia();
  if (S.error) return pintar(true);
  ir(4);
}

/* ---------- Paso 4: resumen ---------- */
const etiquetaNino = n => `${esc(n.nombre.trim() || (n.sexo === 'F' ? 'Niña' : 'Niño'))} (${n.sexo === 'F' ? 'Niña' : 'Niño'}, ${plural(n.edad, 'año', 'años')})`;

function vista4() {
  return cabecera('Resumen y envío', 'ir(3)') + pasos(4) + `
  <div><h2 class="font-display text-2xl font-bold tracking-tight text-primary">Verifica tu información antes de confirmar</h2>
  <p class="text-sm text-on-surface-variant mt-1">Revisa con atención los detalles de tu pase familiar.</p></div>
  <div class="tarjeta overflow-hidden">
    <div class="h-1.5 bg-gradient-to-r from-secondary via-secondary-fixed to-primary-container"></div>
    <div class="p-5 space-y-4">
      <div><span class="text-[11px] font-semibold uppercase tracking-wider text-secondary" >fiesta de fin de año</span>
        <h3 class="font-display text-xl font-bold text-primary">${esc(CFG.empresa)}</h3>
        <p class="text-xs font-semibold text-on-surface-variant">${esc(CFG.evento)} • ${esc(CFG.lugar)}</p></div>
      <div class="bg-surface-container-low rounded-xl p-4 flex items-center gap-3">
        <div class="w-12 h-12 rounded-full bg-primary-container text-secondary-fixed flex items-center justify-center shrink-0">${ic('badge')}</div>
        <div><span class="text-[11px] uppercase tracking-wider text-on-surface-variant">Titular colaborador</span><p class="font-semibold leading-tight">${esc(S.emp.nombre)}</p><p class="text-sm">${cc(S.emp.cedula)}</p></div>
      </div>
      <div class="flex justify-between text-[11px] font-semibold uppercase tracking-wider"><span class="text-primary">Desglose del grupo familiar</span><span class="text-secondary">${plural(total(), 'persona', 'personas')}</span></div>
      <div class="bg-surface-container-low rounded-lg px-3 py-2 text-sm flex justify-between"><span>${ic('person', 'text-base')} Titular (empleado)</span><strong>1 persona</strong></div>
      <div class="bg-surface-container-low rounded-lg px-3 py-2 text-sm space-y-2">
        <div class="flex justify-between"><span>${ic('group', 'text-base')} Acompañantes</span><strong>${S.adultos.length}</strong></div>
        ${S.adultos.map(a => `<div class="bg-white rounded-lg px-3 py-2"><p class="font-semibold">${esc(a.nombre)} <span class="font-normal text-on-surface-variant">(${plural(Number(a.edad), 'año', 'años')})</span></p><p class="text-xs text-secondary font-semibold">${esc(a.parentesco)}</p></div>`).join('')}
      </div>
      <div class="bg-surface-container-low rounded-lg px-3 py-2 text-sm space-y-2">
        <div class="flex justify-between"><span>${ic('child_care', 'text-base')} Niños (menores de ${CFG.edadNino})</span><strong>${S.ninos.length}</strong></div>
        ${S.ninos.map(n => `<div class="bg-white rounded-lg px-3 py-2 flex justify-between items-center"><div><p class="font-semibold">${etiquetaNino(n)}</p><p class="text-xs text-secondary font-semibold"></p></div>${ic('celebration', 'text-secondary')}</div>`).join('')}
      </div>
      <div class="bg-primary text-white rounded-xl p-4 flex justify-between items-center"><span class="font-display font-bold uppercase tracking-wide">${ic('confirmation_number')} Total asistentes</span><span class="font-display text-2xl font-bold text-secondary-fixed">${plural(total(), 'persona', 'personas')}</span></div>
      <label class="bg-surface-container-low rounded-lg p-3 flex gap-3 text-sm cursor-pointer">
        <input type="checkbox" class="w-5 h-5 mt-0.5 accent-[#1b4332] shrink-0" ${S.certifico ? 'checked' : ''} onchange="S.certifico=this.checked; S.error=''"/>
        <span>Certifico que los acompañantes registrados pertenecen a mi núcleo familiar directo y acepto las normativas del evento.</span>
      </label>
    </div>
  </div>
  ${cajaError()}
  ${btnPrimario(`${ic('verified')} CONFIRMAR MI REGISTRO`, 'confirmar()')}
  ${btnSecundario(`${ic('arrow_back', 'text-lg')} Modificar datos familiares`, 'ir(3)')}
  <div class="rounded-xl bg-secondary-fixed/30 p-4 flex gap-3 text-sm">${ic('info', 'text-secondary', true)}<p><strong class="block text-on-secondary-fixed-variant">Plazo máximo de cambios</strong>Tienes plazo hasta el <strong>${fechaLarga(CFG.limite).toLowerCase()}</strong> para realizar modificaciones.</p></div>
  ${pieHtml()}`;
}

function confirmar() {
  if (!S.certifico) { S.error = 'Debes marcar la casilla de certificación para confirmar.'; return pintar(true); }
  guardar();
}

async function guardar() {
  S.error = ''; S.enviando = true; pintar(true);
  try {
    const r = await api('guardar', {
      asiste: S.asiste,
      adultos: S.asiste ? S.adultos.map(a => ({ ...a, nombre: a.nombre.trim(), edad: Number(a.edad) })) : [],
      ninos: S.asiste ? S.ninos.map(n => ({ ...n, nombre: n.nombre.trim() })) : [],
    });
    actualizarCFG(r.config);
    S.reg = r.registro; S.editable = r.editable; S.paso = 5;
  } catch (e) { S.error = e.message; }
  S.enviando = false; pintar(!!S.error);
}

/* ---------- Paso 5: pase digital ---------- */
function vista5() {
  const r = S.reg;
  const modificar = S.editable
    ? btnSecundario(`${ic('edit', 'text-lg')} Modificar mi registro`, 'modificar()')
    : `<p class="no-print text-center text-sm text-on-surface-variant">El plazo de cambios ya cerró. Para novedades comunícate con <strong>${esc(CFG.contacto)}</strong>.</p>`;
  const salir = `<button type="button" onclick="cambiarCedula()" class="no-print w-full h-11 text-sm text-on-surface-variant flex items-center justify-center gap-2">${ic('logout', 'text-lg')} Cerrar sesión y salir</button>`;

  if (!r.asiste) {
    return cabecera('Registro guardado', '') + `
    <div class="text-center space-y-3 pt-6">
      <div class="w-20 h-20 mx-auto rounded-full bg-surface-container-high flex items-center justify-center">${ic('event_busy', 'text-4xl text-on-surface-variant')}</div>
      <h1 class="font-display text-3xl font-bold tracking-tight text-primary">Respuesta registrada</h1>
      <p class="text-on-surface-variant leading-relaxed">${esc(primerNombre(S.emp.nombre))}, registramos que <strong>no podrás asistir</strong> a la ${esc(CFG.evento)}. ¡Te vamos a extrañar!</p>
    </div>
    <div class="tarjeta p-4 text-sm flex gap-3">${ic('info', 'text-secondary', true)}<p>Si cambias de opinión, puedes modificar tu respuesta hasta el <strong>${fechaLarga(CFG.limite).toLowerCase()}</strong>.</p></div>
    ${modificar}${salir}${pieHtml()}`;
  }

  const nombresNinos = r.ninos.map(n => n.nombre).filter(Boolean).join(', ');
  const personas = 1 + r.adultos.length + r.ninos.length;
  return cabecera('Confirmación exitosa', '') + `
  <div class="no-print text-center space-y-2 pt-4">
    <div class="w-20 h-20 mx-auto rounded-full bg-primary-container flex items-center justify-center shadow-lg">${ic('verified', 'text-4xl text-secondary-fixed')}</div>
    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-secondary-fixed/60 text-on-secondary-fixed-variant text-xs font-semibold">${ic('celebration', 'text-sm')} fiesta de fin de año</span>
    <h1 class="font-display text-3xl font-bold tracking-tight text-primary">¡Registro confirmado!</h1>
    <p class="text-on-surface-variant leading-relaxed">Gracias por confirmar tu asistencia. Nos alegra que puedas compartir este momento especial con toda la familia ${esc(CFG.empresa)}.</p>
  </div>
  <div class="tarjeta overflow-hidden" id="pase">
    <div class="h-1.5 bg-gradient-to-r from-secondary via-secondary-fixed to-primary-container"></div>
    <div class="bg-surface-container-low px-5 py-4 flex items-center gap-3">
      <div class="w-10 h-10 rounded-full bg-primary-container text-secondary-fixed flex items-center justify-center shrink-0">${ic('badge')}</div>
      <div><span class="text-[11px] uppercase tracking-wider text-on-surface-variant">Credencial oficial</span><p class="font-display font-bold">#${esc(r.codigo)}</p></div>
      <span class="ml-auto inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-primary-fixed text-on-primary-fixed-variant text-[11px] font-bold tracking-wider"><span class="w-2 h-2 rounded-full bg-primary-container"></span>ACTIVO</span>
    </div>
    <div class="p-5 space-y-4">
      <div class="flex gap-3 items-center"><div class="w-11 h-11 rounded-lg bg-surface-container-low flex items-center justify-center shrink-0">${ic('event_available')}</div><div><span class="text-[11px] uppercase tracking-wider text-on-surface-variant">Fecha y hora</span><p class="font-semibold">${fechaLarga(CFG.fecha)} • ${horaTexto(CFG.hora)}</p></div></div>
      <div class="flex gap-3 items-center"><div class="w-11 h-11 rounded-lg bg-surface-container-low flex items-center justify-center shrink-0">${ic('location_on')}</div><div><span class="text-[11px] uppercase tracking-wider text-on-surface-variant">Lugar</span><p class="font-semibold">${esc(CFG.lugar)}</p><p class="text-sm text-on-surface-variant">${esc(CFG.salon)}</p></div></div>
      <div class="border-t border-dashed border-outline-variant"></div>
      <div class="flex justify-between items-center"><span class="text-[11px] font-semibold uppercase tracking-wider">Núcleo familiar registrado</span><span class="px-2.5 py-1 rounded-full bg-secondary-fixed/60 text-on-secondary-fixed-variant text-xs font-semibold">${plural(personas, 'cupo confirmado', 'cupos confirmados')}</span></div>
      <div class="bg-surface-container-low rounded-xl p-3 space-y-2 text-sm">
        <div class="flex justify-between gap-2"><span class="font-semibold">${ic('verified_user', 'text-base')} ${esc(S.emp.nombre)}</span><span class="text-xs bg-surface-variant rounded px-1.5 py-0.5 h-fit">Titular</span></div>
        ${r.adultos.map(a => `<div class="flex justify-between gap-2"><span>${ic('person', 'text-base')} ${esc(a.nombre)}</span><span class="text-xs text-on-surface-variant">${esc(a.parentesco)}</span></div>`).join('')}
        ${r.ninos.length ? `<div class="flex justify-between gap-2"><span>${ic('child_care', 'text-base')} ${plural(r.ninos.length, 'niño', 'niños')}${nombresNinos ? ` (${esc(nombresNinos)})` : ''}</span><span class="text-xs text-on-surface-variant">Menores</span></div>` : ''}
      </div>
      <div class="flex flex-col items-center gap-2 pt-1">
        <div id="qr" class="p-3 bg-white rounded-xl border border-outline-variant/40"></div>
        <p class="text-xs font-medium">Código QR único para portería</p>
        <p class="text-xs text-on-surface-variant">Presenta este pase al ingresar</p>
      </div>
    </div>
  </div>
  <div class="no-print bg-surface-container-low rounded-xl p-4 flex gap-3 text-sm">${ic('mark_email_read', 'text-primary-container')}<p><strong>✓ Información registrada con éxito.</strong> Tu registro quedó guardado en el sistema de Gestión Humana. Descarga tu pase o tómale una captura de pantalla para presentarlo en portería.</p></div>
  <div class="no-print space-y-3">
    ${btnPrimario(`${ic('download', 'text-lg')} Descargar pase digital en PDF`, 'window.print()')}
    ${btnSecundario(`${ic('event', 'text-lg')} Añadir al calendario (Google / Apple)`, 'descargarCalendario()')}
    ${modificar}
  </div>
  ${salir}${pieHtml()}`;
}

function descargarCalendario() {
  const f = CFG.fecha.replace(/-/g, ''), [h, m] = CFG.hora.split(':').map(Number);
  const p = n => String(n).padStart(2, '0');
  const fin = new Date(...CFG.fecha.split('-').map((v, i) => i === 1 ? v - 1 : +v), h + 5, m);
  const finTxt = `${fin.getFullYear()}${p(fin.getMonth() + 1)}${p(fin.getDate())}T${p(fin.getHours())}${p(fin.getMinutes())}00`;
  const limpio = s => String(s).replace(/([,;\\])/g, '\\$1');
  const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Chiquique//Fiesta//ES', 'BEGIN:VEVENT',
    `UID:${S.reg.codigo}@fiesta-chiquique`, `DTSTAMP:${f}T000000`, `DTSTART:${f}T${p(h)}${p(m)}00`, `DTEND:${finTxt}`,
    `SUMMARY:${limpio(CFG.evento + ' ' + CFG.empresa)}`, `LOCATION:${limpio(CFG.lugar + ' - ' + CFG.salon)}`,
    `DESCRIPTION:${limpio('Pase ' + S.reg.codigo + '. Preséntalo en portería.')}`, 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar' }));
  a.download = 'fiesta-chiquique.ics';
  a.click();
  URL.revokeObjectURL(a.href);
}

/* ---------- Navegación ---------- */
function ir(paso) { S.error = ''; S.paso = paso; pintar(); }
function modificar() { cargarDesdeRegistro(); ir(2); }
function cambiarCedula() {
  api('salir').catch(() => {});
  Object.assign(S, { paso: 1, emp: null, reg: null, adultos: [], ninos: [], asiste: true, certifico: false, error: '' });
  pintar();
}
function pintar(mantenerScroll = false) {
  app.innerHTML = [vista1, vista2, vista3, vista4, vista5][S.paso - 1]();
  if (!mantenerScroll) window.scrollTo(0, 0);
  const qr = document.getElementById('qr');
  if (qr && window.QRCode) new QRCode(qr, { text: S.reg.codigo, width: 148, height: 148, colorDark: '#1b4332', correctLevel: QRCode.CorrectLevel.M });
}
pintar();
</script>
</body>
</html>
