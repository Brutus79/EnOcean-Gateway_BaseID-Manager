# Fehlerbehebung

[Zur Übersicht](../README.md) · [Bedienungsanleitung](bedienungsanleitung.md)

Die meisten Hinweise sollen verhindern, dass eine nicht eindeutig geprüfte
Verbindung oder der falsche Zielwert verwendet wird. Eine Warnung nicht durch
mehrfaches Klicken oder Änderungen an technischen Verbindungen umgehen.

## Installation oder Gateway-Auswahl

| Beobachtung | Einordnung und nächster Schritt |
| --- | --- |
| Repository wird nicht installiert | Die öffentliche URL aus der README und Branch `main` kontrollieren. Erreichbarkeit von GitHub und die Fehlermeldung in IP-Symcon prüfen. Keine privaten Entwicklungsrepositorys verwenden. |
| Modul wird nach der Installation nicht gefunden | Den Konfigurator **EnOcean Gateway Manager** suchen; sein technischer Name ist **EnOcean Gateway Configurator**. Die Managerinstanz wird anschließend über dessen Gatewayliste erstellt. |
| Gatewayliste ist leer oder Erstellen nicht verfügbar | Es muss eine passende native EnOcean-Gatewayinstanz bereits existieren. Aktuelle Unterstützung: direktes serielles ESP3 mit aktiver Serial-Port-Verbindung; LAN/TCP oder ESP2 nicht unterstützt. |
| Bitte EnOcean-Gateway auswählen | Im Dropdown **EnOcean-Gateway auswählen** die gewünschte vorhandene Instanz wählen und die Konfiguration übernehmen. Nicht den Systembutton **Gateway ändern** verwenden. |
| Gespeicherte Gateway-Auswahl nicht verfügbar | Die referenzierte native Instanz könnte gelöscht worden sein. Gewünschte vorhandene Instanz bewusst auswählen; keine technische Parent-Kette auf Verdacht verändern. |

### Veralteter Sperrhinweis im Konfigurator

Der Konfigurator des aktuellen Stands enthält noch den allgemeinen Text
**„Keine realen Hardware-Writes in diesem Build.“** Dieser Hinweis stammt aus dem
früheren Entwicklungsstand und beschreibt die Schreibfähigkeit der akzeptierten
Finalversion nicht korrekt. Der Produktcode wurde für diese Dokumentation nicht verändert.

Der aktuelle Manager bietet den bestätigten Hardware-Schreibworkflow. Das bedeutet
aber nicht, dass jede Verbindung oder jeder Zustand schreiben darf. Zeigt der
**Manager im konkreten Ablauf** eine Schreibsperre, einen unbekannten Ausgang oder
eine fehlgeschlagene Prüfung, halten Sie an. Diese Meldungen nicht unter Verweis auf
den alten Konfiguratorhinweis ignorieren und keine Barrieren verändern.

## Base-ID lässt sich nicht verwenden

| Meldung oder Verhalten | Bedeutung / Abhilfe |
| --- | --- |
| Genau acht Hexzeichen erforderlich | Acht Zeichen aus `0–9` und `A–F` eingeben; keine eingebauten Leerzeichen oder Trennstriche. |
| Außerhalb des zulässigen Wertebereichs | Ein schreibbares Ziel muss zwischen `FF800000` und `FFFFFF80` liegen. Nicht irgendeine Geräte-ID als Base-ID übernehmen. |
| 128-Adressen-Block / Endung `00` oder `80` erforderlich | Beispielsweise ist `FF900090` ungültig. Ermitteln Sie die tatsächlich beabsichtigte Blockadresse; das Modul korrigiert sie nicht automatisch. |
| Master speichern / Ziel vorbereiten deaktiviert | Exakt die aktuelle Auswahl zuerst über **BASE-ID PRÜFEN** prüfen. Änderungen oder erneutes Öffnen des Formulars verwerfen den positiven Prüfstatus. |
| Zielvorbereitung fehlt außerhalb der Wartung | Erst **Gateway prüfen und Base-ID verwalten** ausführen und auf den bereiten Zustand warten. Lokale Validierung allein ist keine Hardwarefreigabe. |
| Ziel bereits aktuell | Keine Änderung nötig; keinen weiteren Write für denselben Wert anstreben. |
| Reserve würde unterschritten | Bei begrenztem Zähler müssen fünf Änderungen übrig bleiben. Bei fünf oder weniger verbleibenden Änderungen ist ein anderer Zielwert gesperrt. |
| Zähler nicht verfügbar | Fehlende Hardwareinformation, nicht null und nicht unbegrenzt. Ohne zuverlässigen Zähler keine Änderung vorbereiten. |

In technischen Fehlermeldungen können auch Antworten des Gateways stehen:
**RET_NOT_SUPPORTED** bedeutet „Kommando nicht unterstützt“,
**BASEID_OUT_OF_RANGE** „Base-ID außerhalb des Bereichs“ und
**BASEID_MAX_REACHED** „Hardware-Schreibgrenze erreicht“. Keine alternative
Schreibmethode ausprobieren und die Hardwaregrenze nicht mit einer lokalen
Löschfunktion zu umgehen versuchen.

