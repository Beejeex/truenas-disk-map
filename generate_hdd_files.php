<?php
// generate_hdd_files.php - generic enclosure-first disk mapping.
//
// Pipeline:
//   1. lsscsi -g discovers visible disks and SES enclosure devices.
//   2. sg_ses --join on each enclosure discovers bay count and slot numbers.
//   3. HCTL is used only when it agrees with SES, or as a clearly logged hint.
//   4. lsscsi -t SAS addresses are used when available to confirm slot matches.
//   5. Write HDD files: SERIAL|ENC_INDEX|PHYSICAL_SLOT|FILE_INDEX

require_once __DIR__ . "/hardware_helpers.php";

function tdm_hctl_domain_key(array $row)
{
    if (!isset($row['host']) || !isset($row['channel']) || $row['host'] === null || $row['channel'] === null)
    {
        return '';
    }

    return (int)$row['host'] . ':' . (int)$row['channel'];
}

function tdm_format_number_ranges(array $numbers)
{
    $numbers = array_values(array_unique(array_map('intval', $numbers)));
    sort($numbers, SORT_NUMERIC);

    if (empty($numbers))
    {
        return 'none';
    }

    $ranges = [];
    $start = $numbers[0];
    $prev = $numbers[0];

    for ($i = 1; $i < count($numbers); $i++)
    {
        $n = $numbers[$i];
        if ($n === $prev + 1)
        {
            $prev = $n;
            continue;
        }

        $ranges[] = ($start === $prev) ? (string)$start : ($start . '-' . $prev);
        $start = $n;
        $prev = $n;
    }

    $ranges[] = ($start === $prev) ? (string)$start : ($start . '-' . $prev);

    return implode(',', $ranges);
}

function tdm_ses_status_is_empty(array $slot)
{
    $status = strtolower(trim($slot['status'] ?? ''));
    return $status === 'not installed';
}

function tdm_ses_status_is_installed(array $slot)
{
    if (!empty($slot['installed']))
    {
        return true;
    }

    $status = strtolower(trim($slot['status'] ?? ''));
    return $status === 'ok' || $status === 'installed';
}

function tdm_sas_is_real($sas)
{
    $sas = strtolower(trim((string)$sas));
    return $sas !== '' && $sas !== '0x0';
}

function tdm_build_ses_inventory(array $ses_result, array $enc)
{
    $slots = [];
    $duplicates = [];
    $self_refs = [];
    $enclosure_id = $ses_result['enclosure_id'] ?? '';
    $enc_target = isset($enc['target']) && $enc['target'] !== null ? (int)$enc['target'] : null;

    foreach (($ses_result['elements'] ?? []) as $elem)
    {
        $slot = isset($elem['device_slot_number']) ? (int)$elem['device_slot_number'] : (int)$elem['index'];
        $sas = strtolower(trim($elem['sas_address'] ?? ''));

        if ($enc_target !== null &&
            $enclosure_id !== '' &&
            $slot === $enc_target &&
            strcasecmp($sas, $enclosure_id) === 0)
        {
            $self_refs[] = $slot;
            continue;
        }

        if (isset($slots[$slot]))
        {
            $duplicates[] = $slot;
            continue;
        }

        $elem['device_slot_number'] = $slot;
        $slots[$slot] = $elem;
    }

    ksort($slots, SORT_NUMERIC);

    return [
        'slots' => $slots,
        'duplicates' => $duplicates,
        'self_refs' => $self_refs,
        'enclosure_id' => $enclosure_id,
    ];
}

