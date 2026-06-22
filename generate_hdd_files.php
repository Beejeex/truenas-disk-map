<?php
// generate_hdd_files.php — Generic disk-to-enclosure mapping.
//
// Pipeline:
//   1. lsscsi -g  -> disks and enclosures
//   2. If the HCTL target layout clearly exposes bays, use target ranges.
//   3. Otherwise, use lsscsi -t plus sg_ses --join SAS address matching.
//   4. Write HDD files: SERIAL|ENC_INDEX|PHYSICAL_SLOT|FILE_INDEX
//
// No sas3ircu. No serial prefix matching.

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

function tdm_build_hctl_slot_maps(array $disks, array $enclosures)
{
    $disks_by_domain = [];
    foreach ($disks as $disk)
    {
        $domain = tdm_hctl_domain_key($disk);
        if ($domain === '' || !isset($disk['target']) || $disk['target'] === null)
        {
            continue;
        }

        $disks_by_domain[$domain][] = $disk;
    }

    $enclosures_by_domain = [];
    foreach ($enclosures as $enc)
    {
        $domain = tdm_hctl_domain_key($enc);
        if ($domain === '' || !isset($enc['target']) || $enc['target'] === null)
        {
            continue;
        }

        $enclosures_by_domain[$domain][] = $enc;
    }

    $maps = [];
    foreach ($enclosures_by_domain as $domain => $domain_enclosures)
    {
        usort($domain_enclosures, function ($a, $b) {
            return ((int)$a['target']) <=> ((int)$b['target']);
        });

        $domain_disks = $disks_by_domain[$domain] ?? [];
        $domain_targets = [];
        foreach ($domain_disks as $disk)
        {
            if (isset($disk['target']) && $disk['target'] !== null)
            {
                $domain_targets[] = (int)$disk['target'];
            }
        }

        $candidate_maps = [];
        $covered_targets = [];
        $previous_enclosure_target = -1;
        $domain_is_clear = true;

        foreach ($domain_enclosures as $enc)
        {
            $enc_target = (int)$enc['target'];
            $range_start = $previous_enclosure_target + 1;
            $range_end = $enc_target - 1;
            $slot_count = $range_end - $range_start + 1;
            $previous_enclosure_target = $enc_target;

            if ($slot_count <= 0)
            {
                continue;
            }

            $assignments = array_fill(0, $slot_count, null);
            $present_targets = [];
            $slot_map_parts = [];
            $duplicate_slot = false;

            foreach ($domain_disks as $disk)
            {
                $target = (int)$disk['target'];
                if ($target < $range_start || $target > $range_end)
                {
                    continue;
                }

                $slot = $target - $range_start;
                if ($assignments[$slot] !== null)
                {
                    $duplicate_slot = true;
                    break;
                }

                $assignments[$slot] = $disk;
                $present_targets[] = $target;
                $covered_targets[] = $target;
            }

            if ($duplicate_slot || empty($present_targets))
            {
                $domain_is_clear = false;
                continue;
            }

            $empty_slots = [];
            for ($slot = 0; $slot < $slot_count; $slot++)
            {
                if ($assignments[$slot] === null)
                {
                    $empty_slots[] = $slot;
                    $slot_map_parts[] = $slot . '=EMPTY';
                }
                else
                {
                    $slot_map_parts[] = $slot . '=' . $assignments[$slot]['dev'];
                }
            }

            $candidate_maps[(int)$enc['index']] = [
                'assignments' => $assignments,
                'domain' => $domain,
                'range_start' => $range_start,
                'range_end' => $range_end,
                'slot_count' => $slot_count,
                'present_targets' => $present_targets,
                'empty_slots' => $empty_slots,
                'slot_map' => implode(', ', $slot_map_parts),
            ];
        }

        $uncovered_targets = array_values(array_diff(
            array_values(array_unique($domain_targets)),
            array_values(array_unique($covered_targets))
        ));

        if (!$domain_is_clear || !empty($uncovered_targets))
        {
            continue;
        }

        foreach ($candidate_maps as $enc_index => $map)
        {
            $map['accepted_reason'] = 'all disk targets in HCTL domain ' . $domain . ' are covered by enclosure-delimited bay ranges';
            $maps[$enc_index] = $map;
        }
    }

    return $maps;
}

