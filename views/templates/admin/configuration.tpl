{**
 * Admin template – NBP Currency configuration
 *}

<script>
function nbpToggleRateType(selectEl, groupEl) {
    if (!groupEl) { return; }
    groupEl.style.display = (selectEl.value === 'C') ? '' : 'none';
}
</script>

{foreach from=$untracked_default item=shop}
<div class="alert alert-warning">
    <i class="icon-warning"></i>
    {l s='Waluta domyślna sklepu' mod='nbpcurrency'} <strong>{$shop.name|escape:'html':'UTF-8'}</strong> {l s='to' mod='nbpcurrency'} <strong>{$shop.default_iso|escape:'html':'UTF-8'}</strong>.
    {l s='Aby moduł mógł poprawnie przeliczać kursy pozostałych walut, dodaj tę walutę do listy śledzonych poniżej.' mod='nbpcurrency'}
</div>
{/foreach}

{* ---- Śledzone waluty ---- *}
<div class="panel">
    <div class="panel-heading">
        <i class="material-icons">monetization_on</i>
        {l s='Śledzone waluty' mod='nbpcurrency'}
    </div>
    <div class="panel-body">

        {* Formularze-powłoki (bez widocznej treści) – umożliwiają wysyłkę
           pól z poszczególnych wierszy tabeli przez atrybut form="..." bez
           zagnieżdżania <form> wewnątrz <tr>, co jest niepoprawnym HTML. *}
        {foreach from=$tracked_currencies item=tc}
        <form id="trackedForm_{$tc.id_tracked}" method="post" action="{$form_action}">
            <input type="hidden" name="updateTrackedCurrency" value="1">
            <input type="hidden" name="id_tracked" value="{$tc.id_tracked}">
        </form>
        {/foreach}

        {if $tracked_currencies}
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>{l s='Waluta' mod='nbpcurrency'}</th>
                        <th>{l s='Tabela NBP / typ kursu' mod='nbpcurrency'}</th>
                        <th>{l s='Marża' mod='nbpcurrency'}</th>
                        <th>{l s='Kurs NBP' mod='nbpcurrency'}</th>
                        <th>{l s='Kurs w PS' mod='nbpcurrency'}</th>
                        <th>{l s='Ostatnia aktualizacja' mod='nbpcurrency'}</th>
                        <th class="text-center">{l s='Aktywna' mod='nbpcurrency'}</th>
                        <th class="text-right">{l s='Akcje' mod='nbpcurrency'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$tracked_currencies item=tc}
                    <tr>
                        <td>
                            <strong>{$tc.iso_code|escape:'html':'UTF-8'}</strong><br>
                            <small class="text-muted">{$tc.name|escape:'html':'UTF-8'}</small>
                        </td>
                        <td>
                            <select name="nbp_table" form="trackedForm_{$tc.id_tracked}"
                                    class="form-control input-sm"
                                    onchange="nbpToggleRateType(this, document.getElementById('rate_type_group_{$tc.id_tracked}'))">
                                <option value="A" {if $tc.nbp_table == 'A'}selected="selected"{/if}>A – {l s='kurs średni' mod='nbpcurrency'}</option>
                                <option value="B" {if $tc.nbp_table == 'B'}selected="selected"{/if}>B – {l s='kurs średni, raz w tygodniu' mod='nbpcurrency'}</option>
                                <option value="C" {if $tc.nbp_table == 'C'}selected="selected"{/if}>C – {l s='kupno/sprzedaż' mod='nbpcurrency'}</option>
                            </select>
                            <div id="rate_type_group_{$tc.id_tracked}" style="margin-top:4px;{if $tc.nbp_table != 'C'}display:none;{/if}">
                                <select name="rate_type" form="trackedForm_{$tc.id_tracked}" class="form-control input-sm">
                                    <option value="ask" {if $tc.rate_type == 'ask'}selected="selected"{/if}>{l s='sprzedaż (ask)' mod='nbpcurrency'}</option>
                                    <option value="bid" {if $tc.rate_type == 'bid'}selected="selected"{/if}>{l s='kupno (bid)' mod='nbpcurrency'}</option>
                                    <option value="avg" {if $tc.rate_type == 'avg'}selected="selected"{/if}>{l s='średnia' mod='nbpcurrency'}</option>
                                </select>
                            </div>
                        </td>
                        <td>
                            <input type="number" step="0.0001" min="0" name="markup"
                                   form="trackedForm_{$tc.id_tracked}"
                                   class="form-control input-sm" style="width:90px;"
                                   value="{$tc.markup|escape:'html':'UTF-8'}">
                        </td>
                        <td>
                            {if $tc.rate_value}
                                {$tc.rate_value|string_format:"%.4f"}
                            {else}
                                <span class="text-muted">{l s='brak danych' mod='nbpcurrency'}</span>
                            {/if}
                        </td>
                        <td>
                            {foreach from=$tc.shop_rates item=sr}
                                {if count($tc.shop_rates) > 1}<small class="text-muted">{$sr.name|escape:'html':'UTF-8'}:</small> {/if}{$sr.rate|string_format:"%.6f"}<br>
                            {foreachelse}
                                <span class="text-muted">{l s='brak danych' mod='nbpcurrency'}</span>
                            {/foreach}
                        </td>
                        <td>
                            {if $tc.last_update}
                                {$tc.last_update|escape:'html':'UTF-8'}
                                {if $tc.last_success === false}
                                    <br><span class="label label-danger" title="{$tc.last_message|escape:'html':'UTF-8'}">{l s='błąd' mod='nbpcurrency'}</span>
                                {/if}
                            {else}
                                <span class="text-muted">{l s='nigdy' mod='nbpcurrency'}</span>
                            {/if}
                        </td>
                        <td class="text-center">
                            <input type="hidden" name="active" value="0" form="trackedForm_{$tc.id_tracked}">
                            <input type="checkbox" name="active" value="1" form="trackedForm_{$tc.id_tracked}" {if $tc.active}checked="checked"{/if}>
                        </td>
                        <td class="text-right">
                            <button type="submit" form="trackedForm_{$tc.id_tracked}" class="btn btn-default btn-xs" title="{l s='Zapisz' mod='nbpcurrency'}">
                                <i class="icon-save"></i>
                            </button>
                            <form method="post" action="{$form_action}" style="display:inline;"
                                  onsubmit="return confirm('{l s='Na pewno usunąć tę walutę z listy śledzonych?' mod='nbpcurrency'}');">
                                <input type="hidden" name="deleteTrackedCurrency" value="1">
                                <input type="hidden" name="id_tracked" value="{$tc.id_tracked}">
                                <button type="submit" class="btn btn-default btn-xs" title="{l s='Usuń' mod='nbpcurrency'}">
                                    <i class="icon-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        {else}
        <p class="text-muted">{l s='Brak śledzonych walut – dodaj pierwszą poniżej.' mod='nbpcurrency'}</p>
        {/if}

        {* ---- Dodaj nową walutę ---- *}
        <hr>
        <form method="post" action="{$form_action}" class="form-inline">
            <input type="hidden" name="addTrackedCurrency" value="1">
            <input type="hidden" name="active" value="0">

            <div class="form-group" style="margin-right:10px;">
                <label>{l s='Waluta' mod='nbpcurrency'}</label><br>
                <select name="id_currency" class="form-control" required="required">
                    <option value="">-- {l s='wybierz' mod='nbpcurrency'} --</option>
                    {foreach from=$available_currencies item=cur}
                        <option value="{$cur.id_currency}">{$cur.iso_code|escape:'html':'UTF-8'} – {$cur.name|escape:'html':'UTF-8'}</option>
                    {/foreach}
                </select>
            </div>

            <div class="form-group" style="margin-right:10px;">
                <label>{l s='Tabela NBP' mod='nbpcurrency'}</label><br>
                <select name="nbp_table" class="form-control"
                        onchange="nbpToggleRateType(this, document.getElementById('add_rate_type_group'))">
                    <option value="A">A – {l s='kurs średni' mod='nbpcurrency'}</option>
                    <option value="B">B – {l s='kurs średni, raz w tygodniu' mod='nbpcurrency'}</option>
                    <option value="C">C – {l s='kupno/sprzedaż' mod='nbpcurrency'}</option>
                </select>
            </div>

            <div class="form-group" id="add_rate_type_group" style="margin-right:10px; display:none;">
                <label>{l s='Typ kursu' mod='nbpcurrency'}</label><br>
                <select name="rate_type" class="form-control">
                    <option value="ask">{l s='sprzedaż (ask)' mod='nbpcurrency'}</option>
                    <option value="bid">{l s='kupno (bid)' mod='nbpcurrency'}</option>
                    <option value="avg">{l s='średnia' mod='nbpcurrency'}</option>
                </select>
            </div>

            <div class="form-group" style="margin-right:10px;">
                <label>{l s='Marża' mod='nbpcurrency'}</label><br>
                <input type="number" step="0.0001" min="0" name="markup" class="form-control" style="width:100px;" value="0">
            </div>

            <div class="form-group" style="margin-right:10px;">
                <label>&nbsp;</label><br>
                <label class="checkbox-inline">
                    <input type="checkbox" name="active" value="1" checked="checked"> {l s='aktywna' mod='nbpcurrency'}
                </label>
            </div>

            <div class="form-group">
                <label>&nbsp;</label><br>
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">add</i> {l s='Dodaj walutę' mod='nbpcurrency'}
                </button>
            </div>
        </form>
        <p class="help-block">
            {l s='Tabela A i C: kursy z dni roboczych, ok. godz. 12:00. Tabela B: waluty mniej płynne, publikowane raz w tygodniu (środy).' mod='nbpcurrency'}
        </p>
    </div>
