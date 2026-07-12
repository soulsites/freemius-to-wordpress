=== Freemius Dashboard ===
Contributors:
Tags: freemius, sales, dashboard, api
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verbindet WordPress mit der Freemius API und zeigt Käufe, Kundendaten und Netto-Umsatz in einem minimalistischen Dashboard an.

== Description ==

Dieses Plugin verbindet deine WordPress-Website mit deinem Freemius-Verkäuferkonto und zeigt:

* Eine Tabelle aller Käufe/Zahlungen des ausgewählten Kalendermonats mit Kundendaten (Name/E-Mail), Kauftyp (Abo oder Lifetime) und Netto-Betrag.
* Die Summe der Netto-Einnahmen des ausgewählten Monats (je Währung).
* Ein Diagramm der Käufe der letzten 30 Tage.
* Automatische E-Mail-Benachrichtigungen bei jedem neuen Kauf über einen Freemius-Webhook.
* Eine Affiliate-Partner-Übersicht mit Provisionssätzen und im Monat verdienter Provision.
* Shortcode `[fsd_affiliate_signup]` für ein öffentliches Anmeldeformular für neue Affiliate-Partner.

Design: minimalistisch, angelehnt an Material Design 3 (M3).

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/` hochladen.
2. Plugin im WordPress-Adminbereich aktivieren.
3. Unter „Freemius → Einstellungen“ die Developer-Keys, die Produkt-Keys und die Affiliate-Programm-ID eintragen (siehe FAQ).
4. Unter „Freemius → Dashboard“ die Käufe einsehen.

== Frequently Asked Questions ==

= Wo finde ich meine API-Zugangsdaten? =

Die Einstellungsseite fragt beide Arten von Keys in getrennten Abschnitten ab – jeweils mit ihrer eigenen ID:

* **Developer-Keys** (Abschnitt 1, für das Affiliate-Anmeldeformular): oben rechts unter „Mein Profil → Keys“. Als ID wird die Developer-ID eingetragen. Nur mit Developer-Keys lassen sich neue Affiliates über die API anlegen.
* **Produkt-Keys** (Abschnitt 2, für Dashboard, Käufe und Affiliates-Liste): in den Einstellungen des jeweiligen Produkts unter „Keys“. Als ID genügt die Produkt-ID; sie dient zugleich als Scope-ID.

Werden ID und Schlüsselpaar vertauscht, meldet Freemius „Invalid Authorization header“.

= Wird der Secret Key sicher gespeichert? =

Der Secret Key wird in der WordPress-Datenbank (Options-Tabelle) gespeichert. Beschränke den Zugriff auf `wp-admin` auf vertrauenswürdige Administratoren.

= Wie richte ich E-Mail-Benachrichtigungen bei Käufen ein? =

1. Unter „Freemius → Einstellungen“ muss der Secret Key hinterlegt sein – er wird auch zur Prüfung der Webhook-Signatur verwendet.
2. Unter „Freemius → E-Mails“ die Benachrichtigungen aktivieren, die gewünschten Empfänger-Adressen eintragen sowie optional Absender-Name/-Adresse anpassen. Über die Checkbox lassen sich Benachrichtigungen für Käufe mit 0 EUR Kaufbetrag deaktivieren.
3. Die dort angezeigte Webhook-URL im Freemius Developer-Dashboard des Produkts unter „Events & Webhooks“ als Endpoint eintragen und mindestens das Event „payment.created“ aktivieren.

Freemius sendet den Webhook bei jedem neuen Kauf, mit HMAC-SHA256 signiert (Header `X-Signature`) über den Secret Key. Die Website prüft die Signatur, bevor sie E-Mails verschickt.

= Wie richte ich das Affiliate-Anmeldeformular ein? =

1. Unter „Freemius → Einstellungen“ müssen die API-Zugangsdaten sowie die Affiliate-Programm-ID hinterlegt sein.
2. Den Shortcode `[fsd_affiliate_signup]` in eine beliebige Seite oder einen Beitrag einfügen.
3. Neue Bewerbungen werden mit Status „Ausstehend“ bei Freemius angelegt und erscheinen unter „Freemius → Affiliates“ sowie im Freemius Developer-Dashboard zur Freigabe. Nach der Freigabe verschickt Freemius automatisch eine E-Mail mit dem Zugang zum Affiliate-Dashboard.

== Changelog ==

= 1.6.1 =
* Fix: Das Anlegen von Affiliates über das Anmeldeformular schlug mit „Invalid Authorization header“ (UnauthorizedAccess) fehl. Developer-Keys erfordern den Developer-Scope im Ressourcen-Pfad (`/v1/developers/{id}/products/{id}/…`); dieser wird jetzt korrekt aufgebaut.

= 1.6.0 =
* Änderung: Die E-Mail-Bestätigung im Affiliate-Anmeldeformular läuft jetzt über einen sechsstelligen Code (reiner Text, kein Link). Der Code wird per E-Mail verschickt und im Formular eingegeben; danach wird die Bewerbung angelegt.
* Änderung: Einstellungsseite überarbeitet. Statt eines „Scope“-Umschalters werden Developer-Keys, Produkt-Keys und die Affiliate-Programm-ID nun in getrennten Abschnitten abgefragt. Dashboard, Käufe und Affiliates-Liste nutzen die Produkt-Keys, das Anmeldeformular die Developer-Keys. Bestehende Einstellungen werden automatisch migriert.

= 1.4.0 =
* Neu: Shortcode `[fsd_affiliate_signup]` für ein öffentliches Anmeldeformular, über das sich Besucher als Affiliate-Partner bewerben können. Bewerbungen werden per API mit Status „pending“ bei Freemius angelegt und müssen im Freemius-Dashboard freigegeben werden.
* Fix: Assets (CSS/Cache-Busting) auf 1.4.0 gehoben, damit gestylte Admin-Seiten nach dem Update nicht durch gecachte Alt-Versionen unstyled erscheinen.

= 1.3.0 =
* Neu: Kauf-Benachrichtigungen werden jetzt als gestaltete HTML-E-Mail mit motivierender Botschaft verschickt.
* Neu: Absender-Name und -E-Mail-Adresse der Benachrichtigungen sind unter „Freemius → E-Mails“ editierbar.
* Neu: Checkbox, um Benachrichtigungen bei einem Kaufbetrag von 0 EUR zu deaktivieren.

= 1.2.0 =
* Neu: Unterseite „E-Mails“ zur Verwaltung von Benachrichtigungs-Empfängern.
* Neu: Webhook-Endpoint (`/wp-json/fsd/v1/webhook`) nimmt Freemius-Events entgegen und verschickt bei „payment.created“ eine E-Mail mit den Kaufinformationen an die hinterlegten Adressen.

= 1.1.0 =
* Fix: Authorization-Header schlug mit „Invalid Authorization header“ fehl, wenn Produkt-Keys statt Developer-Keys verwendet wurden. Einstellungen unterscheiden jetzt explizit zwischen Developer- und Produkt-Keys mit der jeweils korrekten Scope-ID.

= 1.0.0 =
* Erste Veröffentlichung.
