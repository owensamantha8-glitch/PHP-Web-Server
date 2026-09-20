<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Shared by the tenant registration and update forms: tariff drop-downs, property switches and municipality defaults

require_once __DIR__ . '/bootstrap.php';
lum_use('slips'); // Property settings and the tariff catalog

if (!defined('LUM_TENANT_FORM_STANDARD_TARIFFS')) {
    // Standard tariffs, offered unless the Tariff Catalog marks them inactive or not applicable
    define('LUM_TENANT_FORM_STANDARD_TARIFFS', [
        'electricity' => [
            'Tshwane' => ['City of Tshwane - TOU', 'City of Tshwane - Business', 'City of Tshwane - Business - L', 'City of Tshwane - Business - S', 'City of Tshwane - Non-Domestic - Three Phase Conventional', 'City of Tshwane - Low Voltage Demand', 'City of Tshwane - Low Voltage Demand Scale'],
            'Ekhurhuleni' => ['Ekhurhuleni - TOU', 'Ekhurhuleni - Tariff A', 'Ekhurhuleni - Tariff B', 'Ekhurhuleni - Tariff C'],
            'Rustenburg' => ['Rustenburg - Non-Domestic Conventional', 'Rustenburg - Bulk Supply and Rural 400V'],
            'Mkhondo' => ['Mkhondo Business (Less Than 80 (kVA))', 'Mkhondo Business (More Than 80 (kVA))', 'Mkhondo Industrial Small (Less than 50KVA)', 'Mkhondo Industrial (More than 50 KVA)'],
            'Plettenberg Bay' => ['Plettenberg Bay - 1 Phase 15A', 'Plettenberg Bay - 1 Phase 30A', 'Plettenberg Bay - 1 Phase 40A', 'Plettenberg Bay - 1 Phase 60A', 'Plettenberg Bay - 3 Phase 60A', 'Plettenberg Bay - 3 Phase 60A-63A', 'Plettenberg Bay - 3 Phase 100A', 'Plettenberg Bay - LV Electricity', 'Plettenberg Bay - Bitou TOU'],
            'George' => ['George - General Consumers', 'George - Bulk TOU (Medium Voltage)', 'George - Bulk TOU 2 (Medium Voltage)'],
        ],
        'water' => [
            'Tshwane' => ['City of Tshwane - Non Domestic'],
            'Ekhurhuleni' => ['Ekhurhuleni - Water Charge'],
            'Rustenburg' => ['Rustenburg - Commercial Tiered'],
            'Mkhondo' => ['Mkhondo Water Business'],
            'Plettenberg Bay' => ['Plettenberg Bay - Bitou Water Shops', 'Plettenberg Bay - Bitou Water Business', 'Plettenberg Bay - Bitou Water Restaurant'],
            'George' => ['George - Water (Industries/Businesses)'],
        ],
        'sewer' => [
            'Tshwane' => ['City of Tshwane - Sewer Charges - Non Domestic', 'City of Tshwane - Sewer Charges - Non Domestic (Business)'],
            'Ekhurhuleni' => ['Ekhurhuleni - Sewer Charge'],
            'Rustenburg' => ['Rustenburg - Sewer Basic Charge'],
            'Mkhondo' => ['Mkhondo Sewer', 'Mkhondo Business_M Sewer', 'Mkhondo Business Large Sewer'],
            'Plettenberg Bay' => ['Plettenberg Bay - Bitou Sewer Business', 'Plettenberg Bay - Bitou Sewer Restaurant'],
            'George' => ['George - Sewer Basic Fee'],
        ],
        'generator' => [
            'Tshwane' => ['City of Tshwane - Generator'],
            'Ekhurhuleni' => ['Ekhurhuleni - Generator'],
            'Rustenburg' => ['Rustenburg - Generator'],
            'Mkhondo' => ['Mkhondo - Generator'],
            'Plettenberg Bay' => ['Plettenberg Bay - Generator'],
            'George' => ['George - Generator'],
        ],
        'elec_common' => [
            'Tshwane' => ['City of Tshwane - Common Area'],
            'Ekhurhuleni' => ['Ekhurhuleni - Common Area'],
            'Rustenburg' => ['Rustenburg - Common Area'],
            'Mkhondo' => ['Mkhondo - Common Area'],
            'Plettenberg Bay' => ['Plettenberg Bay - Common Area'],
            'George' => ['George - Common Area'],
        ],
        'water_common' => [
            'Tshwane' => ['City of Tshwane - Water Common Area'],
            'Ekhurhuleni' => ['Ekhurhuleni - Water Common Area'],
            'Rustenburg' => ['Rustenburg - Water Common Area'],
            'Mkhondo' => ['Mkhondo - Water Common Area'],
            'Plettenberg Bay' => ['Plettenberg Bay - Water Common Area'],
            'George' => ['George - Water Common Area'],
        ],
    ]);
    define('LUM_TENANT_FORM_GROUP_LABELS', ['Tshwane' => 'City of Tshwane']);
}

