# C2 → bestehender B6-Schreibpfad

Finaler Produktpfad: Die permanente Entwicklungsbarriere ist geöffnet.

## Produktpfad

Die bestehende explizite A/B-Bestätigung und fünf konsistente Prewrite-Paare
starten genau eine B6-Transaktion pro bestätigter Auswahl. Der kleine zustandslose
`C2WriteBridge` bindet diesen Nachweis an ausgewählte native Instanz, Handoff,
exklusiven Transport, Session, Warn-Epoch, Hardwareidentität und Ziel. Er ist
keine weitere Schreibengine und keine Registry.

B6 liest VERSION/IDBASE erneut, vergleicht die tatsächlichen Werte vollständig
mit dem C2-Nachweis und übernimmt dessen bereits bewusst erteilte Bestätigung.
Nach der vorhandenen B6-Confirmation folgen nochmals frische VERSION/IDBASE-Reads.
Am einzigen `sendPreparedWrite()`-Sendepunkt werden C2-Kontext und Messwerte sowie
die bestehenden B6-Gates erneut geprüft: Ownership, unveränderte Konfiguration,
Session, Korrelation/Idle, Zielbereich/Ausrichtung, Counter/Reserve, Bestätigung,
Frische, WAL und maximal ein Sendversuch. Gleiche aktuelle und gewünschte Base-ID
ist NO_OP, kein Write.

`B6_HARDWARE_WRITE_BARRIER = false` lässt ausschließlich den bestehenden
`prepareSend()`-Pfad zu. Sämtliche genannten Sicherheitsgates bleiben aktiv.
`WRITE_SENT / MAY_HAVE_SENT` wird vor dem einzigen Parent-Sendversuch dauerhaft
geschrieben. Der normale Read-only-Transport erlaubt weiterhin keine Write-Frames.

Ein vollständig blockierter Nachweis hat weiterhin keinen Benutzer-Zeit-Timeout.
Live-Kontextänderungen invalidieren ihn weiterhin. Er wird bei einer späteren
Barrierenänderung nicht zum fortsetzbaren alten Sendauftrag: Runtime-Reload
invalidiert C2; ein neuer ausdrücklich bestätigter Ablauf mit frischen Reads ist
erforderlich. Zurück zur Auswahl / Rückgabe cancelt eine ungesendete Vorbereitung.
Gesicherte Base-ID und Master werden durch die Transaktionsidentitätsbindung nicht
überschrieben.

Die vorhandene Oberfläche wurde nicht umgestaltet. Nur technisch notwendige
Statusanzeigen wurden angebunden: zusätzliche B6-Prüfung ist noch kein finaler
Barrierenstopp; nach einem Sendversuch bleiben Auswahl/Rückgabe während laufender
Postverification gesperrt. Eine gelatchte UNKNOWN-/Fehlersituation kann weiterhin
bewusst und ohne Erfolgsbehauptung sicher zurückgegeben werden.

## Postverification und Read-only Recovery

Nach einem ausdrücklich vom Benutzer bestätigten Sendversuch übernimmt ausschließlich
der bestehende B6-Mechanismus Antwort, WAL, maximal einen Versuch und UNKNOWN.
Fehlende Antwort erzeugt keinen Write-Retry. Einmalige Read-only-Recovery nutzt
dieselbe Transaktion.

Der C2-Adapter schließt ausschließlich sein eigenes I/O per bestehender CAS-
Prüfung. Erst tatsächlich beobachteter Disconnect und null UART-Handles erlauben
das Wiederöffnen; B6 verlangt eine neue Session und dieselbe Transportidentität.
Fünf konsistente neue VERSION/IDBASE-Paare sind erforderlich. Keine feste Wartezeit
beweist Sicherheit; kurze Zeitgrenzen führen ausschließlich zum STOPP.

- Gleiche EURID, Ziel-Base-ID, erwarteter reduzierter Counter: VERIFIED.
- Bei Recovery: gleiche EURID, alte Base-ID, unveränderter Counter:
  RECOVERY_NOT_APPLIED, kein Retry.
- Andere Kombinationen, inkonsistente Reads oder unklare Kommunikation: UNKNOWN.

Bei Counter 0xFF bleibt die dokumentierte Unlimited-Behandlung erhalten.
Die neue C2-Session übernimmt ausschließlich den verifizierten Postzustand.
Die technische Rückgabe prüft Konfiguration und Ownership, wartet aber nicht
mehr auf eine nachgelagerte native Base-ID-Refresh-Beobachtung.
Die vorhandene Warnhistorie/Epoch wird nie gelöscht oder zurückgesetzt. Genau
der bewusst durchgeführte und beobachtete eigene Disconnect gehört zur neuen
Post-Session; jede zusätzliche Warnung verhindert C2-Ready.

Das akzeptierte Risiko verlorener historischer Versuchszurechnung nach UNKNOWN
und anschließendem Verlust des temporären Arbiters bleibt bestehen; siehe
[praktische Sicherheitsabwägung](practical-write-safety.md). Kein dauerhafter
globaler Journal-/Registry-Entwurf wurde eingeführt.

## Fokussierter Nachweis und Grenze

Separat ausführen: `php tests/c2_write_integration.php` (PHP 8.5).
Der Test verwendet die echten Manager-/Arbiter-/B6-/Parser-/WAL-/Sendegate-
Implementierungen. Nur SDK, C2-Umgebung und OS-Descriptor-Inspektion sind Doubles;
die echte Produktbarriere bleibt false. Der echte Produkt-Sendeaufruf erreicht
ausschließlich den lokalen SDK-Parent-Dummy. Es gibt keine Gatewayverbindung.

Geprüft werden initiale fünf Paare, echte A/B-Aufrufe, fünf Prewrite-Paare,
B6-Übergabe, zusätzliche finale Reads, genau ein WAL-/Audit-/Parent-Schreibversuch
und kein Retry. Gegenproben umfassen Gateway, Hardwareidentität,
Base-ID, Counter, Ownership, Session, Maintenance, Ziel, fehlende Confirmation,
Pending-Zustand und Parser-Busy, auch direkt vor dem letzten Sendegate.
Simulierte RET_OK-/Lost-response-Ausgänge durchlaufen den produktiven Reconnect-
und Fünf-Paar-Postpfad einschließlich widersprüchlicher Werte und Zusatzwarnung.

Kein Raspberry-Zugriff, keine reale UART-Kommunikation, kein Hardware-Write und
kein Counter-Verbrauch bei der Finalisierung. Der produktive Sendepunkt ist
hardwarefrei nachgewiesen. Der erste reale Write dieser Finalversion wird vom
Benutzer bewusst über den bestehenden bestätigten UI-Workflow ausgelöst; dies
ist noch kein Nachweis einer realen SDK-/Hardware-Ausführung der Finalversion.
