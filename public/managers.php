<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function managerH(string|int|null $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Nur Administratoren können Verwalterkonten verwalten.');
}
$db = coachingDatabase();
$token = $_SESSION['csrf_token'] ?? '';
if (!is_string($token) || strlen($token) !== 64) { $token = bin2hex(random_bytes(32)); $_SESSION['csrf_token'] = $token; }
$error = '';
$notice = isset($_GET['saved']) ? 'Die Änderungen am Verwalterkonto wurden gespeichert.' : (isset($_GET['created']) ? 'Das Verwalterkonto wurde angelegt.' : (isset($_GET['assigned']) ? 'Die Coach-Zuordnungen wurden gespeichert.' : ''));
$values = ['id'=>0,'vorname'=>'','nachname'=>'','username'=>'','email'=>'','aktiv'=>1];
$editing = false;

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId === false || (int)$editId < 1) $error = 'Ungültige Verwalter-ID.';
    else {
        $q = $db->prepare("SELECT id,vorname,nachname,benutzername,email,aktiv FROM benutzer WHERE id=:id AND rolle='verwalter' LIMIT 1");
        $q->execute(['id'=>(int)$editId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) { $values = ['id'=>(int)$row['id'],'vorname'=>$row['vorname'],'nachname'=>$row['nachname'],'username'=>$row['benutzername'],'email'=>$row['email'],'aktiv'=>(int)$row['aktiv']]; $editing=true; }
        else $error = 'Dieses Verwalterkonto wurde nicht gefunden.';
    }
}

