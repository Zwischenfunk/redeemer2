-- Taken from tests/schema_real.sql of https://gitlab.com/funkfeuer/portal.funkfeuer.at
-- (commit 4794890, only this header replaced); the FunkFeuer maintainers allow its reuse here.
-- A pg_dump --schema-only of the production redeemer database: real tables, constraints,
-- triggers and DB functions; views, *_backup tables, pgcrypto, ACLs and owners are left out.
-- Used by the tests only (see app/docs/teststack.md).

SET check_function_bodies = false;
SET client_min_messages = warning;

--
-- Name: changed(); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.changed() RETURNS trigger
    LANGUAGE plpgsql
    AS $$BEGIN
  NEW.changed := date_trunc('seconds', NOW());
  RETURN NEW;
END;$$;



--
-- Name: created(); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.created() RETURNS trigger
    LANGUAGE plpgsql
    AS $$BEGIN
  NEW.created := date_trunc('seconds', NOW());
  RETURN NEW;
END;$$;



--
-- Name: deletedevice(integer); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.deletedevice(integer) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
/**
 * deletedevice
 *
 * $1 -- id
 **/

DECLARE
    i_id ALIAS FOR $1;
BEGIN
    DELETE FROM devices WHERE id=i_id;
    RETURN;
END;
$_$;



--
-- Name: deleteip6(integer); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.deleteip6(integer) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
    i_id ALIAS FOR $1;
BEGIN
    DELETE FROM ip6 WHERE id=i_id;
    RETURN;
END;
$_$;



--
-- Name: deletemember(integer); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.deletemember(integer) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
/**
 * deletemember
 *
 * $1 -- id
 **/

DECLARE
    i_id ALIAS FOR $1;
BEGIN
    delete from members_roles where id_members=i_id;
    DELETE FROM members WHERE id=i_id;
    RETURN;
END;
$_$;



--
-- Name: deletenode(integer); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.deletenode(integer) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
/**
 * deletenode
 *
 * $1 -- id
 **/

DECLARE
    i_id ALIAS FOR $1;
BEGIN
    DELETE FROM nodes WHERE id=i_id;
    RETURN;
END;
$_$;



