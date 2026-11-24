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
use Quince\Notifuse\Queue\Outbox;
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Spadek ceny i powrót do sprzedaży.
 *
 * Odbiorcy: zalogowani klienci z produktem w ulubionych albo w otwartym
 * koszyku, z zapisaną zgodą analityczną. Zdarzenie wysyła sklep przy zmianie
 * ceny albo stanu, więc zgody bieżącego odwiedzającego nie ma kogo pytać:
 * bierzemy zapisaną (`Consent::sprawdzIZapisz`).
 *
 * Do kolejki trafia wpis na każdą osobę, a wysyłkę robi cron: zmiana ceny
 * w panelu nie może czekać na kilkaset wywołań API.
 */
final class ProductAlerts
{
    /** Koszyki starsze niż tyle dni nie są „otwarte". */
    private const KOSZYK_DNI = 30;

    public function __construct(
        private readonly NotifuseSettings $ustawienia = new NotifuseSettings(),
        private readonly Outbox $kolejka = new Outbox()
    ) {
    }

    public function spadekCeny(int $idProduct, int $idAttribute, int $idShop, float $stara, float $nowa): int
    {
        if ($nowa >= $stara || !$this->wolno('product.price_dropped', $idShop)) {
            return 0;
        }

        return $this->doOdbiorcow('product.price_dropped', $idProduct, $idAttribute, $idShop, [
            'old_price' => round($stara, 2),
            'new_price' => round($nowa, 2),
        ], false, $nowa);
    }

    /**
     * @param int $ilosc stan po zmianie
     * @param int $roznica o ile zmienił się stan
     */
    public function zmianaStanu(int $idProduct, int $idAttribute, int $idShop, int $ilosc, int $roznica): int
    {
        // Powrót to przejście z braku na stan dodatni, nie każde dołożenie towaru.
        if ($ilosc <= 0 || $ilosc - $roznica > 0 || !$this->wolno('product.back_in_stock', $idShop)) {
            return 0;
        }

        return $this->doOdbiorcow('product.back_in_stock', $idProduct, $idAttribute, $idShop, [], true);
    }

    private function wolno(string $zdarzenie, int $idShop): bool
    {
        return $this->ustawienia->polaczenieUstawione($idShop) && $this->ustawienia->zdarzenieWlaczone($zdarzenie, $idShop);
    }

    /**
     * @param array<string,mixed> $dodatki
     * @param bool $bezPowiadomienSklepu pomija zapisanych na powiadomienie `ps_emailalerts`
     */
    private function doOdbiorcow(string $zdarzenie, int $idProduct, int $idAttribute, int $idShop, array $dodatki, bool $bezPowiadomienSklepu, ?float $cena = null): int
    {
        $odbiorcy = $this->odbiorcy($idProduct, $idAttribute, $idShop, $bezPowiadomienSklepu);

        if ($odbiorcy === []) {
            return 0;
        }

        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $produkt = (new ProductData($idShop, $idLang))->produkt($idProduct, $idAttribute, $cena);

        if ($produkt === null) {
            return 0;
        }

        foreach ($odbiorcy as $o) {
            $this->kolejka->add($idShop, 'event', $zdarzenie, (string) $o['email'], [
                'email' => (string) $o['email'],
                'event_name' => $zdarzenie,
                // Jeden wpis na osobę, produkt i dzień: kilka zmian ceny tego samego dnia go nadpisuje.
                'external_id' => $zdarzenie . '-' . $idProduct . '-' . $idAttribute . '-' . (int) $o['id_customer'] . '-' . date('Ymd'),
                'occurred_at' => date(DATE_ATOM),
                'properties' => ['shop' => Account::sklep($idShop), 'product' => $produkt] + $dodatki,
            ]);
        }

        return count($odbiorcy);
    }

    /** @return array<int,array{id_customer:string,email:string}> */
    private function odbiorcy(int $idProduct, int $idAttribute, int $idShop, bool $bezPowiadomienSklepu): array
    {
        // Wariant: wpis na ten wariant albo na produkt bez wariantu.
        $wariant = '(%s.id_product_attribute = ' . $idAttribute . ' OR %s.id_product_attribute = 0)';
        $czesci = [];

        if (\Module::isEnabled('blockwishlist')) {
            $czesci[] = 'SELECT w.id_customer FROM `' . _DB_PREFIX_ . 'wishlist` w
                JOIN `' . _DB_PREFIX_ . 'wishlist_product` wp ON wp.id_wishlist = w.id_wishlist
                WHERE w.id_shop = ' . $idShop . ' AND wp.id_product = ' . $idProduct . '
                  AND ' . sprintf($wariant, 'wp', 'wp');
        }

        $czesci[] = 'SELECT c.id_customer FROM `' . _DB_PREFIX_ . 'cart` c
            JOIN `' . _DB_PREFIX_ . 'cart_product` cp ON cp.id_cart = c.id_cart
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_cart = c.id_cart
            WHERE c.id_shop = ' . $idShop . ' AND c.id_customer > 0 AND o.id_order IS NULL
              AND c.date_upd > DATE_SUB(NOW(), INTERVAL ' . self::KOSZYK_DNI . ' DAY)
              AND cp.id_product = ' . $idProduct . ' AND ' . sprintf($wariant, 'cp', 'cp');

        $bezAlertow = '';
        if ($bezPowiadomienSklepu && \Module::isEnabled('ps_emailalerts')) {
            $bezAlertow = ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'mailalert_customer_oos` ma
                WHERE ma.id_product = ' . $idProduct . ' AND ma.id_product_attribute = ' . $idAttribute . '
                  AND ma.id_shop = ' . $idShop . '
                  AND (ma.id_customer = cu.id_customer OR ma.customer_email = cu.email))';
        }

        return Db::getInstance()->executeS(
            'SELECT DISTINCT cu.id_customer, cu.email
             FROM (' . implode(' UNION ', $czesci) . ') z
             JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.id_customer = z.id_customer AND cu.is_guest = 0 AND cu.deleted = 0 AND cu.active = 1
             WHERE ' . Consent::warunekSql('cu', $idShop) . $bezAlertow
        ) ?: [];
    }
}
