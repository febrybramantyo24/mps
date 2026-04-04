<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

function normalize_whatsapp_number(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?: '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '62')) {
        return $digits;
    }
    if (str_starts_with($digits, '08')) {
        return '62' . substr($digits, 1);
    }
    if (str_starts_with($digits, '8')) {
        return '62' . $digits;
    }
    return $digits;
}

$conn = db();
$conn->query(
    "CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query(
    "INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
    ('social_facebook', ''),
    ('social_twitter', ''),
    ('social_instagram', ''),
    ('social_youtube', ''),
    ('social_linkedin', ''),
    ('social_whatsapp', ''),
    ('contact_whatsapp_number', ''),
    ('footer_phone_primary', ''),
    ('footer_phone_secondary', ''),
    ('footer_office_hours_1', ''),
    ('footer_office_hours_2', ''),
    ('footer_support_email_primary', ''),
    ('footer_support_email_secondary', ''),
    ('footer_address_1', ''),
    ('footer_address_2', ''),
    ('header_phone_primary', ''),
    ('header_phone_secondary', ''),
    ('header_email_primary', ''),
    ('home_show_team_section', '1'),
    ('show_menu_layanan', '1'),
    ('show_menu_produk', '1'),
    ('show_menu_proyek', '1'),
    ('show_menu_artikel', '1'),
    ('show_menu_kontak', '1'),
    ('show_menu_tentang', '1'),
    ('contact_section_pretitle', ''),
    ('contact_section_title', ''),
    ('contact_section_description', ''),
    ('contact_card_call_title', ''),
    ('contact_card_office_title', ''),
    ('home_counter_years', '10'),
    ('home_counter_projects', '2'),
    ('home_counter_clients', '20'),
    ('home_counter_response_hours', '24'),
    ('company_profile_pdf_url', ''),
    ('map_embed_url', ''),
    ('map_lat', ''),
    ('map_lng', ''),
    ('map_zoom', '')"
);

$result = $conn->query(
    "SELECT setting_key, setting_value
     FROM site_settings
     WHERE setting_key IN (
        'social_facebook',
        'social_twitter',
        'social_instagram',
        'social_youtube',
        'social_linkedin',
        'social_whatsapp',
        'contact_whatsapp_number',
        'footer_phone_primary',
        'footer_phone_secondary',
        'footer_office_hours_1',
        'footer_office_hours_2',
        'footer_support_email_primary',
        'footer_support_email_secondary',
        'footer_address_1',
        'footer_address_2',
        'header_phone_primary',
        'header_phone_secondary',
        'header_email_primary',
        'home_show_team_section',
        'show_menu_layanan',
        'show_menu_produk',
        'show_menu_proyek',
        'show_menu_artikel',
        'show_menu_kontak',
        'show_menu_tentang',
        'contact_section_pretitle',
        'contact_section_title',
        'contact_section_description',
        'contact_card_call_title',
        'contact_card_office_title',
        'home_counter_years',
        'home_counter_projects',
        'home_counter_clients',
        'home_counter_response_hours',
        'company_profile_pdf_url',
        'map_embed_url',
        'map_lat',
        'map_lng',
        'map_zoom'
     )"
);

$settings = [
    'social_facebook' => '',
    'social_twitter' => '',
    'social_instagram' => '',
    'social_youtube' => '',
    'social_linkedin' => '',
    'social_whatsapp' => '',
    'contact_whatsapp_number' => '',
    'footer_phone_primary' => '',
    'footer_phone_secondary' => '',
    'footer_office_hours_1' => '',
    'footer_office_hours_2' => '',
    'footer_support_email_primary' => '',
    'footer_support_email_secondary' => '',
    'footer_address_1' => '',
    'footer_address_2' => '',
    'header_phone_primary' => '',
    'header_phone_secondary' => '',
    'header_email_primary' => '',
    'home_show_team_section' => '1',
    'show_menu_layanan' => '1',
    'show_menu_produk' => '1',
    'show_menu_proyek' => '1',
    'show_menu_artikel' => '1',
    'show_menu_kontak' => '1',
    'show_menu_tentang' => '1',
    'contact_section_pretitle' => '',
    'contact_section_title' => '',
    'contact_section_description' => '',
    'contact_card_call_title' => '',
    'contact_card_office_title' => '',
    'home_counter_years' => '10',
    'home_counter_projects' => '2',
    'home_counter_clients' => '20',
    'home_counter_response_hours' => '24',
    'company_profile_pdf_url' => '',
    'map_embed_url' => '',
    'map_lat' => '',
    'map_lng' => '',
    'map_zoom' => '',
];

