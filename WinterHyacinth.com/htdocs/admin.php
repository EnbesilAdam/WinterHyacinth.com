<?php
session_start();

if (!isset($_SESSION['yonetici_giris']) || $_SESSION['yonetici_giris'] !== true) {
    header('Location: login.html');
    exit();
}

// ---- CSRF Token ----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

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

$env = loadEnv(__DIR__ . '/.env');
$githubToken = $env['GITHUB_TOKEN'] ?? '';
$gistId = 'efa76e1d7df2ba5be195bd4717d339b5';

// ---- API: Gist verisini SUNUCU ÜZERİNDEN çek (token tarayıcıya hiç gitmez) ----
if (isset($_GET['action']) && $_GET['action'] === 'get_gist_data') {
    header('Content-Type: application/json; charset=utf-8');

    $ch = curl_init("https://api.github.com/gists/{$gistId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $githubToken,
        'User-Agent: WinterHyacinthAdmin'
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(["status" => "error", "message" => "Gist verisi alınamadı."]);
        exit();
    }

    $gistData = json_decode($resp, true);
    $posts = [];
    $snapshots = [];
    if (isset($gistData['files']['posts.json']['content'])) {
        $posts = json_decode($gistData['files']['posts.json']['content'], true) ?? [];
    }
    if (isset($gistData['files']['snapshots.json']['content'])) {
        $snapshots = json_decode($gistData['files']['snapshots.json']['content'], true) ?? [];
    }

    echo json_encode(["status" => "success", "posts" => $posts, "snapshots" => $snapshots]);
    exit();
}

// ---- API: Gist verisini SUNUCU ÜZERİNDEN kaydet ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_gist_data') {
    header('Content-Type: application/json; charset=utf-8');

    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrf($csrfHeader)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Güvenlik doğrulaması başarısız (CSRF). Sayfayı yenileyip tekrar deneyin."]);
        exit();
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body) || !isset($body['posts'])) {
        echo json_encode(["status" => "error", "message" => "Geçersiz veri."]);
        exit();
    }
    $posts = $body['posts'];
    $snapshots = $body['snapshots'] ?? [];

    $ch = curl_init("https://api.github.com/gists/{$gistId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'files' => [
            'posts.json'     => ['content' => json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
            'snapshots.json' => ['content' => json_encode($snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]
        ]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $githubToken,
        'Content-Type: application/json',
        'User-Agent: WinterHyacinthAdmin'
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo json_encode(["status" => "success"]);
    } else {
        error_log("Gist güncelleme hatası (HTTP $httpCode): " . $resp);
        echo json_encode(["status" => "error", "message" => "Gist güncellenemedi."]);
    }
    exit();
}

