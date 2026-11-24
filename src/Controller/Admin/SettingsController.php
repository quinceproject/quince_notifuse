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

use Context;
use ImageType;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Engine\Controller\Admin\QuinceController;
use Quince\Notifuse\Api\NotifuseClient;
use Quince\Notifuse\Api\NotifuseException;
use Quince\Notifuse\Queue\Sender;
use Quince\Notifuse\Settings\NotifuseSettings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran ustawień: połączenie, newsletter, odwiedziny, zdarzenia, błędy wysyłki.
 *
 * Stan połączenia sprawdzamy przy każdym otwarciu ekranu, pobierając listy
 * z Notifuse: te same listy wypełniają wybór list dla języków, więc ekran
 * i tak musi o nie zapytać.
 */
class SettingsController extends QuinceController
{
    public const DOMENA = 'Modules.Quincenotifuse.Admin';

    /** @return array<int,array<string,string>> */
    public static function zakladki(): array
    {
        return [
            ['label' => 'Settings', 'route' => 'quince_notifuse_settings'],
            ['label' => 'Log', 'route' => 'quince_notifuse_log'],
        ];
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        $idShop = (int) $this->getContext()->shop->id;
        $ustawienia = new NotifuseSettings();
        $u = $ustawienia->all($idShop);

        [$listy, $bladPolaczenia] = $this->listyZNotifuse($ustawienia, $idShop);
        $przypisane = $ustawienia->listy($idShop);

        return $this->renderPanel('@Modules/quince_notifuse/views/templates/admin/settings.html.twig', [
            'u' => $u,
            'maKlucz' => $u['API_KEY'] !== '',
            'maSekret' => $u['SECRET'] !== '',
            'polaczenieUstawione' => $ustawienia->polaczenieUstawione($idShop),
            'listy' => $listy,
            'bladPolaczenia' => $bladPolaczenia,
            'jezyki' => $this->languages(),
            'przypisane' => $przypisane,
            'ostrzezenia' => $this->ostrzezenia($listy, $przypisane),
            // Prośba o potwierdzenie z modułu newslettera PrestaShopa: przy liście
            // z potwierdzeniem w Notifuse klient dostałby dwa maile.
            'potwierdzenieSklepu' => \Module::isEnabled('ps_emailsubscription')
                && (bool) \Configuration::get('NW_VERIFICATION_EMAIL', null, null, $idShop),
            'zdarzenia' => $ustawienia->zdarzenia($idShop),
            'typyZdjec' => $this->typyZdjec(),
            'polaJson' => NotifuseSettings::JSON_FIELDS,
            'cronUrl' => Context::getContext()->link->getModuleLink(
                'quince_notifuse',
                'cron',
                ['token' => $ustawienia->cronToken()],
                true,
                null,
                $idShop
            ),
            'layoutTitle' => 'Notifuse',
            'layoutTitleDomain' => self::DOMENA,
            'quinceRoute' => 'quince_notifuse_settings',
            'quinceTabs' => self::zakladki(),
            'quinceTabsDomain' => self::DOMENA,
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_notifuse_save', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_notifuse_settings');
        }

        $ustawienia = new NotifuseSettings();
        $ustawienia->save($request->request->all());

        if ($request->request->getBoolean('SECRET_CLEAR')) {
            $ustawienia->usunSekret();
        }

        $this->addFlash('success', $this->trans('Settings saved.', self::DOMENA));

        return $this->redirectToRoute('quince_notifuse_settings');
    }

    /**
     * Zdarzenie testowe na adres zalogowanego pracownika. Idzie przez kolejkę,
     * więc widać je w dzienniku razem z odpowiedzią Notifuse.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function testEventAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_notifuse_test_event', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_notifuse_settings');
        }

        $idShop = (int) $this->getContext()->shop->id;
        $email = (string) $this->getContext()->employee->email;

        $id = (new Sender())->dodajIWyslij($idShop, 'event', 'quince.test', $email, [
            'email' => $email,
            'event_name' => 'quince.test',
            'external_id' => 'test-' . bin2hex(random_bytes(6)),
            'occurred_at' => date(DATE_ATOM),
            'properties' => ['source' => 'Quince Notifuse', 'shop' => (string) $this->getContext()->shop->domain],
        ]);

        return $this->redirectToRoute('quince_notifuse_log_entry', ['id' => $id]);
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function listyZNotifuse(NotifuseSettings $ustawienia, int $idShop): array
    {
        if (!$ustawienia->polaczenieUstawione($idShop)) {
            return [[], ''];
        }

        $u = $ustawienia->all($idShop);

        try {
            return [(new NotifuseClient($u['URL'], $u['WORKSPACE'], $u['API_KEY']))->listy(), ''];
        } catch (NotifuseException $e) {
            return [[], $e->getMessage()];
        }
    }

    /**
     * Listy przypisane do języków, które nie poproszą klienta o potwierdzenie
     * zapisu albo nie przyjmą zapisu z formularza sklepu.
     *
     * @param array<int,array<string,mixed>> $listy
     * @param array<int,string> $przypisane
     *
     * @return array<int,array{name:string,problem:string}>
     */
    private function ostrzezenia(array $listy, array $przypisane): array
    {
        if ($listy === []) {
            return [];
        }

        $poId = [];
        foreach ($listy as $lista) {
            $poId[$lista['id']] = $lista;
        }

        $out = [];
        foreach (array_unique($przypisane) as $idListy) {
            if (!isset($poId[$idListy])) {
                $out[] = ['name' => $idListy, 'problem' => 'missing'];
            } elseif (!$poId[$idListy]['is_double_optin'] || !$poId[$idListy]['is_public']) {
                $out[] = ['name' => (string) $poId[$idListy]['name'], 'problem' => 'optin'];
            }
        }

        return $out;
    }

    /** @return array<int,array{name:string,width:int,height:int}> */
    private function typyZdjec(): array
    {
        return array_map(
            static fn (array $t): array => ['name' => (string) $t['name'], 'width' => (int) $t['width'], 'height' => (int) $t['height']],
            ImageType::getImagesTypes('products')
        );
    }
}
