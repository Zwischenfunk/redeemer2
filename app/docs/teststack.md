# Rewrite: lokaler Teststack und Konfiguration

Gilt für Issue #3. Der Stack ist vom Legacy-Stack unabhängig: eigener Compose-Projektname
`redeemer-rewrite`, eigene Volumes, eigener Portbereich `184xx`, kein Zugriff auf Legacy-Daten
oder Produktion. Alle Werte und Daten sind synthetisch.

## Befehle

| Befehl | Wirkung |
|---|---|
| `make rewrite-up` | Baut das PHP-8.4-Image, installiert `composer.lock` nach `app/vendor/` (eigener Container mit Netz), startet nginx/PHP-FPM, PostgreSQL 13, OpenLDAP und Mailpit und wartet auf „healthy“. |
| `make rewrite-test` | Startet bei Bedarf den Stack und führt die komplette PHPUnit-Suite aus (`stack` + `http`). |
| `make rewrite-probe` | Nur die Suite `stack`: Seeds, DB, LDAP, Mailpit, kein Egress. Das ist die Probe für den späteren CI-Stack. |
| `make rewrite-lint` | `composer validate --strict`, PHP-/Twig-/YAML-Lint, PHPStan (Level 6, `app/phpstan.neon.dist`) und actionlint (Image per Digest gepinnt). |
| `make rewrite-audit` | `composer audit --locked` gegen das Lockfile (braucht Internet, läuft im Container `composer`). |
| `make rewrite-qa` | Alles der CI: `rewrite-lint`, `rewrite-audit`, `rewrite-test`. |
| `make rewrite-down` | Stoppt den Stack und löscht seine Volumes. Der nächste Start ist frisch. |

Erreichbar auf dem Host (nur `127.0.0.1`): App `http://127.0.0.1:18480/wien/` (`REWRITE_HTTP_PORT`),
Mailpit-Oberfläche `http://127.0.0.1:18481/` (`REWRITE_MAIL_UI_PORT`). DB und LDAP sind nicht veröffentlicht.

## Aufbau und Isolation

* `app/` ist der Symfony-Baum (PHP 8.4, Symfony 7.4 LTS, Twig, Doctrine DBAL ohne ORM, Composer-Lockfile).
  nginx bekommt nur `app/public/` eingebunden; `config/`, `src/`, `templates/` und `vendor/` liegen nicht im HTTP-Baum.
  Assets liegen in `app/public/assets/` und werden ohne Node-Build ausgeliefert.
* nginx bildet `/wien/` auf den Front Controller ab (`SCRIPT_NAME=/wien/index.php`). `/wien/index.php` ist
  der belegte Browser-Alias, kein zusätzlicher PHP-Einstiegspunkt. Generierte Links bleiben kanonisch
  unter `/wien/`; Varianten wie `/wien/index.php/extra` und interne Dateien bleiben gesperrt.
* Netz `stack` ist `internal: true` (kein Gateway). Das PHP-Image, die Tests und alle Dienste liegen nur dort;
  weder reale MX-Server noch reale LDAP-Server oder öffentliches DNS sind erreichbar (`NoEgressTest`).
  Mails gehen per SMTP an Mailpit (`smtp://mail:1025`), Mailpit hat kein Relay. Nur nginx hängt zusätzlich am Netz `edge`
  für die Host-Ports. Nur der Container `composer` hat Internet, nicht aber Zugriff auf DB, LDAP oder Mail.
* Produktiv bleibt nginx/PHP-FPM; das Image `docker/rewrite/php/Dockerfile` zeigt die benötigten Extensions (`pdo_pgsql`, `ldap`, `intl`).

## Konfiguration (Umgebungsvariablen)

Es gibt keine versionierten Settings-Dateien mit Geheimnissen; die App liest nur die Umgebung. PHP-FPM
reicht sie mit `clear_env = no` durch. Die Beispielwerte stehen in `compose.rewrite.yaml`.

