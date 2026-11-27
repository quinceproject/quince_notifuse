# Quince Notifuse

**Contacts, newsletter sign-ups and shop events in Notifuse, for PrestaShop 9.2.**

[Notifuse](https://www.notifuse.com) is an open-source platform for newsletters and email
automations that you host yourself. Quince Notifuse connects a PrestaShop shop to it:
customers become contacts, newsletter sign-ups go to the list in the customer's
language, and what customers do in the shop arrives as events that Notifuse automations
can start from. The module sends no email of its own and decides nothing about sending:
the automations in Notifuse do.

> Quince Notifuse is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **Contacts.** A contact is created when a customer registers and kept up to date when
  they change their details: name, language, country, the shop, the customer group,
  the date of registration and whether it is an account or a guest. A list of all the
  shops the customer used (several shops can share one Notifuse workspace) is kept on
  the contact. A change of email address moves the subscriptions to the new address,
  unsubscriptions included.
- **Newsletter with double opt-in.** A sign-up from the registration form, the customer
  account or the newsletter block goes to the Notifuse list chosen for the customer's
  language, through Notifuse's public sign-up, so Notifuse asks the customer to confirm
  by email. Unsubscribing in the shop unsubscribes from every list.
- **Events.** Each one can be switched off: registration, product viewed, product added
  to the cart, product added to favourites, price drop and back in stock for products
  in favourites or open carts, abandoned cart, order placed (also a purchase goal) and
  order shipped with the tracking number. Products in events carry the name, reference,
  variant, price, address and a picture in the size chosen in the settings.
- **Abandoned carts.** A cart with an email address and no order after the time set in
  the settings (customers and guests) is sent once per state of the cart.
- **Recently viewed on the contact.** Optionally, the products a customer viewed last
  are saved in a chosen JSON field of the contact, for newsletter templates.
- **Visit tracking.** Optionally, the Notifuse web analytics script (`na.js`) is added to
  the shop. Signed-in customers are identified with a signature computed on the server,
  so the workspace secret never reaches the browser.
- **Consent.** Everything about customer behaviour (viewed products, the cart,
  favourites, visit tracking) goes only with the customer's consent to analytics
  cookies, read from Quince CMP. The script waits for that consent and clears its
  storage when consent is withdrawn. The consent to email open and click tracking on
  the contact follows the same consent and is updated whenever the customer changes it.
- **Delivery that does not block the shop.** Everything goes through a queue in the
  database. If Notifuse does not answer, checkout and registration keep working and the
  cron retries with growing intervals. A log screen shows every entry with what was
  sent and the last answer, with a retry button, and the shop address gets an email
  (at most once a day) when sending keeps failing.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Notifuse    | 43 (built against the 43.0 API) |
| Quince Engine | required |
| Quince CMP  | needed for everything that requires consent |
| Quince Omnibus | needed for the price drop event |

Without Quince CMP the module has no way to confirm consent, so it sends no events
about customer behaviour and the tracking script never starts. Contacts, newsletter
sign-ups and orders work without it.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_notifuse.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_notifuse/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_notifuse` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Notifuse and click **Install**.

**From Quince Engine**

With Quince Engine installed, **Quince project → Quince modules** installs and updates
this module from the Quince release list, without downloading anything by hand.

**With Composer**

A PrestaShop shop ships without a `composer.json` of its own, so Quince packages are
installed from a separate folder next to the shop. The one-time setup of that folder is
described in the [Quince Engine README](https://github.com/quinceproject/quince_engine#installation).
Then, from that folder:

```sh
composer require "quinceproject/notifuse:*"
php ../bin/console prestashop:module install quince_notifuse
```

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_notifuse/releases).

Uninstalling the module removes its settings, its queue and its log. Contacts already
in Notifuse stay there.

## Configuration

Open **Quince project → Notifuse** in the back office menu, or click **Configure** next
to the module in the Module Manager. Settings belong to each shop.

1. **In Notifuse**, create an API key under **Settings → API Keys** with read and write
   access to contacts and lists, and a public newsletter list with **Double opt-in**
   turned on for each language.
2. **Connection.** Enter the Notifuse address, the workspace identifier and the API key,
   then click **Check the connection**.
3. **Newsletter.** Pick the list for each shop language. If the PrestaShop newsletter
   module also sends its own verification email, the screen says so; turn that off, or
   the customer gets two emails.
4. **Events.** Switch off the ones you do not want, set the time after which a cart
   counts as abandoned and the picture size.
5. **Cron.** PrestaShop has no clock of its own. Call the address shown on the screen
   from the server cron every 5 minutes. It sends abandoned carts and retries what
   Notifuse did not accept.
6. **Visit tracking (optional).** In Notifuse turn on Web Analytics, add the shop domain
   to the allowed ones and turn on identifying customers from email links. To identify
   signed-in customers, enter the workspace secret.

## Architecture

- **`NotifuseClient`** talks to the Notifuse API (`contacts.upsert`,
  `contacts.getByEmail`, the public `/subscribe`, `contactLists.updateStatus`,
  `customEvents.upsert`, `lists.list`). Events are sent with an `external_id`, so a
  retry never creates a duplicate.
- **`Outbox`** and **`Sender`** keep the queue in the module's own table and send it;
  the `cron` front controller sends abandoned carts and what is overdue.
- **Sources** listen to shop hooks: account and newsletter hooks, `actionValidateOrder`,
  `actionOrderStatusPostUpdate`, `displayFooterProduct`, `actionCartSave`,
  `actionWishlistAddProduct`, `actionAuthentication`, `actionUpdateQuantity` and
  `actionQuincePriceDropped` from Quince Omnibus.
- **Consent** is read through the public API of Quince CMP (`allowed()`) during the
  customer's request and stored with the customer and the cart, so events sent later
  from the cron follow it. The `consent` front controller records a change made in the
  cookie banner.
- **`displayHeader`** adds the Notifuse script held back in the Quince CMP format
  (`type="text/plain" data-qc-code="cookies_analytics"`), so Quince CMP releases it
  only after consent.
- **Symfony controllers and Twig** for the settings and log screens, inside the Quince
  Engine shell. Settings are stored in PrestaShop configuration under the
  `QUINCE_NOTIFUSE_` prefix.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_notifuse.git
cd quince_notifuse
composer install
```

The folder must be called `quince_notifuse`, the same as the module. A clone of this
repository already has that name.

To build an installable archive, zip the `quince_notifuse/` folder **with its `vendor/`
directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name. Notifuse is a separate project of its own authors; this module is
not affiliated with it.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. You can help in two ways.

**Report what you find.** Open an issue in this repository to report a bug, suggest a
change or share a remark: [github.com/quinceproject/quince_notifuse/issues](https://github.com/quinceproject/quince_notifuse/issues).
Reports from working shops decide what gets built next. When reporting a bug, say what
happened, how to repeat it, and which versions of PrestaShop and the module you run.

**Support the development** on Ko-fi:
**[Ko-fi](https://ko-fi.com/quinceproject)**

The whole project, with every module: [quinceproject.github.io](https://quinceproject.github.io/)
