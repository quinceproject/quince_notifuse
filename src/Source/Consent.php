<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Notifuse\Source;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;

/**
 * Zgoda odwiedzającego na pliki analityczne (`cookies_analytics` w Quince CMP).
 *
 * Zdarzenia z zachowań klienta (oglądanie, koszyk, ulubione) idą tylko po tej
 * zgodzie. Pytamy publiczne API modułu zgód (`allowed()`,
 * Quince CMP API.md, sekcja 1), nigdy jego ciasteczka ani klas wewnętrznych.
 *
 * Odpowiedź dotyczy bieżącego odwiedzającego, więc pytamy w trakcie jego
 * żądania i zapisujemy wynik przy kliencie. Zdarzenia wysyłane bez udziału
 * klienta (spadek ceny, powrót do sprzedaży, porzucony koszyk) korzystają
 * z tego zapisu: tak samo Quince Insight zapisuje zgodę przy zamówieniu.
 * Bez modułu zgód nie ma czym zgody potwierdzić: zachowań wtedy nie wysyłamy.
 */
final class Consent
{
    public const ANALYTICS = 'cookies_analytics';

    /** Ostatnia znana zgoda zalogowanego klienta. */
    public const TABLE = 'quince_notifuse_consent';

    public static function analityczna(int $idShop): bool
    {
        if (!\Module::isEnabled('quince_cmp')) {
            return false;
        }

        $cmp = \Module::getInstanceByName('quince_cmp');

        return is_object($cmp) && method_exists($cmp, 'allowed') && (bool) $cmp->allowed(self::ANALYTICS, $idShop);
    }

    /**
     * Zgoda bieżącego odwiedzającego, zapisana przy kliencie. Zapisujemy też brak
     * zgody: cofnięcie musi zatrzymać wysyłkę z crona.
     */
    public static function sprawdzIZapisz(int $idCustomer, int $idShop): bool
    {
        $zgoda = self::analityczna($idShop);

        if ($idCustomer > 0) {
            $poprzednia = self::zapisana($idCustomer, $idShop);

            Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (id_customer, id_shop, analytics, date_upd)
                 VALUES (' . $idCustomer . ', ' . $idShop . ', ' . ($zgoda ? 1 : 0) . ', NOW())
                 ON DUPLICATE KEY UPDATE analytics = VALUES(analytics), date_upd = NOW()'
            );

            if ($poprzednia !== $zgoda) {
                self::przekazSledzenie($idCustomer, $idShop, $zgoda);
            }
        }

        return $zgoda;
    }

    /** Zapisana zgoda klienta: `null`, gdy jeszcze żadnej nie zapisaliśmy. */
    public static function zapisana(int $idCustomer, int $idShop): ?bool
    {
        $wartosc = Db::getInstance()->getValue(
            'SELECT analytics FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE id_customer = ' . $idCustomer . ' AND id_shop = ' . $idShop
        );

        return $wartosc === false || $wartosc === null ? null : (bool) (int) $wartosc;
    }

    /**
     * Zgoda na śledzenie otwarć i kliknięć maili w kontakcie Notifuse
     * obie wynikają ze zgody
     * analitycznej, ustawia je wyłącznie sklep. Idzie z pełnym profilem, bo
     * dla klienta sprzed włączenia modułu to pierwszy zapis kontaktu.
     *
     * Tylko klienci z kontem: gość trafia do Notifuse dopiero z porzuconym
     * koszykiem albo zamówieniem.
     */
    private static function przekazSledzenie(int $idCustomer, int $idShop, bool $zgoda): void
    {
        $ustawienia = new \Quince\Notifuse\Settings\NotifuseSettings();
        $klient = new \Customer($idCustomer);

        if (!$klient->email || $klient->is_guest || !$ustawienia->polaczenieUstawione($idShop)) {
            return;
        }

        (new \Quince\Notifuse\Queue\Sender())->dodajIWyslij($idShop, 'contact', 'tracking_consent', (string) $klient->email, [
            'contact' => Account::kontakt($klient, $idShop) + self::polaSledzenia($zgoda),
        ]);
    }

    /** @return array{tracking_opens_consent:string,tracking_clicks_consent:string} */
    public static function polaSledzenia(bool $zgoda): array
    {
        $wartosc = $zgoda ? 'granted' : 'denied';

        return ['tracking_opens_consent' => $wartosc, 'tracking_clicks_consent' => $wartosc];
    }

    /**
     * Warunek SQL: klient o tym aliasie ma zapisaną zgodę analityczną w sklepie.
     */
    public static function warunekSql(string $aliasKlienta, int $idShop): string
    {
        return 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '` qz
                 WHERE qz.id_customer = ' . $aliasKlienta . '.id_customer AND qz.id_shop = ' . $idShop . ' AND qz.analytics = 1)';
    }

    public static function installTable(): bool
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_customer` INT(11) UNSIGNED NOT NULL,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `analytics` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_customer`, `id_shop`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function dropTable(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }
}
