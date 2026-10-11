# Store-Vorlage: 0.9.1, Build 3 für Testing und anschließend Beta

Stand: **11. Oktober 2026**. Vorgesehene Testing-Version **0.9.1, Build 3**
auf `main`, auf Grundlage von Commit
`d9c59fb093681db91759319d145aa108d809656b` (0.9.1, Build 2).
Die Rückgabe beim Löschen/Deinstallieren berücksichtigt bereits entfernte
temporäre Verbindungen. Der veraltete Schreibsperrhinweis im Konfigurator wurde
korrigiert. Der konkrete Löschfehler wurde zunächst hardwarefrei reproduziert;
127 gezielte Lösch-/Rückgabe-/Aufbewahrungsprüfungen bestanden nach der Korrektur.
Keine neuen Timer oder Überwachungsmechanismen; die vorhandenen Sicherheits-
und Konfigurationsprüfungen bleiben erhalten. Noch keine reale Abnahme von Build 3.

## Bundle ID und Produktname

**Festgelegt: `io.github.brutus79.enoceangatewaybaseidmanager`**

Der vollständige Produktbezug ist erkennbar. `io.github.brutus79` ordnet das
Projekt dem bestehenden GitHub-Konto zu, ohne eine eigene Produktdomain oder
Thorstens persönlichen Namen vorauszusetzen.

