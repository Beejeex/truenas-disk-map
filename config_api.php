<?php
require_once __DIR__ . "/api_config_store.php";

$tdm_api_config = tdm_load_api_config();

$API_URL = $tdm_api_config['api_url'];
$API_KEY = $tdm_api_config['api_key'];
$VERIFY_TLS = $tdm_api_config['verify_tls'];

?>
