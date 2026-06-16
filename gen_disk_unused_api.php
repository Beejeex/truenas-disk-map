<?php
// gen_disk_unused_api.php
// Scop: listeaza toate disk-urile NEFOLOSITE in niciun pool (excluzand BOOT),
// folosind API-ul TrueNAS (fara midclt/jq) si scrie rezultatul in
// hdd_controlere/disk_unused_no_pool.txt

// ====== CONFIG ======
require_once __DIR__ . "/config_api.php";

$target_dir  = __DIR__ . "/hdd_controlere";
$target_file = $target_dir . "/disk_unused_no_pool.txt";

if (!is_dir($target_dir))
{
    if (!mkdir($target_dir, 0775, true))
    {
        throw new RuntimeException("Could not create directory: " . $target_dir);
    }
}

if (!tdm_api_configured())
{
    file_put_contents($target_file, "");
    echo "[INFO] TrueNAS API is not configured; unused disk labels were skipped.\n";
    return;
}

// ====== Functii helper ======
function api_request_get($url, $api_key, $verify_tls)
{
    $ch = curl_init();
    $headers = array(
        "Authorization: Bearer " . $api_key,
        "Accept: application/json"
    );
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($verify_tls)
    {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    else
    {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0)
    {
        throw new RuntimeException("cURL GET error ($errno): $err");
    }
    if ($code < 200 || $code >= 300)
    {
        throw new RuntimeException("HTTP $code for GET $url. Response: " . $resp);
    }

    return $resp;
}

function api_request_post($url, $api_key, $verify_tls, $payload_array_or_null)
{
    $ch = curl_init();
    $headers = array(
        "Authorization: Bearer " . $api_key,
        "Accept: application/json",
        "Content-Type: application/json"
    );

    if ($payload_array_or_null === null)
    {
        $payload = "";
    }
    else
    {
        $payload = json_encode($payload_array_or_null);
        if ($payload === false)
        {
            throw new RuntimeException("json_encode payload failed.");
        }
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($verify_tls)
    {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    else
    {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0)
    {
        throw new RuntimeException("cURL POST error ($errno): $err");
    }
    if ($code < 200 || $code >= 300)
    {
        throw new RuntimeException("HTTP $code for POST $url. Response: " . $resp);
    }

    return $resp;
}

function add_disk(&$set_assoc, $name)
{
    if ($name === null) return;
    $name = trim($name);
    if ($name === "") return;
    $set_assoc[$name] = true;
}

function collect_disks_from_node($node, &$set_assoc)
{
    if (!is_array($node)) return;

    if (array_key_exists("disk", $node))
    {
        add_disk($set_assoc, $node["disk"]);
    }
    if (array_key_exists("device", $node) && !array_key_exists("disk", $node))
    {
        add_disk($set_assoc, $node["device"]);
    }
    if (array_key_exists("children", $node) && is_array($node["children"]))
    {
        foreach ($node["children"] as $child)
        {
            collect_disks_from_node($child, $set_assoc);
        }
    }
}

function collect_disks_from_vdev_list($vdev_list, &$set_assoc)
{
    if (!is_array($vdev_list)) return;
    foreach ($vdev_list as $vdev)
    {
        collect_disks_from_node($vdev, $set_assoc);
    }
}

// ====== MAIN ======
// 1) Toate disk-urile: /disk
$disks_json = api_request_get($API_URL . "/disk", $API_KEY, $VERIFY_TLS);
$disks_arr  = json_decode($disks_json, true);
if (!is_array($disks_arr)) throw new RuntimeException("Invalid /disk response.");

$all_disks_set = array();
foreach ($disks_arr as $d)
{
    if (is_array($d) && array_key_exists("name", $d))
    {
        add_disk($all_disks_set, $d["name"]);
    }
}

// 2) Pool-uri: /pool
$pools_json = api_request_get($API_URL . "/pool", $API_KEY, $VERIFY_TLS);
$pools      = json_decode($pools_json, true);
if (!is_array($pools)) throw new RuntimeException("Invalid /pool response.");

// 3) BOOT disks: incercam GET, fallback POST /core/call
$boot_arr  = array();
$resp_ok   = true;

// GET direct
try {
    $resp = api_request_get($API_URL . "/boot/get_disks", $API_KEY, $VERIFY_TLS);
    $tmp  = json_decode($resp, true);
    if (is_array($tmp)) $boot_arr = $tmp; else $resp_ok = false;
} catch (Exception $e) {
    $resp_ok = false;
}

if (!$resp_ok)
{
    $resp = api_request_post($API_URL . "/core/call", $API_KEY, $VERIFY_TLS, array(
        "method" => "boot.get_disks",
        "params" => array()
    ));
    $tmp = json_decode($resp, true);
    if (is_array($tmp)) $boot_arr = $tmp;
}

$boot_set = array();
foreach ($boot_arr as $b)
{
    add_disk($boot_set, $b);
}

// 4) Discurile folosite in pool-uri
$used_set = array();
foreach ($pools as $pool)
{
    if (!is_array($pool)) continue;
    if (!array_key_exists("topology", $pool) || !is_array($pool["topology"])) continue;

    $top = $pool["topology"];

    foreach (array("data","cache","log","special","dedup","spare","spares") as $key)
    {
        if (array_key_exists($key, $top))
        {
            collect_disks_from_vdev_list($top[$key], $used_set);
        }
    }
}

// 5) Diferenta: ALL - (USED ∪ BOOT)
$used_or_boot = $used_set + $boot_set;
$unused_list  = array();

foreach ($all_disks_set as $disk_name => $_v)
{
    if (!array_key_exists($disk_name, $used_or_boot))
    {
        $unused_list[] = $disk_name;
    }
}

// 6) Sortare si scriere
sort($unused_list, SORT_STRING);

$f = fopen($target_file, "w");
if ($f === false) throw new RuntimeException("Could not open file for writing: " . $target_file);

foreach ($unused_list as $name)
{
    fwrite($f, $name . "\n");
}
fclose($f);

echo "Generat: " . $target_file . "\n";
