<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Notifuse\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Engine\Controller\Admin\QuinceController;
use Quince\Notifuse\Queue\Outbox;
use Quince\Notifuse\Queue\Sender;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dziennik: co sklep wysłał do Notifuse, co czeka i co się nie udało.
 */
class LogController extends QuinceController
{
    private const DOMENA = SettingsController::DOMENA;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $idShop = (int) $this->getContext()->shop->id;
        $kolejka = new Outbox();

        $filtry = [
            'label' => (string) $request->query->get('label', ''),
            'status' => (string) $request->query->get('status', ''),
            'email' => (string) $request->query->get('email', ''),
            'from' => (string) $request->query->get('from', ''),
            'to' => (string) $request->query->get('to', ''),
        ];
        $strona = max(1, $request->query->getInt('page', 1));
        $wszystkich = $kolejka->countSearch($idShop, $filtry);

        return $this->renderPanel('@Modules/quince_notifuse/views/templates/admin/log.html.twig', [
            'wpisy' => $kolejka->search($idShop, $filtry, $strona),
            'licznik' => $kolejka->counts($idShop),
            'etykiety' => $kolejka->labels($idShop),
            'filtry' => $filtry,
            'strona' => $strona,
            'stron' => max(1, (int) ceil($wszystkich / Outbox::PER_PAGE)),
            'wszystkich' => $wszystkich,
            'layoutTitle' => 'Notifuse',
            'layoutTitleDomain' => self::DOMENA,
            'quinceRoute' => 'quince_notifuse_log',
            'quinceNavRoute' => 'quince_notifuse_settings',
            'quinceTabs' => SettingsController::zakladki(),
            'quinceTabsDomain' => self::DOMENA,
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function entryAction(int $id): Response
    {
        $wpis = (new Outbox())->get($id);

        if ($wpis === null || (int) $wpis['id_shop'] !== (int) $this->getContext()->shop->id) {
            $this->addFlash('error', $this->trans('This entry is no longer in the log.', self::DOMENA));

            return $this->redirectToRoute('quince_notifuse_log');
        }

        $tresc = json_decode((string) $wpis['payload'], true);

        return $this->renderPanel('@Modules/quince_notifuse/views/templates/admin/entry.html.twig', [
            'wpis' => $wpis,
            'tresc' => (string) json_encode($tresc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'maxProb' => Outbox::MAX_ATTEMPTS,
            'layoutTitle' => 'Notifuse',
            'layoutTitleDomain' => self::DOMENA,
            'quinceRoute' => 'quince_notifuse_log',
            'quinceNavRoute' => 'quince_notifuse_settings',
            'quinceTabs' => SettingsController::zakladki(),
            'quinceTabsDomain' => self::DOMENA,
        ]);
    }

    /** Ponowienie od ręki: jedna próba od razu, bez czekania na cron. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function retryAction(Request $request, int $id): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_notifuse_log_retry', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_notifuse_log_entry', ['id' => $id]);
        }

        $kolejka = new Outbox();
        $wpis = $kolejka->get($id);

        if ($wpis === null || (int) $wpis['id_shop'] !== (int) $this->getContext()->shop->id || $wpis['status'] === 'sent') {
            return $this->redirectToRoute('quince_notifuse_log');
        }

        $kolejka->requeue($id);
        $stan = (new Sender())->wyslij((array) $kolejka->get($id));

        $this->addFlash(
            $stan === 'sent' ? 'success' : 'error',
            $stan === 'sent'
                ? $this->trans('Sent.', self::DOMENA)
                : $this->trans('Notifuse did not accept it. The reason is below.', self::DOMENA)
        );

        return $this->redirectToRoute('quince_notifuse_log_entry', ['id' => $id]);
    }
}
