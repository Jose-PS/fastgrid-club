import argparse
import glob
import json
import math
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import geo
import osm

ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
OUT_DIR = os.path.join(ROOT, "resources", "tracks")
VIEW_W, VIEW_H, MARGIN = 400, 270, 20
ATTRIBUTION = "Trazado: © colaboradores de OpenStreetMap (ODbL)"
BRIDGE_LIMIT = 250.0


def load_ways(layout):
    query = layout.get("query") or osm.raceway_query(layout["bbox"])
    data = osm.run_query(query)
    return {e["id"]: e for e in data["elements"] if e.get("type") == "way"}


def way_points(way):
    return [(n["lat"], n["lon"]) for n in way["geometry"]]


def cut_polyline(points, start_fraction, end_fraction):
    total = geo.polyline_length(points)
    lo, hi = total * start_fraction, total * end_fraction
    out = []
    run = 0.0
    for a, b in zip(points, points[1:]):
        seg = geo.dist(a, b)
        if seg == 0:
            continue
        s0, s1 = run, run + seg
        if s1 >= lo and s0 <= hi:
            ta = max(0.0, (lo - s0) / seg)
            tb = min(1.0, (hi - s0) / seg)
            pa = (a[0] + (b[0] - a[0]) * ta, a[1] + (b[1] - a[1]) * ta)
            pb = (a[0] + (b[0] - a[0]) * tb, a[1] + (b[1] - a[1]) * tb)
            if not out:
                out.append(pa)
            out.append(pb)
        run = s1
    return out


def assemble(layout, ways, project):
    sequence = []
    for entry in layout["ways"]:
        way_id = entry["id"] if isinstance(entry, dict) else entry
        if way_id not in ways:
            raise SystemExit("%s: la vía %s no está en la respuesta de OSM" % (layout["slug"], way_id))
        points = [project(lat, lon) for lat, lon in way_points(ways[way_id])]
        if isinstance(entry, dict) and "trim" in entry:
            points = cut_polyline(points, entry["trim"][0], entry["trim"][1])
        if isinstance(entry, dict) and entry.get("reverse"):
            points.reverse()
        sequence.append({"id": way_id, "points": points, "oneway": ways[way_id]["tags"].get("oneway") in ("yes", "-1"), "backward": ways[way_id]["tags"].get("oneway") == "-1", "origin": points[0], "fixed": isinstance(entry, dict) and "reverse" in entry})

    for i, item in enumerate(sequence):
        if item["fixed"]:
            continue
        if len(sequence) == 1:
            continue
        if i == 0:
            nxt = sequence[1]["points"]
            best = min(
                (geo.dist(a, b), flip)
                for flip, a in ((False, item["points"][-1]), (True, item["points"][0]))
                for b in (nxt[0], nxt[-1])
            )
            if best[1]:
                item["points"].reverse()
        else:
            prev_end = sequence[i - 1]["points"][-1]
            if geo.dist(prev_end, item["points"][-1]) < geo.dist(prev_end, item["points"][0]):
                item["points"].reverse()

    path = []
    gaps = []
    for i, item in enumerate(sequence):
        pts = item["points"]
        if not path:
            path.extend(pts)
            continue
        gap = geo.dist(path[-1], pts[0])
        if gap > 4.0:
            gaps.append((sequence[i - 1]["id"], item["id"], gap))
        if gap < 0.5:
            path.extend(pts[1:])
        else:
            path.extend(pts)
    closing = geo.dist(path[-1], path[0])
    if closing < 0.5:
        path.pop()
    elif closing > 4.0:
        gaps.append((sequence[-1]["id"], sequence[0]["id"], closing))
    return path, sequence, gaps


def way_direction_agreement(sequence):
    agree = total = 0.0
    for item in sequence:
        if item["oneway"]:
            length = geo.polyline_length(item["points"])
            total += length
            if (item["points"][0] == item["origin"]) != item["backward"]:
                agree += length
    return agree, total


