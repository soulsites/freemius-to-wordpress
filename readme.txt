=== Freemius Dashboard ===
Contributors:
Tags: freemius, sales, dashboard, api
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verbindet WordPress mit der Freemius API und zeigt Käufe, Kundendaten und Netto-Umsatz in einem minimalistischen Dashboard an.

== Description ==

Dieses Plugin verbindet deine WordPress-Website mit deinem Freemius-Verkäuferkonto und zeigt:

* Eine Tabelle aller Käufe/Zahlungen des ausgewählten Kalendermonats mit Kundendaten (Name/E-Mail), Kauftyp (Abo oder Lifetime) und Netto-Betrag.
* Die Summe der Netto-Einnahmen des ausgewählten Monats (je Währung).
* Ein Diagramm der Käufe der letzten 30 Tage.

Design: minimalistisch, angelehnt an Material Design 3 (M3).

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/` hochladen.
2. Plugin im WordPress-Adminbereich aktivieren.
3. Unter „Freemius → Einstellungen“ die Art der API-Keys wählen und die zugehörigen Zugangsdaten eintragen (siehe FAQ).
4. Unter „Freemius → Dashboard“ die Käufe einsehen.

== Frequently Asked Questions ==

= Wo finde ich meine API-Zugangsdaten? =

Freemius unterscheidet zwei Arten von Keys – wichtig ist, die passende Scope-ID zum jeweiligen Schlüsselpaar zu verwenden, sonst meldet Freemius „Invalid Authorization header“:

* **Developer-Keys**: oben rechts unter „Mein Profil → Keys“. Scope-ID = deine Developer-ID. Zugriff auf alle deine Produkte, daher zusätzlich die Produkt-ID des gewünschten Produkts eintragen.
* **Produkt-Keys**: in den Einstellungen des jeweiligen Produkts unter „Keys“. Scope-ID = die Produkt-ID selbst (wird automatisch auch als Produkt-ID übernommen).

= Wird der Secret Key sicher gespeichert? =

Der Secret Key wird in der WordPress-Datenbank (Options-Tabelle) gespeichert. Beschränke den Zugriff auf `wp-admin` auf vertrauenswürdige Administratoren.

== Changelog ==

= 1.1.0 =
* Fix: Authorization-Header schlug mit „Invalid Authorization header“ fehl, wenn Produkt-Keys statt Developer-Keys verwendet wurden. Einstellungen unterscheiden jetzt explizit zwischen Developer- und Produkt-Keys mit der jeweils korrekten Scope-ID.

= 1.0.0 =
* Erste Veröffentlichung.
