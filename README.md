# EnOcean Gateway Base-ID Manager

Ein Modul für IP-Symcon, mit dem Sie die Base-ID eines kompatiblen EnOcean-Gateways
auslesen, lokal sichern und bei Bedarf bewusst ändern können.

Die Base-ID ist die Ausgangsadresse für die Senderadressen des Gateways. Wenn Sie
ein Gateway ersetzen, kann die Übernahme der bisherigen Base-ID unnötige
Neuzuordnungen bestehender Geräte und Konfigurationen vermeiden. Die eindeutige
Chipidentität wird dabei nicht kopiert. Das Modul ersetzt keine Sicherung Ihrer
vollständigen IP-Symcon-Konfiguration.

> **Wichtig:** Eine Hardware-Base-ID ist keine beliebig oft änderbare Einstellung.
> Das Gateway kann nur eine begrenzte Anzahl von Änderungen erlauben. Eingeben,
> **BASE-ID PRÜFEN** und lokales Speichern verbrauchen keinen Hardware-Schreibzyklus.
> Erst der ausdrücklich bestätigte Hardware-Schreibvorgang verändert das Gateway.

## Was kann das Modul?

- Aktuelle Base-ID und verbleibende Änderungen vom Gateway lesen.
- Geräteinformationen wie Radio-ID und Firmware-Version anzeigen.
- Die gelesene Base-ID sichern und eine eigene Master Base-ID lokal festlegen.
- Eine manuell eingegebene oder historische Base-ID vor der Verwendung prüfen.
- Eine gewünschte Base-ID nach zwei bewussten Bestätigungen auf das Gateway schreiben.
- Den neuen Hardwarezustand anschließend prüfen und das Gateway an IP-Symcon zurückgeben.

## Voraussetzungen und unterstützter Einsatz

Das Modulmanifest setzt **IP-Symcon 9.0** voraus. Benötigt wird ein bereits
eingerichtetes, funktionsfähiges **natives EnOcean-Gateway in IP-Symcon**.
„Nativ“ bedeutet hier: die vorhandene EnOcean-Gatewayinstanz von IP-Symcon,
nicht eine Instanz dieses Managers.

Der aktuelle Wartungsweg unterstützt eine direkte serielle ESP3-Anbindung:
Das native Gateway muss direkt mit einer aktiven **Serial-Port-Instanz** verbunden
sein, mit 8 Datenbits, keiner Parität und 1 Stopbit. Der Manager übernimmt die
vorhandenen Anschlussparameter; Sie wählen keinen UART oder technischen Parent
manuell aus. Ein USB-Anschluss kommt nur infrage, wenn er in IP-Symcon so als
serieller Anschluss eingebunden ist.

**Praktisch abgenommen:** Raspberry Pi 5 mit TCM310, einschließlich frischer
Installation aus diesem öffentlichen Repository, Einrichtung, Auslesen und einer
realen Base-ID-Änderung durch den Maintainer.