def point_on_way(sequence, way_id, fraction):
    for item in sequence:
        if item["id"] == way_id:
            pts = item["points"]
            target = geo.polyline_length(pts) * fraction
            run = 0.0
            for a, b in zip(pts, pts[1:]):
                seg = geo.dist(a, b)
                if run + seg >= target and seg > 0:
                    t = (target - run) / seg
                    return (a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t)
                run += seg
            return pts[-1]
    raise SystemExit("la vía %s no forma parte del layout" % way_id)


def locate(spec, sequence, project):
    if "way" in spec:
        return point_on_way(sequence, spec["way"], spec.get("fraction", 0.5))
    return project(spec["lat"], spec["lon"])


def rotate_to_start(path, start_xy):
    d, i, t, p = geo.nearest_on_polyline(path, start_xy)
    return [p] + path[i + 1 :] + path[: i + 1], d


def fit(points_sets, marks):
    xs = [p[0] for s in points_sets for p in s]
    ys = [p[1] for s in points_sets for p in s]
    minx, maxx, miny, maxy = min(xs), max(xs), min(ys), max(ys)
    w, h = maxx - minx, maxy - miny
    scale = min((VIEW_W - 2 * MARGIN) / w, (VIEW_H - 2 * MARGIN) / h)
    ox = (VIEW_W - w * scale) / 2 - minx * scale
    oy = (VIEW_H - h * scale) / 2 + maxy * scale

    def tf(p):
        return (p[0] * scale + ox, oy - p[1] * scale)

    return tf, scale


def point_at_arc(path, start_index, arc):
    n = len(path)
    remaining = arc
    i = start_index
    while True:
        a, b = path[i % n], path[(i + 1) % n]
        seg = geo.dist(a, b)
        if seg >= remaining and seg > 0:
            t = remaining / seg
            return (a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t)
        remaining -= seg
        i += 1


def rotate_point(p, cos_a, sin_a):
    return (p[0] * cos_a - p[1] * sin_a, p[0] * sin_a + p[1] * cos_a)


def start_heading(path, window=100.0, closed=True):
    n = len(path)
    ahead = point_at_arc(path, 0, window)
    if not closed:
        return math.atan2(ahead[1] - path[0][1], ahead[0] - path[0][0])
    reverse = [path[0]] + path[:0:-1]
    behind = point_at_arc(reverse, 0, window)
    return math.atan2(ahead[1] - behind[1], ahead[0] - behind[0])


def fmt(v):
    return "%.1f" % v


def path_d(points, closed=True):
    out = ["M%s %s" % (fmt(points[0][0]), fmt(points[0][1]))]
    for p in points[1:]:
        out.append("L%s %s" % (fmt(p[0]), fmt(p[1])))
    if closed:
        out.append("Z")
    return "".join(out)


def tangent_at(points, p):
    d, i, t, q = geo.nearest_on_polyline(points, p)
    a = points[i]
    b = points[(i + 1) % len(points)]
    length = geo.dist(a, b) or 1.0
    return ((b[0] - a[0]) / length, (b[1] - a[1]) / length)


def text_box(x, y, text, size, anchor):
    width = len(text) * size * 0.55
    if anchor == "start":
        x0 = x
    elif anchor == "end":
        x0 = x - width
    else:
        x0 = x - width / 2
    return (x0, y - size, x0 + width, y + size * 0.25)


def boxes_overlap(a, b, pad=1.0):
    return not (a[2] + pad < b[0] or b[2] + pad < a[0] or a[3] + pad < b[1] or b[3] + pad < a[1])


def place_label(text, anchor_pt, path, boxes, size, wide=False, closed=True):
    for clear in (11.5, 9.5, 8.0):
        try:
            return place_label_at(text, anchor_pt, path, boxes, size, clear, closed)
        except LookupError:
            continue
    raise SystemExit("sin hueco para la etiqueta %s" % text)


