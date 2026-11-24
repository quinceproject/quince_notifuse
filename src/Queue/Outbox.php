<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Notifuse\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Db;

/**
 * Kolejka wpisów do Notifuse.
 *
 * Wpis **najpierw ląduje w bazie**, dopiero potem idzie do Notifuse. Odwrotna
 * kolejność gubi dane, gdy Notifuse akurat nie odpowiada. Ta sama tabela jest
 * dziennikiem w panelu: widać w niej, co wyszło, co czeka i co się nie udało.
 *
 * Rodzaje wpisów odpowiadają wywołaniom API:
 * - `contact`: `contacts.upsert`;
 * - `subscribe`: publiczny `/subscribe` (zapis czeka na potwierdzenie mailem);
 * - `list_status`: `contactLists.updateStatus` na jednej liście;
 * - `unsubscribe`: wypis ze wszystkich list, na których adres jest zapisany;
 * - `email_change`: nowy adres przejmuje zapisy starego, stary zostaje wypisany;
 * - `event`: `customEvents.upsert`.
 */
final class Outbox
{
    public const TABLE = 'quince_notifuse_outbox';

    public const KINDS = ['contact', 'subscribe', 'list_status', 'unsubscribe', 'email_change', 'event'];

    /** Ile prób, zanim wpis zostaje uznany za nieudany. */
    public const MAX_ATTEMPTS = 6;

    public const PER_PAGE = 20;

    /** Odstępy między próbami w sekundach: minuta, pięć, pół godziny, dwie, sześć. */
    private const BACKOFF = [60, 300, 1800, 7200, 21600];

    /** Ile wpisów wysyła jedno wywołanie crona. */
    public const CRON_BATCH = 200;

    private const KEEP_SENT_DAYS = 30;
    private const KEEP_FAILED_DAYS = 90;

    /** Kiedy ostatnio sprzątaliśmy stare wpisy (znacznik czasu, globalnie). */
    public const LAST_PURGE = 'QUINCE_NOTIFUSE_LAST_PURGE';

