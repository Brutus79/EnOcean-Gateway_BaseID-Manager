# Praktische Sicherheitsabwägung für den nächsten C2-Integrationsschritt

Stand: 7. Oktober 2026. Keine Hardwarefreigabe. Der C2-Benutzerpfad endet
weiterhin bei WRITE_BLOCKED; B6_HARDWARE_WRITE_BARRIER bleibt true.

## Einfacher Recovery-Weg

Die vorhandene B6-Policy kennt alte Base-ID, gewünschte neue Base-ID, EURID und
den vor dem Versuch tatsächlich gelesenen Zähler. Nach einem unklaren Ergebnis
gibt es keine automatische Wiederholung. Read-only Recovery verlangt einen
beobachteten Disconnect, eine neue Session, exklusive Ownership und gültige,
korrelierte VERSION-/IDBASE-Antworten desselben Chips.

Bei einem begrenzten Zähler ergeben frische Reads:

- Ziel-Base-ID und erwarteter um eins reduzierter Zähler: VERIFIED.
- Alte Base-ID und unveränderter Zähler: RECOVERY_NOT_APPLIED.
- Jede andere Kombination oder nicht verfügbare Daten: UNKNOWN_OUTCOME.

RET_OK allein ist kein Erfolg. Die Auflösung ist keine Wiederholung des alten
Write-Intents; ein weiterer Write braucht eine neue bewusste Benutzeraktion.
Bei 0xFF meldet das Gerät unbegrenzte Änderungen. Es gibt dort keinen messbaren
Zähler-Decrement als zusätzlichen Beleg; diese Einschränkung wird nicht geschätzt.
Die C2-Fünf-Runden-Reads bleiben Bestandteil des kommenden integrierten Pfads.

## Risikobewertung ohne künstliche Prozentzahlen

| Fall / notwendige Ereigniskette | Einordnung und Auswirkung | Bestehende Absicherung / Erkennbarkeit | Aufwand und Entscheidung |
| --- | --- | --- | --- |
| C2-Nachweis nicht am realen Sendepunkt gebunden; Barriere würde dennoch geöffnet | Relevante Integrationslücke im normalen Ablauf; falsche Freigabe möglich | Aktuell Barriere und separate C2-Sperre; keine Hardwarefreigabe | Vorhandene Komponenten verbinden und final prüfen: **beheben im nächsten Auftrag** |
| Ziel, Ownership oder Gateway ändern sich vor Send | Realistischer Kontextwechsel; falsches Gerät/Ziel möglich | Range/128er-Ausrichtung, Live-Binding, exklusive Ownership, neue Reads, Bestätigung | Gates erhalten und unmittelbar am Sendepunkt testen: **einfach abgesichert**, Integration noch ausstehend |
| Antwort nach einzigem Write fehlt oder Verbindung bricht ab | Seltenes Kommunikationsereignis ohne belastbare Häufigkeitsdaten; Erfolg zunächst unbekannt | UNKNOWN, kein Retry, anschließend Read-only Vergleich von Base-ID/Zähler/EURID | Bestehende Recovery wiederverwenden: **einfach abgesichert**, nicht als Erfolg raten |
| UNKNOWN wird nicht aufgelöst; temporärer Arbiter wird entfernt; später bewusster neuer Wartungs-/Schreibablauf | Mehrstufig, bedienabhängig; historische Zuordnung des alten Versuchs kann verloren gehen | Neue Hardware-Reads und sichtbarer aktueller Zähler; Ziel gleich aktueller Base-ID wird abgelehnt; kein automatischer Folge-Write | Gatewayübergreifende Persistenzarchitektur unverhältnismäßig für diesen seltenen Wartungsfall: **akzeptiertes Restrisiko** bei Verlust der Versuchshistorie, nicht Freigabe bei ungelesener Hardware |
| Recovery liefert falsche Base-ID/Zähler-Kombination, falsche EURID oder keine gültigen Reads | Realistisch bei Störung/Hardwarewechsel; tatsächlicher Zustand nicht sicher bestimmt | Klare UNKNOWN-/Fehleranzeige, keine automatische Reparatur; neue Reads zwingend | Keine zusätzliche Zustandsmaschine nötig: **einfach abgesichert** |
| Systemzeit läuft zwischen zwei Reads oder vor Send rückwärts | Ungewöhnlich, aber kleiner lokal prüfbarer Fehler; Messung könnte fälschlich frisch erscheinen | C2 verwirft bereits zukünftige Zeitstempel | Zwei entsprechende B6-Prüfbedingungen ergänzt: **beheben** |

Das Restrisiko bedeutet ausdrücklich nicht, dass verlorene historische Evidence
ein unverändert bekanntes Write-Ergebnis beweist. Nach Lifecycle-Wechsel ist die
frisch ausgelesene Hardware maßgeblich. Ohne historische Vorwerte kann der frühere
Versuch nicht nachträglich eindeutig zugerechnet werden. Das muss als unbekannt
behandelt werden; ein neuer bewusst bestätigter Write ist kein automatischer Retry.

## Entscheidung

Die bestehende Read-only/Blocked-Grundlage ist für den nächsten gezielten
C2-Schreibintegrationsauftrag ausreichend. Keine weitere globale Registry wird
vorausgesetzt. Das ist noch keine fertige Schreibversion oder Freigabe eines
Hardwaretests: zuerst C2-Autorisierung bis zum einzigen Sendepunkt verbinden,
Recovery/Postverification und die letzte aktive Barriere im Produktpfad mocken
und prüfen. Erst danach separat genau einen realen Test autorisieren.