function tdm_hctl_range_for_enclosure(array $enc, array $enclosures, array $disks)
{
    $domain = tdm_hctl_domain_key($enc);
    if ($domain === '' || !isset($enc['target']) || $enc['target'] === null)
    {
        return null;
    }

    $domain_enclosures = [];
    foreach ($enclosures as $candidate)
    {
        if (tdm_hctl_domain_key($candidate) === $domain && isset($candidate['target']) && $candidate['target'] !== null)
        {
            $domain_enclosures[] = $candidate;
        }
    }

    usort($domain_enclosures, function ($a, $b) {
        return ((int)$a['target']) <=> ((int)$b['target']);
    });

    $range_start = 0;
    foreach ($domain_enclosures as $candidate)
    {
        if ((int)$candidate['index'] === (int)$enc['index'])
        {
            break;
        }

        $range_start = (int)$candidate['target'] + 1;
    }

    $range_end = (int)$enc['target'] - 1;
    $slot_count = $range_end - $range_start + 1;
    if ($slot_count <= 0)
    {
        return null;
    }

    $range_disks = [];
    $present_targets = [];
    foreach ($disks as $disk)
    {
        if (tdm_hctl_domain_key($disk) !== $domain || !isset($disk['target']) || $disk['target'] === null)
        {
            continue;
        }

        $target = (int)$disk['target'];
        if ($target < $range_start || $target > $range_end)
        {
            continue;
        }

        $range_disks[$target] = $disk;
        $present_targets[] = $target;
    }

    return [
        'domain' => $domain,
        'range_start' => $range_start,
        'range_end' => $range_end,
        'slot_count' => $slot_count,
        'disks_by_target' => $range_disks,
        'present_targets' => $present_targets,
    ];
}

function tdm_hctl_range_agrees_with_ses(array $range = null, array $slots)
{
    if ($range === null)
    {
        return [false, 'no enclosure-delimited HCTL range is available'];
    }

    $slot_numbers = array_keys($slots);
    sort($slot_numbers, SORT_NUMERIC);

    if (count($slot_numbers) !== (int)$range['slot_count'])
    {
        return [
            false,
            'HCTL candidate range has ' . (int)$range['slot_count'] . ' slot(s), but SES reports ' . count($slot_numbers) . ' slot(s)'
        ];
    }

    foreach ($range['disks_by_target'] as $target => $disk)
    {
        $position = (int)$target - (int)$range['range_start'];
        if (!isset($slot_numbers[$position]))
        {
            return [false, 'HCTL target ' . $target . ' has no corresponding SES slot position'];
        }

        $slot = $slot_numbers[$position];
        if (isset($slots[$slot]) && tdm_ses_status_is_empty($slots[$slot]))
        {
            return [
                false,
                'HCTL target ' . $target . ' maps to SES slot ' . $slot . ', but SES says that slot is not installed'
            ];
        }
    }

    return [true, 'HCTL range slot count and occupied targets agree with SES'];
}

function tdm_disk_label(array $disk)
{
    $target = isset($disk['target']) && $disk['target'] !== null ? (int)$disk['target'] : '?';
    return $disk['dev'] . ' [target ' . $target . ']';
}

function tdm_assign_disk_to_slot(array &$ctx, $slot, array $disk, $method, array &$assigned_devs)
{
    $dev = $disk['dev'];
    if (isset($assigned_devs[$dev]))
    {
        return false;
    }

    if (isset($ctx['assignments'][$slot]) && $ctx['assignments'][$slot] !== null)
    {
        return false;
    }

    $serial = tdm_get_smart_serial($dev);
    if ($serial === '')
    {
        $serial = 'DEV-' . basename($dev);
    }

    $ctx['assignments'][$slot] = [
        'dev' => $dev,
        'serial' => $serial,
        'method' => $method,
    ];
    $assigned_devs[$dev] = [
        'enclosure' => $ctx['enc']['index'],
        'slot' => $slot,
        'method' => $method,
    ];

    return true;
}

function tdm_exact_hctl_slot_candidates(array $contexts, array $disk)
{
    $target = isset($disk['target']) && $disk['target'] !== null ? (int)$disk['target'] : null;
    $domain = tdm_hctl_domain_key($disk);
    if ($target === null || $domain === '')
    {
        return [];
    }

    $candidates = [];
    foreach ($contexts as $ctx_index => $ctx)
    {
        if (tdm_hctl_domain_key($ctx['enc']) !== $domain)
        {
            continue;
        }

        if (!isset($ctx['slots'][$target]))
        {
            continue;
        }

        if (!tdm_ses_status_is_installed($ctx['slots'][$target]))
        {
            continue;
        }

        $candidates[] = [
            'context_index' => $ctx_index,
            'slot' => $target,
        ];
    }

    return $candidates;
}