def place_label_at(text, anchor_pt, path, boxes, size, min_clear, closed=True):
    best = None
    for radius in (13, 16, 20, 25, 31, 38, 46):
        for k in range(16):
            ang = k * math.pi / 8
            cx = anchor_pt[0] + radius * math.cos(ang)
            cy = anchor_pt[1] + radius * math.sin(ang)
            c = math.cos(ang)
            anchor = "start" if c > 0.35 else ("end" if c < -0.35 else "middle")
            box = text_box(cx, cy, text, size, anchor)
            if box[0] < 4 or box[2] > VIEW_W - 4 or box[1] < 4 or box[3] > VIEW_H - 4:
                continue
            samples = [(box[0], box[1]), (box[2], box[1]), (box[0], box[3]), (box[2], box[3]), ((box[0] + box[2]) / 2, (box[1] + box[3]) / 2), ((box[0] + box[2]) / 2, box[1]), ((box[0] + box[2]) / 2, box[3])]
            clearance = min(geo.distance_to_polygon(s, path, closed) for s in samples)
            if clearance < min_clear:
                continue
            if any(boxes_overlap(box, b) for b in boxes):
                continue
            score = radius
            if best is None or score < best[0]:
                best = (score, cx, cy, anchor, box)
        if best:
            break
    if best is None:
        raise LookupError(text)
    return best


