# Kontrollierter serieller Handoff und Rückgabe

Der Manager verwendet den Verbindungszustand von IP-Symcon, nicht eine zusätzliche
Linux-Prozessüberwachung. Entfernt wurden `/proc`-Scans, PID-/PPID-/FD-Zählungen,
Kindprozessklassifikation, Root-Anforderung und die daraus entstandene
30-Sekunden-Sonderwartezeit. Es entstehen keine neuen UART-Prozessdiagnosen.
Vorhandene historische Journalinformationen bleiben erhalten.

## Ablauf

1. Die ausgewählte native Gatewayinstanz und ihre aktuelle Serial-I/O-Verbindung
   werden frisch aufgelöst. Identität, Parameter und Parent-Beziehungen werden
   im bestehenden Handoff-Journal gesichert.
2. Das ursprüngliche I/O wird geschlossen. `Open=false` allein reicht nicht:
   IP-Symcon muss das Serial-I/O als **inaktiv (104)** melden, bevor die
   Wartungsverbindung geöffnet wird. Keine feste Wartezeit gilt als Nachweis.
3. Die temporäre Verbindung muss aktiv (102), korrekt konfiguriert und dem
   vorgesehenen Arbiter zugeordnet sein. Ein gemeinsam genutztes I/O oder ein
   weiteres in Symcon geöffnetes Serial-I/O am gleichen auflösbaren Anschluss
   gilt nicht als kontrollierter Transport. Es gibt keine Suche nach externen
   Programmen. Die fünf Versions-/Base-ID-Lesepaare bleiben Hardwarebeweis.
4. **Wartung beenden** schließt die temporäre Verbindung. Auch hier wird der
   inaktive I/O-Zustand beobachtet, nicht eine leere Linux-Deskriptorliste.
5. Die vorhandenen Restore-Prüfungen stellen exakt die ursprüngliche Konfiguration
   und Parent-Beziehung wieder her und entfernen nur eigene temporäre Instanzen.
   Die Rückgabe gilt erst bei aktivem nativen Gateway und aktivem originalem I/O
   als abgeschlossen. Es gibt keine zusätzliche Einbindungs-/Refresh-Wartephase.

Der vorhandene C2-Timer bleibt für die tatsächlichen asynchronen I/O-Zustände und
den Maintenance-Ablauf notwendig. Meldet Symcon das angeforderte Schließen nicht,
bleibt die Rückgabe unvollständig und stoppt nach der vorhandenen kurzen
3-Sekunden-Fehlergrenze. **Wartung beenden – Rückgabe erneut prüfen** setzt nur
dieselbe Rückgabe fort; es erzeugt keine neue Maintenance-Sitzung.

## Verbleibende Sicherheit und Anzeige

Gateway-/Hardwareidentität, Zielvalidierung einschließlich 128er-Ausrichtung,
Benutzerbestätigungen, fünf konsistente Prewrite-Paare, Counter und Mindestreserve,
Write-Lease, WAL, kein automatischer Write-Retry und fail-closed UNKNOWN-Behandlung
bleiben bestehen. Postverification verlangt weiterhin beobachteten Disconnect,
neue Transport-Session und die erwartete EURID/Base-ID/Counter-Kombination.

Der Transportkontext verwendet nun `controlledTransport` statt des irreführenden
`exclusiveUARTOwner`. Es wird kein erfundener Deskriptorzähler als Ersatz geliefert.
Ein alter Runtime-/Confirmation-Kontext wird nicht stillschweigend übernommen.

Nach belegter Rückgabe zeigt der Manager Status 102. Historische Fehler bleiben
als solche sichtbar; ein aktueller Konfigurations-/Restore-Fehler wird nicht
ausgeblendet. Auch bei `RESTORED` im Journal bleibt eine erneute Rückgabeprüfung
möglich, wenn die native Wiederöffnung noch nicht erfolgreich bestätigt wurde.

## Bewusste Grenze und Validierung

Die Kontrolle bezieht sich auf IP-Symcon und dessen I/O-Zustände, nicht auf den
Beweis, dass kein anderer Betriebssystemprozess Zugriff besitzt. Ein defekter
nativer Treiber oder ein extern zugreifendes Programm wird nicht durch eine
zusätzliche Linux-Überwachung abgesichert. Dies entspricht dem Einsatz als
Wartungswerkzeug für ein normalerweise funktionierendes EnOcean-System.

Die gezielten Tests verwenden ausschließlich lokale Metadaten und einen
Fake-Transport. Positive Handoffs, nicht bestätigtes Schließen, Restore-Fehler,
erneute Rückgabe, historische UI-Warnungen und Write-/Postverification-Gates
werden geprüft. Es wurde kein reales System kontaktiert oder Hardwarekommando
ausgeführt; eine reale Runtime-Abnahme steht noch aus.

PHP-Syntax der geänderten Dateien und `git diff --check` sind bestanden. Ein
zusätzlich isoliert ausgeführter älterer `master_target_binding_b82.php`-Test
scheitert im Engine-Fixture identisch auf dem unveränderten Ausgangscommit
`8359df6`; dies wurde nicht als PASS gewertet oder in diesem Auftrag korrigiert.

Referenz: [IP-Symcon Instanzstatus](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/instanzenverwaltung/ips-getinstance/).
