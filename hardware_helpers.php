<?php

function tdm_run_command(array $argv, &$exit_code = null)
{
    $parts = array();
    foreach ($argv as $arg)
    {
        $parts[] = escapeshellarg((string)$arg);
    }

    $cmd = implode(' ', $parts) . ' 2>&1';
    $output = array();
    $code = 0;

    exec($cmd, $output, $code);
    $exit_code = $code;

    return implode("\n", $output);
}

/**
 * Parse sg_ses --join output to extract slot element data.
 * Returns array of element_index => [device_slot_number, sas_address, installed]
 */
function tdm_parse_ses_join($sg_device)
{
    if (!tdm_is_safe_sg_device($sg_device)) return [];

    $code = 0;
    $output = tdm_run_command(['sg_ses', '--join', $sg_device], $code);
    if ($code !== 0) return [];

    $elements = [];
    $current_element = null;

    foreach (explode("\n", $output) as $line) {
        // Match element header: "Slot00 [0,0]" or "Element 0 [0,0]"
        if (preg_match('/^(?:Slot|Element\s*)?(\d+)\s*\[(\d+),(\d+)\]/i', $line, $m)) {
            if ($current_element !== null) {
                $elements[$current_element['index']] = $current_element;
            }
            $current_element = [
                'index' => (int)$m[1],
                'device_slot_number' => (int)$m[1],
                'sas_address' => '',
                'installed' => false,
            ];
        }
        // device slot number
        elseif ($current_element && preg_match('/device slot number:\s*(\d+)/i', $line, $m)) {
            $current_element['device_slot_number'] = (int)$m[1];
        }
        // SAS address
        elseif ($current_element && preg_match('/SAS address:\s*(0x[0-9a-f]+)/i', $line, $m)) {
            $current_element['sas_address'] = $m[1];
        }
        // installed check
        elseif ($current_element && preg_match('/status:\s*(OK|Installed)/i', $line, $m)) {
            $current_element['installed'] = true;
        }
    }
    if ($current_element !== null) {
        $elements[$current_element['index']] = $current_element;
    }

    return $elements;
}

function tdm_parse_lsscsi_enclosures($output)
{
    $devices = array();

    foreach (explode("\n", (string)$output) as $line)
    {
        $line = trim($line);
        if ($line === '' || stripos($line, 'enclosu') === false)
        {
            continue;
        }

        if (!preg_match('/^\[([^\]]+)\]\s+enclosu\s+(.+?)\s+(\/dev\/sg\d+)\s*$/i', $line, $m))
        {
            continue;
        }

        $hctl = trim($m[1]);
        $name = trim($m[2]);
        $name = preg_replace('/\s+-\s*$/', '', $name);
        $parts = explode(':', $hctl);

        $devices[] = array(
            'index' => count($devices),
            'hctl' => $hctl,
            'host' => isset($parts[0]) ? (int)$parts[0] : null,
            'channel' => isset($parts[1]) ? (int)$parts[1] : null,
            'target' => isset($parts[2]) ? (int)$parts[2] : null,
            'lun' => isset($parts[3]) ? (int)$parts[3] : null,
            'name' => $name,
            'sg' => trim($m[3]),
            'raw' => $line,
        );
    }

    usort($devices, function ($a, $b) {
        $ah = $a['host'] ?? 0;
        $bh = $b['host'] ?? 0;
        if ($ah !== $bh) return $ah <=> $bh;

        $at = $a['target'] ?? 0;
        $bt = $b['target'] ?? 0;
        if ($at !== $bt) return $at <=> $bt;

        return strcmp($a['sg'], $b['sg']);
    });

    foreach ($devices as $i => &$device)
    {
        $device['index'] = $i;
    }
    unset($device);

    return $devices;
}

function tdm_parse_lsscsi_disks($output)
{
    $devices = array();

    foreach (explode("\n", (string)$output) as $line)
    {
        $line = trim($line);
        if ($line === '' || !preg_match('/^\[([^\]]+)\]\s+disk\s+(.+?)\s+(\/dev\/[A-Za-z0-9._-]+)\s+(\/dev\/sg\d+)\s*$/i', $line, $m))
        {
            continue;
        }

        $hctl = trim($m[1]);
        $parts = explode(':', $hctl);

        $devices[] = array(
            'index' => count($devices),
            'hctl' => $hctl,
            'host' => isset($parts[0]) ? (int)$parts[0] : null,
            'channel' => isset($parts[1]) ? (int)$parts[1] : null,
            'target' => isset($parts[2]) ? (int)$parts[2] : null,
            'lun' => isset($parts[3]) ? (int)$parts[3] : null,
            'name' => preg_replace('/\s+/', ' ', trim($m[2])),
            'dev' => trim($m[3]),
            'sg' => trim($m[4]),
            'raw' => $line,
        );
    }

    usort($devices, function ($a, $b) {
        $ah = $a['host'] ?? 0;
        $bh = $b['host'] ?? 0;
        if ($ah !== $bh) return $ah <=> $bh;

        $ac = $a['channel'] ?? 0;
        $bc = $b['channel'] ?? 0;
        if ($ac !== $bc) return $ac <=> $bc;

        $at = $a['target'] ?? 0;
        $bt = $b['target'] ?? 0;
        if ($at !== $bt) return $at <=> $bt;

        return strcmp($a['dev'], $b['dev']);
    });

    foreach ($devices as $i => &$device)
    {
        $device['index'] = $i;
    }
    unset($device);

    return $devices;
}

