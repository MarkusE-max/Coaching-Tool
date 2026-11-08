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
    $roleLabel = $isAdmin ? 'Administrator' : 'Coach';
    $displayName = (string) ($user['name'] ?? 'Benutzer');
    $initial = preg_match('/^./u', $displayName, $initialMatch) === 1 ? $initialMatch[0] : 'U';
    $coachingOpen = in_array($active, ['clients', 'appointments', 'goals'], true);
    $docsOpen = $active === 'notes';
    $adminOpen = $active === 'coaches';
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
                        <a class="sidebar-link<?= $active === 'appointments' ? ' is-active' : '' ?>" href="appointments.php"<?= $active === 'appointments' ? ' aria-current="page"' : '' ?>>Termine</a>
                        <a class="sidebar-link<?= $active === 'goals' ? ' is-active' : '' ?>" href="goals.php"<?= $active === 'goals' ? ' aria-current="page"' : '' ?>>Ziele</a>
                    </div>
                </details>
                <?php if ($role === 'coach'): ?>
                <details class="sidebar-group"<?= $docsOpen ? ' open' : '' ?>>
                    <summary><span class="sidebar-link-icon" aria-hidden="true">▤</span><span>Dokumentation</span></summary>
                    <div class="sidebar-submenu">
                        <a class="sidebar-link<?= $active === 'notes' ? ' is-active' : '' ?>" href="notes.php"<?= $active === 'notes' ? ' aria-current="page"' : '' ?>>Private Sitzungsnotizen</a>
                    </div>
                </details>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                <details class="sidebar-group"<?= $adminOpen ? ' open' : '' ?>>
                    <summary><span class="sidebar-link-icon" aria-hidden="true">⚙</span><span>Verwaltung</span></summary>
                    <div class="sidebar-submenu">
                        <a class="sidebar-link<?= $active === 'coaches' ? ' is-active' : '' ?>" href="coaches.php"<?= $active === 'coaches' ? ' aria-current="page"' : '' ?>>Coach-Konten</a>
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
