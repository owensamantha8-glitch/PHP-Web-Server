<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Safe session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone to strictly follow South African time zones for the TOU Algorithm
date_default_timezone_set('Africa/Johannesburg');

// Disable PCRE JIT compiler to prevent memory allocation warnings in strict environments
ini_set('pcre.jit', '0');

// =========================================================================
// LYNX UTILITY MANAGEMENT - CORE CALCULATION ENGINE
// =========================================================================

// =========================================================================
// PUBLIC HOLIDAYS (sys_db_information.lum_public_holidays)
// -------------------------------------------------------------------------
// Used by the Time Of Use calculation. Managed on the Public Holidays screen
// (Configurations). If the table is not there yet, the list the engine used
// before (2026) is used, so billing does not change.
// =========================================================================
if (!defined('LUM_FALLBACK_PUBLIC_HOLIDAYS')) {
    define('LUM_FALLBACK_PUBLIC_HOLIDAYS', ['2026-01-01', '2026-03-21', '2026-04-03', '2026-04-06', '2026-04-27', '2026-05-01', '2026-06-16',
                                            '2026-08-09', '2026-08-10', '2026-09-24', '2026-12-16', '2026-12-25', '2026-12-26']);
}




// =========================================================================
// WATER TIER BANDS (sys_db_tariffs.lum_water_tiers)
// -------------------------------------------------------------------------
// How many kL fall in each tier of a tiered water tariff. The rates stay in the
// municipal tariff tables (getTariffRates: water_tier_1 ... water_tier_7).
// Used by the consumption slip, the dashboard cache and the tenant estimate.
// If the table is not there yet, the same bands are used from the list below.
// =========================================================================
if (!defined('LUM_FALLBACK_WATER_TIERS')) {
    // municipality => [[up_to_kl (null = no limit), rate_key, label], ...]
    define('LUM_FALLBACK_WATER_TIERS', [
        'Mkhondo' => [[6, 'water_tier_1', 'Water Charge Tier 1 (0–6 kL)'], [20, 'water_tier_2', 'Water Charge Tier 2 (7–20 kL)'], [40, 'water_tier_3', 'Water Charge Tier 3 (21–40 kL)'], [60, 'water_tier_4', 'Water Charge Tier 4 (41–60 kL)'], [null, 'water_tier_5', 'Water Charge Tier 5 (61+ kL)']],
        'George' => [[6, 'water_tier_1', 'Water Charge Tier 1 (0–6 kL)'], [15, 'water_tier_2', 'Water Charge Tier 2 (7–15 kL)'], [20, 'water_tier_3', 'Water Charge Tier 3 (16–20 kL)'], [30, 'water_tier_4', 'Water Charge Tier 4 (21–30 kL)'], [50, 'water_tier_5', 'Water Charge Tier 5 (31–50 kL)'], [75, 'water_tier_6', 'Water Charge Tier 6 (51–75 kL)'], [null, 'water_tier_7', 'Water Charge Tier 7 (75+ kL)']],
        'Rustenburg' => [[60, 'water_tier_1', 'Water Charge Tier 1 (0–60 kL)'], [100, 'water_tier_2', 'Water Charge Tier 2 (61–100 kL)'], [150, 'water_tier_3', 'Water Charge Tier 3 (101–150 kL)'], [null, 'water_tier_4', 'Water Charge Tier 4 (151+ kL)']],
        'Plettenberg Bay' => [[60, 'water_tier_1', 'Water Charge Tier 1 (0–60 kL)'], [100, 'water_tier_2', 'Water Charge Tier 2 (61–100 kL)'], [200, 'water_tier_3', 'Water Charge Tier 3 (101–200 kL)'], [null, 'water_tier_4', 'Water Charge Tier 4 (above 200 kL)']],
        '*' => [[60, 'water_tier_1', 'Water Charge Tier 1 (0–60 kL)'], [100, 'water_tier_2', 'Water Charge Tier 2 (61–100 kL)'], [150, 'water_tier_3', 'Water Charge Tier 3 (101–150 kL)'], [null, 'water_tier_4', 'Water Charge Tier 4 (151+ kL)']],
    ]);
}





