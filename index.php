<?php

require_once __DIR__ . "/i18n.php";

$app_version = "0.1.6";
// Prefer git commit hash from build (stored outside web root to survive volume mounts)
$version_file = "/version.txt";
if (is_file($version_file)) {
    $hash = trim(file_get_contents($version_file));
    if ($hash !== '' && $hash !== 'dev') {
        $app_version = $hash;
    }
}



function human_time_diff($secs){
    $secs = (int)$secs;
    if ($secs < 60) return $secs . 's';
    $mins = floor($secs/60);
    if ($mins < 60) return $mins . 'm';
    $hrs  = floor($mins/60);
    if ($hrs < 24)  return $hrs . 'h ' . ($mins%60) . 'm';
    $days = floor($hrs/24);
    return $days . 'd ' . ($hrs%24) . 'h';
}

function tdm_js($value)
{
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    return htmlspecialchars($json === false ? 'null' : $json, ENT_QUOTES, 'UTF-8');
}

function tdm_ses_meta_from_parts(array $parts)
{
    return array(
        'model' => isset($parts[7]) ? trim($parts[7]) : '',
        'capacity' => isset($parts[8]) ? trim($parts[8]) : '',
        'firmware' => isset($parts[9]) ? trim($parts[9]) : '',
        'power_hours' => isset($parts[10]) ? (int)$parts[10] : 0,
        'temperature_c' => isset($parts[11]) ? trim($parts[11]) : '',
        'reallocated' => isset($parts[12]) ? (int)$parts[12] : 0,
        'pending' => isset($parts[13]) ? (int)$parts[13] : 0,
        'uncorrectable' => isset($parts[14]) ? (int)$parts[14] : 0,
        'crc_errors' => isset($parts[15]) ? (int)$parts[15] : 0,
        'ata_errors' => isset($parts[16]) ? (int)$parts[16] : 0,
        'load_cycle_count' => isset($parts[17]) ? (int)$parts[17] : 0,
        'reported_uncorrectable' => isset($parts[18]) ? (int)$parts[18] : 0,
        'end_to_end_errors' => isset($parts[19]) ? (int)$parts[19] : 0,
        'reallocation_events' => isset($parts[20]) ? (int)$parts[20] : 0,
        'spin_retry_count' => isset($parts[21]) ? (int)$parts[21] : 0,
        'calibration_retry_count' => isset($parts[22]) ? (int)$parts[22] : 0,
        'command_timeout' => isset($parts[23]) ? (int)$parts[23] : 0,
        'high_fly_writes' => isset($parts[24]) ? (int)$parts[24] : 0,
    );
}

function tdm_status_name($smart)
{
    $smart = trim((string)$smart);
    if ($smart === '' || strtoupper($smart) === 'X') return 'UNKNOWN';
    if (preg_match('/^([A-Z_]+)/', strtoupper($smart), $m))
    {
        return $m[1];
    }
    return 'UNKNOWN';
}

function tdm_status_slug($status)
{
    $slug = strtolower(str_replace('_', '-', (string)$status));
    $slug = preg_replace('/[^a-z0-9-]+/', '', $slug);
    return $slug !== '' ? $slug : 'unknown';
}

function tdm_status_class($smart)
{
    $status = tdm_status_name($smart);
    if ($status === 'DEAD' || $status === 'CRITICAL') return 'smart-bad';
    if ($status === 'DANGEROUS') return 'smart-danger';
    if ($status === 'SUSPECT') return 'smart-warn';
    if ($status === 'INTERFACE') return 'smart-interface';
    if ($status === 'MAINTENANCE') return 'smart-maintenance';
    if ($status === 'UNKNOWN') return 'smart-unknown';
    return 'smart-ok';
}

function tdm_hours_label($hours)
{
    $hours = (int)$hours;
    if ($hours <= 0) return '';
    return number_format($hours) . 'h';
}

function tdm_disk_meta_summary(array $meta)
{
    $bits = array();
    if ($meta['model'] !== '') $bits[] = $meta['model'];
    if ($meta['capacity'] !== '') $bits[] = $meta['capacity'];
    if ((int)$meta['power_hours'] > 0) $bits[] = tdm_hours_label($meta['power_hours']);
    if ($meta['temperature_c'] !== '') $bits[] = (int)$meta['temperature_c'] . 'C';
    return implode(' | ', $bits);
}

function tdm_disk_counter_summary(array $meta)
{
    $bits = array(
        'R=' . (int)$meta['reallocated'],
        'P=' . (int)$meta['pending'],
        'U=' . (int)$meta['uncorrectable'],
        'CRC=' . (int)$meta['crc_errors'],
        'ATA=' . (int)$meta['ata_errors'],
        'R187=' . (int)$meta['reported_uncorrectable'],
        'E2E=' . (int)$meta['end_to_end_errors'],
        'Ev=' . (int)$meta['reallocation_events'],
    );

    if ((int)$meta['load_cycle_count'] > 0)
    {
        $bits[] = 'Load=' . number_format((int)$meta['load_cycle_count']);
    }

    if ($meta['temperature_c'] !== '')
    {
        $bits[] = 'Temp=' . (int)$meta['temperature_c'] . 'C';
    }

    if ((int)$meta['spin_retry_count'] > 0) $bits[] = 'Spin=' . (int)$meta['spin_retry_count'];
    if ((int)$meta['calibration_retry_count'] > 0) $bits[] = 'Cal=' . (int)$meta['calibration_retry_count'];
    if ((int)$meta['command_timeout'] > 0) $bits[] = 'Cmd=' . (int)$meta['command_timeout'];
    if ((int)$meta['high_fly_writes'] > 0) $bits[] = 'Fly=' . (int)$meta['high_fly_writes'];

    return implode(' / ', $bits);
}



// ======================== LOAD UNUSED DISKS ========================
$unused_by_disk = array(); // ex: 'sdah' => true

$unused_file = __DIR__ . "/disk_data/disk_unused_no_pool.txt";
if (is_file($unused_file)) {
    $lines_unused = file($unused_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines_unused)) {
        foreach ($lines_unused as $u) {
            $u = trim($u);
            if ($u !== '') {
                // Store disk names without /dev/; the file contains values such as "sdX".
                $unused_by_disk[$u] = true;
            }
        }
    }
}

// ======================== HELPERS LAYOUT ========================
function get_controller_from_file($filepath)
{
    if (preg_match('~hdd_c_(\d+)_~', basename($filepath), $m)) {
        return (int)$m[1];
    }
    return 0;
}

function cols_for_file($filepath)
{
    // Auto-detect from first disk: 3.5" (TB capacity) → horizontal 4 cols
    $fh = @fopen($filepath, 'r');
    if ($fh) {
        $first = fgets($fh);
        fclose($fh);
        if ($first !== false) {
            $parts = explode('|', $first);
            $capacity = isset($parts[8]) ? trim($parts[8]) : '';
            if (stripos($capacity, 'TB') !== false) return 4;
        }
    }

    // Count total disks: ≤4 disks → 4 cols, many disks → 15 cols
    $lines = @file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $count = is_array($lines) ? count($lines) : 0;
    if ($count <= 4) return 4;
    return 15;
}

// c_0: display by columns, with higher slot numbers on the top row.
function build_display_order_colwise_top_high($totalSlots, $cols)
{
    if ($totalSlots < 1) return [];
    $rows = (int)ceil($totalSlots / $cols);
    $order = [];
    for ($r = 1; $r <= $rows; $r++) {
        for ($c = 1; $c <= $cols; $c++) {
            $n = ($rows - $r + 1) + ($c - 1) * $rows;
            if ($n <= $totalSlots) $order[] = $n;
        }
    }
    return $order;
}

// c_1: reversed row-wise display, left to right within each row.
function build_display_order_rowwise_reversed($totalSlots, $cols)
{
    if ($totalSlots < 1) return [];
    $rows = (int)ceil($totalSlots / $cols);
    $order = [];
    for ($r = $rows; $r >= 1; $r--) {
        $start = ($r - 1) * $cols + 1;
        for ($c = 0; $c < $cols; $c++) {
            $n = $start + $c;
            if ($n <= $totalSlots) $order[] = $n;
        }
    }
    return $order;
}

// ======= DISK DATA FILE UPDATE INFO =======
$hc_dir = __DIR__ . '/disk_data';
$all_hc_files = glob($hc_dir . '/*');
$latest_mtime = 0;
$oldest_mtime = PHP_INT_MAX;
$files_count  = 0;

if ($all_hc_files) {
    foreach ($all_hc_files as $pf) {
        if (is_file($pf)) {
            $mt = @filemtime($pf);
            if ($mt !== false) {
                $files_count++;
                if ($mt > $latest_mtime) $latest_mtime = $mt;
                if ($mt < $oldest_mtime) $oldest_mtime = $mt;
            }
        }
    }
}

$now_ts   = time();
$age_sec  = ($latest_mtime > 0) ? ($now_ts - $latest_mtime) : null;
$age_text = ($age_sec !== null) ? human_time_diff($age_sec) : 'n/a';
$latest_dt= ($latest_mtime > 0) ? date('Y-m-d H:i:s', $latest_mtime) : 'n/a';

// Stale warning threshold: 24 hours.
$is_stale = ($age_sec !== null && $age_sec > 24*3600);


// ======================== LOAD POOLS ========================
$pool_by_disk  = array();
$spare_by_disk = array();
$pool_names    = array();

$pool_file = __DIR__ . "/disk_data/disk_per_pool.txt";
if (is_file($pool_file)) {
    $raw = file_get_contents($pool_file);
    if ($raw !== false) {
        if (preg_match_all('/\{.*?\}/s', $raw, $m)) {
            foreach ($m[0] as $obj) {
                $data = json_decode($obj, true);
                if (is_array($data)) {
                    $pool_name = isset($data['name']) ? trim($data['name']) : '';
                    if ($pool_name !== '') {
                        $pool_names[$pool_name] = true;
                    }
                    if (isset($data['data_disks']) && is_array($data['data_disks'])) {
                        foreach ($data['data_disks'] as $d) {
                            $d = trim($d);
                            if ($d !== '') $pool_by_disk[$d] = $pool_name;
                        }
                    }
                    if (isset($data['spare_disks']) && is_array($data['spare_disks'])) {
                        foreach ($data['spare_disks'] as $d) {
                            $d = trim($d);
                            if ($d !== '') $spare_by_disk[$d] = $pool_name;
                        }
                    }
                }
            }
        }
    }
}


