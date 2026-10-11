# Store-Vorlage: 0.9.0 für Testing und anschließend Beta

Stand: **11. Oktober 2026**. Lokaler UAT-Kandidat **0.9.0, Build 6**, noch
nicht veröffentlicht. Grundlage: öffentlicher `main`, Commit
`70003029f971d862b81d9e59eb0bd7a5e5554d6b` (0.9.0, Build 5).
Branch: `codex/c2-target-preparation-fix`. Die gezielte Korrektur behandelt eine
bereits aktive Ziel-Base-ID ohne Wartungsabbruch; die Rückgabelogik bleibt unverändert.

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
| GIT-Commit | **Vollständige SHA des nach manueller UAT freigegebenen und auf GitHub bereitgestellten Build-6-Commits**; kein automatisches Einreichen dieses lokalen Kandidaten |
| Lokalisierung | **Deutsch** |
| Name | **EnOcean Gateway BaseID Manager** |
| Beschreibung | Text im Abschnitt „Beschreibung“ unten |
| Versionsinformation | Text im Abschnitt „Versionsinformation“ unten |
| Link zur Dokumentation | `https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager/blob/<GIT-COMMIT>/README.md` — `<GIT-COMMIT>` durch dieselbe vollständige SHA ersetzen |
| Kategorie | **Geräte**; keine zusätzliche Kategorie erforderlich |
| Aktualisierung beinhaltet keine funktionalen Änderungen | **Nicht aktivieren**; Build 6 korrigiert die Zielvorbereitung |
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

0.9.0, Build 6: Eine bereits im Gateway aktive Ziel-Base-ID wird als
„Keine Änderung erforderlich“ angezeigt und beendet die Wartung nicht mehr
mit einem Sicherheitsstopp. Die vorhandenen Bestätigungs- und Schreibprüfungen
sowie die kontrollierte Wartungsrückgabe bleiben erhalten. Die Anschlusskontrolle
erfolgt weiterhin ohne Linux-PID-/PPID-/Dateideskriptor-Überwachung.

Die numerische Version, Build und das Datum stammen aus `library.json`.
Der beschreibende Text wird separat als **Versionsinformation** der deutschen
Lokalisierung im Symcon-Publishing-Portal gepflegt und nicht automatisch aus
dieser Datei übernommen. Den Text oben erst nach Freigabe des Build-6-Kandidaten
für dessen Einreichung verwenden. Für den derzeit veröffentlichten Build 5 passt:

> 0.9.0, Build 5: Kontrollierte Wartungsübernahme und zuverlässige Rückgabe an
> IP-Symcon. Die Anschlusskontrolle erfolgt über den I/O-Zustand und die
> Verbindungskette, ohne Linux-PID-/PPID-/Dateideskriptor-Überwachung.

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

Vorbereitet: `version = "0.9.0"`, `build = 6`,
`date = 1791716251` (11.10.2026, 10:57:31 UTC). Build 6 führt Build 5 fort;
die Nummer ist unsere Wahl, keine besondere Store-Kanalnummer.
**1.0.0 bleibt für den späteren Stable-Stand reserviert.**

Gezielt geprüft: `library.json`, drei Module mit vorhandenen Manifesten und
PHP-Einstiegspunkten, gebündelte Hilfsdateien, README, deutsche Anleitung,
Fehlerhilfe und MIT-Lizenz. Keine zusätzliche Store-Verpackung oder
Paketmanagerinstallation nötig. Mindestversion **9.0** bleibt unverändert.
Die aus früherer gezielter Vorbereitung dokumentierten veralteten Schema-
Einschränkungen rechtfertigen keine Absenkung der Mindestversion oder Produktänderung.
Keine vollständige Code-/Privacy-/Historienprüfung oder Regression wiederholt.

Dieser Kandidat enthält den gezielten Zielvorbereitungs-Fix und dessen
hardwarefreie Tests, Versionsmetadaten und diese aktualisierte Portalvorlage.
Der öffentliche `main` bleibt ohne Push bei `70003029…` mit 0.9.0 / Build 5.
Nach manueller UAT und ausdrücklicher Freigabe muss der neue Commit auf GitHub
bereitgestellt werden. Anschließend genau dessen SHA und den darauf festgelegten
README-Link im Portal einsetzen. Bestehende IDs dürfen nicht geändert werden.
Ein **Tag oder GitHub-Release ist nicht erforderlich**: Symcon wählt einen Git-Commit,
auch von einem anderen Branch. Optionaler Tag `v0.9.0` wäre lediglich eine spätere
Orientierungshilfe; für diese Vorbereitung weder nötig noch erstellt.

## Testing, Beta und Freigabegrenze

Testing ist nur für explizit eingeladene Benutzer sichtbar; Beta steht allen
Benutzern offen. Ein öffentlicher Quellcode-Branch macht den Testing-Kanal nicht
öffentlich. [Offizielle Kanäle](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/)

Nach Thorstens Freigabe und Bereitstellung des Build-6-Commits kann Thorsten unter
**account.symcon.de → Entwicklerbereich → bestehendes Modul** die Testing-Version
aktualisieren und diese Vorlage verwenden. Testerzugang/Einladung für das
verwendete Symcon-Konto beziehungsweise die Installation muss eingerichtet sein.
Konkreter Kontostatus, Bundle-Verfügbarkeit und Einladungsmaske sind hier nicht
verifiziert. **Einreichen** ist bereits eine Veröffentlichung; Testing/Beta
werden unmittelbar bereitgestellt, nicht erst nach Stable-Review. Der vorherige
Testing-Stand wurde laut Benutzer bereits über den Store installiert; Build 6
ist damit noch nicht über den Store abgenommen.

Nach erfolgreichem manuellem Testing und erneuter bewusster Freigabe:
im Portal auf **Beta** übernehmen beziehungsweise eine Beta-Vorlage vom Testing-
Stand erstellen. Unverändert bleiben Bundle ID, Name, Sprache, Repository,
**exakter freigegebener Commit, Version und Build**, Beschreibung, Dokumentationslink und Kategorie.
Nur den Kanal ändern; Versionsinformation optional sachlich zu „Erste Beta-Version;
identischer geprüfter Stand der Testing-Version“ anpassen. Keine Codeänderung,
neuer Build oder Tag ist allein für den Kanalwechsel nötig. Bei Fehlerkorrekturen
stattdessen neuen Commit/Build gezielt testen; nicht stillschweigend austauschen.
[Kanalübernahme](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/)

**Nächster Schritt:** Build 6 zuerst manuell auf dem Testsystem abnehmen.
Keine neue Testing-Einreichung ohne anschließende ausdrückliche Freigabe und
Bereitstellung des exakten Commits auf GitHub.

Stable und dessen Architektur-/Reviewfragen bleiben ein separates späteres Paket;
insbesondere temporäre Verbindungsübernahme und lokale Journal-/Prozesszugriffe
dann transparent erläutern. Keine allgemeine Store-Reviewfreigabe behaupten.

Für diesen Kandidaten: **keine Registrierung, Einladung, Store-Einreichung,
Beta-/Stable-Freigabe, kein Push, Tag oder Release; kein Produktivkontakt,
kein CO_WR_IDBASE und kein Hardware-Schreibzyklus.** Die normale Hardware-
Schreibfähigkeit bleibt unverändert. **STOPP zur manuellen UAT.**
