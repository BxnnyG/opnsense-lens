"""
Which services the devices asked for (#44, §4.78).

A curated map of domain suffixes to the services that own them, laid over the
questions the DNS page already reads. It turns "who asked what" into "who asked
for Netflix, WhatsApp, Steam" -- and no further. What it cannot see, the page
says (§4.78): only questions put to Unbound, approximate behind shared CDNs,
blind to DNS over HTTPS and to anything a device resolved elsewhere.

A name matches the longest suffix it ends in, on a label boundary: "nflxvideo.net"
matches "a.nflxvideo.net" but not "notnflxvideo.net". A name nothing matches
stays a name; it is never guessed into a service.

Kinds sort the page: what someone chose to use (streaming, games, social...)
stands apart from what devices ask by themselves (platform: Apple, Google,
Microsoft background traffic), so a phone checking for updates does not read as
a person doing something.
"""

# key: (name, kind, icon, suffixes)
SERVICES = {
    # streaming
    'netflix': ('Netflix', 'streaming', 'fa-film', ['netflix.com', 'netflix.net', 'nflxvideo.net', 'nflximg.net',
                                                     'nflximg.com', 'nflxext.com', 'nflxso.net']),
    'youtube': ('YouTube', 'streaming', 'fa-youtube-play', ['youtube.com', 'googlevideo.com', 'ytimg.com',
                                                             'youtubei.googleapis.com', 'youtube-nocookie.com',
                                                             'youtu.be']),
    'disney': ('Disney+', 'streaming', 'fa-film', ['disneyplus.com', 'disney-plus.net', 'dssott.com', 'bamgrid.com']),
    'prime': ('Prime Video', 'streaming', 'fa-film', ['primevideo.com', 'aiv-cdn.net', 'aiv-delivery.net',
                                                       'amazonvideo.com', 'pv-cdn.net']),
    'twitch': ('Twitch', 'streaming', 'fa-twitch', ['twitch.tv', 'ttvnw.net', 'jtvnw.net']),
    'spotify': ('Spotify', 'streaming', 'fa-spotify', ['spotify.com', 'scdn.co', 'spotifycdn.com', 'spotify.net',
                                                        'pscdn.co']),
    'appletv': ('Apple TV+ / Music', 'streaming', 'fa-music', ['tv.apple.com', 'music.apple.com',
                                                                'hls.itunes.apple.com', 'aod.itunes.apple.com']),
    'dazn': ('DAZN', 'streaming', 'fa-futbol-o', ['dazn.com', 'dazn-api.com', 'indazn.com']),
    'joyn': ('Joyn', 'streaming', 'fa-film', ['joyn.de', 'joyn.net']),
    'zdf': ('ZDF / ARD', 'streaming', 'fa-television', ['zdf.de', 'ardmediathek.de', 'ard.de', 'daserste.de',
                                                         'akamaized-ard.de']),
    'plex': ('Plex', 'streaming', 'fa-play-circle', ['plex.tv', 'plex.direct']),
    # social
    'instagram': ('Instagram', 'social', 'fa-instagram', ['instagram.com', 'cdninstagram.com']),
    'facebook': ('Facebook', 'social', 'fa-facebook-official', ['facebook.com', 'facebook.net', 'fbcdn.net',
                                                                 'fb.com', 'fbsbx.com']),
    'tiktok': ('TikTok', 'social', 'fa-music', ['tiktok.com', 'tiktokv.com', 'tiktokcdn.com', 'byteoversea.com',
                                                 'ibytedtos.com', 'tiktokcdn-eu.com', 'tiktokv.eu']),
    'snapchat': ('Snapchat', 'social', 'fa-snapchat-ghost', ['snapchat.com', 'sc-cdn.net', 'snapkit.com',
                                                              'sc-static.net']),
    'x': ('X / Twitter', 'social', 'fa-twitter', ['twitter.com', 'twimg.com', 'x.com', 't.co']),
    'reddit': ('Reddit', 'social', 'fa-reddit-alien', ['reddit.com', 'redd.it', 'redditmedia.com',
                                                        'redditstatic.com']),
    'pinterest': ('Pinterest', 'social', 'fa-pinterest', ['pinterest.com', 'pinimg.com']),
    'linkedin': ('LinkedIn', 'social', 'fa-linkedin', ['linkedin.com', 'licdn.com']),
    # messaging and calls
    'whatsapp': ('WhatsApp', 'messaging', 'fa-whatsapp', ['whatsapp.com', 'whatsapp.net']),
    'telegram': ('Telegram', 'messaging', 'fa-telegram', ['telegram.org', 't.me', 'telegram.me']),
    'signal': ('Signal', 'messaging', 'fa-comment', ['signal.org', 'whispersystems.org', 'signal.art']),
    'discord': ('Discord', 'messaging', 'fa-comments', ['discord.com', 'discord.gg', 'discordapp.com',
                                                         'discordapp.net', 'discord.media']),
    'teams': ('Microsoft Teams', 'messaging', 'fa-users', ['teams.microsoft.com', 'teams.live.com',
                                                            'skype.com', 'lync.com']),
    'zoom': ('Zoom', 'messaging', 'fa-video-camera', ['zoom.us', 'zoom.com', 'zoomgov.com']),
    # games
    'steam': ('Steam', 'games', 'fa-steam', ['steampowered.com', 'steamcommunity.com', 'steamcontent.com',
                                              'steamstatic.com', 'steamserver.net']),
    'playstation': ('PlayStation', 'games', 'fa-gamepad', ['playstation.com', 'playstation.net',
                                                           'sonyentertainmentnetwork.com']),
    'xbox': ('Xbox', 'games', 'fa-gamepad', ['xboxlive.com', 'xbox.com', 'xboxservices.com']),
    'nintendo': ('Nintendo', 'games', 'fa-gamepad', ['nintendo.com', 'nintendo.net', 'nintendo.co.jp']),
    'epic': ('Epic Games / Fortnite', 'games', 'fa-gamepad', ['epicgames.com', 'epicgames.dev', 'unrealengine.com',
                                                               'fortnite.com']),
    'riot': ('Riot Games', 'games', 'fa-gamepad', ['riotgames.com', 'leagueoflegends.com', 'riotcdn.net']),
    'roblox': ('Roblox', 'games', 'fa-gamepad', ['roblox.com', 'rbxcdn.com', 'rbx.com']),
    'minecraft': ('Minecraft', 'games', 'fa-cube', ['minecraft.net', 'mojang.com', 'minecraftservices.com']),
    'battlenet': ('Battle.net', 'games', 'fa-gamepad', ['battle.net', 'blizzard.com', 'blzstatic.com']),
    'ea': ('EA', 'games', 'fa-gamepad', ['ea.com', 'origin.com']),
    # shopping and other chosen use
    'amazon': ('Amazon shopping', 'shopping', 'fa-shopping-cart', ['amazon.com', 'amazon.de', 'media-amazon.com',
                                                                   'ssl-images-amazon.com']),
    'ebay': ('eBay', 'shopping', 'fa-shopping-cart', ['ebay.com', 'ebay.de', 'ebayimg.com', 'ebaystatic.com']),
    'chatgpt': ('ChatGPT', 'work', 'fa-comment-o', ['chatgpt.com', 'openai.com', 'oaiusercontent.com']),
    'claude': ('Claude', 'work', 'fa-comment-o', ['claude.ai', 'anthropic.com']),
    'github': ('GitHub', 'work', 'fa-github', ['github.com', 'githubusercontent.com', 'github.io',
                                                'githubassets.com']),
    'dropbox': ('Dropbox', 'work', 'fa-dropbox', ['dropbox.com', 'dropboxapi.com', 'dropboxusercontent.com']),
    # what devices ask by themselves
    'apple': ('Apple (iCloud, updates)', 'platform', 'fa-apple', ['apple.com', 'icloud.com', 'mzstatic.com',
                                                                  'apple-dns.net', 'aaplimg.com', 'cdn-apple.com',
                                                                  'icloud-content.com', 'apple.news']),
    'google': ('Google (services, updates)', 'platform', 'fa-google', ['google.com', 'googleapis.com', 'gstatic.com',
                                                                       'gvt1.com', 'gvt2.com', 'google.de',
                                                                       'googleusercontent.com', 'android.com',
                                                                       '1e100.net']),
    'microsoft': ('Microsoft (Windows, Office)', 'platform', 'fa-windows', ['microsoft.com', 'windows.com',
                                                                            'windowsupdate.com', 'office.com',
                                                                            'office.net', 'live.com', 'msn.com',
                                                                            'bing.com', 'msftconnecttest.com',
                                                                            'microsoftonline.com', 'azureedge.net',
                                                                            'sharepoint.com', 'onedrive.com']),
    'samsung': ('Samsung (TV, phone)', 'platform', 'fa-television', ['samsung.com', 'samsungcloud.com',
                                                                     'samsungcloudsolution.com', 'samsungqbe.com',
                                                                     'samsungosp.com', 'samsungelectronics.com']),
    'amazon_devices': ('Amazon devices (Alexa, Fire TV)', 'platform', 'fa-amazon', ['amazonalexa.com',
                                                                                    'alexa.amazon.com',
                                                                                    'device-metrics-us.amazon.com',
                                                                                    'amazon-dss.com',
                                                                                    'amcs-tachyon.com']),
}

KINDS = ['streaming', 'social', 'messaging', 'games', 'shopping', 'work', 'platform']

# suffix -> key, built once
_BY_SUFFIX = {}
for _key, (_name, _kind, _icon, _suffixes) in SERVICES.items():
    for _suffix in _suffixes:
        _BY_SUFFIX[_suffix] = _key


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
    name, kind, icon, _ = SERVICES[key]
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