if (!empty($unused_by_disk)) {
    $pool_names['UNUSED'] = true;
}

$pool_options = array_keys($pool_names);
sort($pool_options, SORT_NATURAL | SORT_FLAG_CASE);

// Warn if API is configured but no pool data was loaded
$api_configured_but_no_pools = false;
if (empty($pool_names) || (count($pool_names) === 1 && isset($pool_names['UNUSED']))) {
    require_once __DIR__ . '/api_config_store.php';
    if (tdm_api_configured()) {
        $api_configured_but_no_pools = true;
    }
}


// ======================== LOAD VDEV MAP ========================
$vdev_by_disk = array();
$vdev_file = __DIR__ . "/disk_data/disk_vdev.json";
if (is_file($vdev_file)) {
    $vdev_raw = @json_decode(@file_get_contents($vdev_file), true);
    if (is_array($vdev_raw)) {
        $vdev_by_disk = $vdev_raw;
    }
}



// ======================== LOAD GENERATED SES FILES ========================
$files = glob("disk_data/*_ses");
$panels = [];

foreach ($files as $file)
{
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) continue;

    $controller = get_controller_from_file($file);
    $tiles      = [];
    $title      = null;
    $min_slot   = PHP_INT_MAX;
    $max_slot   = 0;
    $first_capacity = '';

    foreach ($lines as $line)
    {
        // serial | device | locatie | slot | smart | cmd_on | cmd_off
        $parts = explode("|", $line);
        if (count($parts) < 7) continue;

        list($serial, $device, $locatie, $slot, $smart, $cmd_on, $cmd_off) = $parts;
        $meta = tdm_ses_meta_from_parts($parts);

        if ($title === null) $title = trim($locatie);
        if ($first_capacity === '') $first_capacity = $meta['capacity'];

        // SMART-derived visual class, with SPARE override.
        $class = tdm_status_class($smart);

        // SMART-derived visual class, with SPARE override.
        $class = tdm_status_class($smart);
        if (stripos($smart, "SPARE") !== false) {
            $class = "smart-spare";
        }

        $pozitia = (int)$slot; // raw slot number
        if ($pozitia < $min_slot) $min_slot = $pozitia;
        if ($pozitia > $max_slot) $max_slot = $pozitia;

        $tiles[$pozitia] = [
            'serial' => trim($serial),
            'device' => trim($device),
            'locatie'=> trim($locatie),
            'smart'  => trim($smart),
            'class'  => $class,
            'cmd_on' => trim($cmd_on),
            'cmd_off'=> trim($cmd_off),
            'meta'   => $meta,
        ];
    }

    // Determine layout: 3.5" TB drives → 4 cols horizontal, everything else → 15 cols vertical
    $cols = (stripos($first_capacity, 'TB') !== false) ? 4 : 15;

    // Normalize slots to start at 0 (physical bay numbering)
    if ($min_slot < PHP_INT_MAX) {
        $offset = $min_slot;
        $norm = [];
        foreach ($tiles as $pos => $tile) {
            $norm[$pos - $offset] = $tile;
        }
        $tiles = $norm;
        $max_slot = $max_slot - $offset;
    }

    if ($title === null) $title = basename($file);

    $panels[] = [
        'title'      => $title,
        'cols'       => $cols,
        'tiles'      => $tiles,
        'max_slot'   => $max_slot,
        'file'       => basename($file),
        'controller' => $controller,
    ];
}
?>
<!DOCTYPE html>
<html lang="<?php echo tdm_h('html.lang'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title><?php echo tdm_h('page.title'); ?></title>

<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

<script>
(function(){
  try {
    if (localStorage.getItem('tdmTheme') === 'light') {
      document.documentElement.classList.add('theme-light');
    }
  } catch (e) {}
})();
</script>

<style>



/* Legend dots are inline elements, not positioned overlays. */
.led-dot-legend{
  display:inline-block;
  width:12px;
  height:12px;
  border-radius:50%;
  margin-right:10px;
  vertical-align:middle;
}

/* Colors match the grid status lights. */
.led-dot-legend.smart-ok    { background:var(--status-ok); box-shadow:0 0 8px rgba(34,197,94,.8); }
.led-dot-legend.smart-dead  { background:var(--status-dead); box-shadow:0 0 8px rgba(153,27,27,.85); }
.led-dot-legend.smart-critical { background:var(--status-critical); box-shadow:0 0 8px rgba(239,68,68,.85); }
.led-dot-legend.smart-warn  { background:var(--status-suspect); box-shadow:0 0 8px rgba(234,179,8,.8); }
.led-dot-legend.smart-danger{ background:var(--status-dangerous); box-shadow:0 0 8px rgba(249,115,22,.85); }
.led-dot-legend.smart-interface{ background:var(--status-interface); box-shadow:0 0 8px rgba(168,85,247,.85); }
.led-dot-legend.smart-maintenance{ background:var(--status-maintenance); box-shadow:0 0 8px rgba(34,211,238,.8); }
.led-dot-legend.smart-unknown{ background:var(--status-unknown); box-shadow:0 0 8px rgba(148,163,184,.75); }
.led-dot-legend.smart-spare { background:#e9ecef; box-shadow:0 0 6px rgba(233,236,239,.6); }
.led-dot-legend.empty       { background:#111;    box-shadow:inset 0 0 3px rgba(255,255,255,.2); }
.led-dot-legend.smart-unused{ background:#2da8ff; box-shadow:0 0 10px rgba(45,168,255,.9); }

/* Legend layout */
.legend-grid {
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  grid-gap:12px 20px;
}
.legend-item {
  display:flex;
  align-items:center;
  font-size:14px;
  color:#cfd4da;
}


#regenSpinner.d-none { display: none !important; }
#regenSpinner { pointer-events: none; }
#regenSpinner .text-center { pointer-events: auto; }


#driveModal .copyable { cursor: copy; }
#copyHint.fadeout { transition: opacity .4s ease; opacity: 0; }



.tile-hidden { display: none !important; }
.tile-hit { outline-color: rgba(45,168,255,.9) !important; box-shadow: 0 0 0 2px rgba(45,168,255,.3) inset; }



/* By default, show short pool names and hide full pool names. */
.pool-full { display: none; }
.pool-short { display: inline; }

/* When body has .show-full-pool, reverse the visibility. */
.show-full-pool .pool-full { display: inline; }
.show-full-pool .pool-short { display: none; }


:root{
  --c1-scale: calc(685 / 202);
  --c1-unscale: calc(202 / 685);
  --bg: #1b1e23;
  --panel: #232730;
  --panel-2: #1f2330;
  --text: #e6e6e6;
  --title: #f0f0f0;
  --muted: #8d97a5;
  --border: rgba(255,255,255,0.08);
  --pill-bg: rgba(255,255,255,.06);
  --pill-text: #cbd3da;
  --pre-bg: #0f121a;
  --pre-text: #cfe4ff;
  --status-dead: #991b1b;
  --status-critical: #ef4444;
  --status-dangerous: #f97316;
  --status-suspect: #eab308;
  --status-interface: #a855f7;
  --status-maintenance: #22d3ee;
  --status-unknown: #94a3b8;
  --status-ok: #22c55e;
  --status-info: #64748b;
}

html.theme-light{
  --bg: #eef2f7;
  --panel: #ffffff;
  --panel-2: #f8fafc;
  --text: #172033;
  --title: #101827;
  --muted: #657082;
  --border: rgba(15,23,42,0.13);
  --pill-bg: #eef2f7;
  --pill-text: #465568;
  --pre-bg: #f6f8fb;
  --pre-text: #172033;
}



  body { background: var(--bg); color: var(--text); }
  .page-wrap { min-height: 100vh; padding: 24px 0 48px 0; }
  .app-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:14px;
  }
  .app-title{
    margin:0;
    color:var(--title);
    font-size:28px;
    font-weight:800;
    line-height:1.15;
  }
  .app-version{
    padding:5px 9px;
    border-radius:6px;
    background:var(--pill-bg);
    color:var(--pill-text);
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
  }
  .nas-panel {
    background: var(--panel); border-radius: 8px;
    box-shadow: 0 12px 30px rgba(0,0,0,0.4), inset 0 0 0 1px rgba(255,255,255,0.03);
    padding: 18px; margin-bottom: 26px;
  }
  .nas-title { font-weight: 600; letter-spacing: .3px; color: var(--title); }
  .file-pill { font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--pill-bg); color: var(--pill-text); }
  .top-status-bar, .display-options-bar{
    background: var(--panel-2) !important;
    border-color: var(--border) !important;
    border-radius:8px !important;
    box-shadow:inset 0 0 0 1px var(--border);
  }
  .top-status-bar{
    flex-wrap:wrap;
    gap:10px;
  }
  .top-status-meta{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:8px;
    min-width:240px;
  }
  .top-status-actions{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:8px;
  }
  .display-options-bar{
    gap:12px;
    flex-wrap:wrap;
  }
  .display-options-controls{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:10px;
  }

  /* The tile aspect ratio controls row height. */
  .nas-grid { display: grid; grid-row-gap: 14px; grid-column-gap: 14px; }
  
  
  
  /* --- tile-ul default (C_0) orizontal --- */
.hdd-tile{
  position:relative; border-radius:10px; overflow:hidden; cursor:pointer;
  outline:2px solid rgba(255,255,255,.06);
  box-shadow: inset 0 -20px 30px rgba(0,0,0,.35), 0 8px 18px rgba(0,0,0,.35);
  aspect-ratio: 685 / 202;        /* landscape by default */
  background-color:#2a2f3a;
}

/* --- C_1: portrait tile (4 x 15 grid) --- */
.panel-c1 .hdd-tile{
  aspect-ratio: 202 / 685;        /* portrait box */
}

/* Content holder for image, LED, and overlay. */
.hdd-content{
  position:absolute; inset:0;
  background-position:center;
  background-repeat:no-repeat;
  background-size: 100% 100%;
}

/* ====== BAY IMAGES ======
   Two base images: 01hex2026.png (2309x681) for populated drives,
   01blank2026.png (2309x681) for empty slots.
   C_1 rotates via ::before pseudo-element — dimensions are swapped so
   the rotated image fills the portrait tile exactly (both have 3.39:1 ratio).
*/
.hdd-tile::before {
  content: '';
  position: absolute; inset: 0;
  background-image: url('src/img/01hex2026.png');
  background-position: center;
  background-repeat: no-repeat;
  background-size: 100% 100%;
  z-index: 0;
}
.hdd-tile.empty::before {
  background-image: url('src/img/01blank2026.png');
}

.panel-c1 .hdd-tile::before {
  /* Swap width/height so the landscape image, when rotated -90deg,
     fills the portrait tile exactly. 685/202 = tile height/width ratio. */
  width:  calc(685 / 202 * 100%);
  height: calc(202 / 685 * 100%);
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%) rotate(-90deg);
}