// ---- Görsel Yükleme (Medya Yöneticisi) API ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['media_file'])) {
    header('Content-Type: application/json; charset=utf-8');

    $csrfPost = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($csrfPost)) {
        echo json_encode(["status" => "error", "message" => "Güvenlik doğrulaması başarısız (CSRF). Sayfayı yenileyip tekrar deneyin."]);
        exit();
    }

    if ($_FILES['media_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["status" => "error", "message" => "Dosya yükleme hatası!"]);
        exit();
    }

    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($_FILES['media_file']['size'] > $maxSize) {
        echo json_encode(["status" => "error", "message" => "Dosya çok büyük (maksimum 5MB)!"]);
        exit();
    }

    // İzin verilen GERÇEK görsel tipleri (dosya içeriğinden kontrol edilir, isimden değil)
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['media_file']['tmp_name']);
    finfo_close($finfo);

    // Ekstra doğrulama: dosya gerçekten açılabilir bir görsel mi?
    $imageInfo = @getimagesize($_FILES['media_file']['tmp_name']);

    if (!isset($allowedMimes[$mime]) || $imageInfo === false) {
        echo json_encode(["status" => "error", "message" => "Sadece JPG, PNG, GIF veya WEBP formatında görsel yükleyebilirsiniz!"]);
        exit();
    }

    $targetDir = __DIR__ . '/img/uploads/';
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // Dosya adı TAMAMEN sunucu tarafında üretiliyor, kullanıcının gönderdiği isim hiç kullanılmıyor
    $fileName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMimes[$mime];
    $targetFilePath = $targetDir . $fileName;

    if (move_uploaded_file($_FILES['media_file']['tmp_name'], $targetFilePath)) {
        echo json_encode(["status" => "success", "url" => "img/uploads/" . $fileName]);
    } else {
        echo json_encode(["status" => "error", "message" => "Görsel yüklenirken hata oluştu!"]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetici Paneli | Winter Hyacinth Studios</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Quill.js CSS -->
    <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">

    <!-- CodeMirror CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/show-hint.min.css">

    <style>
        * { box-sizing: border-box; }

        body {
            background: radial-gradient(circle at top, #1a1f3a 0%, #0b0e1a 60%, #060810 100%);
            color: white;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            -webkit-font-smoothing: antialiased;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Arka planda yumuşak hareketli ışık efekti */
        body::before, body::after {
            content: "";
            position: fixed;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            filter: blur(110px);
            opacity: 0.18;
            z-index: 0;
            animation: floatBlob 14s ease-in-out infinite;
            pointer-events: none;
        }
        body::before { background: #7d5fff; top: -120px; left: -120px; }
        body::after { background: #38bdf8; bottom: -140px; right: -120px; animation-delay: 3s; }
        @keyframes floatBlob {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(40px, 30px) scale(1.1); }
        }

        .particles { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
        .particle { position: absolute; width: 3px; height: 3px; background: rgba(125, 95, 255, 0.45); border-radius: 50%; animation: drift linear infinite; }
        @keyframes drift {
            from { transform: translateY(0); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            to { transform: translateY(-100vh); opacity: 0; }
        }

        .navbar { position: fixed; top: 0; width: 100%; display: flex; justify-content: space-between; align-items: center; padding: 20px 5%; background: rgba(11, 14, 20, 0.85); backdrop-filter: blur(10px); z-index: 1000; box-sizing: border-box; border-bottom: 1px solid #1a1f29; }
        .logo { font-size: 1.7rem; font-weight: 800; text-decoration: none; color: #fff; letter-spacing: 1.5px; text-transform: uppercase; white-space: nowrap; }
        .logo span { background: linear-gradient(135deg, #7d5fff, #a78bfa); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .admin-container { flex: 1; max-width: 1150px; width: 100%; margin: 120px auto 60px auto; padding: 0 20px; box-sizing: border-box; position: relative; z-index: 1; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card {
            background: rgba(22, 27, 34, 0.75);
            backdrop-filter: blur(16px);
            padding: 20px;
            border-radius: 14px;
            border: 1px solid rgba(125, 95, 255, 0.15);
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
            animation: fadeInUp 0.5s ease backwards;
        }
        .stat-card:nth-child(1) { animation-delay: 0.05s; }
        .stat-card:nth-child(2) { animation-delay: 0.12s; }
        .stat-card:nth-child(3) { animation-delay: 0.19s; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 16px 36px rgba(125, 95, 255, 0.18); border-color: rgba(125, 95, 255, 0.35); }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; background: rgba(125, 95, 255, 0.12); color: #7d5fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
        .stat-info h3 { margin: 0; font-size: 1.6rem; color: #fff; font-variant-numeric: tabular-nums; }
        .stat-info p { margin: 5px 0 0 0; font-size: 0.85rem; color: #94a3b8; }

        .card {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(16px);
            padding: 30px;
            border-radius: 18px;
            border: 1px solid rgba(125, 95, 255, 0.12);
            box-shadow: 0 15px 40px rgba(0,0,0,0.4);
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease backwards;
            transition: border-color 0.3s ease;
        }
        .card:hover { border-color: rgba(125, 95, 255, 0.22); }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 { margin-top: 0; font-size: 1.4rem; margin-bottom: 20px; border-left: 4px solid #7d5fff; padding-left: 12px; letter-spacing: 0.2px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-size: 0.87rem; color: #ccc; }
        input, select, textarea {
            width: 100%; padding: 10px 12px;
            border: 1.5px solid #334155;
            background: #0f172a;
            color: #fff;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #7d5fff; box-shadow: 0 0 0 3px rgba(125, 95, 255, 0.18); }
        textarea { resize: vertical; height: 90px; }

        .editor-preview-layout { display: grid; grid-template-columns: 1.2fr 1fr; gap: 20px; align-items: start; }
        .preview-box { background: #0d1117; border: 1px dashed #30363d; border-radius: 10px; padding: 20px; box-sizing: border-box; position: sticky; top: 100px; }
        .preview-card-mock { background: #161b22; border: 1px solid #30363d; border-radius: 10px; overflow: hidden; margin-top: 10px; transition: border-color 0.2s; }
        .preview-card-mock img { width: 100%; height: 120px; object-fit: cover; }
        .preview-card-body { padding: 15px; }
        .preview-content-box { margin-top: 10px; font-size: 0.85rem; color: #ccc; background: #0d1117; padding: 10px; border-radius: 6px; max-height: 150px; overflow-y: auto; }

        .lang-tabs { display: flex; gap: 10px; margin-bottom: 15px; border-bottom: 1px solid #30363d; padding-bottom: 10px; }
        .lang-tab { background: #0d1117; color: #888; border: 1px solid #30363d; padding: 6px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.85rem; transition: 0.2s; }
        .lang-tab:hover { border-color: #7d5fff; color: #ccc; }
        .lang-tab.active { background: linear-gradient(135deg, #7d5fff, #6c4de6); color: white; border-color: #7d5fff; }
        .lang-content { display: none; animation: fadeIn 0.3s ease; }
        .lang-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .ql-toolbar.ql-snow { background: #1a1f29; border-color: #30363d !important; border-top-left-radius: 8px; border-top-right-radius: 8px; }
        .ql-container.ql-snow { background: #0d1117; border-color: #30363d !important; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; color: #fff; font-family: inherit; font-size: 0.95rem; }
        .ql-editor { min-height: 140px; }

        .CodeMirror { height: 320px; border-radius: 8px; border: 1px solid #30363d; font-family: 'Consolas', monospace; font-size: 0.9rem; line-height: 1.5; }

        .btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #7d5fff, #6c4de6);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.2s ease, opacity 0.2s;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(125, 95, 255, 0.35); }
        .btn:active { transform: translateY(0); }
        .btn-success { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .btn-success:hover { box-shadow: 0 8px 20px rgba(34, 197, 94, 0.35); }
        .btn-warning { background: #f59e0b; color: white; padding: 6px 12px; font-size: 0.85rem; border-radius: 6px; border: none; cursor: pointer; transition: transform 0.15s, box-shadow 0.2s; }
        .btn-warning:hover { background: #d97706; transform: translateY(-1px); box-shadow: 0 6px 14px rgba(245, 158, 11, 0.3); }
        .btn-danger { background: #ef4444; padding: 6px 12px; font-size: 0.85rem; border-radius: 6px; color: white; border: none; cursor: pointer; transition: transform 0.15s, box-shadow 0.2s; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 6px 14px rgba(239, 68, 68, 0.3); }
        .btn-secondary { background: #334155; color: white; padding: 10px 15px; border-radius: 8px; border: none; cursor: pointer; display: none; transition: background 0.2s; }
        .btn-secondary:hover { background: #1e293b; }
        .btn-sm { padding: 5px 10px; font-size: 0.8rem; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #30363d; font-size: 0.9rem; }
        th { background: #0d1117; color: #94a3b8; font-weight: 600; }
        tr { transition: background 0.2s; }
        tr:hover { background: rgba(125, 95, 255, 0.05); }

        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .page-btn { background: #0d1117; color: #ccc; border: 1px solid #30363d; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; transition: 0.2s; }
        .page-btn:hover { border-color: #7d5fff; color: #fff; }
        .page-btn.active { background: linear-gradient(135deg, #7d5fff, #6c4de6); color: white; border-color: #7d5fff; }

        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: none;
        }
        .alert.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-success { background: rgba(74, 222, 128, 0.1); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.25); }
        .alert-error { background: rgba(248, 113, 113, 0.1); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.25); }

        .logout-btn { padding: 8px 18px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.85rem; transition: transform 0.15s, box-shadow 0.2s; }
        .logout-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(239, 68, 68, 0.35); }
        footer { text-align: center; padding: 30px; color: #555; background: #07090d; border-top: 1px solid #1a1f29; font-size: 0.85rem; position: relative; z-index: 1; }

        /* Spinner (yükleniyor göstergesi) */
        .spinner-inline {
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            border-radius: 50%;
            display: inline-block;
            animation: spin 0.7s linear infinite;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media(max-width: 900px) { .editor-preview-layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <div class="particles" id="particles"></div>

    <nav class="navbar">
        <a href="#" class="logo">WINTER<span>HYACINTH</span></a>
        <div class="nav-links" style="display: flex; align-items: center; gap: 15px;">
            <div style="display: flex; gap: 5px;">
                <button type="button" class="lang-tab active" id="navbar-tr-btn" onclick="switchLang('tr')" style="padding: 4px 10px; font-size: 0.8rem;">TR</button>
                <button type="button" class="lang-tab" id="navbar-en-btn" onclick="switchLang('en')" style="padding: 4px 10px; font-size: 0.8rem;">EN</button>
            </div>
            <a href="logout.php" class="logout-btn" id="nav-logout">Çıkış Yap</a>
        </div>
    </nav>

    <main class="admin-container">

        <div id="status-alert" class="alert"></div>

        <!-- İSTATİSTİK KARTLARI -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-newspaper"></i></div>
                <div class="stat-info">
                    <h3 id="stat-total-posts">0</h3>
                    <p id="stat-lbl-total">Toplam Devlog</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #38bdf8; background: rgba(56, 189, 248, 0.1);"><i class="fas fa-user-edit"></i></div>
                <div class="stat-info">
                    <h3 id="stat-enbest-posts">0</h3>
                    <p id="stat-lbl-enbest">Enbest Yazıları</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #4ade80; background: rgba(74, 222, 128, 0.1);"><i class="fas fa-shield-alt"></i></div>
                <div class="stat-info">
                    <h3 id="stat-admin-posts">0</h3>
                    <p id="stat-lbl-admin">Admin Yazıları</p>
                </div>
            </div>
        </div>

        <!-- ÖZEL SÜRÜM / ANLIK YEDEK NOKTALARI (SNAPSHOTS) -->
        <div class="card">
            <h2 id="snapshot-card-title"><i class="fas fa-bookmark"></i> Özel Sürüm / Anlık Yedek Noktaları (Snapshots)</h2>
            <p id="snapshot-card-desc" style="color: #888; font-size: 0.85rem; margin-bottom: 15px;">Mevcut durumunuzu özel bir isim ve tarihle kaydedebilir, dilediğiniz zaman tek tıkla o sürüme geri dönebilirsiniz:</p>

            <div style="display: flex; gap: 10px; margin-bottom: 20px; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="flex: 2; margin-bottom: 0; min-width: 250px;">
                    <label id="snapshot-name-lbl">Sürüm Adı / Etiketi</label>
                    <input type="text" id="snapshot-name-input" placeholder="Örn: 25 Ekim 2026 - Kararlı Sürüm">
                </div>
                <button type="button" id="create-snapshot-btn" class="btn btn-success" style="height: 42px;" onclick="createCustomSnapshot()"><i class="fas fa-save"></i> <span id="snapshot-btn-text">Bu Anki Durumu Kaydet</span></button>
            </div>

            <div id="snapshots-list-container">
                <p style="color: #888; text-align: center; padding: 15px;">Kayıtlı özel sürüm bulunmuyor.</p>
            </div>
        </div>

        <!-- MEDYA YÖNETİCİSİ -->
        <div class="card">
            <h2 id="media-card-title"><i class="fas fa-images"></i> Görsel & Medya Yöneticisi</h2>
            <form id="media-upload-form" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: flex-end;">
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <input type="file" id="media-file-input" accept="image/*" required>
                </div>
                <button type="submit" id="media-upload-btn" class="btn btn-success" style="height: 42px;"><i class="fas fa-upload"></i> Yükle</button>
            </form>
            <div id="media-result" style="margin-top: 15px; display: none;"></div>
        </div>

        <!-- FORM VE CANLI ÖNİZLEME -->
        <div class="card">
            <h2 id="form-main-title">Yeni Geliştirici Günlüğü Ekle</h2>

            <div class="editor-preview-layout">
                <!-- FORM ALANI -->
                <div>
                    <form id="quick-add-form">
                        <input type="hidden" id="edit-id" value="">

                        <div id="lang-tr" class="lang-content active">
                            <div class="form-group">
                                <label id="lbl-title">Başlık (Türkçe)</label>
                                <input type="text" id="add-baslik" oninput="updatePreview()" placeholder="Örn: V0.2 Güncellemesi">
                            </div>
                            <div class="form-group">
                                <label id="lbl-summary">Özet / Kısa Açıklama</label>
                                <textarea id="add-ozet" oninput="updatePreview()" placeholder="Kısa özet..."></textarea>
                            </div>
                            <div class="form-group">
                                <label id="lbl-content">Tam İçerik (Zengin Metin)</label>
                                <div id="editor-container-tr"></div>
                            </div>
                        </div>

                        <div id="lang-en" class="lang-content">
                            <div class="form-group">
                                <label id="lbl-title-en">Title (English)</label>
                                <input type="text" id="add-baslik-en" oninput="updatePreview()" placeholder="Ex: V0.2 Update">
                            </div>
                            <div class="form-group">
                                <label id="lbl-summary-en">Summary / Short Description</label>
                                <textarea id="add-ozet-en" oninput="updatePreview()" placeholder="Short summary..."></textarea>
                            </div>
                            <div class="form-group">
                                <label id="lbl-content-en">Full Content (Rich Text)</label>
                                <div id="editor-container-en"></div>
                            </div>
                        </div>

                        <div class="form-row" style="margin-top: 15px;">
                            <div class="form-group">
                                <label id="lbl-author">Yazar</label>
                                <select id="add-yazar" onchange="updatePreview()" required>
                                    <option value="Enbest">Enbest</option>
                                    <option value="Admin">Admin</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label id="lbl-category">Kategori</label>
                                <select id="add-kategori" onchange="updatePreview()">
                                    <option value="Güncelleme">Güncelleme</option>
                                    <option value="Duyuru">Duyuru</option>
                                    <option value="Oynanış">Oynanış</option>
                                    <option value="Hata Düzeltmesi">Hata Düzeltmesi</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label id="lbl-tags">Etiketler</label>
                                <input type="text" id="add-tags" placeholder="csharp, devlog">
                            </div>
                            <div class="form-group">
                                <label id="lbl-seo">SEO Açıklama</label>
                                <input type="text" id="add-seo-desc" placeholder="SEO meta desc">
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button type="submit" id="form-submit-btn" class="btn"><i class="fas fa-bolt"></i> Kaydet ve Gönder</button>
                            <button type="button" id="cancel-edit-btn" class="btn-secondary">İptal</button>
                        </div>
                    </form>
                </div>

                <!-- CANLI ÖNİZLEME ALANI -->
                <div>
                    <label id="preview-box-title"><i class="fas fa-eye"></i> Sitedeki Görünüm Önizlemesi</label>
                    <div class="preview-box">
                        <div class="preview-card-mock">
                            <img src="img/winterhyacinthLogo.jpg" alt="Banner">
                            <div class="preview-card-body">
                                <span style="font-size: 0.75rem; color: #7d5fff; font-weight: bold;" id="prev-category">GÜNCELLEME</span>
                                <h3 id="prev-title" style="margin: 8px 0; font-size: 1.1rem; color: #fff;">Başlık Burada Çıkacak</h3>
                                <p id="prev-summary" style="font-size: 0.85rem; color: #888; margin: 0 0 10px 0;">Özet açıklaması buraya yazılacak...</p>
                                <div class="preview-content-box" id="prev-content">Zengin içerik önizlemesi...</div>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: #aaa; border-top: 1px solid #30363d; padding-top: 8px; margin-top: 10px;">
                                    <span id="prev-author">Yazar: Enbest</span>
                                    <span id="prev-date">Tarih</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLO VE TOPLU İŞLEMLER -->
        <div class="card">
            <h2 id="table-card-title">Mevcut Loglar & Toplu İşlemler</h2>

            <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="flex: 2; margin-bottom: 0;">
                    <label id="search-input-label"><i class="fas fa-search"></i> Loglarda Ara</label>
                    <input type="text" id="search-input" placeholder="Başlık veya yazar ara...">
                </div>
                <button type="button" id="batch-delete-btn" class="btn btn-danger" style="height: 42px;"><i class="fas fa-trash-alt"></i> Seçilenleri Toplu Sil</button>
            </div>

            <div id="posts-table-container">
                <p style="color: #888; text-align: center; padding: 20px;">Loglar yükleniyor...</p>
            </div>

            <div id="pagination-container" class="pagination"></div>
        </div>

        <!-- KOD DÜZENLEYİCİ -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h2 id="json-card-title" style="margin-bottom:0; border-left:none; padding-left:0;">Gist Canlı Kod Düzenleyici (JSON)</h2>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="codeMirrorEditor.undo()"><i class="fas fa-undo"></i> <span id="btn-undo">Geri Al</span></button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="codeMirrorEditor.redo()"><i class="fas fa-redo"></i> <span id="btn-redo">Yeniden Yap</span></button>
                </div>
            </div>
            <textarea id="json-code-editor">Gist yükleniyor...</textarea>
            <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                <button id="save-gist-btn" class="btn btn-success"><i class="fas fa-save"></i> <span id="btn-save-gist">Kod Değişikliklerini Kaydet</span></button>
                <span id="editor-status" style="font-size: 0.85rem; color: #888;"></span>
            </div>
        </div>

    </main>

    <footer>
        <p>&copy; 2026 Winter Hyacinth Studios. All rights reserved.</p>
    </footer>

    <!-- Kütüphane Scriptleri -->
    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/show-hint.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/javascript-hint.min.js"></script>

    <script>
        // NOT: GitHub token artık burada YOK. Tüm Gist okuma/yazma işlemleri
        // sunucudaki admin.php üzerinden (?action=get_gist_data / save_gist_data) yapılıyor.
        const CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8"); ?>';

        let currentPosts = [];
        let currentSnapshots = [];
        let quillTr, quillEn;
        let codeMirrorEditor;
        let currentPage = 1;
        const itemsPerPage = 5;
        let currentLang = 'tr';
        let isEditingState = false;
        let editingPostId = null;

        // Arka plana hafif yüzen parçacıklar ekle (sadece görsel)
        (function() {
            const container = document.getElementById('particles');
            if (!container) return;
            const count = 20;
            for (let i = 0; i < count; i++) {
                const p = document.createElement('div');
                p.className = 'particle';
                const left = Math.random() * 100;
                const delay = Math.random() * 10;
                const duration = 9 + Math.random() * 10;
                const size = 2 + Math.random() * 2;
                p.style.left = left + 'vw';
                p.style.bottom = '-10px';
                p.style.width = size + 'px';
                p.style.height = size + 'px';
                p.style.animationDelay = delay + 's';
                p.style.animationDuration = duration + 's';
                container.appendChild(p);
            }
        })();

        document.addEventListener("DOMContentLoaded", function() {
            quillTr = new Quill('#editor-container-tr', { theme: 'snow', modules: { toolbar: [['bold', 'italic', 'underline', 'strike'], ['blockquote', 'code-block'], [{ header: [1, 2, 3, false] }], [{ list: 'ordered'}, { list: 'bullet' }], ['link']] } });
            quillEn = new Quill('#editor-container-en', { theme: 'snow', modules: { toolbar: [['bold', 'italic', 'underline', 'strike'], ['blockquote', 'code-block'], [{ header: [1, 2, 3, false] }], [{ list: 'ordered'}, { list: 'bullet' }], ['link']] } });

            quillTr.on('text-change', function() {
                if (currentLang === 'tr') {
                    document.getElementById('prev-content').innerHTML = quillTr.root.innerHTML || 'Zengin içerik önizlemesi...';
                }
            });

            quillEn.on('text-change', function() {
                if (currentLang === 'en') {
                    document.getElementById('prev-content').innerHTML = quillEn.root.innerHTML || 'Rich text content preview...';
                }
            });

            codeMirrorEditor = CodeMirror.fromTextArea(document.getElementById('json-code-editor'), {
                mode: { name: "javascript", json: true },
                theme: "dracula",
                lineNumbers: true,
                autoCloseBrackets: true,
                matchBrackets: true,
                indentUnit: 4,
                extraKeys: {"Ctrl-Space": "autocomplete"}
            });

            document.getElementById('media-upload-form').addEventListener('submit', async function(e) {
                e.preventDefault();
                const fileInput = document.getElementById('media-file-input');
                const formData = new FormData();
                formData.append('media_file', fileInput.files[0]);
                formData.append('csrf_token', CSRF_TOKEN);

                try {
                    const res = await fetch('', { method: 'POST', body: formData });
                    const data = await res.json();
                    const resultDiv = document.getElementById('media-result');
                    resultDiv.style.display = 'block';
                    if (data.status === 'success') {
                        let msg = currentLang === 'tr' ? `Yüklendi! Bağlantı:` : `Uploaded! Link:`;
                        let btnText = currentLang === 'tr' ? `Kopyala` : `Copy`;
                        let alertText = currentLang === 'tr' ? `Bağlantı kopyalandı!` : `Link copied!`;
                        resultDiv.innerHTML = `<span style="color: #4ade80;">${msg} <code>${data.url}</code></span> <button type="button" class="btn btn-sm btn-success" onclick="navigator.clipboard.writeText('${data.url}'); alert('${alertText}');">${btnText}</button>`;
                    } else {
                        resultDiv.innerHTML = `<span style="color: #f87171;">${data.message}</span>`;
                    }
                } catch (err) { alert(currentLang === 'tr' ? 'Hata oluştu!' : 'An error occurred!'); }
            });

            // Varsayılan snapshot input değerini bugünün tarihi yapalım
            const todayStr = new Date().toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' });
            document.getElementById('snapshot-name-input').value = `${todayStr} - Yedek`;

            fetchGistData();
            updatePreview();
        });

        // Kapsamlı Dil Değiştirme ve Arayüz Çevirisi (Localization)
        window.switchLang = function(lang) {
            currentLang = lang;
            document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.lang-content').forEach(c => c.classList.remove('active'));

            if (lang === 'tr') {
                document.getElementById('navbar-tr-btn').classList.add('active');
                document.getElementById('navbar-en-btn').classList.remove('active');
                document.getElementById('lang-tr').classList.add('active');

                document.getElementById('nav-logout').textContent = 'Çıkış Yap';
                document.getElementById('stat-lbl-total').textContent = 'Toplam Devlog';
                document.getElementById('stat-lbl-enbest').textContent = 'Enbest Yazıları';
                document.getElementById('stat-lbl-admin').textContent = 'Admin Yazıları';

                document.getElementById('snapshot-card-title').innerHTML = '<i class="fas fa-bookmark"></i> Özel Sürüm / Anlık Yedek Noktaları (Snapshots)';
                document.getElementById('snapshot-card-desc').textContent = 'Mevcut durumunuzu özel bir isim ve tarihle kaydedebilir, dilediğiniz zaman tek tıkla o sürüme geri dönebilirsiniz:';
                document.getElementById('snapshot-name-lbl').textContent = 'Sürüm Adı / Etiketi';
                document.getElementById('snapshot-btn-text').textContent = 'Bu Anki Durumu Kaydet';

                document.getElementById('media-card-title').innerHTML = '<i class="fas fa-images"></i> Görsel & Medya Yöneticisi';
                document.getElementById('media-upload-btn').innerHTML = '<i class="fas fa-upload"></i> Yükle';

                document.getElementById('form-main-title').textContent = isEditingState ? `Logu Düzenle (#${editingPostId})` : 'Yeni Geliştirici Günlüğü Ekle';
                document.getElementById('lbl-title').textContent = 'Başlık (Türkçe)';
                document.getElementById('lbl-summary').textContent = 'Özet / Kısa Açıklama';
                document.getElementById('lbl-content').textContent = 'Tam İçerik (Zengin Metin)';
                document.getElementById('lbl-author').textContent = 'Yazar';
                document.getElementById('lbl-category').textContent = 'Kategori';
                document.getElementById('lbl-tags').textContent = 'Etiketler';
                document.getElementById('lbl-seo').textContent = 'SEO Açıklama';

                document.getElementById('form-submit-btn').innerHTML = isEditingState ? '<i class="fas fa-save"></i> Değişiklikleri Kaydet' : '<i class="fas fa-bolt"></i> Kaydet ve Gönder';
                document.getElementById('cancel-edit-btn').textContent = 'İptal';

                document.getElementById('preview-box-title').innerHTML = '<i class="fas fa-eye"></i> Sitedeki Görünüm Önizlemesi';
                document.getElementById('table-card-title').textContent = 'Mevcut Loglar & Toplu İşlemler';
                document.getElementById('search-input-label').innerHTML = '<i class="fas fa-search"></i> Loglarda Ara';
                document.getElementById('search-input').placeholder = 'Başlık veya yazar ara...';
                document.getElementById('batch-delete-btn').innerHTML = '<i class="fas fa-trash-alt"></i> Seçilenleri Toplu Sil';

                document.getElementById('json-card-title').textContent = 'Gist Canlı Kod Düzenleyici (JSON)';
                document.getElementById('btn-undo').textContent = 'Geri Al';
                document.getElementById('btn-redo').textContent = 'Yeniden Yap';
                document.getElementById('btn-save-gist').textContent = 'Kod Değişikliklerini Kaydet';

                document.getElementById('prev-content').innerHTML = quillTr.root.innerHTML || 'Zengin içerik önizlemesi...';
            } else {
                document.getElementById('navbar-en-btn').classList.add('active');
                document.getElementById('navbar-tr-btn').classList.remove('active');
                document.getElementById('lang-en').classList.add('active');

                document.getElementById('nav-logout').textContent = 'Logout';
                document.getElementById('stat-lbl-total').textContent = 'Total Devlogs';
                document.getElementById('stat-lbl-enbest').textContent = 'Enbest Posts';
                document.getElementById('stat-lbl-admin').textContent = 'Admin Posts';

                document.getElementById('snapshot-card-title').innerHTML = '<i class="fas fa-bookmark"></i> Custom Version / Snapshots';
                document.getElementById('snapshot-card-desc').textContent = 'You can save your current state with a custom name and date, and rollback to it anytime with one click:';
                document.getElementById('snapshot-name-lbl').textContent = 'Version Name / Tag';
                document.getElementById('snapshot-btn-text').textContent = 'Save Current State';

                document.getElementById('media-card-title').innerHTML = '<i class="fas fa-images"></i> Media Manager';
                document.getElementById('media-upload-btn').innerHTML = '<i class="fas fa-upload"></i> Upload';

                document.getElementById('form-main-title').textContent = isEditingState ? `Edit Devlog (#${editingPostId})` : 'Add New Devlog';
                document.getElementById('lbl-title-en').textContent = 'Title (English)';
                document.getElementById('lbl-summary-en').textContent = 'Summary / Short Description';
                document.getElementById('lbl-content-en').textContent = 'Full Content (Rich Text)';
                document.getElementById('lbl-author').textContent = 'Author';
                document.getElementById('lbl-category').textContent = 'Category';
                document.getElementById('lbl-tags').textContent = 'Tags';
                document.getElementById('lbl-seo').textContent = 'SEO Description';

                document.getElementById('form-submit-btn').innerHTML = isEditingState ? '<i class="fas fa-save"></i> Save Changes' : '<i class="fas fa-bolt"></i> Save & Send';
                document.getElementById('cancel-edit-btn').textContent = 'Cancel';

                document.getElementById('preview-box-title').innerHTML = '<i class="fas fa-eye"></i> Live Site Preview';
                document.getElementById('table-card-title').textContent = 'Existing Devlogs & Batch Actions';
                document.getElementById('search-input-label').innerHTML = '<i class="fas fa-search"></i> Search Devlogs';
                document.getElementById('search-input').placeholder = 'Search by title or author...';
                document.getElementById('batch-delete-btn').innerHTML = '<i class="fas fa-trash-alt"></i> Delete Selected';

                document.getElementById('json-card-title').textContent = 'Gist Live Code Editor (JSON)';
                document.getElementById('btn-undo').textContent = 'Undo';
                document.getElementById('btn-redo').textContent = 'Redo';
                document.getElementById('btn-save-gist').textContent = 'Save Code Changes';

                document.getElementById('prev-content').innerHTML = quillEn.root.innerHTML || 'Rich text content preview...';
            }
            renderSnapshotsUI();
            updatePreview();
        };

        // Özel Sürüm (Snapshot) Oluşturma
        window.createCustomSnapshot = function() {
            const nameInput = document.getElementById('snapshot-name-input');
            const snapName = nameInput.value.trim() || (`Yedek - ` + new Date().toLocaleString('tr-TR'));

            const newSnapshot = {
                id: 'snap_' + Date.now(),
                name: snapName,
                date: new Date().toLocaleString('tr-TR'),
                posts: JSON.parse(JSON.stringify(currentPosts))
            };

            currentSnapshots.unshift(newSnapshot);
            renderSnapshotsUI();
            saveToGithubGist(currentPosts);
            showAlert(currentLang === 'tr' ? `"${snapName}" adlı özel sürüm başarıyla kaydedildi!` : `Custom snapshot "${snapName}" saved successfully!`);
        };

        // Kayıtlı Özel Sürümü Geri Yükleme (Rollback)
        window.restoreCustomSnapshot = function(snapId) {
            const snap = currentSnapshots.find(s => s.id === snapId);
            if (!snap) return;

            if (!confirm(currentLang === 'tr' ? `"${snap.name}" sürümüne geri dönmek istediğinize emin misiniz?` : `Are you sure you want to rollback to version "${snap.name}"?`)) return;

            currentPosts = JSON.parse(JSON.stringify(snap.posts));
            renderUI();
            saveToGithubGist(currentPosts);
            showAlert(currentLang === 'tr' ? `"${snap.name}" sürümüne başarıyla geri dönüldü!` : `Successfully rolled back to version "${snap.name}"!`);
        };

        // Kayıtlı Özel Sürümü Silme
        window.deleteCustomSnapshot = function(snapId) {
            if (!confirm(currentLang === 'tr' ? 'Bu özel sürümü silmek istediğinize emin misiniz?' : 'Are you sure you want to delete this custom snapshot?')) return;
            currentSnapshots = currentSnapshots.filter(s => s.id !== snapId);
            renderSnapshotsUI();
            saveToGithubGist(currentPosts);
        };

        // Snapshot Listesini Arayüze Çizme
        function renderSnapshotsUI() {
            const container = document.getElementById('snapshots-list-container');
            if (!currentSnapshots || currentSnapshots.length === 0) {
                container.innerHTML = `<p style="color: #888; text-align: center; padding: 15px;">${currentLang === 'tr' ? 'Kayıtlı özel sürüm bulunmuyor.' : 'No saved custom snapshots.'}</p>`;
                return;
            }

            let html = '<div style="display: flex; flex-direction: column; gap: 10px; max-height: 220px; overflow-y: auto;">';
            currentSnapshots.forEach(s => {
                let restoreTxt = currentLang === 'tr' ? 'Geri Yükle' : 'Restore';
                let delTxt = currentLang === 'tr' ? 'Sil' : 'Delete';
                html += `
                    <div style="display: flex; justify-content: space-between; align-items: center; background: #0d1117; padding: 10px 15px; border-radius: 6px; border: 1px solid #30363d;">
                        <div>
                            <span style="font-weight: bold; color: #7d5fff; font-size: 0.95rem;">${s.name}</span>
                            <span style="font-size: 0.8rem; color: #888; margin-left: 10px;">(${s.date})</span>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="btn btn-sm btn-warning" onclick="restoreCustomSnapshot('${s.id}')"><i class="fas fa-history"></i> ${restoreTxt}</button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="deleteCustomSnapshot('${s.id}')"><i class="fas fa-trash"></i> ${delTxt}</button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        // Canlı Önizleme Güncelleyici
        window.updatePreview = function() {
            const baslik = currentLang === 'tr' ? document.getElementById('add-baslik').value.trim() : document.getElementById('add-baslik-en').value.trim();
            const ozet = currentLang === 'tr' ? document.getElementById('add-ozet').value.trim() : document.getElementById('add-ozet-en').value.trim();
            const yazar = document.getElementById('add-yazar').value;
            const kategori = document.getElementById('add-kategori').value;

            document.getElementById('prev-title').textContent = baslik || (currentLang === 'tr' ? 'Başlık Burada Çıkacak' : 'Title Will Appear Here');
            document.getElementById('prev-summary').textContent = ozet || (currentLang === 'tr' ? 'Özet açıklaması buraya yazılacak...' : 'Summary description will appear here...');
            document.getElementById('prev-author').textContent = (currentLang === 'tr' ? 'Yazar: ' : 'Author: ') + yazar;
            document.getElementById('prev-category').textContent = kategori.toUpperCase();
            document.getElementById('prev-date').textContent = new Date().toLocaleDateString(currentLang === 'tr' ? 'tr-TR' : 'en-US', { day: 'numeric', month: 'long', year: 'numeric' });
        };

        function showAlert(msg, isSuccess = true) {
            const alert = document.getElementById('status-alert');
            alert.className = 'alert show ' + (isSuccess ? 'alert-success' : 'alert-error');
            alert.textContent = msg;
            alert.style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ID'leri 1'den başlayarak sırayla yeniden numaralandırır (boşluk bırakmaz)
        function renumberPosts() {
            currentPosts.sort((a, b) => parseInt(a.id) - parseInt(b.id));
            currentPosts.forEach((p, idx) => {
                p.id = (idx + 1).toString();
            });
        }

        function updateStats() {
            document.getElementById('stat-total-posts').textContent = currentPosts.length;
            document.getElementById('stat-enbest-posts').textContent = currentPosts.filter(p => p.yazar === 'Enbest').length;
            document.getElementById('stat-admin-posts').textContent = currentPosts.filter(p => p.yazar === 'Admin').length;
        }

        function renderUI(postsToRender = currentPosts) {
            postsToRender.sort((a, b) => parseInt(a.id) - parseInt(b.id));

            if (codeMirrorEditor && document.activeElement !== codeMirrorEditor.getWrapperElement()) {
                codeMirrorEditor.setValue(JSON.stringify(currentPosts, null, 4));
            }

            updateStats();

            const container = document.getElementById('posts-table-container');
            const paginationContainer = document.getElementById('pagination-container');

            if (!postsToRender || postsToRender.length === 0) {
                container.innerHTML = `<p style="color: #888; text-align: center; padding: 20px;">${currentLang === 'tr' ? 'Kayıt bulunamadı.' : 'No records found.'}</p>`;
                paginationContainer.innerHTML = '';
                return;
            }

            const totalPages = Math.ceil(postsToRender.length / itemsPerPage);
            if (currentPage > totalPages) currentPage = totalPages;
            const start = (currentPage - 1) * itemsPerPage;
            const paginatedPosts = postsToRender.slice(start, start + itemsPerPage);

            let thDate = currentLang === 'tr' ? 'Tarih' : 'Date';
            let thTitle = currentLang === 'tr' ? 'Başlık' : 'Title';
            let thAuthor = currentLang === 'tr' ? 'Yazar' : 'Author';
            let thCategory = currentLang === 'tr' ? 'Kategori' : 'Category';
            let thActions = currentLang === 'tr' ? 'İşlemler' : 'Actions';
            let btnEdit = currentLang === 'tr' ? 'Düzenle' : 'Edit';
            let btnDel = currentLang === 'tr' ? 'Sil' : 'Delete';

            let html = `
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="select-all" onclick="toggleSelectAll(this)"></th>
                            <th style="width: 50px;">ID</th>
                            <th>${thDate}</th>
                            <th>${thTitle}</th>
                            <th>${thAuthor}</th>
                            <th>${thCategory}</th>
                            <th style="width: 150px; text-align: center;">${thActions}</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            paginatedPosts.forEach(post => {
                let displayTitle = currentLang === 'en' && post.baslik_en ? post.baslik_en : post.baslik;
                html += `
                    <tr>
                        <td><input type="checkbox" class="post-checkbox" value="${post.id}"></td>
                        <td><strong>#${post.id}</strong></td>
                        <td style="color: #888; white-space: nowrap;">${post.tarih}</td>
                        <td>${displayTitle}</td>
                        <td><span style="color: #7d5fff; font-weight: bold;">${post.yazar || 'Bilinmiyor'}</span></td>
                        <td><span style="background: rgba(125,95,255,0.1); color: #7d5fff; padding: 3px 8px; border-radius: 4px; font-size: 0.8rem;">${post.kategori || 'Güncelleme'}</span></td>
                        <td style="text-align: center; display: flex; gap: 5px; justify-content: center;">
                            <button type="button" class="btn-warning" onclick="editPost('${post.id}')"><i class="fas fa-edit"></i> ${btnEdit}</button>
                            <button type="button" class="btn-danger" onclick="deletePost('${post.id}')"><i class="fas fa-trash"></i> ${btnDel}</button>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table>`;
            container.innerHTML = html;

            let pageHtml = '';
            for (let i = 1; i <= totalPages; i++) {
                pageHtml += `<button type="button" class="page-btn ${i === currentPage ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
            }
            paginationContainer.innerHTML = pageHtml;
        }

        window.changePage = function(page) { currentPage = page; renderUI(); };
        window.toggleSelectAll = function(source) { document.querySelectorAll('.post-checkbox').forEach(cb => cb.checked = source.checked); };

        async function fetchGistData() {
            try {
                const response = await fetch(`?action=get_gist_data&t=${new Date().getTime()}`);
                const data = await response.json();
                if (data.status === 'success') {
                    currentPosts = data.posts || [];
                    currentSnapshots = data.snapshots || [];
                } else {
                    showAlert(currentLang === 'tr' ? 'Gist verisi çekilemedi!' : 'Could not fetch Gist data!', false);
                }
                renderUI();
                renderSnapshotsUI();
            } catch (err) { showAlert(currentLang === 'tr' ? 'Gist verisi çekilemedi!' : 'Could not fetch Gist data!', false); }
        }

        async function saveToGithubGist(jsonArray) {
            const editorStatus = document.getElementById('editor-status');
            editorStatus.innerHTML = '<span class="spinner-inline"></span> ' + (currentLang === 'tr' ? 'Gist güncelleniyor...' : 'Updating Gist...');
            try {
                const response = await fetch(`?action=save_gist_data`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        posts: jsonArray,
                        snapshots: currentSnapshots
                    })
                });
                const data = await response.json();
                if (data.status === 'success') {
                    showAlert(currentLang === 'tr' ? 'GitHub Gist güncellendi!' : 'GitHub Gist updated!');
                    editorStatus.textContent = currentLang === 'tr' ? 'Son güncelleme: Başarılı' : 'Last update: Successful';
                } else {
                    showAlert(currentLang === 'tr' ? 'Gist güncellenemedi!' : 'Could not update Gist!', false);
                }
            } catch (err) { showAlert(currentLang === 'tr' ? 'Bağlantı hatası!' : 'Connection error!', false); }
        }

        window.editPost = function(id) {
            const post = currentPosts.find(p => String(p.id) === String(id));
            if (!post) return;

            isEditingState = true;
            editingPostId = post.id;

            document.getElementById('edit-id').value = post.id;
            document.getElementById('add-baslik').value = post.baslik || '';
            document.getElementById('add-ozet').value = post.ozet || '';
            document.getElementById('add-baslik-en').value = post.baslik_en || '';
            document.getElementById('add-ozet-en').value = post.ozet_en || '';
            document.getElementById('add-yazar').value = post.yazar || 'Enbest';
            document.getElementById('add-kategori').value = post.kategori || 'Güncelleme';
            document.getElementById('add-tags').value = post.tags || '';
            document.getElementById('add-seo-desc').value = post.seo_desc || '';

            quillTr.root.innerHTML = post.icerik || '';
            quillEn.root.innerHTML = post.icerik_en || '';
            document.getElementById('prev-content').innerHTML = (currentLang === 'tr' ? post.icerik : post.icerik_en) || (currentLang === 'tr' ? 'Zengin içerik önizlemesi...' : 'Rich text content preview...');

            document.getElementById('form-main-title').textContent = currentLang === 'tr' ? `Logu Düzenle (#${post.id})` : `Edit Devlog (#${post.id})`;
            document.getElementById('form-submit-btn').innerHTML = currentLang === 'tr' ? '<i class="fas fa-save"></i> Değişiklikleri Kaydet' : '<i class="fas fa-save"></i> Save Changes';
            document.getElementById('cancel-edit-btn').style.display = 'inline-block';
            updatePreview();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        document.getElementById('cancel-edit-btn').addEventListener('click', resetForm);

        function resetForm() {
            isEditingState = false;
            editingPostId = null;
            document.getElementById('quick-add-form').reset();
            document.getElementById('edit-id').value = '';
            document.getElementById('form-main-title').textContent = currentLang === 'tr' ? 'Yeni Geliştirici Günlüğü Ekle' : 'Add New Devlog';
            document.getElementById('form-submit-btn').innerHTML = currentLang === 'tr' ? '<i class="fas fa-bolt"></i> Kaydet ve Gönder' : '<i class="fas fa-bolt"></i> Save & Send';
            document.getElementById('cancel-edit-btn').style.display = 'none';
            quillTr.setContents([]); quillEn.setContents([]);
            document.getElementById('prev-content').innerHTML = currentLang === 'tr' ? 'Zengin içerik önizlemesi...' : 'Rich text content preview...';
            updatePreview();
        }

        window.deletePost = function(idToDelete) {
            if (!confirm(currentLang === 'tr' ? `#${idToDelete} ID'li günlüğü silmek istediğinize emin misiniz?` : `Are you sure you want to delete log #${idToDelete}?`)) return;
            currentPosts = currentPosts.filter(p => String(p.id) !== String(idToDelete));
            renumberPosts();
            renderUI();
            saveToGithubGist(currentPosts);
        };

        document.getElementById('batch-delete-btn').addEventListener('click', function() {
            const selected = document.querySelectorAll('.post-checkbox:checked');
            if (selected.length === 0) { alert(currentLang === 'tr' ? 'Lütfen seçim yapın!' : 'Please make a selection!'); return; }
            if (!confirm(currentLang === 'tr' ? `Seçilen ${selected.length} log silinecek?` : `Selected ${selected.length} logs will be deleted?`)) return;
            const ids = Array.from(selected).map(cb => String(cb.value));
            currentPosts = currentPosts.filter(p => !ids.includes(String(p.id)));
            renumberPosts();
            renderUI();
            saveToGithubGist(currentPosts);
        });

        document.getElementById('search-input').addEventListener('input', function(e) {
            const q = e.target.value.toLowerCase().trim();
            currentPage = 1;
            if (!q) { renderUI(currentPosts); return; }
            renderUI(currentPosts.filter(p => p.baslik.toLowerCase().includes(q) || String(p.id).toLowerCase().includes(q)));
        });

        document.getElementById('save-gist-btn').addEventListener('click', function() {
            try {
                currentPosts = JSON.parse(codeMirrorEditor.getValue());
                renderUI();
                saveToGithubGist(currentPosts);
            } catch (err) { showAlert(currentLang === 'tr' ? 'Geçersiz JSON formatı!' : 'Invalid JSON format!', false); }
        });

        document.getElementById('quick-add-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const editId = document.getElementById('edit-id').value;
            const baslik = document.getElementById('add-baslik').value.trim();
            const ozet = document.getElementById('add-ozet').value.trim();
            const icerik = quillTr.root.innerHTML.trim();
            const baslikEn = document.getElementById('add-baslik-en').value.trim();
            const ozetEn = document.getElementById('add-ozet-en').value.trim();
            const icerikEn = quillEn.root.innerHTML.trim();
            const yazar = document.getElementById('add-yazar').value;
            const kategori = document.getElementById('add-kategori').value;
            const tags = document.getElementById('add-tags').value.trim();
            const seoDesc = document.getElementById('add-seo-desc').value.trim();
            const tarih = new Date().toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' });

            if (editId) {
                const idx = currentPosts.findIndex(p => String(p.id) === String(editId));
                if (idx !== -1) {
                    currentPosts[idx].baslik = baslik;
                    currentPosts[idx].ozet = ozet;
                    currentPosts[idx].icerik = icerik;
                    currentPosts[idx].baslik_en = baslikEn;
                    currentPosts[idx].ozet_en = ozetEn;
                    currentPosts[idx].icerik_en = icerikEn;
                    currentPosts[idx].yazar = yazar;
                    currentPosts[idx].kategori = kategori;
                    currentPosts[idx].tags = tags;
                    currentPosts[idx].seo_desc = seoDesc;
                }
            } else {
                let maxId = 0;
                currentPosts.forEach(p => { if (parseInt(p.id) > maxId) maxId = parseInt(p.id); });
                currentPosts.push({
                    id: (maxId + 1).toString(),
                    tarih: tarih,
                    baslik: baslik,
                    ozet: ozet,
                    icerik: icerik,
                    baslik_en: baslikEn,
                    ozet_en: ozetEn,
                    icerik_en: icerikEn,
                    yazar: yazar,
                    kategori: kategori,
                    tags: tags,
                    seo_desc: seoDesc,
                    banner: "img/winterhyacinthLogo.jpg"
                });
            }

            renderUI();
            saveToGithubGist(currentPosts);
            resetForm();
        });
    </script>
</body>
</html>