    public function installTable(): bool
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_quince_notifuse_outbox` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `kind` VARCHAR(16) NOT NULL,
                `label` VARCHAR(64) NOT NULL,
                `email` VARCHAR(255) NOT NULL DEFAULT "",
                `payload` MEDIUMTEXT NOT NULL,
                `status` ENUM("pending","sent","failed") NOT NULL DEFAULT "pending",
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `last_error` VARCHAR(255) NOT NULL DEFAULT "",
                `next_try_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_quince_notifuse_outbox`),
                INDEX `idx_do_wyslania` (`status`, `next_try_at`),
                INDEX `idx_email` (`email`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public function dropTable(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /**
     * @param array<string,mixed> $payload treść żądania bez `workspace_id`
     */
    public function add(int $idShop, string $kind, string $label, string $email, array $payload): int
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown outbox kind: ' . $kind);
        }

        $teraz = date('Y-m-d H:i:s');

        // Najkrótszy zapis liczb: przy `serialize_precision = 17` (ustawienie
        // serwera) 27.3 wychodzi jako 27.300000000000001 i tak trafia do maila.
        $precyzja = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ini_set('serialize_precision', (string) $precyzja);

        Db::getInstance()->insert(self::TABLE, [
            'id_shop' => $idShop,
            'kind' => pSQL($kind),
            'label' => pSQL(substr($label, 0, 64)),
            'email' => pSQL(substr($email, 0, 255)),
            'payload' => pSQL($json, true),
            'status' => 'pending',
            'attempts' => 0,
            'next_try_at' => 0,
            'date_add' => $teraz,
            'date_upd' => $teraz,
        ]);

        return (int) Db::getInstance()->Insert_ID();
    }

    public function markSent(int $id): void
    {
        Db::getInstance()->update(self::TABLE, [
            'status' => 'sent',
            'attempts' => ['type' => 'sql', 'value' => 'attempts + 1'],
            'last_error' => '',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_quince_notifuse_outbox = ' . $id);
    }

    /**
     * Nieudana próba: rosnący odstęp do następnej, po wyczerpaniu prób stan „failed".
     *
     * @param bool $ostateczny odpowiedź, której ponowienie nie zmieni (błędne dane)
     *
     * @return bool czy wpis właśnie przeszedł w stan „failed"
     */
    public function markFailed(int $id, string $blad, bool $ostateczny = false): bool
    {
        $proby = 1 + (int) Db::getInstance()->getValue(
            'SELECT attempts FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_quince_notifuse_outbox = ' . $id
        );
        $koniec = $ostateczny || $proby >= self::MAX_ATTEMPTS;

        Db::getInstance()->update(self::TABLE, [
            'status' => $koniec ? 'failed' : 'pending',
            'attempts' => $proby,
            'last_error' => pSQL(substr($blad, 0, 255)),
            'next_try_at' => time() + self::BACKOFF[min($proby - 1, count(self::BACKOFF) - 1)],
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_quince_notifuse_outbox = ' . $id);

        return $koniec;
    }

    /** Ponowienie od ręki: wpis wraca do kolejki z zerowym odstępem. */
    public function requeue(int $id): void
    {
        Db::getInstance()->update(self::TABLE, [
            'status' => 'pending',
            'next_try_at' => 0,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_quince_notifuse_outbox = ' . $id . ' AND status != "sent"');
    }

    /** @return array<int,array<string,mixed>> */
    public function due(int $limit = self::CRON_BATCH): array
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE status = "pending" AND next_try_at <= ' . time() . '
             ORDER BY id_quince_notifuse_outbox ASC
             LIMIT ' . (int) $limit
        ) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_quince_notifuse_outbox = ' . $id
        );

        return is_array($wiersz) && $wiersz !== [] ? $wiersz : null;
    }

    /**
     * Czy wpis o tej etykiecie dla adresu i sklepu powstał w ostatnich `$sekund`
     * (nieudane się nie liczą: trzeba spróbować jeszcze raz).
     */
    public function bylyNiedawno(int $idShop, string $label, string $email, int $sekund): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE email = "' . pSQL($email) . '" AND id_shop = ' . $idShop . ' AND label = "' . pSQL($label) . '"
               AND status <> "failed" AND date_add > DATE_SUB(NOW(), INTERVAL ' . $sekund . ' SECOND)'
        );
    }

    /** @return array{pending:int,sent:int,failed:int} */
    public function counts(int $idShop): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0];

        $wiersze = Db::getInstance()->executeS(
            'SELECT status, COUNT(*) AS ile FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE id_shop = ' . $idShop . ' GROUP BY status'
        ) ?: [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['status']] = (int) $wiersz['ile'];
        }

        return $out;
    }

    /**
     * @param array{label?:string,status?:string,email?:string,from?:string,to?:string} $filtry
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(int $idShop, array $filtry, int $strona): array
    {
        $offset = (max(1, $strona) - 1) * self::PER_PAGE;

        return Db::getInstance()->executeS(
            'SELECT id_quince_notifuse_outbox, kind, label, email, status, attempts, last_error, date_add
             FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE ' . $this->warunek($idShop, $filtry) . '
             ORDER BY id_quince_notifuse_outbox DESC
             LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset
        ) ?: [];
    }

    /** @param array{label?:string,status?:string,email?:string,from?:string,to?:string} $filtry */
    public function countSearch(int $idShop, array $filtry): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $this->warunek($idShop, $filtry)
        );
    }

    /** @return string[] rodzaje wpisów, które są w dzienniku sklepu */
    public function labels(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT label FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_shop = ' . $idShop . ' ORDER BY label'
        ) ?: [];

        return array_map(static fn (array $w): string => (string) $w['label'], $wiersze);
    }

    /**
     * Usunięcie starych wpisów, najwyżej raz na dobę. Wpisy niosą adresy e-mail,
     * więc nie mogą leżeć w bazie bez terminu.
     */
    public function purgeExpired(): void
    {
        if ((int) Configuration::getGlobalValue(self::LAST_PURGE) > time() - 86400) {
            return;
        }

        Configuration::updateGlobalValue(self::LAST_PURGE, (string) time());

        Db::getInstance()->delete(
            self::TABLE,
            'status = "sent" AND date_upd < "' . date('Y-m-d H:i:s', time() - self::KEEP_SENT_DAYS * 86400) . '"'
        );
        Db::getInstance()->delete(
            self::TABLE,
            'status IN ("failed", "pending") AND date_upd < "' . date('Y-m-d H:i:s', time() - self::KEEP_FAILED_DAYS * 86400) . '"'
        );
    }

    /** @param array{label?:string,status?:string,email?:string,from?:string,to?:string} $filtry */
    private function warunek(int $idShop, array $filtry): string
    {
        $warunki = ['id_shop = ' . $idShop];

        $etykieta = trim((string) ($filtry['label'] ?? ''));
        if ($etykieta !== '') {
            $warunki[] = 'label = "' . pSQL($etykieta) . '"';
        }

        $status = trim((string) ($filtry['status'] ?? ''));
        if (in_array($status, ['pending', 'sent', 'failed'], true)) {
            $warunki[] = 'status = "' . pSQL($status) . '"';
        }

        $email = trim((string) ($filtry['email'] ?? ''));
        if ($email !== '') {
            $warunki[] = 'email LIKE "%' . pSQL(addcslashes($email, '%_')) . '%"';
        }

        $od = trim((string) ($filtry['from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $od)) {
            $warunki[] = 'date_add >= "' . $od . ' 00:00:00"';
        }

        $do = trim((string) ($filtry['to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $do)) {
            $warunki[] = 'date_add <= "' . $do . ' 23:59:59"';
        }

        return implode(' AND ', $warunki);
    }
}