| Variable | Bedeutung | Beispiel (synthetisch) |
|---|---|---|
| `APP_ENV` | `prod` (Standard) oder `test` | `prod` |
| `APP_DEBUG` | `0`/`1` | `0` |
| `APP_SECRET` | App-Secret, ohne Standardwert (Start schlägt ohne ihn fehl) | `rewrite-test-only-secret-not-for-production` |
| `APP_CACHE_DIR`, `APP_LOG_DIR` | Cache-/Log-Verzeichnis (optional, Standard `var/`) | `/var/cache/redeemer` |
| `DATABASE_URL` | DBAL-DSN, `postgresql://…?serverVersion=13&charset=utf8` | `postgresql://redeemer:rewrite-test-only@db:5432/redeemer?serverVersion=13&charset=utf8` |
| `LDAP_URI` | LDAP-Server | `ldap://ldap:389` |
| `LDAP_ENCRYPTION` | `none`, `tls` (StartTLS) oder `ssl` | `none` |
| `LDAP_BASE_DN` | Basis-DN der Benutzer | `ou=users,dc=funkfeuer,dc=at` |
| `LDAP_SERVICE_DN`, `LDAP_SERVICE_PASSWORD` | Dienstkonto für Suche und spätere Schreibzugriffe | `cn=admin,dc=funkfeuer,dc=at` / `rewrite-test-only` |
| `MAILER_DSN` | Symfony-Mailer-Transport | `smtp://mail:1025` (Mailpit) |
| `MAIL_FROM` | Absenderadresse | `redeemer@example.test` |
| `MAIL_FROM_NAME` | Absendername der Mails (optional) | `Funkfeuer Redeemer` |
| `PORTAL_URL` | Link in Zugangsmails | `https://portal.example.test/wien/` |
| `MEMBER_SUBSCRIBE_MAIL` | `1`: Willkommensmail beim Anlegen (die Reset-Mail ist unabhängig) | `1` |
| `USER_ROLES` | JSON `{"schlüssel": [Label, benötigte Rolle, "Rollen-IDs"]}` für die Rollenfelder im Profil; Standard: Admin und Superuser | siehe `compose.rewrite.yaml` |
| `SESSION_COOKIE_SECURE` | Standard `1` (produktiv); `0` nur lokal ohne TLS | `0` |
| `SESSION_COOKIE_PATH` | Cookie-Pfad, entspricht dem URL-Basispfad | `/wien/` |
| `SESSION_SAVE_PATH` | Verzeichnis der PHP-Sessions | `/var/lib/redeemer/sessions` |

Nur die Tests zusätzlich: `HTTP_BASE_URL` (`http://nginx/wien`), `MAILPIT_URL` (`http://mail:8025`).

## Browser-Identität und Berechtigungen (#5)

