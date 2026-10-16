<?php
/**
 * 1.1.0 – ustawienia modułu tylko globalne + wybór sklepów do aktualizacji kursów.
 *
 * Wersja 1.0.0 zapisywała konfigurację w kontekście sklepu, z którego domeny
 * wywołano cron / otwarto panel, więc w multistore mogły powstać wpisy per sklep.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    $db    = Db::getInstance();
    $table = '`' . _DB_PREFIX_ . 'configuration`';

    $global = [];
    foreach ($db->executeS(
        'SELECT `name`, `date_upd` FROM ' . $table . '
         WHERE `name` LIKE \'NBPCURRENCY\\_%\' AND `id_shop` IS NULL AND `id_shop_group` IS NULL'
    ) ?: [] as $row) {
        $global[$row['name']] = $row['date_upd'];
    }

    // Wartości per sklep nowsze niż globalne przenosimy do globalnych
    $scoped = $db->executeS(
        'SELECT `name`, `value`, `date_upd` FROM ' . $table . '
         WHERE `name` LIKE \'NBPCURRENCY\\_%\' AND (`id_shop` IS NOT NULL OR `id_shop_group` IS NOT NULL)
         ORDER BY `date_upd` ASC'
    ) ?: [];
    foreach ($scoped as $row) {
        if (!isset($global[$row['name']]) || $row['date_upd'] > $global[$row['name']]) {
            Configuration::updateGlobalValue($row['name'], $row['value']);
            $global[$row['name']] = $row['date_upd'];
        }
    }

    $db->execute(
        'DELETE FROM ' . $table . '
         WHERE `name` LIKE \'NBPCURRENCY\\_%\' AND (`id_shop` IS NOT NULL OR `id_shop_group` IS NOT NULL)'
    );
    Configuration::loadConfiguration();

    if (!Configuration::getGlobalValue(NbpCurrency::CONFIG_KEY_SHOPS)) {
        Configuration::updateGlobalValue(
            NbpCurrency::CONFIG_KEY_SHOPS,
            implode(',', Shop::getShops(false, null, true))
        );
    }

    return true;
}
