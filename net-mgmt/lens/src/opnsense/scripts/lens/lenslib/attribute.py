"""
Traffic to devices.

Core keeps hourly per-client buckets keyed on an address and nothing else, for
twenty-four hours (DESIGN 1.4). Lens keeps the buckets, and keeps who held which
address when. Joining them is the point of the whole plugin -- and the join has
to happen *at the time of the bucket*, not against who holds the address today,
or a lease that moved at noon silently hands one device's evening to another.

Three things are not traffic from a device on your network, and all three must
be named rather than dropped:

  far end       the aggregator writes every flow twice, once keyed on the local
                address and once on the remote one (DESIGN 1.4). The second row
                carries the egress interface -- pppoe0 on the operator's router,
                where it is 91% of all rows. Those are the internet's addresses,
                not devices, and no observation will ever match them.
  not watching  measured before Lens had started observing at all. The harvest
                reaches 23 hours back on its first run; identity starts the
                moment the collector does. On the operator's second firewall
                that is 129 GB, and calling it a collection gap would be a
                warning that can never go green (4.20).
  unknown       a device interface, an address, Lens was watching, and still
                nobody was seen holding it that hour. Either the collector
                stopped, or the device never answered ARP.
  ambiguous     two devices held the same address inside one hour. The bucket
                cannot be split between them and guessing would be worse than
                saying so.
"""


def classify(rows, device_interfaces, watching_since=None):
    """
    :param rows: (bucket, interface, address, direction, octets, packets, macs, mac)
    :param device_interfaces: interfaces on which any device has ever been seen
    :param watching_since: first observation ever recorded, None if there is none
    :return: (per_mac, unattributed, worst) -- totals, and the heaviest
             addresses that landed in each unattributed class
    """
    per_mac = {}
    unattributed = {
        'far_end': _counter(),
        'not_watching': _counter(),
        'unknown': _counter(),
        'ambiguous': _counter(),
    }
    # Which addresses make up the piles above. A total nobody can break down is
    # a number you either believe or ignore, and neither is useful: on the
    # operator's second firewall 'unknown' reached 95 GB against 43 GB
    # attributed, and the page could say so without saying what it was.
    examples = {}

    for bucket, interface, address, direction, octets, packets, macs, mac in rows:
        if interface not in device_interfaces:
            reason = 'far_end'
        elif watching_since is None or bucket + 3600 <= watching_since:
            reason = 'not_watching'
        elif not macs:
            reason = 'unknown'
        elif macs > 1:
            reason = 'ambiguous'
        else:
            totals = per_mac.setdefault(mac, {'in': _counter(), 'out': _counter()})
            # the aggregator's own words: 'in' entered the interface, so it left
            # the device. Nothing is renamed here; the surface decides wording.
            _add(totals.get(direction, totals['in']), octets, packets)
            continue

        _add(unattributed[reason], octets, packets)

        # The far end is not listed by address. On the operator's router it is
        # 25 rows all reading `pppoe0 / far end` -- one sentence repeated until
        # the table stops being read (§4.47) -- and there is nothing to do about
        # any of them: they are the internet's addresses. Its total is on the
        # line above, which is the whole of what that class has to say.
        if reason != 'far_end':
            _add(examples.setdefault((reason, interface, address), _counter()),
                 octets, packets)

    return per_mac, unattributed, _worst(examples)


def _worst(examples, limit=25):
    """
    The heaviest unattributed addresses, biggest first.

    Deliberately not every one of them: on a busy box the far end alone is tens
    of thousands of internet addresses, and a list that long answers nothing.
    Twenty-five is enough to recognise a pattern -- one subnet, one interface,
    one machine behind another router -- which is what a person actually needs.
    """
    worst = [
        {
            'reason': reason, 'interface': interface, 'address': address,
            'octets': counter['octets'], 'hours': counter['rows'],
        }
        for (reason, interface, address), counter in examples.items()
    ]
    worst.sort(key=lambda row: row['octets'], reverse=True)

    return worst[:limit]


def _counter():
    return {'octets': 0, 'packets': 0, 'rows': 0}


def _add(counter, octets, packets):
    counter['octets'] += octets
    counter['packets'] += packets
    counter['rows'] += 1
