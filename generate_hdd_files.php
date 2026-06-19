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
    $ses_elements = tdm_parse_ses_join($sg);
    if (empty($ses_elements)) {
        echo "[WARN]   Could not read SES elements — skipping.\n";
        continue;
    }

    $total_slots = count($ses_elements);
    $populated = 0;

    // Find the enclosure's own SAS address (same as "attached SAS address" on all bays)
    // Count occurrences; the most frequent is the enclosure address, skip it.
    $sas_freq = [];
    foreach ($ses_elements as $elem) {
        $a = $elem['sas_address'];
        if ($a !== '' && $a !== '0x0') $sas_freq[$a] = ($sas_freq[$a] ?? 0) + 1;
    }
    arsort($sas_freq);
    $enclosure_sas = !empty($sas_freq) ? array_key_first($sas_freq) : '';
    if ($enclosure_sas && ($sas_freq[$enclosure_sas] ?? 0) < 2) $enclosure_sas = '';
    if ($enclosure_sas) echo "[INFO]   Enclosure self-address " . $enclosure_sas . " (appears " . $sas_freq[$enclosure_sas] . "×) will be excluded.\n";

    // Match disks to SES elements by SAS address
    $slot_assignments = []; // element_index => ['dev'=>..., 'serial'=>...] or null for empty
    foreach ($ses_elements as $ei => $elem) {
        $elem_sas = $elem['sas_address'];
        // Skip enclosure self-reference (same SAS as all bays' "attached" address)
        if ($enclosure_sas && $elem_sas === $enclosure_sas) continue;
        if ($elem_sas === '' || $elem_sas === '0x0') {
            $slot_assignments[$ei] = null;
            continue;
        }

        // Find disk with matching SAS address
        $matched = null;
        foreach ($disk_sas_map as $dev => $disk_sas) {
            if (strcasecmp($disk_sas, $elem_sas) === 0) {
                $serial = tdm_get_smart_serial($dev);
                if ($serial === '') $serial = "DEV-" . basename($dev);
                $matched = ['dev' => $dev, 'serial' => $serial];
                break;
            }
        }
        $slot_assignments[$ei] = $matched;
        if ($matched) $populated++;
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
