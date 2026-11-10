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
    $displayName = (string) ($user['name'] ?? 'Benutzer');
    $initial = preg_match('/^./u', $displayName, $initialMatch) === 1 ? $initialMatch[0] : 'U';
    $coachingOpen = in_array($active, ['clients', 'appointments', 'goals', 'tasks', 'engagements'], true);
    $settingsOpen = in_array($active, ['invoice-settings', 'email-settings', 'design', 'documents'], true);
    $knowledgeOpen = in_array($active, ['formats', 'format-categories'], true);
    $adminOpen = in_array($active, ['coaches', 'managers', 'format-categories'], true);
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
                        <a class="sidebar-link<?= $active === 'clients' ? ' is-active' : '' ?>" href="clients.php"<?= $active === 'clients' ? ' aria-current="page"' : '' ?>>Klienten</a>
                        <?php if (!$isManager): ?>
                        <a class="sidebar-link<?= $active === 'appointments' ? ' is-active' : '' ?>" href="appointments.php"<?= $active === 'appointments' ? ' aria-current="page"' : '' ?>>Termine</a>
                        <a class="sidebar-link<?= $active === 'goals' ? ' is-active' : '' ?>" href="goals.php"<?= $active === 'goals' ? ' aria-current="page"' : '' ?>>Ziele</a>
                        <?php endif; ?>
                        <?php if ($role === 'coach'): ?><a class="sidebar-link<?= $active === 'tasks' ? ' is-active' : '' ?>" href="tasks.php"<?= $active === 'tasks' ? ' aria-current="page"' : '' ?>>Aufgaben</a><a class="sidebar-link<?= $active === 'engagements' ? ' is-active' : '' ?>" href="engagements.php"<?= $active === 'engagements' ? ' aria-current="page"' : '' ?>>Coaching-Aufträge</a><?php endif; ?>
                    </div>
                </details>
                <?php if ($role === 'coach'): ?>
                <details class="sidebar-group"<?= in_array($active, ['notes'], true) ? ' open' : '' ?>>
                    <summary><span class="sidebar-link-icon" aria-hidden="true">▤</span><span>Dokumentation</span></summary>
                    <div class="sidebar-submenu">
                        <a class="sidebar-link<?= $active === 'notes' ? ' is-active' : '' ?>" href="notes.php"<?= $active === 'notes' ? ' aria-current="page"' : '' ?>>Private Sitzungsnotizen</a>
                    </div>
                </details>
                <?php endif; ?>
                <?php if ($role === 'coach'): ?>
                <a class="sidebar-link<?= $active === 'invoices' ? ' is-active' : '' ?>" href="invoices.php"<?= $active === 'invoices' ? ' aria-current="page"' : '' ?>><span class="sidebar-link-icon" aria-hidden="true">€</span><span>Rechnungen</span></a>
                <?php endif; ?>
                <?php if ($role === 'coach'): ?>
                <details class="sidebar-group"<?= $knowledgeOpen ? ' open' : '' ?>>
                    <summary><span class="sidebar-link-icon" aria-hidden="true">▣</span><span>Wissenssammlung</span></summary>
                    <div class="sidebar-submenu"><a class="sidebar-link<?= $active === 'formats' ? ' is-active' : '' ?>" href="formats.php"<?= $active === 'formats' ? ' aria-current="page"' : '' ?>>Wissensdatenbank</a></div>
                </details>
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
                        <a class="sidebar-link<?= $active === 'documents' ? ' is-active' : '' ?>" href="documents.php"<?= $active === 'documents' ? ' aria-current="page"' : '' ?>>Dokumente</a>
                        <?php endif; ?>
                    </div>
                </details>
                <?php if ($isAdmin || $isManager): ?>
                <details class="sidebar-group"<?= $adminOpen ? ' open' : '' ?>>
                    <summary><span class="sidebar-link-icon" aria-hidden="true">⚙</span><span>Verwaltung</span></summary>
                    <div class="sidebar-submenu">
                        <?php if ($isAdmin): ?>
                        <a class="sidebar-link<?= $active === 'coaches' ? ' is-active' : '' ?>" href="coaches.php"<?= $active === 'coaches' ? ' aria-current="page"' : '' ?>>Coach-Konten</a>
                        <a class="sidebar-link<?= $active === 'managers' ? ' is-active' : '' ?>" href="managers.php"<?= $active === 'managers' ? ' aria-current="page"' : '' ?>>Verwalterkonten</a>
                        <?php endif; ?>
                        <a class="sidebar-link<?= $active === 'email-settings' ? ' is-active' : '' ?>" href="email-settings.php"<?= $active === 'email-settings' ? ' aria-current="page"' : '' ?>>Meine E-Mail & Passwort-Reset</a>
                        <a class="sidebar-link<?= $active === 'format-categories' ? ' is-active' : '' ?>" href="format-categories.php"<?= $active === 'format-categories' ? ' aria-current="page"' : '' ?>>Wissenskategorien</a>
                    </div>
                </details>
                <?php endif; ?>
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
