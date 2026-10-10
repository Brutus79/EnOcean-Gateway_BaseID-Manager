# Store-Vorlage: 0.9.0 für Testing und anschließend Beta

Stand: **10. Oktober 2026**. Zur Freigabe durch Thorsten, **nicht eingereicht**.
Funktionale Basis: öffentlicher `main`, Commit
`f4ba803dc8ad16f08be662f2d975b3da1557218c`.
Lokale Vorbereitung auf `codex/store-testing-preparation`: ausschließlich diese
Vorlage und Versionsmetadaten in `library.json`; keine Produktänderung.

## Bundle ID und Produktname

**Bevorzugt: `io.github.brutus79.enoceangatewaybaseidmanager`**

Der vollständige Produktbezug ist erkennbar. `io.github.brutus79` ordnet das
Projekt dem bestehenden GitHub-Konto zu, ohne eine eigene Produktdomain oder
Thorstens persönlichen Namen vorauszusetzen.

Alternativen:

- `io.github.brutus79.enoceanbaseidmanager` — kürzer.
- `io.github.brutus79.enoceangatewaymanager.baseid` — stärker gegliedert.

Alle drei entsprechen dem Zeichensatz der offiziellen Dialogabbildung:
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
dafür nicht als Voraussetzung dokumentiert. Verfügbarkeit und tatsächliche
Portalannahme sind **noch nicht geprüft**; keine ID ist registriert.
[Offizielle Einreichung](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/)

**Sichtbarer Name: EnOcean Gateway BaseID Manager.** Keine IPS-/IPSymcon-Präfixe.
Die Bundle ID ist keine Bibliotheks-/Modul-GUID. Bestehende GUIDs, technische
Modulnamen und Aliasse bleiben unverändert; der Store-Name ist separat lokalisierbar.

## Auszufüllende Portalvorlage

| Feld | Einzutragender Inhalt |
| --- | --- |
| Bundle ID | `io.github.brutus79.enoceangatewaybaseidmanager`, vorbehaltlich Freigabe und Verfügbarkeit |
| Initialer Kanal | **Testing** |
| GIT-URL | `https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager.git` |
| GIT-Commit | **Vollständige SHA des noch zu committenden und nach Freigabe auf GitHub bereitzustellenden 0.9.0-Vorbereitungsstands**, nicht `f4ba803…` |
| Lokalisierung | **Deutsch** |
| Name | **EnOcean Gateway BaseID Manager** |
| Beschreibung | Text im Abschnitt „Beschreibung“ unten |
| Versionsinformation | Text im Abschnitt „Versionsinformation“ unten |
| Link zur Dokumentation | `https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager/blob/<GIT-COMMIT>/README.md` — `<GIT-COMMIT>` durch dieselbe vollständige SHA ersetzen |
| Kategorie | **Geräte**; keine zusätzliche Kategorie erforderlich |
| Aktualisierung beinhaltet keine funktionalen Änderungen | **Nicht aktivieren** für die erste Bereitstellung; diese Option unterdrückt ein Benutzer-Update |
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

Voraussetzungen: IP-Symcon 9.0, Linux mit Zugriff auf die benötigten
Prozessmetadaten und Symcon-Prozess mit root-Rechten sowie ein bereits
eingerichtetes natives EnOcean-Gateway direkt an einer seriellen ESP3-Verbindung.
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

0.9.0, Build 4: Erste Testing-Version für die Installation über den Module Store.
Funktional identisch mit dem bereits manuell abgenommenen Produktstand.
Enthält Gatewayauswahl, Geräteinformationen, lokale Base-ID-Sicherung,
Master/Historie, gemeinsame Base-ID-Prüfung, bestätigte Hardwareänderung mit
Ergebnisprüfung und kontrollierte Wartungsrückgabe. Deutsche Bedienungsanleitung
und Fehlerhilfe sind enthalten. Gegenüber dem akzeptierten main-Stand wurden
nur Veröffentlichungsmetadaten angepasst.

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
definierter String sein. **0.9.0 ist zulässig**; das empfohlene Zweierformat ist
keine Pflicht. Build ist eine Ganzzahl, Datum ein Unix-Zeitstempel.
[Bibliotheksmetadaten](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/bibliotheken/)

Vorbereitet: `version = "0.9.0"`, `build = 4`,
`date = 1791665489` (10.10.2026, 20:51:29 UTC). Build 4 führt Build 3 fort;
die Nummer ist unsere Wahl, keine besondere Store-Kanalnummer.
**1.0.0 bleibt für den späteren Stable-Stand reserviert.**

