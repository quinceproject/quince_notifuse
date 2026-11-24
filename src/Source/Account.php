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

use Customer;
use Db;
use Language;
use Quince\Notifuse\Queue\Sender;
use Quince\Notifuse\Settings\NotifuseSettings;

/**
 * Konto klienta i newsletter: kontakt, zapis, wypis, zmiana adresu, rejestracja.
 *
 * Kontakt w Notifuse powstaje przy każdej rejestracji, także bez zgody na
 * newsletter. O wysyłce decyduje Notifuse.
 * Konta gości nie zakładają kontaktu: gość trafia do Notifuse ze swoim
 * zamówieniem, porzuconym koszykiem albo zapisem na newsletter.
 */
final class Account
{
    /** @var array<int,array<string,mixed>> stan klienta sprzed zapisu, do porównania po zapisie */
    private static array $przedZapisem = [];

    public function __construct(
        private readonly NotifuseSettings $ustawienia = new NotifuseSettings(),
        private readonly Sender $wysylka = new Sender()
    ) {
    }

    public function poRejestracji(Customer $klient, int $idShop): void
    {
        if (!$this->ustawienia->polaczenieUstawione($idShop) || !$klient->email) {
            return;
        }

        if (!$klient->is_guest) {
            $kontakt = self::kontakt($klient, $idShop);
            $this->wysylka->dodajIWyslij($idShop, 'contact', 'contact', $klient->email, ['contact' => $kontakt]);
            // Zgoda na śledzenie maili w kontakcie, ze zgody analitycznej z rejestracji.
            Consent::sprawdzIZapisz((int) $klient->id, $idShop);

            if ($this->ustawienia->zdarzenieWlaczone('customer.registered', $idShop)) {
                $this->wysylka->dodajIWyslij($idShop, 'event', 'customer.registered', $klient->email, [
                    'email' => $klient->email,
                    'event_name' => 'customer.registered',
                    'external_id' => 'customer-' . (int) $klient->id,
                    'occurred_at' => date(DATE_ATOM),
                    'goal_type' => 'signup',
                    'properties' => ['shop' => self::sklep($idShop)],
                ]);
            }
        }

        if ((int) $klient->newsletter === 1) {
            $this->zapisz($klient->email, (int) $klient->id_lang, $idShop, self::kontakt($klient, $idShop));
        }
    }

    /** Stan sprzed zapisu obiektu klienta: z bazy, bo obiekt ma już nowe wartości. */
    public function przedZmiana(Customer $klient): void
    {
        if (!$klient->id) {
            return;
        }

        $wiersz = Db::getInstance()->getRow(
            'SELECT email, newsletter, firstname, lastname, id_lang, is_guest FROM `' . _DB_PREFIX_ . 'customer`
             WHERE id_customer = ' . (int) $klient->id
        );

        if (is_array($wiersz) && $wiersz !== []) {
            self::$przedZapisem[(int) $klient->id] = $wiersz;
        }
    }

    public function poZmianie(Customer $klient, int $idShop): void
    {
        $przed = self::$przedZapisem[(int) $klient->id] ?? null;
        unset(self::$przedZapisem[(int) $klient->id]);

        if ($przed === null || !$this->ustawienia->polaczenieUstawione($idShop) || !$klient->email) {
            return;
        }

        $kontakt = self::kontakt($klient, $idShop);
        $staryAdres = (string) $przed['email'];

        if (strcasecmp($staryAdres, (string) $klient->email) !== 0 && $staryAdres !== '') {
            $this->wysylka->dodajIWyslij($idShop, 'email_change', 'email_change', $klient->email, [
                'old_email' => $staryAdres,
                'contact' => $kontakt,
            ]);
        } elseif (!$klient->is_guest && (
            (string) $przed['firstname'] !== (string) $klient->firstname
            || (string) $przed['lastname'] !== (string) $klient->lastname
            || (int) $przed['id_lang'] !== (int) $klient->id_lang
        )) {
            $this->wysylka->dodajIWyslij($idShop, 'contact', 'contact', $klient->email, ['contact' => $kontakt]);
        }

        $bylZapisany = (int) $przed['newsletter'] === 1;
        $jestZapisany = (int) $klient->newsletter === 1;

        if (!$bylZapisany && $jestZapisany) {
            $this->zapisz($klient->email, (int) $klient->id_lang, $idShop, $kontakt);
        } elseif ($bylZapisany && !$jestZapisany) {
            $this->wypisz($klient->email, $idShop);
        }
    }

