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

require_once __DIR__ . '/vendor/autoload.php';

use Quince\Notifuse\Queue\Outbox;
use Quince\Notifuse\Source\Account;
use Quince\Notifuse\Source\Behaviour;
use Quince\Notifuse\Source\Consent;
use Quince\Notifuse\Source\Front;
use Quince\Notifuse\Source\ProductAlerts;
use Quince\Notifuse\Source\Orders;
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Quince Notifuse: sklep przekazuje do Notifuse kontakty, zapisy na newsletter
 * i zdarzenia.
 *
 * Moduł niczego nie wysyła klientom i nie decyduje o wysyłce. Przekazuje dane,
 * a o tym, czy klient dostanie maila, decydują automatyzacje w Notifuse.
 *
 * Wszystko, co idzie do Notifuse, najpierw trafia do kolejki w bazie
 * (`Outbox`), dopiero potem jest wysyłane. Awaria Notifuse nie może zatrzymać
 * kasy ani rejestracji, a nie wysłane wpisy ponawia cron.
 */
class Quince_Notifuse extends Module
{
    public function __construct()
    {
        $this->name = 'quince_notifuse';
        $this->tab = 'emailing';
        $this->version = '0.1.0';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->dependencies = ['quince_engine'];

        // Jeden wpis w gałęzi „Quince project", prowadzący na ekran modułu.
        // Do tej zakładki odnoszą się uprawnienia pracownika (`#[AdminSecurity]`).
        $this->tabs = [
            [
                'class_name' => 'AdminQuinceNotifuse',
                'visible' => true,
                'name' => 'Notifuse',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_notifuse_settings',
                'wording' => 'Notifuse',
                'wording_domain' => 'Modules.Quincenotifuse.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince Notifuse', [], 'Modules.Quincenotifuse.Admin');
        $this->description = $this->trans(
            'Passes contacts, newsletter sign-ups and shop events to Notifuse.',
            [],
            'Modules.Quincenotifuse.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstalling removes the settings and the log. Contacts already in Notifuse stay there.',
            [],
            'Modules.Quincenotifuse.Admin'
        );
    }

    /** Hak zgłaszany przez Quince Omnibus po zapisie ceny niższej od poprzedniej. */
    public const HOOK_PRICE_DROPPED = 'actionQuincePriceDropped';

    public function install(): bool
    {
        // Hak spoza rdzenia musi istnieć, zanim moduł się pod niego podepnie.
        if (!Hook::getIdByName(self::HOOK_PRICE_DROPPED)) {
            $hak = new Hook();
            $hak->name = self::HOOK_PRICE_DROPPED;
            $hak->title = 'Quince: product price dropped';
            $hak->description = 'Quince Omnibus recorded a price lower than the previous one.';
            $hak->add();
        }

        return parent::install()
            && (new Outbox())->installTable()
            && Behaviour::installTables()
            && Consent::installTable()
            && (new NotifuseSettings())->installDefaults()
            && $this->registerHook([
                'actionQuinceRegisterFeature',
                // Konto i newsletter.
                'actionCustomerAccountAdd',
                'actionObjectCustomerUpdateBefore',
                'actionObjectCustomerUpdateAfter',
                'actionNewsletterRegistrationAfter',
                // Zamówienia.
                'actionValidateOrder',
                'actionOrderStatusPostUpdate',
                // Zachowania zalogowanego klienta, po zgodzie analitycznej.
                'displayFooterProduct',
                'actionCartSave',
                'actionWishlistAddProduct',
                'actionAuthentication',
                // Cena i stan produktów z ulubionych i koszyków.
                self::HOOK_PRICE_DROPPED,
                'actionUpdateQuantity',
                // Skrypt analityki Notifuse, wstrzymany do zgody analitycznej.
                'displayHeader',
            ]);
    }

    /** @param array<string,mixed> $params */
    public function hookDisplayHeader(array $params): string
    {
        try {
            return (new Front())->naglowek();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[quince_notifuse] ' . $e->getMessage(), 3, null, 'Module', (int) $this->id);

            return '';
        }
    }

    /**
     * Quince Omnibus zapisał cenę niższą od poprzedniej.
     *
     * @param array<string,mixed> $params id_product, id_product_attribute, id_shop, old_price, new_price
     */
    public function hookActionQuincePriceDropped(array $params): void
    {
        $this->bezpiecznie(fn () => (new ProductAlerts())->spadekCeny(
            (int) ($params['id_product'] ?? 0),
            (int) ($params['id_product_attribute'] ?? 0),
            (int) ($params['id_shop'] ?? $this->idSklepu()),
            (float) ($params['old_price'] ?? 0),
            (float) ($params['new_price'] ?? 0)
        ));
    }

    /** @param array<string,mixed> $params */
    public function hookActionUpdateQuantity(array $params): void
    {
        // Bez różnicy nie wiadomo, czy produkt wrócił z braku: starsze wywołania jej nie podają.
        if (!isset($params['delta_quantity'])) {
            return;
        }

        $this->bezpiecznie(fn () => (new ProductAlerts())->zmianaStanu(
            (int) ($params['id_product'] ?? 0),
            (int) ($params['id_product_attribute'] ?? 0),
            (int) ($params['id_shop'] ?? $this->idSklepu()),
            (int) ($params['quantity'] ?? 0),
            (int) $params['delta_quantity']
        ));
    }

    /**
     * Karta produktu. Hak wyświetlający, bo rdzeń nie ma haka „produkt obejrzany":
     * niczego nie rysujemy, zwracamy pusty napis.
     *
     * @param array<string,mixed> $params
     */
    public function hookDisplayFooterProduct(array $params): string
    {
        $produkt = $params['product'] ?? null;
        $idProduct = (int) ($produkt['id_product'] ?? 0);

        if ($idProduct > 0) {
            $this->bezpiecznie(fn () => (new Behaviour())->obejrzany($idProduct, (int) ($produkt['id_product_attribute'] ?? 0)));
        }

        return '';
    }

    /** @param array<string,mixed> $params */
    public function hookActionCartSave(array $params): void
    {
        $koszyk = $params['cart'] ?? null;

        // Tylko na froncie: zapis koszyka z panelu (zamówienie od pracownika) nie jest zachowaniem klienta.
        if ($koszyk instanceof Cart && $koszyk->id && $this->context->controller instanceof FrontController) {
            $this->bezpiecznie(fn () => (new Behaviour())->zmianaKoszyka($koszyk));
        }
    }

    /** @param array<string,mixed> $params */
    public function hookActionWishlistAddProduct(array $params): void
    {
        $this->bezpiecznie(fn () => (new Behaviour())->ulubiony(
            (int) ($params['idProduct'] ?? 0),
            (int) ($params['idProductAttribute'] ?? 0),
            (int) ($params['customerId'] ?? 0)
        ));
    }

    /** @param array<string,mixed> $params */
    public function hookActionAuthentication(array $params): void
    {
        $klient = $params['customer'] ?? null;

        if ($klient instanceof Customer) {
            $this->bezpiecznie(fn () => (new Behaviour())->poZalogowaniu($klient));
        }
    }

    /** @param array<string,mixed> $params */
    public function hookActionValidateOrder(array $params): void
    {
        $zamowienie = $params['order'] ?? null;

        if ($zamowienie instanceof Order) {
            $this->bezpiecznie(fn () => (new Orders())->zlozone($zamowienie));
        }
    }

    /** @param array<string,mixed> $params */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        $status = $params['newOrderStatus'] ?? null;

        if ($status instanceof OrderState) {
            $this->bezpiecznie(fn () => (new Orders())->zmianaStatusu((int) ($params['id_order'] ?? 0), $status));
        }
    }