// Options of a tariff drop-down: [municipality => [['value', 'muni', 'rank', 'note'], ...]] in display order.
// $current (update form): the tenant's tariff, always offered.
function lumTenantFormOptions($service, $current = null) {
    $catalog = function_exists('lumTariffCatalog') ? (lumTariffCatalog()[$service] ?? []) : [];
    $items = [];
    $rank = 0;
    foreach ((LUM_TENANT_FORM_STANDARD_TARIFFS[$service] ?? []) as $muni => $names) {
        foreach ($names as $name) {
            $key = lumLower(trim($name));
            $row = $catalog[$key] ?? null;
            $rank++;
            if ($row && (empty($row['active']) || !empty($row['not_applicable']))) continue; // Hidden on the Tariff Catalog
            $cat_muni = $row ? trim((string)$row['municipality']) : '';
            $items[$key] = ['value' => $row ? $row['tariff_name'] : $name, 'muni' => ($cat_muni !== '' ? $cat_muni : $muni), 'rank' => $rank, 'note' => ''];
        }
    }
    foreach ($catalog as $key => $row) {
        if (isset($items[$key]) || empty($row['active']) || !empty($row['not_applicable'])) continue;
        $items[$key] = ['value' => $row['tariff_name'], 'muni' => trim((string)$row['municipality']), 'rank' => 100000, 'note' => ''];
    }
    $cur = trim((string)$current);
    if ($cur !== '' && !is_na($cur) && !isset($items[lumLower($cur)])) {
        $items[lumLower($cur)] = ['value' => $cur, 'muni' => (string)lumTariffMunicipality($cur, $service), 'rank' => 200000,
                                  'note' => ' (current - no longer offered)'];
    }

    // Order: municipality, standard order, then name
    $muni_order = array_flip(LUM_TARIFF_MUNICIPALITIES);
    uasort($items, function ($a, $b) use ($muni_order) {
        $ma = $muni_order[$a['muni']] ?? 999;
        $mb = $muni_order[$b['muni']] ?? 999;
        if ($ma !== $mb) return $ma <=> $mb;
        if ($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
        return strcasecmp($a['value'], $b['value']);
    });
    $groups = [];
    foreach ($items as $item) $groups[$item['muni']][] = $item;
    return $groups;
}

// Does an electricity tariff have a demand or network access (kVA) rate? (rate map, otherwise the tariff-name rules)
function lumTenantFormHasDemand($name, $muni) {
    static $L = null;
    static $cache = [];
    $k = $name . '|' . $muni;
    if (isset($cache[$k])) return $cache[$k];
    if ($L === null) {
        list($L) = function_exists('lumRateProbeLedgers') ? lumRateProbeLedgers() : [[]];
    }
    $r = function_exists('lumRateMapCalc') ? lumRateMapCalc($name, $muni, '', '', $L) : null;
    if ($r === null && function_exists('lumRateProbeCall')) $r = lumRateProbeCall($name, $muni, '', '', $L);
    return $cache[$k] = ($r !== null && (($r['kva_demand'] ?? 0) != 0 || ($r['kva_network'] ?? 0) != 0));
}

function lumTenantFormTariffOptions($service, $current = null) {
    $cur = trim((string)$current);
    $na_selected = ($cur === '' || is_na($cur));
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    echo '<option value="Not applicable"' . ($na_selected ? ' selected' : '') . '>Not applicable</option>' . "\n";
    foreach (lumTenantFormOptions($service, $current) as $muni => $items) {
        $label = ($muni === '') ? 'Other' : (LUM_TENANT_FORM_GROUP_LABELS[$muni] ?? $muni);
        echo '<optgroup label="' . $h($label) . '">' . "\n";
        foreach ($items as $item) {
            $attrs = ' data-muni="' . $h($item['muni']) . '"';
            if ($service === 'electricity' && lumTenantFormHasDemand($item['value'], $item['muni'])) $attrs .= ' data-demand="1"';
            $sel = (!$na_selected && lumLower($item['value']) === lumLower($cur)) ? ' selected' : '';
            echo '    <option value="' . $h($item['value']) . '"' . $attrs . $sel . '>' . $h($item['value'] . $item['note']) . '</option>' . "\n";
        }
        echo '</optgroup>' . "\n";
    }
}

// Default water, sewer, generator and common area tariff per municipality (first offered tariff of each)
function lumTenantFormMunicipalityDefaults() {
    $out = [];
    foreach (['water', 'sewer', 'generator', 'elec_common', 'water_common'] as $service) {
        foreach (lumTenantFormOptions($service) as $muni => $items) {
            if ($muni !== '' && !isset($out[$muni][$service]) && !empty($items)) $out[$muni][$service] = $items[0]['value'];
        }
    }
    return $out;
}

// data-* attributes of a property option (Billing Settings switches)
function lumTenantFormPropertyAttrs($property) {
    $s = function_exists('lumPropertySettings') ? lumPropertySettings($property) : [];
    return ' data-refuse="' . (!empty($s['charges_refuse']) ? '1' : '0') . '" data-nac="' . (!empty($s['charges_shared_nac']) ? '1' : '0') . '"';
}