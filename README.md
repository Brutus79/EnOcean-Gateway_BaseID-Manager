# EnOcean Gateway Manager

EnOcean-ESP3-Gateway-Manager für **IP-Symcon 9.0**.
Das Modul trennt die gelesene Hardware-Base-ID von einer innerhalb der
Modulinstanz gesicherten Master-ID und einem bewusst angewandten Transferziel.
Ziel ist, die bisherige Base-ID beim Austausch eines kompatiblen Gateways zu übernehmen.
Gerätemodell, Funkregion und optionale Fähigkeiten werden nicht pauschal angenommen.

## Installation

Das Produktrepository ist [EnOcean-Gateway_BaseID-Manager](https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager).
**IP-Symcon-Konsole → Module → + → Repository hinzufügen → URL des neuen
Produktrepositorys eintragen → Branch `main` wählen.**

Danach eine Instanz des Konfigurators **EnOcean Gateway Manager** anlegen.
Die vorhandene native EnOcean-Gatewayinstanz auswählen; ihre aktuelle Anbindung
wird bei jedem Wartungsstart neu geprüft. Der unterstützte C2-Pfad ist direktes
serielles ESP3/8N1. LAN, ESP2 und unbekannte Parentketten bleiben gesperrt.
Details: [C2 Maintenance](docs/c2-maintenance.md).

## Base-ID verwalten

Die gemeinsame Auswahl bietet manuelle Eingabe, gespeicherte Master-ID und Historie.
Zuerst **BASE-ID PRÜFEN**: Format, ESP3-Wertebereich und 128er-Ausrichtung müssen
gültig sein. Eine Wertänderung verwirft die Freigabe. Danach kann die Auswahl nur
lokal als Master gespeichert oder während der Wartung als Gateway-Ziel vorbereitet
werden. Hardware-Base-ID, lokale Master-ID und gewünschtes Ziel sind getrennte Werte.

Ein Hardware-Write verlangt die angezeigten Sicherheitsbestätigungen A/B, fünf
konsistente Prewrite-Paare und die zusätzlichen finalen B6-Live-Prüfungen. Ein
erfolgreicher Write wird erst nach Disconnect, neuer Session und frischen Reads
von EURID, Ziel-Base-ID und erwartetem Zähler als verifiziert angezeigt.
Bei einem begrenzten Zähler muss mindestens die bestehende Reserve von fünf
Änderungen erhalten bleiben. Ein unklarer Ausgang erzeugt keinen automatischen Retry.

## Status und Sicherheit

- Funktionsfähiger Produktstand auf `main`; noch kein Release-Tag oder Store-Release.
- Auslesen, Master-ID lokal speichern und ein Transferziel anwenden sind
  getrennte Vorgänge. Lokales Speichern schreibt keine Hardware-Base-ID.
- Der bestehende C2/B6-Schreibpfad ist freigegeben, nicht die übrige normale
  Transportkommunikation. Hardware-Schreiben bleibt an alle implementierten
  Sicherheitsgates und die ausdrücklichen Benutzerbestätigungen gebunden.
- Keine direkte konkurrierende UART-Kommunikation und keine automatischen
  Base-ID-Schreibwiederholungen. Hardwarevalidierung aller Generationen und
  Regionen ist nicht nachgewiesen.
- Keine Aktivierung von Symcon Connect oder einer Lizenz durch dieses Modul.
- C2-Destroy, Reload, Dienst-Neustart, SDK-Deinstallation/Wiederinstallation und
  lokale Inventarerhaltung wurden isoliert geprüft. Das ersetzt weder die manuelle
  Benutzerabnahme noch eine allgemeine Freigabe beliebiger Backup-/Importsysteme.

## Offline-Entwicklung

PHP 8.5: `php tests/run_all.php`. Die Tests verwenden synthetische Fixtures
und benötigen weder IP-Symcon noch Gateway-Hardware.
Die JSON-Schemas unter `docs/` beschreiben interne Journalformate, enthalten
aber keine Journaldaten. Historische Package-Namen im Code und in Tests sind
Entwicklungsbezeichnungen, keine Veröffentlichungskanäle.

Der finale Sendepfad wurde mit einem lokalen Parent-Dummy geprüft. Ein realer
Hardware-Write dieses finalisierten Stands wurde bei der Finalisierung nicht ausgeführt.

## Lizenz

Das Projekt wird unter der [MIT License](LICENSE) veröffentlicht.
Copyright (c) 2026 Thorsten Dehen.