--
-- Name: deletev642(bigint); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.deletev642(bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
    i_id ALIAS FOR $1;
BEGIN
    DELETE FROM ip6 WHERE id_devices=i_id;
    DELETE from devices where id=i_id;
    RETURN;
END;
$_$;



--
-- Name: extract_octet(inet, integer); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.extract_octet(inet, integer) RETURNS integer
    LANGUAGE sql
    AS $_$
    SELECT ((regexp_split_to_array(host($1), E'\\.'))[$2])::int;
$_$;



--
-- Name: getnextfreeip(character varying, integer, character varying); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.getnextfreeip(character varying, integer, character varying) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
/**
 * getnextfreeip
 *
 * $1 -- usage
 * $2 -- id_devices||id_nodes||id_members
 * $3 -- devices||nodes||members
 **/

DECLARE
    i_usage ALIAS FOR $1;
    i_id ALIAS FOR $2;
    i_type ALIAS FOR $3;
    o_id int4;
BEGIN
   SELECT id INTO o_id
   FROM ips
   WHERE usage=i_usage
   AND id_members ISNULL
   AND id_nodes ISNULL
   AND id_devices ISNULL
   ORDER BY ip ASC
   LIMIT 1;

   IF i_type='devices' THEN
      UPDATE ips
      SET id_devices=i_id
      WHERE id=o_id;
    ELSIF i_type='nodes' THEN
      UPDATE ips
      SET id_nodes=i_id
      WHERE id=o_id;
    ELSIF i_type='members' THEN
      UPDATE ips
      SET id_members=i_id
      WHERE id=o_id;
    END IF;

    RETURN o_id;
END;
$_$;



--
-- Name: insertdevice(bigint, character varying, character varying, character varying, character varying, character varying, boolean); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.insertdevice(bigint, character varying, character varying, character varying, character varying, character varying, boolean) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$/**
 * insertdevice
 *
 * $1 -- id_nodes
 * $2 -- name
 * $3 -- antenna
 * $4 -- hardware
 * $5 -- ssid
 * $6 -- mac
 * $7 -- smokeping
 **/

DECLARE
    i_id_nodes ALIAS FOR $1;
    i_name ALIAS FOR $2;
    i_antenna ALIAS FOR $3;
    i_hardware ALIAS FOR $4;
    i_ssid ALIAS FOR $5;
    i_mac ALIAS FOR $6;
    i_smokeping ALIAS FOR $7;
    o_id int4;
    ips_free_var int4;
BEGIN

   /* check for free IPs first - datacop 02.05.2012 */
   SELECT INTO ips_free_var count(*) FROM ips WHERE usage='main'
   AND id_members ISNULL
   AND id_nodes ISNULL
   AND id_devices ISNULL;
   
   IF ( ips_free_var < 1) THEN
      RAISE EXCEPTION 'could not create device: no free IPs';
      RETURN 0;
   END IF;
   /* end check - datacop 02.05.2012 */

    INSERT INTO devices (id,     name,   antenna,   hardware,   ssid,    mac,  smokeping, last_seen, id_nodes, id_members)
    VALUES (nextval('devices_id_seq'), i_name, i_antenna, i_hardware, i_ssid, i_mac, i_smokeping, NULL, i_id_nodes, NULL);
    SELECT INTO o_id currval('devices_id_seq');
    PERFORM getnextfreeip('main', o_id, 'devices');
    RETURN o_id;
END;
$_$;



--
-- Name: insertip6(character varying, bigint, character varying, character varying, boolean, boolean, boolean, character varying, character varying, character varying, character varying); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.insertip6(character varying, bigint, character varying, character varying, boolean, boolean, boolean, character varying, character varying, character varying, character varying) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
    i_what ALIAS FOR $1;
    i_thisid ALIAS FOR $2;
    i_ip6 ALIAS FOR $3;
    i_name ALIAS FOR $4;
    i_smokeping ALIAS FOR $5;
    i_forward ALIAS FOR $6;
    i_reverse ALIAS FOR $7;
    i_antenna ALIAS for $8;
    i_hardware ALIAS for $9;
    i_ssid ALIAS for $10;
    i_mac ALIAS for $11;
    o_id int4;
    n_ip6 character varying;
BEGIN
    SELECT abbrev(cast(i_ip6 as inet)) INTO n_ip6;
    IF i_what='n' THEN
        insert into ip6 (id,          ip6,   dns_forward, dns_reverse, id_nodes, smokeping,   name, antenna, hardware, ssid, mac)
        values (nextval('ip6_id_seq'), n_ip6, i_forward,  i_reverse,   i_thisid, i_smokeping, i_name, i_antenna, i_hardware, i_ssid, i_mac);
    ELSIF i_what='d' THEN
        insert into ip6 (id,          ip6,   dns_forward, dns_reverse, id_devices, smokeping, name, antenna, hardware, ssid, mac)
        values (nextval('ip6_id_seq'), n_ip6, i_forward,   i_reverse,   i_thisid, i_smokeping, i_name, i_antenna, i_hardware, i_ssid, i_mac);
    ELSE
        RAISE EXCEPTION 'insertip6 failed: action not requested properly';
        RETURN 0;
    END IF;
    SELECT INTO o_id currval('ip6_id_seq');
    RETURN o_id;
END;
$_$;



--
-- Name: insertmember(character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.insertmember(character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$

DECLARE
    i_nickname ALIAS FOR $1;
    i_firstname ALIAS FOR $2;
    i_lastname ALIAS FOR $3;
    i_street ALIAS FOR $4;
    i_housenumber ALIAS FOR $5;
    i_zip ALIAS FOR $6;
    i_town ALIAS FOR $7;
    i_telephone ALIAS FOR $8;
    i_mobilephone ALIAS FOR $9;
    i_fax ALIAS FOR $10;
    i_email ALIAS FOR $11;
    i_homepage ALIAS FOR $12;
    o_id int4;
BEGIN
    INSERT INTO members 
		(id, nickname, firstname, lastname, street, housenumber, zip, town, telephone, mobilephone, fax, email, homepage)
	VALUES(nextval('members_id_seq'), i_nickname, i_firstname, i_lastname, i_street, i_housenumber, i_zip, i_town, i_telephone, i_mobilephone, i_fax, i_email, i_homepage)
;
    SELECT INTO o_id currval('members_id_seq');
    RETURN o_id;
END;
$_$;



--
-- Name: insertnode(bigint, character varying, double precision, double precision, double precision, double precision, double precision, double precision, boolean); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.insertnode(bigint, character varying, double precision, double precision, double precision, double precision, double precision, double precision, boolean) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$/**
 * insertnode
 *
 * $1 -- id_members
 * $2 -- name
 * $3 -- gps_lat_deg
 * $4 -- gps_lat_min
 * $5 -- gps_lat_sec
 * $6 -- gps_lon_deg
 * $7 -- gps_lon_min
 * $8 -- gps_lon_sec
 * $9 -- map
 **/

DECLARE
    i_id_members ALIAS FOR $1;
    i_name ALIAS FOR $2;
    i_gps_lat_deg ALIAS FOR $3;
    i_gps_lat_min ALIAS FOR $4;
    i_gps_lat_sec ALIAS FOR $5;
    i_gps_lon_deg ALIAS FOR $6;
    i_gps_lon_min ALIAS FOR $7;
    i_gps_lon_sec ALIAS FOR $8;
    i_map ALIAS FOR $9;
    o_id int4;
BEGIN
    INSERT INTO nodes (
id,
name,
gps_lat_deg,
gps_lat_min,
gps_lat_sec,
gps_lon_deg,	
gps_lon_min,	
gps_lon_sec,	
map,	
id_members,
id_tech_c
)
VALUES(nextval('nodes_id_seq'), i_name, i_gps_lat_deg, i_gps_lat_min, i_gps_lat_sec, i_gps_lon_deg, i_gps_lon_min, i_gps_lon_sec, i_map, i_id_members, i_id_members);
    SELECT INTO o_id currval('nodes_id_seq');
    RETURN o_id;
END;

$_$;



--
-- Name: insertv642(bigint, bigint, character varying, boolean, boolean, boolean); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.insertv642(bigint, bigint, character varying, boolean, boolean, boolean) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
    i_id_nodes ALIAS FOR $1;
    i_id_ips ALIAS FOR $2;
    i_name ALIAS FOR $3;
    i_smokeping ALIAS FOR $4;
    i_forward ALIAS FOR $5;
    i_reverse ALIAS FOR $6;
    ips_free_var int4;
    o_id int4;
    o_ins_2 int4;
    o_ins_3 int4;
    o_ipv4 character varying;
BEGIN
    /* check for free IP first */
    SELECT INTO ips_free_var count(*) FROM ips WHERE usage='v642'
    and id=i_id_ips
    AND id_members ISNULL
    AND id_nodes ISNULL
    AND id_devices ISNULL;
    IF ( ips_free_var != 1) THEN
        RAISE EXCEPTION 'could not create device: requested IP not available or already in use';
        RETURN 0;
    END IF;
    /* end check */
    INSERT INTO devices VALUES (nextval('devices_id_seq'), i_name, null,null,null,null, i_smokeping, NULL, i_id_nodes, NULL);
    SELECT INTO o_id currval('devices_id_seq');
    update ips set id_devices=o_id where id=i_id_ips;
    SELECT INTO o_ipv4 ip FROM ips WHERE id=i_id_ips;
    select into o_ins_2 insertip6('d', o_id, '2a02:61:0:ee:0:ffff:'||ip4_to_hex(cast(o_ipv4 as inet)),'s-'||i_name, false, i_forward, i_reverse, null,null,null,null);
    select into o_ins_3 insertip6('d', o_id, '2a02:61:0:ee:1:ffff:'||ip4_to_hex(cast(o_ipv4 as inet)),i_name, i_smokeping, i_forward, i_reverse, null,null,null,null);
    RETURN o_id;
END;
$_$;



--
-- Name: ip4_to_hex(inet); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.ip4_to_hex(inet) RETURNS character
    LANGUAGE sql
    AS $_$
    select lpad(to_hex(extract_octet($1, 1)),2,'0')||
    lpad(to_hex(extract_octet($1, 2)),2,'0')||':'||
    lpad(to_hex(extract_octet($1, 3)),2,'0')||
    lpad(to_hex(extract_octet($1, 4)),2,'0');
$_$;



--
-- Name: ip4_unique_aaaa(); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.ip4_unique_aaaa() RETURNS trigger
    LANGUAGE plpgsql
    AS $$BEGIN
    if exists (select id from ip6 where id_nodes=NEW.id_nodes and dns_forward=true and lower(name)=lower(NEW.name)
         union select id from ip6 where id_devices!=NEW.id and dns_forward=true and lower(name)=lower(NEW.name)
               and id_devices in (select id from devices where id_nodes=NEW.id_nodes and dns_forward=true and lower(name)=lower(NEW.name)))
    THEN
        update ip6
        set    dns_forward=false
        where  dns_forward=true
        and    id_devices=NEW.id;
    END IF;
    update ip6
    set    name=NEW.name
    where  id_devices=NEW.id
    and    name!=NEW.name;
    RETURN NEW;
END;$$;



--
-- Name: ip4_unique_master(); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.ip4_unique_master() RETURNS trigger
    LANGUAGE plpgsql
    AS $$BEGIN
    IF NEW.nodemaster=true THEN
        update devices
        set    nodemaster=false
        where  nodemaster=true
        and    id != NEW.id
        and    id in (
        select id from devices n
        where  n.id_nodes=NEW.id_nodes and n.nodemaster=true
        );
    END IF;
    RETURN NEW;
END;$$;



--
-- Name: ip6_unique_aaaa(); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.ip6_unique_aaaa() RETURNS trigger
    LANGUAGE plpgsql
    AS $$BEGIN
    IF NEW.dns_forward=true THEN
        update ip6
        set    dns_forward=false
        where  dns_forward=true
        and    id != NEW.id
        and    id in (
        select id from ip6 n
        where (n.id_nodes=NEW.id_nodes and lower(n.name)=lower(NEW.name) and n.dns_forward=true)
        or    (n.id_devices in (select id  from devices where id_nodes=NEW.id_nodes and lower(name)=lower(NEW.name)) and n.dns_forward=true)
        or    (n.id_nodes=(select id_nodes from devices where id=NEW.id_devices) and lower(n.name)=lower(NEW.name) and n.dns_forward=true)
        or    (n.id_devices in (select id  from devices where id=NEW.id_devices and lower(name)=lower(NEW.name)) and n.dns_forward=true)
        );
    END IF;
    --if (NEW.id_devices > 0) then /*problem with renaming a ipv4-device | overlapp OLD/NEW on ip4_unique_aaaa*/
    --    NEW.name:=(select name from devices where id=NEW.id_devices);
    --end if;
    RETURN NEW;
END;$$;



--
-- Name: ip6_unique_master(); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.ip6_unique_master() RETURNS trigger
    LANGUAGE plpgsql
    AS $$BEGIN
    IF NEW.nodemaster=true THEN
        update ip6
        set    nodemaster=false
        where  nodemaster=true
        and    id != NEW.id
        and    id in (
         select id from ip6 n
         where (n.id_nodes=NEW.id_nodes)
         or    (n.id_devices in (select id from devices where id_nodes=NEW.id_nodes))
         or    (n.id_nodes in (select id_nodes from devices where id=NEW.id_devices))
         or    (n.id_devices in (select id from devices where id_nodes in (select id_nodes from devices where id=NEW.id_devices)))
        );
    END IF;
    RETURN NEW;
END;$$;



--
-- Name: member_login(character varying); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.member_login(character varying) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
i_nickname ALIAS FOR $1;
o_id bigint;
o_current_time timestamp without time zone;
BEGIN
  select into o_id min(id) from members where nickname=lower(i_nickname) and id>=0;
  if (o_id >= 0) then
    select into o_current_time date_trunc('second',now());
    update members set last_login=o_current_time where id=o_id;
  end if;
END;
$_$;



--
-- Name: update_lastmodified_column(); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.update_lastmodified_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    NEW.lastmodified = NOW();
    RETURN NEW;
  END;
$$;



--
-- Name: update_lastseen(character varying, timestamp without time zone); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.update_lastseen(character varying, timestamp without time zone) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
i_ip ALIAS FOR $1;
i_time ALIAS FOR $2;
o_current_time timestamp without time zone;
BEGIN
  select into o_current_time coalesce(i_time, date_trunc('second',now()));
  --
  update ips set last_seen=o_current_time where ip=i_ip and (last_seen<o_current_time or last_seen is null);
  --
  update devices set last_seen=o_current_time 
  where id in (
    select id_devices from ips where ip=i_ip union
    select id_devices from ip6 where ip6=lower(i_ip) and ip6 not like '2a02:61:0:ee:0:ffff:%'
  ) and (last_seen<o_current_time or last_seen is null);
  --
  update nodes set last_seen=o_current_time 
  where  id in (
    select d.id_nodes from ips i, devices d where i.id_devices=d.id and i.ip=i_ip union
    select e.id_nodes from ip6 e where e.ip6=lower(i_ip) and ip6 not like '2a02:61:0:ee:0:ffff:%' union
    select v.id_nodes from ip6 f, devices v where f.ip6=lower(i_ip) and f.ip6 not like '2a02:61:0:ee:0:ffff:%' and f.id_devices=v.id
  ) and    (last_seen<o_current_time or last_seen is null);
  --
  update ip6 set last_seen=o_current_time 
  where ip6=lower(i_ip) and (last_seen<o_current_time or last_seen is null);
RETURN;
END;
$_$;



--
-- Name: updatedevice(integer, character varying, character varying, character varying, character varying, character varying, boolean, boolean); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.updatedevice(integer, character varying, character varying, character varying, character varying, character varying, boolean, boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
    i_id ALIAS FOR $1;
    i_name ALIAS FOR $2;
    i_antenna ALIAS FOR $3;
    i_hardware ALIAS FOR $4;
    i_ssid ALIAS FOR $5;
    i_mac ALIAS FOR $6;
    i_smokeping ALIAS FOR $7;
    i_nodemaster ALIAS FOR $8;
BEGIN
    UPDATE devices SET name=i_name,
                       antenna=i_antenna,
                       hardware=i_hardware,
                       ssid=i_ssid,
                       mac=i_mac,
                       smokeping=i_smokeping,
                       nodemaster=i_nodemaster
                       WHERE id=i_id;
    RETURN;
END;
$_$;



--
-- Name: updateip6(integer, integer, integer, character varying, character varying, boolean, boolean, boolean, character varying, character varying, character varying, character varying, boolean); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.updateip6(integer, integer, integer, character varying, character varying, boolean, boolean, boolean, character varying, character varying, character varying, character varying, boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
    i_id ALIAS FOR $1;
    i_id_nodes ALIAS FOR $2;
    i_id_devices ALIAS FOR $3;
    i_ip6 ALIAS FOR $4;
    i_name ALIAS FOR $5;
    i_smokeping ALIAS FOR $6;
    i_forward ALIAS FOR $7;
    i_reverse ALIAS FOR $8;
    i_antenna ALIAS for $9;
    i_hardware ALIAS for $10;
    i_ssid ALIAS for $11;
    i_mac ALIAS for $12;
    i_nodemaster ALIAS for $13;
    n_ip6 character varying;
BEGIN
    SELECT abbrev(cast(i_ip6 as inet)) INTO n_ip6;
    UPDATE ip6     SET name=i_name,
        id_nodes=i_id_nodes,
        id_devices=i_id_devices,
        ip6=n_ip6,
        smokeping=i_smokeping,
        dns_forward=i_forward,
        dns_reverse=i_reverse,
        antenna=i_antenna,
        hardware=i_hardware,
        ssid=i_ssid,
        mac=i_mac,
        nodemaster=i_nodemaster
    WHERE id=i_id;
    RETURN;
END;
$_$;



--
-- Name: updatemember(integer, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.updatemember(integer, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
    i_id ALIAS FOR $1;
    i_nickname ALIAS FOR $2;
    i_firstname ALIAS FOR $3;
    i_lastname ALIAS FOR $4;
    i_street ALIAS FOR $5;
    i_housenumber ALIAS FOR $6;
    i_zip ALIAS FOR $7;
    i_town ALIAS FOR $8;
    i_telephone ALIAS FOR $9;
    i_mobilephone ALIAS FOR $10;
    i_fax ALIAS FOR $11;
    i_email ALIAS FOR $12;
    i_homepage ALIAS FOR $13;
BEGIN
    UPDATE members SET nickname=i_nickname,
                       firstname=i_firstname,
                       lastname=i_lastname,
                       street=i_street,
                       housenumber=i_housenumber,
                       zip=i_zip,
                       town=i_town,
                       telephone=i_telephone,
                       mobilephone=i_mobilephone,
                       fax=i_fax,
                       email=i_email,
                       homepage=i_homepage 
      WHERE id=i_id
      and not (  coalesce(nickname, '')=coalesce(i_nickname, '')
      and coalesce(firstname, '')=coalesce(i_firstname, '')
      and coalesce(lastname, '')=coalesce(i_lastname, '')
      and coalesce(street, '')=coalesce(i_street, '')
      and coalesce(housenumber, '')=coalesce(i_housenumber, '')
      and coalesce(zip, '')=coalesce(i_zip, '')
      and coalesce(town, '')=coalesce(i_town, '')
      and coalesce(telephone, '')=coalesce(i_telephone, '')
      and coalesce(mobilephone, '')=coalesce(i_mobilephone, '')
      and coalesce(fax, '')=coalesce(i_fax, '')
      and coalesce(email, '')=coalesce(i_email, '')
      and coalesce(homepage, '')=coalesce(i_homepage, '')
    );
    RETURN;
END;
$_$;



--
-- Name: updatenode(integer, character varying, double precision, double precision, double precision, double precision, double precision, double precision, boolean); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.updatenode(integer, character varying, double precision, double precision, double precision, double precision, double precision, double precision, boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
/**
 * updatenode
 *
 * $1 -- id
 * $2 -- name
 * $3 -- gps_lat_deg
 * $4 -- gps_lat_min
 * $5 -- gps_lat_sec
 * $6 -- gps_lon_deg
 * $7 -- gps_lon_min
 * $8 -- gps_lon_sec
 * $9 -- map
 **/

DECLARE
    i_id ALIAS FOR $1;
    i_name ALIAS FOR $2;
    i_gps_lat_deg ALIAS FOR $3;
    i_gps_lat_min ALIAS FOR $4;
    i_gps_lat_sec ALIAS FOR $5;
    i_gps_lon_deg ALIAS FOR $6;
    i_gps_lon_min ALIAS FOR $7;
    i_gps_lon_sec ALIAS FOR $8;
    i_map ALIAS FOR $9;
BEGIN
    UPDATE nodes SET name=i_name,
                     gps_lat_deg=i_gps_lat_deg,
                     gps_lat_min=i_gps_lat_min,
                     gps_lat_sec=i_gps_lat_sec,
                     gps_lon_deg=i_gps_lon_deg,
                     gps_lon_min=i_gps_lon_min,
                     gps_lon_sec=i_gps_lon_sec,
                     map=i_map WHERE id=i_id;
    RETURN;
END;
$_$;



--
-- Name: updatev642(bigint, bigint, character varying, boolean, boolean, boolean, boolean); Type: FUNCTION; Schema: public
--

CREATE FUNCTION public.updatev642(bigint, bigint, character varying, boolean, boolean, boolean, boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    AS $_$
DECLARE
    i_id_devices ALIAS FOR $1;
    i_id_ips ALIAS FOR $2;
    i_name ALIAS FOR $3;
    i_smokeping ALIAS FOR $4;
    i_forward ALIAS FOR $5;
    i_reverse ALIAS FOR $6;
    i_nodemaster ALIAS FOR $7;
    o_id_nodes bigint;
    o_ipv4_oldid int4;
    o_ipv4 character varying;
BEGIN
    SELECT INTO o_ipv4_oldid id FROM ips WHERE id_devices=i_id_devices; /*get old ipv4*/
    if (o_ipv4_oldid != i_id_ips) then /*user changed the ipv4 address*/
        select into o_id_nodes id_nodes from devices where id=i_id_devices;
        perform deletev642(i_id_devices); /*delete old data, then insert new from scratch*/
        perform insertv642(o_id_nodes, i_id_ips,i_name,i_smokeping,i_forward,i_reverse);
    else
        /*update attributes, keep ips the same*/
        SELECT INTO o_ipv4 ip FROM ips WHERE id=o_ipv4_oldid;
        UPDATE devices SET name=i_name,
        smokeping=i_smokeping,
        nodemaster=i_nodemaster
        WHERE id=i_id_devices;
        UPDATE ip6     set name='s-'||i_name,
        dns_forward=i_forward,
        dns_reverse=i_reverse
        where id_devices=i_id_devices
        and   ip6='2a02:61:0:ee:0:ffff:'||ip4_to_hex(cast(o_ipv4 as inet));
        UPDATE ip6     set name=i_name,
        smokeping=i_smokeping,
        dns_forward=i_forward,
        dns_reverse=i_reverse,
        nodemaster=i_nodemaster
        where id_devices=i_id_devices
        and   ip6='2a02:61:0:ee:1:ffff:'||ip4_to_hex(cast(o_ipv4 as inet));
    end if;
    RETURN;
END;
$_$;



SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: devices; Type: TABLE; Schema: public
--

CREATE TABLE public.devices (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    antenna character varying(255),
    hardware character varying(255),
    ssid character varying(255),
    mac character varying(17),
    smokeping boolean DEFAULT true NOT NULL,
    last_seen timestamp without time zone,
    id_nodes bigint,
    id_members bigint,
    created timestamp with time zone,
    changed timestamp with time zone,
    delete_mail timestamp without time zone,
    delete_protected boolean DEFAULT false NOT NULL,
    comment character varying(8000),
    nodemaster boolean DEFAULT false NOT NULL
);



--
-- Name: COLUMN devices.comment; Type: COMMENT; Schema: public
--

COMMENT ON COLUMN public.devices.comment IS 'comment for the device';


--
-- Name: devices_id_seq; Type: SEQUENCE; Schema: public
--

CREATE SEQUENCE public.devices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;



--
-- Name: ip6; Type: TABLE; Schema: public
--

CREATE TABLE public.ip6 (
    id bigint NOT NULL,
    ip6 character varying(39) NOT NULL,
    dns_forward boolean NOT NULL,
    custom_forward character varying(100),
    dns_reverse boolean NOT NULL,
    custom_reverse character varying(100),
    id_devices bigint,
    id_members bigint,
    id_nodes bigint,
    name character varying(100),
    antenna character varying(255),
    hardware character varying(255),
    ssid character varying(255),
    mac character varying(17),
    smokeping boolean DEFAULT true NOT NULL,
    last_seen timestamp without time zone,
    created timestamp with time zone,
    changed timestamp with time zone,
    nodemaster boolean DEFAULT false NOT NULL
);



--
-- Name: ips; Type: TABLE; Schema: public
--

CREATE TABLE public.ips (
    id bigint NOT NULL,
    ip character varying(15) NOT NULL,
    cidr character varying(2) NOT NULL,
    usage character varying(100) NOT NULL,
    dns_forward boolean NOT NULL,
    custom_forward character varying(100),
    dns_reverse boolean NOT NULL,
    custom_reverse character varying(100),
    id_devices bigint,
    id_members bigint,
    id_nodes bigint,
    last_seen timestamp without time zone
);



--
-- Name: nodes; Type: TABLE; Schema: public
--

CREATE TABLE public.nodes (
    id bigint NOT NULL,
    name character varying(250) NOT NULL,
    gps_lat_deg double precision,
    gps_lat_min double precision,
    gps_lat_sec double precision,
    gps_lon_deg double precision,
    gps_lon_min double precision,
    gps_lon_sec double precision,
    map boolean DEFAULT true NOT NULL,
    id_members bigint NOT NULL,
    created timestamp with time zone,
    changed timestamp with time zone,
    id_tech_c bigint NOT NULL,
    last_seen timestamp without time zone
);



--
-- Name: COLUMN nodes.id_tech_c; Type: COMMENT; Schema: public
--

COMMENT ON COLUMN public.nodes.id_tech_c IS 'references table members';


--
-- Name: ip6_id_seq; Type: SEQUENCE; Schema: public
--

CREATE SEQUENCE public.ip6_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;



--
-- Name: members; Type: TABLE; Schema: public
--

CREATE TABLE public.members (
    id bigint NOT NULL,
    nickname character varying(25) NOT NULL,
    firstname character varying(50),
    lastname character varying(50),
    street character varying(100),
    housenumber character varying(20),
    zip character varying(10),
    town character varying(100),
    telephone character varying(25),
    mobilephone character varying(25),
    fax character varying(25),
    email character varying(50),
    homepage character varying(50),
    created timestamp with time zone,
    changed timestamp with time zone,
    mentor_id bigint,
    instant_messenger_nick character varying(50),
    last_login timestamp without time zone
);



--
-- Name: ips_id_seq; Type: SEQUENCE; Schema: public
--

CREATE SEQUENCE public.ips_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;



--
-- Name: members_roles; Type: TABLE; Schema: public
--

CREATE TABLE public.members_roles (
    id_roles bigint NOT NULL,
    id_members bigint NOT NULL
);



--
-- Name: roles; Type: TABLE; Schema: public
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(100) NOT NULL
);



--
-- Name: members_id_seq; Type: SEQUENCE; Schema: public
--

CREATE SEQUENCE public.members_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;



--
-- Name: nodes_id_seq; Type: SEQUENCE; Schema: public
--

CREATE SEQUENCE public.nodes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;



--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;



--
-- Name: verify; Type: TABLE; Schema: public
--

CREATE TABLE public.verify (
    hash character varying(32) NOT NULL,
    last_checked timestamp without time zone,
    code bigint,
    ca bigint,
    text character varying(300)
);



--
-- Name: devices devices_pkey; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_pkey PRIMARY KEY (id);


--
-- Name: devices devices_unique1; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_unique1 UNIQUE (id_nodes, name);


--
-- Name: devices devices_unique2; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_unique2 UNIQUE (mac);


--
-- Name: verify hash_pkey; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.verify
    ADD CONSTRAINT hash_pkey PRIMARY KEY (hash);


--
-- Name: ips id_devices_unique; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ips
    ADD CONSTRAINT id_devices_unique UNIQUE (id_devices);


--
-- Name: ip6 ip6_pkey; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ip6
    ADD CONSTRAINT ip6_pkey PRIMARY KEY (id);


--
-- Name: ips ips_ip_key; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ips
    ADD CONSTRAINT ips_ip_key UNIQUE (ip);


--
-- Name: ips ips_pkey; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ips
    ADD CONSTRAINT ips_pkey PRIMARY KEY (id);


--
-- Name: ips ips_unique; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ips
    ADD CONSTRAINT ips_unique UNIQUE (ip);


--
-- Name: members members_pkey; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.members
    ADD CONSTRAINT members_pkey PRIMARY KEY (id);


--
-- Name: members_roles members_roles_pk; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.members_roles
    ADD CONSTRAINT members_roles_pk PRIMARY KEY (id_roles, id_members);


--
-- Name: members members_unique; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.members
    ADD CONSTRAINT members_unique UNIQUE (nickname);


--
-- Name: nodes nodes_pkey; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.nodes
    ADD CONSTRAINT nodes_pkey PRIMARY KEY (id);


--
-- Name: nodes nodes_unique; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.nodes
    ADD CONSTRAINT nodes_unique UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: roles roles_unique; Type: CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_unique UNIQUE (name);


--
-- Name: devices_unique_ci; Type: INDEX; Schema: public
--

CREATE UNIQUE INDEX devices_unique_ci ON public.devices USING btree (id_nodes, lower((name)::text));


--
-- Name: ip6_unique6; Type: INDEX; Schema: public
--

CREATE UNIQUE INDEX ip6_unique6 ON public.ip6 USING btree (lower((ip6)::text));


--
-- Name: members_unique_insensitive; Type: INDEX; Schema: public
--

CREATE UNIQUE INDEX members_unique_insensitive ON public.members USING btree (lower((nickname)::text));


--
-- Name: nodes_unique_ci; Type: INDEX; Schema: public
--

CREATE UNIQUE INDEX nodes_unique_ci ON public.nodes USING btree (lower((name)::text));


--
-- Name: devices changed; Type: TRIGGER; Schema: public
--

CREATE TRIGGER changed BEFORE UPDATE OF id, name, antenna, hardware, ssid, mac, id_nodes, id_members, comment, smokeping, nodemaster ON public.devices FOR EACH ROW EXECUTE FUNCTION public.changed();


--
-- Name: ip6 changed; Type: TRIGGER; Schema: public
--

CREATE TRIGGER changed BEFORE UPDATE OF ip6, dns_forward, custom_forward, dns_reverse, custom_reverse, id_devices, id_members, id_nodes, name, antenna, hardware, ssid, mac, smokeping, nodemaster ON public.ip6 FOR EACH ROW EXECUTE FUNCTION public.changed();


--
-- Name: members changed; Type: TRIGGER; Schema: public
--

CREATE TRIGGER changed BEFORE UPDATE OF nickname, firstname, lastname, street, housenumber, zip, town, telephone, fax, email, homepage, mentor_id, instant_messenger_nick ON public.members FOR EACH ROW EXECUTE FUNCTION public.changed();


--
-- Name: nodes changed; Type: TRIGGER; Schema: public
--

CREATE TRIGGER changed BEFORE UPDATE OF name, gps_lat_deg, gps_lat_min, gps_lat_sec, gps_lon_deg, gps_lon_min, gps_lon_sec, map, id_members, id_tech_c ON public.nodes FOR EACH ROW EXECUTE FUNCTION public.changed();


--
-- Name: devices created; Type: TRIGGER; Schema: public
--

CREATE TRIGGER created BEFORE INSERT ON public.devices FOR EACH ROW EXECUTE FUNCTION public.created();


--
-- Name: ip6 created; Type: TRIGGER; Schema: public
--

CREATE TRIGGER created BEFORE INSERT ON public.ip6 FOR EACH ROW EXECUTE FUNCTION public.created();


--
-- Name: members created; Type: TRIGGER; Schema: public
--

CREATE TRIGGER created BEFORE INSERT ON public.members FOR EACH ROW EXECUTE FUNCTION public.created();


--
-- Name: nodes created; Type: TRIGGER; Schema: public
--

CREATE TRIGGER created BEFORE INSERT ON public.nodes FOR EACH ROW EXECUTE FUNCTION public.created();


--
-- Name: devices ip4_unique_aaaa; Type: TRIGGER; Schema: public
--

CREATE TRIGGER ip4_unique_aaaa BEFORE INSERT OR UPDATE OF id_nodes, id_members, name ON public.devices FOR EACH ROW EXECUTE FUNCTION public.ip4_unique_aaaa();


--
-- Name: devices ip4_unique_master; Type: TRIGGER; Schema: public
--

CREATE TRIGGER ip4_unique_master BEFORE INSERT OR UPDATE OF id_nodes, id, nodemaster ON public.devices FOR EACH ROW EXECUTE FUNCTION public.ip4_unique_master();


--
-- Name: ip6 ip6_unique_aaaa; Type: TRIGGER; Schema: public
--

CREATE TRIGGER ip6_unique_aaaa BEFORE INSERT OR UPDATE OF id_nodes, id_members, id_devices, name, dns_forward ON public.ip6 FOR EACH ROW EXECUTE FUNCTION public.ip6_unique_aaaa();


--
-- Name: ip6 ip6_unique_master; Type: TRIGGER; Schema: public
--

CREATE TRIGGER ip6_unique_master BEFORE INSERT OR UPDATE OF id_nodes, id_devices, nodemaster ON public.ip6 FOR EACH ROW EXECUTE FUNCTION public.ip6_unique_master();


--
-- Name: devices devices_fkey1; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_fkey1 FOREIGN KEY (id_nodes) REFERENCES public.nodes(id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: devices devices_fkey2; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_fkey2 FOREIGN KEY (id_members) REFERENCES public.members(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: ip6 ip6_fkey1; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ip6
    ADD CONSTRAINT ip6_fkey1 FOREIGN KEY (id_nodes) REFERENCES public.nodes(id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: ip6 ip6_fkey2; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ip6
    ADD CONSTRAINT ip6_fkey2 FOREIGN KEY (id_devices) REFERENCES public.devices(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: ips ips_fkey1; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ips
    ADD CONSTRAINT ips_fkey1 FOREIGN KEY (id_members) REFERENCES public.members(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: ips ips_fkey2; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ips
    ADD CONSTRAINT ips_fkey2 FOREIGN KEY (id_nodes) REFERENCES public.nodes(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: ips ips_fkey3; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.ips
    ADD CONSTRAINT ips_fkey3 FOREIGN KEY (id_devices) REFERENCES public.devices(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: members_roles members_roles_fk1; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.members_roles
    ADD CONSTRAINT members_roles_fk1 FOREIGN KEY (id_members) REFERENCES public.members(id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: members_roles members_roles_fk2; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.members_roles
    ADD CONSTRAINT members_roles_fk2 FOREIGN KEY (id_roles) REFERENCES public.roles(id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: nodes nodes_fkey1; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.nodes
    ADD CONSTRAINT nodes_fkey1 FOREIGN KEY (id_members) REFERENCES public.members(id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: nodes nodes_fkey2; Type: FK CONSTRAINT; Schema: public
--

ALTER TABLE ONLY public.nodes
    ADD CONSTRAINT nodes_fkey2 FOREIGN KEY (id_tech_c) REFERENCES public.members(id);