def build(layout_path, check_only=False):
    with open(layout_path) as handle:
        layout = json.load(handle)
    slug = layout["slug"]
    ways = load_ways(layout)
    lat0 = sum(layout["bbox"][0::2]) / 2
    lon0 = sum(layout["bbox"][1::2]) / 2
    project = geo.make_projection(lat0, lon0)

    path, sequence, gaps = assemble(layout, ways, project)

    clockwise = layout["direction"] == "clockwise"
    area = geo.signed_area(path)
    is_clockwise = area < 0
    reversed_whole = False
    if is_clockwise != clockwise:
        path.reverse()
        for item in sequence:
            item["points"].reverse()
        reversed_whole = True
    area = geo.signed_area(path)
    direction_ok = (area < 0) == clockwise

    length = geo.polyline_length(path, closed=True)
    official = layout["official_length_m"]
    deviation = (length - official) / official * 100
    length_ok = abs(deviation) <= 5.0

    start_xy = locate(layout["start"], sequence, project)
    path, start_off = rotate_to_start(path, start_xy)
    is_open = bool(layout.get("open"))
    loop_length = length
    if is_open:
        cut = [path[0]]
        run = 0.0
        for a, b in zip(path, path[1:] + [path[0]]):
            seg = geo.dist(a, b)
            if run + seg >= official:
                t = (official - run) / seg
                cut.append((a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t))
                break
            cut.append(b)
            run += seg
        path = cut
        length = geo.polyline_length(path)
        deviation = (length - official) / official * 100
        length_ok = abs(deviation) <= 5.0

    agree, total = way_direction_agreement(sequence)

    theta = -start_heading(path, closed=not is_open) + math.radians(layout.get("rotation_offset_deg", 0))
    cos_a, sin_a = math.cos(theta), math.sin(theta)
    rotated = [rotate_point(p, cos_a, sin_a) for p in path]
    cy = sum(p[1] for p in rotated) / len(rotated)
    if cy < rotated[0][1]:
        theta += math.pi
        cos_a, sin_a = math.cos(theta), math.sin(theta)
        rotated = [rotate_point(p, cos_a, sin_a) for p in path]
    path = rotated
    for item in sequence:
        item["points"] = [rotate_point(p, cos_a, sin_a) for p in item["points"]]
    applied = math.degrees(theta) % 360

    tf, scale = fit([path], None)
    scaled_full = [tf(p) for p in path]
    tol = layout.get("tolerance", 0.12)
    simple = geo.simplify(scaled_full, tol) if is_open else geo.simplify_closed(scaled_full, tol)
    d = path_d(simple, not is_open)

    sx = tf(path[0])
    tan = tangent_at(scaled_full, sx)
    nx, ny = -tan[1], tan[0]
    half = 12.0
    line = (sx[0] - nx * half, sx[1] - ny * half, sx[0] + nx * half, sx[1] + ny * half)

    boxes = []
    meta = place_label("Meta", sx, simple, boxes, 9, closed=not is_open)
    boxes.append(meta[4])
    exit_label = None
    if is_open:
        ex = simple[-1]
        exit_label = place_label("Salida", ex, simple, boxes, 9, closed=False)
        boxes.append(exit_label[4])

    labels = []
    for turn in layout.get("turns", []):
        pt = tf(locate(turn, sequence, project))
        near = geo.nearest_on_polyline(simple, pt, not is_open)
        label = place_label(turn["name"], near[3], simple, boxes, 7, closed=not is_open)
        boxes.append(label[4])
        labels.append((turn["name"], label))

    parts = [
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" data-attribution="%s">' % (VIEW_W, VIEW_H, ATTRIBUTION),
        "<title>%s</title>" % layout["name"],
        '<path class="track-edge" d="%s"/>' % d,
        '<path class="track-asphalt" d="%s"/>' % d,
        '<line class="track-line" x1="%s" y1="%s" x2="%s" y2="%s"/>' % tuple(fmt(v) for v in line),
        '<text class="track-label" x="%s" y="%s" text-anchor="%s" font-size="9" fill="currentColor">Meta</text>' % (fmt(meta[1]), fmt(meta[2]), meta[3]),
    ]
    if exit_label:
        parts.append('<text class="track-label" x="%s" y="%s" text-anchor="%s" font-size="9" fill="currentColor">Salida</text>' % (fmt(exit_label[1]), fmt(exit_label[2]), exit_label[3]))
    for name, label in labels:
        parts.append('<text class="track-turn" x="%s" y="%s" text-anchor="%s" font-size="7" fill="currentColor" opacity="0.75">%s</text>' % (fmt(label[1]), fmt(label[2]), label[3], name))
    parts.append("</svg>")
    svg = "\n".join(parts) + "\n"

    os.makedirs(OUT_DIR, exist_ok=True)
    out_path = os.path.join(OUT_DIR, slug + ".svg")
    with open(out_path, "w") as handle:
        handle.write(svg)

    print("== %s (%s)" % (slug, layout["name"]))
    print("  vías: %d, huecos puenteados: %s" % (len(sequence), ["%s->%s %.0f m" % g for g in gaps] or "ninguno"))
    if is_open:
        print("  recorrido abierto: vuelta completa %.0f m, tramo no recorrido %.0f m" % (loop_length, loop_length - length))
    print("  longitud medida %.0f m vs oficial %d m (%+.1f %%) -> %s" % (length, official, deviation, "OK" if length_ok else "FALLA"))
    print("  sentido declarado %s, área con signo %.0f m2 -> %s%s" % (layout["direction"], area, "OK" if direction_ok else "FALLA", " (orden de vías invertido)" if reversed_whole else ""))
    print("  vías oneway de OSM recorridas en su sentido: %.0f %% (%.0f m con oneway)" % (100 * agree / total if total else 100, total))
    print("  rotación aplicada %.1f grados (antihoraria)" % applied)
    print("  salida en %.1f m de la meta declarada; d: %d bytes, %d puntos; %s" % (start_off, len(d), len(simple), os.path.relpath(out_path, ROOT)))
    return length_ok and direction_ok and not [g for g in gaps if g[2] > BRIDGE_LIMIT]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("slugs", nargs="*")
    args = parser.parse_args()
    files = sorted(glob.glob(os.path.join(HERE, "layouts", "*.json")))
    if args.slugs:
        files = [f for f in files if os.path.basename(f)[:-5] in args.slugs]
    ok = True
    for f in files:
        ok = build(f) and ok
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
