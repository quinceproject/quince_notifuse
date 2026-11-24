# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-24

### Added

- Connection to a Notifuse workspace per shop: address, workspace, API key, connection
  check and the lists read from Notifuse.
- Contacts from registration and customer account changes, with language, country,
  shop, customer group, account or guest, date of registration and the list of the
  customer's shops. A change of email address moves the subscriptions.
- Newsletter sign-ups through the Notifuse public sign-up (double opt-in), one list per
  shop language; unsubscribing from every list.
- Events: registration, product viewed, added to cart, added to favourites, price drop
  (with Quince Omnibus), back in stock, abandoned cart (customers and guests), order
  placed and order shipped.
- Recently viewed products saved in a chosen contact field.
- Notifuse web analytics script held back until consent to analytics cookies (Quince
  CMP), with server-signed identification of signed-in customers.
- Email open and click tracking consent on the contact, following the analytics consent.
- Queue with retries, a cron address, a log screen with retry and an email to the shop
  when sending keeps failing.
- Polish translation.
