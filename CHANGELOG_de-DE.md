# 0.9.0-beta.3

Dritte Vorabversion. Weiterhin für interne Entwicklung, QA und Sandbox-/
Staging-Tests; noch nicht im Shopware Store eingereicht.

**Hinweis zum Update: Das Plugin wurde umbenannt.** Der technische Name wurde von `KommandhubPaystackSW` in `KmhPaystackSW` geändert. Shopware behandelt es daher als anderes Plugin, und Installationen von `0.9.0-beta.1` / `0.9.0-beta.2` werden nicht direkt aktualisiert. Installieren Sie `KmhPaystackSW`, geben Sie Ihre Paystack-API-Schlüssel und Einstellungen erneut ein (sie liegen jetzt unter der Domain `KmhPaystackSW.config.*`) und entfernen Sie anschließend das alte Plugin. Auch das Zusatzfeld-Set und die Bankfelder wurden umbenannt (`kmh_paystack_*`); von früheren Versionen gespeicherte Bankdaten werden nicht migriert.

- Neue Installationen sind sofort einsatzbereit: Die Paystack-Zahlungsart wird bei der erstmaligen Anlage allen Verkaufskanälen zugewiesen, und der Sandbox-Modus ist standardmäßig aktiv, sodass eine neue Installation nie die Live-Umgebung von Paystack aufruft, bevor er deaktiviert wird. Ein Plugin-Update fügt die Zahlungsart nie wieder zu einem Verkaufskanal hinzu, aus dem sie entfernt wurde.
- Eine abgelehnte Karte wird jetzt als fehlgeschlagene statt als abgebrochene Zahlung erfasst. Schickt Paystack den Kunden ohne Referenz zurück (Abbruch/Zurück, einige Fehlerfälle), prüft das Plugin die beim Checkout gespeicherte Referenz und lässt den tatsächlichen Paystack-Status entscheiden: fehlgeschlagene Zahlungen schlagen fehl, abgebrochene werden storniert, erfolgreiche als bezahlt markiert und ausstehende bleiben offen.
- Ein verspäteter `charge.success`-Webhook für einen früheren Zahlungsversuch wird nun der Bestellung zugeordnet. Jeder erneute Versuch überschreibt die gespeicherte Referenz; deshalb wird die Bestelltransaktions-ID zusätzlich in den Paystack-Metadaten gesendet und als Ersatz-Suche genutzt. Die Zahlung wird weiterhin bei Paystack verifiziert, bevor die Bestellung als bezahlt markiert wird.
- Dokumentation: Verhalten bei der Erstinstallation, die Anforderung, dass die Checkout-Währung in Ihrem Paystack-Konto aktiviert ist, sowie Hinweise zu „Insufficient Funds" in der Sandbox bei hohen Beträgen und zum Standardtext der Shopware-Statusmails.

# 0.9.0-beta.2

Zweite Vorabversion. Weiterhin für interne Entwicklung, QA und Sandbox-/
Staging-Tests; noch nicht im Shopware Store eingereicht.

- Die Debug-Protokollierung wird jetzt pro Verkaufskanal ausgewertet. `enableDebugging` und die Log-Level sind normale Plugin-Einstellungen und lassen sich je Verkaufskanal setzen; bisher wurden sie nur global gelesen, sodass die Aktivierung für einen einzelnen Verkaufskanal wirkungslos blieb.
- Die Template-Blöcke der Bankverifizierung im Storefront sind jetzt eindeutig benannt und kollidieren nicht mehr mit anderen Plugins, die dieselben Konto-Templates erweitern.
- Lizenzwechsel von MIT zur Apache License 2.0: mit ausdrücklicher Patentlizenz, ausdrücklichem Markenvorbehalt und einer `NOTICE`-Datei, die die Urheberangaben in Forks weiterträgt.
- Das Plugin weist nun deutlich darauf hin, dass es eine unabhängige Drittanbieter-Integration ist und nicht mit Paystack verbunden oder von Paystack unterstützt wird. Ergänzt um eine Marken- und Branding-Richtlinie (`TRADEMARKS.md`).
- Die als Plugin- und Administrations-Icon verwendeten fremden Logos wurden durch KommandHub-Branding ersetzt.
- Build-Werkzeuge: `make prepare` funktioniert jetzt auch mit Vorabversionen und bei wiederholten Läufen, und der Testcontainer enthält `shopware-cli` für die Plugin-Validierung.

---

# 0.9.0-beta.1

Vorabversion für interne Entwicklung, QA und Sandbox-/Staging-Tests. Noch
nicht im Shopware Store eingereicht. Die öffentliche API und Namespaces können
sich vor `1.0.0` noch ändern.

- Paystack-Zahlung für Shopware 6: Karte, Banküberweisung, USSD und Mobile Money.
- Zahlungsprüfung kontrolliert Status, Betrag und Währung, bevor eine Bestellung als bezahlt markiert wird.
- Rückerstattungen aus der Bestelldetailseite, inklusive Teilrückerstattungen, mit serverseitiger Absicherung gegen Überrückerstattung.
- Eigene Berechtigung „Paystack-Rückerstattung", die Rollen zugewiesen werden kann (abhängig von der Bestell-Editor-Berechtigung).
- Webhook-Verarbeitung für `charge.success`, `refund.pending` und `refund.processed` mit Signaturprüfung.
- Bankkontoprüfung im Kundenkonto (Kontoauflösung über Paystack).
- Korrekte Betragsverarbeitung für alle von Paystack unterstützten Währungen, einschließlich Währungen mit null und drei Dezimalstellen (z. B. XOF, RWF, KWD). Das Plugin legt keine Währungen oder Sprachen im Shop an.
- Plugin-Oberfläche auf Englisch, Deutsch und Französisch verfügbar.
- Konfigurierbares Logging, Sandbox-/Live-Modus und ein Mindestrückerstattungsbetrag.
- Unterstützt Shopware 6.6 und 6.7.
