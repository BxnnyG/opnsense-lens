"""
The store: schema, migrations, and the queries the collector needs.

Versioned from the first commit. A schema change after this ships is a migration
whether or not anyone planned for one, and discovering that during an upgrade is
the expensive way to find out.

Mode 0600 throughout: every row here describes what a person did on the network.
"""

import os
import sqlite3
import time

from lenslib import settings as settingslib

SCHEMA_VERSION = 11

# No stored window is longer than this (§4.69). A device present for weeks is a
# chain of day-long pieces, joined back into one stay for every reader; the cap
# is what lets the attribution join seek instead of scanning every window.
WINDOW_MAX = 86400

# the open end of a range that has none
FOREVER = 2 ** 62

# every key, its default and its bounds live in lenslib.settings (§4.58)
DEFAULT_SETTINGS = settingslib.defaults()

MIGRATIONS = {
    1: [
        """CREATE TABLE device (
            mac TEXT PRIMARY KEY,
            first_seen INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            randomised INTEGER NOT NULL DEFAULT 0,
            is_local INTEGER NOT NULL DEFAULT 0,
            hostname TEXT,
            hostname_source TEXT
        )""",
        """CREATE TABLE address_observation (
            mac TEXT NOT NULL,
            address TEXT NOT NULL,
            interface TEXT NOT NULL,
            first_seen INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            PRIMARY KEY (mac, address, interface, first_seen)
        )""",
        "CREATE INDEX address_observation_by_address ON address_observation(address, first_seen)",
        """CREATE TABLE traffic_hour (
            bucket INTEGER NOT NULL,
            address TEXT NOT NULL,
            direction TEXT NOT NULL,
            octets INTEGER NOT NULL,
            packets INTEGER NOT NULL,
            PRIMARY KEY (bucket, address, direction)
        )""",
        "CREATE TABLE harvest_state (provider TEXT PRIMARY KEY, last_bucket INTEGER NOT NULL)",
        """CREATE TABLE run_log (
            duty TEXT NOT NULL,
            at INTEGER NOT NULL,
            ok INTEGER NOT NULL,
            took_ms INTEGER NOT NULL,
            detail TEXT,
            PRIMARY KEY (duty, at)
        )""",
        "CREATE TABLE setting (key TEXT PRIMARY KEY, value TEXT NOT NULL)",
    ],
    # v1 harvested without the interface, which is the only thing separating a
    # device from the far end of its own connection (parse.buckets_from_timeseries).
    # The old rows are unusable rather than merely incomplete, so they go, and
    # the watermark is reset so the next harvest refetches the window netflow
    # still holds. Nothing is lost that netflow has not already deleted.
    2: [
        "DROP TABLE traffic_hour",
        """CREATE TABLE traffic_hour (
            bucket INTEGER NOT NULL,
            interface TEXT NOT NULL,
            address TEXT NOT NULL,
            direction TEXT NOT NULL,
            octets INTEGER NOT NULL,
            packets INTEGER NOT NULL,
            PRIMARY KEY (bucket, interface, address, direction)
        )""",
        "CREATE INDEX traffic_hour_by_address ON traffic_hour(address, bucket)",
        "DELETE FROM harvest_state",
    ],
    # Attribution joins observations onto buckets by (address, interface).
    # v1's index was (address, first_seen), which nothing reads that way and
    # which leaves the join scanning every window for every bucket. The leading
    # column is unchanged, so anything looking up by address alone still uses it.
    3: [
        "DROP INDEX address_observation_by_address",
        "CREATE INDEX address_observation_by_address"
        " ON address_observation(address, interface)",
    ],
    # What the operator says, kept apart from what the box observed. Nothing in
    # the collector ever writes this table and nothing outside the operator ever
    # reads over it: an observation cannot overwrite a name a person chose.
    4: [
        """CREATE TABLE device_label (
            mac TEXT PRIMARY KEY,
            name TEXT,
            kind TEXT,
            tags TEXT,
            note TEXT,
            updated INTEGER NOT NULL
        )""",
    ],
    # What dpinger measured on each gateway, every observation run. Core keeps
    # this in RRD graphs of its own; Lens keeps it beside the traffic so the two
    # can be read against each other on one time axis.
    5: [
        """CREATE TABLE gateway_sample (
            at INTEGER NOT NULL,
            name TEXT NOT NULL,
            delay REAL,
            stddev REAL,
            loss REAL,
            status TEXT,
            PRIMARY KEY (at, name)
        )""",
    ],
    # Round trips Lens measures itself, to the public resolvers people know by
    # name. The first traffic this plugin originates (§4.57).
    6: [
        """CREATE TABLE probe_sample (
            at INTEGER NOT NULL,
            target TEXT NOT NULL,
            rtt REAL,
            stddev REAL,
            loss REAL,
            PRIMARY KEY (at, target)
        )""",
    ],
    # Who each device talked to, per day (§4.62): core's FlowSourceAddrDetails,
    # device segments only, the top 25 destinations per address per day and one
    # summed '*' row for the rest. Opt-in; empty until switched on.
    7: [
        """CREATE TABLE destination_day (
            day INTEGER NOT NULL,
            interface TEXT NOT NULL,
            address TEXT NOT NULL,
            peer TEXT NOT NULL,
            port INTEGER NOT NULL,
            protocol INTEGER NOT NULL,
            direction TEXT NOT NULL,
            octets INTEGER NOT NULL,
            packets INTEGER NOT NULL,
            PRIMARY KEY (day, interface, address, peer, port, protocol, direction)
        )""",
        "CREATE INDEX destination_day_by_address ON destination_day(address, interface, day)",
    ],
    # The operator's mute (§4.63): kept with the rest of what they said about a
    # device, in the table the collector never writes.
    8: [
        "ALTER TABLE device_label ADD COLUMN muted INTEGER NOT NULL DEFAULT 0",
    ],
    # Whose it is (§4.67): the operator's word, beside the name they gave it.
    9: [
        "ALTER TABLE device_label ADD COLUMN owner TEXT",
    ],
    # Stage 41 (§4.69): a span index the join can seek on, windows cut into
    # day-long pieces so it may, and complete days summed once per device.
    # The cut keeps every second: the first piece ends where the second begins.
    10: [
        "CREATE INDEX address_observation_span"
        " ON address_observation(address, interface, first_seen, last_seen)",
        "DROP INDEX address_observation_by_address",
        """WITH RECURSIVE piece(mac, address, interface, first_seen, last_seen, end_at) AS (
               SELECT mac, address, interface, first_seen + 86400,
                      min(first_seen + 172800, last_seen), last_seen
               FROM address_observation WHERE last_seen - first_seen > 86400
               UNION ALL
               SELECT mac, address, interface, first_seen + 86400,
                      min(first_seen + 172800, end_at), end_at
               FROM piece WHERE end_at - first_seen > 86400
           )
           INSERT OR IGNORE INTO address_observation (mac, address, interface, first_seen, last_seen)
           SELECT mac, address, interface, first_seen, last_seen FROM piece""",
        "UPDATE address_observation SET last_seen = first_seen + 86400 WHERE last_seen - first_seen > 86400",
        """CREATE TABLE device_day (
            mac TEXT NOT NULL,
            day INTEGER NOT NULL,
            octets INTEGER NOT NULL,
            sent INTEGER NOT NULL,
            PRIMARY KEY (mac, day)
        )""",
        "CREATE TABLE device_day_done (day INTEGER PRIMARY KEY, at INTEGER NOT NULL)",
    ],
    # Every settled hour attributed once (§4.76). The device list summed a
    # week of raw buckets on every page load: 8.3 s on the second firewall
    # (2.4 million rows). Now the harvest settles each closed hour into per-
    # device and per-reason sums, and pages add those up.
    11: [
        """CREATE TABLE device_hour (
            bucket INTEGER NOT NULL,
            mac TEXT NOT NULL,
            sent INTEGER NOT NULL,
            sent_packets INTEGER NOT NULL,
            received INTEGER NOT NULL,
            received_packets INTEGER NOT NULL,
            PRIMARY KEY (bucket, mac)
        )""",
        """CREATE TABLE unattributed_hour (
            bucket INTEGER NOT NULL,
            reason TEXT NOT NULL,
            octets INTEGER NOT NULL,
            packets INTEGER NOT NULL,
            rows INTEGER NOT NULL,
            PRIMARY KEY (bucket, reason)
        )""",
        "CREATE TABLE hour_done (bucket INTEGER PRIMARY KEY, at INTEGER NOT NULL)",
    ],
}


