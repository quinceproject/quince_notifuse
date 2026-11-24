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

use Cart;
use Context;
use Customer;
use Db;
use Quince\Notifuse\Queue\Sender;
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Zachowania zalogowanego klienta, wysyłane z serwera kluczem API:
 * obejrzany produkt, koszyk, ulubione
 * i lista ostatnio oglądanych na kontakcie. Zawsze po zgodzie analitycznej.
 *
 * Klientów z linku w mailu, którzy nie są zalogowani, sklep nie zna: ich
 * zachowania zbiera skrypt Notifuse w przeglądarce.
 */
final class Behaviour
{
    /** Oglądane produkty zalogowanych klientów: źródło listy „ostatnio oglądane". */
    public const TABLE = 'quince_notifuse_viewed';

    /** Zgoda na koszyku: porzucony koszyk wysyła cron, który zgody nie odczyta. */
    public const TABLE_CART = 'quince_notifuse_cart';

    public function __construct(
        private readonly NotifuseSettings $ustawienia = new NotifuseSettings(),
        private readonly Sender $wysylka = new Sender()
    ) {
    }

    public static function installTables(): bool
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_customer` INT(11) UNSIGNED NOT NULL,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_product` INT(11) UNSIGNED NOT NULL,
                `viewed_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_customer`, `id_shop`, `id_product`),
                INDEX `idx_ostatnie` (`id_customer`, `id_shop`, `viewed_at`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        ) && (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_CART . '` (
                `id_cart` INT(11) UNSIGNED NOT NULL,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `consent` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `abandoned_sent_at` DATETIME NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_cart`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function dropTables(): bool
    {
        return (bool) Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`, `' . _DB_PREFIX_ . self::TABLE_CART . '`'
        );
    }

    /** Karta produktu otwarta przez zalogowanego klienta. */
    public function obejrzany(int $idProduct, int $idAttribute): void
    {
        $kontekst = Context::getContext();
        $idShop = (int) $kontekst->shop->id;
        $klient = $kontekst->customer;

        if (!$this->mozna($klient, $idShop)) {
            return;
        }

        $this->zapamietaj((int) $klient->id, $idShop, [$idProduct]);

        if ($this->ustawienia->zdarzenieWlaczone('product.viewed', $idShop)) {
            $produkt = (new ProductData($idShop, (int) $kontekst->language->id))->produkt($idProduct, $idAttribute);

            if ($produkt !== null) {
                $this->wysylka->dodajIWyslij($idShop, 'event', 'product.viewed', $klient->email, [
                    'email' => $klient->email,
                    'event_name' => 'product.viewed',
                    // Jeden wpis na produkt i dzień: kolejne odsłony tego dnia go odświeżają.
                    'external_id' => 'view-' . (int) $klient->id . '-' . $idProduct . '-' . date('Ymd'),
                    'occurred_at' => date(DATE_ATOM),
                    'properties' => ['shop' => Account::sklep($idShop), 'product' => $produkt],
                ]);
            }
        }

        $this->wyslijOstatnio($klient, $idShop);
    }

    /**
     * Po zalogowaniu albo rejestracji: produkty obejrzane wcześniej w tej
     * przeglądarce (ciasteczko `ps_viewedproduct`) dochodzą do listy klienta.
     */
    public function poZalogowaniu(Customer $klient): void
    {
        $kontekst = Context::getContext();
        $idShop = (int) $kontekst->shop->id;

        // Bez warunku „zalogowany": przy rejestracji hak przychodzi przed
        // zalogowaniem, a klienta i tak podaje sam rdzeń.
        if (!$klient->id || !$klient->email || $klient->is_guest
            || !$this->ustawienia->polaczenieUstawione($idShop) || !Consent::sprawdzIZapisz((int) $klient->id, $idShop)
        ) {
            return;
        }

        $obejrzane = array_values(array_filter(array_map('intval', explode(',', (string) ($kontekst->cookie->viewed ?? '')))));

        if ($obejrzane === []) {
            return;
        }

        $this->zapamietaj((int) $klient->id, $idShop, $obejrzane);
        $this->wyslijOstatnio($klient, $idShop);
    }

    /** @var array<int,true> koszyki zgłoszone w tym żądaniu */
    private static array $koszykiDoWyslania = [];

    /**
     * Zmiana koszyka. Rdzeń zapisuje koszyk kilka razy w jednym żądaniu (samo
     * dodanie produktu to trzy zapisy), więc zdarzenie idzie raz, na końcu
     * żądania, ze stanem koszyka po wszystkich zmianach.
     */
    public function zmianaKoszyka(Cart $koszyk): void
    {
        $idCart = (int) $koszyk->id;

        if (isset(self::$koszykiDoWyslania[$idCart])) {
            return;
        }

        self::$koszykiDoWyslania[$idCart] = true;

        register_shutdown_function(function () use ($idCart): void {
            try {
                $this->koszyk(new Cart($idCart));
            } catch (\Throwable $e) {
                \PrestaShopLogger::addLog('[quince_notifuse] ' . $e->getMessage(), 3, null, 'Module');
            }
        });
    }

    /**
     * Stan koszyka zalogowanego klienta. Jedno zdarzenie na koszyk, nadpisywane
     * przy każdej zmianie: Notifuse trzyma ostatni stan.
     */
    public function koszyk(Cart $koszyk): void
    {
        $kontekst = Context::getContext();
        $idShop = (int) $koszyk->id_shop;
        $zgoda = $this->ustawienia->polaczenieUstawione($idShop) && Consent::sprawdzIZapisz((int) $koszyk->id_customer, $idShop);

        // Zgoda zapisana przy koszyku: porzucony koszyk wyśle cron, który jej nie odczyta.
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE_CART . '` (id_cart, id_shop, consent, date_upd)
             VALUES (' . (int) $koszyk->id . ', ' . $idShop . ', ' . ($zgoda ? 1 : 0) . ', NOW())
             ON DUPLICATE KEY UPDATE consent = VALUES(consent), date_upd = NOW()'
        );

        $klient = $kontekst->customer;

        if (!$zgoda || !$klient || !$klient->isLogged() || !$klient->email
            || !$this->ustawienia->zdarzenieWlaczone('cart.updated', $idShop)
        ) {
            return;
        }

        $this->wysylka->dodajIWyslij($idShop, 'event', 'cart.updated', $klient->email, [
            'email' => $klient->email,
            'event_name' => 'cart.updated',
            'external_id' => 'cart-' . (int) $koszyk->id,
            'occurred_at' => date(DATE_ATOM),
            'properties' => ['shop' => Account::sklep($idShop)] + self::zawartosc($koszyk),
        ]);
    }

    /** Produkt dodany do ulubionych (`blockwishlist`). */
    public function ulubiony(int $idProduct, int $idAttribute, int $idCustomer): void
    {
        $kontekst = Context::getContext();
        $idShop = (int) $kontekst->shop->id;
        $klient = $kontekst->customer;

        if (!$this->mozna($klient, $idShop) || (int) $klient->id !== $idCustomer
            || !$this->ustawienia->zdarzenieWlaczone('wishlist.added', $idShop)
        ) {
            return;
        }

        $produkt = (new ProductData($idShop, (int) $kontekst->language->id))->produkt($idProduct, $idAttribute);

        if ($produkt === null) {
            return;
        }

        $this->wysylka->dodajIWyslij($idShop, 'event', 'wishlist.added', $klient->email, [
            'email' => $klient->email,
            'event_name' => 'wishlist.added',
            'external_id' => 'wishlist-' . $idCustomer . '-' . $idProduct . '-' . $idAttribute,
            'occurred_at' => date(DATE_ATOM),
            'properties' => ['shop' => Account::sklep($idShop), 'product' => $produkt],
        ]);
    }

    /**
     * Pozycje koszyka do zdarzenia: wspólne dla zmiany koszyka i porzuconego koszyka.
     *
     * @return array{currency:string,total:float,items:array<int,array<string,mixed>>}
     */
    public static function zawartosc(Cart $koszyk): array
    {
        $waluta = (string) (new \Currency((int) $koszyk->id_currency))->iso_code;
        $dane = new ProductData((int) $koszyk->id_shop, (int) $koszyk->id_lang);
        $pozycje = [];

        foreach ($koszyk->getProducts() as $p) {
            $produkt = $dane->produkt((int) $p['id_product'], (int) $p['id_product_attribute'], (float) $p['price_wt'], $waluta);

            if ($produkt !== null) {
                $pozycje[] = $produkt + ['quantity' => (int) $p['cart_quantity']];
            }
        }

        return [
            'currency' => $waluta,
            'total' => round((float) $koszyk->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), 2),
            'items' => $pozycje,
        ];
    }

    private function mozna(?Customer $klient, int $idShop): bool
    {
        return $klient !== null && $klient->isLogged() && $klient->email
            && $this->ustawienia->polaczenieUstawione($idShop)
            && Consent::sprawdzIZapisz((int) $klient->id, $idShop);
    }

    /** @param int[] $produkty */
    private function zapamietaj(int $idCustomer, int $idShop, array $produkty): void
    {
        foreach (array_unique($produkty) as $i => $idProduct) {
            // Kolejność z ciasteczka: wcześniejsze pozycje są starsze, więc dostają starszy czas.
            Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (id_customer, id_shop, id_product, viewed_at)
                 VALUES (' . $idCustomer . ', ' . $idShop . ', ' . (int) $idProduct . ', "' . date('Y-m-d H:i:s', time() - (count($produkty) - $i)) . '")
                 ON DUPLICATE KEY UPDATE viewed_at = GREATEST(viewed_at, VALUES(viewed_at))'
            );
        }
    }

    /** Lista ostatnio oglądanych w polu kontaktu, gdy włączona w panelu. */
    private function wyslijOstatnio(Customer $klient, int $idShop): void
    {
        if ($this->ustawienia->get('RECENT', $idShop) !== '1') {
            return;
        }

        $ile = (int) $this->ustawienia->get('RECENT_COUNT', $idShop);
        $wiersze = Db::getInstance()->executeS(
            'SELECT id_product FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE id_customer = ' . (int) $klient->id . ' AND id_shop = ' . $idShop . '
             ORDER BY viewed_at DESC LIMIT ' . max(1, $ile)
        ) ?: [];

        $dane = new ProductData($idShop, (int) Context::getContext()->language->id);
        $lista = [];

        foreach ($wiersze as $w) {
            $produkt = $dane->produkt((int) $w['id_product']);

            if ($produkt !== null) {
                $lista[] = $produkt;
            }
        }

        $this->wysylka->dodajIWyslij($idShop, 'contact', 'recently_viewed', $klient->email, [
            'contact' => [
                'email' => $klient->email,
                $this->ustawienia->get('RECENT_FIELD', $idShop) => ['products' => $lista],
            ],
        ]);
    }
}