// =========================================================================
// TARIFF CATALOG (sys_db_tariffs.lum_tariff_catalog)
// -------------------------------------------------------------------------
// How each tariff behaves (municipality, Time Of Use, not applicable, Amps,
// display / calculation name). Tariffs not in the catalog keep the old rules
// based on words in the name (the lumTariffLegacy... functions below), so a
// catalog filled from those rules bills exactly as before.
// Services: electricity, water, sewer, generator.
// =========================================================================
if (!defined('LUM_TARIFF_SERVICES')) {
    define('LUM_TARIFF_SERVICES', ['electricity' => 'Electricity', 'water' => 'Water', 'sewer' => 'Sewer', 'generator' => 'Generator',
                                   'elec_common' => 'Electrical Common Area', 'water_common' => 'Water Common Area']);
    define('LUM_TARIFF_MUNICIPALITIES', ['Tshwane', 'Ekhurhuleni', 'Rustenburg', 'Mkhondo', 'Plettenberg Bay', 'George']);
    define('LUM_TOU_ALGORITHMS', ['Tshwane', 'Ekurhuleni', 'Bitou', 'George']);
}

















// =========================================================================
// TIME OF USE PERIODS (sys_db_tariffs.lum_tou_algorithms / lum_tou_bands)
// -------------------------------------------------------------------------
// Which hours are peak, standard and off-peak for each TOU algorithm, how a
// weekday public holiday counts, and where the high / low season comes from.
// Managed on Tariffs -> TOU Periods. Until tou-periods-setup.sql has been run,
// the built-in periods below are used (exactly the periods used until now).
// Hours: 24 characters, 00:00-01:00 first. P = peak, S = standard, O = off-peak.
// =========================================================================
if (!defined('LUM_TOU_DEFAULTS')) {
    define('LUM_TOU_DEFAULTS', [
        'Ekurhuleni' => ['label' => 'Ekurhuleni', 'holiday_rule' => 'saturday', 'season_source' => 'months',
            'high_season_months' => '6,7,8', 'default_for' => 'Ekhurhuleni', 'aliases' => '',
            'season_ledger' => 'lum_tarrifs_ekhurhuleni', 'sort_order' => 1, 'active' => 1,
            'bands' => ['high' => ['weekday' => 'OOOOOOPPSSSSSSSSSPPPSSOO', 'saturday' => 'OOOOOOOSSSSSOOOOOSSOOOOO', 'sunday' => 'OOOOOOOOOOOOOOOOOSSOOOOO'],
                        'low'  => ['weekday' => 'OOOOOOSPPSSSSSSSSSPPPSOO', 'saturday' => 'OOOOOOOSSSSSOOOOOOSSOOOO', 'sunday' => 'OOOOOOOOOOOOOOOOOOSSOOOO']]],
        'Tshwane' => ['label' => 'City of Tshwane (Eskom-based)', 'holiday_rule' => 'saturday', 'season_source' => 'months',
            'high_season_months' => '6,7,8', 'default_for' => '*', 'aliases' => '',
            'season_ledger' => 'lum_tariffs_city_of_tshwane', 'sort_order' => 2, 'active' => 1,
            'bands' => ['high' => ['weekday' => 'OOOOOOPPPSSSSSSSSPPSSSOO', 'saturday' => 'OOOOOOOSSSSSOOOOOOSSOOOO', 'sunday' => 'OOOOOOOOOOOOOOOOOOOOOOOO'],
                        'low'  => ['weekday' => 'OOOOOOSPPPSSSSSSSSPPSSOO', 'saturday' => 'OOOOOOOSSSSSOOOOOOSSOOOO', 'sunday' => 'OOOOOOOOOOOOOOOOOOOOOOOO']]],
        'George' => ['label' => 'George', 'holiday_rule' => 'saturday', 'season_source' => 'none',
            'high_season_months' => '', 'default_for' => 'George', 'aliases' => '*George*',
            'season_ledger' => 'lum_tariffs_george', 'sort_order' => 3, 'active' => 1,
            'bands' => ['high' => ['weekday' => 'OOOOOOPPPSSSSSSSSPPSSSOO', 'saturday' => 'OOOOOOOSSSSSOOOOOOSSOOOO', 'sunday' => 'OOOOOOOOOOOOOOOOOOOOOOOO'],
                        'low'  => ['weekday' => 'OOOOOOPPPSSSSSSSSPPSSSOO', 'saturday' => 'OOOOOOOSSSSSOOOOOOSSOOOO', 'sunday' => 'OOOOOOOOOOOOOOOOOOOOOOOO']]],
        'Bitou' => ['label' => 'Bitou / Plettenberg Bay', 'holiday_rule' => 'saturday', 'season_source' => 'months',
            'high_season_months' => '6,7,8', 'default_for' => 'Plettenberg Bay', 'aliases' => 'Bitou TOU,Plettenberg Bay,The Marketsquare,*Plett*,*Bitou*',
            'season_ledger' => 'lum_tariffs_plett', 'sort_order' => 4, 'active' => 1,
            'bands' => ['high' => ['weekday' => 'OOOOOOPPPSSSSSSSSPPSSSOO', 'saturday' => 'OOOOOOOSSSSSOOOOOOSSOOOO', 'sunday' => 'OOOOOOOOOOOOOOOOOOOOOOOO'],
                        'low'  => ['weekday' => 'OOOOOOSPPPSSSSSSSSPPSSOO', 'saturday' => 'OOOOOOOSSSSSOOOOOOSSOOOO', 'sunday' => 'OOOOOOOOOOOOOOOOOOOOOOOO']]],
    ]);
    // Municipal tariff tables that hold a demand_season per month (for the ledger season source)
    define('LUM_TOU_SEASON_LEDGERS', [
        'lum_tariffs_city_of_tshwane' => 'Tshwane', 'lum_tarrifs_ekhurhuleni' => 'Ekurhuleni', 'lum_tariffs_rustenburg' => 'Rustenburg',
        'lum_tariffs_mkhondo' => 'Mkhondo', 'lum_tariffs_plett' => 'Plettenberg Bay', 'lum_tariffs_george' => 'George',
    ]);
}








