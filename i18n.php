<?php

$tdm_lang = getenv('TDM_LANG');
if ($tdm_lang === false || $tdm_lang === '')
{
    $tdm_lang = 'en';
}

$tdm_lang = preg_replace('/[^A-Za-z0-9_-]/', '', $tdm_lang);
$tdm_translation_file = __DIR__ . '/translations/' . $tdm_lang . '.php';
if (!is_file($tdm_translation_file))
{
    $tdm_translation_file = __DIR__ . '/translations/en.php';
}

$TDM_TRANSLATIONS = require $tdm_translation_file;

function tdm_t($key, array $vars = array())
{
    global $TDM_TRANSLATIONS;

    $text = isset($TDM_TRANSLATIONS[$key]) ? $TDM_TRANSLATIONS[$key] : $key;
    foreach ($vars as $name => $value)
    {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }

    return $text;
}

function tdm_h($key, array $vars = array())
{
    return htmlspecialchars(tdm_t($key, $vars), ENT_QUOTES, 'UTF-8');
}

?>