</div>

{* ---- Pobieranie i ustawienia globalne ---- *}
<div class="panel">
    <div class="panel-heading">
        <i class="material-icons">refresh</i>
        {l s='Pobieranie kursów' mod='nbpcurrency'}
    </div>
    <div class="panel-body">
        <form method="post" action="{$form_action}">
            <input type="hidden" name="refreshRate" value="1">
            <button type="submit" class="btn btn-primary">
                <i class="material-icons">refresh</i>
                {l s='Pobierz kursy teraz' mod='nbpcurrency'}
            </button>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="material-icons">settings</i>
        {l s='Ustawienia globalne' mod='nbpcurrency'}
    </div>
    <div class="panel-body">
        <form method="post" action="{$form_action}" class="defaultForm form-horizontal">
            <input type="hidden" name="submitNbpConfig" value="1">

            <div class="form-group">
                <label class="control-label col-lg-3">
                    {l s='Automatyczna aktualizacja (hook CronJobs)' mod='nbpcurrency'}
                </label>
                <div class="col-lg-4">
                    <span class="switch prestashop-switch fixed-width-lg">
                        <input type="radio" name="auto_update" id="auto_update_on" value="1"
                               {if $auto_update}checked="checked"{/if}>
                        <label for="auto_update_on">{l s='Tak' mod='nbpcurrency'}</label>
                        <input type="radio" name="auto_update" id="auto_update_off" value="0"
                               {if !$auto_update}checked="checked"{/if}>
                        <label for="auto_update_off">{l s='Nie' mod='nbpcurrency'}</label>
                        <a class="slide-button btn"></a>
                    </span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3">
                    {l s='Zapisuj pobrane kursy do walut PrestaShop' mod='nbpcurrency'}
                </label>
                <div class="col-lg-7">
                    <span class="switch prestashop-switch fixed-width-lg">
                        <input type="radio" name="update_ps_currency" id="update_ps_cur_on" value="1"
                               {if $update_ps_currency}checked="checked"{/if}>
                        <label for="update_ps_cur_on">{l s='Tak' mod='nbpcurrency'}</label>
                        <input type="radio" name="update_ps_currency" id="update_ps_cur_off" value="0"
                               {if !$update_ps_currency}checked="checked"{/if}>
                        <label for="update_ps_cur_off">{l s='Nie' mod='nbpcurrency'}</label>
                        <a class="slide-button btn"></a>
                    </span>
                    <p class="help-block">
                        {l s='Jeśli wyłączone, moduł tylko pobiera i loguje kursy, bez zapisu do tabeli walut PrestaShop.' mod='nbpcurrency'}<br>
                        {l s='Kurs jest zapisywany tylko gdy się zmienił – wywołanie bez nowej tabeli NBP (weekend, święto) niczego nie zapisuje.' mod='nbpcurrency'}
                    </p>
                </div>
            </div>

            {if count($shops) > 1}
            <div class="form-group">
                <label class="control-label col-lg-3">
                    {l s='Sklepy, w których aktualizować kursy' mod='nbpcurrency'}
                </label>
                <div class="col-lg-6">
                    {foreach from=$shops item=shop}
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="shops[]" value="{$shop.id_shop|intval}"
                                   {if $shop.selected}checked="checked"{/if}>
                            {$shop.name|escape:'html':'UTF-8'}
                            <small class="text-muted">({l s='waluta domyślna' mod='nbpcurrency'}: {$shop.default_iso|escape:'html':'UTF-8'})</small>
                        </label>
                    </div>
                    {/foreach}
                </div>
            </div>
            {else}
                {foreach from=$shops item=shop}
                <input type="hidden" name="shops[]" value="{$shop.id_shop|intval}">
                {/foreach}
            {/if}

            <div class="panel-footer">
                <button type="submit" class="btn btn-default pull-right">
                    <i class="process-icon-save"></i>
                    {l s='Zapisz' mod='nbpcurrency'}
                </button>
            </div>
        </form>
    </div>
