<?php
session_start();

// Oturum dizisini tamamen sıfırla
$_SESSION = array();

// Çerez varsa tarayıcbanktan sil
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Oturumu sunucu tarafında tamamen yok et
session_destroy();

// Doğrudan ana sayfaya (index.html) yönlendir
header('Location: index.html');
exit();
?>