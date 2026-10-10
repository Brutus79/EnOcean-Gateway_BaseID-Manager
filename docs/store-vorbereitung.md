# Veröffentlichungsvorbereitung: Testing, danach Beta

Stand der Prüfung: **10. Oktober 2026**.
Produktkandidat: **f4ba803dc8ad16f08be662f2d975b3da1557218c** auf `main`.
Diese Vorbereitung ändert weder Produktcode noch Schreibfähigkeit oder Lizenz.
Sie ist selbst **keine Store-Veröffentlichung**.

## Entscheidung und offizielle Wege

**Testing** ist der einladungsbasierte Store-Kanal; **Beta** ist öffentlich.
**Stable** hat ein Symcon-Review und ist hier ausgeschlossen.
Ein öffentlicher GitHub-Branch allein ist kein zugriffsbeschränkter Testkanal:
Der Quellcode bleibt öffentlich, auch wenn die Store-Verteilung auf eingeladene
Tester beschränkt wird. [Offizielle Kanäle](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/)

Die Einreichung erfolgt im Symcon-Konto: Bundle-ID, Kanal, Repository und exakter
Commit, mindestens eine nutzbare Sprache mit Name/Beschreibung/Versionshinweis/
Dokumentationslink sowie mindestens eine Kategorie. Testing und Beta werden ohne
Stable-Review bereitgestellt. Tags, GitHub-Releases oder besondere Branchnamen
werden dafür nicht verlangt; bei GitHub ist der Commit auswählbar.
[Offizielle Einreichung](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/)

## Ergebnis der gezielten Repositoryprüfung

| Punkt | Ergebnis am Produktkandidaten |
| --- | --- |
| Repository | `Brutus79/EnOcean-Gateway_BaseID-Manager`, öffentlich, Standardbranch `main`. |
| Bibliothek | `library.json` im Wurzelverzeichnis, feste Bibliotheks-ID, vollständige Pflichtfelder. |
| Module | Drei passende Ordner mit jeweils `module.json` und `module.php`; Namen/Klassen, eindeutige IDs und Funktionspräfixe geprüft. |
| Weitere Ordner | `libs`, `docs`, `tests`: vorgesehene Struktur, keine zusätzliche Installationsverpackung nötig. |
| Version | `0.8`, Build `3`, Datumsfeld vorhanden. Keine neue Versionsnummer oder Funktionalität erfunden. |
| Mindestversion | IP-Symcon `9.0`; nicht für einen alten Schema-Validator herabgesetzt. |
| Abhängigkeiten | Mitgelieferte PHP-Hilfsdateien, Symcon-SDK und vorhandenes natives EnOcean-Gateway mit Serial Port; kein Composer-/Paketmanager-Installationsschritt vorgesehen. |
| Betriebsgrenze | Direkter serieller ESP3-Wartungsweg unter Linux; keine pauschale LAN-, Modell- oder Regionenfreigabe. |
| Dokumentation/Lizenz | README, deutsche Bedienungsanleitung und Fehlerhilfe vorhanden; MIT unverändert. |
| Installationsblocker | In den geprüften Struktur-/Metadatenpunkten keiner festgestellt. Kein Produktfix erforderlich. |

Grundlagen: [Bibliotheken](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/bibliotheken/),
[Struktur](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/struktur/)
und [Module](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/).

