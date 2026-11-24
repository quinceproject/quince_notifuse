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

use Context;
use Image;
use Link;
use Product;
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Produkt w zdarzeniu, gotowy do pokazania w mailu.
 *
 * Notifuse nie ma katalogu produktów, więc każde zdarzenie niesie to, czego
 * szablon maila potrzebuje: nazwę, indeks, wariant, cenę z walutą, adres
 * i zdjęcie w rozmiarze wybranym w panelu.
 */
final class ProductData
{
    /** @var array<string,array<string,mixed>> */
    private array $pamiec = [];

    public function __construct(
        private readonly int $idShop,
        private readonly int $idLang,
        private readonly NotifuseSettings $ustawienia = new NotifuseSettings()
    ) {
    }

    /**
     * @return array<string,mixed>|null null, gdy produktu nie ma albo jest wyłączony
     */
    public function produkt(int $idProduct, int $idAttribute = 0, ?float $cena = null, ?string $waluta = null): ?array
    {
        $klucz = $idProduct . '-' . $idAttribute;

        if (!isset($this->pamiec[$klucz])) {
            $produkt = new Product($idProduct, false, $this->idLang, $this->idShop);

            if (!\Validate::isLoadedObject($produkt) || !$produkt->active) {
                return null;
            }

            $link = Context::getContext()->link ?? new Link();
            $this->pamiec[$klucz] = array_filter([
                'id' => $idProduct,
                'id_attribute' => $idAttribute ?: null,
                'name' => (string) $produkt->name,
                'reference' => $this->indeks($produkt, $idAttribute),
                'variant' => $idAttribute ? $this->wariant($idProduct, $idAttribute) : null,
                'url' => $link->getProductLink($produkt, null, null, null, $this->idLang, $this->idShop, $idAttribute),
                'image' => $this->zdjecie($produkt, $idAttribute, $link),
            ], static fn ($v): bool => $v !== null && $v !== '');
        }

        $dane = $this->pamiec[$klucz];
        $dane['price'] = round($cena ?? $this->cenaSklepu($idProduct, $idAttribute), 2);
        $dane['currency'] = $waluta ?? $this->walutaSklepu();

        return $dane;
    }

    /**
     * Cena oferty sklepu, brutto, z obniżkami, bez klienta i bez koszyka.
     *
     * Nie `getPriceStatic()`: ta bierze klienta, koszyk i walutę z kontekstu,
     * a bez koszyka i pracownika (cron, zapis cen nocą przez Omnibus) rzuca
     * wyjątkiem. Liczymy tak jak Quince Omnibus: sklep, jego waluta, kraj
     * i domyślna grupa klientów.
     */
    private function cenaSklepu(int $idProduct, int $idAttribute): float
    {
        $cenaSzczegolna = null;

        $wartosc = Product::priceCalculation(
            $this->idShop,
            $idProduct,
            $idAttribute ?: null,
            (int) \Configuration::get('PS_COUNTRY_DEFAULT', null, null, $this->idShop),
            0,
            '',
            (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $this->idShop),
            (int) \Configuration::get('PS_CUSTOMER_GROUP', null, null, $this->idShop),
            1,
            true,
            2,
            false,
            true,
            true,
            $cenaSzczegolna,
            true,
            0,
            false,
            0,
            0,
            0
        );

        return is_numeric($wartosc) ? (float) $wartosc : 0.0;
    }

    private function walutaSklepu(): string
    {
        return (string) (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $this->idShop)))->iso_code;
    }

    private function indeks(Product $produkt, int $idAttribute): string
    {
        if ($idAttribute) {
            $wariant = (string) \Db::getInstance()->getValue(
                'SELECT reference FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . $idAttribute
            );

            if ($wariant !== '') {
                return $wariant;
            }
        }

        return (string) $produkt->reference;
    }

    /**
     * Nazwa wariantu prosto z bazy. Nie `getAttributeCombinationsById()`: liczy
     * przy okazji ceny i bez waluty w kontekście (import stanów, cron) rzuca
     * wyjątkiem, a zdarzenie przepadało.
     */
    private function wariant(int $idProduct, int $idAttribute): string
    {
        $wiersze = \Db::getInstance()->executeS(
            'SELECT agl.name AS grupa, al.name AS wartosc
             FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
             JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = pac.id_attribute
             JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $this->idLang . '
             JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . $this->idLang . '
             WHERE pac.id_product_attribute = ' . $idAttribute . '
             ORDER BY a.id_attribute_group'
        ) ?: [];

        return implode(', ', array_map(static fn (array $w): string => $w['grupa'] . ': ' . $w['wartosc'], $wiersze));
    }

    private function zdjecie(Product $produkt, int $idAttribute, Link $link): ?string
    {
        $idImage = 0;

        if ($idAttribute) {
            $zdjecia = Image::getBestImageAttribute($this->idShop, $this->idLang, (int) $produkt->id, $idAttribute);
            $idImage = (int) ($zdjecia['id_image'] ?? 0);
        }

        if (!$idImage) {
            $okladka = Product::getCover((int) $produkt->id);
            $idImage = (int) ($okladka['id_image'] ?? 0);
        }

        if (!$idImage) {
            return null;
        }

        $adres = $link->getImageLink(
            is_array($produkt->link_rewrite) ? (string) reset($produkt->link_rewrite) : (string) $produkt->link_rewrite,
            (string) $idImage,
            $this->ustawienia->get('IMAGE_TYPE', $this->idShop)
        );

        // Mail otwiera się poza sklepem: adres musi być pełny, z protokołem.
        return str_starts_with($adres, 'http') ? $adres : 'https://' . ltrim($adres, '/');
    }
}
