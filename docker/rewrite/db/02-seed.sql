-- Synthetic seed for the rewrite test stack. Deterministic: fixed ids and timestamps,
-- no triggers, so two fresh starts yield identical rows. Personal data is invented;
-- network ranges and role ids follow the confirmed original contract (see app/docs/teststack.md):
-- main 10.12.0.0/16, v642 185.194.20.0/20, IPv6 2a02:61::/32, mail uses example.test.
SET session_replication_role = replica;
SET TIME ZONE 'UTC';

INSERT INTO public.roles (id, name) VALUES
  (0, 'none'),
  (1, 'Admin'),
  (2, 'Superuser'),
  (3, 'Mitglied'),
  (4, 'Ausserordentlich'),
  (5, 'Kein Mitglied'),
  (6, 'Foerderer'),
  (7, 'Ehrenmitglied'),
  (9, 'DeleteMe'),
  (32, 'Mitgliedsantrag vorhanden');

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

-- Membership 3-7 (member 6: application pending, member 9: marked for deletion).
INSERT INTO public.members_roles (id_roles, id_members) VALUES
  (1, 1), (3, 1),
  (2, 2), (3, 2),
  (3, 3),
  (4, 4),
  (3, 5),
  (32, 6),
  (7, 7),
  (6, 8),
  (9, 9);

-- Owners 3, 4, 5; Tech-Cs 3, 5, 4; member 6 owns nothing, member 8 owns nothing.
-- Negative ids are pending proposals for node -id (name = the negated id, as the original frontend stores them):
-- -1 invites member 6 as Tech-C of node 1; -2 hands node 2 to member 7, owner 4 stays Tech-C.
INSERT INTO public.nodes (id, name, gps_lat_deg, gps_lat_min, gps_lat_sec, gps_lon_deg, gps_lon_min, gps_lon_sec, map, id_members, id_tech_c, created, changed, last_seen) VALUES
  (1, 'alpha', 48, 12, 30, 16, 22, 10, true,  3, 3, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', '2026-01-02 00:00:00'),
  (2, 'beta',  48, 13, 0,  16, 23, 0,  true,  4, 5, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', '2026-01-02 00:00:00'),
  (3, 'gamma', 48, 14, 0,  16, 24, 0,  false, 3, 4, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', NULL),
  (4, 'delta', NULL, NULL, NULL, NULL, NULL, NULL, true, 5, 5, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', NULL),
  (-1, '-1', NULL, NULL, NULL, NULL, NULL, NULL, false, 3, 6, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', NULL),
  (-2, '-2', NULL, NULL, NULL, NULL, NULL, NULL, false, 7, 4, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', NULL);

-- Like insertdevice/insertv642: devices.id_members stays NULL; device 4 is a v642 device (no antenna, hardware, SSID, MAC).
INSERT INTO public.devices (id, name, antenna, hardware, ssid, mac, smokeping, last_seen, id_nodes, id_members, created, changed, nodemaster) VALUES
  (1, 'alpha-r1',   'Test Antenna', 'Test Router A', 'test-ssid', '02:00:00:00:00:01', true,  '2026-01-02 00:00:00', 1, NULL, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', true),
  (2, 'beta-r1',    NULL,           'Test Router B', NULL,        '02:00:00:00:00:02', true,  '2026-01-02 00:00:00', 2, NULL, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', true),
  (3, 'gamma-r1',   NULL,           'Test Router C', NULL,        '02:00:00:00:00:03', false, NULL,                  3, NULL, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', false),
  (4, 'alpha-v642', NULL,           NULL,            NULL,        NULL,                true,  NULL,                  1, NULL, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', false);

-- Assigned addresses first, then the free pools: 20 x 'main' (10.12.0.4-23, first in text order: 10.12.0.10),
-- 20 x 'v642' (185.194.20.2-21). getnextfreeip sorts ips.ip as text.
INSERT INTO public.ips (id, ip, cidr, usage, dns_forward, dns_reverse, id_devices, last_seen) VALUES
  (1, '10.12.0.1',     '16', 'main', true, true, 1, '2026-01-02 00:00:00'),
  (2, '10.12.0.2',     '16', 'main', true, true, 2, '2026-01-02 00:00:00'),
  (3, '10.12.0.3',     '16', 'main', true, true, 3, NULL),
  (4, '185.194.20.1',  '20', 'v642', true, true, 4, NULL);
INSERT INTO public.ips (id, ip, cidr, usage, dns_forward, dns_reverse)
SELECT 100 + n, '10.12.0.' || (3 + n), '16', 'main', false, false FROM generate_series(1, 20) AS n;
INSERT INTO public.ips (id, ip, cidr, usage, dns_forward, dns_reverse)
SELECT 200 + n, '185.194.20.' || (1 + n), '20', 'v642', false, false FROM generate_series(1, 20) AS n;

-- 1, 3: user blocks 2a02:61:<node id hex>::/48 on a node; 2: EUI-64 address (2a02:61:0:ff::/64) on device 1;
-- 4, 5: the v642 pair of device 4 (2a02:61:0:ee:{0,1}:ffff:<IPv4 as hex>).
INSERT INTO public.ip6 (id, ip6, dns_forward, dns_reverse, id_devices, id_members, id_nodes, name, mac, smokeping, created, changed, nodemaster) VALUES
  (1, '2a02:61:1::1',                   true,  true,  NULL, NULL, 1,    'alpha-6',      NULL,                true,  '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', false),
  (2, '2a02:61:0:ff:0:ff:fe00:1',       true,  false, 1,    NULL, NULL, 'alpha-r1',     '02:00:00:00:00:01', false, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', false),
  (3, '2a02:61:2::1',                   false, false, NULL, NULL, 2,    'beta-6',       NULL,                true,  '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', false),
  (4, '2a02:61:0:ee:0:ffff:b9c2:1401',  true,  true,  4,    NULL, NULL, 's-alpha-v642', NULL,                false, '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', false),
  (5, '2a02:61:0:ee:1:ffff:b9c2:1401',  true,  true,  4,    NULL, NULL, 'alpha-v642',   NULL,                true,  '2026-01-01 00:00:00+00', '2026-01-01 00:00:00+00', false);

-- Mailbox check cache: hash = md5(email), code 250 = accepted.
INSERT INTO public.verify (hash, last_checked, code, ca, text) VALUES
  ('a34f3ef8743a029e47e41ace516e165c', '2026-01-02 00:00:00', 250, 550, '250 2.1.5 Ok');

SELECT setval('public.roles_id_seq', 32);
SELECT setval('public.members_id_seq', 9);
SELECT setval('public.nodes_id_seq', 4);
SELECT setval('public.devices_id_seq', 4);
SELECT setval('public.ips_id_seq', 300);
SELECT setval('public.ip6_id_seq', 5);
