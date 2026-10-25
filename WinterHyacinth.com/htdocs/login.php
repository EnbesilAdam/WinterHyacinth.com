<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

// .env Dosyasını Güvenli Okuma Fonksiyonu
function loadEnv($file) {
    if (!file_exists($file)) return [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim($value);
    }
    return $env;
}

// .env dosyasını yükle
$env = loadEnv(__DIR__ . '/.env');

// Hassas bilgileri .env'den çekiyoruz
$allowed_user  = strtolower($env['ADMIN_USER'] ?? 'admin');
$allowed_email = strtolower($env['ADMIN_EMAIL'] ?? 'harunuysalyedek@gmail.com');
$dogru_sifre   = $env['ADMIN_PASS'] ?? '12345';
$apiKey        = $env['RESEND_API_KEY'] ?? '';
$toEmail       = $env['ADMIN_EMAIL'] ?? 'harunuysalyedek@gmail.com';

// ---- BRUTE-FORCE KORUMASI ----
if (!isset($_SESSION['attempts'])) $_SESSION['attempts'] = 0;
if (!isset($_SESSION['last_attempt'])) $_SESSION['last_attempt'] = 0;

$lockout_time = 900; // 15 dakika (saniye)
$max_attempts = 5;

if ($_SESSION['attempts'] >= $max_attempts && (time() - $_SESSION['last_attempt']) < $lockout_time) {
    $kalan = $lockout_time - (time() - $_SESSION['last_attempt']);
    echo json_encode(["status" => "error", "message" => "Çok fazla başarısız deneme. Lütfen " . ceil($kalan / 60) . " dakika sonra tekrar deneyin."]);
    exit();
}

// Kilitleme süresi dolduysa sayaç sıfırlansın
if ($_SESSION['attempts'] >= $max_attempts && (time() - $_SESSION['last_attempt']) >= $lockout_time) {
    $_SESSION['attempts'] = 0;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Girilen kullanıcı adını küçük harfe çeviriyoruz (büyük/küçük harf duyarsız olması için)
    $kullanici = strtolower(trim($_POST['username'] ?? ''));
    $sifre     = trim($_POST['password'] ?? '');

    // Kullanıcı adı "admin" veya mail adresiyle eşleşiyorsa ve şifre doğruysa
    $kullanici_dogru = ($kullanici === $allowed_user || $kullanici === $allowed_email);
    $sifre_dogru = hash_equals($dogru_sifre, $sifre);

    if ($kullanici_dogru && $sifre_dogru) {
        // Başarılı giriş: deneme sayacını sıfırla
        $_SESSION['attempts'] = 0;

        // 6 haneli güvenli rastgele kod üret (kriptografik olarak güvenli)
        $code = random_int(100000, 999999);

        // Kodu DOSYAYA DEĞİL, session'a yaz (token.json kaldırıldı)
        $_SESSION['login_code'] = (string)$code;
        $_SESSION['login_code_expires'] = time() + 300; // 5 dakika geçerli
        $_SESSION['login_code_attempts'] = 0; // 2FA kodu için ayrı deneme sayacı

        $postData = [
            'from'    => 'admin@winterhyacinth.com',
            'to'      => [$toEmail],
            'subject' => '🔐 Winterhyacinth - Yönetici Giriş Kodunuz',
            'html'    => '<div style="font-family:sans-serif;padding:20px;background:#0f172a;color:#fff;border-radius:8px;text-align:center;">'
                       . '<h2 style="color:#38bdf8;">Yönetici Giriş Onayı</h2>'
                       . '<p>Panelinize giriş yapmak için aşağıdaki 6 haneli kodu kullanın (5 dakika geçerlidir):</p>'
                       . '<h1 style="background:#1e293b;color:#4ade80;padding:15px;letter-spacing:5px;border-radius:6px;display:inline-block;">' . $code . '</h1>'
                       . '<p style="color:#94a3b8;font-size:12px;margin-top:20px;">IP Adresi: ' . ($_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor') . '</p>'
                       . '</div>'
        ];

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            // Detaylı hatayı kullanıcıya göstermiyoruz, sadece log'a yazıyoruz
            error_log("Resend API hatası (HTTP $httpCode): " . $response);
            echo json_encode(["status" => "error", "message" => "Doğrulama kodu gönderilemedi, lütfen tekrar deneyin."]);
            exit();
        }

        echo json_encode(["status" => "success", "message" => "Şifre doğru! E-postanıza 6 haneli doğrulama kodu gönderildi."]);
        exit();
    } else {
        // Başarısız deneme: sayaç ve zaman güncelle
        $_SESSION['attempts']++;
        $_SESSION['last_attempt'] = time();

        echo json_encode(["status" => "error", "message" => "Hatalı kullanıcı adı veya şifre!"]);
        exit();
    }
}
?>