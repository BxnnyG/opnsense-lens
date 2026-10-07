"""
Which services the devices asked for (#44, §4.78).

A map of domain suffixes to the services that own them, laid over the questions
the DNS page already reads. It turns "who asked what" into "who asked for
Netflix, WhatsApp, Steam" -- and no further. What it cannot see, the page says
(§4.78): only questions put to Unbound, approximate behind shared CDNs, blind
to DNS over HTTPS and to anything a device resolved elsewhere.

Who keeps the domains (§4.79): not Lens. They come from v2fly's
domain-list-community (MIT, github.com/v2fly/domain-list-community), one file
per service, kept by many hands. tools/services/refresh.py pulls the lists
named below at one pinned commit into services_domains.json, which ships in the
package; the box never fetches anything. What Lens keeps itself is only what a
page needs and v2fly does not say: the name, the kind, the icon, which of its
lists belong to which service -- and a few suffixes v2fly has no list for
(German broadcasters, Alexa), which also win any disagreement.

A name matches the longest suffix it ends in, on a label boundary: "nflxvideo.net"
matches "a.nflxvideo.net" but not "notnflxvideo.net". A name nothing matches
stays a name; it is never guessed into a service.

Kinds sort the page: what someone chose to use (streaming, games, social...)
stands apart from what devices ask by themselves (platform: Apple, Google,
Microsoft background traffic), so a phone checking for updates does not read as
a person doing something.
"""

import json
import os

# key: (name, kind, icon, v2fly lists, suffixes Lens adds itself)
SERVICES = {
    # streaming
    'netflix': ('Netflix', 'streaming', 'fa-film', ['netflix'], []),
    'youtube': ('YouTube', 'streaming', 'fa-youtube-play', ['youtube'], ['youtubei.googleapis.com']),
    'disney': ('Disney+', 'streaming', 'fa-film', ['disney'], []),
    'prime': ('Prime Video', 'streaming', 'fa-film', ['primevideo'], []),
    'twitch': ('Twitch', 'streaming', 'fa-twitch', ['twitch'], []),
    'spotify': ('Spotify', 'streaming', 'fa-spotify', ['spotify'], []),
    'appletv': ('Apple TV+ / Music', 'streaming', 'fa-music', ['apple-tvplus', 'apple-music'], ['tv.apple.com']),
    'dazn': ('DAZN', 'streaming', 'fa-futbol-o', ['dazn'], []),
    'joyn': ('Joyn', 'streaming', 'fa-film', [], ['joyn.de', 'joyn.net']),
    'zdf': ('ZDF / ARD', 'streaming', 'fa-television', [], ['zdf.de', 'ardmediathek.de', 'ard.de', 'daserste.de',
                                                             'akamaized-ard.de']),
    'plex': ('Plex', 'streaming', 'fa-play-circle', ['plex'], []),
    # social
    'instagram': ('Instagram', 'social', 'fa-instagram', ['instagram'], []),
    'facebook': ('Facebook', 'social', 'fa-facebook-official', ['facebook', 'messenger', 'threads'], []),
    'tiktok': ('TikTok', 'social', 'fa-music', ['tiktok'], []),
    'snapchat': ('Snapchat', 'social', 'fa-snapchat-ghost', ['snap'], []),
    'x': ('X / Twitter', 'social', 'fa-twitter', ['twitter'], []),
    'reddit': ('Reddit', 'social', 'fa-reddit-alien', ['reddit'], []),
    'pinterest': ('Pinterest', 'social', 'fa-pinterest', ['pinterest'], []),
    'linkedin': ('LinkedIn', 'social', 'fa-linkedin', ['linkedin'], []),
    # messaging and calls
    'whatsapp': ('WhatsApp', 'messaging', 'fa-whatsapp', ['whatsapp'], []),
    'telegram': ('Telegram', 'messaging', 'fa-telegram', ['telegram'], []),
    'signal': ('Signal', 'messaging', 'fa-comment', ['signal'], []),
    'discord': ('Discord', 'messaging', 'fa-comments', ['discord'], []),
    'teams': ('Microsoft Teams', 'messaging', 'fa-users', [], ['teams.microsoft.com', 'teams.live.com', 'skype.com',
                                                               'lync.com']),
    'zoom': ('Zoom', 'messaging', 'fa-video-camera', ['zoom'], []),
    # games
    'steam': ('Steam', 'games', 'fa-steam', ['steam'], []),
    'playstation': ('PlayStation', 'games', 'fa-gamepad', ['playstation'], []),
    'xbox': ('Xbox', 'games', 'fa-gamepad', ['xbox'], []),
    'nintendo': ('Nintendo', 'games', 'fa-gamepad', ['nintendo'], []),
    'epic': ('Epic Games / Fortnite', 'games', 'fa-gamepad', ['epicgames'], []),
    'riot': ('Riot Games', 'games', 'fa-gamepad', ['riot'], []),
    'roblox': ('Roblox', 'games', 'fa-gamepad', ['roblox'], []),
    'minecraft': ('Minecraft', 'games', 'fa-cube', ['mojang'], ['minecraft.net', 'minecraftservices.com']),
    'battlenet': ('Battle.net', 'games', 'fa-gamepad', ['blizzard'], []),
    'ea': ('EA', 'games', 'fa-gamepad', ['ea'], []),
    # shopping and other chosen use
    'amazon': ('Amazon shopping', 'shopping', 'fa-shopping-cart', ['amazon'], []),
    'ebay': ('eBay', 'shopping', 'fa-shopping-cart', ['ebay'], []),
    'chatgpt': ('ChatGPT', 'work', 'fa-comment-o', ['openai'], []),
    'claude': ('Claude', 'work', 'fa-comment-o', ['anthropic'], []),
    'github': ('GitHub', 'work', 'fa-github', ['github'], []),
    'dropbox': ('Dropbox', 'work', 'fa-dropbox', ['dropbox'], []),
    # what devices ask by themselves
    'apple': ('Apple (iCloud, updates)', 'platform', 'fa-apple', ['apple'], []),
    'google': ('Google (services, updates)', 'platform', 'fa-google', ['google'], []),
    'microsoft': ('Microsoft (Windows, Office)', 'platform', 'fa-windows', ['microsoft'], []),
    'samsung': ('Samsung (TV, phone)', 'platform', 'fa-television', ['samsung'], []),
    'amazon_devices': ('Amazon devices (Alexa, Fire TV)', 'platform', 'fa-amazon', [], [
        'amazonalexa.com', 'alexa.amazon.com', 'device-metrics-us.amazon.com', 'amazon-dss.com', 'amcs-tachyon.com']),
}

