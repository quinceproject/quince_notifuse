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
use Mail;
use Quince\Notifuse\Settings\NotifuseSettings;
use Shop;

/**
 * Mail do sklepu, gdy wysyłanie do Notifuse przestało działać.
 *
 * Najwyżej jeden na dobę, na adres sklepu, niezależnie od liczby nieudanych wpisów.
 */
final class Alert
{
    private const ODSTEP_GODZIN = 24;
    private const SZABLON = 'quince_notifuse_failed';

    public static function wyslij(int $idShop, int $ile, string $ostatniBlad): bool
    {
        $ustawienia = new NotifuseSettings();

        if ($ile < 1 || $ustawienia->get('ALERT', $idShop) !== '1') {
            return false;
        }

        $klucz = NotifuseSettings::LAST_ALERT . '_' . $idShop;

        if ((int) Configuration::getGlobalValue($klucz) > time() - self::ODSTEP_GODZIN * 3600) {
            return false;
        }

        $adres = (string) Configuration::get('PS_SHOP_EMAIL', null, null, $idShop);

        if ($adres === '' || !\Validate::isEmail($adres)) {
            return false;
        }

        $sklep = new Shop($idShop);
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $temat = \Context::getContext()->getTranslator()->trans(
            'Sending to Notifuse is failing on %shop%',
            ['%shop%' => (string) $sklep->name],
            'Modules.Quincenotifuse.Admin',
            (string) (new \Language($idLang))->locale
        );

        $poszlo = Mail::Send(
            $idLang,
            self::SZABLON,
            $temat,
            [
                '{shop_name}' => (string) $sklep->name,
                '{count}' => (string) $ile,
                '{error}' => $ostatniBlad,
                '{endpoint}' => $ustawienia->get('URL', $idShop),
                '{attempts}' => (string) Outbox::MAX_ATTEMPTS,
            ],
            $adres,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'quince_notifuse/mails/',
            false,
            $idShop
        );

        if ($poszlo) {
            Configuration::updateGlobalValue($klucz, (string) time());
        }

        return (bool) $poszlo;
    }
}
