<?php

$dir = "disk_data";
$deleted = 0;
$deleted_extra = 0;
$errors = array();

if (is_dir($dir))
{
    $files = glob($dir . "/*");

    if (is_array($files))
    {
        foreach ($files as $file)
        {
            if (is_file($file))
            {
                if (unlink($file))
                {
                    $deleted++;
                }
                else
                {
                    $errors[] = "Could not delete: " . $file;
                }
            }
        }
    }

    echo "[OK] Deleted " . $deleted . " files from " . $dir . ".\n";
}
else
{
    echo "[WARN] Directory does not exist: " . $dir . "\n";
}

if (file_exists("serial_cache.txt"))
{
    if (unlink("serial_cache.txt"))
    {
        $deleted_extra++;
        echo "[OK] Deleted serial_cache.txt\n";
    }
    else
    {
        $errors[] = "Could not delete serial_cache.txt";
    }
}
else
{
    echo "[INFO] serial_cache.txt does not exist\n";
}

if (file_exists("controllers.txt"))
{
    if (unlink("controllers.txt"))
    {
        $deleted_extra++;
        echo "[OK] Deleted controllers.txt\n";
    }
    else
    {
        $errors[] = "Could not delete controllers.txt";
    }
}
else
{
    echo "[INFO] controllers.txt does not exist\n";
}

echo "[OK] Extra files deleted: " . $deleted_extra . "\n";

if (!empty($errors))
{
    echo "\n[WARN] Delete problems:\n";

    foreach ($errors as $err)
    {
        echo "- " . $err . "\n";
    }
}
?>
