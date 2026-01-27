<?php

// Settings for production environment (Docker/Coolify)

if (getenv('DB_NAME')) {
  $databases['default']['default'] = [
    'database' => getenv('DB_NAME'),
    'username' => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'host' => getenv('DB_HOST'),
    'port' => getenv('DB_PORT'),
    'driver' => getenv('DB_DRIVER'),
    'prefix' => '',
  ];
}

if (getenv('HASH_SALT')) {
  $settings['hash_salt'] = getenv('HASH_SALT');
}

if (getenv('DRUPAL_TRUSTED_HOST_PATTERNS')) {
  $settings['trusted_host_patterns'] = array_map('trim', explode(',', getenv('DRUPAL_TRUSTED_HOST_PATTERNS')));
}

// Config directories if needed, though often set in settings.php via 'config_sync_directory'
// $settings['config_sync_directory'] = ...
