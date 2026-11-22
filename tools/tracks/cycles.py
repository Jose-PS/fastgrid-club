import argparse
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import geo
import osm

SNAP = 4.0


def graph_for(bbox, exclude):
    data = osm.run_query(osm.raceway_query(bbox))
    lat0 = (bbox[0] + bbox[2]) / 2
    lon0 = (bbox[1] + bbox[3]) / 2
    project = geo.make_projection(lat0, lon0)
    ways = []
    for e in data["elements"]:
        name = (e["tags"].get("name") or "").lower()
        if any(x in name for x in exclude):
            continue
        pts = [project(n["lat"], n["lon"]) for n in e["geometry"]]
        ways.append({"id": e["id"], "name": e["tags"].get("name"), "pts": pts, "oneway": e["tags"].get("oneway") == "yes", "length": geo.polyline_length(pts)})
    return ways


def node_index(ways):
    nodes = []

    def find(p):
        for i, q in enumerate(nodes):
            if geo.dist(p, q) <= SNAP:
                return i
        nodes.append(p)
        return len(nodes) - 1

    edges = []
    for w in ways:
        a = find(w["pts"][0])
        b = find(w["pts"][-1])
        edges.append((a, b, w, False))
        if not w["oneway"]:
            edges.append((b, a, w, True))
    return nodes, edges


def find_cycles(ways, target, tol, limit=20):
    nodes, edges = node_index(ways)
    out_edges = {}
    for e in edges:
        out_edges.setdefault(e[0], []).append(e)
    results = []
    upper = target * (1 + tol)

    def dfs(start, node, used, total, trail):
        if len(results) >= 200:
            return
        for a, b, w, rev in out_edges.get(node, []):
            if w["id"] in used:
                continue
            t = total + w["length"]
            if t > upper:
                continue
            step = trail + [(w["id"], rev)]
            if b == start:
                if t >= target * (1 - tol):
                    results.append((t, step))
                continue
            dfs(start, b, used | {w["id"]}, t, step)

    starts = sorted({e[0] for e in edges})
    seen = set()
    for s in starts:
        dfs(s, s, frozenset(), 0.0, [])
    unique = {}
    for t, steps in results:
        key = frozenset(i for i, _ in steps)
        unique.setdefault(key, (t, steps))
    ordered = sorted(unique.values(), key=lambda r: abs(r[0] - target))
    return ordered[:limit], ways


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("bbox", nargs=4, type=float)
    parser.add_argument("length", type=float)
    parser.add_argument("--tol", type=float, default=0.06)
    parser.add_argument("--exclude", nargs="*", default=[])
    args = parser.parse_args()
    ways = graph_for(tuple(args.bbox), args.exclude)
    names = {w["id"]: w["name"] for w in ways}
    cycles, _ = find_cycles(ways, args.length, args.tol)
    for t, steps in cycles:
        print("%.0f m" % t, [(i, names[i]) for i, _ in steps])


if __name__ == "__main__":
    main()
