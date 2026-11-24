<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Notifuse\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Quince\Engine\Settings\ConfigStore;
use Shop;

/**
 * Ustawienia modułu, osobno dla każdego sklepu.
 *
 * Odczyt zawsze z jawnym sklepem: kolejka wysyła wpisy z crona, gdzie kontekst
 * sklepu bywa przypadkowy, a każdy wpis należy do sklepu, z którego przyszedł.
 */
final class NotifuseSettings
{
    private const PREFIX = 'QUINCE_NOTIFUSE_';

    /** Zdarzenia z przełącznikiem w panelu, w kolejności tabeli. */
    public const EVENTS = [
        'customer.registered',
        'product.viewed',
        'cart.updated',
        'wishlist.added',
        'product.price_dropped',
        'product.back_in_stock',
        'cart.abandoned',
        'order.placed',
        'order.shipped',
    ];

    /** Pola kontaktu w Notifuse na listę ostatnio oglądanych. */
    public const JSON_FIELDS = ['custom_json_1', 'custom_json_2', 'custom_json_3', 'custom_json_4', 'custom_json_5'];

    /** @var array<string,string> klucz => wartość domyślna */
    private const SCHEMA = [
        'URL' => '',
        'WORKSPACE' => '',
        'API_KEY' => '',
        // Lista Notifuse dla każdego języka sklepu: {"id_lang": "id listy"}.
        'LISTS' => '{}',
        'TRACKING' => '0',
        // Sekret obszaru roboczego do podpisu `identify()`. Opcjonalny.
        'SECRET' => '',
        // Przełączniki zdarzeń: {"nazwa": 0|1}. Brak wpisu znaczy „włączone".
        'EVENTS' => '{}',
        'ABANDON_MINUTES' => '60',
        'IMAGE_TYPE' => 'home_default',
        'RECENT' => '0',
        'RECENT_FIELD' => 'custom_json_1',
        'RECENT_COUNT' => '10',
        'ALERT' => '1',
    ];

    /** Pola, których formularz nie pokazuje: puste pole przy zapisie znaczy „bez zmian". */
    private const UKRYTE = ['API_KEY', 'SECRET'];

    /** Kiedy ostatnio poszedł mail o błędach wysyłki (znacznik czasu, globalnie). */
    public const LAST_ALERT = 'QUINCE_NOTIFUSE_LAST_ALERT';

    /** @return array<string,string> */
    public function all(?int $idShop = null): array
    {
        $idShop ??= (int) \Context::getContext()->shop->id;
        $out = [];

        foreach (self::SCHEMA as $klucz => $domyslna) {
            $wartosc = Configuration::get(self::PREFIX . $klucz, null, null, $idShop);
            $out[$klucz] = ($wartosc === false || $wartosc === null) ? $domyslna : (string) $wartosc;
        }

        return $out;
    }

    public function get(string $klucz, ?int $idShop = null): string
    {
        return $this->all($idShop)[$klucz] ?? '';
    }

    /** Połączenie ma adres, obszar roboczy i klucz. */
    public function polaczenieUstawione(?int $idShop = null): bool
    {
        $u = $this->all($idShop);

        return $u['URL'] !== '' && $u['WORKSPACE'] !== '' && $u['API_KEY'] !== '';
    }

    /** @return array<int,string> id_lang => id listy */
    public function listy(?int $idShop = null): array
    {
        $dane = json_decode($this->get('LISTS', $idShop), true);
        $out = [];

        foreach (is_array($dane) ? $dane : [] as $idLang => $idListy) {
            if ((string) $idListy !== '') {
                $out[(int) $idLang] = (string) $idListy;
            }
        }

        return $out;
    }

    /** @return array<string,bool> */
    public function zdarzenia(?int $idShop = null): array
    {
        $dane = json_decode($this->get('EVENTS', $idShop), true);
        $dane = is_array($dane) ? $dane : [];
        $out = [];

        foreach (self::EVENTS as $nazwa) {
            $out[$nazwa] = !array_key_exists($nazwa, $dane) || (bool) $dane[$nazwa];
        }

        return $out;
    }

    public function zdarzenieWlaczone(string $nazwa, ?int $idShop = null): bool
    {
        return $this->zdarzenia($idShop)[$nazwa] ?? false;
    }

