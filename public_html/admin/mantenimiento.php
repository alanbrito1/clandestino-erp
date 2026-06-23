<?php
/**
 * admin/mantenimiento.php — Limpieza de datos (solo superadmin).
 * Reset transaccional global + borrado por módulo (inactivos / anulados / todos),
 * en modo seguro o cascada. Toda la lógica destructiva vive en api/mantenimiento.php.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'admin';
$nav_sub    = 'mantenimiento';

if (($_SESSION['usuario_rol'] ?? '') !== 'superadmin') {
    http_response_code(403);
    include __DIR__ . '/../app/views/errors/403.php';
    exit;
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mantenimiento de datos — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:960px; margin:0 auto; padding:20px 14px 60px; }
        .page-title { font-size:22px; font-weight:800; margin-bottom:6px; }
        .page-sub { font-size:13px; color:var(--g5); margin-bottom:20px; }
        .card { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:20px; margin-bottom:18px; }
        .card-title { font-size:14px; font-weight:800; margin-bottom:6px; }
        .card-desc { font-size:12.5px; color:var(--g5); margin-bottom:14px; line-height:1.5; }
        .warning-box { background:#fef3c7; border:1px solid #fde68a; border-radius:10px; padding:12px 14px; margin-bottom:14px; font-size:13px; color:#92400e; }
        .danger-box { background:#fee2e2; border:1px solid #fca5a5; border-radius:10px; padding:12px 14px; margin-bottom:14px; font-size:13px; color:#991b1b; }
        .btn { padding:9px 16px; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-back { background:#374151; color:#fff; }
        .btn-red  { background:#dc2626; color:#fff; }
        .btn-warn { background:#f59e0b; color:#fff; }
        .btn-gray { background:var(--g9); color:var(--dark); border:1px solid var(--g8); }
        .btn-sm   { padding:5px 10px; font-size:12px; border-radius:7px; }
        .btn:disabled { opacity:.5; cursor:not-allowed; }
        .tbl { width:100%; border-collapse:collapse; font-size:13px; }
        .tbl thead th { background:var(--g9); padding:9px 10px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); }
        .tbl thead th.r, .tbl td.r { text-align:right; font-variant-numeric:tabular-nums; }
        .tbl tbody tr { border-bottom:1px solid var(--g9); }
        .tbl td { padding:9px 10px; vertical-align:middle; }
        .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .badge { font-size:11px; padding:2px 8px; border-radius:20px; font-weight:700; }
        .b-amber { background:#fef3c7; color:#92400e; }
        .b-gray  { background:#e5e7eb; color:#374151; }
        .acts { display:flex; gap:5px; flex-wrap:wrap; justify-content:flex-end; }
        /* modal */
        .ov { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:100; padding:16px; }
        .ov.open { display:flex; }
        .modal { background:#fff; border-radius:14px; padding:22px; max-width:460px; width:100%; }
        .modal h3 { font-size:16px; margin-bottom:10px; }
        .modal .m-body { font-size:13px; color:#374151; line-height:1.55; margin-bottom:14px; }
        .modal label { font-size:12px; font-weight:700; display:block; margin:10px 0 5px; }
        .modal input[type=text] { width:100%; padding:9px 11px; border:1px solid var(--g8); border-radius:8px; font-size:14px; }
        .modal .modes { display:flex; gap:8px; margin-bottom:6px; }
        .modal .modes label { display:flex; gap:6px; align-items:flex-start; font-weight:500; font-size:12px; flex:1; border:1px solid var(--g8); border-radius:8px; padding:8px; cursor:pointer; margin:0; }
        .modal .m-acts { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; }
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#111827; color:#fff; padding:11px 18px; border-radius:10px; font-size:13px; z-index:200; display:none; max-width:90%; }
        .toast.err { background:#991b1b; } .toast.ok { background:#065f46; }
        @media(max-width:479px){ .main{padding:12px 10px 40px;} .tbl{min-width:520px;} }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">🧹 Mantenimiento de datos</h1>
    <p class="page-sub">Borrado masivo de registros. Solo superadmin. <strong>Estas acciones son irreversibles.</strong></p>

    <div class="warning-box">
        ⚠️ <strong>Descarga un respaldo antes de borrar.</strong>
        <a class="btn btn-sm btn-gray" style="margin-left:8px" href="<?= APP_BASE ?>/admin/backup.php?action=download&token=<?= htmlspecialchars($csrf) ?>">⬇ Descargar respaldo SQL</a>
    </div>

    <!-- A) RESET TRANSACCIONAL GLOBAL -->
    <div class="card">
        <div class="card-title">Empezar de cero (reset transaccional)</div>
        <div class="card-desc">
            Borra <strong>todo lo transaccional</strong> — ventas, compras, producción, nómina,
            abonos de fiado, turnos de caja y ajustes de stock — y pone el <strong>saldo de fiado
            de los clientes en 0</strong>. <strong>Conserva el catálogo</strong> (productos, insumos,
            recetas, clientes, proveedores, empleados, activos, costos). Úsalo para arrancar la
            operación limpia.
        </div>
        <label style="font-size:12.5px;display:flex;gap:7px;align-items:center;margin-bottom:12px;cursor:pointer">
            <input type="checkbox" id="reset-stock"> También reiniciar el stock a 0 (productos e insumos)
        </label>
        <button class="btn btn-red" onclick="abrirReset()">Reset transaccional…</button>
    </div>

    <!-- B) LIMPIEZA POR MÓDULO -->
    <div class="card">
        <div class="card-title">Limpieza por módulo</div>
        <div class="card-desc">
            Borra registros de un módulo. <strong>Inactivos</strong> = desactivados (activo=0);
            <strong>anulados</strong> = ventas/lotes anulados; <strong>todos</strong> = absolutamente
            todo el módulo. Modo <strong>seguro</strong> omite los que tienen historial (y los
            reporta); modo <strong>cascada</strong> borra también ese historial.
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr>
                    <th>Módulo</th><th class="r">Total</th><th class="r">Inactivos</th>
                    <th class="r">Anulados</th><th class="r">Acciones</th>
                </tr></thead>
                <tbody id="tbody"><tr><td colspan="5" style="color:var(--g5)">Cargando…</td></tr></tbody>
            </table>
        </div>
    </div>

    <a class="btn btn-back" href="<?= APP_BASE ?>/admin/">← Volver a Admin</a>
</main>

<!-- MODAL CONFIRMACIÓN -->
<div class="ov" id="ov">
    <div class="modal">
        <h3 id="m-title">Confirmar</h3>
        <div class="m-body" id="m-body"></div>
        <div id="m-modes-wrap" style="display:none">
            <label style="margin-top:0">Modo de borrado</label>
            <div class="modes">
                <label><input type="radio" name="modo" value="seguro" checked> <span><strong>Seguro</strong> — omite los que tienen historial</span></label>
                <label><input type="radio" name="modo" value="cascada"> <span><strong>Cascada</strong> — borra también su historial</span></label>
            </div>
        </div>
        <label>Escribe <strong>BORRAR</strong> para confirmar</label>
        <input type="text" id="m-conf" autocomplete="off" placeholder="BORRAR" oninput="chkConf()">
        <div class="m-acts">
            <button class="btn btn-gray" onclick="cerrar()">Cancelar</button>
            <button class="btn btn-red" id="m-go" disabled onclick="ejecutar()">Borrar</button>
        </div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let STATS = {};
let pend = null; // acción pendiente {tipo, entidad, ambito, label}

function toast(m, t) { const e=document.getElementById('toast'); e.textContent=m; e.className='toast '+(t||''); e.style.display='block'; setTimeout(()=>e.style.display='none', 4200); }

async function cargarStats() {
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('accion', 'stats');
    const r = await fetch('api/mantenimiento.php', {method:'POST', body:fd});
    const d = await r.json();
    if (!d.success) { toast(d.error||'Error', 'err'); return; }
    STATS = d.entidades; render();
}

function render() {
    const tb = document.getElementById('tbody'); tb.innerHTML = '';
    for (const k in STATS) {
        const e = STATS[k];
        const acts = [];
        if (e.tipo === 'activo' && e.inactivos > 0)
            acts.push(`<button class="btn btn-sm btn-warn" onclick="abrir('${k}','inactivos')">Inactivos</button>`);
        if (e.tipo === 'estado' && e.anulados > 0)
            acts.push(`<button class="btn btn-sm btn-warn" onclick="abrir('${k}','anulados')">Anulados</button>`);
        if (e.total > 0)
            acts.push(`<button class="btn btn-sm btn-red" onclick="abrir('${k}','todos')">Todos</button>`);
        const tr = document.createElement('tr');
        tr.innerHTML = `<td><strong>${e.label}</strong></td>
            <td class="r">${e.total}</td>
            <td class="r">${e.tipo==='activo' ? '<span class="badge b-amber">'+e.inactivos+'</span>' : '<span style="color:#9ca3af">—</span>'}</td>
            <td class="r">${e.tipo==='estado' ? '<span class="badge b-gray">'+e.anulados+'</span>' : '<span style="color:#9ca3af">—</span>'}</td>
            <td class="r"><div class="acts">${acts.join('') || '<span style="color:#9ca3af;font-size:12px">—</span>'}</div></td>`;
        tb.appendChild(tr);
    }
}

const NOMBRE_AMBITO = {inactivos:'los registros INACTIVOS', anulados:'los registros ANULADOS', todos:'TODOS los registros'};

function abrir(entidad, ambito) {
    const e = STATS[entidad];
    pend = {tipo:'borrar', entidad, ambito, label:e.label};
    document.getElementById('m-title').textContent = 'Borrar ' + e.label;
    let txt = `Vas a borrar <strong>${NOMBRE_AMBITO[ambito]}</strong> de <strong>${e.label}</strong>.`;
    if (ambito === 'todos') txt += ' <strong style="color:#991b1b">Esto incluye registros activos.</strong>';
    document.getElementById('m-body').innerHTML = txt;
    // El selector de modo solo importa para entidades que pueden tener historial bloqueante
    const conHistorial = ['productos','insumos','clientes','empleados'];
    document.getElementById('m-modes-wrap').style.display = conHistorial.includes(entidad) ? 'block' : 'none';
    document.querySelector('input[name=modo][value=seguro]').checked = true;
    document.getElementById('m-conf').value = ''; chkConf();
    document.getElementById('ov').classList.add('open');
}

function abrirReset() {
    pend = {tipo:'reset'};
    document.getElementById('m-title').textContent = 'Reset transaccional';
    const stock = document.getElementById('reset-stock').checked;
    document.getElementById('m-body').innerHTML =
        'Vas a borrar <strong>TODO lo transaccional</strong> (ventas, compras, producción, nómina, '
        + 'fiado, turnos, ajustes) y poner el saldo de fiado en 0.'
        + (stock ? ' <strong>También se reiniciará el stock a 0.</strong>' : '')
        + ' El catálogo se conserva. <strong style="color:#991b1b">Irreversible.</strong>';
    document.getElementById('m-modes-wrap').style.display = 'none';
    document.getElementById('m-conf').value = ''; chkConf();
    document.getElementById('ov').classList.add('open');
}

function chkConf() { document.getElementById('m-go').disabled = document.getElementById('m-conf').value.trim() !== 'BORRAR'; }
function cerrar() { document.getElementById('ov').classList.remove('open'); pend = null; }

async function ejecutar() {
    if (!pend) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('confirmacion', document.getElementById('m-conf').value.trim());
    if (pend.tipo === 'reset') {
        fd.append('accion', 'reset_transaccional');
        if (document.getElementById('reset-stock').checked) fd.append('reset_stock', '1');
    } else {
        fd.append('accion', 'borrar');
        fd.append('entidad', pend.entidad);
        fd.append('ambito', pend.ambito);
        fd.append('modo', (document.querySelector('input[name=modo]:checked')||{}).value || 'seguro');
    }
    document.getElementById('m-go').disabled = true;
    try {
        const r = await fetch('api/mantenimiento.php', {method:'POST', body:fd});
        const txt = await r.text();
        let d; try { d = JSON.parse(txt); } catch(e){ toast(txt.slice(0,200),'err'); return; }
        if (d.success) {
            if (pend.tipo === 'reset') {
                const tot = Object.values(d.borrados).reduce((a,b)=>a+b,0);
                toast('Reset completo: ' + tot + ' registros borrados.', 'ok');
            } else {
                let m = pend.label + ': ' + d.borrados + ' borrados';
                if (d.omitidos > 0) m += ', ' + d.omitidos + ' omitidos (con historial)';
                toast(m, 'ok');
            }
            cerrar(); cargarStats();
        } else { toast(d.error || 'Error', 'err'); chkConf(); }
    } catch(e) { toast('Error de red', 'err'); chkConf(); }
}

cargarStats();
</script>
</body>
</html>