    /**
     * Blok newslettera (`ps_emailsubscription`). Dla zarejestrowanego klienta
     * moduł zmienia flagę zapytaniem SQL, bez obiektu, więc zmiana nie przechodzi
     * przez `poZmianie()`: obsługujemy ją tutaj.
     *
     * @param int $akcja 0 zapis, 1 wypis (stałe `Ps_Emailsubscription::*_ACTION`)
     */
    public function zBlokuNewslettera(string $email, int $akcja, int $idLang, int $idShop): void
    {
        if (!$this->ustawienia->polaczenieUstawione($idShop) || !\Validate::isEmail($email)) {
            return;
        }

        if ($akcja === 1) {
            $this->wypisz($email, $idShop);

            return;
        }

        $this->zapisz($email, $idLang, $idShop, [
            'email' => $email,
            'language' => (string) Language::getIsoById($idLang),
            self::POLE_SKLEP => self::sklep($idShop),
        ]);
    }

    /** @param array<string,mixed> $kontakt */
    private function zapisz(string $email, int $idLang, int $idShop, array $kontakt): void
    {
        $listy = $this->ustawienia->listy($idShop);
        $idListy = $listy[$idLang] ?? '';

        if ($idListy === '') {
            return;
        }

        $this->wysylka->dodajIWyslij($idShop, 'subscribe', $idListy, $email, [
            'contact' => $kontakt,
            'list_ids' => [$idListy],
        ]);
    }

    private function wypisz(string $email, int $idShop): void
    {
        $this->wysylka->dodajIWyslij($idShop, 'unsubscribe', 'unsubscribe', $email, ['email' => $email]);
    }

    /**
     * Profil kontaktu z danych sklepu.
     *
     * `external_id` należy do systemu zewnętrznego, który prowadzi rejestr osób
     * (jego numer osoby), więc sklep go nie wysyła. Pola tego systemu
     * (`custom_string_4/5`, `custom_number_1/2`, `custom_datetime_2`,
     * `custom_json_2`) pomijamy, żeby ich nie nadpisać. Listę sklepów klienta
     * (`custom_json_3`) uzupełnia wysyłka, bo do tego trzeba odczytać kontakt.
     *
     * @return array<string,mixed>
     */
    public static function kontakt(Customer $klient, int $idShop): array
    {
        $gosc = (bool) $klient->is_guest;

        return array_filter([
            'email' => (string) $klient->email,
            'first_name' => (string) $klient->firstname,
            'last_name' => (string) $klient->lastname,
            'language' => (string) Language::getIsoById((int) $klient->id_lang),
            'country' => self::kraj((int) $klient->id),
            self::POLE_SKLEP => self::sklep($idShop),
            'custom_string_2' => self::grupa($klient),
            'custom_string_3' => $gosc ? 'guest' : 'account',
            // Gość nie zakłada konta, więc nie ma daty rejestracji.
            'custom_datetime_1' => $gosc || !$klient->date_add ? '' : date(DATE_ATOM, strtotime((string) $klient->date_add) ?: time()),
        ], static fn ($v): bool => $v !== '' && $v !== '0');
    }

    /** Sklep ostatniej aktywności klienta. */
    public const POLE_SKLEP = 'custom_string_1';

    /** Wszystkie sklepy klienta w obszarze roboczym, lista domen. */
    public const POLE_SKLEPY = 'custom_json_3';

    /** Kraj z pierwszego adresu klienta, kod ISO. */
    private static function kraj(int $idCustomer): string
    {
        $idAddress = (int) \Address::getFirstCustomerAddressId($idCustomer);

        return $idAddress > 0 ? (string) \Country::getIsoById((int) (new \Address($idAddress))->id_country) : '';
    }

    /** Nazwa domyślnej grupy klienta w języku domyślnym sklepu. */
    private static function grupa(Customer $klient): string
    {
        $grupa = new \Group((int) $klient->id_default_group, (int) \Configuration::get('PS_LANG_DEFAULT'));

        return is_string($grupa->name) ? $grupa->name : '';
    }

    public static function sklep(int $idShop): string
    {
        return (string) (new \Shop($idShop))->domain;
    }
}