## Wartung oder Kommunikation gestoppt

Ein Fehler bei Kommunikation, Anschlussbesitz, Geräteidentität oder
Verbindungskonfiguration bleibt sichtbar, auch wenn später einzelne Reads wieder
gelingen. Eine alte Freigabe darf nicht weiterverwendet werden.

1. Keinen Schreibvorgang bestätigen oder wiederholen.
2. Lesen Sie **Technische Details** und die genaue Fehlermeldung.
3. Ist **Wartung beenden** freigegeben, geben Sie die Verbindung kontrolliert zurück.
4. Bei abgeschlossener Rückgabe die Ursache prüfen, beispielsweise ein parallel
   zugreifendes Programm oder geänderte native Anschlussparameter.
5. Erst nach Klärung eine neue Prüfung beginnen. Bei unklarem früheren Write gelten
   zusätzlich die Hinweise im nächsten Abschnitt.

**Besitz unbekannt / Schnittstelle nicht exklusiv:** Kein zweites Programm oder
Gateway darf den Serial Port gleichzeitig verwenden. Die Berechtigungen der
implementierten Linux-Prüfung müssen erfüllt sein. Nicht den Anschluss selbst
öffnen, einen Prozess auf Verdacht beenden oder die Prüfung umgehen.

**Anderes Gateway erkannt:** Wenn der Austausch beabsichtigt ist, die neue Hardware
nach erfolgreichem Lesen unter **Gespeicherte Base-IDs** ausdrücklich zuordnen.
Wenn kein Austausch beabsichtigt war, Auswahl und Anbindung prüfen; nichts schreiben.

**Lokale Sicherungsdaten nicht lesbar:** Weitere Auswahl-/Speicheraktionen können
gesperrt sein. Nicht durch erfundene Historieneinträge reparieren oder Daten löschen,
um eine Freigabe zu erzwingen. Fehlerdetails sichern und den Maintainer kontaktieren.

## Unklarer Schreibausgang

**UNKNOWN** oder „unklar“ bedeutet nicht „nichts passiert“. Ein Schreibkommando
kann das Gateway erreicht haben, obwohl die Antwort oder spätere Verifikation fehlt.
Eine positive Kommandoantwort allein belegt ebenfalls noch keinen Erfolg.

Der Manager wiederholt den möglichen Write nicht automatisch. Die vorhandene
Wiederverbindungs-/Leseprüfung kann den Ausgang klären:

- Derselbe Chip, gewünschte neue Base-ID und erwarteter Zähler: Änderung verifiziert.
- Derselbe Chip, alte Base-ID und unveränderter Zähler: Änderung in der
  Wiederherstellungsprüfung nicht angewendet; kein automatischer neuer Write.
- Abweichende oder fehlende Werte: Ausgang weiterhin unbekannt.

Warten Sie die angebotene Ergebnisprüfung ab. Lösen Sie **nicht** erneut
**Jetzt schreiben** aus, starten Sie keinen Dienst auf Verdacht neu und nehmen Sie
keine manuelle Hardwarekorrektur vor. Bei anhaltender Unklarheit den Maintainer
mit der Fehlermeldung kontaktieren. Nach Verlust der früheren Versuchsdaten kann
eine neue Messung den aktuellen Hardwarezustand zeigen, aber nicht sicher beweisen,
welcher frühere Versuch ihn verursacht hat.

## Rückgabe und zuletzt gelesene Werte

**Verbindung wird an IP-Symcon zurückgegeben:** Die notwendige technische Rückgabe
läuft noch. Es gibt keine zusätzliche ein- bis zweiminütige Einbindungswartephase.
Nicht währenddessen umkonfigurieren oder einen weiteren Wartungsvorgang starten.

**Technische Rückgabe nicht vollständig belegt:** Nicht als erfolgreichen Abschluss
werten. Die native Gatewayverbindung und Fehlerdetails prüfen lassen; der Manager
überschreibt fremde Änderungen nicht einfach, um den Vorgang abzuschließen.

**Zuletzt gelesene Base-ID:** Nach erfolgreicher Rückgabe ist das eine historische
Messung. Für neue Werte ist eine neue kontrollierte Prüfung erforderlich. Eine
erfolgreiche Rückgabe bestätigt die technische Verbindung, nicht sämtliche
Funkgeräte oder jeden nativen Senderadress-Cache.

## Fehlermeldung weitergeben, ohne private Daten zu veröffentlichen

Hilfreich sind der genaue Bedienungsschritt, die sichtbare Fehlermeldung und der
verwendete Modulstand. Prüfen Sie Screenshots und technische Details vorher:
Sie können private Instanznamen, IDs, Anschlusskonfigurationen oder Geräteidentitäten
enthalten. Zugangsdaten, Tokens, private Netzadressen und vollständige Diagnose-
oder Sicherungsdateien nicht ungeprüft in öffentliche GitHub-Issues stellen.