# One definition of "who held this address in this hour", used by the list and
# by the detail view. Written once because a detail total that disagrees with
# the row it was opened from destroys the credibility of both, and two copies of
# a join drift the first time one of them is corrected.
#
# The window test is an overlap, not containment: an observation opened at 14:32
# covers the 14:00 bucket, because the device was there for part of the hour the
# bucket measures. `macs` counts how many distinct devices the overlap found --
# more than one means the bucket cannot be attributed at all, and both readers
# refuse it rather than picking one.
#
# Grouping is on traffic_hour's own primary key, so the join can never multiply
# the octets it is counting. Parameters, in order: bucket_seconds, since, until.
#
# `first_seen >= bucket - WINDOW_MAX` changes nothing about which windows match
# -- no piece is longer than WINDOW_MAX, so one that reaches the bucket started
# at most that long before it -- and turns the join from a scan of every window
# of the address into a seek on the span index (§4.69).
ATTRIBUTION_SQL = """
    SELECT t.bucket AS bucket, t.interface AS interface, t.address AS address,
           t.direction AS direction, t.octets AS octets, t.packets AS packets,
           count(DISTINCT o.mac) AS macs, min(o.mac) AS mac
    FROM traffic_hour t
    LEFT JOIN address_observation o
      ON o.address = t.address
     AND o.interface = t.interface
     AND o.first_seen >= t.bucket - %d
     AND o.first_seen < t.bucket + ?
     AND o.last_seen >= t.bucket
    WHERE t.bucket >= ? AND t.bucket < ?
    GROUP BY t.bucket, t.interface, t.address, t.direction
""" % WINDOW_MAX


def stays(rows):
    """
    Pieces of one window joined back into the stay they were cut from (§4.69):
    same device, address and interface, one starting where the last one ended.

    :param rows: mac, address, interface, first_seen, last_seen -- ordered by
                 mac, address, interface, first_seen
    :return: list of dicts with the same keys
    """
    out = []
    for row in rows:
        row = {key: row[key] for key in ('mac', 'address', 'interface', 'first_seen', 'last_seen')}
        last = out[-1] if out else None
        if last and (last['mac'], last['address'], last['interface']) == \
                (row['mac'], row['address'], row['interface']) and row['first_seen'] <= last['last_seen']:
            last['last_seen'] = max(last['last_seen'], row['last_seen'])
        else:
            out.append(row)
    return out