$target_dir = __DIR__ . "/disk_data";
if (!is_dir($target_dir)) {
    @mkdir($target_dir, 0775, true);
}

// ── Phase 1: Detect enclosures ─────────────────────────────────────
$raw_lsscsi = "";
$lsscsi_code = 0;
$detected = tdm_detect_lsscsi($raw_lsscsi, $lsscsi_code);
$enclosures = $detected['enclosures'];

if ($lsscsi_code !== 0) {
    echo "[WARN] lsscsi failed (exit " . $lsscsi_code . ").\n";
    if (trim($raw_lsscsi) !== "") echo trim($raw_lsscsi) . "\n";
    exit(1);
}

if (empty($enclosures)) {
    echo "[WARN] No SES enclosures found. Nothing to map.\n";
    exit(0);
}

echo "[INFO] " . count($enclosures) . " SES enclosure(s) detected.\n";

// ── Phase 2: Get SAS transport addresses ───────────────────────────
$transport = tdm_parse_lsscsi_transport();
$disk_sas_map = $transport['disks'];

echo "[INFO] " . count($disk_sas_map) . " disk SAS addresses resolved.\n";
$hctl_slot_maps = tdm_build_hctl_slot_maps($detected['disks'], $enclosures);

// ── Phase 3+4: Per enclosure: read SES, match SAS addresses, write HDD ──
$total_rows = 0;
$file_index = 0;

