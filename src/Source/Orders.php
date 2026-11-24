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

use Currency;
use Customer;
use Order;
use OrderCarrier;
use OrderState;
use Quince\Notifuse\Queue\Sender;
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Zamówienia ze sklepu. Zamówienia spoza sklepu zgłasza do Notifuse system
 * zewnętrzny, więc żadne nie idzie dwa razy.
 *
 * `order.placed` jest celem typu `purchase`: zatrzymuje automatyzację
 * porzuconego koszyka i zasila wartość zakupów kontaktu w Notifuse.
 * `order.shipped` idzie przy pierwszym statusie oznaczonym w sklepie jako wysłany.
 */
final class Orders
{
    public function __construct(
        private readonly NotifuseSettings $ustawienia = new NotifuseSettings(),
        private readonly Sender $wysylka = new Sender()
    ) {
    }

    public function zlozone(Order $zamowienie): void
    {
        $idShop = (int) $zamowienie->id_shop;

        if (!$this->ustawienia->polaczenieUstawione($idShop) || !$this->ustawienia->zdarzenieWlaczone('order.placed', $idShop)) {
            return;
        }

        $klient = new Customer((int) $zamowienie->id_customer);

        if (!$klient->email) {
            return;
        }

        $waluta = (string) (new Currency((int) $zamowienie->id_currency))->iso_code;
        $suma = round((float) $zamowienie->total_paid_tax_incl, 2);

        $this->wysylka->dodajIWyslij($idShop, 'event', 'order.placed', $klient->email, [
            'email' => $klient->email,
            'event_name' => 'order.placed',
            'external_id' => 'order-' . (int) $zamowienie->id,
            'occurred_at' => date(DATE_ATOM, strtotime((string) $zamowienie->date_add) ?: time()),
            'goal_type' => 'purchase',
            'goal_value' => $suma,
            'properties' => [
                'shop' => Account::sklep($idShop),
                'reference' => (string) $zamowienie->reference,
                'total' => $suma,
                'currency' => $waluta,
                'items' => $this->pozycje($zamowienie, $waluta),
            ],
        ]);
    }

    public function zmianaStatusu(int $idOrder, OrderState $status): void
    {
        if (!$status->shipped) {
            return;
        }

        $zamowienie = new Order($idOrder);
        $idShop = (int) $zamowienie->id_shop;

        if (!\Validate::isLoadedObject($zamowienie)
            || !$this->ustawienia->polaczenieUstawione($idShop)
            || !$this->ustawienia->zdarzenieWlaczone('order.shipped', $idShop)
            || $this->juzWyslane($idOrder)
        ) {
            return;
        }

        $klient = new Customer((int) $zamowienie->id_customer);

        if (!$klient->email) {
            return;
        }

        $przewoznik = new OrderCarrier((int) $zamowienie->getIdOrderCarrier());
        $numer = (string) ($przewoznik->tracking_number ?: $zamowienie->shipping_number);
        $firma = new \Carrier((int) $zamowienie->id_carrier);
        $sledzenie = ($numer !== '' && $firma->url) ? str_replace('@', $numer, (string) $firma->url) : '';

        $this->wysylka->dodajIWyslij($idShop, 'event', 'order.shipped', $klient->email, [
            'email' => $klient->email,
            'event_name' => 'order.shipped',
            'external_id' => 'order-' . $idOrder,
            'occurred_at' => date(DATE_ATOM),
            'properties' => array_filter([
                'shop' => Account::sklep($idShop),
                'reference' => (string) $zamowienie->reference,
                'carrier' => (string) $firma->name,
                'tracking_number' => $numer,
                'tracking_url' => $sledzenie,
            ], static fn ($v): bool => $v !== ''),
        ]);
    }

    /**
     * Zamówienie, które już było zgłoszone jako wysłane, nie idzie drugi raz (na
     * przykład przejście „Wysłane" → „Dostarczone", oba oznaczone jako wysłane).
     *
     * Sprawdzamy własną kolejkę, nie historię zamówienia: rdzeń zapisuje wiersz
     * historii raz przed hakiem, raz po nim, zależnie od miejsca zmiany statusu.
     */
    private function juzWyslane(int $idOrder): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . \Quince\Notifuse\Queue\Outbox::TABLE . '`
             WHERE kind = "event" AND label = "order.shipped"
               AND payload LIKE "%\"external_id\":\"order-' . $idOrder . '\"%"'
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function pozycje(Order $zamowienie, string $waluta): array
    {
        // `getProducts()` formatuje kwoty walutą z kontekstu. Poza kasą (import
        // zamówień, wiersz poleceń) kontekst bywa bez waluty: bierzemy walutę zamówienia.
        $kontekst = \Context::getContext();
        if (!$kontekst->currency) {
            $kontekst->currency = new Currency((int) $zamowienie->id_currency);
        }

        $dane = new ProductData((int) $zamowienie->id_shop, (int) $zamowienie->id_lang);
        $out = [];

        foreach ($zamowienie->getProducts() as $pozycja) {
            $produkt = $dane->produkt(
                (int) $pozycja['product_id'],
                (int) $pozycja['product_attribute_id'],
                (float) $pozycja['unit_price_tax_incl'],
                $waluta
            );

            $out[] = ($produkt ?? [
                'name' => (string) $pozycja['product_name'],
                'reference' => (string) $pozycja['product_reference'],
                'price' => round((float) $pozycja['unit_price_tax_incl'], 2),
                'currency' => $waluta,
            ]) + ['quantity' => (int) $pozycja['product_quantity']];
        }

        return $out;
    }
}