</div>

{* ---- URL Cron ---- *}
<div class="panel">
    <div class="panel-heading">
        <i class="material-icons">schedule</i>
        {l s='Konfiguracja Cron' mod='nbpcurrency'}
    </div>
    <div class="panel-body">
        <p>{l s='Dodaj poniższy adres do harmonogramu zadań (crontab) lub modułu CronJobs w PrestaShop.' mod='nbpcurrency'}</p>
        <div class="input-group">
            <input type="text" class="form-control" readonly
                   id="cron_url_input"
                   value="{$cron_url|escape:'html':'UTF-8'}">
            <span class="input-group-btn">
                <button class="btn btn-default" type="button"
                        onclick="var el=document.getElementById('cron_url_input');el.select();document.execCommand('copy');this.innerHTML='<i class=\'material-icons\'>check</i>';">
                    <i class="material-icons">content_copy</i>
                </button>
            </span>
        </div>
        <p class="help-block" style="margin-top:8px;">
            <strong>{l s='Przykład crontab (pon–pt, godz. 12:05):' mod='nbpcurrency'}</strong><br>
            <code>5 12 * * 1-5 curl -s "{$cron_url|escape:'html':'UTF-8'}" > /dev/null</code>
        </p>
    </div>
</div>

{* ---- Historia pobierania ---- *}
{if $rate_log}
<div class="panel">
    <div class="panel-heading">
        <i class="material-icons">history</i>
        {l s='Historia (ostatnie 20 rekordów)' mod='nbpcurrency'}
    </div>
    <div class="panel-body">
        <div class="table-responsive">
            <table class="table tableDnD">
                <thead>
                    <tr>
                        <th>{l s='Waluta' mod='nbpcurrency'}</th>
                        <th>{l s='Tabela' mod='nbpcurrency'}</th>
                        <th>{l s='Typ' mod='nbpcurrency'}</th>
                        <th>{l s='Kurs' mod='nbpcurrency'}</th>
                        <th>{l s='Kurs w PS' mod='nbpcurrency'}</th>
                        <th>{l s='Data tabeli NBP' mod='nbpcurrency'}</th>
                        <th>{l s='Pobrano o' mod='nbpcurrency'}</th>
                        <th>{l s='Status' mod='nbpcurrency'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$rate_log item=row}
                    <tr>
                        <td>{$row.iso_code|default:$row.nbp_code|escape:'html':'UTF-8'}</td>
                        <td>{$row.nbp_table|escape:'html':'UTF-8'}</td>
                        <td>{$row.rate_type|escape:'html':'UTF-8'}</td>
                        <td>{$row.rate_value|string_format:"%.4f"}</td>
                        <td>{if $row.conversion_rate}{$row.conversion_rate|string_format:"%.6f"}{else}–{/if}</td>
                        <td>{$row.effective_date|escape:'html':'UTF-8'}</td>
                        <td>{$row.fetched_at|escape:'html':'UTF-8'}</td>
                        <td>
                            {if $row.success}
                                <span class="label label-success">OK</span>
                            {else}
                                <span class="label label-danger" title="{$row.message|escape:'html':'UTF-8'}">{l s='błąd' mod='nbpcurrency'}</span>
                            {/if}
                        </td>
                    </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
</div>
{/if}