// =========================================================================
// COMPANY DETAILS (sys_db_information.lum_company_settings)
// -------------------------------------------------------------------------
// Printed on consumption slips and credit / debit notes. Managed on
// Configurations -> Company Details. Until company-details-setup.sql has been
// run, the details below are used.
// =========================================================================
if (!defined('LUM_COMPANY_DEFAULTS')) {
    define('LUM_COMPANY_DEFAULTS', [
        'company_name'         => 'Lynx Utility Management (Pty) Ltd',
        'company_phone'        => '012 807 2113',
        'company_email'        => 'utilities@lynx-re.co.za',
        'logo_url'             => 'https://lynx-um.co.za/Additions/Style-index/LUM-login-logo.png',
        'slip_title'           => 'Consumption Slip',
        'contact_note'         => 'Should you have any concerns regarding {document}, please do not hesitate to contact our offices at {phone}. Alternatively you are welcome to send your concern via email to {email}.',
        'company_address'      => '',
        'company_registration' => '',
        'vat_number'           => '',
    ]);
    // Settings that may not be blank (a blank value falls back to the default)
    define('LUM_COMPANY_REQUIRED', ['company_name', 'slip_title', 'contact_note']);
}




// =========================================================================
// PROPERTY METERS & DASHBOARD SETTINGS
// -------------------------------------------------------------------------
// sys_db_meters.lum_property_meters : which meters the dashboard uses per property
// lum_properties.dashboard_*        : what the dashboard shows per property
// sys_db_information.lum_system_settings : e.g. the meter status limits
// Managed on Configurations -> Property Meters. Until property-meters-setup.sql
// has been run, the built-in lists below are used (the dashboard as it was).
// =========================================================================
if (!defined('LUM_PROPERTY_METER_ROLES')) {
    define('LUM_PROPERTY_METER_ROLES', [
        'grid'       => 'Grid supply (electricity)',
        'solar'      => 'Solar production',
        'elec_check' => 'Electrical check meter (total usage: solar = check - grid)',
        'water_main' => 'Property water meter (water graph)',
        'flow_in'    => 'Water flow diagram: source',
        'flow_out'   => 'Water flow diagram: combined / destination',
        // Billing: the property's common area calculation (property Billing Settings)
        'ca_elec_main'  => 'Electrical common area: main meters (added)',
        'ca_elec_less'  => 'Electrical common area: meters subtracted',
        'ca_water_main' => 'Water common area: main meters',
    ]);
    define('LUM_PROPERTY_METER_COLORS', ['info' => '#0dcaf0', 'primary' => '#0d6efd', 'success' => '#198754', 'warning' => '#ffc107', 'danger' => '#dc3545']);
    // property, serial, role, label, icon, color, sort order
    define('LUM_PROPERTY_METERS_DEFAULT', [
        ['DHL', '36339327', 'grid', '', '', '', 1],
        ['DHL', '56738245', 'water_main', '', '', '', 1],
        ['Groenkloof Chambers', '30767413', 'grid', '', '', '', 1],
        ['Groenkloof Chambers', '35779463', 'solar', '', '', '', 1],
        ['Lambton Gardens', '35727449', 'grid', '', '', '', 1],
        ['Lambton Gardens', '35727451', 'grid', '', '', '', 2],
        ['Lambton Gardens', '56704822', 'water_main', '', '', '', 1],
        ['Lambton Gardens', '77603471', 'water_main', '', '', '', 2],
        ['Lambton Gardens', '54135231', 'water_main', '', '', '', 3],
        ['Lambton Gardens', '54366895', 'water_main', '', '', '', 4],
        ['Linton\'s Corner', '35727284', 'grid', '', '', '', 1],
        ['Linton\'s Corner', '35727283', 'grid', '', '', '', 2],
        ['Linton\'s Corner', '35727281', 'grid', '', '', '', 3],
        ['Linton\'s Corner', '35727274', 'elec_check', '', '', '', 1],
        ['Linton\'s Corner', '35727282', 'elec_check', '', '', '', 2],
        ['Linton\'s Corner', '35727293', 'elec_check', '', '', '', 3],
        ['Linton\'s Corner', '56721769', 'water_main', 'Borehole 2 meter (outgoing)', '', '', 1],
        ['Linton\'s Corner', '56721797', 'water_main', 'Main council bulk check meter', '', '', 2],
        ['Linton\'s Corner', '56738781', 'flow_in', 'Borehole 1 (incoming)', 'cloud-rain', 'info', 1],
        ['Linton\'s Corner', '56721769', 'flow_in', 'Borehole 2 (outgoing)', 'arrow-up-right-circle', 'info', 2],
        ['Linton\'s Corner', '56721797', 'flow_in', 'Council water', 'building', 'primary', 3],
        ['Linton\'s Corner', '53027429', 'flow_out', 'Main check meter (combined)', 'speedometer', 'success', 1],
        ['Lynnwood Lane', '30895043', 'grid', '', '', '', 1],
        ['Lynnwood Lane', '30895042', 'solar', '', '', '', 1],
        ['Lynnwood Lane', '68738628', 'water_main', '', '', '', 1],
        ['Lynnwood Lane', '68738629', 'water_main', '', '', '', 2],
        ['Lynnwood Lane', '68777331', 'water_main', '', '', '', 3],
        ['Lynnwood Lane', '68985387', 'water_main', '', '', '', 4],
        ['Lynnwood Lane', '75471335', 'water_main', '', '', '', 5],
        ['Lynnwood Lane', '75471336', 'water_main', '', '', '', 6],
        ['Lynnwood Lane', '77804481', 'water_main', '', '', '', 7],
        ['Lynnwood Lane', '26700282', 'flow_in', 'Water treatment pump 1', 'gear-wide-connected', 'info', 1],
        ['Lynnwood Lane', '26663411', 'flow_in', 'Water treatment pump 2', 'gear-wide-connected', 'info', 2],
        ['Lynnwood Lane', '26700284', 'flow_out', 'Water treatment tanks', 'database', 'success', 1],
        ['N2 Woodhill', '32910149', 'grid', '', '', '', 1],
        ['N2 Woodhill', '32910148', 'solar', '', '', '', 1],
        ['Thatchfield Centre', '34445624', 'grid', '', '', '', 1],
        ['Thatchfield Centre', '34445612', 'solar', '', '', '', 1],
        ['Thatchfield Centre', '56738251', 'water_main', '', '', '', 1],
        ['Thatchfield Centre', '75471340', 'water_main', '', '', '', 2],
        ['Thatchfield Centre', '75471356', 'water_main', '', '', '', 3],
        ['Thatchfield Centre', '75749219', 'water_main', '', '', '', 4],
        ['Greystone Crossing', '35778796', 'grid', '', '', '', 1],
        ['Greystone Crossing', '35778790', 'solar', '', '', '', 1],
        ['Greystone Crossing', '35778792', 'solar', '', '', '', 2],
        ['The Marketsquare', '36922141', 'grid', '', '', '', 1],
        ['The Marketsquare', '54462594', 'water_main', '', '', '', 1],
        ['The Marketsquare', '54462588', 'water_main', '', '', '', 2],
        // Common area calculations
        ['Lambton Gardens', '35727449', 'ca_elec_main', 'Common area: main meter', '', '', 1],
        ['Lambton Gardens', '35727451', 'ca_elec_main', 'Common area: main meter', '', '', 2],
        ['Thatchfield Centre', '34445612', 'ca_elec_main', 'Common area: main meter', '', '', 1],
        ['Thatchfield Centre', '34445624', 'ca_elec_main', 'Common area: main meter', '', '', 2],
        ['Thatchfield Centre', '34445808', 'ca_elec_less', 'Common area: subtracted meter', '', '', 1],
        ['Thatchfield Centre', '34445585', 'ca_elec_less', 'Common area: subtracted meter', '', '', 2],
        ['Thatchfield Centre', '34445625', 'ca_elec_less', 'Common area: subtracted meter', '', '', 3],
        ['Thatchfield Centre', '34445584', 'ca_elec_less', 'Common area: subtracted meter', '', '', 4],
        ['Thatchfield Centre', '29963721', 'ca_elec_less', 'Common area: subtracted meter', '', '', 5],
        ['Thatchfield Centre', '29963728', 'ca_elec_less', 'Common area: subtracted meter', '', '', 6],
        ['Thatchfield Centre', '31384436', 'ca_elec_less', 'Common area: subtracted meter', '', '', 7],
        ['Thatchfield Centre', '33755776', 'ca_elec_less', 'Common area: subtracted meter', '', '', 8],
        ['Thatchfield Centre', '33755707', 'ca_elec_less', 'Common area: subtracted meter', '', '', 9],
        ['Thatchfield Centre', '34321648', 'ca_elec_less', 'Common area: subtracted meter', '', '', 10],
        ['Thatchfield Centre', '34321649', 'ca_elec_less', 'Common area: subtracted meter', '', '', 11],
        ['Thatchfield Centre', '33755778', 'ca_elec_less', 'Common area: subtracted meter', '', '', 12],
        ['Thatchfield Centre', '33755710', 'ca_elec_less', 'Common area: subtracted meter', '', '', 13],
        ['Thatchfield Centre', '33755808', 'ca_elec_less', 'Common area: subtracted meter', '', '', 14],
        ['Lynnwood Lane', '30895042', 'ca_elec_main', 'Common area: main meter', '', '', 1],
        ['Lynnwood Lane', '30895043', 'ca_elec_main', 'Common area: main meter', '', '', 2],
        ['Lynnwood Lane', '30895037', 'ca_elec_less', 'Common area: subtracted meter', '', '', 1],
        ['Lynnwood Lane', '30895023', 'ca_elec_less', 'Common area: subtracted meter', '', '', 2],
        ['Lynnwood Lane', '30895019', 'ca_elec_less', 'Common area: subtracted meter', '', '', 3],
        ['Lynnwood Lane', '30895024', 'ca_elec_less', 'Common area: subtracted meter', '', '', 4],
        ['Lynnwood Lane', '30895039', 'ca_elec_less', 'Common area: subtracted meter', '', '', 5],
        ['Lynnwood Lane', '30895021', 'ca_elec_less', 'Common area: subtracted meter', '', '', 6],
        ['Lynnwood Lane', '30895045', 'ca_elec_less', 'Common area: subtracted meter', '', '', 7],
        ['Lynnwood Lane', '30895030', 'ca_elec_less', 'Common area: subtracted meter', '', '', 8],
        ['Lynnwood Lane', '30895018', 'ca_elec_less', 'Common area: subtracted meter', '', '', 9],
        ['Lynnwood Lane', '30895044', 'ca_elec_less', 'Common area: subtracted meter', '', '', 10],
        ['Lynnwood Lane', '30895017', 'ca_elec_less', 'Common area: subtracted meter', '', '', 11],
        ['Lynnwood Lane', '32046067', 'ca_elec_less', 'Common area: subtracted meter', '', '', 12],
        ['Lynnwood Lane', '30767414', 'ca_elec_less', 'Common area: subtracted meter', '', '', 13],
        ['Lynnwood Lane', '30767415', 'ca_elec_less', 'Common area: subtracted meter', '', '', 14],
        ['Lynnwood Lane', '30895035', 'ca_elec_less', 'Common area: subtracted meter', '', '', 15],
        ['Lynnwood Lane', '30895022', 'ca_elec_less', 'Common area: subtracted meter', '', '', 16],
        ['Lynnwood Lane', '32046063', 'ca_elec_less', 'Common area: subtracted meter', '', '', 17],
        ['Lynnwood Lane', '31139895', 'ca_elec_less', 'Common area: subtracted meter', '', '', 18],
        ['Lynnwood Lane', '30895028', 'ca_elec_less', 'Common area: subtracted meter', '', '', 19],
        ['Lynnwood Lane', '30895033', 'ca_elec_less', 'Common area: subtracted meter', '', '', 20],
        ['Lynnwood Lane', '30895036', 'ca_elec_less', 'Common area: subtracted meter', '', '', 21],
        ['Lynnwood Lane', '30895049', 'ca_elec_less', 'Common area: subtracted meter', '', '', 22],
        ['Greystone Crossing', '35727721', 'ca_elec_main', 'Common area: main meter', '', '', 1],
        ['Greystone Crossing', '35725092', 'ca_elec_main', 'Common area: main meter', '', '', 2],
        ['Greystone Crossing', '35725034', 'ca_elec_main', 'Common area: main meter', '', '', 3],
        ['Greystone Crossing', '34978384', 'ca_elec_main', 'Common area: main meter', '', '', 4],
        ['Greystone Crossing', '35778793', 'ca_elec_main', 'Common area: main meter', '', '', 5],
        ['One On York', '36792048', 'ca_elec_main', 'Common area: main meter', '', '', 1],
        ['The Marketsquare', '36922141', 'ca_elec_main', 'Common area: main meter', '', '', 1],
        ['Linton\'s Corner', '56721769', 'ca_water_main', 'Common area: water main', '', '', 1],
        ['Linton\'s Corner', '56721797', 'ca_water_main', 'Common area: water main', '', '', 2],
        ['The Marketsquare', '54462594', 'ca_water_main', 'Common area: water main', '', '', 1],
        ['The Marketsquare', '54462588', 'ca_water_main', 'Common area: water main', '', '', 2],
        ['Lambton Gardens', '56704822', 'ca_water_main', 'Common area: water main', '', '', 1],
        ['Lambton Gardens', '77603471', 'ca_water_main', 'Common area: water main', '', '', 2],
        ['Lambton Gardens', '54135231', 'ca_water_main', 'Common area: water main', '', '', 3],
        ['Lambton Gardens', '54366895', 'ca_water_main', 'Common area: water main', '', '', 4],
        ['Thatchfield Retail', '56738251', 'ca_water_main', 'Common area: water main', '', '', 1],
        ['Thatchfield Retail', '75471340', 'ca_water_main', 'Common area: water main', '', '', 2],
        ['Thatchfield Retail', '75471356', 'ca_water_main', 'Common area: water main', '', '', 3],
        ['Thatchfield Retail', '75749219', 'ca_water_main', 'Common area: water main', '', '', 4],
        ['Lynnwood Lane', '26700284', 'ca_water_main', 'Common area: water main', '', '', 1]
    ]);
    define('LUM_SYSTEM_SETTING_DEFAULTS', ['meter_online_hours' => '6', 'meter_lag_hours' => '72']);
}













