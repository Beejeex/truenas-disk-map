<?php

function tdm_data_dir()
{
    $env = getenv('TDM_DATA_DIR');
    if ($env !== false && trim($env) !== '')
    {
        return rtrim(trim($env), "/\\");
    }

    return __DIR__ . "/data";
}

function tdm_api_config_path()
{
    $env = getenv('TDM_API_CONFIG_FILE');
    if ($env !== false && trim($env) !== '')
    {
        return trim($env);
    }

    return tdm_data_dir() . "/config_api.local.php";
}

function tdm_normalize_api_url($url)
{
    $url = trim((string)$url);
    if ($url === '')
    {
        return '';
    }

    $url = rtrim($url, '/');
    if (!preg_match('~/api/v2\.0$~', $url))
    {
        $url .= '/api/v2.0';
    }

    return $url;
}

function tdm_load_api_config()
{
    $config = array(
        'api_url' => '',
        'api_key' => '',
        'verify_tls' => false,
    );

    $path = tdm_api_config_path();
    if (is_file($path))
    {
        $loaded = require $path;
        if (is_array($loaded))
        {
            $config = array_merge($config, $loaded);
        }
    }

    $config['api_url'] = tdm_normalize_api_url($config['api_url']);
    $config['api_key'] = trim((string)$config['api_key']);
    $config['verify_tls'] = (bool)$config['verify_tls'];

    return $config;
}

function tdm_api_configured(array $config = null)
{
    if ($config === null)
    {
        $config = tdm_load_api_config();
    }

    return $config['api_url'] !== '' && $config['api_key'] !== '';
}

function tdm_mask_api_key($api_key)
{
    $api_key = trim((string)$api_key);
    if ($api_key === '')
    {
        return '';
    }

    $len = strlen($api_key);
    if ($len <= 8)
    {
        return str_repeat('*', $len);
    }

    return substr($api_key, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($api_key, -4);
}

function tdm_save_api_config($api_url, $api_key, $verify_tls)
{
    $api_url = tdm_normalize_api_url($api_url);
    $api_key = trim((string)$api_key);

    if ($api_url !== '')
    {
        $parts = parse_url($api_url);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (($scheme !== 'http' && $scheme !== 'https') || !filter_var($api_url, FILTER_VALIDATE_URL))
        {
            throw new InvalidArgumentException('Invalid API URL.');
        }
    }

    $dir = dirname(tdm_api_config_path());
    if (!is_dir($dir) && !mkdir($dir, 0775, true))
    {
        throw new RuntimeException('Could not create data directory.');
    }

    $config = array(
        'api_url' => $api_url,
        'api_key' => $api_key,
        'verify_tls' => (bool)$verify_tls,
    );

    $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents(tdm_api_config_path(), $content, LOCK_EX) === false)
    {
        throw new RuntimeException('Could not save API settings.');
    }

    return $config;
}

?>
