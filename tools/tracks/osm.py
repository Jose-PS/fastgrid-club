import hashlib
import json
import os
import time
import urllib.parse
import urllib.request

ENDPOINTS = [
    "https://overpass-api.de/api/interpreter",
]
USER_AGENT = "acevo-track-tool/1.0 (offline track outline generator; OSM ODbL data)"
CACHE_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "cache")


def run_query(query, pause=5):
    os.makedirs(CACHE_DIR, exist_ok=True)
    key = hashlib.sha1(query.encode()).hexdigest()[:16]
    path = os.path.join(CACHE_DIR, key + ".json")
    if os.path.exists(path):
        with open(path) as handle:
            return json.load(handle)
    data = urllib.parse.urlencode({"data": query}).encode()
    last = None
    for attempt in range(8):
        for endpoint in ENDPOINTS:
            request = urllib.request.Request(endpoint, data=data, headers={"User-Agent": USER_AGENT})
            try:
                with urllib.request.urlopen(request, timeout=60) as response:
                    payload = json.load(response)
                with open(path, "w") as handle:
                    json.dump(payload, handle)
                time.sleep(pause)
                return payload
            except Exception as error:
                last = error
                time.sleep(pause * (attempt + 1))
    raise RuntimeError("Overpass no responde: %s" % last)


def raceway_query(bbox):
    south, west, north, east = bbox
    return '[out:json][timeout:90];way["highway"="raceway"](%s,%s,%s,%s);out tags geom;' % (south, west, north, east)
