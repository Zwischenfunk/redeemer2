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
| `make rewrite-down` | Stoppt den Stack und löscht seine Volumes. Der nächste Start ist frisch. |

Erreichbar auf dem Host (nur `127.0.0.1`): App `http://127.0.0.1:18480/wien/` (`REWRITE_HTTP_PORT`),
Mailpit-Oberfläche `http://127.0.0.1:18481/` (`REWRITE_MAIL_UI_PORT`). DB und LDAP sind nicht veröffentlicht.

## Aufbau und Isolation

* `app/` ist der Symfony-Baum (PHP 8.4, Symfony 7.4 LTS, Twig, Doctrine DBAL ohne ORM, Composer-Lockfile).
  nginx bekommt nur `app/public/` eingebunden; `config/`, `src/`, `templates/` und `vendor/` liegen nicht im HTTP-Baum.
  Assets liegen in `app/public/assets/` und werden ohne Node-Build ausgeliefert.
* nginx bildet `/wien/` auf den Front Controller ab (`SCRIPT_NAME=/wien/index.php`); Symfony erzeugt dadurch
  alle Links und Asset-URLs unter `/wien/`. `/wien/index.php` und alles außerhalb von `/wien/` liefert 404.
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
| `SESSION_COOKIE_SECURE` | `1` (produktiv) oder `0` (lokal ohne TLS) | `0` |
| `SESSION_COOKIE_PATH` | Cookie-Pfad, entspricht dem URL-Basispfad | `/wien/` |
| `SESSION_SAVE_PATH` | Verzeichnis der PHP-Sessions | `/var/lib/redeemer/sessions` |

Nur die Tests zusätzlich: `HTTP_BASE_URL` (`http://nginx/wien`), `MAILPIT_URL` (`http://mail:8025`).

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
| 3 | `testuser` | 3 | 3 | Eigentümer von 1 `alpha` (Tech-C 3) und 3 `gamma` |
| 4 | `testowner2` | 4 | 4 | Eigentümer von 2 `beta`; Tech-C von 3 |
| 5 | `testtech` | 3 | 5 | Tech-C von 2; Eigentümer und Tech-C von 4 `delta` |
| 6 | `testplain` | – („unbestimmt“) | 6 | – |
| 7 | `testhonor` | 7 | 7 | – |
| 8 | `testfriend` | 6 | 8 | – |
| 9 | `testsqlonly` | – | **kein** LDAP-Konto | – |
| – | `testldaponly` | – | `uidNumber` 900, **kein** Mitgliedssatz | – |
| – | `testpending` | – | `uidNumber` -1 (Teilanlage) | – |

Rollen: 1 Admin, 2 Superuser, 3 ordentlich, 4 außerordentlich, 5 kein, 6 fördernd, 7 Ehren, 8 Zusatzrolle.
Geräte: 1 `alpha-r1` (Node 1, `192.0.2.1`), 2 `beta-r1` (Node 2, `192.0.2.2`), 3 `gamma-r1` (Node 3, `192.0.2.3`);
IPv6 `2001:db8:1::1` an Gerät 1, `2001:db8:2::1` an Node 2. Freie Pools: `main` = `192.0.2.10`–`192.0.2.29`,
`v642` = `198.51.100.10`–`198.51.100.29` (je 20, `id_devices IS NULL`). Noch nicht enthalten: Übergabevorschläge
(negative Node-IDs), Mailbox-Cache `verify`; Folge-Issues ergänzen sie.

## Originalschema und Testfixture

`docker/rewrite/db/01-schema.sql` ist ein **unabhängig geschriebenes** öffentliches CI-Fixture. Es enthält Tabellen,
Spaltennamen/-typen, Constraints, Sequenzen, `created`/`changed`-Trigger und die Routinen `insertmember`,
`updatemember`, `deletemember`, `member_login` und `update_lastseen`. Das Originalschema aus der
Legacy-Bestandsaufnahme wird nicht versioniert. In einer dafür autorisierten, isolierten Umgebung lässt es sich
unverändert importieren:

```sh
REWRITE_SCHEMA_FILE=/pfad/zum/redeemer-schema.sql make rewrite-down rewrite-test
```

Keine Migrationen, kein Zugriff auf Produktion. Der Seed und alle Tests laufen mit beiden Varianten (die Seed-Summen
sind identisch); beim Import des Originals gilt dessen Verhalten.

| Unterschied | Testfixture | Bestätigter Originalvertrag |
|---|---|---|
| Views (`f_dns`, `r_dns`, `smokeping`, `smokeping6`, `members_and_roles` u. a.), `*_backup`-Tabellen | fehlen | vorhanden |
| Routinen für Nodes, Geräte, IPv6, v642 (`insertnode`, `insertdevice`, `getnextfreeip`, `insertv642`, `updatev642`, …) und Eindeutigkeits-Trigger | fehlen | vorhanden; Tests für #7/#9 brauchen das Original oder erweitern das Fixture |
| `updatemember` | schreibt immer | überspringt, wenn nichts geändert ist (kein `changed`-Update) |
| `member_login` | gleiche Wirkung | gleiche Wirkung |
| `insertmember` | liefert die neue ID | liefert die neue ID |
| Grants/Owner der `SECURITY DEFINER`-Routinen | Anwendung nutzt den Besitzer `redeemer` | unbekannt (offener Punkt O-8 der Paritätsmatrix) |
| Kollation `en_US` | `00-collation.sql` (ICU) | vom Dump erwartet, identische Datei |

## Übergabe: Probe für den späteren CI-Stack

Der CI-Job startet PostgreSQL 13, OpenLDAP und Mailpit mit den Dateien aus `docker/rewrite/` (`db/00`–`02`, `ldap/seed.ldif`),
setzt die Variablen oben und führt in `app/` `composer install` und `vendor/bin/phpunit --testsuite stack` aus. Die
Suite `stack` ist die Probe: Sie prüft PostgreSQL 13, Rollen-IDs 1/2, Pools, DBAL-Routinen, LDAP-Suche und -Bind,
`uidNumber`↔`members.id`, Mailversand nach Mailpit, Seed-Summen und fehlenden Egress. Ohne den internen Netzwerk-Schutz
des lokalen Stacks muss der CI-Job die Egress-Sperre selbst sicherstellen (`NoEgressTest`). Die Suite `http` braucht
zusätzlich nginx/PHP-FPM wie in `compose.rewrite.yaml`.