$target_dir = __DIR__ . '/disk_data';
if (!is_dir($target_dir))
{
    @mkdir($target_dir, 0775, true);
}

// Phase 1: find visible disks and backplanes.
$raw_lsscsi = '';
$lsscsi_code = 0;
$detected = tdm_detect_lsscsi($raw_lsscsi, $lsscsi_code);
$enclosures = $detected['enclosures'];
$disks = $detected['disks'];

if ($lsscsi_code !== 0)
{
    echo '[WARN] lsscsi failed (exit ' . $lsscsi_code . ").\n";
    if (trim($raw_lsscsi) !== '') echo trim($raw_lsscsi) . "\n";
    exit(1);
}

if (empty($enclosures))
{
    echo "[WARN] No SES enclosures found. Nothing to map.\n";
    exit(0);
}

echo '[INFO] ' . count($enclosures) . " SES enclosure(s) detected by lsscsi -g.\n";
foreach ($enclosures as $enc)
{
    echo '[INFO]   Backplane ' . $enc['index'] . ': ' . $enc['sg'] . ' [' . $enc['hctl'] . '] ' . ($enc['name'] ?? 'unknown') . "\n";
}
echo '[INFO] ' . count($disks) . " disk device(s) visible in lsscsi -g.\n";

// Phase 2: ask each backplane for its own bay inventory.
$contexts = [];
foreach ($enclosures as $enc)
{
    echo '[INFO] Reading SES bay inventory from ' . $enc['sg'] . "...\n";
    $ses_result = tdm_parse_ses_join($enc['sg']);
    $inventory = tdm_build_ses_inventory($ses_result, $enc);
    $slots = $inventory['slots'];
    $slot_numbers = array_keys($slots);

    $installed_slots = [];
    $empty_slots = [];
    foreach ($slots as $slot => $slot_info)
    {
        if (tdm_ses_status_is_installed($slot_info))
        {
            $installed_slots[] = $slot;
        }
        elseif (tdm_ses_status_is_empty($slot_info))
        {
            $empty_slots[] = $slot;
        }
    }

    echo '[INFO]   SES enclosure ID: ' . ($inventory['enclosure_id'] !== '' ? $inventory['enclosure_id'] : 'not reported') . "\n";
    echo '[INFO]   SES slots reported: ' . count($slots) . ' (' . tdm_format_number_ranges($slot_numbers) . ").\n";
    echo '[INFO]   SES installed slots: ' . tdm_format_number_ranges($installed_slots) . ".\n";
    echo '[INFO]   SES empty/not-installed slots: ' . tdm_format_number_ranges($empty_slots) . ".\n";
    if (!empty($inventory['self_refs']))
    {
        echo '[INFO]   Ignored SES self-reference slot(s): ' . tdm_format_number_ranges($inventory['self_refs']) . ".\n";
    }
    if (!empty($inventory['duplicates']))
    {
        echo '[WARN]   Duplicate SES device slot number(s) ignored: ' . tdm_format_number_ranges($inventory['duplicates']) . ".\n";
    }

    $assignments = [];
    foreach ($slots as $slot => $_)
    {
        $assignments[$slot] = null;
    }

    $contexts[] = [
        'enc' => $enc,
        'ses_result' => $ses_result,
        'slots' => $slots,
        'assignments' => $assignments,
        'hctl_range' => null,
        'range_accepted' => false,
        'range_reason' => '',
    ];
}

// Phase 3: collect SAS transport addresses when the platform exposes them.
$transport = tdm_parse_lsscsi_transport();
$disk_sas_map = $transport['disks'];
echo '[INFO] ' . count($disk_sas_map) . " disk SAS address(es) resolved by lsscsi -t.\n";
if (empty($disk_sas_map))
{
    echo "[INFO]   SAS-address matching will be skipped; lsscsi -t returned no disk SAS addresses.\n";
}

