<?php
declare(strict_types=1);
function coachingThemeCss(PDO $database, int $userId): string
{
 $s=['schriftart'=>'system','schriftgroesse'=>16,'farbe_hintergrund'=>'#f5f7f5','farbe_flaeche'=>'#ffffff','farbe_text'=>'#1f302d','farbe_akzent'=>'#245c50','farbe_menue'=>'#173a33'];
 try{$q=$database->prepare('SELECT * FROM coaching_design_einstellungen WHERE benutzer_id=:id');$q->execute(['id'=>$userId]);$s=array_merge($s,$q->fetch(PDO::FETCH_ASSOC)?:[]);}catch(Throwable $e){}
 $fonts=['system'=>'system-ui,-apple-system,"Segoe UI",sans-serif','arial'=>'Arial,Helvetica,sans-serif','verdana'=>'Verdana,Geneva,sans-serif','georgia'=>'Georgia,serif','trebuchet'=>'"Trebuchet MS",sans-serif'];
 $font=$fonts[$s['schriftart']]??$fonts['system'];$size=max(14,min(20,(int)$s['schriftgroesse']));
 $valid=static fn(string $v,string $fallback):string=>preg_match('/^#[0-9A-Fa-f]{6}$/',$v)?$v:$fallback;
 $bg=$valid((string)$s['farbe_hintergrund'],'#f5f7f5');$surface=$valid((string)$s['farbe_flaeche'],'#ffffff');$text=$valid((string)$s['farbe_text'],'#1f302d');$accent=$valid((string)$s['farbe_akzent'],'#245c50');$menu=$valid((string)$s['farbe_menue'],'#173a33');
 $contrast=static function(string $hex):string{$h=ltrim($hex,'#');$rgb=[hexdec(substr($h,0,2)),hexdec(substr($h,2,2)),hexdec(substr($h,4,2))];$lum=0;foreach($rgb as $i=>$v){$v/=255;$lum+=($v<=.03928?$v/12.92:pow(($v+.055)/1.055,2.4))*[.2126,.7152,.0722][$i];}return $lum>.42?'#17211d':'#ffffff';};
 $menuText=$contrast($menu);$accentText=$contrast($accent);
 return ':root{--theme-bg:'.$bg.';--theme-surface:'.$surface.';--theme-text:'.$text.';--theme-accent:'.$accent.';--theme-font:'.$font.';--theme-size:'.$size.'px;--nav-bg:'.$menu.';--nav-text:'.$menuText.';--nav-active:'.$accent.';--nav-accent:'.$accent.';--nav-muted:'.$menuText.';--nav-action-text:'.$accentText.'}'.
 'body.has-app-sidebar,body.portal{font-family:var(--theme-font)!important;font-size:var(--theme-size)!important;background:var(--theme-bg)!important;color:var(--theme-text)!important}'.
 'body.has-app-sidebar .page,body.has-app-sidebar .shell,body.portal .portal-wrap{font-family:var(--theme-font)!important;font-size:var(--theme-size)!important;color:var(--theme-text)!important}'.
 'body.has-app-sidebar .page h1,body.portal h1{font-size:2.1em!important}'.
 'body.has-app-sidebar .card,body.has-app-sidebar .overview-stat,body.has-app-sidebar .overview-card,body.portal .card,body.portal .portal-head{background:var(--theme-surface)!important;color:var(--theme-text)!important}'.
 'body.has-app-sidebar .button,body.has-app-sidebar .btn:not(.light),body.has-app-sidebar .overview-button:not(.secondary),body.portal .button{background:var(--theme-accent)!important;color:var(--nav-action-text)!important}'.
 'body.has-app-sidebar input,body.has-app-sidebar select,body.has-app-sidebar textarea{font-family:var(--theme-font)!important;font-size:var(--theme-size)!important}'.
 '.app-sidebar{background:var(--nav-bg)!important;color:var(--nav-text)!important}.sidebar-link.is-active,.sidebar-submenu .sidebar-link.is-active{background:var(--nav-active)!important;color:var(--nav-action-text)!important}.sidebar-link.is-active .sidebar-link-icon{color:var(--nav-action-text)!important}';
}