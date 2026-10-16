<?php
/**
 * Admin controller for NBP Currency module
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminNbpCurrencyController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->meta_title = $this->l('Kursy walut NBP');

        // Jawnie rejestrujemy katalog szablonów modułu w Smarty.
        // Rozwiązuje błąd "Unable to load template module:..." na niektórych
        // instalacjach PS8 z niestandardowymi motywami.
        $this->context->smarty->addTemplateDir(
            _PS_MODULE_DIR_ . 'nbpcurrency/views/templates/admin/'
        );
    }

    public function initContent(): void
    {
        parent::initContent();

        if (Tools::isSubmit('submitNbpConfig')) {
            $this->processGlobalConfig();
        }
        if (Tools::isSubmit('addTrackedCurrency')) {
            $this->processAddTrackedCurrency();
        }
        if (Tools::isSubmit('updateTrackedCurrency')) {
            $this->processUpdateTrackedCurrency();
        }
        if (Tools::isSubmit('deleteTrackedCurrency')) {
            $this->processDeleteTrackedCurrency();
        }
        if (Tools::isSubmit('refreshRate')) {
            $this->processRefreshRate();
        }

        $this->context->smarty->assign($this->getTemplateVars());

        // Używamy samej nazwy pliku – Smarty szuka go w katalogu
        // zarejestrowanym przez addTemplateDir() w konstruktorze.
        $this->setTemplate('configuration.tpl');
    }

    private function processGlobalConfig(): void
    {
        $autoUpdate  = (int) Tools::getValue('auto_update', 0);
        $updatePsCur = (int) Tools::getValue('update_ps_currency', 0);
        $shopIds     = Tools::getValue('shops', []);

        $shopIds = array_values(array_intersect(
            array_map('intval', is_array($shopIds) ? $shopIds : []),
            array_map('intval', Shop::getShops(false, null, true))
        ));

        if ($updatePsCur && !$shopIds) {
            $this->errors[] = $this->l('Zaznacz co najmniej jeden sklep, w którym mają być aktualizowane kursy.');
            return;
        }

        NbpCurrency::setConfig(NbpCurrency::CONFIG_KEY_AUTO_UPDATE, $autoUpdate);
        NbpCurrency::setConfig(NbpCurrency::CONFIG_KEY_UPDATE_PS_CUR, $updatePsCur);
        NbpCurrency::setConfig(NbpCurrency::CONFIG_KEY_SHOPS, implode(',', $shopIds));

        $this->confirmations[] = $this->l('Ustawienia zostały zapisane.');
    }

    private function processAddTrackedCurrency(): void
    {
        $idCurrency = (int) Tools::getValue('id_currency', 0);
        $nbpTable   = Tools::getValue('nbp_table', 'A');
        $rateType   = Tools::getValue('rate_type', 'mid');
        $markup     = (float) str_replace(',', '.', Tools::getValue('markup', '0'));
        $active     = (int) Tools::getValue('active', 1);

        if (!$idCurrency) {
            $this->errors[] = $this->l('Wybierz walutę.');
            return;
        }
        if ($markup < 0) {
            $this->errors[] = $this->l('Marża nie może być ujemna.');
            return;
        }

        $result = $this->module->addTrackedCurrency($idCurrency, $nbpTable, $rateType, $markup, (bool) $active);
        if ($result['success']) {
            $this->confirmations[] = $result['message'];
        } else {
            $this->errors[] = $result['message'];
        }
    }

    private function processUpdateTrackedCurrency(): void
    {
        $idTracked = (int) Tools::getValue('id_tracked', 0);
        $nbpTable  = Tools::getValue('nbp_table', 'A');
        $rateType  = Tools::getValue('rate_type', 'mid');
        $markup    = (float) str_replace(',', '.', Tools::getValue('markup', '0'));
        $active    = (int) Tools::getValue('active', 1);

        if ($markup < 0) {
            $this->errors[] = $this->l('Marża nie może być ujemna.');
            return;
        }

        $result = $this->module->updateTrackedCurrency($idTracked, $nbpTable, $rateType, $markup, (bool) $active);
        if ($result['success']) {
            $this->confirmations[] = $result['message'];
        } else {
            $this->errors[] = $result['message'];
        }
    }

    private function processDeleteTrackedCurrency(): void
    {
        $idTracked = (int) Tools::getValue('id_tracked', 0);
        if ($idTracked && $this->module->deleteTrackedCurrency($idTracked)) {
            $this->confirmations[] = $this->l('Waluta usunięta z listy śledzonych.');
        } else {
            $this->errors[] = $this->l('Błąd usuwania wpisu.');
        }
    }

    private function processRefreshRate(): void
    {
        // Ręczne pobranie zawsze zapisuje wynik do logu
        $result = $this->module->fetchAll(true);

        if (!$result['success'] && empty($result['currencies'])) {
            $this->errors[] = $result['message'] ?? $this->l('Błąd pobierania kursów.');
            return;
        }

        $ok = 0;
        $fail = 0;
        foreach ($result['currencies'] as $iso => $currencyResult) {
            if ($currencyResult['success']) {
                $ok++;
            } else {
                $fail++;
                $this->errors[] = $iso . ': ' . $currencyResult['message'];
            }
        }

        if ($ok) {
            $this->confirmations[] = sprintf(
                $this->l('Kursy zaktualizowane: %1$d OK, %2$d błędów.'),
                $ok,
                $fail
            );
        }
    }

    private function getTemplateVars(): array
    {
        $cronToken = NbpCurrency::getConfig(NbpCurrency::CONFIG_KEY_CRON_TOKEN);
        $baseUrl   = Tools::getShopDomainSsl(true) . __PS_BASE_URI__;
        $cronUrl   = $baseUrl . 'modules/nbpcurrency/cron/update.php?token=' . $cronToken;

        $trackedRaw = $this->module->getTrackedCurrencies(false);

        $targetShopIds = NbpCurrency::getTargetShopIds();
        $shops = [];
        foreach (Shop::getShops(false, null, true) as $idShop) {
            $shop    = Shop::getShop((int) $idShop);
            $shops[] = [
                'id_shop'     => (int) $idShop,
                'name'        => $shop ? $shop['name'] : '#' . $idShop,
                'default_iso' => NbpCurrency::getShopDefaultIso((int) $idShop),
                'selected'    => in_array((int) $idShop, $targetShopIds, true),
            ];
        }

        $tracked = [];
        foreach ($trackedRaw as $row) {
            $log = $this->module->getLatestLog((int) $row['id_currency']);

            // conversion_rate w każdym sklepie (ps_currency_shop)
            $shopRates = [];
            foreach ($shops as $shop) {
                $rate = NbpCurrency::getShopCurrencyRate($row['iso_code'], $shop['id_shop']);
                if ($rate) {
                    $shopRates[] = ['name' => $shop['name'], 'rate' => $rate['conversion_rate']];
                }
            }

            $tracked[] = [
                'id_tracked'         => (int) $row['id_tracked'],
                'iso_code'           => $row['iso_code'],
                'name'               => $row['name'],
                'nbp_table'          => $row['nbp_table'],
                'rate_type'          => $row['rate_type'],
                'markup'             => $row['markup'],
                'active'             => (bool) $row['active'],
                'rate_value'         => $log['rate_value'] ?? null,
                'shop_rates'         => $shopRates,
                'last_update'        => $log['fetched_at'] ?? null,
                'last_success'       => $log ? (bool) $log['success'] : null,
                'last_message'       => $log['message'] ?? null,
            ];
        }

        $trackedCurrencyIds = array_map('intval', array_column($trackedRaw, 'id_currency'));
        $available = [];
        foreach (Currency::getCurrencies(false) as $currency) {
            if ($currency['iso_code'] === 'PLN' || in_array((int) $currency['id_currency'], $trackedCurrencyIds, true)) {
                continue;
            }
            $available[] = $currency;
        }

        // Sklepy z walutą domyślną inną niż PLN, której nie śledzimy – nie da się w nich przeliczyć kursów
        $trackedIsos      = array_column($trackedRaw, 'iso_code');
        $untrackedDefault = [];
        foreach ($shops as $shop) {
            if ($shop['selected'] && $shop['default_iso'] !== 'PLN' && !in_array($shop['default_iso'], $trackedIsos, true)) {
                $untrackedDefault[] = $shop;
            }
        }

        return [
            'tracked_currencies'   => $tracked,
            'available_currencies' => $available,
            'auto_update'          => (bool) NbpCurrency::getConfig(NbpCurrency::CONFIG_KEY_AUTO_UPDATE),
            'update_ps_currency'   => (bool) NbpCurrency::getConfig(NbpCurrency::CONFIG_KEY_UPDATE_PS_CUR),
            'shops'                => $shops,
            'untracked_default'    => $untrackedDefault,
            'cron_url'             => $cronUrl,
            'rate_log'             => $this->module->getRecentLog(20),
            'module_dir'           => $this->module->getPathUri(),
            'form_action'          => $this->context->link->getAdminLink('AdminNbpCurrency'),
        ];
    }
}