    /**
     * Zapis z formularza.
     *
     * @param array<string,mixed> $dane
     */
    public function save(array $dane): void
    {
        $adres = rtrim(trim((string) ($dane['URL'] ?? '')), '/');
        $this->zapisz('URL', filter_var($adres, FILTER_VALIDATE_URL) ? $adres : '');
        $this->zapisz('WORKSPACE', preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($dane['WORKSPACE'] ?? '')) ?? '');

        foreach (self::UKRYTE as $klucz) {
            $wartosc = trim((string) ($dane[$klucz] ?? ''));

            if ($wartosc !== '') {
                $this->zapisz($klucz, $wartosc);
            }
        }

        $listy = [];
        foreach ((array) ($dane['LISTS'] ?? []) as $idLang => $idListy) {
            $idListy = preg_replace('/[^A-Za-z0-9]/', '', (string) $idListy) ?? '';

            if ($idListy !== '') {
                $listy[(int) $idLang] = $idListy;
            }
        }
        $this->zapisz('LISTS', (string) json_encode((object) $listy), true);

        $zdarzenia = [];
        foreach (self::EVENTS as $nazwa) {
            $zdarzenia[$nazwa] = !empty(((array) ($dane['EVENTS'] ?? []))[$nazwa]) ? 1 : 0;
        }
        $this->zapisz('EVENTS', (string) json_encode($zdarzenia), true);

        $this->zapisz('TRACKING', !empty($dane['TRACKING']) ? '1' : '0');
        $this->zapisz('RECENT', !empty($dane['RECENT']) ? '1' : '0');
        $this->zapisz('ALERT', !empty($dane['ALERT']) ? '1' : '0');

        $this->zapisz('ABANDON_MINUTES', (string) max(15, min(10080, (int) ($dane['ABANDON_MINUTES'] ?? 60))));
        $this->zapisz('RECENT_COUNT', (string) max(1, min(20, (int) ($dane['RECENT_COUNT'] ?? 10))));

        $pole = (string) ($dane['RECENT_FIELD'] ?? '');
        $this->zapisz('RECENT_FIELD', in_array($pole, self::JSON_FIELDS, true) ? $pole : 'custom_json_1');

        $typ = (string) ($dane['IMAGE_TYPE'] ?? '');
        $this->zapisz('IMAGE_TYPE', preg_match('/^[a-z0-9_]+$/', $typ) ? $typ : 'home_default');
    }

    /** Wyczyszczenie sekretu: pole hasła nie odróżnia „bez zmian" od „usuń". */
    public function usunSekret(): void
    {
        $this->zapisz('SECRET', '');
    }

    /** Token adresu crona, losowy, jeden dla wszystkich sklepów. */
    public function cronToken(): string
    {
        $token = (string) Configuration::getGlobalValue(self::PREFIX . 'CRON_TOKEN');

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            Configuration::updateGlobalValue(self::PREFIX . 'CRON_TOKEN', $token);
        }

        return $token;
    }

    /**
     * Wartości początkowe przy instalacji, dla każdego sklepu. Instalacja idzie
     * z konsoli albo z kontekstu jednego sklepu, więc bez tego pozostałe sklepy
     * zostałyby bez wartości.
     */
    public function installDefaults(): bool
    {
        foreach (self::SCHEMA as $klucz => $domyslna) {
            Configuration::updateGlobalValue(self::PREFIX . $klucz, $domyslna, true);

            foreach (Shop::getShops(false, null, true) as $idShop) {
                Configuration::updateValue(self::PREFIX . $klucz, $domyslna, true, null, (int) $idShop);
            }
        }

        $this->cronToken();

        return true;
    }

    /** Konkretne klucze, nigdy wzorzec `LIKE`. */
    public function deleteAll(): bool
    {
        foreach (array_keys(self::SCHEMA) as $klucz) {
            Configuration::deleteByName(self::PREFIX . $klucz);
        }

        Configuration::deleteByName(self::PREFIX . 'CRON_TOKEN');
        foreach (Shop::getShops(false, null, true) as $idShop) {
            Configuration::deleteByName(self::LAST_ALERT . '_' . (int) $idShop);
        }
        Configuration::deleteByName('QUINCE_NOTIFUSE_LAST_PURGE');

        return true;
    }

    private function zapisz(string $klucz, string $wartosc, bool $html = false): void
    {
        ConfigStore::set(self::PREFIX . $klucz, $wartosc, $html);
    }
}
