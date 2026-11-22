-- Public CI fixture schema, written independently for the rewrite test stack.
--
-- It mirrors the tables, column names/types, constraints and routine signatures
-- the application is known to depend on (see app/docs/teststack.md), but it is NOT
-- the original production schema: it has no views, no backup tables, no
-- DNS/Smokeping objects and only a subset of the routines. For contract checks
-- against the original, import the original dump instead (REWRITE_SCHEMA_FILE).

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    CONSTRAINT roles_pkey PRIMARY KEY (id),
    CONSTRAINT roles_unique UNIQUE (name)
);

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
    last_login timestamp without time zone,
    CONSTRAINT members_pkey PRIMARY KEY (id),
    CONSTRAINT members_unique UNIQUE (nickname)
);
CREATE UNIQUE INDEX members_unique_insensitive ON public.members USING btree (lower((nickname)::text));

CREATE TABLE public.members_roles (
    id_roles bigint NOT NULL,
    id_members bigint NOT NULL,
    CONSTRAINT members_roles_pk PRIMARY KEY (id_roles, id_members)
);

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
    last_seen timestamp without time zone,
    CONSTRAINT nodes_pkey PRIMARY KEY (id),
    CONSTRAINT nodes_unique UNIQUE (name)
);
CREATE UNIQUE INDEX nodes_unique_ci ON public.nodes USING btree (lower((name)::text));

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
    nodemaster boolean DEFAULT false NOT NULL,
    CONSTRAINT devices_pkey PRIMARY KEY (id),
    CONSTRAINT devices_unique1 UNIQUE (id_nodes, name),
    CONSTRAINT devices_unique2 UNIQUE (mac)
);
CREATE UNIQUE INDEX devices_unique_ci ON public.devices USING btree (id_nodes, lower((name)::text));

-- IPv4 addresses; free pool entries have usage 'main' or 'v642' and no id_devices.
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
    last_seen timestamp without time zone,
    CONSTRAINT ips_pkey PRIMARY KEY (id),
    CONSTRAINT ips_ip_key UNIQUE (ip),
    CONSTRAINT id_devices_unique UNIQUE (id_devices)
);

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
    nodemaster boolean DEFAULT false NOT NULL,
    CONSTRAINT ip6_pkey PRIMARY KEY (id)
);
CREATE UNIQUE INDEX ip6_unique6 ON public.ip6 USING btree (lower((ip6)::text));

CREATE TABLE public.verify (
    hash character varying(32) NOT NULL,
    last_checked timestamp without time zone,
    code bigint,
    ca bigint,
    text character varying(300),
    CONSTRAINT hash_pkey PRIMARY KEY (hash)
);

CREATE SEQUENCE public.roles_id_seq;
CREATE SEQUENCE public.members_id_seq;
CREATE SEQUENCE public.nodes_id_seq;
CREATE SEQUENCE public.devices_id_seq;
CREATE SEQUENCE public.ips_id_seq;
CREATE SEQUENCE public.ip6_id_seq;

CREATE FUNCTION public.created() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.created := date_trunc('seconds', now());
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.changed() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.changed := date_trunc('seconds', now());
    RETURN NEW;
END;
$$;

CREATE TRIGGER created BEFORE INSERT ON public.members FOR EACH ROW EXECUTE FUNCTION public.created();
CREATE TRIGGER created BEFORE INSERT ON public.nodes FOR EACH ROW EXECUTE FUNCTION public.created();
CREATE TRIGGER created BEFORE INSERT ON public.devices FOR EACH ROW EXECUTE FUNCTION public.created();
CREATE TRIGGER created BEFORE INSERT ON public.ip6 FOR EACH ROW EXECUTE FUNCTION public.created();
CREATE TRIGGER changed BEFORE UPDATE OF nickname, firstname, lastname, street, housenumber, zip, town, telephone, fax, email, homepage ON public.members FOR EACH ROW EXECUTE FUNCTION public.changed();
CREATE TRIGGER changed BEFORE UPDATE OF name, id_members, id_tech_c ON public.nodes FOR EACH ROW EXECUTE FUNCTION public.changed();
CREATE TRIGGER changed BEFORE UPDATE OF name, id_nodes, id_members ON public.devices FOR EACH ROW EXECUTE FUNCTION public.changed();
CREATE TRIGGER changed BEFORE UPDATE OF ip6, id_devices, id_members, id_nodes ON public.ip6 FOR EACH ROW EXECUTE FUNCTION public.changed();

-- Returns the new member id; the caller copies it into LDAP (uidNumber/gidNumber).
CREATE FUNCTION public.insertmember(
    nickname character varying, firstname character varying, lastname character varying,
    street character varying, housenumber character varying, zip character varying,
    town character varying, telephone character varying, mobilephone character varying,
    fax character varying, email character varying, homepage character varying
) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER AS $$
DECLARE
    new_id integer := nextval('public.members_id_seq');
BEGIN
    INSERT INTO public.members (id, nickname, firstname, lastname, street, housenumber, zip, town,
                                telephone, mobilephone, fax, email, homepage)
    VALUES (new_id, $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12);
    RETURN new_id;
END;
$$;

CREATE FUNCTION public.updatemember(
    id integer, nickname character varying, firstname character varying, lastname character varying,
    street character varying, housenumber character varying, zip character varying,
    town character varying, telephone character varying, mobilephone character varying,
    fax character varying, email character varying, homepage character varying
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN
    UPDATE public.members m
       SET nickname = $2, firstname = $3, lastname = $4, street = $5, housenumber = $6, zip = $7,
           town = $8, telephone = $9, mobilephone = $10, fax = $11, email = $12, homepage = $13
     WHERE m.id = $1;
END;
$$;

CREATE FUNCTION public.deletemember(integer) RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN
    DELETE FROM public.members_roles WHERE id_members = $1;
    DELETE FROM public.members WHERE id = $1;
END;
$$;

-- Stamps last_login for the lower-cased nickname.
CREATE FUNCTION public.member_login(character varying) RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN
    UPDATE public.members
       SET last_login = date_trunc('second', now())
     WHERE id = (SELECT min(id) FROM public.members WHERE nickname = lower($1) AND id >= 0);
END;
$$;

-- Newer observation times for an address, propagated to IP, device and node.
CREATE FUNCTION public.update_lastseen(character varying, timestamp without time zone)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS $$
DECLARE
    seen timestamp without time zone := coalesce($2, date_trunc('second', now()));
BEGIN
    UPDATE public.ips SET last_seen = seen WHERE ip = $1 AND (last_seen < seen OR last_seen IS NULL);
    UPDATE public.devices SET last_seen = seen
     WHERE id IN (SELECT id_devices FROM public.ips WHERE ip = $1)
       AND (last_seen < seen OR last_seen IS NULL);
    UPDATE public.nodes SET last_seen = seen
     WHERE id IN (SELECT d.id_nodes FROM public.ips i JOIN public.devices d ON d.id = i.id_devices WHERE i.ip = $1)
       AND (last_seen < seen OR last_seen IS NULL);
END;
$$;