**Keine pauschale Kompatibilitätszusage:** Andere Modelle, Generationen und
Funkregionen sind damit nicht hardwareseitig abgenommen. LAN-/TCP-Gateways, ESP2
und unbekannte Verbindungsketten werden im aktuellen Wartungsweg nicht unterstützt.
Der Anschlussbesitz wird unter Linux geprüft; Windows/macOS und abweichende
Betriebsumgebungen sind nicht freigegeben. Weitere Grenzen stehen in der
[Bedienungsanleitung](docs/bedienungsanleitung.md#voraussetzungen-und-grenzen).

## Installation aus GitHub

1. Öffnen Sie die IP-Symcon Management Console.
2. Öffnen Sie **Module** und wählen Sie **Repository hinzufügen** (je nach
   Konsolenversion über **+** erreichbar).
3. Tragen Sie diese Repository-Adresse ein:

   ```text
   https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager.git
   ```

4. Wählen Sie den Branch **main** und schließen Sie die Installation ab.
5. Legen Sie über **Instanz hinzufügen** den Konfigurator **EnOcean Gateway Manager**
   an. Der technische Modulname lautet **EnOcean Gateway Configurator**.
6. Markieren Sie dort das vorhandene native EnOcean-Gateway und klicken Sie
   **ERSTELLEN**. Existiert bereits ein zugehöriger Manager, öffnen Sie diesen.
7. Öffnen Sie die erzeugte Managerinstanz. Kontrollieren Sie das Feld
   **EnOcean-Gateway auswählen**. Eine Auswahl ändern Sie ausdrücklich selbst und
   übernehmen sie mit der normalen Speichern-/Übernehmen-Funktion der Konsole.

Ohne ausgewähltes Gateway startet keine Wartung. Der Systembutton **Gateway ändern**
gehört zur technischen Parent-Verbindung von IP-Symcon und ist **nicht** die
Gateway-Auswahl des Managers. Verwenden Sie dafür das genannte Dropdown.

Ein veralteter allgemeiner Sperrtext im Konfigurator ist in der
[Fehlerhilfe](docs/fehlerbehebung.md#veralteter-sperrhinweis-im-konfigurator) erläutert.

## Die drei wichtigsten Base-IDs

| Bezeichnung | Bedeutung | Ändert das Gateway? |
| --- | --- | --- |
| Aktuelle Gateway-Base-ID | Tatsächlich vom angeschlossenen Gateway gelesener Wert. Außerhalb der Wartung heißt die Anzeige „Zuletzt gelesene Base-ID“. | Nein, reine Anzeige. |
| Master Base-ID | Bewusst lokal gespeicherte Referenz, die Sie beispielsweise bei einem Austausch wiederverwenden möchten. | Nein, lokales Speichern. |
| Gewünschte Gateway-Base-ID | Geprüfter Zielwert für eine geplante Hardwareänderung. | Erst nach den beiden Schreibbestätigungen und erfolgreichen Sicherheitsprüfungen. |

**Gesicherte Base-ID** und **Historie** sind ebenfalls lokale Daten. Sie belegen
nicht, welchen Wert ein später angeschlossenes Gateway aktuell besitzt.

## Grundlegende Bedienung

1. Klicken Sie im Manager auf **Gateway prüfen und Base-ID verwalten**.
   Warten Sie auf **Gateway bereit zur Base-ID-Verwaltung**. Während der Wartung
   nutzt IP-Symcon dieses Gateway nicht für den normalen Betrieb.
2. Lesen Sie **Aktuelle Base-ID des Gateways** und **Verbleibende Änderungen** ab.
   Unter **Gespeicherte Base-IDs → Aktuelle Base-ID sichern** können Sie den gelesenen
   Wert lokal sichern. Damit wird keine Hardware geändert.
3. Wählen Sie unter **Base-ID auswählen** eine manuelle Eingabe, den bereits
   gespeicherten Master oder einen Eintrag aus der Historie.
4. Klicken Sie immer zuerst auf **BASE-ID PRÜFEN**. Ein gültiger Wert besteht aus
   acht Hexzeichen, liegt zwischen `FF800000` und `FFFFFF80` und endet auf `00`
   oder `80`. Zum Beispiel ist `FF900080` gültig; `FF900090` ist nicht auf einen
   128-Adressen-Block ausgerichtet und wird zurückgewiesen.
5. Nach erfolgreicher Prüfung können Sie die Auswahl **ALS MASTER BASE-ID SPEICHERN**
   oder während der Wartung **als gewünschte Gateway-Base-ID vorbereiten**.
   Eine Änderung von Eingabe oder Auswahl verwirft die Prüfung. Auch nach erneutem
   Öffnen des Formulars müssen Sie die Auswahl erneut prüfen.
6. Wenn Sie nur lesen oder lokal sichern wollten, klicken Sie auf **Wartung beenden**.
   Warten Sie auf **Wartung beendet. Gateway wieder an IP-Symcon übergeben.**

![Erfolgreiche Prüfung einer neutralen Beispiel-Base-ID](docs/images/base-id-pruefung-gueltig.jpg)

*Echter UI-Ausschnitt: reine Validierung ohne Hardwareänderung. Der Beispielwert
ist keine Empfehlung für Ihre Anlage.*

## Wenn Sie die Hardware-Base-ID ändern möchten

> **Vorher:** Sichern Sie die bisherige Base-ID und kontrollieren Sie, welches
> Gateway ausgewählt ist. Verwenden Sie einen begründeten Zielwert, nicht die
> Beispieladresse dieser Anleitung. Planen Sie die Wartungsunterbrechung ein.

Nach dem Vorbereiten zeigt das Modul aktuelle Base-ID, Ziel-Base-ID und den
erwarteten Zähler nach der Änderung. **Gewünschte Base-ID schreiben** öffnet die
zweite Bestätigung. **Jetzt schreiben** ist die abschließende Freigabe für den
Hardware-Schreibvorgang. Bis dahin können Sie **Zurück zur Auswahl** verwenden.

Nach der Freigabe prüft das Modul das Gateway nochmals und unternimmt bei gültigen
Voraussetzungen genau einen Schreibversuch. Es wiederholt einen möglicherweise
gesendeten Schreibvorgang nicht automatisch. Erfolg wird erst nach einer neuen
Verbindung und Prüfung von Chipidentität, Ziel-Base-ID und erwartetem Zähler
festgestellt. Bei einer unklaren Meldung nicht erneut schreiben.

Bei einem begrenzten Zähler lässt das Modul eine Änderung nur zu, wenn danach
**mindestens fünf Änderungen übrig bleiben**: Bei `6` verbleibenden Änderungen
ist `6 → 5` möglich; bei `5` wird eine weitere Änderung gesperrt. Der Zähler stammt
vom Gateway. **Unbegrenzt** bedeutet, dass das Gerät den entsprechenden Sonderwert
meldet; eine fehlende Information ist nicht gleich „unbegrenzt“.

Die vollständige Schrittfolge einschließlich Gatewaytausch finden Sie in der
[Bedienungsanleitung](docs/bedienungsanleitung.md). Bei Warnungen hilft die
[Fehlerbehebung](docs/fehlerbehebung.md).

## Entstehung und Lizenz

Dieses Modul wurde von **Thorsten Dehen** erstellt und wird von ihm gepflegt.
Die technische Implementierung und Weiterentwicklung erfolgte mit Unterstützung
von **OpenAI Codex**. OpenAI und Codex sind weder offizieller Herausgeber noch
Hersteller oder Supportanbieter dieses Moduls.

Das Projekt wird unter der [MIT License](LICENSE) veröffentlicht.
Copyright (c) 2026 Thorsten Dehen.

## Für Entwickler

Die normale Bedienung benötigt keine Programmierkenntnisse. Interne technische
Informationen stehen getrennt unter [Wartungsintegration](docs/c2-maintenance.md)
und [Schreibintegration](docs/c2-write-integration.md). Historische Entwicklungs-
und Testbeschreibungen sind keine Anleitung zur Freigabe anderer Hardware.
Hardwarefreie Tests: `php tests/run_all.php` mit PHP 8.5.
