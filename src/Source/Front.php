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
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Skrypt analityki Notifuse (`na.js`) w nagłówku strony.
 *
 * Znaczniki wychodzą od razu wstrzymane, w umowie modułu zgód (Quince CMP,
 * API.md, sekcja 4): `type="text/plain"` i `data-qc-code="cookies_analytics"`.
 * Moduł zgód uruchamia je po zgodzie analitycznej; bez niego nie uruchomią się
 * wcale, więc domyślnie nie śledzimy. Moduł niczego nie blokuje sam.
 *
 * Osobny, zawsze działający skrypt sprząta po cofnięciu zgody: `na.js` nie
 * obsługuje zgód i zostawiłby identyfikator w pamięci przeglądarki.
 */
final class Front
{
    public const ZGODA = 'cookies_analytics';

    public function __construct(private readonly NotifuseSettings $ustawienia = new NotifuseSettings())
    {
    }

    public function naglowek(): string
    {
        $kontekst = Context::getContext();
        $idShop = (int) $kontekst->shop->id;
        $u = $this->ustawienia->all($idShop);

        if (!$this->ustawienia->polaczenieUstawione($idShop)) {
            return '';
        }

        $klient = $kontekst->customer;
        $zalogowany = $klient && $klient->isLogged() && !$klient->is_guest && $klient->email;

        // Zmiana zgody działa także bez analityki stron: od niej zależy
        // śledzenie otwarć i kliknięć maili.
        $zgoda = $zalogowany ? $this->zmianaZgody($kontekst, (int) $klient->id, $idShop) : '';

        if ($u['TRACKING'] !== '1') {
            return $zgoda;
        }

        $zdarzenia = $this->ustawienia->zdarzenia($idShop);

        $dane = ['logged' => (bool) $zalogowany];

        // Podpis liczony tutaj, kluczem, który nie wychodzi do przeglądarki
        // (Notifuse: „Identifying Visitors”). Podpisujemy adres dokładnie w tej
        // postaci, w jakiej trafi do `identify()`.
        if ($zalogowany && $u['SECRET'] !== '') {
            $dane['email'] = (string) $klient->email;
            $dane['hmac'] = hash_hmac('sha256', 'wa_identify:' . $klient->email, $u['SECRET']);
        }

        // Cele z przeglądarki tylko dla niezalogowanych (klient z linku w mailu).
        // Zachowania zalogowanego wysyła serwer, cel z przeglądarki byłby drugim
        // wpisem tego samego.
        if (!$zalogowany) {
            $dane['cart_goal'] = $zdarzenia['cart.updated'] ?? false;

            if (($zdarzenia['product.viewed'] ?? false) && $kontekst->controller instanceof \ProductController) {
                $dane['product'] = $this->produkt($kontekst, $idShop);
            }
        }

        $flagi = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $konfiguracja = ['workspace_id' => $u['WORKSPACE'], 'endpoint' => $u['URL']];
        $wstrzymany = 'type="text/plain" data-qc-code="' . self::ZGODA . '" data-qc-type="text/javascript"';
        $skrypt = $kontekst->link->getBaseLink() . 'modules/quince_notifuse/views/js/notifuse.js?v=' . \Module::getInstanceByName('quince_notifuse')->version;

        return $zgoda . '<script>' . $this->sprzatanie() . '</script>'
            . '<script ' . $wstrzymany . '>window.NotifuseAnalyticsConfig=' . json_encode($konfiguracja, $flagi)
            . ';window.QuinceNotifuse=' . json_encode($dane, $flagi) . ';</script>'
            . '<script ' . $wstrzymany . ' async src="' . htmlspecialchars(rtrim($u['URL'], '/') . '/na.js', ENT_QUOTES) . '"></script>'
            . '<script ' . $wstrzymany . ' src="' . htmlspecialchars($skrypt, ENT_QUOTES) . '"></script>';
    }

    /**
     * Zgłoszenie zmiany zgody zalogowanego klienta na serwer (kontroler
     * `consent`). Strona zna zgodę zapisaną w module, więc zapytanie idzie
     * tylko wtedy, gdy zgoda z banera jest inna, a nie przy każdej stronie.
     */
    private function zmianaZgody(Context $kontekst, int $idCustomer, int $idShop): string
    {
        $zapisana = Consent::zapisana($idCustomer, $idShop);
        $adres = $kontekst->link->getModuleLink('quince_notifuse', 'consent', [], null, null, $idShop);

        return '<script>(function(){var z=' . ($zapisana === null ? 'null' : ($zapisana ? 'true' : 'false')) . ';'
            . "document.addEventListener('quince:consent',function(e){"
            . "var t=((e.detail&&e.detail.codes)||[]).indexOf('" . self::ZGODA . "')!==-1;if(t===z)return;z=t;"
            . 'fetch(' . json_encode($adres, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ",{method:'POST',credentials:'same-origin'}).catch(function(){});"
            . '});})();</script>';
    }

    /**
     * Sprzątanie po braku albo cofnięciu zgody. Moduł zgód nadaje
     * `quince:consent` na starcie i po każdej zmianie (API.md, sekcja 2).
     */
    private function sprzatanie(): string
    {
        return "document.addEventListener('quince:consent',function(e){"
            . "var k=(e.detail&&e.detail.codes)||[];if(k.indexOf('" . self::ZGODA . "')!==-1)return;"
            . "try{if(window.NotifuseAnalytics&&window.NotifuseAnalytics.pause)window.NotifuseAnalytics.pause();}catch(x){}"
            . "[window.localStorage,window.sessionStorage].forEach(function(s){try{Object.keys(s).forEach(function(n){"
            . "if(n.indexOf('nf_')===0||n==='q_nf_identity')s.removeItem(n);});}catch(x){}});});";
    }

    /**
     * Produkt do celu `product.viewed`. Notifuse przyjmuje we właściwościach
     * celu tylko proste wartości (zapisuje je jako tekst), więc bez list.
     *
     * @return array<string,string>
     */
    private function produkt(Context $kontekst, int $idShop): array
    {
        $idProduct = (int) \Tools::getValue('id_product');
        $idAttribute = (int) \Tools::getValue('id_product_attribute');
        $produkt = (new ProductData($idShop, (int) $kontekst->language->id))->produkt($idProduct, $idAttribute) ?? [];

        return array_map('strval', array_filter($produkt, static fn ($v): bool => is_scalar($v)));
    }
}