$assigningManagerId = filter_var($_GET['zuweisung'] ?? null, FILTER_VALIDATE_INT);
$assigningManagerId = $assigningManagerId === false ? 0 : (int)$assigningManagerId;
$assigningManager = null;
$assignedCoachIds = [];
if ($assigningManagerId > 0) {
    $q = $db->prepare("SELECT id,vorname,nachname FROM benutzer WHERE id=:id AND rolle='verwalter'");
    $q->execute(['id'=>$assigningManagerId]);
    $assigningManager = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($assigningManager) {
        $q = $db->prepare('SELECT coach_benutzer_id FROM coaching_verwalter_coaches WHERE verwalter_benutzer_id=:id');
        $q->execute(['id'=>$assigningManagerId]);
        $assignedCoachIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_coaches') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $managerId = filter_var($_POST['manager_id'] ?? null, FILTER_VALIDATE_INT);
    $managerId = $managerId === false ? 0 : (int)$managerId;
    $requested = $_POST['coach_ids'] ?? [];
    if (!is_array($requested)) $requested = [];
    $requested = array_values(array_unique(array_filter(array_map(static fn($value) => filter_var($value, FILTER_VALIDATE_INT), $requested), static fn($value) => $value !== false && (int)$value > 0)));
    if (!is_string($postedToken) || !hash_equals($token, $postedToken)) $error = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    else {
        try {
            $managerCheck = $db->prepare("SELECT id FROM benutzer WHERE id=:id AND rolle='verwalter'");
            $managerCheck->execute(['id'=>$managerId]);
            if (!$managerCheck->fetchColumn()) throw new InvalidArgumentException('Dieses Verwalterkonto wurde nicht gefunden.');
            if ($requested) {
                $holders = implode(',', array_fill(0, count($requested), '?'));
                $coachCheck = $db->prepare("SELECT id FROM benutzer WHERE rolle='coach' AND aktiv=1 AND id IN ($holders)");
                $coachCheck->execute($requested);
                if (count($coachCheck->fetchAll(PDO::FETCH_COLUMN)) !== count($requested)) throw new InvalidArgumentException('Mindestens ein ausgewählter Coach ist inaktiv oder ungültig.');
            }
            $db->beginTransaction();
            $delete = $db->prepare('DELETE FROM coaching_verwalter_coaches WHERE verwalter_benutzer_id=:manager');
            $delete->execute(['manager'=>$managerId]);
            $add = $db->prepare('INSERT INTO coaching_verwalter_coaches (verwalter_benutzer_id,coach_benutzer_id,angelegt_von_benutzer_id) VALUES (:manager,:coach,:admin)');
            foreach ($requested as $coachId) $add->execute(['manager'=>$managerId,'coach'=>(int)$coachId,'admin'=>(int)$user['id']]);
            $audit = $db->prepare("INSERT INTO coaching_admin_aktionen (admin_benutzer_id,aktion,ziel_typ,ziel_id,details) VALUES (:admin,'verwalter_coaches_zugewiesen','verwalter',:target,:details)");
            $audit->execute(['admin'=>(int)$user['id'],'target'=>$managerId,'details'=>count($requested).' Coach-Zuordnung(en) gesetzt']);
            $db->commit();
            header('Location: managers.php?assigned=1'); exit;
        } catch (InvalidArgumentException $e) { if ($db->inTransaction()) $db->rollBack(); $error=$e->getMessage(); }
        catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log('Manager coach assignment failed: '.$e->getMessage()); $error='Die Coach-Zuordnung konnte nicht gespeichert werden. Prüfe Migration 022 und Tabellenrechte.'; }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
    $id = $id === false ? -1 : (int)$id;
    $first = trim(is_string($_POST['vorname'] ?? null) ? $_POST['vorname'] : '');
    $last = trim(is_string($_POST['nachname'] ?? null) ? $_POST['nachname'] : '');
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $active = isset($_POST['aktiv']) && $_POST['aktiv'] === '1' ? 1 : 0;
    $values = ['id'=>max(0,$id),'vorname'=>$first,'nachname'=>$last,'username'=>$username,'email'=>$email,'aktiv'=>$id>0?$active:1];
    $editing = $id > 0;
    $passwordValid = strlen($password) >= 8 && strlen($password) <= 4096 && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    if (!is_string($postedToken) || !hash_equals($token, $postedToken)) $error = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
    elseif ($id < 0 || $first === '' || strlen($first)>100 || $last === '' || strlen($last)>100 || $username === '' || strlen($username)>50 || $email === '' || strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || (!$editing && !$passwordValid) || ($editing && $password!=='' && !$passwordValid)) $error = 'Bitte prüfe die Angaben. Ein neues Passwort braucht mindestens 8 Zeichen und ein Sonderzeichen.';
    else {
        try {
            if ($editing) {
                $exists=$db->prepare("SELECT id FROM benutzer WHERE id=:id AND rolle='verwalter'");
                $exists->execute(['id'=>$id]);
                if (!$exists->fetchColumn()) throw new InvalidArgumentException('Dieses Verwalterkonto wurde nicht gefunden.');
                $set=['vorname=:first','nachname=:last','benutzername=:username','email=:email','aktiv=:active'];
                $params=['first'=>$first,'last'=>$last,'username'=>$username,'email'=>$email,'active'=>$active,'id'=>$id];
                if ($password!=='') { $set[]='passwort_hash=:hash'; $params['hash']=password_hash($password,PASSWORD_DEFAULT); }
                $update=$db->prepare('UPDATE benutzer SET '.implode(', ',$set).' WHERE id=:id');
                $update->execute($params);
                header('Location: managers.php?saved=1'); exit;
            }
            $insert=$db->prepare("INSERT INTO benutzer (benutzername,email,passwort_hash,vorname,nachname,rolle,aktiv) VALUES (:username,:email,:hash,:first,:last,'verwalter',1)");
            $insert->execute(['username'=>$username,'email'=>$email,'hash'=>password_hash($password,PASSWORD_DEFAULT),'first'=>$first,'last'=>$last]);
            header('Location: managers.php?created=1'); exit;
        } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
        catch (Throwable $e) { error_log('Manager account save failed: '.$e->getMessage()); $error='Das Konto konnte nicht gespeichert werden. Prüfe Migration 007 sowie doppelte Benutzernamen/E-Mail-Adressen.'; }
    }
}
try {
    $managers=$db->query("SELECT id,vorname,nachname,benutzername,email,aktiv FROM benutzer WHERE rolle='verwalter' ORDER BY aktiv DESC,nachname,vorname")->fetchAll(PDO::FETCH_ASSOC);
    $coachAccounts=$db->query("SELECT id,vorname,nachname,coach_kennung FROM benutzer WHERE rolle='coach' AND aktiv=1 ORDER BY nachname,vorname")->fetchAll(PDO::FETCH_ASSOC);
    $assignmentRows=$db->query('SELECT m.verwalter_benutzer_id,m.coach_benutzer_id,b.vorname,b.nachname FROM coaching_verwalter_coaches m INNER JOIN benutzer b ON b.id=m.coach_benutzer_id ORDER BY b.nachname,b.vorname')->fetchAll(PDO::FETCH_ASSOC);
    $assignmentsByManager=[];foreach($assignmentRows as $assignment)$assignmentsByManager[(int)$assignment['verwalter_benutzer_id']][]=$assignment['vorname'].' '.$assignment['nachname'];
} catch (Throwable $e) { error_log('Manager list failed: '.$e->getMessage()); $managers=[]; $coachAccounts=[]; $assignmentsByManager=[]; $error='Konten konnten nicht geladen werden. Bitte zuerst Migration 007 und Migration 022 importieren und Tabellenrechte prüfen.'; }
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verwalterkonten · Coaching Tool</title><link rel="stylesheet" href="assets/layout.css"><script src="assets/layout.js" defer></script><style>.page{max-width:1050px;margin:auto;padding:34px clamp(24px,4vw,58px) 62px}.grid{display:grid;grid-template-columns:minmax(280px,390px) 1fr;gap:18px;margin-top:24px}.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:20px}.form{display:grid;gap:10px}.form input{width:100%;padding:11px;border:1px solid #dce5df;border-radius:8px;font:inherit}.button{display:inline-block;border:0;border-radius:8px;padding:11px 15px;background:#245c50;color:#fff;font:inherit;text-decoration:none}.secondary{background:#edf5ef;color:#245c50}.notice,.error{padding:12px;border-radius:8px}.notice{background:#edf5ef}.error{background:#fff3f1}.coach-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;margin:14px 0}.coach-list label{font-size:14px}.row{padding:12px 0;border-bottom:1px solid #edf1ee;display:flex;justify-content:space-between;align-items:center;gap:12px}.muted{color:#718078;font-size:13px}.status{border-radius:999px;background:#edf5ef;padding:4px 8px;font-size:12px}.status.off{background:#f3eeee;color:#765c5c}@media(max-width:800px){.grid{grid-template-columns:1fr}.row{align-items:flex-start;flex-direction:column}}</style></head><body class="has-app-sidebar"><?php require_once dirname(__DIR__).'/app/navigation.php';renderCoachingSidebar($user,'managers',$token);?><main class="page"><h1>Verwalterkonten</h1><p>Weise jedem Verwalter die Coaches zu, für deren Klientenzuordnung er verantwortlich ist. Ein Verwalter und ein Coach können mehreren Zuordnungen angehören.</p><?php if($notice):?><p class="notice" role="status"><?=managerH($notice)?></p><?php endif;?><?php if($error):?><p class="error" role="alert"><?=managerH($error)?></p><?php endif;?><div class="grid"><section class="card"><h2><?=$editing?'Verwalter bearbeiten':'Verwalter hinzufügen'?></h2><form class="form" method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=managerH($token)?>"><input type="hidden" name="id" value="<?=managerH($values['id'])?>"><label>Vorname<input name="vorname" maxlength="100" required value="<?=managerH($values['vorname'])?>"></label><label>Nachname<input name="nachname" maxlength="100" required value="<?=managerH($values['nachname'])?>"></label><label>Benutzername<input name="username" maxlength="50" required value="<?=managerH($values['username'])?>"></label><label>E-Mail<input name="email" type="email" maxlength="190" required value="<?=managerH($values['email'])?>"></label><label>Neues Passwort<?=$editing?' (optional)':''?><input name="password" type="password" minlength="8" <?=$editing?'':'required'?> autocomplete="new-password"></label><?php if($editing):?><label><input type="checkbox" name="aktiv" value="1" <?=$values['aktiv']===1?'checked':''?>> Konto aktiv</label><?php endif;?><button class="button" type="submit"><?=$editing?'Änderungen speichern':'Verwalterkonto anlegen'?></button><?php if($editing):?> <a class="button secondary" href="managers.php">Abbrechen</a><?php endif;?></form></section><section class="card"><h2>Bestehende Konten</h2><?php if(!$managers):?><p class="muted">Noch kein Verwalterkonto angelegt.</p><?php else:foreach($managers as $m):?><div class="row"><div><strong><?=managerH($m['vorname'].' '.$m['nachname'])?></strong><div class="muted"><?=managerH($m['benutzername'])?> · <?=managerH($m['email'])?></div><div class="muted">Coaches: <?=managerH(implode(', ', $assignmentsByManager[(int)$m['id']] ?? [])) ?: 'Keine zugewiesen'?></div></div><div><a href="managers.php?zuweisung=<?=(int)$m['id']?>">Coaches zuweisen</a> · <span class="status <?=((int)$m['aktiv']===1?'':'off')?>"><?=((int)$m['aktiv']===1?'Aktiv':'Inaktiv')?></span> · <a href="managers.php?edit=<?=(int)$m['id']?>">Bearbeiten</a></div></div><?php endforeach;endif;?></section></div>
<?php if($assigningManager):?><section class="card" style="margin-top:20px"><h2>Coaches zuweisen · <?=managerH($assigningManager['vorname'].' '.$assigningManager['nachname'])?></h2><p class="muted">Der Verwalter sieht und verwaltet nur die Klienten der ausgewählten Coaches. Ohne Zuordnung kann er keine Klienten zuordnen.</p><form class="form" method="post"><input type="hidden" name="csrf_token" value="<?=managerH($token)?>"><input type="hidden" name="action" value="assign_coaches"><input type="hidden" name="manager_id" value="<?=(int)$assigningManager['id']?>"><div class="coach-list"><?php foreach($coachAccounts as $coach):?><label><input type="checkbox" name="coach_ids[]" value="<?=(int)$coach['id']?>" <?=in_array((int)$coach['id'],$assignedCoachIds,true)?'checked':''?>> <?=managerH($coach['vorname'].' '.$coach['nachname'])?><?=!empty($coach['coach_kennung'])?' · '.managerH($coach['coach_kennung']):''?></label><?php endforeach;?></div><button class="button" type="submit">Zuordnungen speichern</button><a class="button secondary" href="managers.php">Abbrechen</a></form></section><?php endif;?>
</main></body></html>
