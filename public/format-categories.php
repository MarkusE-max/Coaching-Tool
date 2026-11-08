<?php
declare(strict_types=1);
$isHttps=isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!==''&&$_SERVER['HTTPS']!=='off';
ini_set('session.use_strict_mode','1');
session_name('coaching_tool_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']);
session_start();
header('Cache-Control: no-store, private');
require_once dirname(__DIR__).'/app/bootstrap.php';

function categoryH(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$user=$_SESSION['user']??null;
if(!is_array($user)||!in_array(($user['role']??''),['admin','verwalter'],true)){
    http_response_code(403);
    exit('Die Kategorienverwaltung ist Verwaltern und Administratoren vorbehalten.');
}
$db=coachingDatabase();
$userId=(int)($user['id']??0);
$token=$_SESSION['csrf_token']??'';
if(!is_string($token)||strlen($token)!==64){
    $token=bin2hex(random_bytes(32));
    $_SESSION['csrf_token']=$token;
}
$error='';
$notice=isset($_GET['saved'])?'Änderungen gespeichert.':'';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($token,(string)($_POST['csrf_token']??''))){
        http_response_code(400);
        exit('Ungültige Anfrage.');
    }
    $action=(string)($_POST['action']??'');
    $name=trim((string)($_POST['name']??''));
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    try{
        if($action==='create'){
            if($name===''||mb_strlen($name,'UTF-8')>100){
                throw new RuntimeException('Bitte gib einen Kategorienamen mit höchstens 100 Zeichen ein.');
            }
            $insert=$db->prepare('INSERT INTO coaching_format_kategorien (name) VALUES (:name)');
            $insert->execute(['name'=>$name]);
            header('Location: format-categories.php?saved=1');
            exit;
        }
        if($action==='rename'){
            if($id===false||$id<1||$name===''||mb_strlen($name,'UTF-8')>100){
                throw new RuntimeException('Bitte prüfe den Kategorienamen.');
            }
            $update=$db->prepare('UPDATE coaching_format_kategorien SET name=:name WHERE id=:id');
            $update->execute(['name'=>$name,'id'=>$id]);
            if($update->rowCount()===0){
                $exists=$db->prepare('SELECT id FROM coaching_format_kategorien WHERE id=:id');
                $exists->execute(['id'=>$id]);
                if(!$exists->fetchColumn()) throw new RuntimeException('Kategorie nicht gefunden.');
            }
            header('Location: format-categories.php?saved=1');
            exit;
        }
        if($action==='delete'){
            if($id===false||$id<1) throw new RuntimeException('Kategorie nicht gefunden.');
            $count=$db->prepare('SELECT COUNT(*) FROM coaching_formate WHERE kategorie_id=:id');
            $count->execute(['id'=>$id]);
            if((int)$count->fetchColumn()>0){
                throw new RuntimeException('Diese Kategorie wird noch von Wissenseinträgen verwendet. Benenne sie um oder ordne die Einträge zuerst einer anderen Kategorie zu.');
            }
            $delete=$db->prepare('DELETE FROM coaching_format_kategorien WHERE id=:id');
            $delete->execute(['id'=>$id]);
            if($delete->rowCount()!==1) throw new RuntimeException('Kategorie nicht gefunden.');
            header('Location: format-categories.php?saved=1');
            exit;
        }
        throw new RuntimeException('Unbekannte Aktion.');
    }catch(RuntimeException $e){
        $error=$e->getMessage();
    }catch(PDOException $e){
        $error=(string)$e->getCode()==='23000'
            ?'Eine Kategorie mit diesem Namen gibt es bereits.'
            :'Die Änderung konnte nicht gespeichert werden. Prüfe Migration 009 und die Tabellenrechte.';
        error_log('Knowledge category save failed: '.$e->getMessage());
    }
}

