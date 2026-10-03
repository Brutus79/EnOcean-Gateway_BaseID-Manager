# Transport- und Capability-Grenzen

Technische Entwicklungsnotiz, keine finale Benutzeranleitung oder Hardwarefreigabe.

Endpoint-Typ, Protokoll und unterstützte Kommandos sind getrennte Eigenschaften.
`GatewayTransportProfile` beschreibt diese Trennung ohne Netzwerkzugriff. Der
aktuelle Adapter unterstützt ausschließlich den bestehenden seriellen/USB-ESP3-Pfad.
LAN, ESP2 und unbekannte Protokolle bleiben ohne eigenen nachgewiesenen Adapter
gesperrt. Die serielle Discovery bleibt für Einrichtung verfügbar, ist aber nicht
Bestandteil des normalen Manager-Workflows.

Ein zukünftiger LAN-Adapter muss insbesondere nachweisen:

- dokumentiertes Wire-Protokoll und vollständiges Stream-Framing;
- exklusiven Besitz des Endpoints, keine zweite parallele Verbindung zum Gateway;
- Endpoint-/Owner-Bindung und neue Session nach beobachtetem Disconnect/Reconnect;
- sichere Response-Korrelation ohne erfundene Transaktions-ID;
- dokumentierte Read-Capabilities und Fehlerbehandlung ohne gefährlichen Fallback;
- unveränderte Write-Gates, dauerhaften Intent und keinerlei automatischen Retry.

Nur transparentes, belegtes ESP3 kann denselben Codec und dieselbe Transaktionslogik
wiederverwenden. Ein TCP-Socket oder eine Modellbezeichnung allein genügt nicht.
Die UART-Ownership-Prüfung darf dabei nicht einfach als erfüllt gesetzt werden:
für einen Netzwerkadapter ist ein eigener gleichwertiger Ownership-Nachweis nötig.

## Dokumentierte Kandidaten

Symcons [Geräteliste](https://www.symcon.de/de/service/dokumentation/modulreferenz/geraete/enocean/geraeteliste/)
führt TCM310/TCM515 LAN Gateway und Thermokon STC-Ethernet.
Die [Modulreferenz](https://www.symcon.de/de/service/dokumentation/modulreferenz/geraete/enocean/)
beschreibt EnOcean Discovery und einen standardmäßigen LAN-Port 5000.
Das [aktuelle LAN-Datenblatt](https://www.symcon.de/assets/files/product/enocean-lan-gateway.pdf)
nennt TCM515 und Client Socket.

Symcons [BaseIDTool-Anleitung](https://www.symcon.de/assets/files/service/EnOceanBaseIDTool.pdf)
beschreibt Lesen der Base-ID und verbleibenden Änderungen sowie eine manuelle
Änderung. Sie veröffentlicht keine allgemeine Wire-Protokoll-/Integrationsfreigabe
für den hier verwendeten Arbiter. Das Tool wird vom Manager nicht gestartet.
Base-ID-Kommandos für Thermokon STC-Ethernet sind hier nicht verifiziert.

Support in Symcon bedeutet nicht automatisch Unterstützung durch diesen Manager.
Modell, Generation, Region und optionale Fähigkeiten werden nicht aus dem
Testgateway abgeleitet. Nicht ermittelbare Eigenschaften bleiben unbekannt.

## Anzeige ist kein Sicherheitsbeweis

Die Hauptansicht darf zuletzt vollständig gelesene Werte am selben Endpoint als
historisch anzeigen. `hardware`/`fresh` bleiben ausschließlich an echte vollständige
Reads und deren unveränderte 60-Sekunden-, Session- und Binding-Gates gebunden.
`displayHardware` wird von keinem Write-Gate als Beweis verwendet. ApplyChanges
und Neustart machen historische Anzeige nicht zu einem neuen Read.

Die gewünschte Gateway-Base-ID bleibt in den bestehenden nativen Properties
gebunden. Eine neue Master-ID wendet diese Properties nicht automatisch an.
Die bewusste Auswahl und normale Übernahme bleiben erforderlich. Die einfache
Änderungsaktion startet nur VERSION/IDBASE-Reads; Hardware-Schreiben bleibt gesperrt.
