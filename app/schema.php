<?php
declare(strict_types=1);

function coachingSchemaStatus(PDO $database): array
{
    $required = ['benutzer', 'klienten', 'termine', 'login_versuche', 'coaching_sitzungsnotizen', 'coaching_ziele', 'coaching_dokumentvorlagen', 'coaching_klientendokumente', 'coaching_dokumentdateien', 'coaching_dokument_signaturanfragen', 'coaching_klientenstatus', 'coaching_portal_zugaenge', 'coaching_aufgaben', 'coaching_design_einstellungen', 'coaching_nachrichten', 'coaching_nachrichten_dateien', 'coaching_formate', 'coaching_format_freigaben'];
    $existing = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($required, $existing));

    return [
        'ready' => $missing === [],
        'required_tables' => $required,
        'missing_tables' => $missing,
    ];
}
