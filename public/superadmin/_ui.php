<?php
/**
 * Stile condiviso del superadmin: bianco e nero, box semplici.
 * Includere dentro <head>. Per la barra in alto: $saNav = 'tenant' | 'clic'.
 */
?>
<style>
*{box-sizing:border-box;}
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#fff;color:#000;margin:0;padding:32px 16px;font-size:14px;line-height:1.4;}
.wrap{max-width:1200px;margin:0 auto;}
header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:28px;padding-bottom:14px;border-bottom:1px solid #000;}
h1{margin:0;font-size:16px;font-weight:700;letter-spacing:.02em;text-transform:uppercase;}
.nav{display:flex;gap:4px;}
.nav a{color:#000;text-decoration:none;font-size:13px;padding:6px 12px;border:1px solid transparent;}
.nav a:hover{border-color:#000;}
.nav a.on{background:#000;color:#fff;}
.card{border:1px solid #000;padding:20px;margin-bottom:20px;background:#fff;}
.card h2{margin:0 0 16px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;}
label{display:block;font-size:11px;margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em;}
input,select{width:100%;padding:9px 10px;background:#fff;border:1px solid #000;border-radius:0;color:#000;font:inherit;}
input[type=checkbox]{width:auto;}
input:focus,select:focus{outline:2px solid #000;outline-offset:1px;}
select{min-width:160px;}
button,a.btn{font:inherit;cursor:pointer;text-decoration:none;display:inline-block;border-radius:0;}
button.primary,a.btn{background:#000;color:#fff;border:1px solid #000;padding:9px 16px;font-weight:600;}
button.primary:hover,a.btn:hover{background:#fff;color:#000;}
a.btn.ghost{background:#fff;color:#000;}
a.btn.ghost:hover{background:#000;color:#fff;}
button.icon{background:#fff;border:1px solid #000;color:#000;padding:5px 10px;font-size:12px;}
button.icon:hover{background:#000;color:#fff;}
button.icon.danger{border-style:dashed;}
.alert{padding:10px 14px;margin-bottom:16px;border:1px solid #000;}
.alert.err{background:#000;color:#fff;}
.filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;}
.kpi{border:1px solid #000;padding:16px;}
.kpi b{display:block;font-size:28px;font-weight:700;font-variant-numeric:tabular-nums;}
.kpi span{font-size:11px;text-transform:uppercase;letter-spacing:.06em;}
.scroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{text-align:left;padding:8px 10px;font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:.06em;border-bottom:1px solid #000;white-space:nowrap;}
td{padding:9px 10px;border-bottom:1px solid #ddd;vertical-align:top;}
tr:hover td{background:#f4f4f4;}
td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.bar{height:4px;background:#000;margin-top:6px;min-width:2px;}
.tag{display:inline-block;padding:1px 6px;font-size:11px;border:1px solid #000;white-space:nowrap;}
.status{display:inline-block;padding:2px 8px;font-size:11px;font-weight:600;border:1px solid #000;}
.status.on{background:#000;color:#fff;}
.status.off{background:#fff;color:#000;}
.tiny{font-size:11px;color:#555;}
.empty{color:#555;margin:0;}
.two{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
form.inline{display:inline-block;margin:0;}
a.plain{color:#000;}
details summary{cursor:pointer;}
body.auth{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:16px;}
.box{border:1px solid #000;padding:28px;width:340px;max-width:100%;}
.box h1{margin-bottom:20px;}
.box label{margin-top:14px;}
.box button{margin-top:20px;width:100%;background:#000;color:#fff;border:1px solid #000;padding:11px;font-weight:600;}
.box button:hover{background:#fff;color:#000;}
.box .err{margin-top:14px;padding:9px 12px;background:#000;color:#fff;}
.box .code{font-size:22px;text-align:center;letter-spacing:.6em;font-variant-numeric:tabular-nums;}
@media (max-width:900px){.two{grid-template-columns:1fr;}}
</style>