class Store:
    def __init__(self, path):
        self.path = path
        directory = os.path.dirname(path)
        if directory and not os.path.isdir(directory):
            os.makedirs(directory, mode=0o700)

        fresh = not os.path.exists(path)
        # a reader that arrives during a write waits its turn instead of
        # reporting "database is locked", which is what the operator's second
        # firewall answered while a first harvest was still inserting
        self.db = sqlite3.connect(path, timeout=30)
        self.db.row_factory = sqlite3.Row
        if fresh:
            os.chmod(path, 0o600)

        self._migrate()

    # ------------------------------------------------------------ schema

    def _migrate(self):
        self.db.execute("CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)")
        row = self.db.execute("SELECT max(version) AS v FROM schema_version").fetchone()
        current = row['v'] or 0

        for version in sorted(MIGRATIONS):
            if version <= current:
                continue
            for statement in MIGRATIONS[version]:
                self.db.execute(statement)
            self.db.execute("INSERT INTO schema_version(version) VALUES (?)", (version,))

        if current == 0:
            for key, value in DEFAULT_SETTINGS.items():
                self.db.execute("INSERT OR IGNORE INTO setting(key, value) VALUES (?, ?)", (key, value))

        self.db.commit()

    def setting(self, key, default=None):
        row = self.db.execute("SELECT value FROM setting WHERE key = ?", (key,)).fetchone()
        if row is None:
            return DEFAULT_SETTINGS.get(key, default)
        return row['value']

    def setting_int(self, key):
        try:
            return int(self.setting(key))
        except (TypeError, ValueError):
            return int(DEFAULT_SETTINGS[key])

    def stored_settings(self):
        """:return: key to stored text, exactly as the table holds it"""
        return {row['key']: row['value'] for row in self.db.execute("SELECT key, value FROM setting")}

    def settings(self):
        """:return: every setting, typed; unreadable values are their default"""
        return settingslib.load(self.stored_settings())

    def set_settings(self, clean):
        """:param clean: key to stored text, already through settings.validate"""
        self.db.executemany(
            "INSERT OR REPLACE INTO setting(key, value) VALUES (?, ?)",
            sorted(clean.items()),
        )

    # ------------------------------------------------------- observations

    def open_windows(self):
        """:return: dict of (mac, address, interface) to the window's last_seen"""
        rows = self.db.execute(
            """SELECT mac, address, interface, max(last_seen) AS last_seen
               FROM address_observation GROUP BY mac, address, interface"""
        )
        return {(r['mac'], r['address'], r['interface']): r['last_seen'] for r in rows}

    def extend_windows(self, keys, now):
        """
        Carry each window on to `now`. One that has run WINDOW_MAX continues in
        a new piece starting where it stopped (§4.69): no second is lost or
        doubled, and the join can keep seeking instead of scanning.
        """
        for mac, address, interface in keys:
            row = self.db.execute(
                """SELECT first_seen, last_seen FROM address_observation
                   WHERE mac = ? AND address = ? AND interface = ?
                   ORDER BY first_seen DESC LIMIT 1""",
                (mac, address, interface),
            ).fetchone()
            if row is None:
                continue
            if now - row['first_seen'] > WINDOW_MAX and row['last_seen'] > row['first_seen']:
                self.db.execute(
                    """INSERT OR IGNORE INTO address_observation
                       (mac, address, interface, first_seen, last_seen) VALUES (?, ?, ?, ?, ?)""",
                    (mac, address, interface, row['last_seen'], now),
                )
            else:
                self.db.execute(
                    """UPDATE address_observation SET last_seen = ?
                       WHERE mac = ? AND address = ? AND interface = ? AND first_seen = ?""",
                    (now, mac, address, interface, row['first_seen']),
                )

    def record_window(self, mac, address, interface, first_seen, last_seen):
        """
        A whole window written at once -- imports, fixtures, the preview -- in
        the day-long pieces every stored window is kept in (§4.69).
        """
        start = int(first_seen)
        while True:
            end = min(start + WINDOW_MAX, int(last_seen))
            self.db.execute(
                """INSERT OR IGNORE INTO address_observation
                   (mac, address, interface, first_seen, last_seen) VALUES (?, ?, ?, ?, ?)""",
                (mac, address, interface, start, end),
            )
            if end >= int(last_seen):
                return
            start = end

    def open_new_windows(self, keys, now):
        self.db.executemany(
            """INSERT OR IGNORE INTO address_observation
               (mac, address, interface, first_seen, last_seen) VALUES (?, ?, ?, ?, ?)""",
            [(mac, address, interface, now, now) for mac, address, interface in keys],
        )

    def see_device(self, mac, now, randomised, is_local, hostname=None, source=None):
        self.db.execute(
            """INSERT INTO device (mac, first_seen, last_seen, randomised, is_local,
                                   hostname, hostname_source)
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(mac) DO UPDATE SET
                   last_seen = excluded.last_seen,
                   is_local = excluded.is_local,
                   hostname = COALESCE(excluded.hostname, device.hostname),
                   hostname_source = COALESCE(excluded.hostname_source, device.hostname_source)""",
            (mac, now, now, int(randomised), int(is_local), hostname, source),
        )

    def unnamed(self):
        """:return: the MACs no source has named yet (for the reverse lookup, §4.81)"""
        return {row[0] for row in self.db.execute('SELECT mac FROM device WHERE hostname IS NULL')}

    def devices(self):
        """
        Every device, with every (address, interface) window it has held.

        Addresses are a list, never a single current value. A device holds a
        *set* of addresses (DESIGN S1): the admin PC on the operator's network
        is deliberately in two VLANs at once, and folding that into "current
        address" would list it twice at half its size in every later view.
        """
        devices = {}
        for row in self.db.execute(
            """SELECT mac, first_seen, last_seen, randomised, is_local, hostname,
                      hostname_source FROM device ORDER BY last_seen DESC, mac"""
        ):
            devices[row['mac']] = {
                'mac': row['mac'],
                'first_seen': row['first_seen'],
                'last_seen': row['last_seen'],
                'randomised': bool(row['randomised']),
                'is_local': bool(row['is_local']),
                'hostname': row['hostname'],
                'hostname_source': row['hostname_source'],
                'label': None,
                'addresses': [],
            }

        for row in self.db.execute(
            "SELECT mac, name, kind, tags, note, owner, muted FROM device_label"
        ):
            if row['mac'] in devices:
                devices[row['mac']]['label'] = {
                    'name': row['name'],
                    'kind': row['kind'],
                    'tags': row['tags'],
                    'note': row['note'],
                    'owner': row['owner'],
                    'muted': bool(row['muted']),
                }

        for stay in stays(self.db.execute(
            """SELECT mac, address, interface, first_seen, last_seen
               FROM address_observation ORDER BY mac, address, interface, first_seen"""
        )):
            if stay['mac'] in devices:
                devices[stay['mac']]['addresses'].append({
                    'address': stay['address'],
                    'interface': stay['interface'],
                    'first_seen': stay['first_seen'],
                    'last_seen': stay['last_seen'],
                })
        for device in devices.values():
            device['addresses'].sort(key=lambda window: (-window['last_seen'], window['address']))

        return list(devices.values())

    def set_label(self, mac, fields, now):
        """
        What the operator called this device. An empty value clears that field
        rather than storing an empty string, so "no name of my own" and "a name
        that happens to be blank" cannot be confused later.

        A field that is not in `fields` keeps its value: the label editor sends
        all four texts and no mute, the mute button sends only the mute (§4.63),
        and neither may undo the other.

        :param fields: any of name, kind, tags, note, owner, muted
        """
        columns = ('name', 'kind', 'tags', 'note', 'owner')
        row = self.db.execute(
            "SELECT name, kind, tags, note, owner, muted FROM device_label WHERE mac = ?", (mac,)
        ).fetchone()
        values = [
            ((fields.get(key) or '').strip() or None) if key in fields else (row[key] if row else None)
            for key in columns
        ]
        muted = (1 if fields['muted'] else 0) if 'muted' in fields else (row['muted'] if row else 0)

        if not any(values) and not muted:
            self.db.execute("DELETE FROM device_label WHERE mac = ?", (mac,))
            return 'cleared'

        self.db.execute(
            """INSERT INTO device_label (mac, name, kind, tags, note, owner, muted, updated)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(mac) DO UPDATE SET
                   name = excluded.name, kind = excluded.kind,
                   tags = excluded.tags, note = excluded.note, owner = excluded.owner,
                   muted = excluded.muted, updated = excluded.updated""",
            (mac, values[0], values[1], values[2], values[3], values[4], muted, now),
        )
        return 'saved'

    def identity_health(self, now):
        """
        Whether keying identity on the MAC address is still holding (§4.17).

        Four measurements, and the third is the one that decides it:

        - how many devices, and how many present a randomised MAC
        - how long there has been anything to measure
        - **overlaps**: an (address, interface) two devices held at the same
          time. Non-zero means an address genuinely changed hands mid-window,
          which the attribution refuses to split and which is the failure
          MAC-keying was chosen to avoid
        - **fleeting**: devices seen for under an hour in total. Randomisation
          fragmenting one phone into many looks exactly like this, arriving in a
          steady trickle of short-lived randomised entries
        """
        def one(sql, args=()):
            row = self.db.execute(sql, args).fetchone()
            return (row[0] if row else 0) or 0

        week = now - 7 * 86400
        earliest = one("SELECT min(first_seen) FROM device")

        return {
            'devices': one("SELECT count(*) FROM device"),
            'randomised': one("SELECT count(*) FROM device WHERE randomised = 1"),
            'watching_since': earliest or None,
            'appeared_this_week': one(
                "SELECT count(*) FROM device WHERE first_seen >= ?", (week,)),
            'appeared_this_week_randomised': one(
                "SELECT count(*) FROM device WHERE first_seen >= ? AND randomised = 1",
                (week,)),
            'fleeting': one(
                "SELECT count(*) FROM device WHERE last_seen - first_seen < 3600"),
            'overlaps': one(
                """SELECT count(*) FROM (
                       SELECT DISTINCT a.address, a.interface
                       FROM address_observation a
                       JOIN address_observation b
                         ON a.address = b.address AND a.interface = b.interface
                        AND a.mac < b.mac
                        AND b.first_seen >= a.first_seen - %d
                        AND a.first_seen <= b.last_seen
                        AND b.first_seen <= a.last_seen
                   )""" % WINDOW_MAX),
            'reused': one(
                """SELECT count(*) FROM (
                       SELECT address, interface FROM address_observation
                       GROUP BY address, interface HAVING count(DISTINCT mac) > 1
                   )"""),
        }

    # ------------------------------------------------------------ events

    def watching_since(self):
        """:return: the first device's first sighting, as DeviceReport counts it (§4.34)"""
        row = self.db.execute("SELECT min(first_seen) AS at FROM device").fetchone()
        return row['at'] if row else None

    def address_windows(self, addresses, since, until):
        """:return: (mac, address, first_seen, last_seen) of every window of these addresses in the range"""
        addresses = sorted(set(addresses))
        rows = []
        for offset in range(0, len(addresses), 500):
            chunk = addresses[offset:offset + 500]
            rows.extend(self.db.execute(
                """SELECT mac, address, first_seen, last_seen FROM address_observation
                   WHERE address IN (%s) AND last_seen >= ? AND first_seen <= ?""" % ','.join('?' * len(chunk)),
                chunk + [since, until],
            ).fetchall())
        return [(row['mac'], row['address'], row['first_seen'], row['last_seen']) for row in rows]

    def devices_since(self, since):
        """:return: devices first seen at or after `since`, not this firewall's own"""
        return self.db.execute(
            """SELECT mac, first_seen FROM device
               WHERE first_seen >= ? AND is_local = 0 ORDER BY first_seen""",
            (since,),
        )

    def address_overlaps(self, since):
        """
        Each address two devices held at the same time (§4.36), and from when:
        the later of the two windows' starts, which is when it began.
        """
        return self.db.execute(
            """SELECT a.address, a.interface, a.mac AS one, b.mac AS other,
                      min(max(a.first_seen, b.first_seen)) AS at
               FROM address_observation a
               JOIN address_observation b
                 ON a.address = b.address AND a.interface = b.interface
                AND a.mac < b.mac
                AND b.first_seen >= a.first_seen - %d
                AND a.first_seen <= b.last_seen
                AND b.first_seen <= a.last_seen
               WHERE max(a.first_seen, b.first_seen) >= ?
               GROUP BY a.address, a.interface, a.mac, b.mac
               ORDER BY at""" % WINDOW_MAX,
            (since,),
        )

    def gateway_states(self, since):
        """:return: (name, at, status) per sample, oldest first"""
        return self.db.execute(
            """SELECT name, at, status, loss, delay FROM gateway_sample
               WHERE at >= ? ORDER BY name, at""",
            (since,),
        )

    # ----------------------------------------------------------- traffic

    def last_bucket(self, provider):
        row = self.db.execute(
            "SELECT last_bucket FROM harvest_state WHERE provider = ?", (provider,)
        ).fetchone()
        return row['last_bucket'] if row else None

    def store_buckets(self, provider, rows):
        """:return: how many rows were new"""
        if not rows:
            return 0

        before = self.db.total_changes
        self.db.executemany(
            """INSERT OR IGNORE INTO traffic_hour
               (bucket, interface, address, direction, octets, packets)
               VALUES (?, ?, ?, ?, ?, ?)""",
            rows,
        )
        written = self.db.total_changes - before

        # a bucket arriving for a day already summed: that day is summed again
        if written:
            days = sorted({row[0] // 86400 for row in rows})
            marks = ','.join('?' * len(days))
            self.db.execute("DELETE FROM device_day WHERE day IN (%s)" % marks, days)
            self.db.execute("DELETE FROM device_day_done WHERE day IN (%s)" % marks, days)

        self.db.execute(
            """INSERT INTO harvest_state (provider, last_bucket) VALUES (?, ?)
               ON CONFLICT(provider) DO UPDATE SET
                   last_bucket = max(harvest_state.last_bucket, excluded.last_bucket)""",
            (provider, max(row[0] for row in rows)),
        )
        return written

    # ------------------------------------------------------- bookkeeping

    def device_interfaces(self):
        """:return: set of interfaces on which any device has ever been observed"""
        return {
            row['interface']
            for row in self.db.execute("SELECT DISTINCT interface FROM address_observation")
        }

    def traffic_rows(self, since, bucket_seconds=3600, until=None):
        """
        Every traffic bucket since `since`, with who held its address at the time.

        The window test is an overlap, not containment: an observation opened at
        14:32 covers the 14:00 bucket, because the device was there for part of
        the hour the bucket measures. `macs` is how many distinct devices the
        overlap found -- more than one means the bucket cannot be attributed at
        all, and lenslib.attribute says so rather than picking one.

        Grouping is on traffic_hour's own primary key, so the join can never
        multiply the octets it is counting.
        """
        return self.db.execute(ATTRIBUTION_SQL, (bucket_seconds, since, FOREVER if until is None else until))

    @staticmethod
    def _macs(mac):
        """
        One MAC or several: a folded device is asked about all of its rotating
        private addresses at once (§4.61). Each bucket still resolved to exactly
        one of them in the attribution join, so the union only adds.

        :return: (placeholders, values) for `mac IN (...)`
        """
        macs = [mac] if isinstance(mac, str) else list(mac)
        return ','.join('?' * len(macs)), tuple(macs)

    def device_traffic(self, mac, since, bucket_seconds=3600):
        """
        One device's hourly totals, by direction.

        Wrapped around the *same* attribution query the list uses, filtered to
        the rows that resolved to this device alone. Sharing the query is the
        point: a detail view whose total disagrees with the row that opened it
        is worse than no detail view, and two copies of a join drift.
        """
        marks, macs = self._macs(mac)
        return self.db.execute(
            """SELECT bucket, direction,
                      sum(octets) AS octets, sum(packets) AS packets
               FROM (%s)
               WHERE macs = 1 AND mac IN (%s)
               GROUP BY bucket, direction
               ORDER BY bucket""" % (ATTRIBUTION_SQL, marks),
            (bucket_seconds, since, FOREVER) + macs,
        )

    def device_moment(self, mac, at, step, bucket_seconds=3600):
        """
        What one device's traffic in one slice of the chart was made of.

        The bar says a device moved 4 GB in that hour. This says on which
        addresses and which segments, which is the only question a person has
        after seeing the bar. Same attribution query as everything else, so the
        parts add up to the bar exactly (§4.32).
        """
        marks, macs = self._macs(mac)
        return self.db.execute(
            """SELECT address, interface, direction,
                      sum(octets) AS octets, sum(packets) AS packets
               FROM (%s)
               WHERE macs = 1 AND mac IN (%s) AND bucket >= ? AND bucket < ?
               GROUP BY address, interface, direction
               ORDER BY octets DESC""" % (ATTRIBUTION_SQL, marks),
            (bucket_seconds, at, at + step) + macs + (at, at + step),
        )

    def daily_totals(self, since, bucket_seconds=3600):
        """
        Bytes per device per day, for the baseline.

        Daily rather than hourly on purpose: three weeks gives twenty-one daily
        samples per device, and only three samples of any given hour-of-week.
        A median of three is not a baseline, it is a coincidence.

        Complete days come from device_day, summed once by the harvest (§4.69);
        the rest -- today, and any day not summed yet -- from the join itself.

        :return: list of {'mac', 'day', 'octets', 'sent'}, by mac and day
        """
        first_day = int(since) // 86400
        done = {row['day'] for row in self.db.execute(
            "SELECT day FROM device_day_done WHERE day >= ?", (first_day,))}
        rows = [dict(row) for row in self.db.execute(
            """SELECT mac, day, octets, sent FROM device_day
               WHERE day >= ? AND day IN (SELECT day FROM device_day_done)""", (first_day,))]

        missing = [day for day in range(first_day, int(time.time()) // 86400 + 1) if day not in done]
        if missing:
            live = self._day_sums(max(int(since), missing[0] * 86400), FOREVER, bucket_seconds)
            rows.extend(row for row in live if row['day'] not in done)

        rows.sort(key=lambda row: (row['mac'], row['day']))
        return rows

    def _day_sums(self, since, until, bucket_seconds=3600):
        """The join, summed per device and day, over [since, until)."""
        return [dict(row) for row in self.db.execute(
            """SELECT mac, bucket / 86400 AS day, sum(octets) AS octets,
                      sum(CASE WHEN direction = 'in' THEN octets ELSE 0 END) AS sent
               FROM (%s)
               WHERE macs = 1
               GROUP BY mac, day""" % ATTRIBUTION_SQL,
            (bucket_seconds, since, until),
        )]

    def settled_before(self, now):
        """
        The first bucket that may still change. An hour's attribution depends on
        which windows overlap it, and a window can still be extended into that
        hour by the next observation within the gap -- so an hour is settled one
        hour plus two gaps after it ends, and not before.
        """
        return int(now) - 3600 - 2 * self.setting_int('observation_gap')

    def fill_hours(self, now, limit=240):
        """
        Attribute settled hours once (§4.76), newest first so the ranges people
        open fill first. Bounded per run: the first run on a big store has
        weeks to catch up, and a harvest that ran for minutes would hold the
        store locked behind it (stage 4's lesson).

        :return: how many hours were settled
        """
        from lenslib import attribute

        horizon = self.settled_before(now) - 3600
        oldest = int(now) - self.setting_int('retention_days') * 86400
        buckets = [row[0] for row in self.db.execute(
            """SELECT DISTINCT bucket FROM traffic_hour
               WHERE bucket <= ? AND bucket >= ?
                 AND bucket NOT IN (SELECT bucket FROM hour_done)
               ORDER BY bucket DESC LIMIT ?""", (horizon, oldest, limit))]
        if not buckets:
            return 0

        interfaces = self.device_interfaces()
        watching = self.db.execute("SELECT min(first_seen) FROM address_observation").fetchone()[0]

        for bucket in buckets:
            per_mac, unattributed, _ = attribute.classify(
                self.traffic_rows(bucket, until=bucket + 3600), interfaces, watching)
            self._forget_hour(bucket)
            self.db.executemany(
                """INSERT INTO device_hour (bucket, mac, sent, sent_packets, received, received_packets)
                   VALUES (?, ?, ?, ?, ?, ?)""",
                [(bucket, mac, sides['in']['octets'], sides['in']['packets'],
                  sides['out']['octets'], sides['out']['packets']) for mac, sides in per_mac.items()])
            self.db.executemany(
                "INSERT INTO unattributed_hour (bucket, reason, octets, packets, rows) VALUES (?, ?, ?, ?, ?)",
                [(bucket, reason, c['octets'], c['packets'], c['rows'])
                 for reason, c in unattributed.items() if c['rows']])
            self.db.execute("INSERT OR REPLACE INTO hour_done (bucket, at) VALUES (?, ?)", (bucket, int(now)))
        return len(buckets)

    def _forget_hour(self, bucket):
        for table in ('device_hour', 'unattributed_hour', 'hour_done'):
            self.db.execute("DELETE FROM %s WHERE bucket = ?" % table, (bucket,))

    def done_hours(self, since, until):
        """:return: the settled buckets in [since, until)"""
        return {row[0] for row in self.db.execute(
            "SELECT bucket FROM hour_done WHERE bucket >= ? AND bucket < ?", (since, until))}

    def hour_sums(self, since, until):
        """
        The settled hours in [since, until), in classify()'s own shapes, so a
        caller can add live hours to them without knowing which were which.
        """
        def counter(octets=0, packets=0, rows=0):
            return {'octets': octets or 0, 'packets': packets or 0, 'rows': rows or 0}

        per_mac = {}
        for row in self.db.execute(
                """SELECT mac, sum(sent), sum(sent_packets), sum(received), sum(received_packets), count(*)
                   FROM device_hour WHERE bucket >= ? AND bucket < ? GROUP BY mac""", (since, until)):
            per_mac[row[0]] = {'in': counter(row[1], row[2], row[5]), 'out': counter(row[3], row[4], row[5])}

        unattributed = {reason: counter() for reason in ('far_end', 'not_watching', 'unknown', 'ambiguous')}
        for row in self.db.execute(
                """SELECT reason, sum(octets), sum(packets), sum(rows) FROM unattributed_hour
                   WHERE bucket >= ? AND bucket < ? GROUP BY reason""", (since, until)):
            unattributed[row[0]] = counter(row[1], row[2], row[3])

        return per_mac, unattributed

    def fill_device_days(self, now, limit=31):
        """
        Sum complete days once (§4.69): every hour of the day harvested, and not
        today. Newest first, so the baseline's three weeks are there first.

        :return: how many days were summed
        """
        last = self.last_bucket('FlowSourceAddrTotals')
        first = self.db.execute("SELECT min(bucket) FROM traffic_hour").fetchone()[0]
        if last is None or first is None:
            return 0
        settled = min((last + 3600) // 86400, int(now) // 86400)
        oldest = max(first // 86400, (int(now) - self.setting_int('retention_days') * 86400) // 86400)
        done = {row[0] for row in self.db.execute("SELECT day FROM device_day_done WHERE day >= ?", (oldest,))}
        days = [day for day in range(settled - 1, oldest - 1, -1) if day not in done][:limit]
        for day in days:
            sums = self._day_sums(day * 86400, (day + 1) * 86400)
            self.db.execute("DELETE FROM device_day WHERE day = ?", (day,))
            self.db.executemany(
                "INSERT INTO device_day (mac, day, octets, sent) VALUES (?, ?, ?, ?)",
                [(row['mac'], day, row['octets'], row['sent']) for row in sums],
            )
            self.db.execute("INSERT OR REPLACE INTO device_day_done (day, at) VALUES (?, ?)", (day, int(now)))
        return len(days)

    def network_timeline(self, since, step):
        """
        Traffic on the operator's own segments, per slice, both directions.

        Only interfaces a device has ever been seen on: the far end of the line,
        lo0 and the unplaced '0' are excluded, exactly as the Networks page marks
        them. Not wrapped in ATTRIBUTION_SQL because nothing here is per device;
        the sum is the same one the Networks page shows for those segments, and a
        test holds the two to it.
        """
        return self.db.execute(
            """SELECT (bucket / ?) * ? AS at, direction, sum(octets) AS octets
               FROM traffic_hour
               WHERE bucket >= ?
                 AND interface IN (SELECT DISTINCT interface FROM address_observation)
               GROUP BY at, direction
               ORDER BY at""",
            (step, step, since),
        )

    def store_destinations(self, day, rows):
        """:return: rows written; the day is recorded as harvested even when empty"""
        before = self.db.total_changes
        if rows:
            self.db.executemany(
                """INSERT OR REPLACE INTO destination_day
                   (day, interface, address, peer, port, protocol, direction, octets, packets)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                rows,
            )
        self.db.execute(
            """INSERT INTO harvest_state (provider, last_bucket) VALUES ('FlowSourceAddrDetails', ?)
               ON CONFLICT(provider) DO UPDATE SET
                   last_bucket = max(harvest_state.last_bucket, excluded.last_bucket)""",
            (day,),
        )
        return self.db.total_changes - before - 1

    def destinations(self, mac, since):
        """
        Who one device talked to since `since`, per destination and direction.

        The hourly attribution rule at a daily grain (§4.62): a destination row
        belongs to a device when that device -- and no other -- held the address
        on that interface at some point of that day. A day two devices shared
        the address is refused, as the hourly join refuses such an hour.
        """
        marks, macs = self._macs(mac)
        return self.db.execute(
            """SELECT peer, port, protocol,
                      sum(CASE WHEN direction = 'in' THEN octets ELSE 0 END) AS sent,
                      sum(CASE WHEN direction = 'out' THEN octets ELSE 0 END) AS received,
                      count(DISTINCT day) AS days
               FROM (
                   SELECT d.day, d.peer, d.port, d.protocol, d.direction, d.octets,
                          count(DISTINCT o.mac) AS holders, min(o.mac) AS mac
                   FROM destination_day d
                   JOIN address_observation o
                     ON o.address = d.address AND o.interface = d.interface
                    AND o.first_seen < d.day + 86400 AND o.last_seen >= d.day
                   WHERE d.day >= ?
                   GROUP BY d.day, d.interface, d.address, d.peer, d.port, d.protocol, d.direction
               )
               WHERE holders = 1 AND mac IN (%s)
               GROUP BY peer, port, protocol
               ORDER BY sent + received DESC""" % marks,
            (since,) + macs,
        )

    def first_observation(self):
        row = self.db.execute("SELECT min(first_seen) AS at FROM address_observation").fetchone()
        return row['at'] if row else None

    def presence_windows(self, since):
        """:return: every address window that was still open at or after `since`"""
        return self.db.execute(
            """SELECT mac, first_seen, last_seen FROM address_observation
               WHERE last_seen >= ?""",
            (since,),
        )

    def device_heatmap(self, mac, since, bucket_seconds=3600):
        """
        One device's bytes per hour of the week, in the firewall's own timezone.

        Local time on purpose: a heatmap in UTC puts the evening at the wrong
        end of the row for everyone not in London.
        """
        marks, macs = self._macs(mac)
        return self.db.execute(
            """SELECT CAST(strftime('%%w', bucket, 'unixepoch', 'localtime') AS INTEGER) AS dow,
                      CAST(strftime('%%H', bucket, 'unixepoch', 'localtime') AS INTEGER) AS hour,
                      sum(octets) AS octets
               FROM (%s)
               WHERE macs = 1 AND mac IN (%s)
               GROUP BY dow, hour""" % (ATTRIBUTION_SQL, marks),
            (bucket_seconds, since, FOREVER) + macs,
        )

    def network_heatmap(self, since):
        """
        The same, for everything on the operator's own segments.

        Settled hours come from the sums (§4.76): what a device sent and
        received, plus everything unattributed that was not the far end, is
        exactly the traffic on the operator's own segments. Only the hours not
        settled yet are read raw. Four weeks of raw buckets took 3.5 s on the
        second firewall.
        """
        done = self.done_hours(since, FOREVER)
        per_bucket = {}
        for bucket, octets in self.db.execute(
                """SELECT bucket, sum(sent + received) FROM device_hour
                   WHERE bucket >= ? GROUP BY bucket""", (since,)):
            per_bucket[bucket] = per_bucket.get(bucket, 0) + (octets or 0)
        for bucket, octets in self.db.execute(
                """SELECT bucket, sum(octets) FROM unattributed_hour
                   WHERE bucket >= ? AND reason <> 'far_end' GROUP BY bucket""", (since,)):
            per_bucket[bucket] = per_bucket.get(bucket, 0) + (octets or 0)
        # raw, but only across the gaps: while a big store catches up the
        # unsettled hours are the newest few *and* the oldest ones
        for start, end in self._gaps(since, done):
            for bucket, octets in self.db.execute(
                    """SELECT bucket, sum(octets) FROM traffic_hour
                       WHERE bucket >= ? AND bucket < ?
                         AND interface IN (SELECT DISTINCT interface FROM address_observation)
                       GROUP BY bucket""", (start, end)):
                per_bucket[bucket] = per_bucket.get(bucket, 0) + (octets or 0)

        # local time, as SQLite's 'localtime' did: the firewall's own clock
        cells = {}
        for bucket, octets in per_bucket.items():
            moment = time.localtime(bucket)
            key = ((moment.tm_wday + 1) % 7, moment.tm_hour)      # 0 = Sunday, as strftime('%w')
            cells[key] = cells.get(key, 0) + octets
        return [{'dow': dow, 'hour': hour, 'octets': octets} for (dow, hour), octets in cells.items()]

    def _gaps(self, since, done):
        """:return: [start, end) ranges from `since` onward that are not settled"""
        gaps, start = [], since
        for bucket in sorted(done):
            if bucket > start:
                gaps.append((start, bucket))
            start = max(start, bucket + 3600)
        gaps.append((start, FOREVER))
        return gaps

    def _network_heatmap_raw(self, since):
        """The raw form, kept for the test that holds the two equal."""
        return self.db.execute(
            """SELECT CAST(strftime('%w', bucket, 'unixepoch', 'localtime') AS INTEGER) AS dow,
                      CAST(strftime('%H', bucket, 'unixepoch', 'localtime') AS INTEGER) AS hour,
                      sum(octets) AS octets
               FROM traffic_hour
               WHERE bucket >= ?
                 AND interface IN (SELECT DISTINCT interface FROM address_observation)
               GROUP BY dow, hour""",
            (since,),
        )

    def device_windows(self, mac):
        """Every stay one device has had on an address, oldest first (pieces joined, §4.69)."""
        marks, macs = self._macs(mac)
        found = stays(self.db.execute(
            """SELECT mac, address, interface, first_seen, last_seen
               FROM address_observation WHERE mac IN (%s)
               ORDER BY mac, address, interface, first_seen""" % marks,
            macs,
        ))
        found.sort(key=lambda stay: stay['first_seen'])
        return found

    def interface_timeline(self, since, step):
        """Bytes per interface per slice, for each network's own sparkline."""
        return self.db.execute(
            """SELECT interface, (bucket / ?) * ? AS at, sum(octets) AS octets
               FROM traffic_hour
               WHERE bucket >= ?
               GROUP BY interface, at
               ORDER BY interface, at""",
            (step, step, since),
        )

    def store_gateway_samples(self, at, rows):
        """:param rows: (name, delay, stddev, loss, status, monitor)"""
        self.db.executemany(
            """INSERT OR REPLACE INTO gateway_sample (at, name, delay, stddev, loss, status)
               VALUES (?, ?, ?, ?, ?, ?)""",
            [(at, name, delay, stddev, loss, status)
             for name, delay, stddev, loss, status, _ in rows],
        )

    def gateway_series(self, since, step):
        """
        Per gateway per slice: the average delay and the worst loss.

        Worst, not average, for loss: one five-minute sample at 40% loss is the
        dropped call someone noticed, and an hourly average would hide it.
        """
        return self.db.execute(
            """SELECT name, (at / ?) * ? AS slot,
                      avg(delay) AS delay, max(loss) AS loss, avg(stddev) AS stddev,
                      count(*) AS samples
               FROM gateway_sample
               WHERE at >= ?
               GROUP BY name, slot
               ORDER BY name, slot""",
            (step, step, since),
        )

    def store_probe_samples(self, at, rows):
        """:param rows: (target, rtt, stddev, loss)"""
        self.db.executemany(
            """INSERT OR REPLACE INTO probe_sample (at, target, rtt, stddev, loss)
               VALUES (?, ?, ?, ?, ?)""",
            [(at, target, rtt, stddev, loss) for target, rtt, stddev, loss in rows],
        )

    def probe_series(self, since, step):
        """Per target per slice: average round trip, worst loss (as gateway_series)."""
        return self.db.execute(
            """SELECT target, (at / ?) * ? AS slot,
                      avg(rtt) AS rtt, max(loss) AS loss, count(*) AS samples
               FROM probe_sample WHERE at >= ?
               GROUP BY target, slot ORDER BY target, slot""",
            (step, step, since),
        )

    def probe_rounds(self, since):
        """
        Each observation run's probes collapsed to one answer: was anything
        reachable at all. The internet is down when every target was.
        """
        return self.db.execute(
            """SELECT at, min(loss) AS best_loss, count(*) AS targets, min(rtt) AS best_rtt
               FROM probe_sample WHERE at >= ?
               GROUP BY at ORDER BY at""",
            (since,),
        )

    def latest_probes(self):
        return self.db.execute(
            """SELECT target, rtt, stddev, loss, at FROM probe_sample
               WHERE at = (SELECT max(at) FROM probe_sample)"""
        )

    def device_interfaces_of(self, mac):
        """:return: interfaces this device has held an address on, most recent first"""
        marks, macs = self._macs(mac)
        return [
            row['interface']
            for row in self.db.execute(
                """SELECT interface, max(last_seen) AS seen FROM address_observation
                   WHERE mac IN (%s) GROUP BY interface ORDER BY seen DESC""" % marks,
                macs,
            )
        ]

    def interface_traffic(self, since, bucket_seconds=3600):
        """
        Traffic per interface, split by whether Lens could name a device for it.

        Core's Insight already totals bytes per interface. The column this adds
        is the second one: how much of each segment Lens can account for. A
        segment that is 95% unattributed is not a busy segment, it is a segment
        whose devices are not on it -- traffic routed through rather than from
        machines attached -- and that distinction is invisible in a plain total.

        Built on the same attribution query as everything else (§4.32).
        """
        return self.db.execute(
            """SELECT interface, direction,
                      sum(octets) AS octets, sum(packets) AS packets,
                      sum(CASE WHEN macs = 1 THEN octets ELSE 0 END) AS named,
                      count(DISTINCT bucket) AS hours,
                      count(DISTINCT address) AS addresses
               FROM (%s)
               GROUP BY interface, direction""" % ATTRIBUTION_SQL,
            (bucket_seconds, since, FOREVER),
        )

    def log_run(self, duty, at, ok, took_ms, detail):
        self.db.execute(
            "INSERT OR REPLACE INTO run_log (duty, at, ok, took_ms, detail) VALUES (?, ?, ?, ?, ?)",
            (duty, at, int(ok), took_ms, detail),
        )
        self.db.execute("DELETE FROM run_log WHERE at < ?", (at - 7 * 86400,))

    def prune(self, now):
        """Drop what is older than the retention setting. :return: rows removed"""
        cutoff = now - self.setting_int('retention_days') * 86400
        before = self.db.total_changes
        self.db.execute("DELETE FROM traffic_hour WHERE bucket < ?", (cutoff,))
        self.db.execute("DELETE FROM address_observation WHERE last_seen < ?", (cutoff,))
        self.db.execute("DELETE FROM device WHERE last_seen < ?", (cutoff,))
        # a label is a MAC address plus what a person wrote about it, so it goes
        # when the device it describes goes -- S14 applies to it like everything
        # else, and an orphan would outlive the retention it was promised under
        self.db.execute(
            "DELETE FROM device_label WHERE mac NOT IN (SELECT mac FROM device)"
        )
        self.db.execute("DELETE FROM gateway_sample WHERE at < ?", (cutoff,))
        self.db.execute("DELETE FROM probe_sample WHERE at < ?", (cutoff,))
        self.db.execute("DELETE FROM destination_day WHERE day < ?", (cutoff,))
        self.db.execute("DELETE FROM device_day WHERE day < ?", (cutoff // 86400,))
        self.db.execute("DELETE FROM device_day_done WHERE day < ?", (cutoff // 86400,))
        for table in ('device_hour', 'unattributed_hour', 'hour_done'):
            self.db.execute("DELETE FROM %s WHERE bucket < ?" % table, (cutoff,))
        return self.db.total_changes - before

    # what a device's windows cover, by the attribution join's overlap rule
    # (§4.26): an hour whose bucket the window touches, a day it touches
    _COVERED_HOURS = """SELECT t.rowid FROM traffic_hour t
        JOIN address_observation o
          ON o.address = t.address AND o.interface = t.interface
         AND o.first_seen < t.bucket + 3600 AND o.last_seen >= t.bucket
        WHERE o.mac IN (%s)"""
    _COVERED_DAYS = """SELECT d.rowid FROM destination_day d
        JOIN address_observation o
          ON o.address = d.address AND o.interface = d.interface
         AND o.first_seen < d.day + 86400 AND o.last_seen >= d.day
        WHERE o.mac IN (%s)"""

    def forget(self, macs, dry=False):
        """
        Everything Lens holds about one device (§4.72): its rows, its label,
        its summed days, its address windows, and every traffic hour and
        destination day those windows cover -- shared hours included, since
        they describe this device as much as any other.

        :param macs: every MAC the device stands for (a folded phone is several)
        :param dry: count only
        :return: rows per kind, as deleted or as a deletion would delete them
        """
        marks, values = self._macs(macs)
        counts = {
            'traffic_hours': "SELECT count(DISTINCT rowid) FROM (%s)" % (self._COVERED_HOURS % marks),
            'destination_days': "SELECT count(DISTINCT rowid) FROM (%s)" % (self._COVERED_DAYS % marks),
            'windows': "SELECT count(*) FROM address_observation WHERE mac IN (%s)" % marks,
            'summed_days': "SELECT count(*) FROM device_day WHERE mac IN (%s)" % marks,
            'labels': "SELECT count(*) FROM device_label WHERE mac IN (%s)" % marks,
            'devices': "SELECT count(*) FROM device WHERE mac IN (%s)" % marks,
        }
        found = {key: self.db.execute(sql, values).fetchone()[0] for key, sql in counts.items()}
        if dry or not found['devices'] and not found['windows']:
            return found

        # deleted pages are overwritten, not left in the file's free list
        self.db.execute("PRAGMA secure_delete = ON")
        try:
            # the hours this device was in are summed with it; settle them again
            for (bucket,) in self.db.execute(
                    "SELECT DISTINCT bucket FROM traffic_hour WHERE rowid IN (%s)" % (self._COVERED_HOURS % marks),
                    values).fetchall():
                self._forget_hour(bucket)
            self.db.execute("DELETE FROM traffic_hour WHERE rowid IN (%s)" % (self._COVERED_HOURS % marks), values)
            self.db.execute("DELETE FROM destination_day WHERE rowid IN (%s)" % (self._COVERED_DAYS % marks),
                            values)
            for table in ('address_observation', 'device_day', 'device_label', 'device'):
                self.db.execute("DELETE FROM %s WHERE mac IN (%s)" % (table, marks), values)
            self.db.commit()
        finally:
            self.db.execute("PRAGMA secure_delete = OFF")
        return found

    def kept(self):
        """
        What the store holds about people, per kind, for the privacy page:
        how many rows and the oldest moment each reaches back to.
        """
        def row(sql):
            found = self.db.execute(sql).fetchone()
            return {'rows': found[0] or 0, 'oldest': found[1]}

        return {
            'devices': row("SELECT count(*), min(first_seen) FROM device"),
            'windows': row("SELECT count(*), min(first_seen) FROM address_observation"),
            'traffic_hours': row("SELECT count(*), min(bucket) FROM traffic_hour"),
            'summed_days': row("SELECT count(*), min(day) * 86400 FROM device_day"),
            'destination_days': row("SELECT count(*), min(day) FROM destination_day"),
            'labels': row("SELECT count(*), min(updated) FROM device_label"),
            'owners': row("SELECT count(DISTINCT owner), NULL FROM device_label WHERE owner <> ''"),
            'line_samples': row(
                "SELECT (SELECT count(*) FROM gateway_sample) + (SELECT count(*) FROM probe_sample),"
                " min((SELECT min(at) FROM gateway_sample), (SELECT min(at) FROM probe_sample))"),
        }

    def purge(self):
        """Everything, deliberately. This is the S14 promise, so it has to work."""
        for table in ('traffic_hour', 'address_observation', 'device',
                      'device_label', 'gateway_sample', 'probe_sample', 'destination_day', 'harvest_state',
                      'device_day', 'device_day_done', 'device_hour', 'unattributed_hour', 'hour_done',
                      'run_log'):
            self.db.execute("DELETE FROM %s" % table)
        self.db.commit()
        self.db.execute("VACUUM")

    def size_mb(self):
        try:
            return round(os.path.getsize(self.path) / (1024 * 1024), 1)
        except OSError:
            return 0.0

    def over_ceiling(self):
        return self.size_mb() >= self.setting_int('disk_ceiling_mb')

    def status(self):
        def one(sql):
            row = self.db.execute(sql).fetchone()
            return row[0] if row else None

        runs = {}
        for row in self.db.execute(
            "SELECT duty, max(at) AS at, ok, took_ms, detail FROM run_log GROUP BY duty"
        ):
            runs[row['duty']] = {
                'at': row['at'], 'ok': bool(row['ok']),
                'took_ms': row['took_ms'], 'detail': row['detail'],
            }

        return {
            'schema_version': one("SELECT max(version) FROM schema_version"),
            'devices': one("SELECT count(*) FROM device"),
            'devices_randomised': one("SELECT count(*) FROM device WHERE randomised = 1"),
            'observations': one("SELECT count(*) FROM address_observation"),
            'first_observation': one("SELECT min(first_seen) FROM address_observation"),
            'traffic_rows': one("SELECT count(*) FROM traffic_hour"),
            'first_bucket': one("SELECT min(bucket) FROM traffic_hour"),
            'last_bucket': one("SELECT max(bucket) FROM traffic_hour"),
            'size_mb': self.size_mb(),
            'ceiling_mb': self.setting_int('disk_ceiling_mb'),
            'retention_days': self.setting_int('retention_days'),
            'baseline_days': self.settings()['baseline_days'],
            'fold_randomised': self.settings()['fold_randomised'],
            'runs': runs,
        }

    def commit(self):
        self.db.commit()