Die ID entspricht dem Zeichensatz der offiziellen Dialogabbildung:
Kleinbuchstaben und Zahlen, durch Punkte getrennte Blöcke. Der begleitende Text
nennt verkürzt nur Kleinbuchstaben und Punkte.
[Offizieller Bundle-ID-Dialog](https://www.symcon.de/media/pages/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/73ee0da9bf-1791206399/store-submit-new-bundle.png)

Reverse-Domain-Notation bedeutet beispielsweise `de.symcon` statt `symcon.de`:
ein eindeutiger Herausgeber-Namensraum mit angehängtem Produktnamen. Symcon
empfiehlt diese Schreibweise, dokumentiert aber weder eine Pflicht zum Besitz
einer eigenen Domain noch einen Domain-/DNS-Nachweis. Das ist **kein Nachweis**,
dass beliebige fremde Namensräume beansprucht werden dürfen. Deshalb weder
`de.symcon` noch einen Hersteller-Namensraum wie `com.enocean` verwenden.
Die Empfehlung nutzt den zugeordneten GitHub-Namensraum; eine eigene Website ist
dafür nicht als Voraussetzung dokumentiert. Die Bundle ID bleibt gegenüber
der vorhandenen Testing-Veröffentlichung unverändert.
[Offizielle Einreichung](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/)

**Sichtbarer Name: EnOcean Gateway BaseID Manager.** Keine IPS-/IPSymcon-Präfixe.
Die Bundle ID ist keine Bibliotheks-/Modul-GUID. Bestehende GUIDs, technische
Modulnamen und Aliasse bleiben unverändert; der Store-Name ist separat lokalisierbar.

## Auszufüllende Portalvorlage

| Feld | Einzutragender Inhalt |
| --- | --- |
| Bundle ID | `io.github.brutus79.enoceangatewaybaseidmanager`, unverändert |
| Initialer Kanal | **Testing** |
| GIT-URL | `https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager.git` |
| GIT-Commit | **Vollständige SHA des auf GitHub bereitgestellten 0.9.1-/Build-3-Commits auf `main`**, aus dem Abschlussbericht übernehmen; keine automatische Store-Einreichung |
| Lokalisierung | **Deutsch** |
| Name | **EnOcean Gateway BaseID Manager** |
| Beschreibung | Text im Abschnitt „Beschreibung“ unten |
| Versionsinformation | Text im Abschnitt „Versionsinformation“ unten |
| Link zur Dokumentation | `https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager/blob/<GIT-COMMIT>/README.md` — `<GIT-COMMIT>` durch dieselbe vollständige SHA ersetzen |
| Kategorie | **Geräte**; keine zusätzliche Kategorie erforderlich |
| Aktualisierung beinhaltet keine funktionalen Änderungen | **Nicht aktivieren**; dieser Stand korrigiert die Rückgabe beim Löschen/Deinstallieren |
| Schließe Beta- und Testing-Kanal nach erfolgreichem Review | **Nicht aktivieren**; Stable ist nicht vorgesehen, Feld eventuell nicht eingeblendet |

Kategorie „Geräte“ ist in der
[offiziellen Auswahlabbildung](https://www.symcon.de/media/pages/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/0f29b5118a-1791206399/store-submit-category-selection.png)
enthalten. Pflichtfelder und Optionen ergeben sich aus der
[Einreichungsdokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/).
Ein separates Pflichtfeld „Kurzbeschreibung“, „Autor“ oder „Hersteller“ ist dort
nicht dokumentiert; keine zusätzlichen Pflichtfelder erfinden.

### Kurzbeschreibung

EnOcean-Gateway-Base-IDs auslesen, lokal sichern und beim Gatewaytausch nach
bewusster Bestätigung übernehmen.

Als Kurztext verwendbar, falls angeboten; ansonsten erster Absatz der Beschreibung.

### Beschreibung

EnOcean-Gateway-Base-IDs auslesen, lokal sichern und beim Gatewaytausch nach
bewusster Bestätigung übernehmen.

Der EnOcean Gateway BaseID Manager zeigt die aktuelle Gateway-Base-ID,
Geräteinformationen und verbleibenden Änderungen an. Lokaler Master, Historie
und gewünschtes Gateway-Ziel werden getrennt dargestellt. Eine gemeinsame
Base-ID-Prüfung und zwei bewusste Bestätigungen führen durch den Schreibablauf.
Ein möglicherweise gesendeter Schreibvorgang wird nicht automatisch wiederholt.
Anschließend wird der neue Hardwarezustand geprüft und das Gateway kontrolliert
an IP-Symcon zurückgegeben.

Vorhandene native EnOcean-Gatewayinstanzen werden anhand ihres IP-Symcon-
Modultyps im Dropdown **EnOcean-Gateway auswählen** angeboten. Der Benutzer
wählt ausdrücklich die gewünschte Instanz; ein anderes Gateway wird nicht
automatisch gewählt. Nach beendeter Wartung kann die Auswahl geändert und die
Konfiguration gespeichert werden. Beim nächsten Wartungsstart prüft das Modul
die aktuelle Verbindung und liest die tatsächliche Chipidentität frisch aus.
Der IP-Symcon-Systembutton **Gateway ändern** ist nicht diese Auswahl.

Master Base-ID und Historie bleiben im lokalen Symcon-Datenverzeichnis unter
`egm-product-inventory/inventory.json`, außerhalb des Modulordners. Beim Löschen
einer Managerinstanz werden diese Werte nicht gelöscht. Eine neue Instanz zeigt
erhaltene Master-Werte unter **Gespeicherte Base-IDs → Master Base-IDs anderer /
früherer Manager** an. Sie werden nicht automatisch übernommen: passenden Wert
manuell eingeben, prüfen und bewusst als Master speichern. Das Datenverzeichnis
muss erhalten oder aus einem Backup übernommen werden.

Voraussetzungen: IP-Symcon 9.0 sowie ein bereits
eingerichtetes natives EnOcean-Gateway direkt an einer seriellen ESP3-Verbindung.
Die Anschlusskontrolle erfolgt über IP-Symcons I/O-Zustand und Verbindungskette,
nicht über Linux-Prozesse oder Dateideskriptoren.
Praktisch abgenommen mit TCM310 auf Raspberry Pi 5. LAN/TCP und ESP2 sind im
aktuellen Wartungsweg nicht unterstützt; andere Modelle und Funkregionen sind
nicht pauschal freigegeben.

Hardwareänderungen können begrenzt sein. Bei endlichem Hardwarezähler bleiben
mindestens fünf Änderungen als Reserve erhalten. Lokales Prüfen und Speichern
verbrauchen keinen Hardware-Schreibzyklus.

Erstellt und gepflegt von Thorsten Dehen. Entwicklung mit Unterstützung von
OpenAI Codex; OpenAI/Codex sind keine Herausgeber oder Supportanbieter.
Lizenz: MIT.

### Versionsinformation

0.9.1, Build 3: Fehler bei der Gateway-Rückgabe während des Löschens oder
Deinstallierens behoben. Bereits entfernte temporäre Verbindungen verursachen
keinen Abbruch mehr beim Auslesen ihrer Konfiguration. Die bestehende Rückgabe
stellt die ursprüngliche Verbindung unter Beibehaltung der vorhandenen
Identitäts- und Konfigurationsprüfungen wieder her. Keine neuen Timer oder
Überwachungsmechanismen. Veralteten Schreibsperrhinweis im Konfigurator korrigiert.

Die numerische Version, Build und das Datum stammen aus `library.json`.
Der beschreibende Text wird separat als **Versionsinformation** der deutschen
Lokalisierung im Symcon-Publishing-Portal gepflegt und nicht automatisch aus
dieser Datei übernommen. Für die neue Testing-Einreichung dort den alten
bisherigen Versionshinweis durch den oben stehenden Text ersetzen und den neuen Git-Commit
auswählen. Ein GitHub-Push allein aktualisiert den Store-Installationsdialog nicht.

### Autor, Hersteller, Lizenz und Unterstützung

- Autor/Maintainer/Herausgeber dieses Projekts: **Thorsten Dehen**.
- Bibliotheksautor in unveränderter `library.json.author`: **EnOcean Gateway Manager Project**.
- Bestehendes `module.json.vendor`: **EnOcean**, technische Modulgruppierung;
  keine Behauptung, dass EnOcean Hersteller oder Herausgeber dieses Community-Moduls ist.
- Lizenz: **MIT**, Copyright (c) 2026 Thorsten Dehen.
- Projekthomepage/Support-Einstieg: `https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager`.
- Bestehender Codex-Hinweis bleibt erhalten; keine offizielle Unterstützung
  durch EnOcean, Symcon oder OpenAI behaupten.

## Version, Repository und notwendiger nächster Schritt

`library.json.version` darf laut offizieller Bibliotheksdokumentation ein frei
definierter String sein. **0.9.1 ist zulässig**; das empfohlene Zweierformat ist
keine Pflicht. Build ist eine Ganzzahl, Datum ein Unix-Zeitstempel.
[Bibliotheksmetadaten](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/bibliotheken/)

Vorbereitet: `version = "0.9.1"`, `build = 3`,
`date = 1791729186` (11.10.2026, 16:33:06 MESZ). Für 0.9.1
begann die Buildzählung auf Benutzerwunsch bei 1; diese Korrektur erhöht sie auf 3.
Die Nummer ist keine besondere Store-Kanalnummer.
**1.0.0 bleibt für den späteren Stable-Stand reserviert.**

Moduldefinitionen und MIT-Lizenz bleiben unverändert.
Die bestehende Behandlung entfernter temporärer Verbindungen und die
Konfiguratorhinweise werden gezielt korrigiert; README und Fehlerhilfe nachgeführt.
Keine zusätzliche Store-Verpackung oder
Paketmanagerinstallation nötig. Mindestversion **9.0** bleibt unverändert.
Geprüft werden die betroffenen Lösch-/Rückgabefunktionen hardwarefrei,
PHP-Syntax, JSON-Metadaten und Diff; keine erneute vollständige Produktabnahme.
Die aus früherer gezielter Vorbereitung dokumentierten veralteten Schema-
Einschränkungen rechtfertigen keine Absenkung der Mindestversion oder Produktänderung.
Keine vollständige Code-/Privacy-/Historienprüfung oder Regression wiederholt.

Dieser Stand enthält weiterhin den bereits geprüften Zielvorbereitungs-Fix.
Die Korrekturen gegenüber `d9c59fb0…` betreffen nur Lösch-/Rückgabepfad und Konfiguratorhinweise,
deren gezielte Tests, Dokumentation und Veröffentlichungsmetadaten.
Der Commit wird auf dem einzigen
Produktbranch `main` bereitgestellt. Anschließend genau dessen SHA und den
darauf festgelegten README-Link im Portal einsetzen. Bestehende IDs bleiben erhalten.
Ein **Tag oder GitHub-Release ist nicht erforderlich**: Symcon wählt einen Git-Commit.
Für diese Vorbereitung wird weder ein Tag noch ein GitHub-Release erstellt.

## Testing, Beta und Freigabegrenze

Testing ist nur für explizit eingeladene Benutzer sichtbar; Beta steht allen
Benutzern offen. Ein öffentlicher Quellcode-Branch macht den Testing-Kanal nicht
öffentlich. [Offizielle Kanäle](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/)

Nach Bereitstellung des 0.9.1-/Build-3-Commits kann Thorsten unter
**account.symcon.de → Entwicklerbereich → bestehendes Modul** die Testing-Version
aktualisieren und diese Vorlage verwenden. Testerzugang/Einladung für das
verwendete Symcon-Konto beziehungsweise die Installation muss eingerichtet sein.
Konkreter Kontostatus, Bundle-Verfügbarkeit und Einladungsmaske sind hier nicht
verifiziert. **Einreichen** ist bereits eine Veröffentlichung; Testing/Beta
werden unmittelbar bereitgestellt, nicht erst nach Stable-Review. Der vorherige
Testing-Stand wurde laut Benutzer bereits über den Store installiert; 0.9.1,
Build 3 ist damit noch nicht über den Store abgenommen.

Nach erfolgreichem manuellem Testing und erneuter bewusster Freigabe:
im Portal auf **Beta** übernehmen beziehungsweise eine Beta-Vorlage vom Testing-
Stand erstellen. Unverändert bleiben Bundle ID, Name, Sprache, Repository,
**exakter freigegebener Commit, Version und Build**, Beschreibung, Dokumentationslink und Kategorie.
Nur den Kanal ändern; Versionsinformation optional sachlich zu „Erste Beta-Version;
identischer geprüfter Stand der Testing-Version“ anpassen. Keine Codeänderung,
neuer Build oder Tag ist allein für den Kanalwechsel nötig. Bei Fehlerkorrekturen
stattdessen neuen Commit/Build gezielt testen; nicht stillschweigend austauschen.
[Kanalübernahme](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/)

**Nächster Schritt für Thorsten:** Im Publishing-Portal den exakten
0.9.1-/Build-3-Commit und die oben stehenden Beschreibungs- und Versionsinformationstexte für
Testing auswählen. Die Store-Einreichung wird nicht durch diesen Git-Auftrag ausgeführt.

Stable und dessen Architektur-/Reviewfragen bleiben ein separates späteres Paket;
insbesondere temporäre Verbindungsübernahme und lokale Journal-/Prozesszugriffe
dann transparent erläutern. Keine allgemeine Store-Reviewfreigabe behaupten.

Für diese Korrektur: **nur Lösch-/Rückgabepfad, Hinweise, gezielte hardwarefreie Tests, Dokumentation
und Metadaten, Commit/Push auf
`main`; keine Registrierung, Einladung, Store-Einreichung, Beta-/Stable-Freigabe,
kein Tag oder Release; kein Test-/Produktivkontakt, kein CO_WR_IDBASE und kein
Hardware-Schreibzyklus.** Die normale Hardware-Schreibfähigkeit bleibt unverändert.
