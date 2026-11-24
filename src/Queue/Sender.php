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

use Quince\Notifuse\Api\NotifuseClient;
use Quince\Notifuse\Api\NotifuseException;
use Quince\Notifuse\Settings\NotifuseSettings;
use Quince\Notifuse\Source\Account;

/**
 * Wysyłka wpisów z kolejki do Notifuse.
 *
 * Każdy wpis idzie z ustawieniami sklepu, z którego przyszedł: cron obsługuje
 * wszystkie sklepy naraz.
 */
final class Sender
{
    /** @var array<int,NotifuseClient|null> klient na sklep */
    private array $klienci = [];

    public function __construct(
        private readonly Outbox $kolejka = new Outbox(),
        private readonly NotifuseSettings $ustawienia = new NotifuseSettings()
    ) {
    }

    /**
     * Zapis do kolejki i od razu jedna próba wysyłki. Nieudana zostaje w kolejce
     * dla crona.
     *
     * @param array<string,mixed> $payload
     */
    public function dodajIWyslij(int $idShop, string $kind, string $label, string $email, array $payload): int
    {
        $id = $this->kolejka->add($idShop, $kind, $label, $email, $payload);
        $wiersz = $this->kolejka->get($id);

        if ($wiersz !== null) {
            $this->wyslij($wiersz);
        }

        if ($kind === 'event' && in_array($label, self::AKTYWNOSC, true)) {
            $this->odnotujSklep($idShop, $email);
        }

        return $id;
    }

    /**
     * Zdarzenia, w których klient sam coś zrobił w sklepie. Po nich kontakt
     * dostaje ten sklep jako sklep ostatniej aktywności i na liście swoich
     * sklepów (`custom_json_3`). Zdarzenia wysyłane
     * bez udziału klienta (spadek ceny, wysyłka, porzucony koszyk) tego nie robią.
     */
    private const AKTYWNOSC = ['customer.registered', 'product.viewed', 'cart.updated', 'wishlist.added', 'order.placed'];

    /**
     * Sklep ostatniej aktywności w kontakcie. Raz na dobę na adres i sklep:
     * kolejne zdarzenia tego dnia niczego by nie zmieniły, a każdy zapis to
     * odczyt kontaktu i zapis w Notifuse.
     */
    private function odnotujSklep(int $idShop, string $email): void
    {
        if ($email === '' || $this->kolejka->bylyNiedawno($idShop, 'shop_activity', $email, 86400)) {
            return;
        }

        $this->dodajIWyslij($idShop, 'contact', 'shop_activity', $email, ['contact' => [
            'email' => $email,
            Account::POLE_SKLEP => Account::sklep($idShop),
        ]]);
    }

    /**
     * Lista sklepów klienta (`custom_json_3`) z dopisanym sklepem z kontaktu.
     * Notifuse nadpisuje pole w całości, więc najpierw odczytujemy, co już
     * jest: sklepy z innych instalacji tej samej firmy muszą zostać.
     *
     * @param array<string,mixed> $kontakt
     *
     * @return array<string,mixed>
     */
    private function zeSklepami(NotifuseClient $klient, array $kontakt, string $poprzedniAdres = ''): array
    {
        $sklep = (string) ($kontakt[Account::POLE_SKLEP] ?? '');

        if ($sklep === '' || empty($kontakt['email'])) {
            return $kontakt;
        }

        $sklepy = [];

        foreach (array_filter([(string) $kontakt['email'], $poprzedniAdres]) as $adres) {
            $zapisane = ($klient->kontakt($adres) ?? [])[Account::POLE_SKLEPY] ?? [];
            $sklepy = array_merge($sklepy, is_array($zapisane) ? array_map('strval', $zapisane) : []);
        }

        $kontakt[Account::POLE_SKLEPY] = array_values(array_unique([...$sklepy, $sklep]));

        return $kontakt;
    }

    /**
     * Wysyłka zaległych wpisów (cron).
     *
     * @return array{sent:int,failed:int,waiting:int}
     */
    public function wyslijZalegle(): array
    {
        $wynik = ['sent' => 0, 'failed' => 0, 'waiting' => 0];
        /** @var array<int,array{ile:int,blad:string}> $porazki nieudane na sklep, do alarmu */
        $porazki = [];

        foreach ($this->kolejka->due() as $wiersz) {
            $stan = $this->wyslij($wiersz);
            ++$wynik[$stan];

            if ($stan === 'failed') {
                $idShop = (int) $wiersz['id_shop'];
                $porazki[$idShop]['ile'] = ($porazki[$idShop]['ile'] ?? 0) + 1;
                $porazki[$idShop]['blad'] = (string) ($this->kolejka->get((int) $wiersz['id_quince_notifuse_outbox'])['last_error'] ?? '');
            }
        }

        foreach ($porazki as $idShop => $p) {
            Alert::wyslij($idShop, $p['ile'], $p['blad']);
        }

        $this->kolejka->purgeExpired();

        return $wynik;
    }

