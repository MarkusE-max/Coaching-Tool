<?php
declare(strict_types=1);

function coachingSchemaStatus(PDO $database): array
{
    $required = ['benutzer', 'klienten', 'termine', 'login_versuche', 'coaching_sitzungsnotizen', 'coaching_ziele', 'coaching_dokumentvorlagen', 'coaching_klientendokumente', 'coaching_dokumentdateien', 'coaching_dokument_signaturanfragen', 'coaching_klientenstatus', 'coaching_portal_zugaenge', 'coaching_aufgaben', 'coaching_design_einstellungen', 'coaching_nachrichten', 'coaching_nachrichten_dateien', 'coaching_formate', 'coaching_format_freigaben', 'coaching_klientenzuweisungen', 'coaching_rechnungseinstellungen', 'coaching_steuersaetze', 'coaching_rechnungsvorlagen', 'coaching_rechnungen'];
    $existing = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($required, $existing));
    $requiredNoteColumns = ['format_id', 'skala_vorher', 'skala_nachher'];
    $noteColumns = in_array('coaching_sitzungsnotizen', $existing, true)
        ? $database->query('SHOW COLUMNS FROM coaching_sitzungsnotizen')->fetchAll(PDO::FETCH_COLUMN)
        : [];
    $missingNoteColumns = array_values(array_diff($requiredNoteColumns, $noteColumns));

    return [
        'ready' => $missing === [] && $missingNoteColumns === [],
        'required_tables' => $required,
        'missing_tables' => $missing,
        'required_note_columns' => $requiredNoteColumns,
        'missing_note_columns' => $missingNoteColumns,
    ];
}
