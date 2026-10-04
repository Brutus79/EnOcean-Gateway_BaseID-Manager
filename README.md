# EnOcean Gateway Manager

Entwicklungsstand eines EnOcean-ESP3-Gateway-Managers für **IP-Symcon 9.0**.
Das Modul trennt die gelesene Hardware-Base-ID von einer innerhalb der
Modulinstanz gesicherten Master-ID und einem bewusst angewandten Transferziel.
Ziel ist, den Austausch eines kompatiblen Gateways sicher vorzubereiten.
Gerätemodell, Funkregion und optionale Fähigkeiten werden nicht pauschal angenommen.

## Installation für die manuelle Benutzerabnahme

Das Produktrepository ist [EnOcean-Gateway_BaseID-Manager](https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager).
Die lokale C2-Weiterentwicklung ist noch nicht veröffentlicht und benötigt vor
einem GitHub-Installationstest eine gesonderte Bereitstellungsfreigabe.
Nach dieser Freigabe ist der vorgesehene Weg:

**IP-Symcon-Konsole → Module → + → Repository hinzufügen → URL des neuen
Produktrepositorys eintragen → den vorgesehenen Abnahmebranch wählen.**

Danach eine Instanz des Konfigurators **EnOcean Gateway Manager** anlegen.
Die vorhandene native EnOcean-Gatewayinstanz auswählen; ihre aktuelle Anbindung
wird bei jedem Wartungsstart neu geprüft. Der unterstützte C2-Pfad ist direktes
serielles ESP3/8N1. LAN, ESP2 und unbekannte Parentketten bleiben gesperrt.
Details und der gesperrte Abnahmeworkflow: [C2 Maintenance](docs/c2-maintenance.md).

## Status und Sicherheit

- **Kein Stable Release.** Die manuelle Benutzerabnahme steht noch aus.
- Auslesen, Master-ID lokal speichern und ein Transferziel anwenden sind
  getrennte Vorgänge. Lokales Speichern schreibt keine Hardware-Base-ID.
- Die reale Hardwareübertragung bleibt in diesem Build technisch gesperrt.
  Hardware-Schreibfunktionen dürfen ausschließlich entsprechend den
  implementierten Sicherheitsmechanismen und einer gesonderten Freigabe
  verwendet werden. Keine Barriere für einen Installationstest entfernen.
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

Noch keine vollständige finale Produktdokumentation.

## Lizenz

Das Projekt wird unter der [MIT License](LICENSE) veröffentlicht.
Copyright (c) 2026 Thorsten Dehen.