// Phase 4: validate HCTL ranges against SES slot inventory.
foreach ($contexts as $ctx_index => &$ctx)
{
    $range = tdm_hctl_range_for_enclosure($ctx['enc'], $enclosures, $disks);
    $ctx['hctl_range'] = $range;
    list($accepted, $reason) = tdm_hctl_range_agrees_with_ses($range, $ctx['slots']);
    $ctx['range_accepted'] = $accepted;
    $ctx['range_reason'] = $reason;

    echo '[INFO] HCTL validation for ' . $ctx['enc']['sg'] . ' [' . $ctx['enc']['hctl'] . "]:\n";
    if ($range === null)
    {
        echo '[INFO]   Candidate range: none. ' . $reason . ".\n";
        continue;
    }

    echo '[INFO]   Candidate bay target range: ' . $range['range_start'] . '..' . $range['range_end'] . ' in HCTL domain ' . $range['domain'] . ".\n";
    echo '[INFO]   Present disk targets in candidate range: ' . tdm_format_number_ranges($range['present_targets']) . ".\n";
    echo '[INFO]   SES agreement: ' . ($accepted ? 'yes' : 'no') . ' - ' . $reason . ".\n";
}
unset($ctx);

$assigned_devs = [];

// Phase 5a: if HCTL range agrees with SES, use it as a complete slot map.
foreach ($contexts as $ctx_index => &$ctx)
{
    if (!$ctx['range_accepted'] || $ctx['hctl_range'] === null)
    {
        continue;
    }

    $slot_numbers = array_keys($ctx['slots']);
    sort($slot_numbers, SORT_NUMERIC);
    $mapped = 0;

    foreach ($ctx['hctl_range']['disks_by_target'] as $target => $disk)
    {
        $position = (int)$target - (int)$ctx['hctl_range']['range_start'];
        if (!isset($slot_numbers[$position]))
        {
            continue;
        }

        $slot = $slot_numbers[$position];
        if (tdm_assign_disk_to_slot($ctx, $slot, $disk, 'hctl-range', $assigned_devs))
        {
            $mapped++;
        }
    }

    echo '[INFO]   HCTL range mapped ' . $mapped . ' disk(s) for ' . $ctx['enc']['sg'] . ".\n";
}
unset($ctx);

// Phase 5b: use SAS addresses for any remaining slots/disks.
if (!empty($disk_sas_map))
{
    foreach ($contexts as $ctx_index => &$ctx)
    {
        $mapped = 0;
        foreach ($ctx['slots'] as $slot => $slot_info)
        {
            if ($ctx['assignments'][$slot] !== null)
            {
                continue;
            }

            $slot_sas = strtolower(trim($slot_info['sas_address'] ?? ''));
            if (!tdm_sas_is_real($slot_sas))
            {
                continue;
            }

            foreach ($disk_sas_map as $dev => $disk_sas)
            {
                if (isset($assigned_devs[$dev]))
                {
                    continue;
                }

                if (strcasecmp($slot_sas, $disk_sas) === 0)
                {
                    $disk = null;
                    foreach ($disks as $candidate)
                    {
                        if ($candidate['dev'] === $dev)
                        {
                            $disk = $candidate;
                            break;
                        }
                    }

                    if ($disk !== null && tdm_assign_disk_to_slot($ctx, $slot, $disk, 'sas-address', $assigned_devs))
                    {
                        $mapped++;
                    }
                    break;
                }
            }
        }

        echo '[INFO]   SAS-address matching mapped ' . $mapped . ' disk(s) for ' . $ctx['enc']['sg'] . ".\n";
    }
    unset($ctx);
}

