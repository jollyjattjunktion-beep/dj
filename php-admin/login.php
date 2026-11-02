<?php
require __DIR__.'/app/bootstrap.php';
if(!empty($_SESSION['admin_id'])){header('Location: /admin.php');exit;}

// A fresh SQLite database has no admins table until install.php is completed.
try {
    db()->query('SELECT id FROM admins LIMIT 1');
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'no such table: admins')) {
        header('Location: /install.php');
        exit;
    }
    throw $e;
}

$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    // Validate same-origin form submission. Some mobile browsers/proxies omit
    // the CSRF cookie on the first POST even though the login page just set it.
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $sameOrigin = false;
    foreach ([$origin, $referer] as $candidate) {
        if ($candidate !== '') {
            $parts = parse_url($candidate);
            if (strtolower((string)($parts['host'] ?? '')) === $host
                && in_array(strtolower((string)($parts['scheme'] ?? '')), ['https','http'], true)) {
                $sameOrigin = true;
                break;
            }
        }
    }
    $posted = (string)($_POST['_csrf'] ?? '');
    $cookie = (string)($_COOKIE['jattbeat_csrf'] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    $tokenValid = ($cookie !== '' && hash_equals($cookie, $posted))
        || ($sessionToken !== '' && hash_equals($sessionToken, $posted));
    if (!$tokenValid && !$sameOrigin) {
        http_response_code(419);
        exit('Login security check failed. Please reopen the login page and try again.');
    }
    $q=db()->prepare('SELECT * FROM admins WHERE username=?');
    $q->execute([trim($_POST['username']??'')]);
    $a=$q->fetch();
    if($a&&password_verify($_POST['password']??'',$a['password_hash'])){
        session_regenerate_id(true);
        $_SESSION['admin_id']=$a['id'];
        $_SESSION['admin_name']=$a['username'];
        header('Location: /admin.php');
        exit;
    }
    $err='Invalid username or password.';
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin login</title><link rel="stylesheet" href="/assets/style.css"></head><body class="auth"><form class="panel authbox" method="post"><h1>Admin login</h1><?php if($err):?><p class="error"><?=e($err)?></p><?php endif?><input type="hidden" name="_csrf" value="<?=e(csrf())?>"><label>Username<input name="username" required></label><label>Password<input type="password" name="password" required></label><button>Sign in</button></form></body></html>