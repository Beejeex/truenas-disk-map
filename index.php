<?php

require_once __DIR__ . "/i18n.php";

$app_version = "0.1.5";



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
    );
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
    );

    if ((int)$meta['load_cycle_count'] > 0)
    {
        $bits[] = 'Load=' . number_format((int)$meta['load_cycle_count']);
    }

    if ($meta['temperature_c'] !== '')
    {
        $bits[] = 'Temp=' . (int)$meta['temperature_c'] . 'C';
    }

    return implode(' / ', $bits);
}



// ======================== ÎNCĂRCARE DISCURi NEFOLOSITE ========================
$unused_by_disk = array(); // ex: 'sdah' => true

$unused_file = __DIR__ . "/hdd_controlere/disk_unused_no_pool.txt";
if (is_file($unused_file)) {
    $lines_unused = file($unused_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines_unused)) {
        foreach ($lines_unused as $u) {
            $u = trim($u);
            if ($u !== '') {
                // păstrăm fără /dev/, în fișier ele sunt doar "sdX"
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
    $c = get_controller_from_file($filepath);
    if ($c === 0) return 4;   // c_0 => 4 coloane
    if ($c === 1) return 15;  // c_1 => 15 coloane
    return 4;
}

// —— c_0: „pe coloane”, rândul de sus are numerele mari
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

// —— c_1: „pe rânduri” invers (sus sunt ultimele blocuri), stânga→dreapta în fiecare rând
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

// ======= INFO ACTUALIZARE FISIERE hdd_controlere =======
$hc_dir = __DIR__ . '/hdd_controlere';
$all_hc_files = glob($hc_dir . '/*');  // include *_ses + txt-uri
$latest_mtime = 0;                     // cel mai recent
$oldest_mtime = PHP_INT_MAX;           // cel mai vechi
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
$age_sec  = ($latest_mtime > 0) ? ($now_ts - $latest_mtime) : null; // de la cel mai recent
$age_text = ($age_sec !== null) ? human_time_diff($age_sec) : 'n/a';
$latest_dt= ($latest_mtime > 0) ? date('Y-m-d H:i:s', $latest_mtime) : 'n/a';

// prag alertă = 24h
$is_stale = ($age_sec !== null && $age_sec > 24*3600);


// ======================== ÎNCĂRCARE POOL-URI (procedural) ========================
$pool_by_disk  = array();
$spare_by_disk = array();
$pool_names    = array();

$pool_file = __DIR__ . "/hdd_controlere/disk_per_pool.txt";
if (is_file($pool_file)) {
    $raw = file_get_contents($pool_file);
    if ($raw !== false) {
        if (preg_match_all('/\{.*?\}/s', $raw, $m)) {
            foreach ($m[0] as $obj) {
                $data = json_decode($obj, true);
                if (is_array($data)) {
                    $pool_name = isset($data['name']) ? trim($data['name']) : '';
                    if ($pool_name !== '') {
                        $pool_names[$pool_name] = true;              // ← ADĂUGAT
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


// ... după ce ai populat $unused_by_disk ...
if (!empty($unused_by_disk)) {
    $pool_names['NEFOLOSIT'] = true;
}

$pool_options = array_keys($pool_names);
sort($pool_options, SORT_NATURAL | SORT_FLAG_CASE);



// ======================== ÎNCĂRCARE DATE PER FIȘIER ========================
$files = glob("hdd_controlere/*_ses");
$panels = [];

foreach ($files as $file)
{
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) continue;

    $controller = get_controller_from_file($file);
    $cols       = cols_for_file($file);
    $tiles      = [];
    $title      = null;
    $max_slot   = 0;

    foreach ($lines as $line)
    {
        // serial | device | locatie | slot | smart | cmd_on | cmd_off
        $parts = explode("|", $line);
        if (count($parts) < 7) continue;

        list($serial, $device, $locatie, $slot, $smart, $cmd_on, $cmd_off) = $parts;
        $meta = tdm_ses_meta_from_parts($parts);

        if ($title === null) $title = trim($locatie);

        // ===== CLASĂ DIN SMART (adăugat SPARE) =====
        $class = "smart-ok";
        if (stripos($smart, "DEAD") !== false || stripos($smart, "DANGEROUS") !== false || stripos($smart, "MORT") !== false || stripos($smart, "PERICULOS") !== false) {
			$class = "smart-bad";
		} elseif (stripos($smart, "SUSPECT") !== false || stripos($smart, "WARNING") !== false || stripos($smart, "UNKNOWN") !== false || trim($smart) === "X" || stripos($smart, "TIRED") !== false || stripos($smart, "OBOSIT") !== false) {
			$class = "smart-warn";
		} elseif (stripos($smart, "SPARE") !== false) {
			$class = "smart-spare";
		}


        $pozitia = (int)$slot + 1; // 1-based
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

<style>



/* --- Legendă: buline independente (nu absolute) --- */
.led-dot-legend{
  display:inline-block;
  width:12px;
  height:12px;
  border-radius:50%;
  margin-right:10px;
  vertical-align:middle;
}

/* Culori identice cu cele din grid */
.led-dot-legend.smart-ok    { background:#2bff6a; box-shadow:0 0 8px rgba(43,255,106,.8); }
.led-dot-legend.smart-warn  { background:#ffd24a; box-shadow:0 0 8px rgba(255,210,74,.8); }
.led-dot-legend.smart-bad   { background:#ff4a4a; box-shadow:0 0 8px rgba(255,74,74,.85); }
.led-dot-legend.smart-spare { background:#e9ecef; box-shadow:0 0 6px rgba(233,236,239,.6); }
.led-dot-legend.empty       { background:#111;    box-shadow:inset 0 0 3px rgba(255,255,255,.2); }
.led-dot-legend.smart-unused{ background:#2da8ff; box-shadow:0 0 10px rgba(45,168,255,.9); }

/* Layout legendă */
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
#regenSpinner { pointer-events: none; } /* overlay-ul nu va bloca click-urile */
#regenSpinner .text-center { pointer-events: auto; } /* dar textul/spinnerul le primește când e vizibil */


#driveModal .copyable { cursor: copy; }
#copyHint.fadeout { transition: opacity .4s ease; opacity: 0; }



.tile-hidden { display: none !important; }
.tile-hit { outline-color: rgba(45,168,255,.9) !important; box-shadow: 0 0 0 2px rgba(45,168,255,.3) inset; }



/* implicit: arătăm scurt, ascundem complet */
.pool-full { display: none; }
.pool-short { display: inline; }

/* când body are clasa .show-full-pool, inversăm */
.show-full-pool .pool-full { display: inline; }
.show-full-pool .pool-short { display: none; }


:root{
  --c1-scale: calc(685 / 202);   /* ≈ 3.389 */
  --c1-unscale: calc(202 / 685); /* ≈ 0.295 – inversul */
}



  body { background: #1b1e23; color: #e6e6e6; }
  .page-wrap { min-height: 100vh; padding: 24px 0 48px 0; }
  .nas-panel {
    background: #232730; border-radius: 14px;
    box-shadow: 0 12px 30px rgba(0,0,0,0.4), inset 0 0 0 1px rgba(255,255,255,0.03);
    padding: 18px; margin-bottom: 26px;
  }
  .nas-title { font-weight: 600; letter-spacing: .3px; color: #f0f0f0; }
  .file-pill { font-size: 12px; padding: 2px 8px; border-radius: 999px; background: rgba(255,255,255,.06); color: #cbd3da; }

  /* Grid: fără înălțime fixă – tile-ul decide dimensiunea */
  .nas-grid { display: grid; grid-row-gap: 14px; grid-column-gap: 14px; }
  
  
  
  /* --- tile-ul default (C_0) orizontal --- */
.hdd-tile{
  position:relative; border-radius:10px; overflow:hidden; cursor:pointer;
  outline:2px solid rgba(255,255,255,.06);
  box-shadow: inset 0 -20px 30px rgba(0,0,0,.35), 0 8px 18px rgba(0,0,0,.35);
  aspect-ratio: 685 / 202;        /* landscape by default */
  background-color:#2a2f3a;
}

/* --- C_1: cutie portret (grid 4×15) --- */
.panel-c1 .hdd-tile{
  aspect-ratio: 202 / 685;        /* portrait box */
}

/* CONȚINUT – folosim ca holder pentru imagine + LED + overlay */
.hdd-content{
  position:absolute; inset:0;
  background-position:center;
  background-repeat:no-repeat;
  background-size: 100% 100%;     /* întinde exact pe cutie */
}

/* ====== IMAGINI ======
   C_0 (orizontale) – rămân cele vechi
*/
.hdd-tile.smart-ok    .hdd-content{ background-image:url('src/img/HDD_OK.png'); }
.hdd-tile.smart-warn  .hdd-content{ background-image:url('src/img/HDD_WARNING.png'); }
.hdd-tile.smart-bad   .hdd-content{ background-image:url('src/img/HDD_ISSUE_PROBLEMS.png'); }
.hdd-tile.smart-spare .hdd-content{ background-image:url('src/img/HDD_SPARE.png'); }
.hdd-tile.empty       .hdd-content{ background-image:url('src/img/HDD_SLOT_EMPTY.png'); }
.hdd-tile.smart-unused  .hdd-content{ background-image:url('src/img/HDD_UNUSED.png'); }


/* C_1 (portret) – folosim imaginile NOI, deja ROTITE */
.panel-c1 .hdd-tile.smart-ok    .hdd-content{ background-image:url('src/img/HDD_OK_rotated.png'); }
.panel-c1 .hdd-tile.smart-warn  .hdd-content{ background-image:url('src/img/HDD_WARNING_rotated.png'); }
.panel-c1 .hdd-tile.smart-bad   .hdd-content{ background-image:url('src/img/HDD_ISSUE_PROBLEMS_rotated.png'); }
.panel-c1 .hdd-tile.smart-spare .hdd-content{ background-image:url('src/img/HDD_SPARE_rotated.png'); }
.panel-c1 .hdd-tile.empty       .hdd-content{ background-image:url('src/img/HDD_SLOT_EMPTY_rotated.png'); }
.panel-c1 .hdd-tile.smart-unused .hdd-content{ background-image:url('src/img/HDD_UNUSED_rotated.png'); }


/* ====== TEXT DOAR ROTIT ÎN C_1 ======
   Rotim overlay-ul cu −90°, fără scale. Îl ancorăm jos-stânga.
*/
.hdd-overlay{
  position:absolute; left:0; right:0; bottom:0; padding:8px 10px;
  background:linear-gradient(to top, rgba(0,0,0,.6), rgba(0,0,0,0));
  text-shadow: 0 1px 2px rgba(0,0,0,.7);
  z-index: 2;                 /* asigură-te că e peste imagine */
}



.panel-c1 .hdd-overlay{
  /* fără fundal, îl poziționăm pe stânga pe mijloc */
  background:none; padding:0;
  top:96%; left:45%; right:auto; bottom:auto;

  /* pivot pe mijlocul stâng; îl rotim și apoi îl aducem în centru vertical */
  transform-origin: left center;
  transform: translateY(-50%) rotate(-90deg);

  /* lăsăm lățimea pe auto ca să nu fie forțat la 100% */
  width:auto;
}
/* tipografie (poți rămâne cu ale tale) */
.slot-label{ font-size:13px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; margin:0; color:#e9ecef; }
.name-label{ font-size:12px; color:#cbd3da; margin:2px 0 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.meta-label{ font-size:11px; color:#9aa6b2; margin:1px 0 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.panel-c1 .slot-label{ font-size:14px; letter-spacing:.6px; }
.panel-c1 .name-label{ font-size:13px; }
.panel-c1 .meta-label{ display:none; }


/* LED – rămâne neschimbat (nu-l rotim acum) */
.led-dot{
  position:absolute; top:8px; right:8px;
  width:12px; height:12px; border-radius:50%;
}
.hdd-tile.smart-ok   .led-dot{ background:#2bff6a; box-shadow:0 0 8px rgba(43,255,106,.8); }
.hdd-tile.smart-warn .led-dot{ background:#ffd24a; box-shadow:0 0 8px rgba(255,210,74,.8); }
.hdd-tile.smart-bad  .led-dot{ background:#ff4a4a; box-shadow:0 0 8px rgba(255,74,74,.85); }
.hdd-tile.smart-spare .led-dot{ background:#e9ecef; box-shadow:0 0 6px rgba(233,236,239,.6); }
.hdd-tile.empty      .led-dot{ background:#fff; box-shadow:0 0 6px rgba(255,255,255,.7); }
.hdd-tile.smart-unused .led-dot{  background:#2da8ff;  box-shadow:0 0 10px rgba(45,168,255,.9);}

.tile-led-toggle{
  position:absolute;
  top:8px;
  left:8px;
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


/* hover fără transform – ca să nu stricăm pozițiile */
.hdd-tile:hover{ outline-color: rgba(255,255,255,.12); }

.detail-grid{
  display:grid;
  grid-template-columns: 110px minmax(0, 1fr);
  gap:6px 12px;
}
.detail-grid strong{ color:#f0f3f7; }
.detail-grid span{ min-width:0; overflow-wrap:anywhere; }

</style>
</head>
<body>
<div class="page-wrap">
<div class="container">


<div class="d-flex align-items-center justify-content-between mb-2"
     style="background:#1f2330;border-radius:10px;padding:8px 12px; border:1px solid rgba(255,255,255,0.06)">
  <div class="text-light">
    <strong><?php echo tdm_h('last_update.label'); ?></strong>:
    <span><?php echo htmlspecialchars($latest_dt); ?></span>
    <span class="text-muted">(<?php echo tdm_h('last_update.ago', array('age' => $age_text)); ?>)</span>
    <span class="text-muted ml-2">| <?php echo tdm_h('last_update.files', array('count' => (int)$files_count)); ?></span>
  </div>
  <div>
    <?php if ($is_stale): ?>
      <span style="color:#ff5858; font-weight:700;"><?php echo tdm_h('last_update.stale'); ?></span>
    <?php else: ?>
      <span class="text-success"><?php echo tdm_h('status.ok'); ?></span>
    <?php endif; ?>
  </div>

    <button id="btnRegen" type="button" class="btn btn-sm btn-outline-info ml-3">
    <?php echo tdm_h('refresh.button'); ?>
  </button>
  <button id="btnApiSettings" type="button" class="btn btn-sm btn-outline-light ml-2">
    <?php echo tdm_h('api.button'); ?>
  </button>

</div>


<div class="d-flex align-items-center justify-content-between mb-3"
     style="background:#232730;border-radius:10px;padding:10px 12px">
  <div class="text-light font-weight-bold"><?php echo tdm_h('display.options'); ?></div>

  <div class="d-flex align-items-center">
    <div class="mr-3 text-light">
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
	
<div class="ml-3 d-flex">
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

    // total
    $total = 0;
    if (isset($panel['max_slot'])) {
        $total = (int)$panel['max_slot'];
    }
    if ($total < 0) {
        $total = 0;
    }

    // controller
    $ctrl = 0;
    if (isset($panel['controller'])) {
        $ctrl = (int)$panel['controller'];
    }

    // order
    $order = array();
    if ($ctrl === 1) {
        $order = build_display_order_rowwise_reversed($total, $cols);
    } else {
        $order = build_display_order_colwise_top_high($total, $cols);
    }

    // rows
    $rows = 0;
    if ($total > 0) {
        $rows = (int)ceil($total / $cols);
    }

    // ordHint (primele 5)
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

    // clasa pentru panou (fără ternar)
    $panelClass = '';
    if ($ctrl === 1) {
        $panelClass = 'panel-c1';
    }

    // minw (fără ternar)
    $minw = 140;
    if ($ctrl === 1) {
        $minw = 60;
    }
?>
    <div class="nas-panel <?php echo $panelClass; ?>">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="nas-title mb-0"><?php echo htmlspecialchars($panel['title']); ?></h5>
        <div class="text-right">
          <div class="file-pill d-inline-block mr-2">
            <?php echo tdm_h('panel.file_info', array('file' => $panel['file'])); ?>
          </div>

          <?php
          // Dacă vrei să afișezi info de grid, folosește IF clasic:
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
        foreach ($order as $slotnum) {

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


			// === mapare device -> 'sda' etc. pt comparatie cu pool-urile ===
			$dev_short = $device_raw;
			
			if ($dev_short !== '') {
				// scoate prefixul /dev/ daca exista
				if (strpos($dev_short, '/dev/') === 0) {
					$dev_short = substr($dev_short, 5);
				}
			}

			// === determinare pool & spare ===
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
					$pool_name = $spare_pool_name; // dacă nu e în data_disks dar e în spare_disks, folosim numele din spares
				}
			}

			// === dacă e SPARE, forțăm clasa vizuală smart-spare ===
			// === NEFOLOSIT? (dacă apare în lista „unused”, ignorăm pool/spare) ===
			$is_unused = false;
			if ($dev_short !== '' && isset($unused_by_disk[$dev_short])) {
				$is_unused = true;
				$pool_name = 'NEFOLOSIT';
				$is_spare  = false; // nefolosit ≠ spare
			}
			


			// === clasa vizuală finală ===
			if ($is_unused) {
				$cls = 'smart-unused';
				$tileCls = 'hdd-tile smart-unused';
			} elseif ($is_spare) {
				$cls = 'smart-spare';
				$tileCls = 'hdd-tile smart-spare';
			} else {
				$tileCls = 'hdd-tile ' . ($has ? $cls : 'empty');
			}

			
			// --- short name (doar pentru pagina principală) ---
			$pool_name_short = $pool_name;
			if ($pool_name_short !== '') {
				if (strlen($pool_name_short) > 5) {
					$pool_name_short = substr($pool_name_short, 0, 4) . '...';
				}
			}


			// === label pentru Slot cu [pool] si - SPARE ===
			// --- short name (doar pe pagină) ---
			$pool_name_short = $pool_name;
			if ($pool_name_short !== '' && $pool_name_short !== 'NEFOLOSIT') {
				if (strlen($pool_name_short) > 5) {
					$pool_name_short = substr($pool_name_short, 0, 5) . '...';
				}
			}

			// --- label pentru Slot ---
			$slotLabelHtml = 'Slot #' . (int)$slotnum;
			if ($pool_name !== '') {
				$slotLabelHtml .= ' ['
					. '<span class="pool-short">' . htmlspecialchars($pool_name_short) . '</span>'
					. '<span class="pool-full">'  . htmlspecialchars($pool_name)        . '</span>'
					. ']';
			}
			if ($is_spare) {
				$slotLabelHtml .= ' - SPARE';
			}




            $tileCls = 'hdd-tile ';
            if ($has) {
                $tileCls .= $cls;
            } else {
                $tileCls .= 'empty';
            }

            // text nume + serial
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
              <?php echo tdm_js($counter_summary); ?>
			)"
			>
          <div class="hdd-content">
            <span class="led-dot" aria-hidden="true"></span>
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
              <p class="slot-label"><?php echo $slotLabelHtml; ?></p>
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
      <span class="led-dot-legend smart-warn"></span>
      <span><?php echo tdm_h('legend.warn'); ?></span>
    </div>

	<div class="legend-item">
	  <span class="led-dot-legend smart-bad"></span>
	  <span><?php echo tdm_h('legend.dead'); ?></span>
	</div>
	<div class="legend-item">
	  <span class="led-dot-legend smart-bad"></span>
	  <span><?php echo tdm_h('legend.danger'); ?></span>
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

  <ul class="mb-2 pl-3">

	<li class="mb-2">
	  <strong class="text-danger"><?php echo tdm_h('smart.criteria.dead'); ?></strong>
	  <ul class="mt-1">
		<li><?php echo tdm_h('smart.criteria.failed_overall'); ?></li>
		<li><?php echo tdm_h('smart.criteria.failed_selftest'); ?></li>
		<li><?php echo tdm_h('smart.criteria.pending_uncorrectable'); ?></li>
		<li><?php echo tdm_h('smart.criteria.reallocated_critical'); ?></li>
	  </ul>
	</li>
    <li class="mb-2">
      <strong class="text-danger"><?php echo tdm_h('smart.criteria.danger'); ?></strong>
      <ul class="mt-1">
        <li><?php echo tdm_h('smart.criteria.pending'); ?></li>
        <li><?php echo tdm_h('smart.criteria.uncorrectable'); ?></li>
        <li><?php echo tdm_h('smart.criteria.reallocated_danger'); ?></li>
      </ul>
    </li>

    <li class="mb-2">
      <strong class="text-warning"><?php echo tdm_h('smart.criteria.tired'); ?></strong>
      <ul class="mt-1">
        <li><?php echo tdm_h('smart.criteria.load_cycle'); ?></li>
        <li><?php echo tdm_h('smart.criteria.reallocated_tired'); ?></li>
      </ul>
    </li>

    <li class="mb-2">
      <strong class="text-info"><?php echo tdm_h('smart.criteria.suspect'); ?></strong>
      <ul class="mt-1">
        <li><?php echo tdm_h('smart.criteria.read_fail'); ?></li>
        <li><?php echo tdm_h('smart.criteria.reallocated_any'); ?></li>
        <li><?php echo tdm_h('smart.criteria.ata_errors'); ?></li>
      </ul>
    </li>
  </ul>

	 <small class="text-muted d-block">
	  <?php echo tdm_h('smart.criteria.note'); ?>
	</small>

</div>







</div>

</div>

<!-- Modal control LED -->
<div class="modal fade" id="driveModal" tabindex="-1" role="dialog" aria-labelledby="driveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
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
    <div><strong><?php echo tdm_h('modal.slot'); ?></strong> <span id="mSlot" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>"></span></div>

<!-- Nume: împărțit pe device/serial + wrapper pentru „full” -->
<div>
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
</div>

<div class="detail-grid mt-2">
  <strong><?php echo tdm_h('modal.model'); ?></strong>
  <span id="mModel" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">—</span>

  <strong><?php echo tdm_h('modal.capacity'); ?></strong>
  <span id="mCapacity" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">—</span>

  <strong><?php echo tdm_h('modal.power_hours'); ?></strong>
  <span id="mPowerHours" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">—</span>

  <strong><?php echo tdm_h('modal.temperature'); ?></strong>
  <span id="mTemperature" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">—</span>

  <strong><?php echo tdm_h('modal.disk_location'); ?></strong>
  <span id="mLocatieDisk" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">—</span>

  <strong><?php echo tdm_h('modal.state'); ?></strong>
  <span id="mSmart" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>"></span>

  <strong><?php echo tdm_h('modal.counters'); ?></strong>
  <span id="mCounters" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">—</span>

  <strong><?php echo tdm_h('modal.pool'); ?></strong>
  <span id="mPool" class="copyable" title="<?php echo tdm_h('modal.copy_field'); ?>">—</span>
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






<!-- Modal Reactualizare -->
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
        <button id="apiSave" type="button" class="btn btn-success btn-sm"><?php echo tdm_h('button.save'); ?></button>
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
  'smart.output.title' => tdm_t('smart.output.title')
), JSON_UNESCAPED_SLASHES); ?>;

function tdmMsg(key, vars) {
  var text = TDM_I18N[key] || key;
  vars = vars || {};
  Object.keys(vars).forEach(function(name){
    text = text.replace('{' + name + '}', vars[name]);
  });
  return text;
}

function openDriveModal(slot, name, serial, smart, locatie, cmdOn, cmdOff, poolName, isSpare, meta, counters) {
  meta = meta || {};
  var nameWithSerial = name || 'Empty';
  if (serial) nameWithSerial += ' [ ' + serial + ' ]';

  // slot & restul
  document.getElementById('mSlot').textContent  = '#' + slot;
  document.getElementById('mLocatieDisk').textContent = locatie || '—';
  document.getElementById('mSmart').textContent = smart || '—';
  document.getElementById('mModel').textContent = meta.model || '—';
  document.getElementById('mCapacity').textContent = meta.capacity || '—';
  document.getElementById('mPowerHours').textContent = meta.power_hours ? Number(meta.power_hours).toLocaleString() + 'h' : '—';
  document.getElementById('mTemperature').textContent = meta.temperature_c ? meta.temperature_c + 'C' : '—';
  document.getElementById('mCounters').textContent = counters || '—';

  // Pool
  var poolText = poolName || '—';
  if (isSpare) poolText += (poolText !== '—' ? ' ' : '') + '(SPARE)';
  document.getElementById('mPool').textContent = poolText;

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

  // 🔹 1) extragem device-ul (primul token din "name")
  var devForSmart = (name || '').trim().split(/\s+/)[0] || '';

  // 🔹 2) îl atașăm pe butonul SMART ca data-* (ca să-l avem la click)
  var btnSmart = document.getElementById('btnSmart');
  if (btnSmart) btnSmart.dataset.device = devForSmart;

  // 🔹 3) (opțional) setăm un titlu util în modalul SMART
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

    // ia butoanele DIN NOU în acest scope
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
  // toggle nume pool (rămâne ca la tine)
  var chk = document.getElementById('toggleShort');
  if (chk) {
    document.body.classList.remove('show-full-pool');
    chk.addEventListener('change', function(){
      if (chk.checked) document.body.classList.remove('show-full-pool');
      else document.body.classList.add('show-full-pool');
    });
  }

  // ------ Căutare + filtrare după pool ------
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
      // highlight doar când există termeni de căutare
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
    $spinner.addClass('d-none');                 // << în loc de .hide()
    $btnClose.prop('disabled', false);
    $btnReload.prop('disabled', false);
  }

  function startRun(){
    // reset UI
    $out.text('');
    $spinner.removeClass('d-none');              // << în loc de .show()
    $btnClose.prop('disabled', true);
    $btnReload.prop('disabled', true);

    $modal.modal({backdrop:'static', keyboard:false, show:true});

    $.ajax({
      url: 'run_regen.php',
      method: 'POST',
      data: { mode: 'cu_smart' },
      dataType: 'text',
      cache: false,                               // evită caching
      timeout: 0
    })
    .done(function(resp){
      $out.text(resp || tdmMsg('js.no_output'));

      // dacă a apărut marker-ul, ascundem imediat overlay-ul
      if (/\=\=\=\s*(COMPLET|COMPLETE)\s*\=\=\=/.test(resp || '')) {
        $out.append("\n" + tdmMsg('js.completed'));
        hideSpinnerEnableButtons();               // << AICI
      }
    })
    .fail(function(xhr, status, err){
      $out.text(tdmMsg('js.refresh_error', {status: status}) + '\n' + (xhr.responseText || err || ''));
      hideSpinnerEnableButtons();                 // arată butoanele la eroare
    })
    .always(function(){
      // fallback: dacă din orice motiv nu s-a ascuns deja, ascunde acum
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
  var $url = $('#apiUrl');
  var $key = $('#apiKey');
  var $verify = $('#apiVerifyTls');
  var $status = $('#apiStatusText');
  var $masked = $('#apiKeyMasked');

  function setBusy(busy){
    $save.prop('disabled', busy);
    $disable.prop('disabled', busy);
  }

  function applySettings(data){
    $url.val(data.api_url || '');
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

    // dblclick pe wrapperul „Nume:” => copiază întregul
    $m.find('#mNameFull').off('dblclick.copyFull').on('dblclick.copyFull', function(e){
      // dacă a fost exact pe dev/serial, lăsăm celelalte handlere să decidă
      if ($(e.target).is('#mNameDev, #mNameSerial')) return;
      var full = $(this).attr('data-full') || '';
      copyToClipboard(full).then(function(){ showCopyHint(full); });
    });

    // dblclick pe device => copiază doar „sde”
    $m.find('#mNameDev').off('dblclick.copyDev').on('dblclick.copyDev', function(){
      var v = shortDev($(this).text());
      if (!v) return;
      copyToClipboard(v).then(function(){ showCopyHint(v); });
    });

    // dblclick pe serial => copiază serialul
    $m.find('#mNameSerial').off('dblclick.copySer').on('dblclick.copySer', function(){
      var v = ($(this).text()||'').trim();
      if (!v || v==='—') return;
      copyToClipboard(v).then(function(){ showCopyHint(v); });
    });

    // celelalte câmpuri rămân „copiabile” ca întreg
    $m.find('.copyable').off('dblclick.copyGeneric').on('dblclick.copyGeneric', function(e){
      // să nu suprascriem logica specială de mai sus
      if (this.id==='mNameFull' || this.id==='mNameDev' || this.id==='mNameSerial') return;
      var v = ($(this).text()||'').trim();
      if (!v || v==='—') return;
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


</body>
</html>
