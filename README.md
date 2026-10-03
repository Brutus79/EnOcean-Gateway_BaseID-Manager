# EnOcean Gateway Manager

Entwicklungsstand eines EnOcean-ESP3-Gateway-Managers für **IP-Symcon 9.0**.
Das Modul trennt die gelesene Hardware-Base-ID von einer innerhalb der
Modulinstanz gesicherten Master-ID und einem bewusst angewandten Transferziel.
Ziel ist, den Austausch eines kompatiblen Gateways sicher vorzubereiten.
Gerätemodell, Funkregion und optionale Fähigkeiten werden nicht pauschal angenommen.

## Installation für die manuelle Benutzerabnahme

Dieser Workspace ist zunächst nur lokal vorbereitet. Ein öffentliches
GitHub-Repository und dessen Installations-URL sind noch nicht eingerichtet.
Das erforderliche URL-Metadatenfeld verweist vorläufig auf das öffentliche
GitHub-Profil des Autors, nicht auf das private Entwicklungsarchiv.
Nach gesondert freigegebener Bereitstellung ist der vorgesehene Weg:

**IP-Symcon-Konsole → Module → + → Repository hinzufügen → URL des neuen
Produktrepositorys eintragen → gegebenenfalls den vorgesehenen Testbranch wählen.**

Danach eine Instanz des Konfigurators **EnOcean Gateway Manager** anlegen.
Die Schnittstelle bewusst auswählen; belegte oder nicht sicher zugeordnete
Anschlüsse dürfen nicht übernommen werden. Der native Einrichtungsweg ist
seriell/USB; TCP-Einrichtung ist noch nicht fertig implementiert.

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
- Verlustfreie Deinstallation sowie Import/Restore sind noch nicht validiert.

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
