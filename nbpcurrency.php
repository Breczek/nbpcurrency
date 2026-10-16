<?php
/**
 * NBP Currency - PrestaShop 8/9 Module
 * Pobiera kursy walut z API NBP (Tabela A i B - kurs średni, Tabela C - kurs kupna/sprzedaży)
 * i aktualizuje conversion_rate wybranych walut w PrestaShop.
 *
 * @author    breczek1
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NbpCurrency extends Module
{
    const CONFIG_KEY_CRON_TOKEN    = 'NBPCURRENCY_CRON_TOKEN';
    const CONFIG_KEY_AUTO_UPDATE   = 'NBPCURRENCY_AUTO_UPDATE';
    const CONFIG_KEY_UPDATE_PS_CUR = 'NBPCURRENCY_UPDATE_PS_CUR';
    const CONFIG_KEY_SHOPS         = 'NBPCURRENCY_SHOPS'; // id sklepów (CSV), w których aktualizujemy kursy

    const NBP_RATE_URL = 'https://api.nbp.pl/api/exchangerates/rates/%s/%s/?format=json';

    // Zestaw walut publikowanych przez NBP w każdej tabeli (stan na 08.2026,
    // zweryfikowany na żywym API). Tabela A i C to kursy dnia (dni robocze),
    // tabela B to kursy walut mniej płynnych, publikowane raz w tygodniu
    // (środy) - stąd rzadsze aktualizacje dla walut śledzonych przez B.
    const TABLE_A_CODES = [
        'THB', 'USD', 'AUD', 'HKD', 'CAD', 'NZD', 'SGD', 'EUR', 'HUF', 'CHF',
        'GBP', 'UAH', 'JPY', 'CZK', 'DKK', 'ISK', 'NOK', 'SEK', 'RON', 'TRY',
        'ILS', 'CLP', 'PHP', 'MXN', 'ZAR', 'BRL', 'MYR', 'IDR', 'INR', 'KRW',
        'CNY', 'XDR',
    ];
    const TABLE_B_CODES = [
        'AFN', 'MGA', 'PAB', 'ETB', 'VES', 'BOB', 'CRC', 'SVC', 'NIO', 'GMD',
        'MKD', 'DZD', 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'RSD', 'TND', 'MAD',
        'AED', 'STN', 'BSD', 'BBD', 'BZD', 'BND', 'FJD', 'GYD', 'JMD', 'LRD',
        'NAD', 'SRD', 'TTD', 'XCD', 'SBD', 'VND', 'AMD', 'CVE', 'AWG', 'BIF',
        'XOF', 'XAF', 'XPF', 'DJF', 'GNF', 'KMF', 'CDF', 'RWF', 'EGP', 'GIP',
        'LBP', 'SSP', 'SDG', 'SYP', 'GHS', 'HTG', 'PYG', 'XCG', 'PGK', 'LAK',
        'MWK', 'ZMW', 'AOA', 'MMK', 'GEL', 'MDL', 'ALL', 'HNL', 'SLE', 'SZL',
        'LSL', 'AZN', 'MZN', 'NGN', 'ERN', 'TWD', 'TMT', 'MRU', 'TOP', 'MOP',
        'ARS', 'DOP', 'COP', 'CUP', 'UYU', 'BWP', 'GTQ', 'IRR', 'YER', 'QAR',
        'OMR', 'SAR', 'KHR', 'BYN', 'RUB', 'LKR', 'MVR', 'MUR', 'NPR', 'PKR',
        'SCR', 'PEN', 'KGS', 'TJS', 'UZS', 'KES', 'SOS', 'TZS', 'UGX', 'BDT',
        'WST', 'KZT', 'MNT', 'VUV', 'BAM', 'ZWG',
    ];
    const TABLE_C_CODES = [
        'USD', 'AUD', 'CAD', 'EUR', 'HUF', 'CHF', 'GBP', 'JPY', 'CZK', 'DKK',
        'NOK', 'SEK', 'XDR',
    ];

    public function __construct()
    {
        $this->name          = 'nbpcurrency';
        $this->tab           = 'pricing_promotion';
        $this->version       = '1.1.0';
        $this->author        = 'breczek1';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Kursy walut NBP');
        $this->description = $this->l('Automatycznie aktualizuje kursy wybranych walut w PrestaShop na podstawie tabel A, B i C Narodowego Banku Polskiego.');
        $this->confirmUninstall = $this->l('Czy na pewno chcesz odinstalować moduł?');
    }

    // -------------------------------------------------------------------------
    // Install / Uninstall
    // -------------------------------------------------------------------------

    public function install(): bool
    {
        // Instalacja zawsze dla wszystkich sklepów – ustawienia modułu są globalne
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install()
            && $this->installTab()
            && $this->installDb()
            && $this->registerHook('actionCronJob')
            && self::setConfig(self::CONFIG_KEY_CRON_TOKEN,    Tools::passwdGen(32))
            && self::setConfig(self::CONFIG_KEY_AUTO_UPDATE,   1)
            && self::setConfig(self::CONFIG_KEY_UPDATE_PS_CUR, 1)
            && self::setConfig(self::CONFIG_KEY_SHOPS,         implode(',', Shop::getShops(false, null, true)));
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallTab()
            && $this->uninstallDb()
            && Configuration::deleteByName(self::CONFIG_KEY_CRON_TOKEN)
            && Configuration::deleteByName(self::CONFIG_KEY_AUTO_UPDATE)
            && Configuration::deleteByName(self::CONFIG_KEY_UPDATE_PS_CUR)
            && Configuration::deleteByName(self::CONFIG_KEY_SHOPS);
    }

    private function installTab(): bool
    {
        $tab             = new Tab();
        $tab->active     = 1;
        $tab->class_name = 'AdminNbpCurrency';
        $tab->module     = $this->name;
        $tab->id_parent  = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->icon       = 'monetization_on';

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = $this->l('Kursy walut NBP');
        }

        return $tab->add();
    }

    private function uninstallTab(): bool
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminNbpCurrency');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

    private function installDb(): bool
    {
        $sqlTracked = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'nbpcurrency_tracked` (
            `id_tracked`  INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_currency` INT(11) UNSIGNED NOT NULL,
            `nbp_code`    VARCHAR(3) NOT NULL,
            `nbp_table`   ENUM(\'A\',\'B\',\'C\') NOT NULL DEFAULT \'A\',
            `rate_type`   ENUM(\'mid\',\'bid\',\'ask\',\'avg\') NOT NULL DEFAULT \'mid\',
            `markup`      DECIMAL(10,4) NOT NULL DEFAULT \'0.0000\',
            `active`      TINYINT(1) UNSIGNED NOT NULL DEFAULT \'1\',
            `date_add`    DATETIME NOT NULL,
            `date_upd`    DATETIME NOT NULL,
            PRIMARY KEY (`id_tracked`),
            UNIQUE KEY `id_currency` (`id_currency`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $sqlLog = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'nbpcurrency_log` (
            `id_log`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_currency`     INT(11) UNSIGNED NOT NULL,
            `nbp_code`        VARCHAR(3) NOT NULL,
            `nbp_table`       VARCHAR(1) NOT NULL,
            `rate_type`       VARCHAR(4) NOT NULL,
            `rate_value`      DECIMAL(10,4) NOT NULL DEFAULT \'0.0000\',
            `conversion_rate` DECIMAL(13,6) NULL DEFAULT NULL,
            `effective_date`  DATE NULL DEFAULT NULL,
            `fetched_at`      DATETIME NOT NULL,
            `success`         TINYINT(1) UNSIGNED NOT NULL DEFAULT \'1\',
            `message`         VARCHAR(255) NULL DEFAULT NULL,
            PRIMARY KEY (`id_log`),
            KEY `id_currency` (`id_currency`, `effective_date`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sqlTracked) && Db::getInstance()->execute($sqlLog);
    }

    private function uninstallDb(): bool
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'nbpcurrency_tracked`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'nbpcurrency_log`');
    }

    // -------------------------------------------------------------------------
    // Konfiguracja – zawsze globalna, niezależnie od kontekstu sklepu
    // (cron wywołany z domeny sklepu X ustawia kontekst na X)
    // -------------------------------------------------------------------------

    public static function getConfig(string $key)
    {
        return Configuration::getGlobalValue($key);
    }

    public static function setConfig(string $key, $value): bool
    {
        return Configuration::updateGlobalValue($key, $value);
    }

    /**
     * @return int[] id sklepów, w których moduł aktualizuje kursy
     */
    public static function getTargetShopIds(): array
    {
        $existing = array_map('intval', Shop::getShops(false, null, true));
        $selected = array_map('intval', array_filter(explode(',', (string) self::getConfig(self::CONFIG_KEY_SHOPS))));

        return array_values(array_intersect($selected, $existing));
    }

    public static function getShopDefaultIso(int $idShop): string
    {
        $idDefault = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, Shop::getGroupFromShop($idShop), $idShop);

        return (string) Db::getInstance()->getValue(
            'SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` = ' . $idDefault
        );
    }

    /**
     * Aktualny conversion_rate waluty w danym sklepie (null = waluta nieprzypisana do sklepu).
     *
     * @return array{id_currency: int, conversion_rate: float}|null
     */
    public static function getShopCurrencyRate(string $isoCode, int $idShop): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT c.`id_currency`, cs.`conversion_rate`
             FROM `' . _DB_PREFIX_ . 'currency` c
             INNER JOIN `' . _DB_PREFIX_ . 'currency_shop` cs
                ON cs.`id_currency` = c.`id_currency` AND cs.`id_shop` = ' . (int) $idShop . '
             WHERE c.`iso_code` = \'' . pSQL($isoCode) . '\' AND c.`deleted` = 0'
        );

        return $row ? ['id_currency' => (int) $row['id_currency'], 'conversion_rate' => (float) $row['conversion_rate']] : null;
    }

    // -------------------------------------------------------------------------
    // Configuration page (displayed from AdminNbpCurrency controller)
    // -------------------------------------------------------------------------

    public function getContent(): string
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminNbpCurrency')
        );
        return '';
    }

    // -------------------------------------------------------------------------
    // Tracked currencies - CRUD
    // -------------------------------------------------------------------------

    public function getTrackedCurrencies(bool $onlyActive = false): array
    {
        $sql = 'SELECT t.*, c.iso_code, c.name
                FROM `' . _DB_PREFIX_ . 'nbpcurrency_tracked` t
                LEFT JOIN `' . _DB_PREFIX_ . 'currency` c ON c.id_currency = t.id_currency';
        if ($onlyActive) {
            $sql .= ' WHERE t.active = 1';
        }
        $sql .= ' ORDER BY c.iso_code ASC';

        return Db::getInstance()->executeS($sql) ?: [];
    }

    public function getTrackedCurrency(int $idTracked): ?array
    {
        $sql = 'SELECT t.*, c.iso_code, c.name
                FROM `' . _DB_PREFIX_ . 'nbpcurrency_tracked` t
                LEFT JOIN `' . _DB_PREFIX_ . 'currency` c ON c.id_currency = t.id_currency
                WHERE t.id_tracked = ' . (int) $idTracked;
        $row = Db::getInstance()->getRow($sql);

        return $row ?: null;
    }

    public function isCurrencyTracked(int $idCurrency): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT id_tracked FROM `' . _DB_PREFIX_ . 'nbpcurrency_tracked` WHERE id_currency = ' . (int) $idCurrency
        );
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function addTrackedCurrency(int $idCurrency, string $nbpTable, string $rateType, float $markup, bool $active): array
    {
        $currency = new Currency($idCurrency);
        if (!Validate::isLoadedObject($currency)) {
            return ['success' => false, 'message' => $this->l('Nieprawidłowa waluta.')];
        }
        if ($currency->iso_code === 'PLN') {
            return ['success' => false, 'message' => $this->l('PLN nie może być śledzone - NBP nie publikuje kursu PLN/PLN.')];
        }
        if ($this->isCurrencyTracked($idCurrency)) {
            return ['success' => false, 'message' => $this->l('Ta waluta jest już śledzona.')];
        }

        [$nbpTable, $rateType, $error] = $this->normalizeTableAndRateType($nbpTable, $rateType, $currency->iso_code);
        if ($error) {
            return ['success' => false, 'message' => $error];
        }

        $now = date('Y-m-d H:i:s');
        $ok  = Db::getInstance()->insert('nbpcurrency_tracked', [
            'id_currency' => (int) $idCurrency,
            'nbp_code'    => pSQL($currency->iso_code),
            'nbp_table'   => pSQL($nbpTable),
            'rate_type'   => pSQL($rateType),
            'markup'      => (float) $markup,
            'active'      => $active ? 1 : 0,
            'date_add'    => $now,
            'date_upd'    => $now,
        ]);

        return $ok
            ? ['success' => true, 'message' => $this->l('Waluta dodana do listy śledzonych.')]
            : ['success' => false, 'message' => $this->l('Błąd zapisu do bazy danych.')];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateTrackedCurrency(int $idTracked, string $nbpTable, string $rateType, float $markup, bool $active): array
    {
        $row = $this->getTrackedCurrency($idTracked);
        if (!$row) {
            return ['success' => false, 'message' => $this->l('Nie znaleziono wpisu.')];
        }

        [$nbpTable, $rateType, $error] = $this->normalizeTableAndRateType($nbpTable, $rateType, $row['nbp_code']);
        if ($error) {
            return ['success' => false, 'message' => $error];
        }

        $ok = Db::getInstance()->update('nbpcurrency_tracked', [
            'nbp_table' => pSQL($nbpTable),
            'rate_type' => pSQL($rateType),
            'markup'    => (float) $markup,
            'active'    => $active ? 1 : 0,
            'date_upd'  => date('Y-m-d H:i:s'),
        ], 'id_tracked = ' . (int) $idTracked);

        return $ok
            ? ['success' => true, 'message' => $this->l('Zmiany zapisane.')]
            : ['success' => false, 'message' => $this->l('Błąd zapisu do bazy danych.')];
    }

    public function deleteTrackedCurrency(int $idTracked): bool
    {
        return Db::getInstance()->delete('nbpcurrency_tracked', 'id_tracked = ' . (int) $idTracked);
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [nbp_table, rate_type, error]
     */
    private function normalizeTableAndRateType(string $nbpTable, string $rateType, string $isoCode): array
    {
        $nbpTable = strtoupper($nbpTable);
        if (!in_array($nbpTable, ['A', 'B', 'C'], true)) {
            return [$nbpTable, $rateType, $this->l('Nieprawidłowa tabela NBP.')];
        }

        $codesByTable = [
            'A' => self::TABLE_A_CODES,
            'B' => self::TABLE_B_CODES,
            'C' => self::TABLE_C_CODES,
        ];
        if (!in_array($isoCode, $codesByTable[$nbpTable], true)) {
            return [$nbpTable, $rateType, sprintf($this->l('NBP nie publikuje waluty %1$s w tabeli %2$s.'), $isoCode, $nbpTable)];
        }

        if (in_array($nbpTable, ['A', 'B'], true)) {
            $rateType = 'mid';
        } elseif (!in_array($rateType, ['bid', 'ask', 'avg'], true)) {
            $rateType = 'ask';
        }

        return [$nbpTable, $rateType, null];
    }

    // -------------------------------------------------------------------------
    // Core: fetch rates from NBP API and update PrestaShop currencies
    // -------------------------------------------------------------------------

    /**
     * Pobiera kursy wszystkich aktywnych śledzonych walut z NBP, opcjonalnie
     * aktualizuje conversion_rate w PrestaShop i zapisuje historię do logu.
     *
     * Bez $force: waluta, dla której NBP zwraca tę samą tabelę (data i kurs)
     * co przy ostatnim udanym pobraniu, nie trafia ponownie do logu, a
     * conversion_rate zapisywany jest tylko gdy faktycznie się zmienił –
     * weekendy, święta i tabela B poza środą nie generują zapisów.
     *
     * @return array{success: bool, message?: string, currencies: array}
     */
    public function fetchAll(bool $force = false): array
    {
        $tracked = $this->getTrackedCurrencies(true);

        $ratesPln  = []; // nbp_code => kurs PLN po markupie
        $rowsByIso = []; // nbp_code => wiersz z nbpcurrency_tracked
        $results   = []; // nbp_code => wynik do zwrócenia wywołującemu

        foreach ($tracked as $row) {
            $nbpData = $this->fetchNbpRate($row['nbp_table'], $row['nbp_code']);

            if (!$nbpData['success']) {
                $results[$row['nbp_code']] = ['success' => false, 'message' => $nbpData['message']];
                $this->logResult($row, null, null, null, false, $nbpData['message']);
                continue;
            }

            $rawValue = $this->computeRateValue($nbpData, $row['rate_type']);
            if ($rawValue === null) {
                $message = sprintf(
                    $this->l('Brak wartości "%1$s" w odpowiedzi NBP dla %2$s.'),
                    $row['rate_type'],
                    $row['nbp_code']
                );
                $results[$row['nbp_code']] = ['success' => false, 'message' => $message];
                $this->logResult($row, null, null, $nbpData['effectiveDate'] ?? null, false, $message);
                continue;
            }

            $ratePln = round($rawValue + (float) $row['markup'], 4);

            $ratesPln[$row['nbp_code']]  = $ratePln;
            $rowsByIso[$row['nbp_code']] = $row;
            $results[$row['nbp_code']]   = [
                'success'         => true,
                'rate_value'      => $ratePln,
                'effective_date'  => $nbpData['effectiveDate'],
                'unchanged'       => !$force && $this->isSameAsLastLog((int) $row['id_currency'], $nbpData['effectiveDate'], $ratePln),
            ];
        }

        if (!$ratesPln) {
            return [
                'success'    => false,
                'message'    => $this->l('Brak aktywnych śledzonych walut lub żadnego kursu nie udało się pobrać.'),
                'currencies' => $results,
            ];
        }

        $conversionResults = [];
        if (self::getConfig(self::CONFIG_KEY_UPDATE_PS_CUR)) {
            $conversionResults = $this->applyConversionRates($ratesPln);
        }

        foreach ($rowsByIso as $iso => $row) {
            $conversionRate = $conversionResults[$iso]['conversion_rate'] ?? null;
            $convSuccess    = $conversionResults[$iso]['success'] ?? true;
            $convMessage    = $conversionResults[$iso]['message'] ?? null;

            // Ta sama tabela NBP co poprzednio i nic się nie zmieniło – nie dublujemy wpisu w logu
            if (!$convSuccess || !$results[$iso]['unchanged'] || !empty($conversionResults[$iso]['changed'])) {
                $this->logResult(
                    $row,
                    $ratesPln[$iso],
                    $conversionRate,
                    $results[$iso]['effective_date'],
                    $convSuccess,
                    $convMessage
                );
            }

            if (!$convSuccess) {
                $results[$iso]['success'] = false;
                $results[$iso]['message'] = $convMessage;
            } elseif ($conversionRate !== null) {
                $results[$iso]['conversion_rate'] = $conversionRate;
            }
        }

        return ['success' => true, 'currencies' => $results];
    }

    /**
     * Wywołuje API NBP dla pojedynczej waluty i tabeli.
     *
     * @return array{success: bool, message?: string, bid?: ?float, ask?: ?float, mid?: ?float, effectiveDate?: string}
     */
    private function fetchNbpRate(string $table, string $nbpCode): array
    {
        $url = sprintf(self::NBP_RATE_URL, strtolower($table), strtolower($nbpCode));
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => 'cURL error: ' . $error];
        }
        if ($httpCode !== 200) {
            return ['success' => false, 'message' => 'HTTP ' . $httpCode];
        }

        $data = json_decode((string) $response, true);
        $rate = $data['rates'][0] ?? null;
        if (!$rate) {
            return ['success' => false, 'message' => $this->l('Nieprawidłowa odpowiedź API NBP.')];
        }

        return [
            'success'       => true,
            'bid'           => isset($rate['bid']) ? (float) $rate['bid'] : null,
            'ask'           => isset($rate['ask']) ? (float) $rate['ask'] : null,
            'mid'           => isset($rate['mid']) ? (float) $rate['mid'] : null,
            'effectiveDate' => $rate['effectiveDate'] ?? date('Y-m-d'),
        ];
    }

    private function isSameAsLastLog(int $idCurrency, string $effectiveDate, float $ratePln): bool
    {
        $last = Db::getInstance()->getRow(
            'SELECT `effective_date`, `rate_value` FROM `' . _DB_PREFIX_ . 'nbpcurrency_log`
             WHERE `id_currency` = ' . (int) $idCurrency . ' AND `success` = 1
             ORDER BY `id_log` DESC'
        );

        return $last
            && $last['effective_date'] === $effectiveDate
            && abs((float) $last['rate_value'] - $ratePln) < 0.00005;
    }

    private function computeRateValue(array $nbpData, string $rateType): ?float
    {
        switch ($rateType) {
            case 'bid':
                return $nbpData['bid'] ?? null;
            case 'ask':
                return $nbpData['ask'] ?? null;
            case 'avg':
                return isset($nbpData['bid'], $nbpData['ask'])
                    ? round(($nbpData['bid'] + $nbpData['ask']) / 2, 4)
                    : null;
            case 'mid':
            default:
                return $nbpData['mid'] ?? null;
        }
    }

    /**
     * Przelicza kursy PLN pobrane z NBP na conversion_rate zapisywane w
     * PrestaShop i zapisuje je w każdym wybranym sklepie (ps_currency_shop).
     *
     * NBP zawsze kwotuje względem PLN. Jeśli domyślną walutą sklepu jest PLN,
     * przeliczenie jest bezpośrednie (1 / kurs). Jeśli domyślną walutą jest
     * inna waluta D, potrzebujemy D jako "kotwicy": D musi być na liście
     * śledzonych walut, żeby dało się policzyć kurs krzyżowy pozostałych.
     * W multistore waluta domyślna jest ustalana osobno dla każdego sklepu.
     *
     * @param array<string, float> $ratesPln nbp_code => kurs PLN (po markupie)
     * @return array<string, array{success: bool, changed: bool, conversion_rate?: float, message?: string}>
     */
    public function applyConversionRates(array $ratesPln): array
    {
        $out = [];
        foreach (array_keys($ratesPln) as $iso) {
            $out[$iso] = ['success' => true, 'changed' => false];
        }

        $shopIds = self::getTargetShopIds();
        if (!$shopIds) {
            foreach (array_keys($out) as $iso) {
                $out[$iso] = ['success' => false, 'changed' => false, 'message' => $this->l('Nie wybrano żadnego sklepu do aktualizacji kursów.')];
            }
            return $out;
        }

        $defaultShopId = (int) Configuration::get('PS_SHOP_DEFAULT');
        $changed       = [];

        foreach ($shopIds as $idShop) {
            $shop       = Shop::getShop($idShop);
            $shopName   = $shop ? $shop['name'] : '#' . $idShop;
            $defaultIso = self::getShopDefaultIso($idShop);

            $targets = []; // iso => conversion_rate w tym sklepie
            if ($defaultIso === 'PLN') {
                foreach ($ratesPln as $iso => $ratePln) {
                    $targets[$iso] = round(1 / $ratePln, 6);
                }
            } elseif (isset($ratesPln[$defaultIso])) {
                $targets['PLN'] = round($ratesPln[$defaultIso], 6);
                foreach ($ratesPln as $iso => $ratePln) {
                    if ($iso !== $defaultIso) { // waluta domyślna ma zawsze conversion_rate = 1, nie ruszamy jej
                        $targets[$iso] = round($ratesPln[$defaultIso] / $ratePln, 6);
                    }
                }
            } else {
                $message = sprintf(
                    $this->l('%1$s: waluta domyślna sklepu (%2$s) nie jest na liście śledzonych walut - dodaj ją, aby moduł mógł przeliczać kursy.'),
                    $shopName,
                    $defaultIso
                );
                foreach (array_keys($out) as $iso) {
                    $out[$iso]['success'] = false;
                    $out[$iso]['message'] = $message;
                }
                continue;
            }

            foreach ($targets as $iso => $conversionRate) {
                $res = $this->writeShopRate($iso, $idShop, $conversionRate, $idShop === $defaultShopId);
                if (!isset($out[$iso])) {
                    continue; // PLN przy sklepie w innej walucie – nieśledzone, tylko zapis
                }
                if (!$res['success']) {
                    $out[$iso]['success'] = false;
                    $out[$iso]['message'] = $shopName . ': ' . $res['message'];
                    continue;
                }
                if ($res['changed']) {
                    $out[$iso]['changed'] = true;
                    $changed[$res['id_currency']] = true;
                }
                if (!$res['skipped'] && (!isset($out[$iso]['conversion_rate']) || $idShop === $defaultShopId)) {
                    $out[$iso]['conversion_rate'] = $conversionRate;
                }
            }
        }

        if ($changed) {
            Currency::resetStaticCache();
            // Powiadom moduły nasłuchujące zmian waluty (np. ps_eventbus)
            foreach (array_keys($changed) as $idCurrency) {
                Hook::exec('actionObjectCurrencyUpdateAfter', ['object' => new Currency($idCurrency)]);
            }
        }

        return $out;
    }

    /**
     * Zapisuje conversion_rate waluty w jednym sklepie.
     *
     * Zapis bezpośrednio w bazie, nie przez Currency::save() – ObjectModel
     * nadpisuje nazwy i symbole waluty we wszystkich językach wartością
     * z języka kontekstu. Brak czyszczenia cache Smarty: kurs nie jest
     * częścią skompilowanych szablonów, a pełny flush przy każdym cronie
     * wymuszał odbudowę cache wszystkich modułów i obciążał bazę.
     *
     * @return array{success: bool, changed: bool, skipped: bool, id_currency?: int, message?: string}
     */
    private function writeShopRate(string $isoCode, int $idShop, float $conversionRate, bool $isDefaultShop): array
    {
        $current = self::getShopCurrencyRate($isoCode, $idShop);
        if (!$current) {
            // Waluta nieprzypisana do tego sklepu – nie ma czego aktualizować
            return ['success' => true, 'changed' => false, 'skipped' => true];
        }

        if (abs($current['conversion_rate'] - $conversionRate) < 0.0000005) {
            return ['success' => true, 'changed' => false, 'skipped' => false, 'id_currency' => $current['id_currency']];
        }

        $db = Db::getInstance();
        $ok = $db->update(
            'currency_shop',
            ['conversion_rate' => $conversionRate],
            '`id_currency` = ' . (int) $current['id_currency'] . ' AND `id_shop` = ' . (int) $idShop
        );

        // Tabela główna ps_currency trzyma kurs sklepu domyślnego
        if ($ok && $isDefaultShop) {
            $ok = $db->update(
                'currency',
                ['conversion_rate' => $conversionRate],
                '`id_currency` = ' . (int) $current['id_currency']
            );
        }

        if (!$ok) {
            return ['success' => false, 'changed' => false, 'skipped' => false, 'message' => $this->l('Błąd zapisu waluty w bazie danych.')];
        }

        return ['success' => true, 'changed' => true, 'skipped' => false, 'id_currency' => $current['id_currency']];
    }

    private function logResult(
        array $trackedRow,
        ?float $rateValue,
        ?float $conversionRate,
        ?string $effectiveDate,
        bool $success,
        ?string $message
    ): void {
        Db::getInstance()->insert('nbpcurrency_log', [
            'id_currency'     => (int) $trackedRow['id_currency'],
            'nbp_code'        => pSQL($trackedRow['nbp_code']),
            'nbp_table'       => pSQL($trackedRow['nbp_table']),
            'rate_type'       => pSQL($trackedRow['rate_type']),
            'rate_value'      => $rateValue !== null ? $rateValue : 0,
            'conversion_rate' => $conversionRate,
            'effective_date'  => $effectiveDate ? pSQL($effectiveDate) : null,
            'fetched_at'      => date('Y-m-d H:i:s'),
            'success'         => $success ? 1 : 0,
            'message'         => $message !== null ? pSQL($message) : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers: historia / odczyt aktualnych wartości do UI i innych modułów
    // -------------------------------------------------------------------------

    public function getLatestLog(int $idCurrency): ?array
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'nbpcurrency_log`
                WHERE id_currency = ' . (int) $idCurrency . '
                ORDER BY id_log DESC';
        $row = Db::getInstance()->getRow($sql);

        return $row ?: null;
    }

    public function getRecentLog(int $limit = 20): array
    {
        $sql = 'SELECT l.*, c.iso_code
                FROM `' . _DB_PREFIX_ . 'nbpcurrency_log` l
                LEFT JOIN `' . _DB_PREFIX_ . 'currency` c ON c.id_currency = l.id_currency
                ORDER BY l.id_log DESC
                LIMIT ' . (int) $limit;

        return Db::getInstance()->executeS($sql) ?: [];
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Hook uruchamiany przez CronJobs module (jeśli zainstalowany).
     */
    public function hookActionCronJob(): void
    {
        if (self::getConfig(self::CONFIG_KEY_AUTO_UPDATE)) {
            $this->fetchAll();
        }
    }
}