/* ====== ROTATED TEXT FOR C_1 ONLY ======
   Rotate the overlay -90 degrees without scaling and anchor it near the lower-left edge.
*/
.hdd-overlay{
  position:absolute; left:0; right:0; bottom:0; padding:8px 10px;
  background:linear-gradient(to top, rgba(0,0,0,.6), rgba(0,0,0,0));
  text-shadow: 0 1px 2px rgba(0,0,0,.7);
  z-index: 2;
}



.panel-c1 .hdd-overlay{
  /* No background; position it near the left middle. */
  background:none; padding:0;
  top:96%; left:45%; right:auto; bottom:auto;

  /* Pivot from the left middle, rotate, then center vertically. */
  transform-origin: left center;
  transform: translateY(-50%) rotate(-90deg);

  /* Keep width automatic so it is not forced to 100%. */
  width:auto;
}
/* Tile typography */
.slot-label{
  position:absolute; top:8px; left:10px;
  z-index:3;
  font-size:11px; font-weight:700; letter-spacing:.3px; text-transform:uppercase; margin:0; color:#e9ecef;
  text-shadow: 0 1px 3px rgba(0,0,0,.8);
  white-space:nowrap;
}
.vdev-label{
  display:inline-block;
  padding:0 5px;
  border-radius:4px;
  font-size:10px;
  font-weight:800;
  letter-spacing:.3px;
  vertical-align:middle;
  margin-left:2px;
}
/* VDEV colors by index */
.vdev-label.vdev-c0{ background:rgba(45,168,255,.25); color:#7cc8ff; }
.vdev-label.vdev-c1{ background:rgba(34,197,94,.25); color:#4ade80; }
.vdev-label.vdev-c2{ background:rgba(234,179,8,.25); color:#facc15; }
.vdev-label.vdev-c3{ background:rgba(168,85,247,.25); color:#c084fc; }
.vdev-label.vdev-c4{ background:rgba(249,115,22,.25); color:#fb923c; }
.vdev-label.vdev-c5{ background:rgba(236,72,153,.25); color:#f472b6; }
.vdev-label.vdev-spare{ background:rgba(233,236,239,.18); color:#cbd3da; }
.vdev-label.vdev-unused{ background:rgba(148,163,184,.25); color:#94a3b8; }
.name-label{ font-size:12px; color:#cbd3da; margin:2px 0 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.meta-label{ font-size:11px; color:#9aa6b2; margin:1px 0 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.panel-c1 .slot-label{
  top:96%; left:45%; right:auto; bottom:auto;
  transform-origin: left center;
  transform: translateY(-50%) rotate(-90deg);
  width:auto;
  font-size:14px; letter-spacing:.6px;
  background:none;
}
.panel-c1 .name-label{ font-size:13px; }
.panel-c1 .meta-label{ display:none; }


/* LED position stays unrotated. */
.led-dot{
  position:absolute; top:8px; right:8px;
  width:12px; height:12px; border-radius:50%;
}
.hdd-tile.smart-ok   .led-dot{ background:#2bff6a; box-shadow:0 0 8px rgba(43,255,106,.8); }
.hdd-tile.smart-warn .led-dot{ background:#ffd24a; box-shadow:0 0 8px rgba(255,210,74,.8); }
.hdd-tile.smart-danger .led-dot{ background:var(--status-dangerous); box-shadow:0 0 8px rgba(249,115,22,.85); }
.hdd-tile.smart-interface .led-dot{ background:var(--status-interface); box-shadow:0 0 8px rgba(168,85,247,.85); }
.hdd-tile.smart-maintenance .led-dot{ background:var(--status-maintenance); box-shadow:0 0 8px rgba(34,211,238,.8); }
.hdd-tile.smart-unknown .led-dot{ background:var(--status-unknown); box-shadow:0 0 8px rgba(148,163,184,.75); }
.hdd-tile.smart-bad  .led-dot{ background:#ff4a4a; box-shadow:0 0 8px rgba(255,74,74,.85); }
.hdd-tile.status-dead .led-dot{ background:var(--status-dead); box-shadow:0 0 8px rgba(153,27,27,.85); }
.hdd-tile.status-critical .led-dot{ background:var(--status-critical); box-shadow:0 0 8px rgba(239,68,68,.85); }
.hdd-tile.smart-spare .led-dot{ background:#e9ecef; box-shadow:0 0 6px rgba(233,236,239,.6); }
.hdd-tile.empty      .led-dot{ background:#fff; box-shadow:0 0 6px rgba(255,255,255,.7); }
.hdd-tile.smart-unused .led-dot{  background:#2da8ff;  box-shadow:0 0 10px rgba(45,168,255,.9);}

.tile-led-toggle{
  position:absolute;
  top:8px;
  right:22px;
  z-index:4;
  min-width:34px;
  height:22px;
  padding:0 7px;
  border-radius:5px;
  border:1px solid rgba(255,255,255,.22);
  background:rgba(11,14,20,.78);
  color:#e8eef5;
  font-size:10px;
  font-weight:700;
  line-height:20px;
  cursor:pointer;
}
.tile-led-toggle:hover{ background:rgba(22,29,40,.92); border-color:rgba(255,255,255,.36); }
.tile-led-toggle.active{ color:#08110b; background:#2bff6a; border-color:#2bff6a; box-shadow:0 0 8px rgba(43,255,106,.65); }
.tile-led-toggle:disabled{ opacity:.55; cursor:wait; }
.panel-c1 .tile-led-toggle{ display:none; }


/* Hover without transforms so internal positions stay stable. */
.hdd-tile:hover{ outline-color: rgba(255,255,255,.12); }

.detail-grid{
  display:grid;
  grid-template-columns: 110px minmax(0, 1fr);
  gap:6px 12px;
}
.detail-grid strong{ color:#f0f3f7; }
.detail-grid span{ min-width:0; overflow-wrap:anywhere; }
.drive-hero{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  padding:14px;
  border-radius:8px;
  background:var(--panel-2);
  border:1px solid var(--border);
  margin-bottom:14px;
}
.drive-hero-title{
  margin:0;
  font-size:18px;
  font-weight:800;
  color:var(--title);
}
.drive-hero-sub{
  color:var(--muted);
  font-size:13px;
  margin-top:3px;
}
.modal-status-pill{
  padding:5px 9px;
  border-radius:6px;
  font-size:12px;
  font-weight:800;
  background:var(--status-ok);
  color:#052e16;
  white-space:nowrap;
}
.modal-status-pill.status-dead{ background:var(--status-dead); color:#fff; }
.modal-status-pill.status-critical{ background:var(--status-critical); color:#fff; }
.modal-status-pill.status-dangerous{ background:var(--status-dangerous); color:#fff; }
.modal-status-pill.status-suspect{ background:var(--status-suspect); color:#111827; }
.modal-status-pill.status-interface{ background:var(--status-interface); color:#fff; }
.modal-status-pill.status-maintenance{ background:var(--status-maintenance); color:#083344; }
.modal-status-pill.status-unknown{ background:var(--status-unknown); color:#111827; }
.detail-section{
  padding:12px 14px;
  border-radius:8px;
  background:rgba(255,255,255,.035);
  border:1px solid var(--border);
  margin-bottom:12px;
}
.detail-section-title{
  margin:0 0 10px;
  font-size:13px;
  font-weight:800;
  color:var(--title);
}
.metric-grid{
  display:grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap:10px;
}
.metric-card{
  border-radius:8px;
  background:rgba(255,255,255,.045);
  border:1px solid var(--border);
  padding:9px 10px;
}
.metric-label{
  display:block;
  color:var(--muted);
  font-size:11px;
  text-transform:uppercase;
  font-weight:800;
}
.metric-value{
  display:block;
  color:var(--title);
  margin-top:3px;
  font-weight:700;
  overflow-wrap:anywhere;
}
.criteria-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(245px, 1fr));
  gap:12px;
}
.criteria-card{
  border:1px solid var(--border);
  border-radius:8px;
  background:rgba(255,255,255,.035);
  padding:12px 14px;
}
.criteria-card h6{
  display:flex;
  align-items:center;
  gap:8px;
  margin:0 0 8px;
  color:var(--title);
  font-size:14px;
  font-weight:800;
}
.criteria-chip{
  display:inline-flex;
  align-items:center;
  min-height:20px;
  padding:2px 7px;
  border-radius:5px;
  font-size:11px;
  font-weight:800;
  color:#052e16;
  background:var(--status-ok);
}
.criteria-chip.status-dead{ color:#fff; background:var(--status-dead); }
.criteria-chip.status-critical{ color:#fff; background:var(--status-critical); }
.criteria-chip.status-dangerous{ color:#fff; background:var(--status-dangerous); }
.criteria-chip.status-suspect{ color:#111827; background:var(--status-suspect); }
.criteria-chip.status-interface{ color:#fff; background:var(--status-interface); }
.criteria-chip.status-maintenance{ color:#083344; background:var(--status-maintenance); }
.criteria-chip.status-unknown{ color:#111827; background:var(--status-unknown); }
.criteria-chip.status-info{ color:#fff; background:var(--status-info); }
.criteria-card ul{
  margin:0;
  padding-left:18px;
  color:var(--text);
  font-size:13px;
}
.criteria-card li{ margin-bottom:5px; }
.criteria-note{
  display:block;
  margin-top:12px;
  color:var(--muted);
  font-size:12px;
}
@media (min-width: 768px){
  .metric-grid{ grid-template-columns:repeat(4, minmax(0, 1fr)); }
}
@media (max-width: 767px){
  .app-header{ align-items:flex-start; }
  .app-title{ font-size:24px; }
  .top-status-actions,
  .display-options-controls{ justify-content:flex-start; width:100%; }
  .display-options-controls .input-group{ width:100% !important; }
  .metric-grid{ grid-template-columns:1fr; }
}

html.theme-light .nas-panel{
  box-shadow: 0 10px 24px rgba(15,23,42,0.09), inset 0 0 0 1px rgba(15,23,42,0.05);
}
html.theme-light .legend-item,
html.theme-light .text-light,
html.theme-light .custom-control-label,
html.theme-light label,
html.theme-light .modal-content{
  color: var(--text) !important;
}
html.theme-light .text-muted{ color: var(--muted) !important; }
html.theme-light .detail-grid strong{ color: var(--title); }
html.theme-light .detail-section,
html.theme-light .metric-card,
html.theme-light .criteria-card{
  background:#f8fafc;
}
html.theme-light .modal-content{
  background: var(--panel) !important;
  border-color: var(--border) !important;
}
html.theme-light .modal-header,
html.theme-light .modal-footer{
  border-color: var(--border);
}
html.theme-light .modal .close{
  color: var(--title) !important;
  text-shadow:none;
}
html.theme-light #regenOutput,
html.theme-light #smartOutput{
  background: var(--pre-bg) !important;
  color: var(--pre-text) !important;
  border-color: var(--border) !important;
}
html.theme-light #regenSpinner{
  background: rgba(248,250,252,.72) !important;
}
html.theme-light .form-control,
html.theme-light .custom-select{
  background:#fff;
  color:#172033;
  border-color:#c8d2df;
}
html.theme-light .btn-outline-light{
  color:#172033;
  border-color:#7b8798;
}
html.theme-light .btn-outline-light:hover{
  background:#172033;
  color:#fff;
}
html.theme-light .btn-outline-secondary{
  color:#39475c;
  border-color:#9aa6b2;
}

</style>
</head>
<body>
<div class="page-wrap">
<div class="container">

<div class="app-header">
  <h1 class="app-title"><?php echo tdm_h('page.title'); ?></h1>
  <span class="app-version"><?php echo tdm_h('app.version', array('version' => $app_version)); ?></span>
</div>

<div class="top-status-bar d-flex align-items-center justify-content-between mb-2 p-2" style="flex-wrap:wrap; row-gap:6px;">
  <div class="top-status-meta text-light" style="flex:1 1 auto; min-width:0; overflow:hidden; text-overflow:ellipsis;">
    <strong><?php echo tdm_h('last_update.label'); ?></strong>:
    <span><?php echo htmlspecialchars($latest_dt); ?></span>
    <span class="text-muted">(<?php echo tdm_h('last_update.ago', array('age' => $age_text)); ?>)</span>
    <?php if ($is_stale): ?>
      <span style="color:#ff5858; font-weight:700;"><?php echo tdm_h('last_update.stale'); ?></span>
    <?php else: ?>
      <span class="text-success"><?php echo tdm_h('status.ok'); ?></span>
    <?php endif; ?>
    <span class="text-muted"><?php echo tdm_h('last_update.files', array('count' => (int)$files_count)); ?></span>
    <?php if ($api_configured_but_no_pools): ?>
      <span class="badge" style="background:rgba(245,158,11,.2); color:#f59e0b; font-size:10px; white-space:nowrap;">No pool data — run Refresh</span>
    <?php endif; ?>
  </div>

  <div class="top-status-actions" style="flex-shrink:0;">
    <span id="autoRefreshBadge" class="badge" style="display:none; background:#1a3a2a; color:#4ade80; font-size:11px; padding:4px 8px; border-radius:5px; margin-right:4px;">
      <?php echo tdm_h('refresh.auto.running'); ?>
    </span>
    <button id="btnCronConfig" type="button" class="btn btn-sm btn-outline-info" title="<?php echo tdm_h('cron.title'); ?>">
      &#9881;
    </button>
    <button id="btnRegen" type="button" class="btn btn-sm btn-outline-info">
      <?php echo tdm_h('refresh.button'); ?>
    </button>
    <button id="btnApiSettings" type="button" class="btn btn-sm btn-outline-light">
      <?php echo tdm_h('api.button'); ?>
    </button>
    <button id="btnTheme" type="button" class="btn btn-sm btn-outline-light"
            aria-pressed="false" title="<?php echo tdm_h('theme.toggle'); ?>">
      <?php echo tdm_h('theme.light'); ?>
    </button>
  </div>

</div>


<div class="display-options-bar d-flex align-items-center justify-content-between mb-3 p-2">
  <div class="text-light font-weight-bold"><?php echo tdm_h('display.options'); ?></div>

  <div class="display-options-controls">
    <div class="text-light">
      <label class="mb-0" style="cursor:pointer">
        <input type="checkbox" id="toggleShort" checked>
        <span class="ml-2"><?php echo tdm_h('display.hide_full_pool_names'); ?></span>
      </label>
    </div>

    <div class="input-group" style="width:340px">
      <input id="diskSearch" type="text" class="form-control form-control-sm"
             placeholder="<?php echo tdm_h('search.placeholder'); ?>">
      <div class="input-group-append">
        <button id="btnClearSearch" class="btn btn-sm btn-outline-light" type="button"><?php echo tdm_h('button.clear'); ?></button>
      </div>
    </div>

    <div class="d-flex">
      <select id="poolFilter" class="custom-select custom-select-sm" style="width: 200px;">
        <option value=""><?php echo tdm_h('select.unselected'); ?></option>
        <?php foreach ($pool_options as $pn): ?>
          <option value="<?php echo htmlspecialchars($pn); ?>">
            <?php echo htmlspecialchars($pn); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="input-group-append ml-2">
        <button id="btnClearPool" class="btn btn-sm btn-outline-light" type="button"><?php echo tdm_h('button.reset'); ?></button>
      </div>
    </div>
  </div>
</div>



<?php foreach ($panels as $panel) {

    // cols
    $cols = 4;
    if (isset($panel['cols'])) {
        $cols = (int)$panel['cols'];
    }
    if ($cols < 1) {
        $cols = 1;
    }

    // total (count of slots from 0 to max)
    $total = 0;
    if (isset($panel['max_slot'])) {
        $total = (int)$panel['max_slot'] + 1;
    }
    if ($total < 0) {
        $total = 0;
    }

    // controller
    $ctrl = 0;
    if (isset($panel['controller'])) {
        $ctrl = (int)$panel['controller'];
    }

    // order (column-wise for landscape, row-wise for portrait)
    $order = array();
    if ($cols > 8) {
        $order = build_display_order_rowwise_reversed($total, $cols);
    } else {
        $order = build_display_order_colwise_top_high($total, $cols);
    }

    // rows
    $rows = 0;
    if ($total > 0) {
        $rows = (int)ceil($total / $cols);
    }

    // Order hint, first five entries.
    $ordHint = '';
    if ($rows >= 1) {
        $parts = array();
        $count = count($order);
        $limit = 5;
        $i = 0;
        while ($i < $count && $i < $limit) {
            $parts[] = $order[$i];
            $i++;
        }
        $ordHint = implode(' ', $parts);
        if ($count > $limit) {
            $ordHint .= ' ...';
        }
    }

    // Panel class (portrait layout for many columns)
    $panelClass = '';
    if ($cols > 8) {
        $panelClass = 'panel-c1';
    }

    // Minimum tile width.
    $minw = $cols > 8 ? 60 : 140;
?>
    <div class="nas-panel <?php echo $panelClass; ?>">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="nas-title mb-0"><?php echo htmlspecialchars($panel['title']); ?></h5>
        <div class="text-right">
          <div class="file-pill d-inline-block mr-2">
            <?php echo tdm_h('panel.file_info', array('file' => $panel['file'])); ?>
          </div>

          <?php
          if ($rows >= 1) {
              echo '<small class="text-muted">';
              echo tdm_h('panel.grid_info', array(
                  'cols' => $cols,
                  'rows' => $rows,
                  'order' => $ordHint
              ));
              echo '</small>';
          }
          ?>
        </div>
      </div>

      <div class="nas-grid" style="grid-template-columns: repeat(<?php echo $cols; ?>, minmax(<?php echo $minw; ?>px, 1fr));">
      <?php
        foreach ($order as $orderPos) {

            $slotnum = $orderPos - 1; // 0-based
            $has = isset($panel['tiles'][$slotnum]);
            $info = null;
            if ($has) {
                $info = $panel['tiles'][$slotnum];
            }

            $device_raw = $has ? $info['device'] : 'Empty';
            $serial_raw = $has ? $info['serial'] : '';
            $smart_raw  = $has ? $info['smart'] : '';
            $meta       = $has ? $info['meta'] : tdm_ses_meta_from_parts(array());

            $device  = htmlspecialchars($device_raw);
            $serial  = htmlspecialchars($serial_raw);
            $smart   = htmlspecialchars($smart_raw);
            $cls     = $has ? $info['class'] : '';
            $cmd_on  = $has ? $info['cmd_on'] : '';
            $cmd_off = $has ? $info['cmd_off'] : '';
            $meta_summary = $has ? tdm_disk_meta_summary($meta) : '';
            $counter_summary = $has ? tdm_disk_counter_summary($meta) : '';
            $status_name = $has ? tdm_status_name($smart_raw) : 'EMPTY';
            $status_class = tdm_status_slug($status_name);


			// Map /dev/sdX to sdX for pool comparisons.
			$dev_short = $device_raw;
			
			if ($dev_short !== '') {
				// Remove /dev/ prefix when present.
				if (strpos($dev_short, '/dev/') === 0) {
					$dev_short = substr($dev_short, 5);
				}
			}

			// Determine pool and spare membership.
			$pool_name = '';
			if (isset($pool_by_disk[$dev_short])) {
				$pool_name = $pool_by_disk[$dev_short];
			}
			$is_spare = false;
			$spare_pool_name = '';
			if (isset($spare_by_disk[$dev_short])) {
				$is_spare = true;
				$spare_pool_name = $spare_by_disk[$dev_short];
				if ($pool_name === '') {
					$pool_name = $spare_pool_name;
				}
			}

			// If the disk is unused, ignore pool/spare labels and use the unused class.
			$is_unused = false;
			if ($dev_short !== '' && isset($unused_by_disk[$dev_short])) {
				$is_unused = true;
				$pool_name = 'UNUSED';
				$is_spare  = false;
			}
			


			// Final visual class.
			if ($is_unused) {
				$cls = 'smart-unused';
				$tileCls = 'hdd-tile smart-unused';
			} elseif ($is_spare) {
				$cls = 'smart-spare';
				$tileCls = 'hdd-tile smart-spare';
			} else {
				$tileCls = 'hdd-tile ' . ($has ? $cls : 'empty');
			}

            if ($has) {
                $tileCls .= ' status-' . $status_class;
            }

			
			// Short pool name for the grid.
			$pool_name_short = $pool_name;
			if ($pool_name_short !== '' && $pool_name_short !== 'UNUSED') {
				if (strlen($pool_name_short) > 8) {
					$pool_name_short = substr($pool_name_short, 0, 7) . '…';
				}
			}


			// Slot label with optional pool and VDEV/SPARE/UNUSED tags.
			$slotLabelHtml = 'Slot #' . (int)$slotnum;
			if ($pool_name !== '' && $pool_name !== 'UNUSED') {
				$slotLabelHtml .= ' ['
					. '<span class="pool-short">' . htmlspecialchars($pool_name_short) . '</span>'
					. '<span class="pool-full">'  . htmlspecialchars($pool_name)        . '</span>'
					. ']';
			}
			// VDEV type label (color-coded by index)
			if ($dev_short !== '' && isset($vdev_by_disk[$dev_short]) && !$is_unused) {
				$vd = $vdev_by_disk[$dev_short];
				$vdev_type = isset($vd['vdev_type']) ? $vd['vdev_type'] : '';
				$vdev_idx  = isset($vd['vdev_index']) ? (int)$vd['vdev_index'] : 0;
				if ($vdev_type !== '' && $vdev_type !== 'DATA') {
					$slotLabelHtml .= ' <span class="vdev-label vdev-c' . ($vdev_idx % 6) . '">' . htmlspecialchars($vdev_type) . '-' . $vdev_idx . '</span>';
				}
			}
			if ($is_spare && !$is_unused) {
				$slotLabelHtml .= ' <span class="vdev-label vdev-spare">SPARE</span>';
			}
			if ($is_unused) {
				$slotLabelHtml .= ' <span class="vdev-label vdev-unused">UNUSED</span>';
			}
			// Build VDEV label for the modal
			$vdev_label = '';
			if ($dev_short !== '' && isset($vdev_by_disk[$dev_short])) {
				$vd = $vdev_by_disk[$dev_short];
				$vt = isset($vd['vdev_type']) ? $vd['vdev_type'] : '';
				$vi = isset($vd['vdev_index']) ? (int)$vd['vdev_index'] : 0;
				if ($vt !== '' && $vt !== 'DATA') {
					$vdev_label = $vt . '-' . $vi;
				}
			}
			if ($is_spare && $vdev_label === '') $vdev_label = 'SPARE';
			if ($is_unused) $vdev_label = 'UNUSED';

            // Device and serial label.
            $labelText = $device;
            if ($serial !== '') {
                $labelText .= ' [ ' . $serial . ' ]';
            }
      ?>
        <div class="<?php echo $tileCls; ?>"
             data-slot="<?php echo $slotnum; ?>"
			 data-device="<?php echo htmlspecialchars($dev_short); ?>"
			 data-serial="<?php echo htmlspecialchars($serial_raw); ?>"
             data-model="<?php echo htmlspecialchars($meta['model']); ?>"
             data-capacity="<?php echo htmlspecialchars($meta['capacity']); ?>"
			 data-pool="<?php echo htmlspecialchars($pool_name); ?>"
             onclick="openDriveModal(
			  <?php echo (int)$slotnum; ?>,
			  <?php echo tdm_js($device_raw); ?>,
			  <?php echo tdm_js($serial_raw); ?>,
			  <?php echo tdm_js($smart_raw); ?>,
			  <?php echo tdm_js($has ? $info['locatie'] : ''); ?>,
			  <?php echo tdm_js($cmd_on); ?>,
			  <?php echo tdm_js($cmd_off); ?>,
			  <?php echo tdm_js($pool_name); ?>,
			  <?php echo $is_spare ? 'true' : 'false'; ?>,
              <?php echo tdm_js($meta); ?>,
              <?php echo tdm_js($counter_summary); ?>,
              <?php echo tdm_js($status_name); ?>,
              <?php echo tdm_js($vdev_label); ?>
			)"
			>
          <div class="hdd-content">
            <span class="led-dot" aria-hidden="true"></span>
            <p class="slot-label"><?php echo $slotLabelHtml; ?></p>
            <?php if ($has && $cmd_on !== '' && $cmd_off !== ''): ?>
              <button type="button"
                      class="tile-led-toggle"
                      data-state="off"
                      data-on="<?php echo htmlspecialchars($cmd_on); ?>"
                      data-off="<?php echo htmlspecialchars($cmd_off); ?>"
                      title="<?php echo tdm_h('button.led_toggle'); ?>"
                      onclick="toggleTileLed(event, this)"><?php echo tdm_h('button.led_short'); ?></button>
            <?php endif; ?>
            <div class="hdd-overlay">
              <p class="name-label"><?php echo $labelText; ?></p>
              <?php if ($meta_summary !== ''): ?>
                <p class="meta-label"><?php echo htmlspecialchars($meta_summary); ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php } // end foreach $order ?>
      </div>
    </div>
<?php } // end foreach $panels ?>




<div class="nas-panel mt-4">
  <h5 class="nas-title mb-3"><?php echo tdm_h('legend.title'); ?></h5>
  <div class="legend-grid">
    <div class="legend-item">
      <span class="led-dot-legend smart-ok"></span>
      <span><?php echo tdm_h('legend.ok'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-dead"></span>
      <span><?php echo tdm_h('legend.dead'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-critical"></span>
      <span><?php echo tdm_h('legend.critical'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-danger"></span>
      <span><?php echo tdm_h('legend.danger'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-warn"></span>
      <span><?php echo tdm_h('legend.warn'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-interface"></span>
      <span><?php echo tdm_h('legend.interface'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-maintenance"></span>
      <span><?php echo tdm_h('legend.maintenance'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-unknown"></span>
      <span><?php echo tdm_h('legend.unknown'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-spare"></span>
      <span><?php echo tdm_h('legend.spare'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend empty"></span>
      <span><?php echo tdm_h('legend.empty'); ?></span>
    </div>
    <div class="legend-item">
      <span class="led-dot-legend smart-unused"></span>
      <span><?php echo tdm_h('legend.unused'); ?></span>
    </div>
  </div>
</div>


<div class="nas-panel mt-3">
  <h5 class="nas-title mb-3"><?php echo tdm_h('smart.criteria.title'); ?></h5>

  <div class="criteria-grid">
    <div class="criteria-card">
      <h6><span class="criteria-chip status-dead">DEAD</span><?php echo tdm_h('smart.criteria.dead'); ?></h6>
      <ul>
        <li><?php echo tdm_h('smart.criteria.failed_overall'); ?></li>
        <li><?php echo tdm_h('smart.criteria.failed_selftest'); ?></li>
        <li><?php echo tdm_h('smart.criteria.pending_uncorrectable'); ?></li>
      </ul>
    </div>
    <div class="criteria-card">
      <h6><span class="criteria-chip status-critical">CRITICAL</span><?php echo tdm_h('smart.criteria.critical'); ?></h6>
      <ul>
        <li><?php echo tdm_h('smart.criteria.pending'); ?></li>
        <li><?php echo tdm_h('smart.criteria.uncorrectable'); ?></li>
        <li><?php echo tdm_h('smart.criteria.reported_uncorrectable'); ?></li>
        <li><?php echo tdm_h('smart.criteria.read_failure'); ?></li>
        <li><?php echo tdm_h('smart.criteria.reallocated_critical'); ?></li>
        <li><?php echo tdm_h('smart.criteria.multiple_media'); ?></li>
      </ul>
    </div>
    <div class="criteria-card">
      <h6><span class="criteria-chip status-dangerous">DANGEROUS</span><?php echo tdm_h('smart.criteria.danger'); ?></h6>
      <ul>
        <li><?php echo tdm_h('smart.criteria.reallocated_danger'); ?></li>
        <li><?php echo tdm_h('smart.criteria.retry_danger'); ?></li>
      </ul>
    </div>
    <div class="criteria-card">
      <h6><span class="criteria-chip status-suspect">SUSPECT</span><?php echo tdm_h('smart.criteria.suspect'); ?></h6>
      <ul>
        <li><?php echo tdm_h('smart.criteria.reallocated_any'); ?></li>
        <li><?php echo tdm_h('smart.criteria.retry_any'); ?></li>
      </ul>
    </div>
    <div class="criteria-card">
      <h6><span class="criteria-chip status-interface">INTERFACE</span><?php echo tdm_h('smart.criteria.interface'); ?></h6>
      <ul>
        <li><?php echo tdm_h('smart.criteria.interface_crc'); ?></li>
        <li><?php echo tdm_h('smart.criteria.interface_path'); ?></li>
      </ul>
    </div>
    <div class="criteria-card">
      <h6><span class="criteria-chip status-maintenance">MAINTENANCE</span><?php echo tdm_h('smart.criteria.maintenance'); ?></h6>
      <ul>
        <li><?php echo tdm_h('smart.criteria.maintenance_tests'); ?></li>
        <li><?php echo tdm_h('smart.criteria.maintenance_missing'); ?></li>
      </ul>
    </div>
    <div class="criteria-card">
      <h6><span class="criteria-chip status-unknown">UNKNOWN</span><?php echo tdm_h('smart.criteria.unknown'); ?></h6>
      <ul>
        <li><?php echo tdm_h('smart.criteria.unknown_read'); ?></li>
      </ul>
    </div>
    <div class="criteria-card">
      <h6><span class="criteria-chip status-info">INFO</span><?php echo tdm_h('smart.criteria.info'); ?></h6>
      <ul>
        <li><?php echo tdm_h('smart.criteria.load_cycle'); ?></li>
        <li><?php echo tdm_h('smart.criteria.reallocated_tired'); ?></li>
      </ul>
    </div>
  </div>

  <small class="criteria-note">
    <?php echo tdm_h('smart.criteria.note'); ?>
  </small>

</div>







</div>

</div>

<!-- Modal control LED -->
<div class="modal fade" id="driveModal" tabindex="-1" role="dialog" aria-labelledby="driveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content" style="background:#232730;color:#e6e6e6;border:1px solid rgba(255,255,255,0.08);">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="driveModalLabel"><?php echo tdm_h('modal.bay_details'); ?></h5>
          <!-- toastul de confirmare copy -->
          <div id="copyHint" class="small" style="display:none;color:#79d2ff;">
            <?php echo tdm_h('modal.copied_value'); ?> <code class="val"></code>
          </div>
        </div>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="<?php echo tdm_h('modal.close'); ?>">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div class="drive-hero">
          <div>
            <p id="mHeroSlot" class="drive-hero-title">Slot</p>
            <div id="mHeroDevice" class="drive-hero-sub">-</div>
          </div>
          <span id="mStatusPill" class="modal-status-pill status-unknown">UNKNOWN</span>
        </div>

        <div class="detail-section">
          <p class="detail-section-title"><?php echo tdm_h('modal.identity'); ?></p>
          <div class="detail-grid">
            <strong><?php echo tdm_h('modal.slot'); ?></strong>
            <span id="mSlot" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>"></span>

            <strong><?php echo tdm_h('modal.name'); ?></strong>
            <span id="mNameFull" class="copyable" title="<?php echo tdm_h('modal.copy_whole'); ?>">
              <span id="mNameDev"
                    class="copyable"
                    data-copy="dev"
                    title="<?php echo tdm_h('modal.copy_device'); ?>"></span>
              <span id="mNameBrL" class="text-muted" style="display:none">[ </span>
              <span id="mNameSerial"
                    class="copyable"
                    data-copy="serial"
                    style="display:none"
                    title="<?php echo tdm_h('modal.copy_serial'); ?>"></span>
              <span id="mNameBrR" class="text-muted" style="display:none"> ]</span>
            </span>

            <strong><?php echo tdm_h('modal.model'); ?></strong>
            <span id="mModel" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">-</span>
          </div>
        </div>

        <div class="metric-grid mb-3">
          <div class="metric-card">
            <span class="metric-label"><?php echo tdm_h('modal.capacity'); ?></span>
            <span id="mCapacity" class="metric-value copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">-</span>
          </div>
          <div class="metric-card">
            <span class="metric-label"><?php echo tdm_h('modal.power_hours'); ?></span>
            <span id="mPowerHours" class="metric-value copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">-</span>
          </div>
          <div class="metric-card">
            <span class="metric-label"><?php echo tdm_h('modal.temperature'); ?></span>
            <span id="mTemperature" class="metric-value copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">-</span>
          </div>
          <div class="metric-card">
            <span class="metric-label"><?php echo tdm_h('modal.pool'); ?></span>
            <span id="mPool" class="metric-value copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">-</span>
          </div>
          <div class="metric-card">
            <span class="metric-label">VDEV</span>
            <span id="mVdev" class="metric-value copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">-</span>
          </div>
        </div>

        <div class="detail-section mb-0">
          <p class="detail-section-title"><?php echo tdm_h('modal.health'); ?></p>
          <div class="detail-grid">
            <strong><?php echo tdm_h('modal.state'); ?></strong>
            <span id="mSmart" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>"></span>

            <strong><?php echo tdm_h('modal.counters'); ?></strong>
            <span id="mCounters" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">-</span>

            <strong><?php echo tdm_h('modal.disk_location'); ?></strong>
            <span id="mLocatieDisk" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">-</span>
          </div>
        </div>

      </div>

<div class="modal-footer d-flex justify-content-between">
  <div>
    <button id="btnSmart" type="button" class="btn btn-info btn-sm">
      <?php echo tdm_h('button.smart'); ?>
    </button>
  </div>
  <div>
    <button id="btnOn"  type="button" class="btn btn-success btn-sm" onclick="runLed(this.dataset.cmd)"><?php echo tdm_h('button.led_on'); ?></button>
    <button id="btnOff" type="button" class="btn btn-danger  btn-sm" onclick="runLed(this.dataset.cmd)"><?php echo tdm_h('button.led_off'); ?></button>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal"><?php echo tdm_h('modal.close'); ?></button>
  </div>
</div>

    </div>
  </div>
</div>





<div class="modal fade" id="smartModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content" style="background:#1f2330;color:#e6e6e6;">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo tdm_h('smart.output.title'); ?></h5>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="<?php echo tdm_h('modal.close'); ?>">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <pre id="smartOutput" style="background:#0f121a;color:#cfe4ff;border-radius:6px;
          border:1px solid rgba(255,255,255,.08);padding:12px;max-height:70vh;overflow:auto;">
          <?php echo tdm_h('smart.output.loading'); ?>
        </pre>
      </div>
    </div>
  </div>
</div>






<!-- Refresh modal -->
<div class="modal fade" id="regenModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content" style="background:#1f2330;color:#e6e6e6;border:1px solid rgba(255,255,255,0.08);">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo tdm_h('refresh.title'); ?></h5>
        <button id="regenCloseX" type="button" class="close text-light" data-dismiss="modal" aria-label="<?php echo tdm_h('modal.close'); ?>">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body position-relative" style="min-height:60vh;">
        <!-- spinner overlay -->
        <div id="regenSpinner"
             class="d-flex align-items-center justify-content-center"
             style="position:absolute; inset:0; z-index:10; background:rgba(20,22,28,.6);">
          <div class="text-center text-light">
            <div class="spinner-border text-info" role="status"></div>
            <div class="mt-2"><?php echo tdm_h('refresh.running'); ?></div>
          </div>
        </div>

        <!-- log -->
        <pre id="regenOutput"
             style="background:#0f121a;color:#cfe4ff;border-radius:6px;border:1px solid rgba(255,255,255,.08);
                    padding:12px; height:60vh; overflow:auto; white-space:pre-wrap;"></pre>
      </div>

      <div class="modal-footer">
        <button id="regenReload" type="button" class="btn btn-success btn-sm"><?php echo tdm_h('button.reload'); ?></button>
        <button id="regenClose"  type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal"><?php echo tdm_h('modal.close'); ?></button>
      </div>
    </div>
  </div>
</div>


<!-- Modal API Settings -->
<div class="modal fade" id="apiSettingsModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content" style="background:#1f2330;color:#e6e6e6;border:1px solid rgba(255,255,255,0.08);">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo tdm_h('api.title'); ?></h5>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="<?php echo tdm_h('modal.close'); ?>">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <form id="apiSettingsForm">
          <div class="form-group">
            <label for="apiUrl"><?php echo tdm_h('api.url'); ?></label>
            <input id="apiUrl" name="api_url" type="url" class="form-control form-control-sm"
                   placeholder="https://truenas.example.local/api/v2.0">
          </div>

          <div class="form-group">
            <label for="apiKey"><?php echo tdm_h('api.key'); ?></label>
            <input id="apiKey" name="api_key" type="password" class="form-control form-control-sm"
                   placeholder="<?php echo tdm_h('api.key_placeholder'); ?>">
          </div>

          <div class="custom-control custom-checkbox">
            <input id="apiVerifyTls" name="verify_tls" type="checkbox" class="custom-control-input">
            <label class="custom-control-label" for="apiVerifyTls"><?php echo tdm_h('api.verify_tls'); ?></label>
          </div>

          <div class="mt-3 small text-muted">
            <span id="apiStatusText"><?php echo tdm_h('api.status_not_configured'); ?></span>
            <span id="apiKeyMasked" class="ml-2"></span>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button id="apiDisable" type="button" class="btn btn-outline-warning btn-sm"><?php echo tdm_h('button.disable'); ?></button>
        <button id="apiTest" type="button" class="btn btn-outline-info btn-sm"><?php echo tdm_h('button.test'); ?></button>
        <button id="apiSave" type="button" class="btn btn-success btn-sm"><?php echo tdm_h('button.save'); ?></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal"><?php echo tdm_h('modal.close'); ?></button>
      </div>
    </div>
  </div>
</div>


<!-- Modal Cron Config -->
<div class="modal fade" id="cronConfigModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content" style="background:#1f2330;color:#e6e6e6;border:1px solid rgba(255,255,255,0.08);">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo tdm_h('cron.title'); ?></h5>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="<?php echo tdm_h('modal.close'); ?>">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <form id="cronConfigForm">
          <div class="custom-control custom-switch mb-3">
            <input id="cronEnabled" name="enabled" type="checkbox" class="custom-control-input">
            <label class="custom-control-label" for="cronEnabled"><?php echo tdm_h('cron.enabled'); ?></label>
          </div>

          <div class="form-group">
            <label for="cronInterval"><?php echo tdm_h('cron.interval'); ?></label>
            <select id="cronInterval" name="interval_minutes" class="custom-select custom-select-sm">
              <option value="5"><?php echo tdm_h('cron.interval.5m'); ?></option>
              <option value="15"><?php echo tdm_h('cron.interval.15m'); ?></option>
              <option value="30"><?php echo tdm_h('cron.interval.30m'); ?></option>
              <option value="60"><?php echo tdm_h('cron.interval.1h'); ?></option>
              <option value="120"><?php echo tdm_h('cron.interval.2h'); ?></option>
              <option value="180"><?php echo tdm_h('cron.interval.3h'); ?></option>
              <option value="360"><?php echo tdm_h('cron.interval.6h'); ?></option>
              <option value="720" selected><?php echo tdm_h('cron.interval.12h'); ?></option>
              <option value="1440"><?php echo tdm_h('cron.interval.24h'); ?></option>
            </select>
          </div>

          <div class="mt-2 small text-muted">
            <?php echo tdm_h('cron.note'); ?>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button id="cronSave" type="button" class="btn btn-success btn-sm"><?php echo tdm_h('button.save'); ?></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal"><?php echo tdm_h('modal.close'); ?></button>
      </div>
    </div>
  </div>
</div>



<!-- JS: jQuery + Popper + Bootstrap 4 -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" crossorigin="anonymous"></script>

<script>
var TDM_I18N = <?php echo json_encode(array(
  'js.device_unknown' => tdm_t('js.device_unknown'),
  'js.smart_running' => tdm_t('js.smart_running'),
  'js.no_output' => tdm_t('js.no_output'),
  'js.error' => tdm_t('js.error'),
  'js.no_led_command' => tdm_t('js.no_led_command'),
  'js.executed' => tdm_t('js.executed'),
  'js.http_error' => tdm_t('js.http_error'),
  'js.led_busy' => tdm_t('js.led_busy'),
  'js.led_on_short' => tdm_t('js.led_on_short'),
  'js.led_off_short' => tdm_t('js.led_off_short'),
  'js.led_turn_on' => tdm_t('js.led_turn_on'),
  'js.led_turn_off' => tdm_t('js.led_turn_off'),
  'js.refresh_error' => tdm_t('js.refresh_error'),
  'js.completed' => tdm_t('js.completed'),
  'js.api_load_error' => tdm_t('js.api_load_error'),
  'js.api_save_error' => tdm_t('js.api_save_error'),
  'js.api_saved' => tdm_t('js.api_saved'),
  'js.api_disabled' => tdm_t('js.api_disabled'),
  'api.status_configured' => tdm_t('api.status_configured'),
  'api.status_not_configured' => tdm_t('api.status_not_configured'),
  'api.test_ok' => tdm_t('api.test_ok'),
  'api.test_fail' => tdm_t('api.test_fail'),
  'api.test_error' => tdm_t('api.test_error'),
  'theme.light' => tdm_t('theme.light'),
  'theme.dark' => tdm_t('theme.dark'),
  'smart.output.title' => tdm_t('smart.output.title'),
  'cron.saved' => tdm_t('cron.saved'),
  'cron.load_error' => tdm_t('cron.load_error'),
  'cron.save_error' => tdm_t('cron.save_error')
), JSON_UNESCAPED_SLASHES); ?>;

function tdmMsg(key, vars) {
  var text = TDM_I18N[key] || key;
  vars = vars || {};
  Object.keys(vars).forEach(function(name){
    text = text.replace('{' + name + '}', vars[name]);
  });
  return text;
}

function setThemeMode(mode) {
  var light = mode === 'light';
  document.documentElement.classList.toggle('theme-light', light);
  try {
    if (light) localStorage.setItem('tdmTheme', 'light');
    else localStorage.removeItem('tdmTheme');
  } catch (e) {}

  var btn = document.getElementById('btnTheme');
  if (btn) {
    btn.textContent = light ? tdmMsg('theme.dark') : tdmMsg('theme.light');
    btn.setAttribute('aria-pressed', light ? 'true' : 'false');
  }
}

document.addEventListener('DOMContentLoaded', function(){
  var light = document.documentElement.classList.contains('theme-light');
  setThemeMode(light ? 'light' : 'dark');

  var btn = document.getElementById('btnTheme');
  if (btn) {
    btn.addEventListener('click', function(){
      var next = document.documentElement.classList.contains('theme-light') ? 'dark' : 'light';
      setThemeMode(next);
    });
  }
});

function openDriveModal(slot, name, serial, smart, locatie, cmdOn, cmdOff, poolName, isSpare, meta, counters, statusName, vdevLabel) {
  meta = meta || {};
  var nameWithSerial = name || 'Empty';
  if (serial) nameWithSerial += ' [ ' + serial + ' ]';
  var normalizedStatus = (statusName || 'UNKNOWN').toString().trim().toUpperCase();
  if (!normalizedStatus) normalizedStatus = 'UNKNOWN';
  var statusSlug = normalizedStatus.toLowerCase().replace(/_/g, '-').replace(/[^a-z0-9-]/g, '') || 'unknown';

  // slot & restul
  document.getElementById('mSlot').textContent  = '#' + slot;
  document.getElementById('mHeroSlot').textContent = 'Slot #' + slot;
  document.getElementById('mHeroDevice').textContent = nameWithSerial;
  document.getElementById('mLocatieDisk').textContent = locatie || '-';
  document.getElementById('mSmart').textContent = smart || '-';
  document.getElementById('mModel').textContent = meta.model || '-';
  document.getElementById('mCapacity').textContent = meta.capacity || '-';
  document.getElementById('mPowerHours').textContent = meta.power_hours ? Number(meta.power_hours).toLocaleString() + 'h' : '-';
  document.getElementById('mTemperature').textContent = meta.temperature_c ? meta.temperature_c + 'C' : '-';
  document.getElementById('mCounters').textContent = counters || '-';

  var statusPill = document.getElementById('mStatusPill');
  if (statusPill) {
    statusPill.textContent = normalizedStatus;
    statusPill.className = 'modal-status-pill status-' + statusSlug;
  }

  // Pool + VDEV
  var poolText = poolName || '-';
  if (vdevLabel) poolText += ' / ' + vdevLabel;
  if (isSpare) poolText += (poolText !== '-' ? ' ' : '') + '(SPARE)';
  document.getElementById('mPool').textContent = poolText;
  document.getElementById('mVdev').textContent = vdevLabel || '-';

  // --- Nume (granular) ---
  var devSpan    = document.getElementById('mNameDev');
  var serialSpan = document.getElementById('mNameSerial');
  var brL        = document.getElementById('mNameBrL');
  var brR        = document.getElementById('mNameBrR');
  var fullWrap   = document.getElementById('mNameFull');

  devSpan.textContent = name || 'Empty';
  fullWrap.setAttribute('data-full', nameWithSerial);

  if (serial) {
    serialSpan.textContent = serial;
    serialSpan.style.display = '';
    brL.style.display = '';
    brR.style.display = '';
  } else {
    serialSpan.textContent = '';
    serialSpan.style.display = 'none';
    brL.style.display = 'none';
    brR.style.display = 'none';
  }

  document.getElementById('btnOn').dataset.cmd  = cmdOn || '';
  document.getElementById('btnOff').dataset.cmd = cmdOff || '';

  // Extract the device path from the first token in the display name.
  var devForSmart = (name || '').trim().split(/\s+/)[0] || '';

  // Store it on the SMART button for the click handler.
  var btnSmart = document.getElementById('btnSmart');
  if (btnSmart) btnSmart.dataset.device = devForSmart;

  // Set a helpful SMART modal title.
  var smartTitle = document.querySelector('#smartModal .modal-title');
  if (smartTitle) smartTitle.textContent = tdmMsg('smart.output.title') + ' - ' + (devForSmart || '?');

  $('#driveModal').modal('show');
}
</script>

<script>
$(function(){
  $('#btnSmart').on('click', function(){
    var dev = this.dataset.device || '';
    if (!dev) { alert(tdmMsg('js.device_unknown')); return; }

    $('#smartOutput').text(tdmMsg('js.smart_running', {device: dev}));
    $('#smartModal').modal('show');

    $.post('smart_run.php', { device: dev })
      .done(function(resp){ $('#smartOutput').text(resp || tdmMsg('js.no_output')); })
      .fail(function(xhr){ $('#smartOutput').text(tdmMsg('js.error', {message: (xhr.responseText || xhr.status)})); });
  });
});
</script>


<script>
  function controlLed(cmd, opts) {
    opts = opts || {};
    if (!cmd) { alert(tdmMsg('js.no_led_command')); return; }

    // Resolve buttons in this scope.
    var btnOn  = document.getElementById('btnOn');
    var btnOff = document.getElementById('btnOff');
    var buttons = [btnOn, btnOff];
    if (opts.button) buttons.push(opts.button);
    buttons.forEach(b => b && (b.disabled = true));

    return $.post('led_control.php', { cmd: cmd })
      .done(function(resp){
        if (!opts.silentSuccess) {
          alert(resp || tdmMsg('js.executed', {cmd: cmd}));
        }
      })
      .fail(function(xhr){ alert(tdmMsg('js.http_error', {status: xhr.status}) + '\n' + (xhr.responseText||'')); })
      .always(function(){
        buttons.forEach(b => b && (b.disabled = false));
      });
  }

  function runLed(cmd) { controlLed(cmd); }

  function toggleTileLed(event, btn) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    var wasOn = btn.dataset.state === 'on';
    var cmd = wasOn ? btn.dataset.off : btn.dataset.on;
    var previousText = btn.textContent;
    btn.textContent = tdmMsg('js.led_busy');

    controlLed(cmd, { button: btn, silentSuccess: true })
      .done(function(){
        var nowOn = !wasOn;
        btn.dataset.state = nowOn ? 'on' : 'off';
        btn.classList.toggle('active', nowOn);
        btn.textContent = nowOn ? tdmMsg('js.led_on_short') : tdmMsg('js.led_off_short');
        btn.title = nowOn ? tdmMsg('js.led_turn_off') : tdmMsg('js.led_turn_on');
      })
      .fail(function(){
        btn.textContent = previousText || tdmMsg('js.led_off_short');
      });
  }
</script>


<script>
(function() {
  // Toggle full pool names.
  var chk = document.getElementById('toggleShort');
  if (chk) {
    document.body.classList.remove('show-full-pool');
    chk.addEventListener('change', function(){
      if (chk.checked) document.body.classList.remove('show-full-pool');
      else document.body.classList.add('show-full-pool');
    });
  }

  // Search and pool filtering.
  var input   = document.getElementById('diskSearch');
  var btnClr  = document.getElementById('btnClearSearch');
  var selPool = document.getElementById('poolFilter');

  function norm(s){
    if (!s) return '';
    s = s.toString().trim();
    if (s.toLowerCase().indexOf('/dev/') === 0) s = s.slice(5);
    return s.toLowerCase();
  }
  function parseQuery(q){
    return q.split(/\s+/).map(norm).filter(Boolean);
  }

  function tileMatches(t, terms, selectedPool){
    var dev  = (t.getAttribute('data-device') || '').toLowerCase();
    var ser  = (t.getAttribute('data-serial') || '').toLowerCase();
    var model = (t.getAttribute('data-model') || '').toLowerCase();
    var capacity = (t.getAttribute('data-capacity') || '').toLowerCase();
    var pool = (t.getAttribute('data-pool')   || '').toLowerCase();

    // OR pe termeni (sau niciun termen => match)
    var searchMatch = true;
    if (terms.length){
      searchMatch = false;
      for (var i=0;i<terms.length;i++){
        var term = terms[i];
        if ((dev && dev.indexOf(term) !== -1) ||
            (ser && ser.indexOf(term) !== -1) ||
            (model && model.indexOf(term) !== -1) ||
            (capacity && capacity.indexOf(term) !== -1)) {
          searchMatch = true; break;
        }
      }
    }

    var poolMatch = !selectedPool || pool === selectedPool;
    return searchMatch && poolMatch;
  }

  function applyFilter(){
    var terms = parseQuery(input ? input.value : '');
    var selectedPool = selPool ? norm(selPool.value) : '';
    var tiles = document.querySelectorAll('.hdd-tile');

    tiles.forEach(function(t){
      var hit = tileMatches(t, terms, selectedPool);
      if (hit){
        t.classList.remove('tile-hidden');
      } else {
        t.classList.add('tile-hidden');
      }
      // Highlight matches only when search terms are present.
      if (terms.length && hit) t.classList.add('tile-hit'); else t.classList.remove('tile-hit');
    });
  }

  if (input)   input.addEventListener('input', applyFilter);
  if (selPool) selPool.addEventListener('change', applyFilter);
  if (btnClr)  btnClr.addEventListener('click', function(){ input.value=''; applyFilter(); input.focus(); });
  
  var btnPoolClr = document.getElementById('btnClearPool');
  if (btnPoolClr) btnPoolClr.addEventListener('click', function(){  selPool.value='';  applyFilter();});

  // init
  applyFilter();
})();
</script>

<script>
(function(){
  var $modal     = $('#regenModal');
  var $spinner   = $('#regenSpinner');
  var $out       = $('#regenOutput');
  var $btnOpen   = $('#btnRegen');
  var $btnClose  = $('#regenClose, #regenCloseX');
  var $btnReload = $('#regenReload');

  function hideSpinnerEnableButtons(){
    $spinner.addClass('d-none');
    $btnClose.prop('disabled', false);
    $btnReload.prop('disabled', false);
  }

  function startRun(){
    // reset UI
    $out.text('');
    $spinner.removeClass('d-none');
    $btnClose.prop('disabled', true);
    $btnReload.prop('disabled', true);

    $modal.modal({backdrop:'static', keyboard:false, show:true});

    $.ajax({
      url: 'run_regen.php',
      method: 'POST',
      data: { mode: 'cu_smart' },
      dataType: 'text',
      cache: false,
      timeout: 0
    })
    .done(function(resp){
      $out.text(resp || tdmMsg('js.no_output'));

      // Hide the overlay as soon as the completion marker appears.
      if (/\=\=\=\s*COMPLETE\s*\=\=\=/.test(resp || '')) {
        $out.append("\n" + tdmMsg('js.completed'));
        hideSpinnerEnableButtons();
      }
    })
    .fail(function(xhr, status, err){
      $out.text(tdmMsg('js.refresh_error', {status: status}) + '\n' + (xhr.responseText || err || ''));
      hideSpinnerEnableButtons();
    })
    .always(function(){
      // Fallback: hide the overlay if it is still visible.
      hideSpinnerEnableButtons();
      $out.scrollTop($out[0].scrollHeight);
    });
  }

  $btnOpen.on('click', startRun);
  $btnReload.on('click', function(){ location.reload(); });
})();
</script>

<script>
(function(){
  var $modal = $('#apiSettingsModal');
  var $open = $('#btnApiSettings');
  var $save = $('#apiSave');
  var $disable = $('#apiDisable');
  var $test = $('#apiTest');
  var $url = $('#apiUrl');
  var $key = $('#apiKey');
  var $verify = $('#apiVerifyTls');
  var $status = $('#apiStatusText');
  var $masked = $('#apiKeyMasked');

  function setBusy(busy){
    $save.prop('disabled', busy);
    $disable.prop('disabled', busy);
    $test.prop('disabled', busy);
  }

  function applySettings(data){
    $url.val(data.api_url || data.suggested_url || '');
    // If we're showing a suggestion, use placeholder to indicate it
    if (!data.api_url && data.suggested_url) {
      $url.attr('placeholder', data.suggested_url + ' (auto-detected)');
    }
    $key.val('');
    $verify.prop('checked', !!data.verify_tls);
    $status.text(data.configured ? tdmMsg('api.status_configured') : tdmMsg('api.status_not_configured'));
    $masked.text(data.api_key_masked ? '(' + data.api_key_masked + ')' : '');
  }

  function loadSettings(){
    setBusy(true);
    $.getJSON('api_settings.php')
      .done(applySettings)
      .fail(function(xhr){
        alert(tdmMsg('js.api_load_error') + '\n' + (xhr.responseText || xhr.status));
      })
      .always(function(){ setBusy(false); });
  }

  function saveSettings(action){
    setBusy(true);
    $.ajax({
      url: 'api_settings.php',
      method: 'POST',
      dataType: 'json',
      data: {
        action: action || 'save',
        api_url: $url.val(),
        api_key: $key.val(),
        verify_tls: $verify.is(':checked') ? '1' : '0'
      }
    })
    .done(function(data){
      applySettings(data);
      alert(action === 'disable' ? tdmMsg('js.api_disabled') : tdmMsg('js.api_saved'));
    })
    .fail(function(xhr){
      var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : (xhr.responseText || xhr.status);
      alert(tdmMsg('js.api_save_error') + '\n' + msg);
    })
    .always(function(){ setBusy(false); });
  }

  $open.on('click', function(){
    $modal.modal('show');
    loadSettings();
  });

  $save.on('click', function(){ saveSettings('save'); });
  $disable.on('click', function(){ saveSettings('disable'); });

  $test.on('click', function(){
    setBusy(true);
    var prevText = $test.text();
    $test.text('…').prop('disabled', true);
    $.ajax({
      url: 'api_test.php',
      method: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      data: JSON.stringify({
        api_url: $url.val(),
        api_key: $key.val(),
        verify_tls: $verify.is(':checked')
      })
    })
    .done(function(data){
      if (data && data.ok) {
        alert(data.message || tdmMsg('api.test_ok'));
      } else {
        alert((data && data.message) || tdmMsg('api.test_fail'));
      }
    })
    .fail(function(xhr){
      alert(tdmMsg('api.test_error') + '\n' + (xhr.responseText || xhr.status));
    })
    .always(function(){
      $test.text(prevText);
      setBusy(false);
    });
  });
})();
</script>


<script>
(function(){
  function copyToClipboard(text){
    text = (text||'').trim();
    if (!text) return Promise.resolve();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function(resolve,reject){
      var ta=document.createElement('textarea');
      ta.value=text; ta.style.position='fixed'; ta.style.opacity='0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(); }
      catch(e){ reject(e); }
      finally { document.body.removeChild(ta); }
    });
  }

  var hideT=null;
  function showCopyHint(value){
    var $hint = $('#copyHint');
    $hint.stop(true,true).removeClass('fadeout').show();
    $hint.find('.val').text(value);
    clearTimeout(hideT);
    hideT=setTimeout(function(){
      $hint.addClass('fadeout');
      setTimeout(function(){ $hint.hide().removeClass('fadeout'); }, 400);
    }, 3500);
  }

  function shortDev(text){
    // din "/dev/sde" -> "sde"
    text = (text||'').trim();
    return text.toLowerCase().indexOf('/dev/')===0 ? text.slice(5) : text;
  }

  // handler unic, pe modal
  $('#driveModal').on('shown.bs.modal', function(){
    var $m = $(this);

    // Double-click the name wrapper to copy the full value.
    $m.find('#mNameFull').off('dblclick.copyFull').on('dblclick.copyFull', function(e){
      // Let the device/serial handlers handle exact child targets.
      if ($(e.target).is('#mNameDev, #mNameSerial')) return;
      var full = $(this).attr('data-full') || '';
      copyToClipboard(full).then(function(){ showCopyHint(full); });
    });

    // Double-click the device to copy only "sde".
    $m.find('#mNameDev').off('dblclick.copyDev').on('dblclick.copyDev', function(){
      var v = shortDev($(this).text());
      if (!v) return;
      copyToClipboard(v).then(function(){ showCopyHint(v); });
    });

    // Double-click the serial to copy only the serial.
    $m.find('#mNameSerial').off('dblclick.copySer').on('dblclick.copySer', function(){
      var v = ($(this).text()||'').trim();
      if (!v || v==='-') return;
      copyToClipboard(v).then(function(){ showCopyHint(v); });
    });

    // Other copyable fields copy their full text.
    $m.find('.copyable').off('dblclick.copyGeneric').on('dblclick.copyGeneric', function(e){
      // Do not override the special handlers above.
      if (this.id==='mNameFull' || this.id==='mNameDev' || this.id==='mNameSerial') return;
      var v = ($(this).text()||'').trim();
      if (!v || v==='-') return;
      copyToClipboard(v).then(function(){ showCopyHint(v); });
    });
  }).on('hide.bs.modal', function(){
    clearTimeout(hideT);
    $('#copyHint').hide().removeClass('fadeout');
  });
})();
</script>



<div style="
position:fixed;
bottom:10px;
right:14px;
font-size:12px;
color:#9aa3ad;
opacity:0.7;
text-align:right;
line-height:1.2;
">
NAS Disk Control<br>
Version <?php echo htmlspecialchars($app_version); ?><br>
SES + SMART + TrueNAS
</div>

<script>
// ── Auto-refresh status polling ────────────────────────────────────
// Polls refresh_status.php every 30s. Shows a badge while a background
// refresh is running, and auto-reloads the page when new data is ready.
(function(){
  var $badge = $('#autoRefreshBadge');
  var POLL_INTERVAL = 30000; // 30 seconds
  var lastRunAt = null;      // track last completed refresh timestamp
  var wasRunning = false;

  function poll(){
    $.getJSON('refresh_status.php')
      .done(function(data){
        if (!data) return;

        var running = !!data.running;

        // Show/hide badge
        if (running) {
          $badge.show();
        } else {
          $badge.hide();
        }

        // Auto-reload when a refresh just finished and we have new data
        if (wasRunning && !running && data.last_run_at && data.last_status === 'ok') {
          if (data.last_run_at !== lastRunAt) {
            // New refresh completed — reload page to pick up fresh data
            location.reload();
            return;
          }
        }

        // Track state for next poll
        wasRunning = running;
        if (!running && data.last_run_at) {
          lastRunAt = data.last_run_at;
        }
      })
      .fail(function(){
        // Silently ignore — endpoint may not exist yet
      });
  }

  // First poll after a short delay, then every POLL_INTERVAL
  setTimeout(function(){
    poll();
    setInterval(poll, POLL_INTERVAL);
  }, 5000);
})();
</script>

<script>
// ── Cron config modal ───────────────────────────────────────────────
(function(){
  var $modal  = $('#cronConfigModal');
  var $open   = $('#btnCronConfig');
  var $save   = $('#cronSave');
  var $enabled = $('#cronEnabled');
  var $interval = $('#cronInterval');

  function loadConfig(){
    $save.prop('disabled', true);
    $.getJSON('cron_config.php')
      .done(function(data){
        $enabled.prop('checked', !!data.enabled);
        $interval.val(data.interval_minutes || 720);
      })
      .fail(function(xhr){
        alert(tdmMsg('cron.load_error') + '\n' + (xhr.responseText || xhr.status));
      })
      .always(function(){ $save.prop('disabled', false); });
  }

  function saveConfig(){
    $save.prop('disabled', true);
    $.ajax({
      url: 'cron_config.php',
      method: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      data: JSON.stringify({
        enabled: $enabled.is(':checked'),
        interval_minutes: parseInt($interval.val(), 10) || 720
      })
    })
    .done(function(){
      alert(tdmMsg('cron.saved'));
      $modal.modal('hide');
    })
    .fail(function(xhr){
      var msg = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : (xhr.responseText || xhr.status);
      alert(tdmMsg('cron.save_error') + '\n' + msg);
    })
    .always(function(){ $save.prop('disabled', false); });
  }

  $open.on('click', function(){
    $modal.modal('show');
    loadConfig();
  });

  $save.on('click', saveConfig);
})();
</script>

</body>
</html>
