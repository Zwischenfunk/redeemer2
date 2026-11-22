-- Synthetic seed for the rewrite test stack. Deterministic: fixed ids and timestamps,
-- no triggers, so two fresh starts yield identical rows. All values are invented;
-- addresses use documentation ranges (RFC 5737 / RFC 3849), mail uses example.test.
SET session_replication_role = replica;
SET TIME ZONE 'UTC';

INSERT INTO public.roles (id, name) VALUES
  (1, 'Admin'),
  (2, 'Superuser'),
  (3, 'Ordentliches Mitglied'),
  (4, 'Ausserordentliches Mitglied'),
  (5, 'Kein Mitglied'),
  (6, 'Foerderndes Mitglied'),
  (7, 'Ehrenmitglied'),
  (8, 'Zusatzrolle');

-- Passwords live in LDAP only. id 9 has no LDAP account, LDAP-only accounts have no row here.
INSERT INTO public.members (id, nickname, firstname, lastname, street, housenumber, zip, town, telephone, mobilephone, email, created, changed) VALUES
  (1, 'testadmin',   'Anna',   'Admin',    'Testgasse',   '1', '1010', 'Wien', '+4310000001', '+436640000001', 'testadmin@example.test',   '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (2, 'testsuper',   'Sven',   'Super',    'Testgasse',   '2', '1010', 'Wien', '+4310000002', '+436640000002', 'testsuper@example.test',   '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (3, 'testuser',    'Uli',    'User',     'Testgasse',   '3', '1020', 'Wien', '+4310000003', '+436640000003', 'testuser@example.test',    '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (4, 'testowner2',  'Olga',   'Owner',    'Probeweg',    '4', '1030', 'Wien', '+4310000004', '+436640000004', 'testowner2@example.test',  '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (5, 'testtech',    'Tom',    'Tech',     'Probeweg',    '5', '1030', 'Wien', '+4310000005', '+436640000005', 'testtech@example.test',    '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (6, 'testplain',   'Paula',  'Plain',    'Beispielweg', '6', '1040', 'Wien', '+4310000006', '+436640000006', 'testplain@example.test',   '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (7, 'testhonor',   'Hans',   'Honor',    'Beispielweg', '7', '1040', 'Wien', '+4310000007', '+436640000007', 'testhonor@example.test',   '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (8, 'testfriend',  'Frida',  'Friend',   'Beispielweg', '8', '1050', 'Wien', '+4310000008', '+436640000008', 'testfriend@example.test',  '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (9, 'testsqlonly', 'Sigi',   'Sqlonly',  'Beispielweg', '9', '1050', 'Wien', '+4310000009', '+436640000009', 'testsqlonly@example.test', '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00');

INSERT INTO public.members_roles (id_roles, id_members) VALUES
  (1, 1), (3, 1),
  (2, 2), (3, 2),
  (3, 3),
  (4, 4),
  (3, 5),
  (7, 7),
  (6, 8);

-- Owners 3, 4, 5; Tech-Cs 3, 5, 4; member 6 owns nothing, member 8 only has a device.
INSERT INTO public.nodes (id, name, gps_lat_deg, gps_lat_min, gps_lat_sec, gps_lon_deg, gps_lon_min, gps_lon_sec, map, id_members, id_tech_c, created, changed, last_seen) VALUES
  (1, 'alpha', 48, 12, 30, 16, 22, 10, true,  3, 3, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', '2026-01-02 00:00:00'),
  (2, 'beta',  48, 13, 0,  16, 23, 0,  true,  4, 5, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', '2026-01-02 00:00:00'),
  (3, 'gamma', 48, 14, 0,  16, 24, 0,  false, 3, 4, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', NULL),
  (4, 'delta', NULL, NULL, NULL, NULL, NULL, NULL, true, 5, 5, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', NULL);

INSERT INTO public.devices (id, name, hardware, mac, smokeping, last_seen, id_nodes, id_members, created, changed, nodemaster) VALUES
  (1, 'alpha-r1', 'Test Router A', '02:00:00:00:00:01', true,  '2026-01-02 00:00:00', 1, 3, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', true),
  (2, 'beta-r1',  'Test Router B', '02:00:00:00:00:02', true,  '2026-01-02 00:00:00', 2, 4, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', true),
  (3, 'gamma-r1', 'Test Router C', '02:00:00:00:00:03', false, NULL,                  3, 3, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', false);

-- Assigned addresses first, then the free pools: 20 x 'main' (192.0.2.10-29), 20 x 'v642' (198.51.100.10-29).
INSERT INTO public.ips (id, ip, cidr, usage, dns_forward, dns_reverse, id_devices, id_members, id_nodes, last_seen) VALUES
  (1, '192.0.2.1', '24', 'main', true, true, 1, 3, 1, '2026-01-02 00:00:00'),
  (2, '192.0.2.2', '24', 'main', true, true, 2, 4, 2, '2026-01-02 00:00:00'),
  (3, '192.0.2.3', '24', 'main', true, true, 3, 3, 3, NULL);
INSERT INTO public.ips (id, ip, cidr, usage, dns_forward, dns_reverse)
SELECT 100 + n, '192.0.2.' || (9 + n), '24', 'main', false, false FROM generate_series(1, 20) AS n;
INSERT INTO public.ips (id, ip, cidr, usage, dns_forward, dns_reverse)
SELECT 200 + n, '198.51.100.' || (9 + n), '24', 'v642', false, false FROM generate_series(1, 20) AS n;

INSERT INTO public.ip6 (id, ip6, dns_forward, dns_reverse, id_devices, id_members, id_nodes, name, smokeping, created, changed) VALUES
  (1, '2001:db8:1::1', true,  true,  1,    3, 1, 'alpha-r1-6', true, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00'),
  (2, '2001:db8:2::1', false, false, NULL, 4, 2, 'beta-6',     true, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00');

SELECT setval('public.roles_id_seq', 8);
SELECT setval('public.members_id_seq', 9);
SELECT setval('public.nodes_id_seq', 4);
SELECT setval('public.devices_id_seq', 3);
SELECT setval('public.ips_id_seq', 300);
SELECT setval('public.ip6_id_seq', 2);
