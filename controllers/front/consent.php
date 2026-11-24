<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Notifuse\Source\Consent;

/**
 * Zalogowany klient zmienił zgodę analityczną w banerze.
 *
 * Moduł zgód nie zgłasza zmian po stronie serwera, nadaje tylko zdarzenie
 * `quince:consent` w przeglądarce. Skrypt modułu wywołuje ten adres, gdy zgoda
 * na stronie różni się od zapisanej. Adres nie przyjmuje żadnej wartości:
 * zgodę odczytuje z modułu zgód tak samo jak przy każdym innym zdarzeniu,
 * więc nikt nie ustawi klientowi zgody z zewnątrz.
 */
class Quince_NotifuseConsentModuleFrontController extends ModuleFrontController
{
    /** @var bool odpowiedź to JSON, nie strona sklepu */
    public $ajax = true;

    public function initContent(): void
    {
        $klient = $this->context->customer;

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||!$klient || !$klient->isLogged() || $klient->is_guest) {
            $this->odpowiedz(['ok' => false], 403);
        }

        $zgoda = Consent::sprawdzIZapisz((int) $klient->id, (int) $this->context->shop->id);

        $this->odpowiedz(['ok' => true, 'analytics' => $zgoda]);
    }

    /** @param array<string,mixed> $dane */
    private function odpowiedz(array $dane, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        echo json_encode($dane);
        exit;
    }
}
