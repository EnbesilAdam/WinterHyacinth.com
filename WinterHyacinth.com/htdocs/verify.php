<?php
session_start();

$message = '';

// ---- 2FA KOD DENEME KORUMASI ----
$max_code_attempts = 3;
$lockout_time = 900; // 15 dakika

if (!isset($_SESSION['login_code_attempts'])) $_SESSION['login_code_attempts'] = 0;
if (!isset($_SESSION['login_code_last_attempt'])) $_SESSION['login_code_last_attempt'] = 0;

$locked_out = false;
if ($_SESSION['login_code_attempts'] >= $max_code_attempts) {
    $gecen = time() - $_SESSION['login_code_last_attempt'];
    if ($gecen < $lockout_time) {
        $locked_out = true;
        $kalan = $lockout_time - $gecen;
        $message = "Çok fazla hatalı deneme. Lütfen " . ceil($kalan / 60) . " dakika sonra tekrar deneyin.";
    } else {
        // Kilitleme süresi doldu, sayaç sıfırlansın
        $_SESSION['login_code_attempts'] = 0;
    }
}

$had_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked_out) {
    $girilenKod = trim($_POST['code'] ?? '');

    // Kod session'da var mı?
    if (!empty($_SESSION['login_code']) && !empty($_SESSION['login_code_expires'])) {

        if (time() > $_SESSION['login_code_expires']) {
            // Süresi dolmuş, temizle
            unset($_SESSION['login_code'], $_SESSION['login_code_expires']);
            $message = "Bu kodun süresi dolmuş (5 dakika). Lütfen tekrar giriş yapın.";
            $had_error = true;
        } elseif (hash_equals((string)$_SESSION['login_code'], $girilenKod)) {
            // Doğru kod
            $_SESSION['yonetici_giris'] = true;
            $_SESSION['login_code_attempts'] = 0;
            // Tek kullanımlık, hemen temizle
            unset($_SESSION['login_code'], $_SESSION['login_code_expires']);
            // Session ID'yi yenile (session fixation koruması)
            session_regenerate_id(true);
            header('Location: admin.php');
            exit();
        } else {
            // Yanlış kod
            $_SESSION['login_code_attempts']++;
            $_SESSION['login_code_last_attempt'] = time();
            $message = "Hatalı doğrulama kodu!";
            $had_error = true;
        }
    } else {
        $message = "Aktif bir giriş talebi bulunamadı. Lütfen önce login sayfasından giriş yapın.";
        $had_error = true;
    }
}