$query=$db->query('SELECT k.id,k.name,COUNT(f.id) AS verwendungen FROM coaching_format_kategorien k LEFT JOIN coaching_formate f ON f.kategorie_id=k.id GROUP BY k.id,k.name ORDER BY k.name');
$categories=$query->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kategorien verwalten · Coaching Tool</title>
<link rel="stylesheet" href="assets/layout.css">
<style>
.page{max-width:1120px;margin:auto;padding:34px clamp(24px,4vw,58px) 62px}
.card{background:#fff;border:1px solid #e4ebe6;border-radius:14px;padding:22px;margin:18px 0}
.form-row{display:flex;gap:10px;align-items:center}.form-row input{flex:1;min-width:0;padding:11px 13px;border:1px solid #dce5df;border-radius:8px;font:inherit}
.button{display:inline-flex;align-items:center;justify-content:center;background:#245c50;color:#fff;border:0;border-radius:8px;padding:10px 14px;font:inherit;text-decoration:none;cursor:pointer}
.button.secondary{background:#edf5ef;color:#245c50}.button.danger{background:#fff1ef;color:#963a30}
.notice,.error{padding:12px;border-radius:8px}.notice{background:#edf5ef}.error{background:#fff3f1}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #e4ebe6;vertical-align:middle}th{color:#64746c;font-size:.9rem}
.actions{display:flex;gap:8px;align-items:center}.actions input{min-width:160px;padding:9px;border:1px solid #dce5df;border-radius:8px;font:inherit}
.usage{color:#6d7b74}.help{color:#6d7b74;line-height:1.5}
@media(max-width:700px){.form-row{align-items:stretch;flex-direction:column}.form-row .button{width:100%}.actions{align-items:stretch;flex-direction:column}.actions input{min-width:0;width:100%}.actions .button{width:100%}}
</style>
</head>
<body class="has-app-sidebar">
<?php require_once dirname(__DIR__).'/app/navigation.php';renderCoachingSidebar($user,'format-categories',$token);?>
<main class="page">
<h1>Kategorien der Wissensdatenbank</h1>
<p>Diese Kategorien stehen allen Coaches bei neuen Wissenseinträgen zur Verfügung. Eine Umbenennung gilt auch für die bereits zugeordneten Einträge.</p>
<?php if($notice):?><p class="notice"><?=categoryH($notice)?></p><?php endif;?>
<?php if($error!==''):?><p class="error"><?=categoryH($error)?></p><?php endif;?>
<section class="card">
<h2>Neue Kategorie</h2>
<form method="post" class="form-row">
<input type="hidden" name="csrf_token" value="<?=categoryH($token)?>">
<input type="hidden" name="action" value="create">
<label for="new-category" class="sr-only">Name der neuen Kategorie</label>
<input id="new-category" name="name" maxlength="100" required placeholder="z. B. Positive Psychologie oder EMDR">
<button class="button" type="submit">Kategorie hinzufügen</button>
</form>
</section>
<section class="card">
<h2>Vorhandene Kategorien (<?=count($categories)?>)</h2>
<p class="help">Kategorien mit verknüpften Einträgen können nicht gelöscht werden. Du kannst sie umbenennen, ohne die Einträge zu verlieren.</p>
<?php if(!$categories):?>
<p>Noch keine Kategorien vorhanden.</p>
<?php else:?>
<div class="table-wrap"><table>
<thead><tr><th>Kategorie</th><th>Einträge</th><th>Aktionen</th></tr></thead>
<tbody>
<?php foreach($categories as $category):?>
<tr>
<td><?=categoryH($category['name'])?></td>
<td class="usage"><?=(int)$category['verwendungen']?></td>
<td><div class="actions">
<form method="post" class="actions">
<input type="hidden" name="csrf_token" value="<?=categoryH($token)?>">
<input type="hidden" name="action" value="rename">
<input type="hidden" name="id" value="<?=(int)$category['id']?>">
<label class="sr-only" for="category-<?=(int)$category['id']?>">Kategorie umbenennen</label>
<input id="category-<?=(int)$category['id']?>" name="name" maxlength="100" required value="<?=categoryH($category['name'])?>">
<button class="button secondary" type="submit">Umbenennen</button>
</form>
<form method="post" onsubmit="return confirm('Kategorie wirklich löschen?');">
<input type="hidden" name="csrf_token" value="<?=categoryH($token)?>">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id" value="<?=(int)$category['id']?>">
<button class="button danger" type="submit" <?=$category['verwendungen']>0?'disabled title="Wird noch verwendet"':''?>>Löschen</button>
</form>
</div></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
<p><a class="button secondary" href="formats.php">Zur Wissensdatenbank</a></p>
</section>
</main>
</body>
</html>
