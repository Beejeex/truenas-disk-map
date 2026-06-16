<?php
require_once __DIR__ . "/hardware_helpers.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['serial'], $_POST['actiune']))
{
    $serial = trim($_POST['serial']);
    $actiune = trim($_POST['actiune']); // 'on' or 'off'

    if ($actiune !== 'on' && $actiune !== 'off')
    {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "mesaj" => "Invalid action. Expected 'on' or 'off'."
        ]);
        exit;
    }

    $files = glob("hdd_controlere/*_ses");

    if (empty($files))
    {
        echo json_encode([
            "status" => "error",
            "mesaj" => "No SES files exist. Run the generation steps: detect controllers, generate HDD files, associate devices, and generate SES files."
        ]);
        exit;
    }

    foreach ($files as $file)
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line)
        {
            list($s, $dev, $loc, $slot, $smart, $cmd_on, $cmd_off) = explode("|", $line);
            if ($s === $serial)
            {
                $cmd = $actiune === 'on' ? $cmd_on : $cmd_off;
                $parsed = tdm_parse_sg_ses_command($cmd);
                if ($parsed === null)
                {
                    http_response_code(400);
                    echo json_encode([
                        "status" => "error",
                        "mesaj" => "No valid LED command exists for this slot.",
                        "locatie" => $loc,
                        "slot" => $slot,
                        "device" => $dev
                    ]);
                    exit;
                }

                $code = 0;
                $output = tdm_exec_sg_ses($parsed['ses_device'], $parsed['slot'], $parsed['action'], $code);
                echo json_encode([
                    "status" => $code === 0 ? "ok" : "error",
                    "executat" => $cmd,
                    "output" => $output,
                    "exit_code" => $code,
                    "locatie" => $loc,
                    "slot" => $slot,
                    "device" => $dev
                ]);
                exit;
            }
        }
    }

    echo json_encode([
        "status" => "error",
        "mesaj" => "Serial '$serial' was not found in the SES files."
    ]);
}
else
{
    echo json_encode([
        "status" => "error",
        "mesaj" => "Invalid request. Fields 'serial' and 'actiune' are required."
    ]);
}
