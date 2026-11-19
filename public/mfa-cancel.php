<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';ini_set('session.use_strict_mode','1');session_name('coaching_tool_session');session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);session_start();header('Cache-Control: no-store, private');
unset($_SESSION['mfa_pending_user'],$_SESSION['mfa_setup_secret'],$_SESSION['mfa_setup_authorized'],$_SESSION['csrf_token']);session_regenerate_id(true);header('Location: ./');exit;
