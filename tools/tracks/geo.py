import math

R = 6371008.8


def make_projection(lat0, lon0):
    k = math.cos(math.radians(lat0))

    def project(lat, lon):
        return (math.radians(lon - lon0) * R * k, math.radians(lat - lat0) * R)

    return project


def dist(a, b):
    return math.hypot(a[0] - b[0], a[1] - b[1])


def polyline_length(points, closed=False):
    total = sum(dist(points[i], points[i + 1]) for i in range(len(points) - 1))
    if closed and points:
        total += dist(points[-1], points[0])
    return total


def signed_area(points):
    total = 0.0
    for i in range(len(points)):
        x1, y1 = points[i]
        x2, y2 = points[(i + 1) % len(points)]
        total += x1 * y2 - x2 * y1
    return total / 2


def nearest_on_polyline(points, target, closed=True):
    best = None
    count = len(points)
    last = count if closed else count - 1
    for i in range(last):
        a = points[i]
        b = points[(i + 1) % count]
        dx, dy = b[0] - a[0], b[1] - a[1]
        length2 = dx * dx + dy * dy
        t = 0.0 if length2 == 0 else max(0.0, min(1.0, ((target[0] - a[0]) * dx + (target[1] - a[1]) * dy) / length2))
        p = (a[0] + t * dx, a[1] + t * dy)
        d = dist(p, target)
        if best is None or d < best[0]:
            best = (d, i, t, p)
    return best


def simplify(points, tolerance):
    if len(points) < 3:
        return list(points)
    keep = [False] * len(points)
    keep[0] = keep[-1] = True
    stack = [(0, len(points) - 1)]
    while stack:
        lo, hi = stack.pop()
        a, b = points[lo], points[hi]
        dx, dy = b[0] - a[0], b[1] - a[1]
        length = math.hypot(dx, dy)
        worst, index = -1.0, -1
        for i in range(lo + 1, hi):
            p = points[i]
            if length == 0:
                d = dist(p, a)
            else:
                d = abs(dx * (a[1] - p[1]) - dy * (a[0] - p[0])) / length
            if d > worst:
                worst, index = d, i
        if worst > tolerance:
            keep[index] = True
            stack.append((lo, index))
            stack.append((index, hi))
    return [p for p, k in zip(points, keep) if k]


def simplify_closed(points, tolerance):
    n = len(points)
    half = n // 2
    first = simplify(points[: half + 1], tolerance)
    second = simplify(points[half:] + [points[0]], tolerance)
    return first[:-1] + second[:-1]


def segment_distance(p, a, b):
    dx, dy = b[0] - a[0], b[1] - a[1]
    length2 = dx * dx + dy * dy
    t = 0.0 if length2 == 0 else max(0.0, min(1.0, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / length2))
    return dist(p, (a[0] + t * dx, a[1] + t * dy))


def distance_to_polygon(p, points, closed=True):
    last = len(points) if closed else len(points) - 1
    return min(segment_distance(p, points[i], points[(i + 1) % len(points)]) for i in range(last))
