=== Freemius Dashboard ===
Contributors:
Tags: freemius, sales, dashboard, api
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verbindet WordPress mit der Freemius API und zeigt Käufe, Kundendaten und Netto-Umsatz in einem minimalistischen Dashboard an.

== Description ==

Dieses Plugin verbindet deine WordPress-Website mit deinem Freemius-Verkäuferkonto und zeigt:

* Eine Tabelle aller Käufe/Zahlungen des ausgewählten Kalendermonats mit Kundendaten (Name/E-Mail), Kauftyp (Abo oder Lifetime) und Netto-Betrag.
* Die Summe der Netto-Einnahmen des ausgewählten Monats (je Währung).
* Ein Diagramm der Käufe der letzten 30 Tage.
* Automatische E-Mail-Benachrichtigungen bei jedem neuen Kauf über einen Freemius-Webhook.
* Bidirektionale Brevo-Synchronisierung: Käufer werden abhängig von ihrer Marketing-Einwilligung in zwei getrennte Listen einsortiert. Brevo-Abmeldungen werden beim Abgleich nach Freemius zurückgeschrieben.
* Elementor-Pro-Formularaktion „Brevo“ mit Double Opt-in.
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

Der Endpoint akzeptiert ausschließlich POST-Anfragen. Ein direkter Aufruf der Webhook-URL im Browser verwendet GET und liefert deshalb erwartungsgemäß die Meldung „keine Route gefunden“.

= Wie richte ich das Affiliate-Anmeldeformular ein? =

1. Unter „Freemius → Einstellungen“ müssen die API-Zugangsdaten sowie die Affiliate-Programm-ID hinterlegt sein.
2. Den Shortcode `[fsd_affiliate_signup]` in eine beliebige Seite oder einen Beitrag einfügen.
3. Neue Bewerbungen werden mit Status „Ausstehend“ bei Freemius angelegt und erscheinen unter „Freemius → Affiliates“ sowie im Freemius Developer-Dashboard zur Freigabe. Nach der Freigabe verschickt Freemius automatisch eine E-Mail mit dem Zugang zum Affiliate-Dashboard.

== Changelog ==

= 1.11.0 =
* Neu: Täglicher automatischer Brevo-Abgleich über WP-Cron. Große Datenbestände werden in kurzen Hintergrund-Batches verarbeitet; Zeitpunkt und Ergebnis erscheinen auf der Brevo-Seite und im Protokoll.
* Neu: Bei Erstattung, verlorenem/geschlossenem Dispute sowie abgelaufener, gekündigter oder gelöschter Lizenz wird der Brevo-Kontakt vollständig gelöscht, sobald keine weitere aktive Freemius-Lizenz mehr besteht. Eine reine Kündigung der automatischen Verlängerung löscht den Kontakt noch nicht vor dem Lizenzende.
* Sicherheit: Vor jeder Löschung werden alle aktiven Lizenzen des Nutzers geprüft. Vorläufige Verlängerungsfehler lösen keine Löschung aus.
* Neu: Gelöschte Kunden werden vom täglichen Abgleich nicht aufgrund alter Zahlungen erneut angelegt. Eine spätere Elementor-Anmeldung darf wieder als Lead eingetragen werden; erst ein neuer Kauf hebt die Kundensperre auf.

= 1.10.4 =
* Neu: Zentrale dritte Brevo-Liste für reine Leads ohne Freemius-Kauf. Elementor-Kontakte werden zusätzlich in diese Lead-Liste eingetragen.
* Neu: Kauf-Webhooks und der vollständige Abgleich erkennen bestehende Lead-Kontakte, entfernen die Lead-Zuordnung und protokollieren die Konvertierung zum Kunden.
* Änderung: Beim Kauf ist der aktuelle Freemius-Marketingwert für Attribut und Listenzuordnung führend; fehlende Einwilligung wird sicher als `NO` behandelt. Eine globale Brevo-Zustellsperre wird dabei nicht automatisch aufgehoben.
* Änderung: Beim späteren Abgleich bleibt eine zwischenzeitliche Brevo-Abmeldung führend und wird weiterhin nach Freemius zurückgeschrieben.

= 1.10.3 =
* Neu: Die Elementor-Brevo-Aktion bietet getrennte Auswahlen für Listen mit und ohne Marketing-Einwilligung.
* Änderung: Ein konfiguriertes Einwilligungsfeld bestimmt automatisch `MARKETING_MAILS` und die Ziel-Liste. Nicht angehakte Formulare werden in die Liste ohne Marketing einsortiert, statt vollständig übersprungen zu werden.
* Änderung: Double Opt-in wird nur für Kontakte mit Marketing-Einwilligung versendet; Kontakte ohne Einwilligung werden direkt in die dafür konfigurierte Liste eingetragen.