// Kalan süreyi arayüzde geri sayım için kullanmak üzere hesaplayalım (varsa)
$expiresInSeconds = null;
if (!empty($_SESSION['login_code_expires'])) {
    $expiresInSeconds = max(0, $_SESSION['login_code_expires'] - time());
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Winterhyacinth - Kod Doğrulama</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top, #1a1f3a 0%, #0b0e1a 60%, #060810 100%);
            color: #f8fafc;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            overflow: hidden;
            position: relative;
        }

        /* Arka planda yumuşak hareketli ışık efekti */
        body::before, body::after {
            content: "";
            position: absolute;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.25;
            z-index: 0;
            animation: float 12s ease-in-out infinite;
        }
        body::before {
            background: #7d5fff;
            top: -100px;
            left: -100px;
        }
        body::after {
            background: #38bdf8;
            bottom: -120px;
            right: -100px;
            animation-delay: 3s;
        }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(40px, 30px) scale(1.1); }
        }

        .card {
            position: relative;
            z-index: 1;
            background: rgba(22, 27, 34, 0.75);
            backdrop-filter: blur(16px);
            padding: 40px 35px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(125, 95, 255, 0.15);
            width: 360px;
            text-align: center;
            animation: cardIn 0.5s cubic-bezier(0.22, 1, 0.36, 1);
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(24px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .card.shake {
            animation: shake 0.45s;
        }
        @keyframes shake {
            10%, 90% { transform: translateX(-2px); }
            20%, 80% { transform: translateX(4px); }
            30%, 50%, 70% { transform: translateX(-8px); }
            40%, 60% { transform: translateX(8px); }
        }

        .icon-wrap {
            width: 64px;
            height: 64px;
            margin: 0 auto 18px auto;
            border-radius: 50%;
            background: linear-gradient(135deg, #7d5fff, #38bdf8);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 0 6px rgba(125, 95, 255, 0.12);
            animation: pulse 2.4s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 6px rgba(125, 95, 255, 0.12); }
            50% { box-shadow: 0 0 0 10px rgba(125, 95, 255, 0.05); }
        }
        .icon-wrap svg { width: 28px; height: 28px; }

        h2 {
            color: #f8fafc;
            margin: 0 0 6px 0;
            font-size: 1.4rem;
            letter-spacing: 0.3px;
        }

        .subtitle {
            font-size: 13.5px;
            color: #94a3b8;
            margin: 0 0 26px 0;
            line-height: 1.5;
        }

        /* 6 haneli kod kutucukları */
        .otp-row {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        .otp-box {
            width: 44px;
            height: 54px;
            text-align: center;
            font-size: 22px;
            font-weight: 600;
            border-radius: 10px;
            border: 1.5px solid #334155;
            background: #0f172a;
            color: #fff;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.15s;
        }
        .otp-box:focus {
            border-color: #7d5fff;
            box-shadow: 0 0 0 3px rgba(125, 95, 255, 0.2);
            transform: translateY(-1px);
        }
        .otp-box.filled {
            border-color: #4ade80;
        }
        .otp-box:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .countdown {
            font-size: 12.5px;
            color: #64748b;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .countdown strong {
            color: #94a3b8;
            font-variant-numeric: tabular-nums;
        }

        button.submit-btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #7d5fff, #6c4de6);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.2s, opacity 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        button.submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(125, 95, 255, 0.35);
        }
        button.submit-btn:active:not(:disabled) {
            transform: translateY(0);
        }
        button.submit-btn:disabled {
            background: #334155;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2.5px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: none;
        }
        .submit-btn.loading .spinner { display: inline-block; }
        .submit-btn.loading .btn-text { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .error {
            color: #f87171;
            font-size: 13px;
            margin-top: 16px;
            background: rgba(248, 113, 113, 0.08);
            border: 1px solid rgba(248, 113, 113, 0.2);
            border-radius: 8px;
            padding: 10px 12px;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .footer-note {
            margin-top: 22px;
            font-size: 11.5px;
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="card<?php echo $had_error ? ' shake' : ''; ?>" id="verifyCard">
        <div class="icon-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>

        <h2>Doğrulama Kodu</h2>
        <p class="subtitle">E-postanıza gelen 6 haneli kodu girin</p>

        <form method="POST" id="otpForm" autocomplete="off">
            <div class="otp-row" id="otpRow">
                <input type="text" inputmode="numeric" maxlength="1" class="otp-box" data-index="0" <?php if ($locked_out) echo 'disabled'; ?>>
                <input type="text" inputmode="numeric" maxlength="1" class="otp-box" data-index="1" <?php if ($locked_out) echo 'disabled'; ?>>
                <input type="text" inputmode="numeric" maxlength="1" class="otp-box" data-index="2" <?php if ($locked_out) echo 'disabled'; ?>>
                <input type="text" inputmode="numeric" maxlength="1" class="otp-box" data-index="3" <?php if ($locked_out) echo 'disabled'; ?>>
                <input type="text" inputmode="numeric" maxlength="1" class="otp-box" data-index="4" <?php if ($locked_out) echo 'disabled'; ?>>
                <input type="text" inputmode="numeric" maxlength="1" class="otp-box" data-index="5" <?php if ($locked_out) echo 'disabled'; ?>>
            </div>

            <input type="hidden" name="code" id="codeHidden">

            <?php if (!$locked_out && $expiresInSeconds !== null): ?>
                <div class="countdown">
                    <span>Kodun geçerlilik süresi:</span>
                    <strong id="countdownTimer"><?php echo gmdate("i:s", $expiresInSeconds); ?></strong>
                </div>
            <?php endif; ?>

            <button type="submit" class="submit-btn" id="submitBtn" <?php if ($locked_out) echo 'disabled'; ?>>
                <span class="spinner"></span>
                <span class="btn-text">Onayla ve Giriş Yap</span>
            </button>
        </form>

        <?php if (!empty($message)): ?>
            <div class="error"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <p class="footer-note">Winter Hyacinth Studios &middot; Güvenli 2 Adımlı Doğrulama</p>
    </div>

    <script>
        const boxes = Array.from(document.querySelectorAll('.otp-box'));
        const hiddenInput = document.getElementById('codeHidden');
        const form = document.getElementById('otpForm');
        const submitBtn = document.getElementById('submitBtn');

        function updateHiddenValue() {
            hiddenInput.value = boxes.map(b => b.value).join('');
        }

        boxes.forEach((box, idx) => {
            box.addEventListener('input', () => {
                box.value = box.value.replace(/[^0-9]/g, '').slice(0, 1);
                box.classList.toggle('filled', box.value !== '');
                if (box.value && idx < boxes.length - 1) {
                    boxes[idx + 1].focus();
                }
                updateHiddenValue();
            });

            box.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !box.value && idx > 0) {
                    boxes[idx - 1].focus();
                }
            });

            box.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                if (!pasted) return;
                pasted.split('').slice(0, boxes.length).forEach((ch, i) => {
                    if (boxes[i]) {
                        boxes[i].value = ch;
                        boxes[i].classList.add('filled');
                    }
                });
                updateHiddenValue();
                const nextEmpty = boxes.find(b => !b.value);
                (nextEmpty || boxes[boxes.length - 1]).focus();
            });
        });

        // Sayfa açılınca ilk kutuya odaklan
        if (boxes[0] && !boxes[0].disabled) {
            boxes[0].focus();
        }

        form.addEventListener('submit', (e) => {
            updateHiddenValue();
            if (hiddenInput.value.length !== 6) {
                e.preventDefault();
                document.getElementById('verifyCard').classList.add('shake');
                setTimeout(() => document.getElementById('verifyCard').classList.remove('shake'), 450);
                return;
            }
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        });

        // Geri sayım
        const timerEl = document.getElementById('countdownTimer');
        <?php if (!$locked_out && $expiresInSeconds !== null): ?>
        let remaining = <?php echo (int)$expiresInSeconds; ?>;
        if (timerEl) {
            const interval = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(interval);
                    timerEl.textContent = "00:00";
                    return;
                }
                const m = String(Math.floor(remaining / 60)).padStart(2, '0');
                const s = String(remaining % 60).padStart(2, '0');
                timerEl.textContent = `${m}:${s}`;
            }, 1000);
        }
        <?php endif; ?>
    </script>
</body>
</html>