// Phase 5c: HCTL exact slot validation for platforms without lsscsi -t SAS data.
foreach ($disks as $disk)
{
    if (isset($assigned_devs[$disk['dev']]))
    {
        continue;
    }

    $candidates = tdm_exact_hctl_slot_candidates($contexts, $disk);
    if (count($candidates) === 1)
    {
        $candidate = $candidates[0];
        $ctx_index = $candidate['context_index'];
        $slot = $candidate['slot'];
        if (tdm_assign_disk_to_slot($contexts[$ctx_index], $slot, $disk, 'hctl-target-equals-ses-slot', $assigned_devs))
        {
            echo '[INFO]   HCTL exact-slot validation mapped ' . tdm_disk_label($disk) . ' to ' . $contexts[$ctx_index]['enc']['sg'] . ' slot ' . $slot . ".\n";
        }
    }
    elseif (count($candidates) > 1)
    {
        $bits = [];
        foreach ($candidates as $candidate)
        {
            $bits[] = $contexts[$candidate['context_index']]['enc']['sg'] . ' slot ' . $candidate['slot'];
        }
        echo '[WARN]   HCTL exact-slot validation is ambiguous for ' . tdm_disk_label($disk) . ': ' . implode(', ', $bits) . ".\n";
    }
}

// Phase 6: write HDD source files from SES slot inventory.
$total_rows = 0;
$file_index = 0;
$unmapped = [];
$enclosure_domains = [];
foreach ($contexts as $ctx)
{
    $domain = tdm_hctl_domain_key($ctx['enc']);
    if ($domain !== '')
    {
        $enclosure_domains[$domain] = true;
    }
}

foreach ($contexts as $ctx)
{
    if (empty($ctx['slots']))
    {
        echo '[WARN]   No SES slots available for ' . $ctx['enc']['sg'] . '; skipping HDD file generation for this enclosure.' . "\n";
        continue;
    }

    $out_path = $target_dir . '/hdd_c_' . $file_index;
    $fh = fopen($out_path, 'w');
    if (!$fh)
    {
        echo '[WARN]   Could not write ' . $out_path . "\n";
        continue;
    }

    $rows = 0;
    $populated = 0;
    $installed_unmapped = 0;

    foreach ($ctx['slots'] as $slot => $slot_info)
    {
        $assignment = $ctx['assignments'][$slot] ?? null;
        if ($assignment !== null)
        {
            $serial = $assignment['serial'];
            $populated++;
        }
        elseif (tdm_ses_status_is_installed($slot_info))
        {
            $serial = 'UNKNOWN';
            $installed_unmapped++;
        }
        else
        {
            $serial = 'EMPTY';
        }

        fwrite($fh, $serial . '|' . $ctx['enc']['index'] . '|' . $slot . '|' . $file_index . "\n");
        $rows++;
    }

    fclose($fh);
    echo '[OK]   ' . $rows . ' SES slots (' . $populated . ' mapped, ' . $installed_unmapped . ' installed-unmapped, ' . ($rows - $populated - $installed_unmapped) . ' empty) -> ' . basename($out_path) . "\n";
    $total_rows += $rows;
    $file_index++;
}

foreach ($disks as $disk)
{
    $domain = tdm_hctl_domain_key($disk);
    if (!isset($assigned_devs[$disk['dev']]) && isset($enclosure_domains[$domain]))
    {
        $unmapped[] = $disk;
    }
}

if (!empty($unmapped))
{
    $um_path = $target_dir . '/disk_unmapped.txt';
    $um_fh = fopen($um_path, 'w');
    if ($um_fh)
    {
        foreach ($unmapped as $disk)
        {
            $serial = tdm_get_smart_serial($disk['dev']);
            if ($serial === '') $serial = 'DEV-' . basename($disk['dev']);
            $sas = $disk_sas_map[$disk['dev']] ?? '';
            fwrite($um_fh, $serial . '|' . $disk['dev'] . '|' . $sas . '|' . $disk['hctl'] . "\n");
        }
        fclose($um_fh);
    }

    $labels = [];
    foreach ($unmapped as $disk)
    {
        $labels[] = tdm_disk_label($disk);
    }
    echo '[WARN] ' . count($unmapped) . ' visible disk(s) could not be mapped to a SES slot: ' . implode(', ', $labels) . ".\n";
    echo '[INFO]   Unmapped disk details written to disk_unmapped.txt.' . "\n";
}

echo "\n[OK] HDD files generated. Total rows: " . $total_rows . ".\n";
?>
