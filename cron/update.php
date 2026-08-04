<?php
/**
 * Cron endpoint – aktualizuje kursy śledzonych walut z NBP.
 *
 * Wywołanie:
 *   https://twojsklep.pl/modules/nbpcurrency/cron/update.php?token=TWOJ_TOKEN
 *
 * Zalecane ustawienie crona (raz dziennie o 12:05, po publikacji tabel NBP):
 *   5 12 * * 1-5 curl -s "https://twojsklep.pl/modules/nbpcurrency/cron/update.php?token=TWOJ_TOKEN" > /dev/null
 */

// Bezpośrednie wywołanie poza PrestaShop – bootstrapujemy CMS ręcznie
// Struktura: modules/nbpcurrency/cron/update.php  → 3 poziomy w górę = root PS
$rootDir = realpath(__DIR__ . '/../../../');

if (!$rootDir || !file_exists($rootDir . '/config/config.inc.php')) {
    // Awaryjne szukanie po całym drzewie katalogów (np. symlinki)
    $dir = __DIR__;
    $rootDir = false;
    for ($i = 0; $i < 6; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/config/config.inc.php')) {
            $rootDir = $dir;
            break;
        }
    }
}

if (!$rootDir) {
    http_response_code(500);
    die('Cannot locate PrestaShop root.');
}

require_once $rootDir . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'nbpcurrency/nbpcurrency.php';

// Weryfikacja tokena (porównanie odporne na timing attack)
$token = Tools::getValue('token', '');
$expected = Configuration::get(NbpCurrency::CONFIG_KEY_CRON_TOKEN);

if (!$token || !$expected || !hash_equals((string) $expected, (string) $token)) {
    http_response_code(403);
    die('Forbidden – invalid token.');
}

/** @var NbpCurrency $module */
$module = Module::getInstanceByName('nbpcurrency');

if (!$module || !$module->active) {
    http_response_code(503);
    die('Module not active.');
}

$result = $module->fetchAll();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
