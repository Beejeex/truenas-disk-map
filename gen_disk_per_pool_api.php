<?php
// Generate disk_data/disk_per_pool.txt with pool, data disk, and spare disk labels.
// Also generates disk_data/disk_vdev.json with per-disk VDEV type and index.

require_once __DIR__ . "/config_api.php";

$target_dir = __DIR__ . "/disk_data";
$target_file = $target_dir . "/disk_per_pool.txt";
$vdev_file    = $target_dir . "/disk_vdev.json";

if (!is_dir($target_dir))
{
    if (!mkdir($target_dir, 0775, true))
    {
        throw new RuntimeException("Could not create directory: " . $target_dir);
    }
}

if (!tdm_api_configured())
{
    file_put_contents($target_file, "[]\n");
    file_put_contents($vdev_file, json_encode((object)[]));
    echo "[INFO] TrueNAS API is not configured; pool labels were skipped.\n";
    return;
}

echo "[DEBUG] API configured, fetching /pool...\n";

function truenas_api_get($url, $api_key, $verify_tls)
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
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0)
    {
        throw new RuntimeException("cURL error ($errno): $err");
    }

    if ($code < 200 || $code >= 300)
    {
        throw new RuntimeException("HTTP $code for $url. Response: " . $resp);
    }

    return $resp;
}

// Load pools. TrueNAS normally includes topology in the /pool response.
$pools_json = truenas_api_get($API_URL . "/pool", $API_KEY, $VERIFY_TLS);
$pools = json_decode($pools_json, true);

if (!is_array($pools))
{
    throw new RuntimeException("Invalid or non-JSON /pool response.");
}

$result = array();
$vdev_map = array();  // disk_device => {pool, vdev_type, vdev_index}

// Parcurgem fiecare pool si extragem data_disks si spare_disks
foreach ($pools as $pool)
{
    $pool_name = "";
    $data_disks = array();
    $spare_disks = array();

    if (isset($pool["name"]))
    {
        $pool_name = $pool["name"];
    }
    else
    {
        $pool_name = "(unnamed)";
    }

    // In many TrueNAS versions, topology is in $pool["topology"].
    if (isset($pool["topology"]) && is_array($pool["topology"]))
    {
        // Data vdevs with children->disk entries.
        $vdev_index = 0;
        if (isset($pool["topology"]["data"]) && is_array($pool["topology"]["data"]))
        {
            foreach ($pool["topology"]["data"] as $vdev)
            {
                $vdev_type = isset($vdev["type"]) ? strtoupper(trim($vdev["type"])) : 'DATA';
                if (isset($vdev["children"]) && is_array($vdev["children"]))
                {
                    foreach ($vdev["children"] as $child)
                    {
                        if (isset($child["disk"]) && $child["disk"] !== null)
                        {
                            $dname = trim($child["disk"]);
                            if ($dname !== "")
                            {
                                $data_disks[] = $dname;
                                // Track VDEV info per disk
                                $vdev_map[$dname] = array(
                                    'pool'       => $pool_name,
                                    'vdev_type'  => $vdev_type,
                                    'vdev_index' => $vdev_index,
                                );
                            }
                        }
                    }
                }
                $vdev_index++;
            }
        }

        // Some TrueNAS versions use "spare"; others use "spares".
        $spare_list = null;
        if (isset($pool["topology"]["spare"]) && is_array($pool["topology"]["spare"]))
            $spare_list = $pool["topology"]["spare"];
        elseif (isset($pool["topology"]["spares"]) && is_array($pool["topology"]["spares"]))
            $spare_list = $pool["topology"]["spares"];

        if ($spare_list !== null)
        {
            foreach ($spare_list as $sp)
            {
                if (isset($sp["disk"]) && $sp["disk"] !== null)
                {
                    $sname = trim($sp["disk"]);
                    if ($sname !== "")
                    {
                        $spare_disks[] = $sname;
                        $vdev_map[$sname] = array(
                            'pool'       => $pool_name,
                            'vdev_type'  => 'SPARE',
                            'vdev_index' => null,
                        );
                    }
                }
            }
        }
    }

    $data_disks = array_values(array_unique($data_disks));
    sort($data_disks, SORT_STRING);

    $spare_disks = array_values(array_unique($spare_disks));
    sort($spare_disks, SORT_STRING);

    $result[] = array(
        "name" => $pool_name,
        "data_disks" => $data_disks,
        "spare_disks" => $spare_disks
    );
}

$pretty = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($pretty === false)
{
    throw new RuntimeException("json_encode failed: " . json_last_error_msg());
}

$f = fopen($target_file, "w");
if ($f === false)
{
    throw new RuntimeException("Could not open file for writing: " . $target_file);
}
fwrite($f, $pretty . "\n");
fclose($f);

echo "[OK] Generated: " . $target_file . "\n";

// ── Write VDEV map ──────────────────────────────────────────────────
$vdev_pretty = json_encode($vdev_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($vdev_pretty === false)
{
    throw new RuntimeException("json_encode vdev_map failed: " . json_last_error_msg());
}
$fv = fopen($vdev_file, "w");
if ($fv === false)
{
    throw new RuntimeException("Could not open file for writing: " . $vdev_file);
}
fwrite($fv, $vdev_pretty . "\n");
fclose($fv);

echo "[OK] Generated: " . $vdev_file . "\n";
