# Paritätsmatrix: Legacy-Redeemer → Rewrite

Stand: Issue #2 (Work Package W0). Diese Matrix ist die verbindliche
Abnahmegrundlage für #5–#11. Eine Zeile gilt erst als erledigt, wenn ihr
benannter Test grün ist (Issue #10). Zeilen dürfen nicht stillschweigend
entfallen; Abweichungen stehen ausschließlich in Abschnitt 7.

## 1. Quellen und Methode

Referenz ist der Legacy-Stand `2cb614b` des PHP-Redeemers (Front Controller
`index.php`, Smarty-Templates `index.tpl`/`admin.tpl`/`error.tpl`, die Klassen
für Datenbank, Formulare, Tabellen, LDAP und Rechte, `verifymail.php`, die
Skripte unter `js/`, die HTTP-Suite `tests/e2e/test-local-web.py` und der
PostgreSQL-Schema-Dump). Gegengeprüft wurden:

| Quelle | Geprüft auf |
|---|---|
| Menü (`index.tpl`) | Hauptseite, Benutzerdaten, Nodes, VoIP (extern), MeetMe (auskommentiert), Phonebook (extern), Logout |
| Admin-Leiste (`admin.tpl`) | Benutzer anlegen, User-ID ändern, Suche, Impersonation-Anzeige |
| Section-/Action-Dispatch (`index.tpl`, `index.php`) | `member`, `nodes`, `devices`, `ip6`, `v642`, `pwdreset`, `voip`, `voip_sip`, `meetme`, `phonebook` |
| JavaScript | Formularvalidierung, GPS-Umrechnung, Lösch-/Rückgabe-/Ablehnungsdialoge, Mailboxprüfung per POST, Hilfetabellen |
| Mail-Links und Mails | Passwort-Reset-Mail, Zugangsdaten-Mail, `mailto:`-Links nach Übergabevorschlag |
| HTTP-Suite | Fixtures (`testadmin`, `testuser`) und geprüfte Abläufe |
| Schema | Funktionen (`insert*`, `update*`, `delete*`, `member_login`, `update_lastseen`), Constraints, Trigger, Views |

Der Legacy-Code, Dumps und Betriebsdaten werden hier bewusst nicht
übernommen; die Matrix beschreibt nur Verhalten und Verträge.

## 2. Legende

**Status**

| Status | Bedeutung |
|---|---|
| **Pflicht** | Immer sichtbar bzw. ausführbar; Paritätsnachweis zwingend |
| **bedingt** | Nur sichtbar/ausführbar unter genannter Bedingung (Rolle, Objektbezug, Daten); Bedingung ist Teil des Tests |
| **extern** | Link auf ein anderes System; nur Linkziel und Sichtbarkeit sind Vertrag |
| **ausgenommen** | Bewusst nicht Teil des Rewrites (Abschnitt 6) |

**Rollen**: *anon* (nicht angemeldet), *Mitglied* (angemeldet), *Superuser*
(Rolle id 2), *Admin* (Rolle id 1). Rollen werden über den Rollennamen
(`ADMIN_ROLE_NAME`, `SUPERUSER_ROLE_NAME`) und das niedrigste `id_roles` in
`members_roles` bestimmt: 1 = Admin, 2 = Superuser.
*Eigentümer* = `nodes.id_members`, *Tech-C* = `nodes.id_tech_c`;
Gerätebesitz folgt dem Node, IPv6 dem Node bzw. Gerät.

**Schreibweg**: *GET-W* = im Altsystem schreibend per GET (unsicher, im
Rewrite POST+CSRF, siehe A-1). *POST* = schon im Altsystem POST (`do=true`),
im Rewrite zusätzlich CSRF.

**Test-Spalte**: Benennung `Feature › Szenario`; Stack-Tests laufen mit PHPUnit (#3, siehe
[teststack.md](teststack.md)); das Werkzeug für Browser-Szenarien legt #4 fest, die Namen
bleiben davon unabhängig.
Die Szenarien sind in den Folge-Issues zu schreiben; die ID der Matrixzeile
steht als Tag (`@PAR-…`) am Szenario. „Altbestand“ nennt vorhandene Evidenz
in `tests/e2e/test-local-web.py` (Abschnitt 8); sie ersetzt den Rewrite-Test
nicht.

**Folge-Issues**: #5 Login/Session/Rollen/Startseite · #6 Mitglieder,
Rollendelegation, Impersonation, Reset · #7 Nodes, Standort, Tech-C,
Eigentumsübergabe · #8 Mailbox-Status Browser und CLI · #9 Geräte, IPv4,
IPv6, v642 · #10 Paritätsabnahme und Release · #11 Deployment und Rollback.

**ID-Schema**: `PAR-<Bereich>-<Nr>`; Bereiche `AUTH`, `NAV`, `HOME`, `MEM`,
`NODE`, `DEV`, `IP6`, `V642`, `MAIL`, `EXT`, `EXCL`. IDs sind stabil und
werden nie wiederverwendet.

## 3. Hauptmatrix

Alle Alt-URLs sind relativ zur Basis (`BASE`, Standard `/frontend/`;
produktiv vermutlich `/wien/`, siehe Abschnitt 9). Querystrings mit einem der
Zeichen `" ' < > # % { } | ^ ~ [ ] \`` ergeben im Altsystem 400.

### 3.1 Anmeldung, Sitzung, Navigation (#5)

| ID | Alt-URL / Methode | Rolle, Objektbezug | Erwartetes Ergebnis | Seiteneffekte (SQL / LDAP / Mail) | Issue | Test |
|---|---|---|---|---|---|---|
| PAR-AUTH-01 | `GET /` ohne Sitzung | anon | Login-Formular (Felder `nickname`, `password`, `CSRFtoken`), Cookie `CSRFtoken` (5 min) | – | #5 | `auth.feature › Login-Formular setzt CSRF-Cookie` |
| PAR-AUTH-02 | `POST /` (`nickname`, `password`, `CSRFtoken`) | anon | Erfolg: 301 auf die angeforderte URI, Header `X-Login-OK: User=<nick>; Id=<id>`, CSRF-Cookie gelöscht, Sitzung angelegt (siehe Abschnitt 4) | LDAP: Suche (genau 1 Treffer, `posixAccount`/`uid`), Bind als Benutzer. SQL: `members` mit `id>0 AND id=uidnumber`, `member_login(lower(nick))` setzt `last_login`, Rollen aus `members_roles` | #5 | `auth.feature › Browser-Login mit Legacy-Header` |
| PAR-AUTH-03 | `POST /` mit falschem Passwort | anon | Seite mit „User/Password missmatch“, Header `X-Login-Failed: User/Password error` | LDAP-Bind schlägt fehl; kein `member_login` | #5 | `auth.feature › Falsches Passwort` |
| PAR-AUTH-04 | `POST /` mit `nickname` und `password`, ohne Cookie/Feld `CSRFtoken` (ohne beide Felder nur das Login-Formular) | anon | 406 | – | #5 | `auth.feature › Login ohne CSRF-Token` |
| PAR-AUTH-05 | `POST /` mit `nickname`, `password` und abweichendem `CSRFtoken` | anon | 401 | – | #5 | `auth.feature › Login mit falschem CSRF-Token` |
| PAR-AUTH-06 | `POST /` mit Nutzer ohne SQL-Mitglied (LDAP-UID ≠ `members.id`) | anon | Anmeldung abgelehnt wie PAR-AUTH-03 | LDAP-Bind erfolgt, SQL-Abgleich schlägt fehl | #5 | `auth.feature › LDAP-Nutzer ohne Mitgliedssatz` |
| PAR-AUTH-07 | `GET /?logoff=true` | Mitglied | Sitzung zerstört, Redirect auf Login | – | #5 | `auth.feature › Logout` |
| PAR-AUTH-08 | Sitzungs-Cookie | alle | `SameSite=Strict`, `Secure` (abschaltbar über Konfiguration); Sitzung wird verworfen, wenn die gespeicherte URI nicht zur Basis passt | – | #5 | `auth.feature › Sitzungs-Cookie-Attribute` |
| PAR-AUTH-09 | `GET /?section=pwdreset` | anon | Formular: Nickname, falls nicht vorbelegt E-Mail statt Nickname; CSRF-Token | – | #6 | `reset.feature › Formular` |
| PAR-AUTH-10 | `POST /?section=pwdreset&action=reset` | anon; Treffer über Nickname oder `lower(email)`, `id>0` | Erfolg: „Password was reset. Check your mail …“. Mehr als ein Treffer oder keiner: Fehlermeldung ohne Änderung | LDAP: Passwort per Service-Konto (Filter `uidNumber`) neu gesetzt. Mail an Mitglied: „This is your new password:“ plus Portal-URL. Der Schalter `MEMBER_SUBSCRIBE_MAIL` betrifft diese Mail nicht | #6 | `reset.feature › Passwort zurücksetzen mit Mail` |
| PAR-AUTH-11 | Reset mit mehrdeutigem Treffer | anon | Fehler, kein LDAP-Schreibzugriff, keine Mail | – | #6 | `reset.feature › Mehrdeutiger Treffer` |
| PAR-NAV-01 | Menü „Hauptseite“ `GET /` | Mitglied | Startseite (HOME-*) | – | #5 | `nav.feature › Menü Hauptseite` |
| PAR-NAV-02 | Menü „Benutzerdaten“ `GET ?section=member&action=update` | Mitglied | Eigenes Profil (MEM-04) | – | #5 | `nav.feature › Menü Benutzerdaten` |
| PAR-NAV-03 | Menü „Nodes“ `GET ?section=nodes` | Mitglied | Node-Übersicht (NODE-01) | – | #5 | `nav.feature › Menü Nodes` |
| PAR-NAV-04 | Menü VoIP / Phonebook | Mitglied | Externe Links, siehe EXT-01/EXT-02 | – | #5 | `nav.feature › Externe Menülinks` |
| PAR-NAV-05 | Menü „Logout“ | Mitglied | `GET ?logoff=true`, siehe PAR-AUTH-07 | – | #5 | `nav.feature › Menü Logout` |
| PAR-NAV-06 | Admin-Leiste | Admin, Superuser | Zusatzleiste sichtbar: „Benutzer anlegen“, „User-ID ändern“, Suchformular; für Mitglieder unsichtbar | – | #5 | `nav.feature › Admin-Leiste nur für Admin und Superuser` |
| PAR-NAV-07 | Impersonation-Anzeige | Admin im fremden Kontext | Goldener Hintergrund, Nickname rot; Rückweg auf eigene Identität möglich | – | #6 | `nav.feature › Impersonation-Anzeige` |
| PAR-NAV-08 | Fußzeile | angemeldet | Zeigt Typ der Client-Adresse (IPv4/IPv6) | – | #5 | `nav.feature › Fußzeile Adresstyp` |

### 3.2 Startseite (#5)

| ID | Alt-URL / Methode | Rolle, Objektbezug | Erwartetes Ergebnis | Seiteneffekte | Issue | Test |
|---|---|---|---|---|---|---|
| PAR-HOME-01 | `GET /` | Mitglied | Mitgliedschaftsstatus aus Rollen 3 ordentlich, 4 außerordentlich, 5 kein, 6 fördernd, 7 Ehren; ohne Rolle „unbestimmt“ plus Link auf das Antragsformular | – | #5 | `home.feature › Mitgliedschaftsstatus` |
| PAR-HOME-02 | `GET /` | Rollen 3, 4, 7 (**bedingt**) | Zählung der Vereinsmitglieder sichtbar; sonst nicht. Die Altlogik wertet das niedrigste Rollenlabel aus (Mischfälle wie 3+5 oder 3+6 zeigen sie nicht); der Paritätstest legt das tatsächliche Verhalten fest | – | #5 | `home.feature › Mitgliederzahlen nur für Vereinsrollen` |
| PAR-HOME-03 | `GET /` | Mitglied | Eigene Daten (Name, Adresse, Telefon, E-Mail) mit Statussymbol der Mailboxprüfung | Browser ruft MAIL-01 auf | #5, #8 | `home.feature › Eigene Daten mit Mailstatus` |
| PAR-HOME-04 | `GET /` | Mitglied mit offenen Übergabevorschlägen (**bedingt**) | Hinweis mit Anzahl offener Owner-/Tech-C-Vorschläge an mich | – | #5, #7 | `home.feature › Hinweis auf offene Vorschläge` |
| PAR-HOME-05 | `GET /` | Mitglied mit Nodes | Statistik je Node/Gerät: online, wenn `last_seen` jünger als 4 h (nicht Mitternacht-Platzhalter); Spalte v4 immer, v6 nur wenn IPv6 vorhanden; Prozent grün >60, orange >30, sonst rot; Summenzeile bei mehr als einer Gruppe | – | #5 | `home.feature › Onlinestatistik` |
| PAR-HOME-06 | `GET /` | Mitglied ohne Nodes | „Du hast noch keine Knoten angelegt.“ | – | #5 | `home.feature › Keine Nodes` |

### 3.3 Mitglieder (#6)

| ID | Alt-URL / Methode | Rolle, Objektbezug | Erwartetes Ergebnis | Seiteneffekte | Issue | Test |
|---|---|---|---|---|---|---|
| PAR-MEM-01 | `GET ?section=member&action=insert` | Admin, Superuser (**bedingt**) | Formular Neuanlage | – | #6 | `members.feature › Anlageformular` |
| PAR-MEM-02 | `POST ?section=member&action=insert` (`do=true`) | Admin, Superuser | Mitglied angelegt, Erfolgsseite | Reihenfolge: LDAP-Add (uidNumber/gidNumber −1; leeres Passwort → 8 Zeichen generiert), SQL `insertmember(lower(nick), …)`, LDAP `uidNumber/gidNumber := members.id`, Mail „Frontend Zugangsdaten“ (Absender `MEMBER_SUBSCRIBE_MAIL_FROM`; abschaltbar mit `MEMBER_SUBSCRIBE_MAIL`) | #6 | `members.feature › Neuanlage mit LDAP, SQL, ID-Zuordnung und Mail` |
| PAR-MEM-03 | wie MEM-02, Zuordnung der LDAP-ID scheitert | Admin | Fehler „LDAP-ID konnte nicht zugeordnet werden“, **keine** Mail | LDAP/SQL-Satz bleibt zurück (Teilzustand, wird im Rewrite explizit gemeldet, A-7) | #6 | `members.feature › Fehler bei ID-Zuordnung ohne Mail` |
| PAR-MEM-04 | `GET ?section=member&action=update` | Mitglied (eigene Daten) | Formular mit eigenen Daten; Passwortfeld nur für die eigene ID; Namen nur für Admin änderbar | – | #6 | `members.feature › Eigenes Profil anzeigen` |
| PAR-MEM-05 | `POST ?section=member&action=update` | Mitglied (eigen) | Profil gespeichert | LDAP `update_member` (givenName, sn, mail, userPassword), SQL `updatemember(…)` nur nach LDAP-Erfolg | #6 | `members.feature › Eigenes Profil speichern` |
| PAR-MEM-06 | `GET/POST ?section=member&action=update&id=N` | Admin, beliebiges Mitglied | Vollformular inkl. Namen | wie MEM-05 | #6 | `members.feature › Admin ändert fremdes Profil` |
| PAR-MEM-07 | wie MEM-06 (`mini=true`) | Superuser, fremdes Mitglied (**bedingt**) | Eingeschränktes Formular: nur Telefon, Mobil, E-Mail änderbar | wie MEM-05, nur diese Felder; ein mitgesendetes Passwort wird im Altsystem ebenfalls nach LDAP (`userPassword`) geschrieben (Paritätstest legt fest, ob das nur für die eigene ID gilt) | #6 | `members.feature › Superuser ändert nur Kontaktfelder` |
| PAR-MEM-08 | Rollenfeld im Profil (`USER_ROLES`: `[Label, benötigte Rolle, Rollen-IDs]`) | Mitglied nur selbst; Admin beliebig (**bedingt**) | Checkbox bei einer Rolle, Auswahl bei mehreren; Nicht-Admins nur eigene Rolle; im Impersonationsmodus keine Rollen mit id > 2 | SQL `members_roles` ändern | #6 | `members.feature › Rollendelegation` |
| PAR-MEM-09 | Admin „Transform me to X“ (`transform=go`) | Admin, Zielmitglied | Sitzungsidentität wechselt, Redirect auf `?section=nodes`; Rückkehr beim Ziel = Ursprungs-ID | Sitzung: `original_id`/`nn`/`fn` | #6 | `members.feature › Impersonation und Rückkehr` |
| PAR-MEM-10 | „User-ID ändern“ (JS-Prompt) → `GET ?section=member&action=update&id=N` | Admin, Superuser | Springt auf Profil der ID | – | #6 | `members.feature › User-ID ändern` |
| PAR-MEM-11 | `GET ?section=member&action=delete&id=N` (GET-W) | Admin; Server verweigert bei Rollen 1–8 sowie bei eigenen/Tech-C-Nodes | Mitglied gelöscht | LDAP `delete_member`, danach SQL `deletemember` (löscht `members_roles`-Zeilen, dann `members`) | #6 | `members.feature › Löschen` |
| PAR-MEM-12 | wie MEM-11 mit Rolle 1–8 oder Node-Bezug | Admin | Abgelehnt mit Meldung, nichts gelöscht | – | #6 | `members.feature › Löschen verweigert` |
| PAR-MEM-13 | `POST ?section=member&action=search` (`search`, `ingroups`, `offgroups`, `limitresult`) | Admin, Superuser | 301 auf dieselbe URI (Suchparameter in der Sitzung); Treffer ab 3 Zeichen, numerischer ID oder Gruppenfilter | – | #6 | `members.feature › Suche mit Redirect` |
| PAR-MEM-14 | `GET ?section=member&action=search` | Admin, Superuser | Ergebnisliste; Limit 30 (`MAX_LIMITED`) bzw. 150 ohne Limit (`MAX_NOLIMIT`); Spalten u. a. Status, Admin-/Löschmarken, `#Nodes/#TechC`, geändert/letzter Login/angelegt; Admin-Suche trifft zusätzliche Spalten (u. a. Straße), Superuser-Suche nicht | – | #6 | `members.feature › Suchergebnisse` |
| PAR-MEM-15 | Löschsymbol in Suchliste | nur Admin, ohne Nodes/Tech-C, Status leer oder 5, id ≥ 2 (**bedingt**) | Symbol sichtbar nur unter diesen Bedingungen (reine UI-Bedingung; `id ≥ 2` wird nur hier geprüft, und bei Status 5 zeigt das UI das Symbol, obwohl der Server wegen Rolle 5 verweigert, siehe MEM-11/MEM-12) | – | #6 | `members.feature › Löschsymbol-Bedingungen` |
| PAR-MEM-16 | Gruppenfilter (`ingroups`/`offgroups`) | Admin, Superuser | Kommagetrennte Rollen-IDs filtern ein bzw. aus | – | #6 | `members.feature › Gruppenfilter` |
| PAR-MEM-17 | Clientvalidierung (`verifyForm`, `isValidEmail`) | Formulare | Nickname, Namen ≤ 25, Straße ≤ 100, Hausnummer, PLZ, Ort, Telefon/Mobil/Fax, E-Mail ≤ 50, Homepage `https?://`; Servervalidierung muss dieselben Regeln durchsetzen | – | #6 | `members.feature › Feldvalidierung` |
| PAR-MEM-18 | Zugriff auf fremdes Profil ohne Recht | Mitglied | Verweigert, keine Änderung; Superuser-Sonderfall: Fehlschlag der Mitgliedsprüfung wird still ignoriert (siehe U-2) | – | #6 | `members.feature › Fremdzugriff verweigert` |

### 3.4 Nodes (#7)

| ID | Alt-URL / Methode | Rolle, Objektbezug | Erwartetes Ergebnis | Seiteneffekte | Issue | Test |
|---|---|---|---|---|---|---|
| PAR-NODE-01 | `GET ?section=nodes` (optional `id_members`) | Mitglied; `id_members` nur mit Recht | Meine Nodes: Name (Link auf Update), Tech-C mit Rückgabe-/Vorschlagslink, Position (Dezimalgrad aus DMS), Kartenkennzeichen, Link auf Geräte, Löschsymbol nur bei 0 Geräten/IPv6, Vorschlagen oder ausstehend (Ablehnen), Node-ID dezimal/hex | – | #7 | `nodes.feature › Node-Übersicht` |
| PAR-NODE-02 | `GET ?section=nodes` | Tech-C eines fremden Nodes (**bedingt**) | Abschnitt „Nodes, bei denen ich Tech-C bin“ mit Rückgabe | – | #7 | `nodes.feature › Tech-C-Nodes` |
| PAR-NODE-03 | `GET ?section=nodes` | Empfänger eines Tech-C-Vorschlags (**bedingt**) | Abschnitt mit Annehmen/Ablehnen | – | #7 | `nodes.feature › Eingehende Tech-C-Vorschläge` |
| PAR-NODE-04 | `GET ?section=nodes` | Empfänger eines Owner-Vorschlags (**bedingt**) | Abschnitt mit Annehmen/Ablehnen | – | #7 | `nodes.feature › Eingehende Owner-Vorschläge` |
| PAR-NODE-05 | `GET ?section=nodes&action=insert` | Mitglied | Formular: Name, GPS (Grad/Minuten/Sekunden), Karte; JS wandelt Dezimal ↔ DMS | – | #7 | `nodes.feature › Anlageformular` |
| PAR-NODE-06 | `POST ?section=nodes&action=insert` (`do=true`) | Mitglied (wird Eigentümer und Tech-C) | Node angelegt; Name `[a-z0-9]([a-z0-9-]{0,23}[a-z0-9])?`, gültiger Domainname `<name>.wien.funkfeuer.at`, eindeutig ohne Groß/Klein | SQL `insertnode` (Eigentümer = Tech-C) | #7 | `nodes.feature › Node anlegen` |
| PAR-NODE-07 | `GET/POST ?section=nodes&action=update&id=N` | Eigentümer oder Tech-C (kein Admin-Sonderrecht) | Formular bzw. Speichern von Name, Koordinaten, Karte | SQL `updatenode` | #7 | `nodes.feature › Node ändern` |
| PAR-NODE-08 | `GET ?section=nodes&action=delete&id=N` (GET-W) | Eigentümer oder Tech-C; Node ohne Geräte/IPv6 | Node gelöscht samt Vorschlagszeile | SQL `deletenode` + Löschung der Vorschlagszeile (negierte ID) | #7 | `nodes.feature › Node löschen` |
| PAR-NODE-09 | wie NODE-08 mit Geräten oder IPv6 | Eigentümer | Kein Löschsymbol; direkter Aufruf wird abgelehnt (FK `RESTRICT`) | – | #7 | `nodes.feature › Löschen mit Abhängigkeiten verweigert` |
| PAR-NODE-10 | `GET/POST ?section=nodes&action=proposeOwner&id=N` (Feld `id_tech_c` = ID, E-Mail oder Nickname; Checkbox `stay_tech_c`, Standard an) | nur Eigentümer | Vorschlag angelegt; Erfolgstext mit `mailto:`-Link | Vorschlagszeile in `nodes` mit negierter ID. App versendet **keine** Mail | #7 | `nodes.feature › Eigentümerwechsel vorschlagen` |
| PAR-NODE-11 | `proposeTechC` analog | Eigentümer | Tech-C-Vorschlag angelegt | wie NODE-10 | #7 | `nodes.feature › Tech-C vorschlagen` |
| PAR-NODE-12 | `GET ?section=nodes&action=accept&id=N` (GET-W) | Empfänger des Vorschlags | Owner bzw. Tech-C übernommen; bei `stay_tech_c` bleibt bisheriger Eigentümer Tech-C | SQL Update `nodes`, Vorschlagszeile entfernt | #7 | `nodes.feature › Vorschlag annehmen` |
| PAR-NODE-13 | `GET ?section=nodes&action=decline&id=N` (GET-W) | Empfänger (im Altsystem zusätzlich Eigentümer/Tech-C des Nodes) | Vorschlag verworfen | Vorschlagszeile entfernt | #7 | `nodes.feature › Vorschlag ablehnen` |
| PAR-NODE-14 | `GET ?section=nodes&action=resign&id=N` (GET-W) | Tech-C | `id_tech_c := id_members` (Eigentümer) | SQL Update `nodes` | #7 | `nodes.feature › Tech-C-Rückgabe` |
| PAR-NODE-15 | `resign&id=N&p=1` | Eigentümer, ausstehender Vorschlag | Vorschlag zurückgezogen | Vorschlagszeile entfernt | #7 | `nodes.feature › Vorschlag zurückziehen` |
| PAR-NODE-16 | Bestätigungsdialoge (`askDelete`, `askResign`, `askDecline`) | alle Löschen-/Rückgabe-/Ablehnen-Aktionen | Rückfrage vor Ausführung; im Rewrite als Formular mit Bestätigung (A-1) | – | #7 | `nodes.feature › Bestätigungsdialog` |

### 3.5 Geräte, IPv4, IPv6, v642 (#9)

| ID | Alt-URL / Methode | Rolle, Objektbezug | Erwartetes Ergebnis | Seiteneffekte | Issue | Test |
|---|---|---|---|---|---|---|
| PAR-DEV-01 | `GET ?section=devices&id_nodes=N` | Eigentümer/Tech-C des Nodes | Tabelle der IPv4-Geräte inkl. v642 (blau), IPv6-Tabelle, Hilfetabellen per JS ein-/ausblendbar; Links `http://<ip>/` und `http://[<ip6>]/` | – | #9 | `devices.feature › Geräteübersicht` |
| PAR-DEV-02 | `POST ?section=devices&action=insert` (`do=true`) | Eigentümer/Tech-C | Gerät mit nächster freier IPv4 (Pool `main`) angelegt; Name-Regeln wie NODE-06, je Node eindeutig ohne Groß/Klein; Felder Name, Antenne, Hardware, SSID, MAC, `smokeping` (DNS-Flags und `nodemaster` gibt es nur bei Update, v642 und IPv6) | SQL `insertdevice`, `getnextfreeip` | #9 | `devices.feature › Gerät anlegen` |
| PAR-DEV-03 | `GET/POST ?section=devices&action=update&id=N` | Eigentümer/Tech-C | Gerät geändert | SQL `updatedevice` | #9 | `devices.feature › Gerät ändern` |
| PAR-DEV-04 | `GET ?section=devices&action=delete&id=N` (GET-W) | Eigentümer/Tech-C | Gerät gelöscht, IPv4 wieder frei; am Gerät gebundene IPv6-Zeilen bleiben am Node erhalten | SQL: zuerst `UPDATE ip6` (`id_devices` NULL, `id_nodes` = Node des Geräts), dann `deletedevice` (`ips.id_devices` auf NULL per FK) | #9 | `devices.feature › Gerät löschen` |
| PAR-DEV-05 | `POST ?section=devices&action=move` (`what=IPv4`, Ziel `id_nodes` aus eigenen Nodes) | Eigentümer/Tech-C von Quelle und Ziel | Gerät wechselt den Node, `nodemaster=false`; zugehörige IPv6 bleiben am alten Node | SQL Update `devices` | #9 | `devices.feature › Gerät verschieben` |
| PAR-DEV-06 | `GET ?section=devices&action=move6&id=N` (Formular), `POST action=move` mit `what=IPv6` | wie DEV-05 | IPv6-Eintrag wechselt den Node und löst sich vom Gerät; Adresse wird mit `checkIp6Range` geprüft | SQL Update `ip6` | #9 | `devices.feature › IPv6 verschieben` |
| PAR-DEV-07 | Verweise auf Smokeping | Geräte mit `smokeping=true` | Links `/olsr2/smokeping/?target=<node>.<device>` und `smokeping/?target=…`; bei v642 Suffix `__v4` (extern, siehe EXT-03) | – | #9 | `devices.feature › Smokeping-Links` |
| PAR-IP6-01 | `POST ?section=ip6&action=insert` | Eigentümer/Tech-C | IPv6-Eintrag an Node (`n-<id>`) oder Gerät (`d-<id>`); Adresse nach `checkIp6Range` (Modus 1 EUI-64 `2a02:61:0:ff:`, Modus 4 Userblock `2a02:61:<hex Node-ID>:`) | SQL `insertip6` (Adresse normalisiert per `abbrev`) | #9 | `ip6.feature › IPv6 anlegen` |
| PAR-IP6-02 | `POST ?section=ip6&action=update&id=N` | Eigentümer/Tech-C | Name, Flags, Antenne/Hardware/SSID/MAC, Zuordnung geändert; Adresse im UI gesperrt (Server akzeptiert sie im Altsystem, U-1) | SQL `updateip6` | #9 | `ip6.feature › IPv6 ändern` |
| PAR-IP6-03 | `GET ?section=ip6&action=delete&id=N` (GET-W) | Eigentümer/Tech-C | Eintrag gelöscht | SQL `deleteip6` | #9 | `ip6.feature › IPv6 löschen` |
| PAR-IP6-04 | Adresse außerhalb der erlaubten Bereiche | Eigentümer/Tech-C | Fehlermeldung, nichts gespeichert; eindeutige Adresse (Index `ip6_unique6`) | – | #9 | `ip6.feature › Adressbereichsprüfung` |
| PAR-IP6-05 | EUI-64-Hilfe (`js/eui64.js`) | Formular | Hilfsfunktion berechnet die Adresse aus MAC | – | #9 | `ip6.feature › EUI-64-Hilfe` |
| PAR-V642-01 | `GET ?section=v642&action=insert&id_nodes=N` | Eigentümer/Tech-C | Formular mit bis zu 5 zufälligen freien Pool-IPs | – | #9 | `v642.feature › Anlageformular` |
| PAR-V642-02 | `POST ?section=v642&action=insert` | Eigentümer/Tech-C; Limit `MAX_642` = 1 je Node, Admin ohne Limit (**bedingt**) | Gerät mit ausgewählter Pool-IP plus zwei IPv6-Adressen (`2a02:61:0:ee:0:ffff:…` als `s-<name>`, `2a02:61:0:ee:1:ffff:…`) | SQL `insertv642` (Prüfung, dass IP frei ist, sonst Ausnahme) | #9 | `v642.feature › v642 anlegen` |
| PAR-V642-03 | `POST ?section=v642&action=update&id=N` | Eigentümer/Tech-C | Name, Flags oder IP geändert; **IP-Wechsel erzeugt Gerät und IPv6 neu** (siehe A-5) | SQL `updatev642` | #9 | `v642.feature › v642 ändern` |
| PAR-V642-04 | `GET ?section=v642&action=delete&id=N` (GET-W) | Eigentümer/Tech-C | Gerät und zugehörige IPv6 gelöscht | SQL `deletev642` | #9 | `v642.feature › v642 löschen` |
| PAR-V642-05 | Limit überschritten | Nicht-Admin | Abgelehnt | – | #9 | `v642.feature › Limit` |

Rechte für Mitglieder-, Node-, Geräte- und IPv6-Zeilen folgen der
Altlogik `fetchMyStuff()`/`checkRights`: Mengen `members`, `owner`, `nodes`,
`devices`, `ip6`; Vorschläge zählen über die negierte Node-ID zu den eigenen
Nodes. Jede Zeile in 3.3–3.5 hat zusätzlich einen Negativtest (fremdes
Objekt → verweigert), der unter `@PAR-<ID>-neg` zu führen ist.

### 3.6 Mailbox-Status (#8)

| ID | Alt-URL / Methode | Rolle, Objektbezug | Erwartetes Ergebnis | Seiteneffekte | Issue | Test |
|---|---|---|---|---|---|---|
| PAR-MAIL-01 | `POST verifymail.php?id=…` (`m` = E-Mail, `i` = Element-ID); GET mit `m`, `i`, `f=1` wird ebenfalls akzeptiert | Mitglied (angemeldet), sonst `{"error":"login required"}` | JSON `{mail, code, formatvalidation, verbose, id, ca}` | SQL `verify` (Cache, Schlüssel `md5(email)`, `SMTP_DAYS`=30), Sitzungscache; **kein** Mailversand (nur `RCPT`) | #8 | `mailbox.feature › Statusabfrage im Browser` |
| PAR-MAIL-02 | Codeabbildung | – | 250 grün (`ca=250` Catch-All); 450/451/452/498 Greylisting = grün (498 = in Wartezeit 15 min); 503/530 orange; 421 Absender-Greylisting; 454/499/550/552/554 rot; 000 und 002 falsches Format; 994 kein MX; 995 inkorrekt; 996 keine Verbindung; 997/998 Verbindungsfehler; 999 unbekannt; 001 ausstehend (Browser fragt alle 2 s) | – | #8 | `mailbox.feature › Statuscodes` |
| PAR-MAIL-03 | Cachelogik | – | Sitzung: Ergebnis 5 min, „ausstehend“ 92 s; DB-Cache 30 Tage; Greylist-Codes werden nicht in `verify` gespeichert | SQL `verify` | #8 | `mailbox.feature › Caches` |
| PAR-MAIL-04 | SMTP-Ablauf | – | Port 25, danach 587; Catch-All-Probe mit `inval-email.test-verify@<domain>` | Netzwerk-Egress zu fremden MX (siehe Abschnitt 9) | #8 | `mailbox.feature › SMTP-Ablauf (Doppel)` |
| PAR-MAIL-05 | UI-Platzierung | Mitglied | Startseite (HOME-03), Profilformular mit `keyup`/`focusout`-Validierung, Suchliste (Prüfung bei Hover und für alle sichtbaren IDs) | – | #8 | `mailbox.feature › UI-Einbindung` |
| PAR-MAIL-06 | `php verifymail.php <email> <id> <debug> <forcesmtp>` (CLI) | Betrieb, kein Login | Ausgabe als JSON wie MAIL-01; getrackter Vertrag, siehe Abschnitt 5 | wie MAIL-01 | #8 | `mailbox_cli.feature › CLI-Ausgabe` |

## 4. Login-Verträge

### 4.1 Browser-Login (bestätigt)

* `POST /` und `POST /index.php` mit `nickname`, `password`, `CSRFtoken`.
* Erfolg: Antwort 301 auf die angeforderte URI **und** genau der Header

  ```
  X-Login-OK: User=<nickname>; Id=<members.id>
  ```

  Das Format ist zeichengenau (Semikolon plus ein Leerzeichen, keine
  Anführungszeichen) und Teil des Tests `auth.feature › Browser-Login mit
  Legacy-Header`.
* Fehler: Seite mit „User/Password missmatch“ plus `X-Login-Failed:
  User/Password error`; fehlender Token 406; falscher Token 401.

### 4.2 Sessionloser Aufrufer (unbestätigt)

Der Python-Nachfolger (`portal.funkfeuer.at`) bietet zusätzlich `POST
/api/login-check` (200 mit Header, 401, 503). Ob ein externer Aufrufer
(z. B. ein anderes System) den Header ohne Browser-Sitzung auswertet, ist
**nicht bestätigt**. Der Rewrite liefert vorerst nur den Browser-Vertrag
(4.1). Ein sessionloser Endpunkt wird erst nach Bestätigung durch den
Betrieb (Abschnitt 9, O-1) als eigene Zeile `PAR-AUTH-12` ergänzt; bis dahin
ist er **kein** Abnahmekriterium; die offene Frage blockiert aber den Cutover
(O-1).

## 5. Externe Links und CLI

| ID | Ziel / Aufruf | Sichtbarkeit | Vertrag | Issue | Test |
|---|---|---|---|---|---|
| PAR-EXT-01 | Menü „VoIP“ → `https://voip.funkfeuer.at/extensions/` | Mitglied | Linkziel unverändert, kein internes Rendering | #5 | `nav.feature › Externer Link VoIP` |
| PAR-EXT-02 | Menü „Phonebook“ → `https://voip.funkfeuer.at/phonebook/` | Mitglied | Linkziel unverändert | #5 | `nav.feature › Externer Link Phonebook` |
| PAR-EXT-03 | Smokeping (`/olsr2/smokeping/?target=<node>.<device>`, `smokeping/?target=…`, `__v4` bei v642) | Geräte mit `smokeping` | Linkziele unverändert, Daten liefert ein anderes System | #9 | `devices.feature › Smokeping-Links` |
| PAR-EXT-04 | Antragsformular (Link bei „unbestimmt“, HOME-01) | Mitglied ohne Rolle | Linkziel unverändert | #5 | `home.feature › Antragsformular-Link` |
| PAR-EXT-05 | Kopfzeile/Logo mit Symbolen von `portal.funkfeuer.at` | alle | Externe Bildreferenzen bleiben erhalten oder werden bewusst ersetzt (nicht abnahmerelevant) | #5 | `nav.feature › Kopfzeile` |
| PAR-EXT-06 | `php verifymail.php …` (MAIL-06) | Betrieb | Getracktes Mailbox-Prüf-CLI mit stabilem Aufruf- und JSON-Vertrag | #8 | `mailbox_cli.feature › CLI-Ausgabe` |

MeetMe ist im Menü auskommentiert und deshalb **kein** Link (siehe EXCL-01).

## 6. Ausnahmen (nicht Teil des Rewrites)

| ID | Gegenstand | Begründung | Bedingung für Neubewertung |
|---|---|---|---|
| PAR-EXCL-01 | Interne Schreibpfade `section=voip`, `voip_sip`, `meetme` samt `insert/update/delete`-Methoden und Tabellen | Menü verweist extern oder ist auskommentiert; die DB-Objekte fehlen im Schema-Dump; die VoIP-Abfragen wurden bereits aus den Rechtemengen entfernt (`5db7d2e`) | Nachweis, dass Objekte in Produktion existieren **und** ein aktiver Aufrufer sie nutzt (O-6) |
| PAR-EXCL-02 | `section=phonebook` intern | Nur externer Link aktiv | wie oben |
| PAR-EXCL-03 | Unversioniertes Erinnerungs-CLI `sendactivatemail.php` und `classes/mailtemp` | Nicht im Referenzstand versioniert; nicht Bestandteil des Rewrites und wird nicht ausgeliefert | Aktiver Vertrag wird belegt (O-4) |

## 7. Bewusste Abweichungen

| Nr. | Altverhalten | Rewrite | Betroffene IDs |
|---|---|---|---|
| A-1 | Löschen, Rückgabe, Annehmen, Ablehnen usw. per GET | Alle Schreibaktionen per POST mit CSRF-Token; alte GET-URLs lesen nur bzw. leiten auf die Bestätigungsseite | alle „GET-W“ |
| A-2 | Übergabeentscheidung (Annehmen/Ablehnen) auch durch Eigentümer/Tech-C des alten Nodes (`checkRights(-id)`) | Nur der Empfänger entscheidet; Eigentümer kann nur vorschlagen/zurückziehen | NODE-12, NODE-13, NODE-15 |
| A-3 | Impersonation: Rechte unklar vermischt | Admin behält Adminrechte, Objektrechte folgen der wirksamen Mitglieds-ID, keine Zielrollen, `USER_ROLES`-Sperre für Rollen-ID > 2 bleibt, Wechsel ist sichtbar und umkehrbar | MEM-08, MEM-09, NAV-07 |
| A-4 | Parameter teils per String-Verkettung in SQL (u. a. Gruppenfilter der Suche) | Alle Parameter gebunden; Gruppenfilter als validierte ID-Liste | MEM-14, MEM-16 |
| A-5 | v642-Änderung kann das Gerät samt IPv6 neu erzeugen und verliert Geräte-ID und `nodemaster` | Änderung bricht mit klarer Meldung ab, solange weitere IPv6-Einträge am Gerät hängen | V642-03 |
| A-6 | Alte Lese-URLs und Mail-Links | Nur ausgewählt abgebildet (Liste in #5/#7, für Nodes in Abschnitt 7.3; alles andere 404) | NAV-*, NODE-10 |
| A-7 | LDAP/DB-Teilfehler bleiben teils still (Teilzustand) | Teilfehler werden explizit gemeldet; keine Mail, wenn die LDAP-ID-Zuordnung scheitert (wie bisher) | MEM-02, MEM-03, MEM-05, MEM-11 |

Die Abweichungen betreffen Methode, Rechtekreis und Fehlerbehandlung, nicht
die Sichtbarkeit von Bedienfunktionen. Weitere Abweichungen gibt es nicht;
Altverhalten ohne Entscheidung (Abschnitt 7.1) wird bis zu einer Entscheidung
1:1 übernommen.

### 7.1 Altverhalten ohne festgelegte Rewrite-Entscheidung

| Nr. | Altverhalten | Vorgabe |
|---|---|---|
| U-1 | Server akzeptiert beim IPv6-Update eine geänderte Adresse, das UI sperrt das Feld (IP6-02) | Paritätstest legt das tatsächliche Altverhalten fest; Änderung nur per Entscheidung im PR von #9 |
| U-2 | Superuser: Fehlschlag der Mitgliedsrechteprüfung wird still ignoriert (MEM-18) | **Entschieden in #6:** Felder oder Rollengruppen außerhalb des erlaubten Satzes ergeben 403, nichts wird still verworfen (`mini` und `_csrf_token` sind erlaubt) |
| U-3 | Vorschlag wird als `nodes`-Zeile mit negierter ID gespeichert (NODE-10/11) | **Entschieden in #7:** Die Speicherform bleibt unverändert (Abschnitt 7.3), damit Alt- und Neusystem dieselben Zeilen lesen; das DB-Schema bleibt kompatibel (O-8) |

### 7.2 Umsetzung in #6 (Mitglieder, Delegation, Impersonation, Reset)

Die Matrixzeilen AUTH-09..11, NAV-07 und MEM-01..18 sind mit PHPUnit-Tests gegen den echten Stack belegt
(`tests/Http/{MemberCreateTest,MemberProfileTest,MemberSearchTest,MemberDeleteTest,ImpersonationTest,PasswordResetTest}`,
Teilfehler in `MemberAccountsFailureTest`); die `Feature › Szenario`-Namen der Spalte „Test“ bleiben Platzhalter für #10.
Festlegungen, die über die Matrix hinausgehen:

- **HTTP-Codes:** 200 Erfolg; 422 Validierung/Vorbedingung (Formular mit Werten und Fehlern); 409 Löschen nicht möglich
  (Rollen 1–8, Nodes/Tech-C inkl. Vorschlagszeilen mit negativer ID, eigenes oder imitiertes Konto, ); 403
  fehlendes Recht oder ungültiges CSRF-Token; 404 unbekannte ID; 502 mit `data-status="failed|partial"` bei Backend-Fehlern.
- **Reihenfolge und Teilfehler (A-7):** Validierung und Rechte vor jedem Schreibzugriff, dann LDAP, dann SQL. Bleibt nach einem
  Fehler ein Teil bestehen (Anlegen: LDAP-Konto ohne Datenbankzeile oder ohne ID; Ändern; Löschen; Reset ohne Mail),
  meldet die Seite `partial` und nennt den Zustand; es gibt kein stilles Zurückrollen und keine Wiederholung. Beim Anlegen
  entfällt die Mail, wenn die ID-Zuordnung scheitert.
- **Löschen ohne LDAP-Konto** (z. B. `testsqlonly` oder nach einem Teilfehler) überspringt den LDAP-Schritt und löscht nur
  die Datenbankzeile; so lässt sich ein Teillösch-Zustand über die Oberfläche abschließen.
- **Reset-CSRF:** Cookie `CSRFreset` plus Formularfeld `CSRFtoken` (Token-ID `password_reset`, einmalig). Das
  Legacy-Cookie `CSRFtoken` wird nicht verwendet, damit ein offenes Login-Formular nicht überschrieben wird.
  Fehlt Cookie oder Feld: 406; Abweichung: 401. Angemeldete erhalten für `pwdreset` 404 (GET) bzw. 405 (POST).
  Es gibt **kein Rate-Limit**; das ist ein offener Betriebspunkt.
- **Impersonation:** Sitzungsschlüssel `impersonated_member_id`; Rechte folgen der Ursprungsrolle, Objektbezug der wirksamen
  ID, nur für Admins (A-3). Der Wechsel leitet nach `?section=nodes` (seit #7 vorhanden).
- **Superuser und E-Mail (MEM-07), gewollt:** Ein Superuser darf die E-Mail jedes Mitglieds ändern, etwa wenn eine Adresse
  dringend korrigiert werden muss. Das beruht auf Vertrauen und Sanktionen unter Menschen, nicht auf einer technischen
  Sperre; eine Rollenprüfung des Zielmitglieds wird bewusst nicht ergänzt.
- **Mailstatus:** `data-mail-status`-Platzhalter bleibt für #8 offen.
- **Konfiguration:** `USER_ROLES` (JSON), `MEMBER_SUBSCRIBE_MAIL`, `MAIL_FROM_NAME`, `PORTAL_URL` (siehe teststack.md). Ob der
  Standardwert von `USER_ROLES` der Produktion entspricht, ist offen (O-9), ebenso Mailrelay (O-5) und LDAP-ACLs (O-2).

### 7.3 Umsetzung in #7 (Nodes, Standort, Tech-C, Eigentumsübergabe)

Die Matrixzeilen NODE-01..16 und HOME-04 sind mit PHPUnit-Tests gegen den echten Stack belegt
(`tests/Http/{NodeOverviewTest,NodeCrudTest,NodeHandoverTest}`, Sperren und Transaktionen in `NodeWriteTest`); die
Matrix-ID steht als Kommentar am Test, die `Feature › Szenario`-Namen bleiben Platzhalter für #10. Geschrieben wird
ausschließlich über `insertnode`, `updatenode`, `deletenode` und, wie im Altsystem, direkt in `nodes` (Vorschlag,
Annahme, Rückgabe). Festlegungen, die über die Matrix hinausgehen:

- **Rechte:** Eigentümer und Tech-C ändern und löschen einen Node und geben die Tech-C-Rolle zurück. Nur der Eigentümer
  schlägt vor und zieht zurück; nur der Empfänger nimmt an oder lehnt ab (A-2). Admins haben kein Sonderrecht; beim
  Identitätswechsel gilt die wirksame Mitglieds-ID (A-3). `id_members` an der Liste ist nur für die eigene ID erlaubt
  (sonst 403). Ein beim Anlegen mitgesendetes `id_members` wird ignoriert: Eigentümer und Tech-C ist immer das
  anlegende Mitglied. Löschen bietet die Oberfläche wie bisher nur dem Eigentümer an.
- **Positive und negative IDs:** `id` in der URL ist immer eine positive Node-ID; alles andere ergibt 404, auch für
  Mitglieder, die in einer Vorschlagszeile stehen. Ein Vorschlag wird nur über seinen Node angesprochen und nie als
  Node gelistet, geändert oder gezählt. (Das Altsystem ließ Vorschlagszeilen über ihre negative ID ändern und löschen.)
- **Speicherform des Vorschlags (U-3), unverändert:** Zeile in `nodes` mit `id` = −Node-ID, `name` = diese ID als Text,
  `map` = false, Koordinaten leer. Tech-C-Einladung: `id_members` = Eigentümer, `id_tech_c` = Empfänger.
  Eigentümerwechsel: `id_members` = Empfänger, `id_tech_c` = bisheriger Eigentümer (`stay_tech_c`) oder Empfänger.
  Je Node gibt es höchstens einen Vorschlag; ein neuer ersetzt den alten (wie bisher, jetzt mit Hinweis).
- **Veraltete Vorschläge:** Die Bestätigungsseite trägt die Fassung des Vorschlags (Art, Empfänger, künftiger Tech-C).
  Annehmen, Ablehnen und Zurückziehen wirken nur, wenn die gesperrte Zeile noch dieser Fassung entspricht; sonst 409
  ohne Schreibzugriff. Eine Zeile, die nicht zum aktuellen Eigentümer passt, gilt als kein Vorschlag.
- **Transaktion:** Jede Schreibaktion sperrt zuerst den Node (`SELECT … FOR UPDATE`), prüft Rolle, Vorbedingungen und
  Vorschlag und schreibt in derselben Transaktion (Konvention für #9 in [teststack.md](teststack.md)).
- **HTTP-Codes:** 200 Erfolg; 422 Eingabefehler (Formular mit Werten); 409 Vorbedingung verletzt (Löschen mit Geräten
  oder IPv6, kein eigener Tech-C, Vorschlag fehlt oder wurde ersetzt); 403 fehlendes Recht oder ungültiges CSRF-Token;
  404 unbekannte oder nicht positive ID; 502 mit `data-status="failed"` bei Datenbankfehlern (nichts geändert).
- **URLs (A-1, A-6):** Alle alten Node-URLs bleiben erhalten: `?section=nodes[&id_members=N]` und
  `&action=insert|update|delete|resign|accept|decline|proposeOwner|proposeTechC[&id=N]`. GET liest nur; für `delete`,
  `resign`, `accept` und `decline` zeigt GET die Bestätigungsseite (ersetzt `askDelete`/`askResign`/`askDecline`),
  geschrieben wird per POST mit CSRF-Token. Zurückziehen ist die neue Aktion `withdraw`; die alten Eigentümer-Links
  `resign&id=N&p=…` und `decline&id=N&p=…` führen auf ihre Bestätigungsseite. `?section=devices&id_nodes=N` folgt in #9.
- **Empfänger eines Vorschlags:** Ziffern = Mitglieds-ID, mit `@` = E-Mail (ohne Groß/Klein), sonst Nickname; nur
  `id > 0`. Abweichend vom Altsystem: Gehört eine E-Mail zu mehreren Mitgliedern, wird abgelehnt (bisher zufälliger
  Treffer); eine negative Zahl wird nicht mehr in eine ID umgedeutet; Vorschläge an den Eigentümer selbst oder an den
  bereits eingetragenen Tech-C werden abgelehnt (bisher entstanden wirkungslose Zeilen). Die App versendet keine Mail.
- **Name und Koordinaten:** Name wie im Altsystem ohne Beachtung von Groß/Klein geprüft und wie eingegeben gespeichert;
  die Regel ergibt immer einen gültigen Domainnamen. Jedes Koordinatenfeld ist einzeln optional (wie bisher), Komma
  oder Punkt, Grenzen wie in `verifyForm`; keine Zahl ergibt 422 statt eines Datenbankfehlers. Die Liste zeigt eine
  Position nur mit Grad ≠ 0, Minuten und Sekunden (wie bisher). Dezimal ↔ Grad/Minuten/Sekunden rechnet
  `assets/nodes.js` im Browser um; die Tests prüfen nur, dass Formular und Skript ausgeliefert werden (NODE-05), die
  Umrechnung selbst ist nicht automatisiert geprüft.
- **Annahme eines Eigentümerwechsels** setzt den Tech-C wie im Altsystem neu (neuer oder bisheriger Eigentümer); ein
  davor eingetragener dritter Tech-C entfällt. Die Tech-C-Rückgabe lässt einen offenen Vorschlag unberührt.
- **Startseite (HOME-04):** Gezählt werden die Vorschläge, die auch die Node-Seite dem Mitglied zur Entscheidung zeigt.
  Das Altsystem übersah Eigentümerwechsel ohne `stay_tech_c`; der Hinweis verlinkt jetzt auf die Node-Seite.
- **Offen (O-12):** Nach einem Vorschlag zeigt die Erfolgsseite wie bisher Name und E-Mail des Empfängers. Wer einen
  Node besitzt, kann so zu jeder ID oder jedem Nickname Name und E-Mail abfragen.

## 8. Vorhandene Evidenz im Altbestand

`tests/e2e/test-local-web.py` (HTTP gegen den lokalen Docker-Stack, Basis
`http://127.0.0.1:8088/frontend/`, Mailpit auf 8028) ist eine einzelne
Skriptdatei ohne benannte Testfunktionen. Sie belegt, ohne den Rewrite-Test
zu ersetzen:

| Ablauf im Skript | Berührte IDs |
|---|---|
| Login für `testadmin` und `testuser` | AUTH-02 |
| Lesezugriffe auf feste Seitenliste | HOME-*, MEM-*, NODE-01, DEV-01 |
| Mailboxprüfung inkl. Cache | MAIL-01, MAIL-03 |
| Admin legt Mitglied an, LDAP-Login des neuen Mitglieds, Reset mit Mailpit-Mail, Löschung | MEM-02, MEM-11, AUTH-10 |
| `testuser` ändert Profil (Ort) | MEM-05 |
| Node, Gerät, IPv6 anlegen, ändern, löschen | NODE-06–08, DEV-02–04, IP6-01–03 |

Lücken der Fixtures (müssen in den Folge-Issues ergänzt werden): kein
Superuser, kein v642-Pool, keine Übergabevorschläge, kein Impersonation-Fall,
keine Negativfälle. Seed: Rollen Admin (1) und Superuser (2); Mitglieder
`testadmin` (1, Admin) und `testuser` (2); Node 1 (Eigentümer und Tech-C = 2);
Gerät 1; IPv4-Pool 192.0.2.100–110 (`main`); IPv6 `2001:db8::10`. Legacy-
Unit-Tests (`ConvertUtilsTest`, `DatabaseUtilsTest`, `FormsTest`) decken
Hilfsfunktionen, keine Abläufe.

## 9. Offene Betriebsfakten und Cutover-Punkte

Diese Punkte sind **nicht geraten**, sondern müssen vom Betrieb bestätigt
werden. Wo „Blocker“ steht, darf #11 nicht ohne Antwort in Produktion gehen.

| Nr. | Frage | Folge | Blocker |
|---|---|---|---|
| O-1 | Welche externen Aufrufer werten `X-Login-OK` ohne Browser-Sitzung aus? | Ob `PAR-AUTH-12` nötig ist | Ja |
| O-2 | Welches LDAP-Produkt, welches TLS-Verfahren und welche ACLs gelten produktiv (Service-Konto: Suche, Passwort ändern, anlegen, löschen, ID setzen)? | Konfiguration #3/#11, Doppel in Tests | Ja |
| O-3 | Effektiver Basispfad (`frontend` vs. `/wien/`) und Reverse-Proxy-Vertrag | Routing, Cookie-Pfad, Mail-Links | Ja |
| O-4 | Läuft `sendactivatemail.php`/`mailtemp` noch (Cron)? | Ob EXCL-03 bleibt; Betrieb vor Abschalten informieren | Nein |
| O-5 | MTA/Relay, Absenderadresse und Egress (Port 25/587 zu fremden MX) für Reset-/Zugangsmails und MAIL-04 | Mailversand und Mailboxprüfung | Ja |
| O-6 | Existieren VoIP-/MeetMe-/SIP-Objekte in Produktion, und wer nutzt sie? | Neubewertung EXCL-01/02 | Nein |
| O-7 | Wer setzt im alten Produktionsablauf die LDAP-IDs (Zuordnung `uidNumber := members.id`)? Im Legacy-PHP `LDAP::assign_member_id` direkt nach `insertmember`; das Portal (portal.funkfeuer.at) lässt `-1` stehen. Zu klären: Ob ein externer Abgleich existiert. Die Neuimplementierung muss die ID schreiben. | MEM-02/03 | Nein |
| O-8 | Reale DB-Grants, Verbraucher der Views (`f_dns`, `r_dns`, `smokeping`, `smokeping6`) und Cron `update_lastseen` | Schema darf nicht verändert werden, Rolle der App-Rechte | Ja |
| O-9 | Existiert die Rolle Superuser produktiv, und wer hält sie? | MEM-07 und Testdaten | Nein |
| O-10 | Rollback-Stand: Datenbank- und LDAP-Schreibzugriffe nach Cutover und Rückweg | #11 | Ja |
| O-11 | Welche alten Lese-URLs und Mail-Links müssen bestehen bleiben (A-6)? | Weiterleitungsliste | Nein |
| O-12 | Soll ein Übergabevorschlag weiterhin Name und E-Mail eines beliebigen Mitglieds anzeigen (`mailto:`-Link, NODE-10/11)? | Erfolgstext der Vorschlagsseite | Nein |

## 10. Abnahmeregel für #10 (Vorschlag)

Vorschlag zur Abstimmung in #10; nicht Teil der Vorgaben aus Issue #2.

1. Jede Pflicht-/bedingt-Zeile aus 3.1–3.6 und 5 hat einen grünen benannten
   Test; Negativtests für 3.3–3.5 sind enthalten.
2. Jede Abweichung aus Abschnitt 7 hat einen Test, der das **neue** Verhalten
   festschreibt.
3. Zeilen aus Abschnitt 6 haben keinen Test, aber der Release-Text nennt sie.
4. Offene Punkte aus Abschnitt 9 mit „Blocker: Ja“ sind beantwortet und in
   dieser Datei nachgetragen, bevor #11 startet.
5. Änderungen an der Matrix erfolgen nur im selben PR wie die zugehörige
   Funktionsänderung.
