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

use Quince\Notifuse\Queue\Sender;
use Quince\Notifuse\Source\AbandonedCarts;
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Wysyłka zaległych wpisów do Notifuse, wywoływana z crona serwera.
 *
 * PrestaShop nie ma własnego zegara. Ten adres ponawia wpisy, których Notifuse
 * nie przyjął od razu. Chroni go losowy token z konfiguracji.
 */
class Quince_NotifuseCronModuleFrontController extends ModuleFrontController
{
    /** @var bool odpowiedź to JSON, nie strona sklepu */
    public $ajax = true;

    public function initContent(): void
    {
        $podany = (string) Tools::getValue('token');

        // hash_equals: porównanie w stałym czasie, czas odpowiedzi nie zdradza tokena.
        if ($podany === '' || !hash_equals((new NotifuseSettings())->cronToken(), $podany)) {
            $this->odpowiedz(['error' => 'forbidden'], 403);
        }

        $porzucone = (new AbandonedCarts())->wyslij();

        $this->odpowiedz((new Sender())->wyslijZalegle() + ['abandoned_carts' => $porzucone]);
    }

    /** @param array<string,mixed> $dane */
    private function odpowiedz(array $dane, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        echo json_encode($dane);
        exit;
    }
}