# v2fly lists that are included somewhere above but are not the service: AWS
# hosts half the internet, so "amazon" without it; a CA's names are no one's use
SKIPPED_LISTS = ['aws', 'amazontrust', 'apple-pki', 'microsoft-pki', 'google-trust-services', 'azure']

DOMAINS_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'services_domains.json')

KINDS = ['streaming', 'social', 'messaging', 'games', 'shopping', 'work', 'platform']

# suffix -> key, built once


def _load():
    """v2fly's suffixes as refresh.py wrote them, then Lens's own on top"""
    by_suffix = {}
    try:
        with open(DOMAINS_FILE) as handle:
            for suffix, key in json.load(handle).get('domains', {}).items():
                if key in SERVICES:
                    by_suffix[suffix] = key
    except (OSError, ValueError, AttributeError):
        pass
    for key, (_, _, _, _, suffixes) in SERVICES.items():
        for suffix in suffixes:
            by_suffix[suffix] = key
    return by_suffix


_BY_SUFFIX = _load()


def match(domain):
    """
    :param domain: a name as Unbound recorded it, trailing dot or not
    :return: the service key it belongs to, or None
    """
    labels = str(domain or '').rstrip('.').lower().split('.')
    # longest suffix first: "tv.apple.com" is Apple TV+, "apple.com" is Apple
    for start in range(len(labels) - 1):
        key = _BY_SUFFIX.get('.'.join(labels[start:]))
        if key is not None:
            return key
    return None


def describe(key):
    name, kind, icon, _, _ = SERVICES[key]
    return {'service': key, 'name': name, 'kind': kind, 'icon': icon}


def from_domains(domains):
    """
    One device's services from its names.

    :param domains: iterable of (name, questions, blocked)
    :return: (services, matched): [{'service', 'name', 'kind', 'icon', 'queries', 'blocked', 'names'}],
             most asked first, and how many questions matched any service
    """
    found = {}
    matched = 0
    for domain, count, blocked in domains:
        key = match(domain)
        if key is None:
            continue
        entry = found.setdefault(key, dict(describe(key), queries=0, blocked=0, names=0))
        entry['queries'] += int(count)
        entry['blocked'] += int(blocked or 0)
        entry['names'] += 1
        matched += int(count)
    return sorted(found.values(), key=lambda entry: (-entry['queries'], entry['name'])), matched


def network(devices, unplaced):
    """
    The network's services: each one, how many devices asked for it, how often,
    and every asker -- all of them, so the page can fold a phone's MACs into one
    device before it counts. Read from dns.by_device's two pools.

    :return: {'services': [{..., 'queries', 'blocked', 'devices', 'addresses',
                            'askers': [{'mac', 'address', 'count'}]}],
              devices: placed devices that asked; addresses: client addresses no device held then,
              'matched': questions that matched a service, 'total': all questions}
    """
    found = {}
    matched = total = 0
    for pool, placed in ((devices, True), (unplaced, False)):
        for key, entry in pool.items():
            total += entry['queries']
            mine, hits = from_domains((domain, values[0], values[1]) for domain, values in entry['domains'].items())
            matched += hits
            for service in mine:
                row = found.setdefault(service['service'], dict(describe(service['service']), queries=0, blocked=0,
                                                                 devices=0, addresses=0, askers=[]))
                row['queries'] += service['queries']
                row['blocked'] += service['blocked']
                row['devices' if placed else 'addresses'] += 1
                row['askers'].append({'mac': key if placed else None, 'address': None if placed else key,
                                      'count': service['queries']})
    listed = sorted(found.values(), key=lambda row: (-row['devices'] - row['addresses'], -row['queries'], row['name']))
    for row in listed:
        row['askers'] = sorted(row['askers'], key=lambda asker: -asker['count'])
    return {'services': listed, 'matched': matched, 'total': total}