    /**
     * Jedna próba wysyłki wpisu.
     *
     * @param array<string,mixed> $wiersz
     *
     * @return string `sent`, `failed` (koniec prób) albo `waiting` (wróci w kolejce)
     */
    public function wyslij(array $wiersz): string
    {
        $id = (int) $wiersz['id_quince_notifuse_outbox'];
        $klient = $this->klient((int) $wiersz['id_shop']);

        if ($klient === null) {
            return $this->kolejka->markFailed($id, 'Connection to Notifuse is not set up for this shop.') ? 'failed' : 'waiting';
        }

        $dane = json_decode((string) $wiersz['payload'], true);
        $dane = is_array($dane) ? $dane : [];

        try {
            match ((string) $wiersz['kind']) {
                'contact' => $klient->zapiszKontakt($this->zeSklepami($klient, (array) ($dane['contact'] ?? []))),
                'subscribe' => $klient->zapiszNaListy(
                    $this->zeSklepami($klient, (array) ($dane['contact'] ?? [])),
                    (array) ($dane['list_ids'] ?? [])
                ),
                'list_status' => $klient->zmienStatusNaLiscie(
                    (string) ($dane['email'] ?? ''),
                    (string) ($dane['list_id'] ?? ''),
                    (string) ($dane['status'] ?? '')
                ),
                'unsubscribe' => $this->wypiszZeWszystkich($klient, (string) ($dane['email'] ?? '')),
                'email_change' => $this->zmienAdres(
                    $klient,
                    (string) ($dane['old_email'] ?? ''),
                    $this->zeSklepami($klient, (array) ($dane['contact'] ?? []), (string) ($dane['old_email'] ?? ''))
                ),
                'event' => $klient->zapiszZdarzenie($dane),
                default => throw new NotifuseException('Unknown kind ' . $wiersz['kind']),
            };
        } catch (NotifuseException $e) {
            // 4xx poza 408 i 429: Notifuse odrzucił treść, ponowienie nic nie zmieni.
            $kod = $e->getCode();
            $ostateczny = $kod >= 400 && $kod < 500 && !in_array($kod, [408, 429], true);

            return $this->kolejka->markFailed($id, $e->getMessage(), $ostateczny) ? 'failed' : 'waiting';
        }

        $this->kolejka->markSent($id);

        return 'sent';
    }

    /** Wypis z każdej listy, na której adres jest zapisany albo czeka na potwierdzenie. */
    private function wypiszZeWszystkich(NotifuseClient $klient, string $email): void
    {
        foreach ($klient->zapisyNaListy($email) as $idListy => $status) {
            if (in_array($status, ['active', 'pending'], true)) {
                $klient->zmienStatusNaLiscie($email, $idListy, 'unsubscribed');
            }
        }
    }

    /**
     * Zmiana adresu w koncie.
     *
     * Nowy adres przejmuje zapisy starego razem z wypisami: zapis potwierdzony
     * zostaje aktywny, czekający dostaje nową prośbę o potwierdzenie, wypis
     * zostaje wypisem. `bounced` i `complained` dotyczą starej skrzynki, więc
     * nie przechodzą. Stary adres zostaje wypisany, a kontakt zostaje w Notifuse:
     * usuwa go wyłącznie system zewnętrzny, który prowadzi rejestr osób.
     *
     * @param array<string,mixed> $kontakt nowy kontakt, z nowym adresem
     */
    private function zmienAdres(NotifuseClient $klient, string $stary, array $kontakt): void
    {
        $nowy = (string) ($kontakt['email'] ?? '');
        $zapisy = $stary !== '' ? $klient->zapisyNaListy($stary) : [];

        $klient->zapiszKontakt($kontakt);

        foreach ($zapisy as $idListy => $status) {
            if ($status === 'active') {
                $klient->zapiszNaListyBezPotwierdzenia($kontakt, [$idListy]);
            } elseif ($status === 'pending') {
                $klient->zapiszNaListy(['email' => $nowy], [$idListy]);
            } elseif ($status === 'unsubscribed') {
                $klient->zapiszNaListyBezPotwierdzenia($kontakt, [$idListy]);
                $klient->zmienStatusNaLiscie($nowy, $idListy, 'unsubscribed');
            }
        }

        foreach ($zapisy as $idListy => $status) {
            if (in_array($status, ['active', 'pending'], true)) {
                $klient->zmienStatusNaLiscie($stary, $idListy, 'unsubscribed');
            }
        }
    }

    private function klient(int $idShop): ?NotifuseClient
    {
        if (!array_key_exists($idShop, $this->klienci)) {
            $u = $this->ustawienia->all($idShop);

            $this->klienci[$idShop] = $this->ustawienia->polaczenieUstawione($idShop)
                ? new NotifuseClient($u['URL'], $u['WORKSPACE'], $u['API_KEY'])
                : null;
        }

        return $this->klienci[$idShop];
    }
}
