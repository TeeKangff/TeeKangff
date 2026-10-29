<?php
declare(strict_types=1);
session_start();
$loggedCode = $_SESSION['oneplay_activation'] ?? '';
?>
<!DOCTYPE html>
<html lang="lo">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OnePlay Shop — BCEL</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script>src="https://cdn.tailwindcss.com"</script>
  <script>src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"</script>
  <style>
    * { font-family: 'Noto Sans Lao', system-ui, sans-serif; }
    body { background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 50%, #ecfdf5 100%); }
    .card { background: rgba(255,255,255,.92); backdrop-filter: blur(8px); }
    .tab-active { color: #1d4ed8; border-bottom: 3px solid #2563eb; font-weight: 600; }
    .btn-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
    .spin { animation: spin 1s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body class="min-h-screen text-slate-800">
  <header class="bg-gradient-to-r from-blue-700 to-blue-800 text-white shadow-lg">
    <div class="max-w-4xl mx-auto px-4 py-5 flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center text-2xl">🏪</div>
        <div>
          <h1 class="text-xl font-bold tracking-tight">OnePlay Shop</h1>
          <p class="text-blue-100 text-sm">BCEL OnePay</p>
        </div>
      </div>
      <button id="btnLogout" class="hidden text-sm bg-white/15 hover:bg-white/25 px-4 py-2 rounded-lg transition">ອອກຈາກລະບົບ</button>
    </div>
  </header>
  <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
    <section id="loginPanel" class="card rounded-2xl shadow-xl border border-white/60 p-8 <?= $loggedCode ? 'hidden' : '' ?>">
      <div class="text-center mb-6">
        <div class="inline-flex w-16 h-16 rounded-2xl bg-blue-50 text-3xl items-center justify-center mb-3">🔐</div>
        <h2 class="text-2xl font-bold text-slate-800">ເປີດໃຊ້ງານຮ້ານຄ້າ BCEL OnePay</h2>
        <p class="text-slate-500 mt-2 text-sm max-w-md mx-auto">ໃສ່ລະຫັດເປີດໃຊ້ງານ 12 ຫຼັກ ຈາກແອັບ BCEL OnePay Shop ເພື່ອຜູກມັດລະບົບເຕີມເງິນ</p>
      </div>
      <div class="max-w-md mx-auto space-y-4">
        <input id="activationCode" type="text" maxlength="12" inputmode="numeric" placeholder="• • • • • • • • • • • •" class="w-full border-2 border-slate-200 focus:border-blue-500 rounded-xl px-4 py-3 text-center text-2xl tracking-[.35em] font-mono">
        <button id="btnLogin" class="btn-primary w-full text-white py-3 rounded-xl font-semibold shadow-md transition flex items-center justify-center gap-2">
          <span id="loginBtnText">ເຂົ້າສູ່ລະບົບ / ເປີດໃຊ້ງານ</span>
          <span id="loginSpinner" class="hidden w-5 h-5 border-2 border-white/30 border-t-white rounded-full spin"></span>
        </button>
        <p id="loginError" class="hidden text-red-600 text-sm text-center bg-red-50 border border-red-100 rounded-lg px-3 py-2"></p>
      </div>
    </section>
    <section id="dashPanel" class="<?= $loggedCode ? '' : 'hidden' ?> space-y-5">
      <div class="card rounded-2xl shadow-lg border border-white/60 p-5">
        <div class="flex flex-wrap gap-4 justify-between items-start">
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400 font-medium">ຮ້ານຄ້າ</p>
            <p id="merchantName" class="text-2xl font-bold text-slate-800 mt-1">-</p>
            <p class="text-sm text-slate-500 mt-2">MCID: <span id="mcidText" class="font-mono text-slate-700">-</span></p>
          </div>
          <div class="text-right">
            <span class="inline-block text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full font-medium">● ເຊື່ອມຕໍ່ແລ້ວ</span>
            <p class="text-xs text-slate-400 mt-2">ລະຫັດ: <span id="codeMasked" class="font-mono">****</span></p>
            <button id="btnReset" class="mt-2 text-xs text-red-500 hover:text-red-700 underline">ຣີເຊັດອຸປະກອນ</button>
          </div>
        </div>
      </div>
    </section>
  </main>
  <script>
    const SESSION_CODE = <?= json_encode($loggedCode) ?>;
    let activationCode = SESSION_CODE || '';
    const $ = (id) => document.getElementById(id);
    const maskCode = (c) => c ? ('••••••••' + c.slice(-4)) : '****';

    const loginWithCode = async (code) => {
      activationCode = (code || '').replace(/\D/g, '');
      $('loginError').classList.add('hidden');
      if (activationCode.length !== 12) {
        $('loginError').textContent = 'ລະຫັດຕ້ອງເປັນຕົວເລກ 12 ຫຼັກ';
        $('loginError').classList.remove('hidden');
        return;
      }
      $('btnLogin').disabled = true;
      $('loginSpinner').classList.remove('hidden');
      try {
        const res = await fetch('api.php?action=activate&activation_code=' + activationCode).then(r => r.json());
        if (res.status !== 'success') throw new Error(res.message || 'ເກີດຂໍ້ຜິດພາດ');
        $('loginPanel').classList.add('hidden');
        $('dashPanel').classList.remove('hidden');
        $('btnLogout').classList.remove('hidden');
        $('merchantName').textContent = res.data.owner || '-';
        $('mcidText').textContent = res.data.mcid || '-';
        $('codeMasked').textContent = maskCode(activationCode);
        alert('ເປີດໃຊ້ງານ BCEL OnePay ສຳເລັດແລ້ວ! ລະບົບເຕີມເງິນອັດຕະໂນມັດພ້ອມໃຊ້ງານ.');
      } catch (e) {
        $('loginError').textContent = e.message;
        $('loginError').classList.remove('hidden');
      } finally {
        $('btnLogin').disabled = false;
        $('loginSpinner').classList.add('hidden');
      }
    };
    $('btnLogin').onclick = () => loginWithCode($('activationCode').value);
    $('btnLogout').onclick = async () => { await fetch('api.php?action=logout'); location.reload(); };
    $('btnReset').onclick = async () => { if (confirm('ຣີເຊັດອຸປະກອນ?')) { await fetch('api.php?action=reset'); location.reload(); } };
  </script>
</body>
</html>