// =========================================================================
// TARIFF RATE MAP (sys_db_tariffs.lum_tariff_rate_map)
// -------------------------------------------------------------------------
// Which municipal ledger column each rate is read from, per
//   municipality (generator, water, sewer, common areas),
//   electricity tariff + municipality, and (Plettenberg Bay / George) water and sewer tariff.
// Filled on Tariffs -> Rate Map from the old rules (getTariffRatesLegacy), and
// checked against them. A combination that is not fully in the map keeps the old rules.
// =========================================================================
if (!defined('LUM_RATE_LEDGERS')) {
    // Ledger table => municipality
    define('LUM_RATE_LEDGERS', [
        'lum_tariffs_city_of_tshwane' => 'Tshwane', 'lum_tarrifs_ekhurhuleni' => 'Ekhurhuleni', 'lum_tariffs_rustenburg' => 'Rustenburg',
        'lum_tariffs_mkhondo' => 'Mkhondo', 'lum_tariffs_plett' => 'Plettenberg Bay', 'lum_tariffs_george' => 'George',
    ]);
    define('LUM_RATE_KEYS_ELEC', ['basic', 'kwh_std', 'kwh_peak', 'kwh_off', 'kva_demand', 'kva_network', 'amps']);
    define('LUM_RATE_KEYS_WATER', ['water_basic', 'water_tier_1', 'water_tier_2', 'water_tier_3', 'water_tier_4', 'water_tier_5', 'water_tier_6', 'water_tier_7']);
    define('LUM_RATE_KEYS_SEWER', ['sewer_basic']);
    define('LUM_RATE_KEYS_MUNICIPALITY', ['generator', 'water', 'sewer', 'comm_elec_kwh', 'comm_water_kl', 'comm_sewer_kl', 'rustenburg_shared_network_access_charge']);
    // Municipalities whose water and sewer rates depend on the water / sewer tariff
    define('LUM_RATE_SUBTARIFF_MUNICIPALITIES', ['Plettenberg Bay', 'George']);
    define('LUM_RATE_KEY_LABELS', [
        'basic' => 'Basic charge', 'kwh_std' => 'Energy (standard) per kWh', 'kwh_peak' => 'Energy (peak) per kWh', 'kwh_off' => 'Energy (off-peak) per kWh',
        'kva_demand' => 'Demand per kVA', 'kva_network' => 'Network access per kVA', 'amps' => 'Capacity per Amp',
        'generator' => 'Generator per kWh', 'water' => 'Water per kL', 'sewer' => 'Sewer per kL',
        'comm_elec_kwh' => 'Common area electricity per kWh', 'comm_water_kl' => 'Common area water per kL', 'comm_sewer_kl' => 'Common area sewer per kL',
        'rustenburg_shared_network_access_charge' => 'Shared network access per kVA',
        'water_basic' => 'Water basic charge', 'sewer_basic' => 'Sewer basic charge',
        'water_tier_1' => 'Water tier 1', 'water_tier_2' => 'Water tier 2', 'water_tier_3' => 'Water tier 3', 'water_tier_4' => 'Water tier 4',
        'water_tier_5' => 'Water tier 5', 'water_tier_6' => 'Water tier 6', 'water_tier_7' => 'Water tier 7',
        'is_tiered_water' => 'Tiered water (switch)',
    ]);
}

// =========================================================================
// The engine is kept in Reporting/engine, a file for each subject.
// Everything that included reporting-engine.php still works unchanged.
// =========================================================================
require_once __DIR__ . '/engine/general.php';
require_once __DIR__ . '/engine/meters.php';
require_once __DIR__ . '/engine/properties.php';
require_once __DIR__ . '/engine/tariffs.php';
require_once __DIR__ . '/engine/time.php';
