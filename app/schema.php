<?php
declare(strict_types=1);

function coachingSchemaStatus(PDO $database): array
{
    $required = ['benutzer', 'klienten', 'termine', 'login_versuche', 'coaching_sitzungsnotizen', 'coaching_ziele', 'coaching_dokumentvorlagen', 'coaching_klientendokumente', 'coaching_dokumentdateien', 'coaching_dokument_signaturanfragen', 'coaching_klientenstatus', 'coaching_portal_zugaenge', 'coaching_aufgaben', 'coaching_design_einstellungen', 'coaching_nachrichten', 'coaching_nachrichten_dateien', 'coaching_formate', 'coaching_format_freigaben', 'coaching_klientenzuweisungen', 'coaching_rechnungseinstellungen', 'coaching_steuersaetze', 'coaching_rechnungsvorlagen', 'coaching_rechnungen', 'coaching_email_einstellungen', 'coaching_passwort_resets'];
    $existing = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($required, $existing));
    $requiredNoteColumns = ['format_id', 'skala_vorher', 'skala_nachher'];
    $requiredInvoiceColumns = ['bank_name', 'zahlungsziel_tage'];
    $requiredInvoiceSettingsColumns = ['bank_name', 'ec_zahlung_aktiv'];
    $invoiceSettingsColumns = in_array('coaching_rechnungseinstellungen', $existing, true)
        ? $database->query('SHOW COLUMNS FROM coaching_rechnungseinstellungen')->fetchAll(PDO::FETCH_COLUMN)
        : [];
    $missingInvoiceSettingsColumns = array_values(array_diff($requiredInvoiceSettingsColumns, $invoiceSettingsColumns));
    $requiredPortalColumns = ['email'];
    $portalColumns = in_array('coaching_portal_zugaenge', $existing, true)
        ? $database->query('SHOW COLUMNS FROM coaching_portal_zugaenge')->fetchAll(PDO::FETCH_COLUMN)
        : [];
    $missingPortalColumns = array_values(array_diff($requiredPortalColumns, $portalColumns));
    $invoiceColumns = in_array('coaching_rechnungen', $existing, true)
        ? $database->query('SHOW COLUMNS FROM coaching_rechnungen')->fetchAll(PDO::FETCH_COLUMN)
        : [];
    $missingInvoiceColumns = array_values(array_diff($requiredInvoiceColumns, $invoiceColumns));
    $noteColumns = in_array('coaching_sitzungsnotizen', $existing, true)
        ? $database->query('SHOW COLUMNS FROM coaching_sitzungsnotizen')->fetchAll(PDO::FETCH_COLUMN)
        : [];
    $missingNoteColumns = array_values(array_diff($requiredNoteColumns, $noteColumns));

    return [
        'ready' => $missing === [] && $missingNoteColumns === [] && $missingInvoiceColumns === [] && $missingInvoiceSettingsColumns === [] && $missingPortalColumns === [],
        'required_tables' => $required,
        'missing_tables' => $missing,
        'required_note_columns' => $requiredNoteColumns,
        'missing_note_columns' => $missingNoteColumns,
        'required_invoice_columns' => $requiredInvoiceColumns,
        'missing_invoice_columns' => $missingInvoiceColumns,
        'required_invoice_settings_columns' => $requiredInvoiceSettingsColumns,
        'missing_invoice_settings_columns' => $missingInvoiceSettingsColumns,
        'required_portal_columns' => $requiredPortalColumns,
        'missing_portal_columns' => $missingPortalColumns,
    ];
}
