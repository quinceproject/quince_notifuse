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
use Db;
use Quince\Notifuse\Queue\Sender;
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Porzucone koszyki, wyszukiwane z crona.
 *
 * Koszyk klienta z produktami, bez zamówienia, nieruszany dłużej niż czas
 * z panelu, z zapisaną przy nim zgodą analityczną. Zmieniony po wysłaniu idzie
 * ponownie, z nowym stanem. Zamówienie (`order.placed`) zatrzymuje automatyzację
 * w Notifuse.
 *
 * Także koszyki gości, którzy podali adres w pierwszym kroku kasy. Kontakt
 * gościa istnieje tylko w Notifuse,
 * zakładamy go razem z koszykiem. O mailu do gościa bez zgody na newsletter
 * decyduje automatyzacja w Notifuse.
 */
final class AbandonedCarts
{
    /** Starszych koszyków nie wysyłamy: przypomnienie po tygodniu nic nie da. */
    private const MAX_DNI = 7;

    private const NA_RAZ = 50;

    public function __construct(
        private readonly NotifuseSettings $ustawienia = new NotifuseSettings(),
        private readonly Sender $wysylka = new Sender()
    ) {
    }

    /** @return int ile koszyków poszło */
    public function wyslij(): int
    {
        $wyslane = 0;

        foreach (\Shop::getShops(true, null, true) as $idShop) {
            $idShop = (int) $idShop;

            if (!$this->ustawienia->polaczenieUstawione($idShop) || !$this->ustawienia->zdarzenieWlaczone('cart.abandoned', $idShop)) {
                continue;
            }

            foreach ($this->koszyki($idShop) as $w) {
                $wyslane += $this->wyslijKoszyk((int) $w['id_cart'], (string) $w['email'], (bool) $w['is_guest']) ? 1 : 0;
            }
        }

        return $wyslane;
    }

    /** @return array<int,array{id_cart:string,email:string,is_guest:string}> */
    private function koszyki(int $idShop): array
    {
        $minuty = (int) $this->ustawienia->get('ABANDON_MINUTES', $idShop);

        return Db::getInstance()->executeS(
            'SELECT c.id_cart, cu.email, cu.is_guest
             FROM `' . _DB_PREFIX_ . 'cart` c
             JOIN `' . _DB_PREFIX_ . Behaviour::TABLE_CART . '` q ON q.id_cart = c.id_cart AND q.consent = 1
             JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.id_customer = c.id_customer AND cu.deleted = 0 AND cu.email <> ""
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_cart = c.id_cart
             WHERE c.id_shop = ' . $idShop . '
               AND o.id_order IS NULL
               AND c.date_upd < DATE_SUB(NOW(), INTERVAL ' . max(15, $minuty) . ' MINUTE)
               AND c.date_upd > DATE_SUB(NOW(), INTERVAL ' . self::MAX_DNI . ' DAY)
               AND (q.abandoned_sent_at IS NULL OR q.abandoned_sent_at < c.date_upd)
               AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . Consent::TABLE . '` qz
                    WHERE qz.id_customer = c.id_customer AND qz.id_shop = c.id_shop
                      AND qz.analytics = 0 AND qz.date_upd > q.date_upd)
               AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_product` cp WHERE cp.id_cart = c.id_cart)
             ORDER BY c.date_upd ASC
             LIMIT ' . self::NA_RAZ
        ) ?: [];
    }

    private function wyslijKoszyk(int $idCart, string $email, bool $gosc): bool
    {
        $koszyk = new Cart($idCart);
        $idShop = (int) $koszyk->id_shop;

        // Cron nie ma kontekstu klienta: kwoty i adresy liczymy dla sklepu i waluty koszyka.
        $kontekst = Context::getContext();
        $kontekst->shop = new \Shop($idShop);
        $kontekst->currency = new \Currency((int) $koszyk->id_currency);
        $kontekst->language = new \Language((int) $koszyk->id_lang);
        $kontekst->cart = $koszyk;

        $zawartosc = Behaviour::zawartosc($koszyk);

        if ($zawartosc['items'] === []) {
            return false;
        }

        // Gość nie ma jeszcze kontaktu. Zgoda na śledzenie maili wynika ze zgody
        // analitycznej zapisanej przy koszyku, a bez niej koszyk by tu nie trafił.
        if ($gosc) {
            $this->wysylka->dodajIWyslij($idShop, 'contact', 'guest', $email, [
                'contact' => Account::kontakt(new \Customer((int) $koszyk->id_customer), $idShop) + Consent::polaSledzenia(true),
            ]);
        }

        $this->wysylka->dodajIWyslij($idShop, 'event', 'cart.abandoned', $email, [
            'email' => $email,
            'event_name' => 'cart.abandoned',
            'external_id' => 'cart-' . $idCart,
            'occurred_at' => date(DATE_ATOM, strtotime((string) $koszyk->date_upd) ?: time()),
            'properties' => [
                'shop' => Account::sklep($idShop),
                'cart_url' => $kontekst->link->getPageLink('cart', null, (int) $koszyk->id_lang, ['action' => 'show'], false, $idShop),
            ] + $zawartosc,
        ]);

        Db::getInstance()->update(Behaviour::TABLE_CART, ['abandoned_sent_at' => date('Y-m-d H:i:s')], 'id_cart = ' . $idCart);

        return true;
    }
}