Die derzeit verlinkten offiziellen JSON-Schemas widersprechen teilweise der
aktuellen Dokumentation: Das Bibliotheksschema kennt nur Mindestversionen bis 6.2,
das Modulschema verbietet leere URLs. Die Modulbeschreibung erlaubt leere URLs
ausdrücklich; Symcon bietet aktuell Version 9.0 an. Das ist kein belegter
Installationsblocker unseres akzeptierten Stands. Keine Schema-Umgehung,
Mindestversionsabsenkung oder unnötige Metadatenänderung vorgenommen.
[Schema Bibliothek](https://www.symcon.de/assets/files/validation/librarySchema.json),
[Schema Modul](https://www.symcon.de/assets/files/validation/moduleSchema.json),
[aktuelle Symcon-Version](https://www.symcon.de/de/downloads/).

## Fertige Daten für die erste Testing-Vorlage

| Portal-Feld | Vorgesehener Wert |
| --- | --- |
| Bundle-ID | Vorschlag `io.github.brutus79.enoceangatewaymanager` — noch nicht registriert oder auf Verfügbarkeit geprüft. Nicht mit einer Modul-GUID verwechseln. |
| Initialer Kanal | **Testing**, nicht Beta und nicht Stable. |
| GIT-URL | `https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager.git` |
| GIT-Commit | `f4ba803dc8ad16f08be662f2d975b3da1557218c` |
| Lokalisierung | **Deutsch**; keine zusätzliche nicht durchgehend nutzbare Sprache zusagen. |
| Name | **EnOcean Gateway BaseID Manager** |
| Dokumentation | `https://github.com/Brutus79/EnOcean-Gateway_BaseID-Manager/blob/f4ba803dc8ad16f08be662f2d975b3da1557218c/README.md` |
| Kategorie | Eine passende angebotene Gerätekategorie wählen, beispielsweise **Geräte**, falls so verfügbar. |

### Beschreibung zum Übernehmen

EnOcean-Gateway-Base-IDs in IP-Symcon auslesen, lokal sichern und nach bewusster
Bestätigung ändern. Ein gespeicherter Master kann beim Austausch eines kompatiblen
Gateways wiederverwendet werden. Hardwarewert, lokaler Master und gewünschtes Ziel
werden getrennt dargestellt. Gültigkeitsprüfung, verbleibende Hardwareänderungen
und kontrollierte Wartungsrückgabe sind Bestandteil des geführten Ablaufs.

Voraussetzungen: IP-Symcon 9.0 und ein eingerichtetes natives Gateway am direkten
seriellen ESP3-Anschluss unter Linux. Praktisch abgenommen mit TCM310 auf Raspberry
Pi 5. Keine pauschale Freigabe anderer Hardware oder Regionen; LAN und ESP2 sind
im aktuellen Wartungsweg nicht unterstützt. Hardwareänderungen sind begrenzt;
bei endlichem Zähler bleiben mindestens fünf Änderungen als Reserve erhalten.

Erstellt und gepflegt von Thorsten Dehen; Entwicklung mit Unterstützung von
OpenAI Codex. OpenAI/Codex sind keine Herausgeber oder Supportanbieter des Moduls.

### Versionsinformation zum Übernehmen

Erste kontrollierte Testing-Bereitstellung des manuell abgenommenen Produktstands
0.8, Build 3. Enthält Gatewayauswahl, Auslesen, lokale Sicherung/Master/Historie,
gemeinsame Base-ID-Prüfung, bestätigten Schreibworkflow, Ergebnisprüfung und
kontrollierte Rückgabe. Deutsche Benutzeranleitung und Fehlerhilfe sind enthalten.
Keine neue Produktfunktion gegenüber dem akzeptierten main-Stand.

### Technischer Hinweis für Rückfragen von Symcon

Die Wartung übernimmt ausschließlich die vom Benutzer ausgewählte native
Gatewayverbindung vorübergehend und stellt sie kontrolliert wieder her. Dafür
werden zugeordnete Verbindungen/Konfigurationen geändert und eigene temporäre
Wartungsinstanzen verwendet. Eigene Sicherungs-/Journaldateien liegen im
Symcon-Verzeichnis; der serielle Besitznachweis liest Linux-Prozessmetadaten.
Dies vor einem späteren Stable-Review offenlegen und erforderliche Ausnahmen
mit Symcon klären; keine allgemeine Reviewkonformität behaupten.

## Persönlicher nächster Schritt und noch offene Portalpunkte

Der Aufruf von [account.symcon.de](https://account.symcon.de/) zeigt hier die
Anmeldung. Es wurde keine Anmeldung, OAuth-Verknüpfung, Registrierung oder
Einladung ausgeführt. Der konkrete Kontostatus und die Testing-Einladungsmaske
sind daher **nicht verifiziert**.

Thorsten muss sich anmelden und die konkreten Tester benennen. Danach im
**Entwicklerbereich → Modul hinzufügen** die vorbereitete Testing-Vorlage anlegen,
Bundle-ID prüfen, Daten übernehmen, Kategorie auswählen und Testing einreichen.
Einladungen ausschließlich für die gewählten Tester über die angebotene
Testing-Verwaltung ausführen. Welches Testerkennzeichen diese Maske verlangt,
ist erst nach Anmeldung zu prüfen; keine E-Mail-/Lizenzfelder erraten.
Ein funktionierender Einladungs- und Store-Installationsweg bleibt bis dahin offen.

Da das Repository öffentlich ist, wird keine zusätzliche GitHub-OAuth-Verbindung
allein zum Lesen dieses Repositorys vorausgesetzt. Eine bestehende Anmeldung
darf nicht durch Beschaffung oder Veröffentlichung von Zugangsdaten ersetzt werden.

## Normaler Benutzer-Installationsweg

Schon ohne Store: **Module → Repository hinzufügen**, öffentliche GIT-URL oben,
Branch `main`; dann Konfigurator anlegen, natives Gateway markieren und Manager
erstellen. Das ist der dokumentierte [Module-Control-Weg](https://www.symcon.de/de/service/dokumentation/modulreferenz/kern-instanzen/module-control/),
kein lokales Datei-Deployment. Die Schrittfolge steht in der
[Benutzeranleitung](bedienungsanleitung.md).

Thorsten hat diesen öffentlichen Installationsweg vor diesem Auftrag frisch und
erfolgreich manuell abgenommen. Die Repository-Erreichbarkeit und die relevanten
Installationsdaten wurden jetzt erneut geprüft. Kein erneuter Installationstest,
keine neue Managerinstanz und kein Zugriff auf Test- oder Produktivsystem in
diesem Vorbereitungsauftrag. Eine **Store-Testing-Installation** ist noch nicht
als getestet auszugeben.

Für eingeladene Tester zunächst empfehlen: Installation auf einem separaten
Testsystem, Gatewayauswahl, Auslesen, gültige/ungültige lokale Base-ID-Prüfung,
lokales Speichern und Wartung beenden. Ein Hardware-Write ist für diese ersten
Prüfungen nicht nötig. Rückmeldung: Modulstand, Symcon-Version, Anbindung,
Bedienungsschritt und Fehlermeldung; private Systemdaten nicht öffentlich teilen.

## Späterer Beta-Schritt

Nach ausgewertetem Testing-Feedback bewusst über eine öffentliche Beta entscheiden.
Das ist unsere Projektfolge, keine von Symcon vorgeschriebene Mindesttesterzahl
oder Wartezeit. Im Portal eine Beta-Vorlage aus dem Testing-Stand vorbereiten
oder den vorgesehenen Kanalwechsel nutzen; unveränderten Commit und deutsche
Beschreibung/Dokumentation kontrollieren. Erst nach Thorstens bewusster Freigabe
Beta einreichen. **Nicht nach Stable übertragen.**

Beta-Anwender nutzen den Module Store und dessen Beta-Auswahl;
[offizieller Installationsdialog](https://www.symcon.de/de/service/dokumentation/komponenten/verwaltungskonsole/module-store/).
Keine Änderung des Symcon-Server-Updatekanals als Voraussetzung behaupten.

## Abschlussstatus dieses Vorbereitungsstands

- Repositoryseitig für die Testing-Vorlage vorbereitet; Produktkandidat bleibt `f4ba803`.
- Offizielle Testing-Einreichung/Einladungen: offen hinter der persönlichen Anmeldung.
- Beta: Vorgehen und Texte vorbereitet, in diesem Auftrag nicht eingereicht.
- Stable: ausdrücklich nicht begonnen.
- Keine Produktänderung, kein CO_WR_IDBASE, kein realer Base-ID-Write und kein
  Verbrauch eines Hardware-Schreibzyklus.