    /** @param array<string,mixed> $params */
    public function hookActionCustomerAccountAdd(array $params): void
    {
        $klient = $params['newCustomer'] ?? null;

        if ($klient instanceof Customer) {
            $this->bezpiecznie(fn () => (new Account())->poRejestracji($klient, $this->idSklepu()));
            $this->bezpiecznie(fn () => (new Behaviour())->poZalogowaniu($klient));
        }
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectCustomerUpdateBefore(array $params): void
    {
        $klient = $params['object'] ?? null;

        if ($klient instanceof Customer) {
            $this->bezpiecznie(fn () => (new Account())->przedZmiana($klient));
        }
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectCustomerUpdateAfter(array $params): void
    {
        $klient = $params['object'] ?? null;

        if ($klient instanceof Customer) {
            $this->bezpiecznie(fn () => (new Account())->poZmianie($klient, $this->idSklepu()));
        }
    }

    /** @param array<string,mixed> $params */
    public function hookActionNewsletterRegistrationAfter(array $params): void
    {
        if (!empty($params['error'])) {
            return;
        }

        $this->bezpiecznie(fn () => (new Account())->zBlokuNewslettera(
            (string) ($params['email'] ?? ''),
            (int) ($params['action'] ?? 0),
            (int) $this->context->language->id,
            $this->idSklepu()
        ));
    }

    /**
     * Haki wywołuje rdzeń w trakcie żądania klienta (rejestracja, zamówienie).
     * Błąd po naszej stronie nie może przerwać tamtego żądania: zapisujemy go
     * w dzienniku PrestaShopa i idziemy dalej.
     */
    private function bezpiecznie(callable $praca): void
    {
        try {
            $praca();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[quince_notifuse] ' . $e->getMessage(), 3, null, 'Module', (int) $this->id);
        }
    }

    private function idSklepu(): int
    {
        return (int) $this->context->shop->id;
    }

    public function uninstall(): bool
    {
        return (new Outbox())->dropTable()
            && Behaviour::dropTables()
            && Consent::dropTable()
            && (new NotifuseSettings())->deleteAll()
            && parent::uninstall();
    }

    /**
     * Zgłoszenie do silnika: pozycja w menu panelu Quince.
     *
     * @return array<int,array<string,string>>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            'id' => $this->name,
            'label' => 'Notifuse',
            'icon' => 'plug',
            'route' => 'quince_notifuse_settings',
        ]];
    }

    /**
     * Przycisk „Konfiguruj" w menedżerze modułów prowadzi na ekran modułu.
     */
    public function getContent(): void
    {
        \Tools::redirectAdmin($this->get('router')->generate('quince_notifuse_settings'));
    }
}
