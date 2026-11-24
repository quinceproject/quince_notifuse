/**
 * Quince Notifuse: rozpoznanie klienta i cele w analityce Notifuse.
 *
 * Plik uruchamia moduł zgód po zgodzie analitycznej, razem z `na.js`
 * (oba znaczniki wychodzą wstrzymane). `na.js` wczytuje się asynchronicznie
 * i nie ma kolejki wywołań, więc czekamy, aż `NotifuseAnalytics` się pojawi.
 *
 * Dane przychodzą z serwera w `window.QuinceNotifuse`:
 * - `email` i `hmac`: zalogowany klient i podpis z serwera do `identify()`;
 * - `logged`: czy klient jest zalogowany (po wylogowaniu zdejmujemy tożsamość);
 * - `product`: produkt do celu `product.viewed` (tylko niezalogowani);
 * - `cart_goal`: czy wysyłać cel `cart.updated` po dodaniu do koszyka.
 */
(function () {
    'use strict';

    var dane = window.QuinceNotifuse || {};

    /* Adres, z którym ten sklep rozpoznał przeglądarkę. Odróżnia naszą
       tożsamość od tej z linku w mailu: wylogowanie zdejmuje tylko naszą. */
    var KLUCZ = 'q_nf_identity';

    function pamiec(nazwa, wartosc) {
        try {
            if (wartosc === undefined) {
                return window.localStorage.getItem(nazwa);
            }

            if (wartosc === null) {
                window.localStorage.removeItem(nazwa);
            } else {
                window.localStorage.setItem(nazwa, wartosc);
            }
        } catch (e) {
            /* pamięć niedostępna: działamy bez niej */
        }

        return null;
    }

    function gotowe(fn, proba) {
        if (window.NotifuseAnalytics) {
            fn(window.NotifuseAnalytics);

            return;
        }

        if ((proba || 0) > 150) {
            return;
        }

        window.setTimeout(function () {
            gotowe(fn, (proba || 0) + 1);
        }, 100);
    }

    /* `trackGoal` zwraca obietnicę i odrzuca błędne dane: błąd celu nie może
       wywrócić strony. */
    function cel(na, dane) {
        try {
            var wynik = na.trackGoal(dane);

            if (wynik && typeof wynik.catch === 'function') {
                wynik.catch(function () {});
            }
        } catch (e) {
            /* cel pominięty */
        }
    }

    gotowe(function (na) {
        // Po ponownej zgodzie skrypt mógł zostać wcześniej wstrzymany.
        if (typeof na.resume === 'function') {
            try { na.resume(); } catch (e) {}
        }

        if (dane.email && dane.hmac) {
            if (pamiec(KLUCZ) !== dane.email) {
                na.identify(dane.email, dane.hmac);
                pamiec(KLUCZ, dane.email);
            }
        } else if (!dane.logged && pamiec(KLUCZ)) {
            na.clearIdentity();
            pamiec(KLUCZ, null);
        }

        if (dane.product && dane.product.id) {
            cel(na, { action: 'product.viewed', type: 'other', properties: dane.product });
        }

        if (dane.cart_goal && window.prestashop && typeof window.prestashop.on === 'function') {
            window.prestashop.on('updateCart', function (e) {
                var powod = e && e.reason;

                if (!powod || powod.linkAction !== 'add-to-cart') {
                    return;
                }

                var produkty = (e.resp && e.resp.cart && e.resp.cart.products) || [];
                var p = null;

                for (var i = 0; i < produkty.length; i++) {
                    if (String(produkty[i].id_product) === String(powod.idProduct)
                        && String(produkty[i].id_product_attribute) === String(powod.idProductAttribute || 0)) {
                        p = produkty[i];
                    }
                }

                cel(na, {
                    action: 'cart.updated',
                    type: 'other',
                    properties: {
                        id: String(powod.idProduct),
                        id_attribute: String(powod.idProductAttribute || 0),
                        name: p ? String(p.name) : '',
                        url: p ? String(p.url) : '',
                        price: p ? String(p.price_amount) : '',
                        quantity: p ? String(p.cart_quantity) : ''
                    }
                });
            });
        }
    });
})();