foreach ($enclosures as $enc_index => $enc) {
    $sg = $enc['sg'];
    echo "[INFO] Enclosure " . $enc_index . ": " . $sg . " (" . ($enc['name'] ?? 'unknown') . ")\n";
    $ses_elements = [];

    $hctl_map = $hctl_slot_maps[(int)$enc['index']] ?? null;
    if ($hctl_map !== null) {
        echo "[INFO]   Generic HCTL target-range layout accepted.\n";
        echo "[INFO]     Evidence: " . $hctl_map['accepted_reason'] . ".\n";
        echo "[INFO]     Enclosure HCTL: [" . $enc['hctl'] . "] target " . (int)$enc['target'] . ".\n";
        echo "[INFO]     Bay target range: " . $hctl_map['range_start'] . ".." . $hctl_map['range_end'] . " (slot = target - " . $hctl_map['range_start'] . ").\n";
        echo "[INFO]     Present disk targets: " . tdm_format_number_ranges($hctl_map['present_targets']) . ".\n";
        echo "[INFO]     Empty slots inferred from missing targets: " . tdm_format_number_ranges($hctl_map['empty_slots']) . ".\n";
        echo "[INFO]     Slot map: " . $hctl_map['slot_map'] . ".\n";

        $slot_assignments = [];
        $populated = 0;
        foreach ($hctl_map['assignments'] as $slot => $disk) {
            if ($disk === null) {
                $slot_assignments[$slot] = null;
                continue;
            }

            $serial = tdm_get_smart_serial($disk['dev']);
            if ($serial === '') $serial = "DEV-" . basename($disk['dev']);
            $slot_assignments[$slot] = ['dev' => $disk['dev'], 'serial' => $serial];
            $populated++;
        }

        $total_slots = count($slot_assignments);
    }
    else {
    // Read SES element data
    $ses_result = tdm_parse_ses_join($sg);
    $ses_elements = $ses_result['elements'] ?? [];
    $enclosure_id = $ses_result['enclosure_id'] ?? '';
    if (empty($ses_elements)) {
        echo "[WARN]   Could not read SES elements — skipping.\n";
        continue;
    }

    $total_slots = count($ses_elements);
    $populated = 0;

    // Filter: enclosure self-reference element has SAS address == enclosure logical identifier
    if ($enclosure_id) echo "[INFO]   Enclosure ID: " . $enclosure_id . "\n";

    // Track which disks get matched (so we can find unmatched ones)
    $unmatched_disks = $disk_sas_map;
    $matched_by_sas = 0;

    // Match disks to SES elements by SAS address
    $slot_assignments = []; // element_index => ['dev'=>..., 'serial'=>...] or null for empty
    $enclosure_slot_index = null; // slot index that has enclosure SAS (firmware quirk)
    foreach ($ses_elements as $ei => $elem) {
        $elem_sas = $elem['sas_address'];
        // Detect enclosure self-reference slot — will handle after the loop
        if ($enclosure_id && strcasecmp($elem_sas, $enclosure_id) === 0) {
            $enclosure_slot_index = $ei;
            $slot_assignments[$ei] = null; // placeholder, may be filled by heuristic
            continue;
        }
        if ($elem_sas === '' || $elem_sas === '0x0') {
            $slot_assignments[$ei] = null;
            continue;
        }

        // Find disk with matching SAS address
        $matched = null;
        foreach ($unmatched_disks as $dev => $disk_sas) {
            if (strcasecmp($disk_sas, $elem_sas) === 0) {
                $serial = tdm_get_smart_serial($dev);
                if ($serial === '') $serial = "DEV-" . basename($dev);
                $matched = ['dev' => $dev, 'serial' => $serial];
                unset($unmatched_disks[$dev]);
                break;
            }
        }
        $slot_assignments[$ei] = $matched;
        if ($matched) {
            $populated++;
            $matched_by_sas++;
        }
    }

    // Heuristic: the SES element that reports the enclosure SAS address may
    // represent a real bay on some expanders. Keep the log explicit that this
    // assignment is inferred rather than a confirmed SES SAS match.
    if ($enclosure_slot_index !== null && count($unmatched_disks) === 1) {
        $dev = array_key_first($unmatched_disks);
        $serial = tdm_get_smart_serial($dev);
        if ($serial === '') $serial = "DEV-" . basename($dev);
        $slot_assignments[$enclosure_slot_index] = ['dev' => $dev, 'serial' => $serial, '_inferred' => true];
        unset($unmatched_disks[$dev]);
        $populated++;
        echo "[INFO]   SES self-reference element detected: element #" . $enclosure_slot_index . " reports enclosure ID " . $enclosure_id . ".\n";
        echo "[INFO]   SAS matching resolved " . $matched_by_sas . " disk(s); exactly one disk remained unmatched: " . $dev . " (SAS " . $disk_sas_map[$dev] . ").\n";
        echo "[INFO]   Inferred element #" . $enclosure_slot_index . " -> " . $dev . " (heuristic, not a confirmed SAS match).\n";
    } elseif ($enclosure_slot_index !== null && count($unmatched_disks) > 1) {
        // Multiple unmatched — leave enclosure slot as empty placeholder
        echo "[INFO]   SES self-reference element detected: element #" . $enclosure_slot_index . " reports enclosure ID " . $enclosure_id . ".\n";
        echo "[INFO]   Leaving it empty because " . count($unmatched_disks) . " unmatched disks remain.\n";
    }

    // Write any remaining unmatched disks to an unmapped file
    if (!empty($unmatched_disks)) {
        $um_path = $target_dir . "/disk_unmapped.txt";
        $um_fh = fopen($um_path, "w");
        if ($um_fh) {
            foreach ($unmatched_disks as $dev => $sas) {
                $serial = tdm_get_smart_serial($dev);
                if ($serial === '') $serial = "DEV-" . basename($dev);
                fwrite($um_fh, $serial . "|" . $dev . "|" . $sas . "\n");
            }
            fclose($um_fh);
            echo "[INFO]   " . count($unmatched_disks) . " unmapped disk(s) written to disk_unmapped.txt\n";
        }
    }
    }

    // Write HDD file
    $out_path = $target_dir . "/hdd_c_" . $file_index;
    $fh = fopen($out_path, "w");
    if (!$fh) {
        echo "[WARN]   Could not write " . $out_path . "\n";
        continue;
    }

    $rows = 0;
    foreach ($slot_assignments as $ei => $disk) {
        $serial = $disk ? $disk['serial'] : 'EMPTY';
        $slot_num = isset($ses_elements) && isset($ses_elements[$ei]['device_slot_number'])
            ? (int)$ses_elements[$ei]['device_slot_number']
            : (int)$ei;
        fwrite($fh, $serial . "|" . $enc_index . "|" . $slot_num . "|" . $file_index . "\n");
        $rows++;
    }
    fclose($fh);

    echo "[OK]   " . $rows . " slots (" . $populated . " populated, " . ($total_slots - $populated) . " empty) → " . basename($out_path) . "\n";
    $total_rows += $rows;
    $file_index++;
}

echo "\n[OK] HDD files generated. Total rows: " . $total_rows . ".\n";
