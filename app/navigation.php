<?php
declare(strict_types=1);

function coachingNavEscape(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderCoachingSidebar(array $user, string $active, string $token): void
{
    $role = (string) ($user['role'] ?? '');
    $isAdmin = $role === 'admin';
    $isManager = $role === 'verwalter';
    $roleLabel = $isAdmin ? 'Administrator' : ($isManager ? 'Verwalter' : 'Coach');
    $clientContextId=filter_input(INPUT_GET,'klient_id',FILTER_VALIDATE_INT);$clientContextId=$clientContextId===false||$clientContextId===null?0:(int)$clientContextId;if($clientContextId<1&&$active==='clients'&&basename((string)($_SERVER['SCRIPT_NAME']??''))==='client-overview.php'){$currentClientId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$clientContextId=$currentClientId===false||$currentClientId===null?0:(int)$currentClientId;}$clientContextSuffix=$clientContextId>0?'?klient_id='.$clientContextId:'';
    $isClientOverview=basename((string)($_SERVER['SCRIPT_NAME']??''))==='client-overview.php';
    $isClientSelector=$active==='clients'&&!$isClientOverview;
    $clientContextName='';
    if($role==='coach'&&$clientContextId>0){try{$clientNameQuery=coachingDatabase()->prepare('SELECT vorname,nachname FROM klienten WHERE id=:id AND benutzer_id=:coach LIMIT 1');$clientNameQuery->execute(['id'=>$clientContextId,'coach'=>(int)$user['id']]);$clientNameRow=$clientNameQuery->fetch(PDO::FETCH_ASSOC);if($clientNameRow)$clientContextName=trim($clientNameRow['vorname'].' '.$clientNameRow['nachname']);else{$clientContextId=0;$clientContextSuffix='';}}catch(Throwable $e){$clientContextId=0;$clientContextSuffix='';}}
    $clientOverviewHref=$clientContextId>0?'client-overview.php?id='.$clientContextId:'clients.php';
    $clientDocumentsHref=$clientContextId>0?'client-overview.php?id='.$clientContextId.'#documents':'clients.php';
    $displayName = (string) ($user['name'] ?? 'Benutzer');
    $initial = preg_match('/^./u', $displayName, $initialMatch) === 1 ? $initialMatch[0] : 'U';
    $coachingOpen = in_array($active, ['clients', 'appointments', 'goals', 'tasks', 'engagements', 'notes', 'invoices'], true);
    $settingsOpen = in_array($active, ['invoice-settings', 'email-settings', 'design', 'documents', 'calendar-settings', 'coaches', 'managers', 'format-categories'], true);
    require_once dirname(__DIR__) . '/app/theme.php';
    $themeDatabase = coachingDatabase();
    echo '<style>' . coachingThemeCss($themeDatabase, (int) $user['id']) . '</style>';
    ?>
    <aside class="app-sidebar" id="app-sidebar">
        <div class="sidebar-brand-row">
            <a class="sidebar-brand" href="./" aria-label="Coaching Tool Übersicht">
                <span class="sidebar-brand-mark" aria-hidden="true">C</span>
                <span>Coaching<span class="sidebar-brand-light">Tool</span></span>
            </a>
            <button class="sidebar-toggle" type="button" aria-controls="sidebar-navigation" aria-expanded="false">
                <span class="sidebar-toggle-icon" aria-hidden="true">☰</span><span>Menü</span>
            </button>
        </div>
        <div class="sidebar-navigation" id="sidebar-navigation">
            <p class="sidebar-caption">ARBEITSBEREICH</p>
            <nav aria-label="Hauptmenü">
                <a class="sidebar-link<?= $active === 'dashboard' ? ' is-active' : '' ?>" href="./"<?= $active === 'dashboard' ? ' aria-current="page"' : '' ?>>
                    <span class="sidebar-link-icon" aria-hidden="true">⌂</span><span>Übersicht</span>
                </a>
                <details class="sidebar-group"<?= $coachingOpen ? ' open' : '' ?>>
                    <summary><span class="sidebar-link-icon" aria-hidden="true">◇</span><span>Coaching</span></summary>
                    <div class="sidebar-submenu">
                        <a class="sidebar-link<?= $isClientSelector ? ' is-active' : '' ?>" href="clients.php"<?= $isClientSelector ? ' aria-current="page"' : '' ?>>Klient anlegen / auswählen</a>
                        <?php if ($role === 'coach' && $clientContextId > 0): ?>
                        <a class="sidebar-link<?= $isClientOverview ? ' is-active' : '' ?>" href="<?= coachingNavEscape($clientOverviewHref) ?>"<?= $isClientOverview ? ' aria-current="page"' : '' ?>>Klientenakte · <?= coachingNavEscape($clientContextName) ?></a>
                        <div class="sidebar-submenu">
                            <a class="sidebar-link<?= $active === 'appointments' ? ' is-active' : '' ?>" href="appointments.php<?= $clientContextSuffix ?>"<?= $active === 'appointments' ? ' aria-current="page"' : '' ?>>Termine</a>
                            <a class="sidebar-link<?= $active === 'tasks' ? ' is-active' : '' ?>" href="tasks.php<?= $clientContextSuffix ?>"<?= $active === 'tasks' ? ' aria-current="page"' : '' ?>>Aufgaben</a>
                            <a class="sidebar-link<?= $active === 'engagements' ? ' is-active' : '' ?>" href="engagements.php<?= $clientContextSuffix ?>"<?= $active === 'engagements' ? ' aria-current="page"' : '' ?>>Aufträge</a>
                            <a class="sidebar-link<?= $active === 'goals' ? ' is-active' : '' ?>" href="goals.php<?= $clientContextSuffix ?>"<?= $active === 'goals' ? ' aria-current="page"' : '' ?>>Ziele</a>
                            <a class="sidebar-link<?= $active === 'documents' ? ' is-active' : '' ?>" href="<?= coachingNavEscape($clientDocumentsHref) ?>"<?= $active === 'documents' ? ' aria-current="page"' : '' ?>>Dokumente verwalten</a>
                            <a class="sidebar-link<?= $active === 'notes' ? ' is-active' : '' ?>" href="notes.php<?= $clientContextSuffix ?>"<?= $active === 'notes' ? ' aria-current="page"' : '' ?>>Private Sitzungsnotizen</a>
                            <a class="sidebar-link<?= $active === 'invoices' ? ' is-active' : '' ?>" href="invoices.php<?= $clientContextSuffix ?>"<?= $active === 'invoices' ? ' aria-current="page"' : '' ?>>Rechnung</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </details>
                <?php if ($role === 'coach'): ?>
                <a class="sidebar-link<?= $active === 'formats' ? ' is-active' : '' ?>" href="formats.php"<?= $active === 'formats' ? ' aria-current="page"' : '' ?>><span class="sidebar-link-icon" aria-hidden="true">▣</span><span>Wissensdatenbank</span></a>
                <?php endif; ?>
                <details class="sidebar-group"<?= $settingsOpen ? ' open' : '' ?>>
                    <summary><span class="sidebar-link-icon" aria-hidden="true">⚙</span><span>Einstellungen</span></summary>
                    <div class="sidebar-submenu">
                        <?php if ($role === 'coach'): ?>
                        <a class="sidebar-link<?= $active === 'invoice-settings' ? ' is-active' : '' ?>" href="invoice-settings.php"<?= $active === 'invoice-settings' ? ' aria-current="page"' : '' ?>>Rechnungseinstellungen</a>
                        <?php endif; ?>
                        <a class="sidebar-link<?= $active === 'email-settings' ? ' is-active' : '' ?>" href="email-settings.php"<?= $active === 'email-settings' ? ' aria-current="page"' : '' ?>>E-Mail & Passwort-Reset</a>
                        <a class="sidebar-link<?= $active === 'design' ? ' is-active' : '' ?>" href="design-settings.php"<?= $active === 'design' ? ' aria-current="page"' : '' ?>>Schrift und Farben</a>
                        <?php if ($role === 'coach'): ?>
                        <a class="sidebar-link<?= $active === 'calendar-settings' ? ' is-active' : '' ?>" href="calendar-settings.php"<?= $active === 'calendar-settings' ? ' aria-current="page"' : '' ?>>Kalender</a>
                        <a class="sidebar-link<?= $active === 'documents' ? ' is-active' : '' ?>" href="documents.php"<?= $active === 'documents' ? ' aria-current="page"' : '' ?>>Dokumente</a>
                        <?php endif; ?>
                        <?php if ($isAdmin || $isManager): ?>
                        <hr class="sidebar-divider"><span class="sidebar-section-label">Verwaltung</span>
                        <?php if ($isAdmin): ?>
                        <a class="sidebar-link<?= $active === 'coaches' ? ' is-active' : '' ?>" href="coaches.php"<?= $active === 'coaches' ? ' aria-current="page"' : '' ?>>Coach-Konten</a>
                        <a class="sidebar-link<?= $active === 'managers' ? ' is-active' : '' ?>" href="managers.php"<?= $active === 'managers' ? ' aria-current="page"' : '' ?>>Verwalterkonten</a>
                        <?php endif; ?>
                        <a class="sidebar-link<?= $active === 'format-categories' ? ' is-active' : '' ?>" href="format-categories.php"<?= $active === 'format-categories' ? ' aria-current="page"' : '' ?>>Wissenskategorien</a>
                        <?php endif; ?>
                    </div>
                </details>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <span class="sidebar-user-mark" aria-hidden="true"><?= coachingNavEscape($initial) ?></span>
                    <span class="sidebar-user-copy"><strong><?= coachingNavEscape($user['name'] ?? 'Benutzer') ?></strong><small><?= coachingNavEscape($roleLabel) ?></small></span>
                </div>
                <form method="post" action="./" class="sidebar-logout">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?= coachingNavEscape($token) ?>">
                    <button type="submit">Abmelden</button>
                </form>
            </div>
        </div>
    </aside>
    <?php
}
