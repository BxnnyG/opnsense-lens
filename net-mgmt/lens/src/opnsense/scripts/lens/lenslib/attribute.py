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
  unknown       a device interface, an address, and nobody observed holding it
                in that hour. Either the collector was not running, or the
                device never answered ARP.
  ambiguous     two devices held the same address inside one hour. The bucket
                cannot be split between them and guessing would be worse than
                saying so.
"""


def classify(rows, device_interfaces):
    """
    :param rows: (bucket, interface, address, direction, octets, packets, macs, mac)
    :param device_interfaces: interfaces on which any device has ever been seen
    :return: (per_mac, unattributed) -- both dicts of counters
    """
    per_mac = {}
    unattributed = {
        'far_end': _counter(),
        'unknown': _counter(),
        'ambiguous': _counter(),
    }

    for bucket, interface, address, direction, octets, packets, macs, mac in rows:
        if interface not in device_interfaces:
            _add(unattributed['far_end'], octets, packets)
        elif not macs:
            _add(unattributed['unknown'], octets, packets)
        elif macs > 1:
            _add(unattributed['ambiguous'], octets, packets)
        else:
            totals = per_mac.setdefault(mac, {'in': _counter(), 'out': _counter()})
            # the aggregator's own words: 'in' entered the interface, so it left
            # the device. Nothing is renamed here; the surface decides wording.
            _add(totals.get(direction, totals['in']), octets, packets)

    return per_mac, unattributed


def _counter():
    return {'octets': 0, 'packets': 0, 'rows': 0}


def _add(counter, octets, packets):
    counter['octets'] += octets
    counter['packets'] += packets
    counter['rows'] += 1
