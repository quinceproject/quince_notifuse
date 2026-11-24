<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Notifuse\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Klient API Notifuse (sprawdzony z wydaniem 43.0).
 *
 * Krótkie limity czasu, bo część wywołań idzie w trakcie żądania klienta
 * (rejestracja, zamówienie). Lepiej odłożyć wpis do kolejki, niż trzymać
 * klienta przy kasie, gdy Notifuse nie odpowiada.
 */
final class NotifuseClient
{
    private const TIMEOUT = 5;
    private const CONNECT_TIMEOUT = 3;

    public function __construct(
        private readonly string $adres,
        private readonly string $obszar,
        private readonly string $klucz
    ) {
    }

    /**
     * Listy obszaru roboczego.
     *
     * @return array<int,array{id:string,name:string,is_double_optin:bool,is_public:bool}>
     */
    public function listy(): array
    {
        $odpowiedz = $this->zadanie('GET', '/api/lists.list', ['workspace_id' => $this->obszar]);
        $out = [];

        foreach ((array) ($odpowiedz['lists'] ?? []) as $lista) {
            $out[] = [
                'id' => (string) ($lista['id'] ?? ''),
                'name' => (string) ($lista['name'] ?? ''),
                'is_double_optin' => (bool) ($lista['is_double_optin'] ?? false),
                'is_public' => (bool) ($lista['is_public'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Założenie albo uzupełnienie kontaktu. Tożsamością jest adres e-mail.
     *
     * @param array<string,mixed> $kontakt
     */
    public function zapiszKontakt(array $kontakt): void
    {
        $this->zadanie('POST', '/api/contacts.upsert', [], [
            'workspace_id' => $this->obszar,
            'contact' => $kontakt,
        ]);
    }

    /**
     * Zapis na listy przez publiczny `/subscribe`.
     *
     * Celowo nie `lists.subscribe` z kluczem: ten pomija podwójne potwierdzenie,
     * a zapis ze sklepu ma czekać na kliknięcie w mail od Notifuse.
     *
     * @param array<string,mixed> $kontakt
     * @param string[] $listy
     */
    public function zapiszNaListy(array $kontakt, array $listy): void
    {
        $this->zadanie('POST', '/subscribe', [], [
            'workspace_id' => $this->obszar,
            'contact' => $kontakt,
            'list_ids' => array_values($listy),
        ], false);
    }

    /**
     * Zapis na listy kluczem API: od razu `active`, bez potwierdzenia mailem.
     * Tylko do przeniesienia zapisu, który klient już potwierdził (zmiana adresu).
     *
     * @param array<string,mixed> $kontakt
     * @param string[] $listy
     */
    public function zapiszNaListyBezPotwierdzenia(array $kontakt, array $listy): void
    {
        $this->zadanie('POST', '/api/lists.subscribe', [], [
            'workspace_id' => $this->obszar,
            'contact' => $kontakt,
            'list_ids' => array_values($listy),
        ]);
    }

    /**
     * Zapisy kontaktu na listy: id listy => status. Pusta tablica, gdy
     * kontaktu nie ma w Notifuse.
     *
     * @return array<string,string>
     */
    public function zapisyNaListy(string $email): array
    {
        $out = [];
        foreach ((array) (($this->kontakt($email) ?? [])['contact_lists'] ?? []) as $zapis) {
            if (empty($zapis['deleted_at']) && isset($zapis['list_id'])) {
                $out[(string) $zapis['list_id']] = (string) ($zapis['status'] ?? '');
            }
        }

        return $out;
    }

    /**
     * Kontakt z Notifuse, razem z zapisami na listy. `null`, gdy go nie ma.
     *
     * @return array<string,mixed>|null
     */
    public function kontakt(string $email): ?array
    {
        try {
            $odpowiedz = $this->zadanie('GET', '/api/contacts.getByEmail', ['workspace_id' => $this->obszar, 'email' => $email]);
        } catch (NotifuseException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        return is_array($odpowiedz['contact'] ?? null) ? $odpowiedz['contact'] : null;
    }

    public function zmienStatusNaLiscie(string $email, string $idListy, string $status): void
    {
        $this->zadanie('POST', '/api/contactLists.updateStatus', [], [
            'workspace_id' => $this->obszar,
            'email' => $email,
            'list_id' => $idListy,
            'status' => $status,
        ]);
    }

    /**
     * Zdarzenie na osi czasu kontaktu. Notifuse rozpoznaje powtórkę po parze
     * (`event_name`, `external_id`), więc ponowienie z kolejki niczego nie dubluje.
     *
     * @param array<string,mixed> $zdarzenie
     */
    public function zapiszZdarzenie(array $zdarzenie): void
    {
        $this->zadanie('POST', '/api/customEvents.upsert', [], ['workspace_id' => $this->obszar] + $zdarzenie);
    }

    /**
     * @param array<string,string> $zapytanie
     * @param array<string,mixed>|null $cialo
     *
     * @return array<string,mixed>
     */
    private function zadanie(string $metoda, string $sciezka, array $zapytanie = [], ?array $cialo = null, bool $zKluczem = true): array
    {
        $url = rtrim($this->adres, '/') . $sciezka . ($zapytanie ? '?' . http_build_query($zapytanie) : '');
        $naglowki = ['Accept: application/json'];

        if ($zKluczem) {
            $naglowki[] = 'Authorization: Bearer ' . $this->klucz;
        }

        $ch = curl_init($url);
        $opcje = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_CUSTOMREQUEST => $metoda,
        ];

        if ($cialo !== null) {
            $naglowki[] = 'Content-Type: application/json';
            // Najkrótszy zapis liczb, niezależnie od `serialize_precision` serwera.
            $precyzja = ini_get('serialize_precision');
            ini_set('serialize_precision', '-1');
            $opcje[CURLOPT_POSTFIELDS] = (string) json_encode($cialo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ini_set('serialize_precision', (string) $precyzja);
        }

        $opcje[CURLOPT_HTTPHEADER] = $naglowki;
        curl_setopt_array($ch, $opcje);

        $tresc = curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $blad = curl_error($ch);
        curl_close($ch);

        if ($tresc === false) {
            throw new NotifuseException('Connection failed: ' . $blad);
        }

        $dane = json_decode((string) $tresc, true);

        if ($kod < 200 || $kod >= 300) {
            $komunikat = is_array($dane) ? (string) ($dane['error'] ?? $dane['message'] ?? '') : '';

            throw new NotifuseException(trim('HTTP ' . $kod . ' ' . ($komunikat !== '' ? $komunikat : substr((string) $tresc, 0, 200))), $kod);
        }

        return is_array($dane) ? $dane : [];
    }
}