function tdm_detect_lsscsi(&$raw_output = '', &$exit_code = null)
{
    $raw_output = tdm_run_command(array('sudo', '/usr/local/sbin/tdm-lsscsi-read'), $exit_code);

    return array(
        'disks' => tdm_parse_lsscsi_disks($raw_output),
        'enclosures' => tdm_parse_lsscsi_enclosures($raw_output),
    );
}

function tdm_detect_ses_devices(&$raw_output = '', &$exit_code = null)
{
    $detected = tdm_detect_lsscsi($raw_output, $exit_code);
    return $detected['enclosures'];
}

function tdm_is_safe_sg_device($device)
{
    return is_string($device) && preg_match('/^\/dev\/sg\d+$/', $device);
}

function tdm_is_safe_dev_path($device)
{
    return is_string($device) && preg_match('/^\/dev\/[A-Za-z0-9._-]+$/', $device);
}

function tdm_get_smart_serial($dev, $device_type = '')
{
    if (!tdm_is_safe_dev_path($dev))
    {
        return '';
    }

    $device_type = trim((string)$device_type);
    $code = 0;
    if ($device_type !== '' && preg_match('/^[A-Za-z0-9,+_-]+$/', $device_type))
    {
        $info = tdm_run_command(array('sudo', '/usr/local/sbin/tdm-smartctl-read', '-i', '-d', $device_type, $dev), $code);
    }
    else
    {
        $info = tdm_run_command(array('sudo', '/usr/local/sbin/tdm-smartctl-read', '-i', $dev), $code);
    }

    if (preg_match('/Serial Number:\s*(.+)/i', $info, $m))
    {
        return trim($m[1]);
    }

    if (preg_match('/Serial number:\s*(.+)/i', $info, $m))
    {
        return trim($m[1]);
    }

    return '';
}

function tdm_build_sg_ses_command($ses_device, $slot, $action)
{
    if (!tdm_is_safe_sg_device($ses_device))
    {
        return '';
    }

    if (!is_numeric($slot) || (int)$slot < 0)
    {
        return '';
    }

    if ($action !== 'set' && $action !== 'clear')
    {
        return '';
    }

    return 'sudo /usr/local/sbin/tdm-sg-ses-ident ' . $action . ' ' . (int)$slot . ' ' . $ses_device;
}

function tdm_parse_sg_ses_command($cmd)
{
    $cmd = trim((string)$cmd);
    if ($cmd === '')
    {
        return null;
    }

    $wrapper_pattern = '/^(?:sudo\s+)?\/usr\/local\/sbin\/tdm-sg-ses-ident\s+(set|clear)\s+(\d+)\s+(\/dev\/sg\d+)$/';
    if (preg_match($wrapper_pattern, $cmd, $m))
    {
        return array(
            'slot' => (int)$m[2],
            'action' => $m[1],
            'ses_device' => $m[3],
        );
    }

    $legacy_pattern = '/^(?:sudo\s+)?(?:\/usr\/(?:local\/)?bin\/)?sg_ses\s+--(?:dev-slot-num|index)=(\d+)\s+--(set|clear)=ident\s+(\/dev\/sg\d+)$/';
    if (!preg_match($legacy_pattern, $cmd, $m))
    {
        return null;
    }

    return array(
        'slot' => (int)$m[1],
        'action' => $m[2],
        'ses_device' => $m[3],
    );
}

function tdm_exec_sg_ses($ses_device, $slot, $action, &$exit_code = null)
{
    if (!tdm_is_safe_sg_device($ses_device))
    {
        $exit_code = 64;
        return 'Invalid SES device.';
    }

    if (!is_numeric($slot) || (int)$slot < 0)
    {
        $exit_code = 64;
        return 'Invalid slot index.';
    }

    if ($action !== 'set' && $action !== 'clear')
    {
        $exit_code = 64;
        return 'Invalid SES action.';
    }

    return tdm_run_command(array(
        'sudo',
        '/usr/local/sbin/tdm-sg-ses-ident',
        $action,
        (int)$slot,
        $ses_device
    ), $exit_code);
}

function tdm_enclosure_label(array $ses_device = null, $fallback)
{
    if ($ses_device === null)
    {
        return $fallback;
    }

    $name = preg_replace('/\s+/', ' ', trim($ses_device['name']));
    if ($name === '')
    {
        return $fallback . ' (' . $ses_device['sg'] . ')';
    }

    return $fallback . ' - ' . $name . ' (' . $ses_device['sg'] . ')';
}

function tdm_write_discovery_report($target_dir, array $data)
{
    if (!is_dir($target_dir))
    {
        @mkdir($target_dir, 0775, true);
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json !== false)
    {
        file_put_contents($target_dir . '/discovery.json', $json . "\n");
    }

    $lines = array();
    $lines[] = 'TrueNAS Disk Map discovery report';
    $lines[] = 'Generated: ' . date('Y-m-d H:i:s');
    $lines[] = '';

    if (isset($data['ses_devices']))
    {
        $lines[] = 'SES enclosures: ' . count($data['ses_devices']);
        foreach ($data['ses_devices'] as $dev)
        {
            $lines[] = '- ' . $dev['sg'] . ' [' . $dev['hctl'] . '] ' . $dev['name'];
        }
        $lines[] = '';
    }

    if (isset($data['controllers']))
    {
        $lines[] = 'Controllers: ' . implode(', ', $data['controllers']);
        $lines[] = '';
    }

    if (!empty($data['warnings']))
    {
        $lines[] = 'Warnings:';
        foreach ($data['warnings'] as $warning)
        {
            $lines[] = '- ' . $warning;
        }
    }

    file_put_contents($target_dir . '/discovery.txt', implode("\n", $lines) . "\n");
}

?>