while ($row = $result->fetch_assoc()) {
    $key = (string) ($row['setting_key'] ?? '');
    if (!array_key_exists($key, $settings)) {
        continue;
    }
    $settings[$key] = trim((string) ($row['setting_value'] ?? ''));
}

$waDigits = normalize_whatsapp_number($settings['contact_whatsapp_number']);
$waUrl = $waDigits !== '' ? ('https://wa.me/' . $waDigits) : '';
if ($waUrl === '' && $settings['social_whatsapp'] !== '') {
    $waUrl = $settings['social_whatsapp'];
    $waFromUrlDigits = normalize_whatsapp_number((string) preg_replace('/\D+/', '', $waUrl));
    if ($waFromUrlDigits !== '') {
        $waUrl = 'https://wa.me/' . $waFromUrlDigits;
        $waDigits = $waFromUrlDigits;
    }
}

json_response([
    'ok' => true,
    'settings' => [
        'facebook' => $settings['social_facebook'],
        'twitter' => $settings['social_twitter'],
        'instagram' => $settings['social_instagram'],
        'youtube' => $settings['social_youtube'],
        'linkedin' => $settings['social_linkedin'],
        'whatsapp' => $waUrl,
        'whatsappNumber' => $waDigits,
        'headerPhonePrimary' => $settings['header_phone_primary'],
        'headerPhoneSecondary' => $settings['header_phone_secondary'],
        'headerEmailPrimary' => $settings['header_email_primary'],
        'homeShowTeamSection' => $settings['home_show_team_section'] === '0' ? '0' : '1',
        'showMenuLayanan' => $settings['show_menu_layanan'] === '0' ? '0' : '1',
        'showMenuProduk' => $settings['show_menu_produk'] === '0' ? '0' : '1',
        'showMenuProyek' => $settings['show_menu_proyek'] === '0' ? '0' : '1',
        'showMenuArtikel' => $settings['show_menu_artikel'] === '0' ? '0' : '1',
        'showMenuKontak' => $settings['show_menu_kontak'] === '0' ? '0' : '1',
        'showMenuTentang' => $settings['show_menu_tentang'] === '0' ? '0' : '1',
        'footerPhonePrimary' => $settings['footer_phone_primary'],
        'footerPhoneSecondary' => $settings['footer_phone_secondary'],
        'footerOfficeHours1' => $settings['footer_office_hours_1'],
        'footerOfficeHours2' => $settings['footer_office_hours_2'],
        'footerSupportEmailPrimary' => $settings['footer_support_email_primary'],
        'footerSupportEmailSecondary' => $settings['footer_support_email_secondary'],
        'footerAddress1' => $settings['footer_address_1'],
        'footerAddress2' => $settings['footer_address_2'],
        'contactSectionPretitle' => $settings['contact_section_pretitle'],
        'contactSectionTitle' => $settings['contact_section_title'],
        'contactSectionDescription' => $settings['contact_section_description'],
        'contactCardCallTitle' => $settings['contact_card_call_title'],
        'contactCardOfficeTitle' => $settings['contact_card_office_title'],
        'homeCounterYears' => $settings['home_counter_years'],
        'homeCounterProjects' => $settings['home_counter_projects'],
        'homeCounterClients' => $settings['home_counter_clients'],
        'homeCounterResponseHours' => $settings['home_counter_response_hours'],
        'companyProfilePdfUrl' => $settings['company_profile_pdf_url'],
        'mapEmbedUrl' => $settings['map_embed_url'],
        'mapLat' => $settings['map_lat'],
        'mapLng' => $settings['map_lng'],
        'mapZoom' => $settings['map_zoom'],
    ],
]);