`App\Security\MemberIdentity` ist der Symfony-User hinter `#[CurrentUser]` bzw. `Security::getUser()`.
`originalId` identifiziert das angemeldete Konto; `effectiveId` ist die ID für Profil, Eigentümer- und
Tech-C-Abfragen. Beide IDs sind gleich, außer ein Admin
imitiert ein Mitglied (#6, `App\Security\Impersonation`, nur für `ROLE_ADMIN`). `originalRoleIds` enthält nur SQL-Rollen des ursprünglichen
Kontos; `getUserIdentifier()` liefert dessen ID als String. `MemberProvider` liest die Originalidentität
bei der Session-Wiederaufnahme neu; Änderungen der privilegierten Rolle verlangen einen neuen Login.

Controller prüfen `ROLE_MEMBER`, `ROLE_ADMIN` oder `ROLE_SUPERUSER` serverseitig. Bei beiden
privilegierten SQL-Rollen gewinnt ID 1 wie im Referenzstand; gemeinsame Aktionen prüfen Admin **oder**
Superuser. Fachliche Objektberechtigungen prüfen zusätzlich die effektive ID; ein Menüpunkt ist kein
Berechtigungsnachweis. Mitglieder-/Identitätswechsel (#6), Nodes (#7), Mailboxstatus (#8) und Geräte (#9)
nutzen diesen Vertrag; Mitglieder und Identitätswechsel gibt es seit #6, die übrigen Aktionen folgen.

Der Browser-Login verwendet die an die Session gebundene Formular-CSRF-Prüfung zusätzlich zum
Legacy-Cookie `CSRFtoken`. Nur nach LDAP- und SQL-Abgleich wird `member_login` ausgeführt und der
`X-Login-OK`-Redirect gesendet. Die Session-ID wird beim Login erneuert; der Cookie `redeemer_wien`
übernimmt keine Legacy-Sessions. `POST /wien/?logoff=true` mit separatem CSRF-Token meldet ab,
`GET` meldet nicht ab. Es gibt keinen sitzungslosen Login.

## Mutierende Tests (#6)

Tests, die Mitglieder ändern, erweitern `tests/Http/HttpStackTestCase`: Es sichert `members`, `members_roles`, die
Sequenz `members_id_seq` und alle LDAP-`posixAccount`-Einträge und stellt sie in `tearDown` wieder her; Mailpit wird
vorher geleert. `nodes` wird nicht wiederhergestellt: Tests dürfen sie nicht ändern, weil der Trigger `changed` setzt und
`SeedDigestTest` bricht. Kein Seed-Mitglied ist löschbar (Mitglied 6 ist Tech-C des Vorschlags −1); Löschtests legen
daher ein neues Mitglied an. `tests/Http/Browser` ist ein Cookie-Client, Formulare tragen `data-form="…"` und `_csrf_token`.

## Seeds und Fixture-IDs

Zwei frische Starts liefern identische Daten: feste IDs und Zeitstempel, Seeds laufen ohne Trigger
(`session_replication_role = replica`), LDAP-Passwörter stehen im Klartext im Seed. `SeedDigestTest` prüft
die SHA-256-Summen über alle Seed-Tabellen und alle LDAP-Einträge; alle schreibenden Tests rollen zurück.
Nach bewussten Seed-Änderungen die neuen Summen aus der Fehlermeldung übernehmen.

Passwort aller LDAP-Konten: `rewrite-test-only`.

| Mitglied (`members.id`) | Nickname | Rollen (`roles.id`) | LDAP | Nodes |
|---|---|---|---|---|
| 1 | `testadmin` | 1 Admin, 3 | `uidNumber` 1 | – |
| 2 | `testsuper` | 2 Superuser, 3 | 2 | – |
| 3 | `testuser` | 3 | 3 | Eigentümer von 1 `alpha` (Tech-C 3) und 3 `gamma`; Vorschlag -1 |
| 4 | `testowner2` | 4 | 4 | Eigentümer von 2 `beta`; Tech-C von 3 und von Vorschlag -2 |
| 5 | `testtech` | 3 | 5 | Tech-C von 2; Eigentümer und Tech-C von 4 `delta` |
| 6 | `testplain` | 32 (Mitgliedsantrag vorhanden) | 6 | eingeladener Tech-C im Vorschlag -1 |
| 7 | `testhonor` | 7 | 7 | künftiger Eigentümer im Vorschlag -2 |
| 8 | `testfriend` | 6 | 8 | – |
| 9 | `testsqlonly` | 9 (DeleteMe) | **kein** LDAP-Konto | – |
| – | `testldaponly` | – | `uidNumber` 900, **kein** Mitgliedssatz | – |
| – | `testpending` | – | `uidNumber` -1 (Teilanlage) | – |

Rollen-IDs wie in Produktion: 0, 1 Admin, 2 Superuser, 3–7 Mitgliedsstatus, 9 DeleteMe, 32 Mitgliedsantrag vorhanden.
Die Namen von 3–7 folgen den Bezeichnungen der Legacy-Startseite (`DatabaseUtils::welcome()`); 0, 1, 2, 9 und 32 sind
unbestätigt (Test-Seed von portal.funkfeuer.at). Die echten Namen liefert `SELECT id, name FROM roles` auf der Produktions-DB.

* Geräte: 1 `alpha-r1` (Node 1, `10.12.0.1`), 2 `beta-r1` (Node 2, `10.12.0.2`), 3 `gamma-r1` (Node 3, `10.12.0.3`),
  4 `alpha-v642` (Node 1, v642, `185.194.20.1`). `devices.id_members` bleibt wie bei den Originalroutinen leer.
* IPv6: `2a02:61:1::1` (Node 1), `2a02:61:2::1` (Node 2), `2a02:61:0:ff:0:ff:fe00:1` (Gerät 1, EUI-64) sowie das
  v642-Paar `2a02:61:0:ee:0:ffff:b9c2:1401` / `…ee:1:ffff:b9c2:1401` (Gerät 4).
* Freie Pools (`id_devices IS NULL`, je 20): `main` = `10.12.0.4`–`10.12.0.23`, `v642` = `185.194.20.2`–`185.194.20.21`.
  `getnextfreeip` sortiert die varchar-Spalte als Text: `insertdevice` vergibt zuerst `10.12.0.10`, nicht `10.12.0.4`.
* Übergabevorschläge sind Node-Zeilen mit negativer ID und `name` = ID als Text: -1 lädt Mitglied 6 als Tech-C von
  Node 1 ein; -2 übergibt Node 2 an Mitglied 7, der bisherige Eigentümer 4 bleibt Tech-C.
* Mailbox-Cache `verify`: `hash` = `md5(E-Mail)`; Seed für `testuser@example.test` (Code 250).
* LDAP-Benutzer haben die ObjectClasses `top`, `posixAccount`, `inetOrgPerson`, `organizationalPerson`, `person`.
  Das Original legt neue Konten mit `uidNumber`/`gidNumber` -1 und `homeDirectory` `/home/<nick>` an und findet
  sie für Änderungen und Löschen über `uidNumber`.

## Originalschema und Testfixture

`docker/rewrite/db/01-schema.sql` ist der Schema-Dump (`pg_dump --schema-only`) aus dem öffentlichen Repository
`https://gitlab.com/funkfeuer/portal.funkfeuer.at` (`tests/schema_real.sql`, Stand Commit `4794890`). Das Repository hat
keine Lizenzdatei; die FunkFeuer-Maintainer erlauben die Verwendung hier. Der Dump enthält alle Tabellen, Constraints,
Sequenzen, Trigger und alle 26 Routinen (`insertmember`, `insertnode`, `insertdevice`, `getnextfreeip`, `insertip6`,
`insertv642`, `updatev642`, `update_lastseen` u. a.). Er ist eine echte Teilmenge des Legacy-Dumps und hat dieselben
Funktionskörper. Es fehlen Views, `*_backup`-Tabellen, `pgcrypto`, ACLs und Owner. Der volle Legacy-Dump lässt sich
unverändert importieren:

```sh
REWRITE_SCHEMA_FILE=/pfad/zum/redeemer-schema.sql make rewrite-down rewrite-test
```

Keine Migrationen, kein Zugriff auf Produktion. Der Seed und alle Tests laufen mit beiden Varianten (die Seed-Summen
sind identisch).

| Unterschied | Fixture (portal-Dump) | Legacy-Dump |
|---|---|---|
| Views (`f_dns`, `r_dns`, `smokeping`, `smokeping6`, `members_and_roles` u. a.), `*_backup`-Tabellen | fehlen | vorhanden |
| `pgcrypto`, ACLs, Owner der `SECURITY DEFINER`-Routinen | fehlen; die Anwendung nutzt den Besitzer `redeemer` | Grants unbekannt (offener Punkt O-8 der Paritätsmatrix) |
| Kollation `en_US` | `00-collation.sql` (ICU), nur für die Views nötig | vom Dump erwartet, identische Datei |

Bestätigter Originalvertrag, abgesichert durch `OriginalContractTest`:

* IPv4: `getnextfreeip` nimmt die erste freie IP in Textreihenfolge; ohne freie `main`-IP schlägt `insertdevice` mit
  `no free IPs` fehl. `insertnode` setzt den Tech-C auf den Eigentümer.
* Eindeutigkeit: Node-Name (ohne Groß-/Kleinschreibung), Gerätename je Node, MAC, `ip6`, Nickname.
* v642: `insertv642` legt Gerät und IPv6-Paar mit fest verdrahteten Präfixen (`2a02:61:0:ee:0|1:ffff:<hex4>`) an;
  `updatev642` erzeugt das Gerät bei neuer IPv4 mit neuer ID neu; `deletev642` löscht IPv6-Zeilen und Gerät (bestätigt A-5).
* `update_lastseen` schreibt auf IP, Gerät, Node und `ip6`, ausgenommen die v642-Quelladresse.
* Typbindung: DBAL bindet ein ungetyptes PHP-`false` als `''`, das PostgreSQL als `boolean` ablehnt. Boolesche
  Routinenparameter mit `ParameterType::BOOLEAN`, `0`/`1` oder `'f'`/`'t'` übergeben.
* Weitere Hinweise aus dem Portal-Repository (Konfigurationsstandards, noch nicht umgesetzt): `MAX_642` = 1 (Admins
  unbegrenzt), Gerätenamen `a-z0-9-` bis 25 Zeichen, IPv6-Netze `2a02:61:0:ff::/64` (EUI-64) und `2a02:61:0:ee::/80`
  (v642), `V642_IPV4_NET` `185.194.20.0/20`. Die Mailprüfung braucht ausgehenden Port 25 (Fehlercodes 994–998 werden
  nicht gecacht). Das Dienstkonto braucht in LDAP Suche, Anlegen, Ändern und Löschen und in der DB `EXECUTE` auf die
  Routinen sowie Schreibrechte auf `nodes`, `devices`, `ip6`, `members_roles` und `verify`.
  Die `uidNumber` setzt das Legacy-PHP selbst: `LDAP::assign_member_id` schreibt nach `insertmember` `uidNumber` und
  `gidNumber` = `members.id`. Das Portal tut das nicht und lässt `-1` stehen (siehe O-7).

## Übergabe: Probe für den späteren CI-Stack

Der CI-Job startet PostgreSQL 13, OpenLDAP und Mailpit mit den Dateien aus `docker/rewrite/` (`db/00`–`02`, `ldap/seed.ldif`),
setzt die Variablen oben und führt in `app/` `composer install` und `vendor/bin/phpunit --testsuite stack` aus. Die
Suite `stack` ist die Probe: Sie prüft PostgreSQL 13, Rollen-IDs, Pools, DBAL-Routinen und Originalvertrag, LDAP-Suche, -Bind und -Schreibrechte,
`uidNumber`↔`members.id`, Mailversand nach Mailpit, Seed-Summen und fehlenden Egress. Ohne den internen Netzwerk-Schutz
des lokalen Stacks muss der CI-Job die Egress-Sperre selbst sicherstellen (`NoEgressTest`). Die Suite `http` braucht
zusätzlich nginx/PHP-FPM wie in `compose.rewrite.yaml`.

## CI (GitHub Actions)

`.github/workflows/rewrite-qa.yml` läuft bei Pull Requests und Pushes auf `main` und ist per `workflow_call` für den
späteren Release-Workflow wiederverwendbar (veröffentlicht selbst nichts). Drei Jobs rufen dieselben Make-Ziele wie lokal auf:
`static` (`make rewrite-lint`), `audit` (`make rewrite-audit`), `test` (`make rewrite-test`, der komplette Stack mit
PostgreSQL 13, OpenLDAP, Mailpit, nginx/PHP-FPM und echten DBAL-/LDAP-/HTTP-Wegen). Bei einem Fehlschlag steht die
Reproduktion (`make …`) als Annotation im Lauf. Es gibt keine Secrets, `permissions: contents: read`, Actions sind auf
Commit-SHAs gepinnt, die Testdienste liegen im internen Netz ohne Egress. Fehlschläge werden weder übersprungen noch zu Warnungen herabgestuft.

Die Pflicht-Checks als Branch-Regel (Required status checks `static`, `audit`, `test`) setzen Maintainer mit Admin-Recht in den Repository-Einstellungen.
