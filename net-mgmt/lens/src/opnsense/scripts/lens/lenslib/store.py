"""
The store: schema, migrations, and the queries the collector needs.

Versioned from the first commit. A schema change after this ships is a migration
whether or not anyone planned for one, and discovering that during an upgrade is
the expensive way to find out.

Mode 0600 throughout: every row here describes what a person did on the network.
"""

import os
import sqlite3

SCHEMA_VERSION = 6

DEFAULT_SETTINGS = {
    # how long observations and harvested traffic are kept
    'retention_days': '365',
    # above this the collector stops writing rather than fill /var on a firewall
    'disk_ceiling_mb': '500',
    # seconds of absence that end an address observation window
    'observation_gap': '900',
}

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
# the octets it is counting. Parameters, in order: bucket_seconds, since.
ATTRIBUTION_SQL = """
    SELECT t.bucket AS bucket, t.interface AS interface, t.address AS address,
           t.direction AS direction, t.octets AS octets, t.packets AS packets,
           count(DISTINCT o.mac) AS macs, min(o.mac) AS mac
    FROM traffic_hour t
    LEFT JOIN address_observation o
      ON o.address = t.address
     AND o.interface = t.interface
     AND o.first_seen < t.bucket + ?
     AND o.last_seen >= t.bucket
    WHERE t.bucket >= ?
    GROUP BY t.bucket, t.interface, t.address, t.direction
"""


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

    # ------------------------------------------------------- observations

    def open_windows(self):
        """:return: dict of (mac, address, interface) to the window's last_seen"""
        rows = self.db.execute(
            """SELECT mac, address, interface, max(last_seen) AS last_seen
               FROM address_observation GROUP BY mac, address, interface"""
        )
        return {(r['mac'], r['address'], r['interface']): r['last_seen'] for r in rows}

    def extend_windows(self, keys, now):
        for mac, address, interface in keys:
            self.db.execute(
                """UPDATE address_observation SET last_seen = ?
                   WHERE mac = ? AND address = ? AND interface = ?
                     AND first_seen = (SELECT max(first_seen) FROM address_observation
                                       WHERE mac = ? AND address = ? AND interface = ?)""",
                (now, mac, address, interface, mac, address, interface),
            )

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
            "SELECT mac, name, kind, tags, note FROM device_label"
        ):
            if row['mac'] in devices:
                devices[row['mac']]['label'] = {
                    'name': row['name'],
                    'kind': row['kind'],
                    'tags': row['tags'],
                    'note': row['note'],
                }

        for row in self.db.execute(
            """SELECT mac, address, interface, first_seen, last_seen
               FROM address_observation ORDER BY last_seen DESC, address"""
        ):
            if row['mac'] in devices:
                devices[row['mac']]['addresses'].append({
                    'address': row['address'],
                    'interface': row['interface'],
                    'first_seen': row['first_seen'],
                    'last_seen': row['last_seen'],
                })

        return list(devices.values())

    def set_label(self, mac, fields, now):
        """
        What the operator called this device. An empty value clears that field
        rather than storing an empty string, so "no name of my own" and "a name
        that happens to be blank" cannot be confused later.

        :param fields: any of name, kind, tags, note
        """
        columns = ('name', 'kind', 'tags', 'note')
        values = [(fields.get(key) or '').strip() or None for key in columns]

        if not any(values):
            self.db.execute("DELETE FROM device_label WHERE mac = ?", (mac,))
            return 'cleared'

        self.db.execute(
            """INSERT INTO device_label (mac, name, kind, tags, note, updated)
               VALUES (?, ?, ?, ?, ?, ?)
               ON CONFLICT(mac) DO UPDATE SET
                   name = excluded.name, kind = excluded.kind,
                   tags = excluded.tags, note = excluded.note,
                   updated = excluded.updated""",
            (mac, values[0], values[1], values[2], values[3], now),
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
                        AND a.first_seen <= b.last_seen
                        AND b.first_seen <= a.last_seen
                   )"""),
            'reused': one(
                """SELECT count(*) FROM (
                       SELECT address, interface FROM address_observation
                       GROUP BY address, interface HAVING count(DISTINCT mac) > 1
                   )"""),
        }

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

    def traffic_rows(self, since, bucket_seconds=3600):
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
        return self.db.execute(ATTRIBUTION_SQL, (bucket_seconds, since))

    def device_traffic(self, mac, since, bucket_seconds=3600):
        """
        One device's hourly totals, by direction.

        Wrapped around the *same* attribution query the list uses, filtered to
        the rows that resolved to this device alone. Sharing the query is the
        point: a detail view whose total disagrees with the row that opened it
        is worse than no detail view, and two copies of a join drift.
        """
        return self.db.execute(
            """SELECT bucket, direction,
                      sum(octets) AS octets, sum(packets) AS packets
               FROM (%s)
               WHERE macs = 1 AND mac = ?
               GROUP BY bucket, direction
               ORDER BY bucket""" % ATTRIBUTION_SQL,
            (bucket_seconds, since, mac),
        )

    def device_moment(self, mac, at, step, bucket_seconds=3600):
        """
        What one device's traffic in one slice of the chart was made of.

        The bar says a device moved 4 GB in that hour. This says on which
        addresses and which segments, which is the only question a person has
        after seeing the bar. Same attribution query as everything else, so the
        parts add up to the bar exactly (§4.32).
        """
        return self.db.execute(
            """SELECT address, interface, direction,
                      sum(octets) AS octets, sum(packets) AS packets
               FROM (%s)
               WHERE macs = 1 AND mac = ? AND bucket >= ? AND bucket < ?
               GROUP BY address, interface, direction
               ORDER BY octets DESC""" % ATTRIBUTION_SQL,
            (bucket_seconds, at, mac, at, at + step),
        )

    def daily_totals(self, since, bucket_seconds=3600):
        """
        Bytes per device per day, for the baseline.

        Daily rather than hourly on purpose: three weeks gives twenty-one daily
        samples per device, and only three samples of any given hour-of-week.
        A median of three is not a baseline, it is a coincidence.
        """
        return self.db.execute(
            """SELECT mac, bucket / 86400 AS day, sum(octets) AS octets
               FROM (%s)
               WHERE macs = 1
               GROUP BY mac, day
               ORDER BY mac, day""" % ATTRIBUTION_SQL,
            (bucket_seconds, since),
        )

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
        return self.db.execute(
            """SELECT CAST(strftime('%%w', bucket, 'unixepoch', 'localtime') AS INTEGER) AS dow,
                      CAST(strftime('%%H', bucket, 'unixepoch', 'localtime') AS INTEGER) AS hour,
                      sum(octets) AS octets
               FROM (%s)
               WHERE macs = 1 AND mac = ?
               GROUP BY dow, hour""" % ATTRIBUTION_SQL,
            (bucket_seconds, since, mac),
        )

    def network_heatmap(self, since):
        """The same, for everything on the operator's own segments."""
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
        """Every address window one device has ever had, oldest first."""
        return self.db.execute(
            """SELECT address, interface, first_seen, last_seen
               FROM address_observation WHERE mac = ? ORDER BY first_seen""",
            (mac,),
        )

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
            """SELECT at, min(loss) AS best_loss, count(*) AS targets
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
        return [
            row['interface']
            for row in self.db.execute(
                """SELECT interface, max(last_seen) AS seen FROM address_observation
                   WHERE mac = ? GROUP BY interface ORDER BY seen DESC""",
                (mac,),
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
            (bucket_seconds, since),
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
        return self.db.total_changes - before

    def purge(self):
        """Everything, deliberately. This is the S14 promise, so it has to work."""
        for table in ('traffic_hour', 'address_observation', 'device',
                      'device_label', 'gateway_sample', 'probe_sample', 'harvest_state',
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
            'runs': runs,
        }

    def commit(self):
        self.db.commit()
