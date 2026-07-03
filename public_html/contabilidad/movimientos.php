<?php
/**
 * contabilidad/movimientos.php — Movimientos de tesorería y capital (Fase 4c).
 * Pagos (a proveedor / de nómina, sacan de "por pagar" a Caja) y aportes/retiros
 * de capital. Guiado: el usuario elige el tipo, no las cuentas contables.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ContabilidadModel.php';

$nav_activo = 'contabilidad';
$nav_sub    = 'movimientos';
if (!in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}
if (!ContabilidadModel::existe()) { include __DIR__ . '/_sin_migracion.php'; exit; }

// Saldos actuales de las cuentas por pagar (contexto)
$saldos = [];
foreach (ContabilidadModel::saldos() as $s) $saldos[$s['codigo']] = (float)$s['saldo'];
$porPagarProv   = $saldos['2205'] ?? 0.0;
$porPagarNomina = $saldos['2510'] ?? 0.0;
$capital        = $saldos['3115'] ?? 0.0;
$csrf = csrf_token();
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Movimientos — <?= APP_NAME ?></title>
<style>
    :root{--brand:#e94f37;--dark:#111827;--g5:#6b7280;--g8:#d1d5db;--g9:#f3f4f6;--white:#fff;--green:#059669;}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:system-ui,-apple-system,sans-serif;background:var(--g9);color:var(--dark);}
    .main{max-width:560px;margin:0 auto;padding:20px 14px 60px;}
    .page-title{font-size:22px;font-weight:800;} .page-sub{font-size:13px;color:var(--g5);margin:3px 0 16px;}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;}
    .kpi{background:var(--white);border:1px solid var(--g8);border-radius:12px;padding:12px 14px;}
    .kpi-val{font-size:18px;font-weight:800;} .kpi-lbl{font-size:10.5px;color:var(--g5);margin-top:3px;text-transform:uppercase;}
    .card{background:var(--white);border:1px solid var(--g8);border-radius:14px;padding:18px;}
    .fg{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
    .fg label{font-size:12px;font-weight:700;color:var(--g5);}
    .fg select,.fg input{padding:9px 11px;border:1px solid var(--g8);border-radius:8px;font-size:14px;}
    .btn{padding:10px 18px;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;color:#fff;background:var(--brand);}
    .btn:disabled{opacity:.5;}
    .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#111827;color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;display:none;}
    .toast.ok{background:#065f46;} .toast.err{background:#991b1b;}
</style></head><body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Movimientos de tesorería y capital</h1>
    <p class="page-sub">Registra pagos y aportes/retiros. El asiento se arma solo.</p>

    <div class="kpis">
        <div class="kpi"><div class="kpi-val">$<?= fmt_moneda($porPagarProv) ?></div><div class="kpi-lbl">Proveedores por pagar</div></div>
        <div class="kpi"><div class="kpi-val">$<?= fmt_moneda($porPagarNomina) ?></div><div class="kpi-lbl">Nómina por pagar</div></div>
        <div class="kpi"><div class="kpi-val">$<?= fmt_moneda($capital) ?></div><div class="kpi-lbl">Capital</div></div>
    </div>

    <div class="card">
        <div class="fg"><label>Tipo de movimiento</label>
            <select id="tipo">
                <option value="pago_proveedor">Pago a proveedor (baja "por pagar")</option>
                <option value="pago_nomina">Pago de nómina (baja "por pagar")</option>
                <option value="aporte_capital">Aporte de capital (socio pone dinero)</option>
                <option value="retiro_capital">Retiro de capital (socio saca dinero)</option>
            </select></div>
        <div class="fg"><label>Desde / hacia</label>
            <select id="tesoreria"><option value="caja">Caja (efectivo)</option><option value="bancos">Bancos</option></select></div>
        <div class="fg"><label>Monto</label><input type="number" id="monto" step="any" min="0" placeholder="0"></div>
        <div class="fg"><label>Fecha</label><input type="date" id="fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
        <div class="fg"><label>Nota (opcional)</label><input type="text" id="nota" maxlength="150" placeholder="Ej. factura #123 / aporte socio X"></div>
        <button class="btn" id="go" onclick="guardar()">Registrar movimiento</button>
    </div>
</main>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
function toast(m,t){const e=document.getElementById('toast');e.textContent=m;e.className='toast '+(t||'');e.style.display='block';setTimeout(()=>e.style.display='none',4200);}
async function guardar(){
    const monto=parseFloat(document.getElementById('monto').value)||0;
    if(monto<=0){ toast('Ingresa un monto','err'); return; }
    const btn=document.getElementById('go'); btn.disabled=true;
    const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('accion','movimiento');
    fd.append('tipo',document.getElementById('tipo').value);
    fd.append('tesoreria',document.getElementById('tesoreria').value);
    fd.append('monto',monto);
    fd.append('fecha',document.getElementById('fecha').value);
    fd.append('nota',document.getElementById('nota').value);
    try{
        const r=await fetch('api/contab.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){ toast('Movimiento registrado (asiento #'+d.asiento+')','ok'); setTimeout(()=>location.reload(),1200); }
        else { toast(d.error||'Error','err'); btn.disabled=false; }
    }catch(e){ toast('Error de red','err'); btn.disabled=false; }
}
</script></body></html>