Gezielt geprüft: `library.json`, drei Module mit vorhandenen Manifesten und
PHP-Einstiegspunkten, gebündelte Hilfsdateien, README, deutsche Anleitung,
Fehlerhilfe und MIT-Lizenz. Keine zusätzliche Store-Verpackung oder
Paketmanagerinstallation nötig. Mindestversion **9.0** bleibt unverändert.
Die aus früherer gezielter Vorbereitung dokumentierten veralteten Schema-
Einschränkungen rechtfertigen keine Absenkung der Mindestversion oder Produktänderung.
Keine vollständige Code-/Privacy-/Historienprüfung oder Regression wiederholt.

Die einzige für 0.9.0 notwendige Anpassung ist `library.json`
(Version und zugehörige Build-/Datumsmetadaten); diese Vorlage enthält die Store-Texte.
**Noch uncommittet und nicht gepusht**, damit Thorsten Namen, Bundle ID, Texte und
Version zuerst freigeben kann. Öffentlicher `main` bleibt bei `f4ba803…` mit
Version 0.8 / Build 3. Diesen alten Commit **nicht als 0.9.0 einreichen**.

Nach Freigabe muss der Metadatenstand auf dem Vorbereitungsbranch committet und
auf GitHub erreichbar gemacht werden. Anschließend genau dessen SHA und den
darauf festgelegten README-Link in der Tabelle einsetzen. Main-Merge ist für die
Commit-Auswahl im Store nicht erforderlich. Bestehende IDs dürfen nicht geändert werden.
Ein **Tag oder GitHub-Release ist nicht erforderlich**: Symcon wählt einen Git-Commit,
auch von einem anderen Branch. Optionaler Tag `v0.9.0` wäre lediglich eine spätere
Orientierungshilfe; für diese Vorbereitung weder nötig noch erstellt.

## Testing, Beta und Freigabegrenze

Testing ist nur für explizit eingeladene Benutzer sichtbar; Beta steht allen
Benutzern offen. Ein öffentlicher Quellcode-Branch macht den Testing-Kanal nicht
öffentlich. [Offizielle Kanäle](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/)

Nach Thorstens Freigabe und Bereitstellung des 0.9.0-Commits kann Thorsten unter
**account.symcon.de → Entwicklerbereich → Modul hinzufügen** die ID mit Kanal
Testing anlegen und diese Vorlage ausfüllen. Testerzugang/Einladung für das
verwendete Symcon-Konto beziehungsweise die Installation muss eingerichtet sein.
Konkreter Kontostatus, Bundle-Verfügbarkeit und Einladungsmaske sind hier nicht
verifiziert. **Einreichen** ist bereits eine Veröffentlichung; Testing/Beta
werden unmittelbar bereitgestellt, nicht erst nach Stable-Review.
Der neue Store-Installationsweg ist noch nicht als getestet auszugeben.

Nach erfolgreichem manuellem Testing und erneuter bewusster Freigabe:
im Portal auf **Beta** übernehmen beziehungsweise eine Beta-Vorlage vom Testing-
Stand erstellen. Unverändert bleiben Bundle ID, Name, Sprache, Repository,
**exakter Commit, 0.9.0, Build 4**, Beschreibung, Dokumentationslink und Kategorie.
Nur den Kanal ändern; Versionsinformation optional sachlich zu „Erste Beta-Version;
identischer geprüfter Stand der Testing-Version“ anpassen. Keine Codeänderung,
neuer Build oder Tag ist allein für den Kanalwechsel nötig. Bei Fehlerkorrekturen
stattdessen neuen Commit/Build gezielt testen; nicht stillschweigend austauschen.
[Kanalübernahme](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/)

**Entscheidung:** Die Namen-/Textvorlage ist freigabereif. Ein Testing-Entwurf
ist nach Freigabe sinnvoll; eine verwendbare 0.9.0-Einreichung setzt noch den
auf GitHub erreichbaren Metadaten-Commit und eingerichteten Testerzugang voraus.
Kein verbleibender Produktfix aus dieser gezielten Prüfung abgeleitet.

Stable und dessen Architektur-/Reviewfragen bleiben ein separates späteres Paket;
insbesondere temporäre Verbindungsübernahme und lokale Journal-/Prozesszugriffe
dann transparent erläutern. Keine allgemeine Store-Reviewfreigabe behaupten.

In diesem Auftrag: **keine Registrierung, Einladung, Store-Einreichung, Beta-/
Stable-Freigabe, kein Commit/Push, Tag oder Release; keine Test-/Produktivkontakte,
keine Hardwarekommunikation, kein CO_WR_IDBASE und kein Hardware-Schreibzyklus.**
Produktcode und Hardware-Schreibfähigkeit sind unverändert. **STOPP zur Freigabe.**
