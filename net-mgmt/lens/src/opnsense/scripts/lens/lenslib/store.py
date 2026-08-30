"""
The store: schema, migrations, and the queries the collector needs.

Versioned from the first commit. A schema change after this ships is a migration
whether or not anyone planned for one, and discovering that during an upgrade is
the expensive way to find out.

Mode 0600 throughout: every row here describes what a person did on the network.
"""

import os
import sqlite3

SCHEMA_VERSION = 3

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
}


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
                'addresses': [],
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
        return self.db.execute(
            """SELECT t.bucket, t.interface, t.address, t.direction,
                      t.octets, t.packets,
                      count(DISTINCT o.mac) AS macs, min(o.mac) AS mac
               FROM traffic_hour t
               LEFT JOIN address_observation o
                 ON o.address = t.address
                AND o.interface = t.interface
                AND o.first_seen < t.bucket + ?
                AND o.last_seen >= t.bucket
               WHERE t.bucket >= ?
               GROUP BY t.bucket, t.interface, t.address, t.direction""",
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
        return self.db.total_changes - before

    def purge(self):
        """Everything, deliberately. This is the S14 promise, so it has to work."""
        for table in ('traffic_hour', 'address_observation', 'device',
                      'harvest_state', 'run_log'):
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
