<?php
// generate_hdd_files.php — Unified SAS-address-based disk-to-enclosure mapping.
//
// Pipeline:
//   1. lsscsi -g  → enclosures (SES /dev/sgX)
//   2. lsscsi -t  → SAS addresses per disk
//   3. sg_ses --join → SES elements per enclosure (bay count + SAS address per bay)
//   4. Match: SES element SAS address ↔ disk SAS address
//   5. Write HDD files: SERIAL|ENC_INDEX|PHYSICAL_SLOT|FILE_INDEX
//
// No sas3ircu. No SCSI target guessing. No serial prefix matching.
// SAS addresses are hardware-assigned and never change.

require_once __DIR__ . "/hardware_helpers.php";

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

// ── Phase 3+4: Per enclosure: read SES, match SAS addresses, write HDD ──
$total_rows = 0;
$file_index = 0;

foreach ($enclosures as $enc_index => $enc) {
    $sg = $enc['sg'];
    echo "[INFO] Enclosure " . $enc_index . ": " . $sg . " (" . ($enc['name'] ?? 'unknown') . ")\n";

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
        if ($matched) $populated++;
    }

    // Heuristic: enclosure self-reference slot may actually be a real bay
    // with a misreported SAS address (LSI SAS2X36 firmware quirk).
    // If exactly 1 disk remains unmatched, assign it to this slot.
    if ($enclosure_slot_index !== null && count($unmatched_disks) === 1) {
        $dev = array_key_first($unmatched_disks);
        $serial = tdm_get_smart_serial($dev);
        if ($serial === '') $serial = "DEV-" . basename($dev);
        $slot_assignments[$enclosure_slot_index] = ['dev' => $dev, 'serial' => $serial, '_inferred' => true];
        unset($unmatched_disks[$dev]);
        $populated++;
        echo "[INFO]   Enclosure slot #" . $enclosure_slot_index . " assigned unmatched disk " . $dev . " (SAS " . $disk_sas_map[$dev] . ") — firmware quirk.\n";
    } elseif ($enclosure_slot_index !== null && count($unmatched_disks) > 1) {
        // Multiple unmatched — leave enclosure slot as empty placeholder
        echo "[INFO]   Enclosure slot #" . $enclosure_slot_index . " skipped (" . count($unmatched_disks) . " unmatched disks remain).\n";
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
        fwrite($fh, $serial . "|" . $enc_index . "|" . $ei . "|" . $file_index . "\n");
        $rows++;
    }
    fclose($fh);

    echo "[OK]   " . $rows . " slots (" . $populated . " populated, " . ($total_slots - $populated) . " empty) → " . basename($out_path) . "\n";
    $total_rows += $rows;
    $file_index++;
}

echo "\n[OK] HDD files generated. Total rows: " . $total_rows . ".\n";