= 1.10.2 =
* Fix: Die Brevo-Seite reiht ihren AJAX-Controller zusätzlich direkt beim Rendern als Footer-Script ein. Der Abgleich-Button funktioniert dadurch auch bei abweichenden Admin-Hooknamen oder angepassten Menü-Konstellationen.
* Fix: Die Asset-Erkennung verwendet zusätzlich den eindeutigen Seitenparameter `page=fsd-brevo` als Fallback.

= 1.10.1 =
* Fix: WordPress-Admin-Assets werden anhand der tatsächlich von WordPress vergebenen Seiten-Hooks geladen. Dadurch reagieren der Brevo-Abgleich und die übrigen Buttons auf Unterseiten wieder auf Klicks.
* Verbesserung: Das Ergebnis eines abgeschlossenen Brevo-Abgleichs bleibt nach dem automatischen Neuladen als Admin-Hinweis sichtbar.

= 1.10.0 =
* Neu: Getrennte Brevo-Listen für Kontakte mit und ohne Marketing-Einwilligung. Statusänderungen verschieben Kontakte automatisch in die richtige Liste und entfernen sie aus der jeweils anderen.
* Neu: Der vollständige Abgleich erkennt globale sowie listenbezogene Brevo-Abmeldungen und setzt `is_marketing_allowed` für bereits abgemeldete Kontakte auch nachträglich in Freemius auf `false`.
* Änderung: Der Abgleich synchronisiert jetzt Kontakte, Attribute und Listen vollständig; nur fehlgeschlagene Kontakte bleiben zur gezielten Wiederholung stehen.
* Fix: Temporäre Brevo-Fehler (HTTP 429/5xx) werden mit Backoff wiederholt. Fehlgeschlagene Webhook-Übertragungen erhalten zusätzlich drei zeitversetzte Neuversuche.
* Fix: Freemius-Werte für `is_marketing_allowed` werden unabhängig davon korrekt verarbeitet, ob die API boolesche oder textuelle Werte liefert.

= 1.9.0 =
* Neu: Elementor-Pro-Formularaktion „Brevo“ (wie die Mailchimp-/ActiveCampaign-Integration). Trägt Absender in Brevo-Listen ein – direkt oder per Brevo-Double-Opt-in – mit Feld-Zuordnung, optionalem Einwilligungsfeld, `MARKETING_MAILS` und frei definierbaren Attributen. Nutzt den API-Key aus „Freemius → Brevo“ und schreibt ins Brevo-Protokoll.

= 1.8.1 =
* Fix: Der Brevo-Abgleich und „Alle übertragen“ liefen bei vielen Käufern in einen Gateway-Timeout. Beides läuft jetzt schrittweise über mehrere kurze AJAX-Requests mit Fortschrittsanzeige.

= 1.8.0 =
* Änderung: ActiveCampaign-Integration durch Brevo (API v3) ersetzt. Unter „Freemius → Brevo“ API-Key, Listen-IDs und die zu befüllenden Attribute (u. a. `MARKETING_MAILS`, `FREEMIUS_ID`, `UMSATZ_GESAMT`, `ANZAHL_KAEUFE`, `KUNDENSTATUS`) festlegen.
* Neu: Jeder Kauf (`payment.created`) trägt den Käufer per Webhook in Brevo ein bzw. aktualisiert ihn. Ergebnis jeder Übertragung wird protokolliert.
* Neu: Dashboard-Spalte „Brevo“ zeigt, ob der Käufer in Brevo vorhanden ist (Tooltip mit Protokolleintrag).

= 1.7.1 =
* Fix: Der Handler für asynchron geplante Kauf-Benachrichtigungen wird jetzt bei jedem Plugin-Start registriert. Zuvor wurde er nur während REST-Anfragen registriert und war beim späteren WP-Cron-Aufruf nicht verfügbar, sodass keine E-Mail versendet wurde.
* Dokumentation: Hinweis ergänzt, dass die Webhook-URL nur POST akzeptiert und ein direkter Browseraufruf per GET daher erwartungsgemäß keine Route findet.

= 1.6.2 =
* Fix: Affiliate-Anlage schlug mit „Invalid request path“ fehl. Unter dem Developer-Scope heißt die Produkt-Ebene „plugins“ statt „products“ (`/v1/developers/{id}/plugins/{id}/aff/…`), entsprechend der offiziellen Freemius-SDK-Referenz.
* Fix: Die angegebene Website/Domain wird jetzt ohne HTTP/S-Protokoll und ohne Pfad an Freemius übergeben (z. B. „example.com“ statt „https://example.com/“), wie von der API verlangt.

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
