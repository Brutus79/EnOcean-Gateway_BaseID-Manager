# UART-Ownership und unterbrochene Rückgabe

## Sicherheitsentscheidung

Ein per `fork` vererbter Dateideskriptor verweist auf dieselbe offene
Dateibeschreibung. Er hält das Gerät aber weiterhin offen und kann grundsätzlich
zum Zugriff benutzt werden. PID/PPID, gleiche FD-Nummer und gleiche Flags beweisen
allein nicht, dass es sich um eine solche Vererbung handelt. Eine unabhängige
Öffnung durch einen Kindprozess bleibt damit ebenfalls möglich.

Deshalb bleibt die aktive Maintenance-Freigabe unverändert streng: genau ein
UART-Deskriptor beim Symcon-Prozess. Zusätzliche Deskriptoren werden nicht
zusammengezählt oder ignoriert. Die aktuelle Sitzung wird gesperrt; Bestätigungen
bleiben nicht aufgrund einer vermuteten Vererbung gültig. Kein Schreibpfad wird
durch die neue Diagnose oder Klassifikation freigegeben.

## Rückgabe

Nach dem Schließen des eigenen I/O muss die tatsächliche Deskriptorliste leer
sein. Erst dann dürfen die temporären Instanzen entfernt und die ursprüngliche
native Verbindung wiederhergestellt werden. Identitäts-, Snapshot-, Parent-,
Konfigurations- und Foreign-Child-Prüfungen bleiben unverändert.

Bei ausschließlich nachvollziehbaren Kindprozessen des Symcon-Prozesses kann
der bestehende Timer bis zu 30 Sekunden ab Beginn des Schließens weiter prüfen.
Das ist ein begrenztes Wartebudget, kein Schließnachweis. Unbekannte oder
unabhängige Prozesse sowie mehrere Symcon-Deskriptoren behalten die bisherige
3-Sekunden-Grenze. Die Kindprozessprüfung verwendet weder Programmnamen als
Allowlist noch Shell-Kommandos oder Prozessmanipulation.

Bei weiter bestehendem Fehler stoppt der Timer fail-closed. **Wartung beenden –
Rückgabe erneut prüfen** aktiviert ausschließlich die bereits bestehende
Rückgabe. Es entsteht keine neue Sitzung. Ist der UART mittlerweile frei,
werden die Restore-Prüfungen erneut durchlaufen. Dies gilt auch, wenn das Journal
bereits `RESTORED` meldet, der abschließende native Ownership-Nachweis jedoch
noch nicht gelungen ist. Auch dort bleibt genau ein Symcon-Handle erforderlich.

## Diagnose und Anzeige

Fehlgeschlagene Ownership-/Close-Prüfungen sichern im bestehenden privaten
Handoff-Journal bis zu acht unterschiedliche Beobachtungen pro Handoff:
Zeitpunkt, Anschluss, PID/FD, Flags und Prozessabstammung einschließlich PPID,
Startzeitkennung und ausführbarer Datei, soweit lesbar. Identische Beobachtungen
werden nicht bei jedem Timer-Tick erneut geschrieben. Unlesbare/verschwindende
Prozesse und instabile PID-Identitäten werden nicht als sichere Abstammung
gewertet. Vor Beginn eines erfassten Handoffs steht diese Journaldiagnose noch
nicht zur Verfügung.

Es werden keine Kommandozeilen oder Umgebungsvariablen gesammelt. Die Daten
bleiben lokal und dürfen nicht ungeprüft in öffentliche Fehlerberichte oder Git
übernommen werden. Die Diagnose ist kein vollständiger Traffic-Audit und kein
Nachweis für den historischen Ursprung einer bereits vergangenen Vererbung.

Nach `RETURNED` / `RESTORED` mit gültigem nativem Kontext zeigt der Manager
Status 102. Frühere Fehler bleiben gespeichert und werden als historische
Wartungsereignisse dargestellt. Eine unvollständige Rückgabe oder ein aktueller
Kontextfehler wird dadurch nicht als erfolgreich angezeigt.

## Begrenzung und Prüfung

Der Manager behebt nicht die fehlende Close-on-exec-Einstellung eines fremden
nativen Serial-I/O. Wiederkehrende Kindprozesse können daher weiterhin eine
aktive Wartung sperren. Das wird bewusst einer nicht belastbar nachgewiesenen
Exklusivität vorgezogen. Lang laufende oder nach ihrem Elternprozessende
reparentete Kindprozesse können eine manuelle erneute Rückgabeprüfung erfordern.

Die Änderung wurde hardwarefrei mit simulierten Handoffs, Ownership-Fehlern,
Kindprozessketten, begrenztem Warten, explizitem Retry und Status-/UI-Gegenproben
geprüft. Ein zusätzlicher Linux-Metadatentest verwendet ausschließlich einen
harmlosen `/dev/zero`-Deskriptor, ohne ihn zu lesen oder zu beschreiben. Es wurde
kein Test- oder Produktivsystem kontaktiert und kein Gateway-Kommando ausgeführt.

Referenzen: [Linux fork(2)](https://man7.org/linux/man-pages/man2/fork.2.html)
und [kcmp(2)](https://man7.org/linux/man-pages/man2/kcmp.2.html).
