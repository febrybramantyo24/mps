INSERT INTO products (slug, name, price, image, short_description, description, features_json, additional_json)
VALUES
(
  'pipa-hydrant',
  'Pipa Hydrant',
  750000,
  '/assets/images/shop/shop-01.jpg',
  'Pipa hydrant untuk instalasi distribusi air pemadam kebakaran.',
  'Pipa hydrant berkualitas untuk sistem proteksi kebakaran gedung dan area industri.',
  JSON_ARRAY('Bahan pipa kuat dan tahan tekanan', 'Cocok untuk jaringan indoor maupun outdoor', 'Mudah dipadukan dengan komponen hydrant standar'),
  JSON_ARRAY('Material: Carbon steel', 'Aplikasi: Gedung, gudang, pabrik', 'Garansi: 3 bulan')
),
(
  'pompa-hydrant',
  'Pompa Hydrant',
  5250000,
  '/assets/images/shop/shop-02.jpg',
  'Pompa hydrant untuk menjaga debit dan tekanan sistem pemadam.',
  'Pompa hydrant ini dirancang menjaga suplai air stabil saat kondisi darurat.',
  JSON_ARRAY('Performa tekanan stabil', 'Efisiensi energi lebih baik', 'Mudah maintenance'),
  JSON_ARRAY('Material body: Cast iron', 'Motor: 3 phase', 'Garansi: 6 bulan')
),
(
  'jasa-instalasi',
  'Jasa Instalasi',
  3500000,
  '/assets/images/shop/shop-03.jpg',
  'Layanan instalasi sistem proteksi kebakaran oleh teknisi berpengalaman.',
  'Jasa instalasi mencakup survey lokasi, perencanaan kebutuhan, pemasangan, dan pengujian sistem.',
  JSON_ARRAY('Tim teknisi profesional', 'Pengerjaan rapi', 'Dukungan after-sales'),
  JSON_ARRAY('Ruang lingkup: Survey hingga commissioning', 'Area: Jabodetabek', 'Garansi pekerjaan: 1 bulan')
);

INSERT INTO team_members (name, role, image, social_facebook, social_linkedin, social_youtube, social_whatsapp, sort_order, is_active)
VALUES
('Samuel Daniel', 'Designer', '/assets/images/team/08.webp', '', '', '', '', 1, 1),
('Mekail Shen', 'Designer', '/assets/images/team/09.webp', '', '', '', '', 2, 1),
('William Daniel', 'Developer', '/assets/images/team/10.webp', '', '', '', '', 3, 1),
('Nicholas Arthur', 'UI/UX Designer', '/assets/images/team/11.webp', '', '', '', '', 4, 1);

INSERT INTO services (slug, name, image, short_description, description, features_json, detail_url, video_url, sort_order, is_active)
VALUES
('ducting-system', 'Ducting System', '/assets/images/service/11.webp', 'Layanan fabrikasi dan instalasi ducting untuk ventilasi dan HVAC standar industri.', 'Layanan ducting mencakup survey, desain jalur, fabrikasi, pemasangan, dan uji performa untuk kebutuhan industri maupun komersial.', JSON_ARRAY('Survey & perhitungan kebutuhan', 'Fabrikasi presisi sesuai gambar kerja', 'Instalasi rapi dan uji performa'), '/layanan/detail/?slug=ducting-system', '', 1, 1),
('hydrant-system', 'Hydrant System', '/assets/images/service/12.webp', 'Instalasi jaringan hydrant dan fire protection untuk keamanan gedung dan area industri.', 'Layanan hydrant meliputi perencanaan, instalasi jaringan pipa, pompa, hingga commissioning sistem proteksi kebakaran.', JSON_ARRAY('Sesuai standar keselamatan', 'Material berkualitas industri', 'Dokumentasi dan testing lengkap'), '/layanan/detail/?slug=hydrant-system', '', 2, 1),
('sprinkler-system', 'Sprinkler System', '/assets/images/service/13.webp', 'Pemasangan sistem sprinkler otomatis untuk proteksi kebakaran yang cepat dan presisi.', 'Layanan sprinkler untuk perlindungan area kritis dengan desain coverage optimal dan integrasi panel alarm kebakaran.', JSON_ARRAY('Coverage area optimal', 'Respons cepat saat darurat', 'Perawatan berkala tersedia'), '/layanan/detail/?slug=sprinkler-system', '', 3, 1);

INSERT INTO projects (slug, title, image, category, short_description, description, features_json, detail_url, video_url, sort_order, is_active)
VALUES
('instalasi-hydrant-gudang-logistik', 'Instalasi Hydrant Gudang Logistik', '/assets/images/portfolio/20.webp', 'Building, Renovation', 'Pengerjaan sistem hydrant lengkap untuk area gudang dengan standar K3 industri.', 'Proyek ini mencakup survey lapangan, pemasangan jaringan pipa hydrant, instalasi pompa, serta pengujian akhir agar sistem siap operasional.', JSON_ARRAY('Pengerjaan sesuai standar K3', 'Material berkualitas industri', 'Timeline pengerjaan terkontrol'), '/proyek/detail/?slug=instalasi-hydrant-gudang-logistik', '', 1, 1),
('upgrade-ducting-fasilitas-manufaktur', 'Upgrade Ducting Fasilitas Manufaktur', '/assets/images/portfolio/21.webp', 'Mechanical', 'Upgrade jalur ducting utama untuk meningkatkan performa aliran udara proses produksi.', 'Upgrade ducting dilakukan bertahap agar proses produksi tetap berjalan, dengan fokus pada efisiensi aliran udara dan kestabilan temperatur.', JSON_ARRAY('Minim gangguan ke operasional', 'Optimasi efisiensi sistem HVAC', 'Dokumentasi commissioning lengkap'), '/proyek/detail/?slug=upgrade-ducting-fasilitas-manufaktur', '', 2, 1),
('instalasi-panel-dan-maintenance', 'Instalasi Panel dan Maintenance', '/assets/images/portfolio/22.webp', 'Electrical', 'Implementasi panel kontrol dan program maintenance berkala untuk fasilitas produksi.', 'Pemasangan panel kontrol dan preventive maintenance untuk meningkatkan reliability sistem serta menekan risiko downtime.', JSON_ARRAY('Panel sesuai kebutuhan site', 'Safety checklist sebelum handover', 'Program maintenance berkala'), '/proyek/detail/?slug=instalasi-panel-dan-maintenance', '', 3, 1);

INSERT INTO site_settings (setting_key, setting_value)
VALUES
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
('contact_section_pretitle', ''),
('contact_section_title', ''),
('contact_section_description', ''),
('contact_card_call_title', ''),
('contact_card_office_title', ''),
('map_embed_url', ''),
('map_lat', ''),
('map_lng', ''),
('map_zoom', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
