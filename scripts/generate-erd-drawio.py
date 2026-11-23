"""Generate both GigKampus ERDs and matching PNG previews. Requires Python 3.10+ and Pillow."""

from __future__ import annotations

import heapq
import math
import os
import re
from collections import Counter
from dataclasses import dataclass
from functools import lru_cache
from itertools import combinations, groupby
from pathlib import Path
from statistics import median
from xml.etree import ElementTree as ET

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[1]
GRID = 18
ATTRIBUTE_GAP = 48
MARGIN = 24
STEP = ((1, 0), (0, 1), (-1, 0), (0, -1))
ARROWS = {"||": "ERmandOne", "optional": "ERzeroToOne", "many": "ERoneToMany", "o{": "ERzeroToMany"}

@dataclass(frozen=True)
class Palette:
    fill: str
    stroke: str
    text: str
    width: float
    size: int
    bold: bool = False

    def style(self, shape):
        return (f"{shape};whiteSpace=wrap;html=1;fillColor={self.fill};strokeColor={self.stroke};"
                f"strokeWidth={self.width:g};fontFamily=Helvetica;fontSize={self.size};"
                f"fontColor={self.text};fontStyle={int(self.bold)};")


ENTITY = Palette("#DBEAFE", "#1D4ED8", "#0F172A", 1.8, 11, True)
DIAMOND = Palette("#FEF3C7", "#B45309", "#78350F", 1.4, 9, True)
ATTRIBUTE = Palette("#FFFFFF", "#64748B", "#1E293B", 1, 9)
PRIMARY = Palette("#EFF6FF", "#2563EB", "#0F172A", 1.2, 9, True)
SPOKE_COLOR, SPOKE_WIDTH = "#94A3B8", 0.9
RELATION_COLOR, RELATION_WIDTH = "#334155", 1.35
ATTRIBUTE_EDGE = ("endArrow=none;startArrow=none;edgeStyle=none;"
                  f"strokeColor={SPOKE_COLOR};strokeWidth={SPOKE_WIDTH};")
RELATION_EDGE = (
    "edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;"
    f"html=1;strokeColor={RELATION_COLOR};strokeWidth={RELATION_WIDTH};"
    "endArrow=none;startSize=7;jumpStyle=arc;jumpSize=7;"
)

# Centers and sizes use the routing grid. These sides have clear approaches for
# relationship connectors. Attributes occupy the other sides and their corners.
PLACEMENT = {
    # table                            center x/y   width/height   clear sides
    "banks":                           (26, 11,        6, 2,       "ES"),
    "categories":                      (56,  7,        6, 2,       "ES"),
    "ktm_verification_requests":       (18, 42,       12, 8,       "E"),
    "users":                           (51, 41,       16, 18,      "ES"),
    "service_requests":                (51, 19,       10, 8,       "SE"),
    "gigs":                            (84, 19,       10, 6,       "S"),
    "price_offers":                    (69, 58,       10, 6,       "NE"),
    "orders":                         (102, 47,       20, 20,      "S"),
    "payments":                       (110, 14,        8, 8,       "SE"),
    "payment_proofs":                 (142, 14,       10, 8,       "WS"),
    "revisions":                      (136, 35,        8, 4,       "SW"),
    "order_deliveries":               (164, 35,        8, 6,       "WS"),
    "reviews":                        (157, 56,        8, 4,       "W"),
    "chat_rooms":                     (134, 63,        8, 4,       "WE"),
    "chat_messages":                  (167, 82,       10, 8,       "W"),
    "notifications":                   (62, 89,        8, 6,       "NE"),
    "admins":                          (91, 88,        8, 4,       "NE"),
    "reports":                        (118, 94,       12, 12,      "N"),
    "audit_logs":                      (76, 113,      10, 6,       "NE"),
    "report_clarifications":          (146, 107,      10, 4,       "NE"),
    "report_clarification_responses": (171, 111,      12, 4,       "W"),
}

# The original foreign-key column names are preserved in each diamond's ID.
VERBS = {
    "users": {"bank_id": "serves"},
    "gigs": {"seller_id": "provides", "category_id": "classifies"},
    "service_requests": {"buyer_id": "posts", "category_id": "groups"},
    "price_offers": {"service_request_id": "elicits", "seller_id": "offers"},
    "orders": {
        "buyer_id": "places", "seller_id": "fulfills", "gig_id": "booked_as",
        "price_offer_id": "accepted_as", "cancellation_requested_by_id": "seeks_cancel",
    },
    "payments": {
        "order_id": "paid_via", "verified_proof_id": "backs",
        "verified_by_user_id": "verifies", "verified_by_admin_id": "verifies",
        "refund_confirmed_by_user_id": "confirms", "refund_confirmed_by_admin_id": "confirms",
    },
    "payment_proofs": {"payment_id": "proven_by", "reviewed_by_seller_id": "checks"},
    "revisions": {"order_id": "revised_by"},
    "order_deliveries": {"order_id": "delivered_as", "revision_id": "resolves"},
    "reviews": {
        "order_id": "rated_with", "reviewer_id": "writes", "seller_id": "receives",
        "hidden_by_admin_id": "hides",
    },
    "chat_rooms": {
        "buyer_id": "asks_in", "seller_id": "answers_in", "gig_id": "discussed_in",
        "price_offer_id": "negotiated_in", "order_id": "links_to",
    },
    "chat_messages": {"chat_room_id": "contains", "sender_id": "sends"},
    "notifications": {"user_id": "notifies", "admin_id": "alerts", "order_id": "triggers", "report_id": "signals"},
    "reports": {
        "reporter_id": "files", "gig_id": "flagged_in", "service_request_id": "flagged_in",
        "review_id": "flagged_in", "chat_room_id": "flagged_in", "order_id": "disputed_in",
        "handled_by_admin_id": "handles",
    },
    "report_clarifications": {"report_id": "clarified_by", "requested_by_admin_id": "requests", "recipient_user_id": "addresses"},
    "report_clarification_responses": {"clarification_id": "answered_by", "respondent_id": "responds"},
    "ktm_verification_requests": {"user_id": "submits", "reviewed_by_admin_id": "reviews"},
    "audit_logs": {"actor_user_id": "acts_in", "actor_admin_id": "administers", "order_id": "logs_order", "report_id": "logs_case"},
}

# Keep the ownership, source, lifecycle, and target links on the overview.
DETAIL_RELATIONS = {
    ("orders", "cancellation_requested_by_id"),
    ("payments", "verified_by_user_id"), ("payments", "verified_by_admin_id"),
    ("payments", "refund_confirmed_by_user_id"), ("payments", "refund_confirmed_by_admin_id"),
    ("payment_proofs", "reviewed_by_seller_id"), ("reviews", "hidden_by_admin_id"),
    ("notifications", "order_id"), ("notifications", "report_id"),
    ("report_clarifications", "requested_by_admin_id"), ("report_clarifications", "recipient_user_id"),
    ("report_clarification_responses", "respondent_id"), ("audit_logs", "actor_user_id"),
    ("audit_logs", "actor_admin_id"),
}
COMPACT_COLUMNS = {"original_filename", "mime_type", "file_size", "updated_at", "ocr_finished_at"}


@dataclass(frozen=True)
class Relation:
    parent: str
    child: str
    column: str
    parent_marker: str
    child_marker: str

    @property
    def key(self):
        return self.child, self.column

    @property
    def label(self):
        return VERBS[self.child][self.column]


@dataclass(frozen=True)
class RelationGroup:
    parent: str
    child: str
    parent_marker: str
    child_marker: str
    roles: tuple[Relation, ...]

    @property
    def id(self):
        return f"relation:{self.child}:" + "+".join(role.column for role in self.roles)

    @property
    def label(self):
        return " /\n".join(role.label for role in self.roles)

    @property
    def detail(self):
        return all(role.key in DETAIL_RELATIONS for role in self.roles)


def group_relations(relations, full):
    groups = {}
    for rel in relations:
        if full or rel.key not in DETAIL_RELATIONS:
            pair = (rel.parent, rel.child, rel.parent_marker, rel.child_marker)
            groups.setdefault(pair, []).append(rel)
    return [RelationGroup(*pair, tuple(roles)) for pair, roles in groups.items()]


def marker(token):
    if token in {"o|", "|o"}:
        token = "optional"
    elif token in {"|{", "}|"}:
        token = "many"
    elif token == "}o":
        token = "o{"
    return ARROWS[token]


def read_schema():
    mermaid = re.search(r"```mermaid\s+erDiagram\n(.*?)```", (ROOT / "ERD_ARCHITECTURE.md").read_text(encoding="utf-8"), re.S)[1]
    documented = {}
    documented_fk = {}
    for table, body in re.findall(r"^    (\w+) \{\n(.*?)^    }", mermaid, re.M | re.S):
        fields = [line.split(maxsplit=2) for line in body.strip().splitlines()]
        documented[table] = [field[1] for field in fields]
        documented_fk[table] = {field[1] for field in fields if len(field) == 3 and "FK" in field[2]}
    relations = [
        Relation(parent, child, column, marker(left), marker(right))
        for parent, left, right, child, column in re.findall(
            r'^    (\w+) (\S{2})--(\S{2}) (\w+) : "(\w+)"$', mermaid, re.M
        )
    ]

    columns = {}
    foreign_keys = {}
    migrations = ROOT / "database" / "migrations"
    for path in sorted(migrations.glob("*create_gigkampus*.php")):
        sql = path.read_text(encoding="utf-8")
        for table, body in re.findall(r"CREATE TABLE (\w+) \(\n(.*?)^[ \t]*\);", sql, re.M | re.S):
            fields = re.findall(r"^[ \t]+([a-z]\w*) ([A-Z][^\n]*),?$", body, re.M)
            columns[table] = {name: definition for name, definition in fields}
            for name, definition in fields:
                match = re.search(r"REFERENCES (\w+) \(([^)]+)\)", definition)
                if match:
                    foreign_keys[table, name] = (match[1], (name,), tuple(match[2].split(", ")))
            read_composite_keys(table, body, foreign_keys)
        for table, constraint in re.findall(r"ALTER TABLE (\w+)\s+ADD CONSTRAINT ([^;]+);", sql):
            read_composite_keys(table, constraint, foreign_keys)

    if set(columns) != set(PLACEMENT) or set(columns) != set(documented):
        raise ValueError("Diagram layout, architecture, and migrations have different business tables")
    if set(foreign_keys) != {rel.key for rel in relations} or set(foreign_keys) != {
        (table, column) for table, verbs in VERBS.items() for column in verbs
    }:
        raise ValueError("Architecture, migrations, and diagram labels have different foreign keys")

    fk_columns = {table: set() for table in columns}
    for rel in relations:
        target, local_columns, target_columns = foreign_keys[rel.key]
        if target != rel.parent or target_columns[0] != "id":
            raise ValueError(f"Unexpected migration target for {rel.key}")
        required = "NOT NULL" in columns[rel.child][rel.column]
        if required != (rel.parent_marker == "ERmandOne"):
            raise ValueError(f"Architecture and migration disagree on optionality for {rel.key}")
        fk_columns[rel.child].update(local_columns)

    attributes = {}
    for table, fields in columns.items():
        if list(fields) != documented[table] or fk_columns[table] != documented_fk[table]:
            raise ValueError(f"Architecture and migrations disagree on columns for {table}")
        if "PRIMARY KEY" not in fields["id"]:
            raise ValueError(f"Unexpected primary key for {table}")
        attributes[table] = [name for name in fields if name == "id" or name not in fk_columns[table]]
    return attributes, relations


def read_composite_keys(table, definition, foreign_keys):
    for local, target, remote in re.findall(r"FOREIGN KEY \(([^)]+)\)\s+REFERENCES (\w+) \(([^)]+)\)", definition):
        local_columns = tuple(local.split(", "))
        foreign_keys[table, local_columns[0]] = (target, local_columns, tuple(remote.split(", ")))


@dataclass(frozen=True)
class Box:
    id: str
    label: str
    x: float
    y: float
    width: int
    height: int

    @property
    def bounds(self):
        return (self.x - self.width / 2, self.y - self.height / 2,
                self.x + self.width / 2, self.y + self.height / 2)


def overlaps(a, b, padding=0):
    return (abs(a.x - b.x) * 2 < a.width + b.width + padding * 2
            and abs(a.y - b.y) * 2 < a.height + b.height + padding * 2)


def line_hits_box(start, end, box, padding=0):
    """Clip a segment against an axis-aligned box using independent X/Y intervals."""
    left, top, right, bottom = box.bounds
    near, far = 0, 1
    for first, last, lower, upper in zip(start, end, (left, top), (right, bottom)):
        lower -= padding
        upper += padding
        if first == last:
            if not lower <= first <= upper:
                return False
            continue
        enter, leave = sorted(((lower - first) / (last - first), (upper - first) / (last - first)))
        near, far = max(near, enter), min(far, leave)
        if near > far:
            return False
    return True


def line_hits_oval(start, end, oval):
    """Use the rendered ellipse, including a small gap round its outline."""
    rx, ry = oval.width / 2 + 2, oval.height / 2 + 2
    start = ((start[0] - oval.x) / rx, (start[1] - oval.y) / ry)
    end = ((end[0] - oval.x) / rx, (end[1] - oval.y) / ry)
    dx, dy = end[0] - start[0], end[1] - start[1]
    fraction = max(0, min(1, -(start[0] * dx + start[1] * dy) / (dx * dx + dy * dy)))
    return (start[0] + fraction * dx) ** 2 + (start[1] + fraction * dy) ** 2 <= 1


@lru_cache(maxsize=None)
def fonts(size, bold=False):
    windows_font = "arialbd.ttf" if bold else "arial.ttf"
    liberation = "LiberationSans-Bold.ttf" if bold else "LiberationSans-Regular.ttf"
    candidates = (
        Path(os.environ.get("WINDIR", "C:/Windows")) / "Fonts" / windows_font,
        Path("/System/Library/Fonts/Supplemental") / ("Arial Bold.ttf" if bold else "Arial.ttf"),
        Path("/usr/share/fonts/truetype/liberation2") / liberation,
        Path("/usr/share/fonts/truetype/liberation") / liberation,
        Path(windows_font), Path(liberation),
    )
    for candidate in candidates:
        try:
            return ImageFont.truetype(str(candidate), size)
        except OSError:
            continue
    raise RuntimeError("Install Arial or Liberation Sans to generate the PNG previews")


def text_width(label, size, bold=False):
    return math.ceil(fonts(size * 4, bold).getlength(label) / 4)


def grid_round(value, interval=GRID):
    return round(value / interval) * interval


def make_entities(full):
    scale = 1.24 if full else 1.13
    return {
        table: Box(f"entity:{table}", table, round(x * scale) * GRID, round(y * scale) * GRID,
                   width * GRID, height * GRID)
        for table, (x, y, width, height, _gates) in PLACEMENT.items()
    }


def side_is_clear(entity, oval, gates):
    left, top, right, bottom = entity.bounds
    x1, y1, x2, y2 = oval.bounds
    # The whole span of a reserved side is kept clear, including nearby corners.
    intersects_strip = {
        "N": y1 < top and x1 < right and x2 > left,
        "S": y2 > bottom and x1 < right and x2 > left,
        "W": x1 < left and y1 < bottom and y2 > top,
        "E": x2 > right and y1 < bottom and y2 > top,
    }
    return not any(intersects_strip[side] for side in gates)


def attribute_slots(entity, gates):
    left, top, right, bottom = entity.bounds
    sides = (("N", (0, -1)), ("S", (0, 1)), ("W", (-1, 0)), ("E", (1, 0)))
    for side, (nx, ny) in sides:
        if side in gates:
            continue
        span = entity.height if nx else entity.width
        for offset in range(-span // 2 + 5, span // 2 - 4, 5):
            x = entity.x + (nx * entity.width / 2 if nx else offset)
            y = entity.y + (ny * entity.height / 2 if ny else offset)
            yield side, (x, y), (nx, ny), abs(offset) / (span / 2)
    for corner, (x, y), start in (("SE", (right, bottom), 0), ("SW", (left, bottom), 90),
                                  ("NW", (left, top), 180), ("NE", (right, top), 270)):
        for angle in range(start + 5, start + 90, 5):
            radians = math.radians(angle)
            yield corner, (x, y), (math.cos(radians), math.sin(radians)), abs(angle - start - 45) / 45


def attribute_at(table, name, width, slot):
    _, start, (nx, ny), _ = slot
    rx, ry = width / 2, 13
    # The shared normal at the box and at the ellipse gives their actual minimum
    # separation, including at the four corners. It also fixes the spoke length.
    end = start[0] + nx * ATTRIBUTE_GAP, start[1] + ny * ATTRIBUTE_GAP
    support = math.hypot(rx * nx, ry * ny)
    oval = Box(f"attribute:{table}:{name}", name, end[0] + rx ** 2 * nx / support,
               end[1] + ry ** 2 * ny / support, width, 2 * ry)
    return oval, (start, end)


def make_attributes(entities, columns, full):
    ovals, rays = {}, {}
    occupied = list(entities.values())
    for table in sorted(entities, key=lambda name: -len(columns[name])):
        entity = entities[table]
        gates = PLACEMENT[table][-1]
        names = [name for name in columns[table] if full or name not in COMPACT_COLUMNS]
        widths = {name: max(60, text_width(name, 9) + 20) for name in names}
        names.sort(key=lambda name: (-widths[name], name != "id"))
        slots = list(attribute_slots(entity, gates))
        counts = Counter()
        ovals[table] = []
        for name in names:
            def rank(slot):
                zone, position, normal, offset = slot
                corner = len(zone) == 2
                capacity = 2 if corner else entity.height / 32 if zone in "WE" else entity.width / 75
                return (counts[zone] / capacity + 0.20 * offset + 0.44 * corner
                        + 0.10 * (normal[1] >= 0) + 0.04 * (normal[0] > 0), position)

            for slot in sorted(slots, key=rank):
                oval, ray = attribute_at(table, name, widths[name], slot)
                if not side_is_clear(entity, oval, gates) or any(overlaps(oval, other, 7) for other in occupied):
                    continue
                if any(line_hits_oval(*other, oval) for other in rays.values()):
                    continue
                if any(line_hits_box(*ray, other, 2) for other in occupied if other != entity):
                    continue
                ovals[table].append(oval)
                occupied.append(oval)
                rays[oval.id] = ray
                counts[slot[0]] += 1
                break
            else:
                raise ValueError(f"No attribute slot for {'Full' if full else 'Compact'} {table}.{name}")
    return ovals, rays


def grid_points(left, top, right, bottom):
    for y in range(math.ceil(top / GRID) * GRID, math.floor(bottom / GRID) * GRID + 1, GRID):
        for x in range(math.ceil(left / GRID) * GRID, math.floor(right / GRID) * GRID + 1, GRID):
            yield x, y


def next_point(point, direction, steps=1):
    dx, dy = STEP[direction]
    return point[0] + dx * GRID * steps, point[1] + dy * GRID * steps


def path_direction(start, end):
    dx, dy = end[0] - start[0], end[1] - start[1]
    return STEP.index(((dx > 0) - (dx < 0), (dy > 0) - (dy < 0)))


def axis(direction):
    return 5 if direction % 2 == 0 else 10


@dataclass(frozen=True)
class Port:
    anchor: tuple
    direction: int
    steps: int

    @property
    def cells(self):
        return [next_point(self.anchor, self.direction, step) for step in range(self.steps + 1)]


@dataclass(frozen=True)
class Connection:
    source: Box
    target: Box
    points: tuple
    marker: str = ""


class Router:
    def __init__(self, boxes, rays):
        self.boxes = list(boxes)
        self.fans = set()
        self.blocked = set()
        self.ports = {}
        self.track = {}
        self.crossings = {}
        self.left = math.floor(min(box.bounds[0] for box in boxes) / GRID) * GRID - 96
        self.top = math.floor(min(box.bounds[1] for box in boxes) / GRID) * GRID - 96
        self.right = math.ceil(max(box.bounds[2] for box in boxes) / GRID) * GRID + 96
        self.bottom = math.ceil(max(box.bounds[3] for box in boxes) / GRID) * GRID + 96
        for box in boxes:
            self.blocked.update(self.footprint(box, 4))
        for first, last in rays:
            distance = math.dist(first, last)
            dx, dy = last[0] - first[0], last[1] - first[1]
            clearance = GRID / 2 + 1
            for x, y in grid_points(min(first[0], last[0]) - clearance, min(first[1], last[1]) - clearance,
                                    max(first[0], last[0]) + clearance, max(first[1], last[1]) + clearance):
                fraction = max(0, min(1, ((x - first[0]) * dx + (y - first[1]) * dy) / distance ** 2))
                if math.hypot(x - first[0] - fraction * dx, y - first[1] - fraction * dy) <= clearance:
                    self.fans.add((x, y))
        self.blocked.update(self.fans)

    def footprint(self, box, padding):
        left, top, right, bottom = box.bounds
        return grid_points(left - padding, top - padding, right + padding, bottom + padding)

    def available_ports(self, box):
        left, top, right, bottom = map(int, box.bounds)
        if box.id.startswith("relation:"):
            ports = [Port(anchor, index, 1) for index, anchor in enumerate(
                ((right, box.y), (box.x, bottom), (left, box.y), (box.x, top)))]
        else:
            ports = [Port((x, y), index, 2) for index, y in ((1, bottom), (3, top))
                     for x in range(left + GRID, right, GRID)]
            ports += [Port((x, y), index, 2) for index, x in ((0, right), (2, left))
                      for y in range(top + GRID, bottom, GRID)]
        selected = self.ports.get(box.id, [])
        return [port for port in ports if not any(math.dist(port.anchor, other) < 12 for other in selected)
                and port.anchor not in self.fans and port.anchor not in self.track
                and not any(point in self.blocked or point in self.track for point in port.cells[1:])]

    def search(self, source, target):
        starts = self.available_ports(source)
        ends = self.available_ports(target)
        if not starts or not ends:
            return None
        target_ports = {port.cells[-1]: port for port in ends}
        left, top = map(min, zip(*target_ports))
        right, bottom = map(max, zip(*target_ports))

        def heuristic(point):
            x, y = point
            return (max(left - x, x - right, 0) + max(top - y, y - bottom, 0)) / GRID

        queue = []
        costs = {}
        previous = {}
        initial = {}
        for port in starts:
            state = port.cells[-1], port.direction
            initial[state] = port
            costs[state] = 0
            heapq.heappush(queue, (heuristic(state[0]) * 13, 0, state))

        while queue:
            _, cost, state = heapq.heappop(queue)
            if cost != costs[state]:
                continue
            point, arrival = state
            destination = target_ports.get(point)
            if destination and arrival != destination.direction:
                route = [point]
                while state in previous:
                    state = previous[state]
                    route.append(state[0])
                return initial[state].cells[:-1] + route[::-1] + destination.cells[-2::-1]

            existing = self.track.get(point, 0)
            for direction in (arrival, (arrival + 1) % 4, (arrival - 1) % 4):
                if existing and direction != arrival:
                    continue
                x, y = neighbor = next_point(point, direction)
                if neighbor in self.blocked or not (self.left <= x <= self.right and self.top <= y <= self.bottom):
                    continue
                occupied = self.track.get(neighbor, 0)
                if occupied and occupied != (axis(direction) ^ 15):
                    continue
                new_cost = cost + 10 + (25 if direction != arrival else 0) + (72 if occupied else 0)
                for side in ((direction + 1) % 4, (direction - 1) % 4):
                    if self.track.get(next_point(neighbor, side), 0) & axis(direction):
                        new_cost += 6
                    if self.track.get(next_point(neighbor, side, 2), 0) & axis(direction):
                        new_cost += 2
                successor = neighbor, direction
                if new_cost < costs.get(successor, math.inf):
                    costs[successor] = new_cost
                    previous[successor] = state
                    heapq.heappush(queue, (new_cost + heuristic(neighbor) * 13, new_cost, successor))
        return None

    def diamond_clear(self, diamond):
        return (not any(overlaps(diamond, box, 9) for box in self.boxes) and
                not any(point in self.fans or point in self.track for point in self.footprint(diamond, 6)))

    def diamond_on_route(self, diamond, route):
        directions = [path_direction(a, b) for a, b in zip(route, route[1:])]
        candidates = []
        start = 0
        for direction, run in groupby(directions):
            length = sum(1 for _ in run)
            half = (diamond.height if direction % 2 else diamond.width) // (2 * GRID)
            # Keep each tip clear of a bend and of the markers on entity edges.
            for index in range(start + half + 1, start + length - half):
                if index - half < 2 or index + half > len(route) - 3:
                    continue
                candidates.append((abs(index - (len(route) - 1) / 2) + (2 if direction % 2 else 0),
                                   index, half, start, start + length))
            start += length

        for _, index, half, first, last in sorted(candidates):
            placed = Box(diamond.id, diamond.label, *route[index], diamond.width, diamond.height)
            if not self.diamond_clear(placed):
                continue
            box_left, box_top, box_right, box_bottom = placed.bounds
            if any(box_left - 4 <= x <= box_right + 4 and box_top - 4 <= y <= box_bottom + 4
                   for x, y in route[:first] + route[last + 1:]):
                continue
            return placed, route[:index - half + 1], route[index + half:][::-1]
        return None

    def reserve(self, connection, route):
        self.ports.setdefault(connection.source.id, []).append(route[0])
        self.ports.setdefault(connection.target.id, []).append(route[-1])
        self.crossings[connection.source.id, connection.target.id] = [point for point in route if point in self.track]
        for first, last in zip(route, route[1:]):
            direction = path_direction(first, last)
            self.track[first] = self.track.get(first, 0) | (1 << direction)
            self.track[last] = self.track.get(last, 0) | (1 << ((direction + 2) % 4))
        return connection

    def add_relation(self, rel, first, second):
        label_width = max(text_width(label, 9, True) for label in rel.label.splitlines())
        width = max(2 * GRID, math.ceil((label_width * 1.35 + 16) / (2 * GRID)) * (2 * GRID))
        diamond = Box(rel.id, rel.label, 0, 0, width, 4 * GRID)
        route = self.search(first, second)
        result = self.diamond_on_route(diamond, route) if route else None
        if result is None:
            result = self.route_via_diamond(diamond, first, second, route)
        if result is None:
            raise ValueError(f"No orthogonal route for {rel.parent} — {rel.id}: "
                             f"{len(self.available_ports(first))}/{len(self.available_ports(second))} free ports")
        placed, incoming, outgoing = result
        self.boxes.append(placed)
        self.blocked.update(self.footprint(placed, 3))
        edges = []
        for entity, points, cardinality in ((first, incoming, rel.parent_marker), (second, outgoing, rel.child_marker)):
            edge = Connection(entity, placed, tuple(simplify(points)), cardinality)
            edges.append(self.reserve(edge, points))
        return placed, edges

    def route_via_diamond(self, diamond, first, second, direct):
        if not self.available_ports(first) or not self.available_ports(second):
            return None
        cx, cy = direct[len(direct) // 2] if direct else (grid_round((first.x + second.x) / 2),
                                                        grid_round((first.y + second.y) / 2))
        # A narrow connector can have room for a diamond beside it, even when no
        # straight segment in the original path is long enough to contain one.
        centers = direct[3:-3:3] if direct and len(direct) > 9 else [(cx, cy)]
        scores = {}
        for center in centers:
            x, y = center
            for point in grid_points(x - 144, y - 144, x + 144, y + 144):
                score = math.dist(point, center) * 2 + math.dist(point, (cx, cy))
                scores[point] = min(score, scores.get(point, math.inf))
        positions = sorted(scores, key=scores.get)
        attempted = []
        for x, y in positions:
            if any(math.dist((x, y), previous) < 44 for previous in attempted):
                continue
            placed = Box(diamond.id, diamond.label, x, y, diamond.width, diamond.height)
            if not self.diamond_clear(placed):
                continue
            blocked = set(self.footprint(placed, 3))
            if blocked & self.blocked:
                continue
            self.blocked.update(blocked)
            incoming = self.search(first, placed)
            if incoming:
                # The second half cannot reuse the first half's tracks or diamond tip.
                track_before = self.track.copy()
                marker_edge = Connection(first, placed, ())
                self.reserve(marker_edge, incoming)
                outgoing = self.search(second, placed)
                self.track = track_before
                self.ports[first.id].pop()
                self.ports[placed.id].pop()
            self.blocked.difference_update(blocked)
            if incoming and outgoing:
                return placed, incoming, outgoing
            attempted.append((x, y))
            if len(attempted) >= 28:
                break
        return None


def simplify(points):
    yield points[0]
    for first, middle, last in zip(points, points[1:], points[2:]):
        if path_direction(first, middle) != path_direction(middle, last):
            yield middle
    yield points[-1]


def make_relations(entities, ovals, rays, relations, full):
    router = Router(list(entities.values()) + [box for group in ovals.values() for box in group], rays.values())
    diamonds, edges = [], []
    selected = group_relations(relations, full)

    def priority(rel):
        parent, child = entities[rel.parent], entities[rel.child]
        distance = math.dist((parent.x, parent.y), (child.x, child.y))
        return rel.detail, distance

    selected.sort(key=priority)
    for rel in selected:
        diamond, halves = router.add_relation(rel, entities[rel.parent], entities[rel.child])
        diamonds.append(diamond)
        edges.extend(halves)
    return diamonds, edges, router.crossings


def attribute_gaps(entities, ovals, rays):
    gaps = []
    for table, group in ovals.items():
        entity = entities[table]
        for oval in group:
            if not side_is_clear(entity, oval, PLACEMENT[table][-1]):
                raise ValueError(f"Oval encroaches on a reserved side: {oval.id}")
            start, end = rays[oval.id]
            length = math.dist(start, end)
            nx, ny = ((last - first) / length for first, last in zip(start, end))
            box_support = (entity.x * nx + entity.y * ny
                           + abs(nx) * entity.width / 2 + abs(ny) * entity.height / 2)
            oval_support = oval.x * nx + oval.y * ny - math.hypot(nx * oval.width / 2, ny * oval.height / 2)
            gap = oval_support - box_support
            if not 42 <= gap <= 55 or not math.isclose(gap, length, abs_tol=1e-8):
                raise ValueError(f"Unacceptable edge-to-edge gap for {oval.id}: {gap:.2f} px")
            gaps.append(gap)
    return min(gaps), max(gaps)


def perpendicular_crossing(a, b, c, d):
    first_vertical, second_vertical = a[0] == b[0], c[0] == d[0]
    if first_vertical == second_vertical:
        return None
    if not first_vertical:
        a, b, c, d = c, d, a, b
    if min(a[1], b[1]) < c[1] < max(a[1], b[1]) and min(c[0], d[0]) < a[0] < max(c[0], d[0]):
        return a[0], c[1]
    return None


def validate_layout(boxes, edges):
    collisions = [(a.id, b.id) for a, b in combinations(boxes, 2) if overlaps(a, b)]
    if collisions:
        raise ValueError(f"{len(collisions)} bounding-box overlaps: {collisions}")
    tracks = set()
    segments = []
    for edge in edges:
        for start, end in zip(edge.points, edge.points[1:]):
            if any(value % GRID for value in (*start, *end)):
                raise ValueError(f"Off-grid relationship lane: {edge.target.id}")
            if start[0] != end[0] and start[1] != end[1]:
                raise ValueError(f"Non-orthogonal path: {edge.source.id} to {edge.target.id}")
            for obstacle in boxes:
                if obstacle not in (edge.source, edge.target) and line_hits_box(start, end, obstacle):
                    raise ValueError(f"Path to {edge.target.id} intersects {obstacle.id}: {start} to {end}")
            step = path_direction(start, end)
            for index in range(round(math.dist(start, end) / GRID)):
                segment = tuple(sorted((next_point(start, step, index), next_point(start, step, index + 1))))
                if segment in tracks:
                    raise ValueError(f"Overlapping orthogonal lines near {edge.target.id}: {segment}")
                tracks.add(segment)
            segments.append((edge, start, end))
    return sum(first != second and perpendicular_crossing(a, b, c, d) is not None
               for (first, a, b), (second, c, d) in combinations(segments, 2))


def page_geometry(boxes, edges):
    edge_points = [point for edge in edges for point in edge.points]
    left = math.floor(min([box.bounds[0] for box in boxes] + [point[0] for point in edge_points]))
    top = math.floor(min([box.bounds[1] for box in boxes] + [point[1] for point in edge_points]))
    right = math.ceil(max([box.bounds[2] for box in boxes] + [point[0] for point in edge_points]))
    bottom = math.ceil(max([box.bounds[3] for box in boxes] + [point[1] for point in edge_points]))
    return (MARGIN - left, MARGIN - top), (right - left + 2 * MARGIN, bottom - top + 2 * MARGIN)


def connection_anchor(prefix, point, box):
    left, top, _, _ = box.bounds
    x, y = (point[0] - left) / box.width, (point[1] - top) / box.height
    return f"{prefix}X={x:.8g};{prefix}Y={y:.8g};{prefix}Dx=0;{prefix}Dy=0;{prefix}Perimeter=0;"


def drawio_tree(name, entities, ovals, rays, diamonds, edges, offset, size):
    mxfile = ET.Element("mxfile", host="app.diagrams.net", compressed="false")
    sheet = ET.SubElement(mxfile, "diagram", id=f"gigkampus-{name.lower()}", name=f"GigKampus {name.replace('_', ' ')}")
    graph = ET.SubElement(sheet, "mxGraphModel", {
        "dx": "1200", "dy": "800", "grid": "0", "gridSize": str(GRID), "guides": "1", "tooltips": "1",
        "connect": "1", "arrows": "1", "fold": "1", "page": "0", "pageScale": "1",
        "pageWidth": str(size[0]), "pageHeight": str(size[1]), "background": "#ffffff", "math": "0", "shadow": "0",
    })
    root = ET.SubElement(graph, "root")
    ET.SubElement(root, "mxCell", id="0")
    ET.SubElement(root, "mxCell", id="1", parent="0")
    ox, oy = offset

    for table, attributes in ovals.items():
        entity = entities[table]
        for oval in attributes:
            start, end = rays[oval.id]
            style = ATTRIBUTE_EDGE + connection_anchor("exit", start, entity) + connection_anchor("entry", end, oval)
            xml_edge(root, f"edge:{oval.id}", entity, oval, style)

    for edge in edges:
        style = RELATION_EDGE + f"startArrow={edge.marker};"
        style += connection_anchor("exit", edge.points[0], edge.source)
        style += connection_anchor("entry", edge.points[-1], edge.target)
        waypoints = edge.points[1:-1] or ((sum(x for x, _ in edge.points) // 2,
                                           sum(y for _, y in edge.points) // 2),)
        points = [(x + ox, y + oy) for x, y in waypoints]
        xml_edge(root, f"edge:{edge.target.id}:{edge.source.label}", edge.source, edge.target, style, points)

    for boxes, palette, shape in ((entities.values(), ENTITY, "rounded=0"),
                                   ([oval for group in ovals.values() for oval in group], ATTRIBUTE, "ellipse"),
                                   (diamonds, DIAMOND, "rhombus")):
        for box in boxes:
            primary = shape == "ellipse" and box.label == "id"
            style = (PRIMARY if primary else palette).style(shape)
            label = "<u>id</u>" if primary else box.label.replace("\n", "<br>")
            cell = ET.SubElement(root, "mxCell", id=box.id, value=label, style=style, vertex="1", parent="1")
            left, top, _, _ = box.bounds
            ET.SubElement(cell, "mxGeometry", {"x": f"{left + ox:.10g}", "y": f"{top + oy:.10g}",
                                               "width": str(box.width), "height": str(box.height), "as": "geometry"})
    ET.indent(mxfile)
    validate_xml(ET.fromstring(ET.tostring(mxfile)))
    return ET.ElementTree(mxfile)


def xml_edge(root, cell_id, source, target, style, points=()):
    cell = ET.SubElement(root, "mxCell", id=cell_id, value="", style=style,
                         edge="1", parent="1", source=source.id, target=target.id)
    geometry = ET.SubElement(cell, "mxGeometry", {"relative": "1", "as": "geometry"})
    if points:
        hints = ET.SubElement(geometry, "Array", {"as": "points"})
        for x, y in points:
            ET.SubElement(hints, "mxPoint", x=str(x), y=str(y))


def validate_xml(mxfile):
    cells = list(mxfile.find("./diagram/mxGraphModel/root"))
    ids = {cell.get("id") for cell in cells}
    if len(cells) != len(ids) or None in ids or [cell.get("id") for cell in cells[:2]] != ["0", "1"]:
        raise ValueError("Invalid mxGraph root or duplicate cell ID")
    vertices = {cell.get("id") for cell in cells if cell.get("vertex") == "1"}
    for cell in cells[2:]:
        if cell.get("parent") != "1" or cell.find("mxGeometry[@as='geometry']") is None:
            raise ValueError(f"Missing parent or geometry: {cell.get('id')}")
        if cell.get("edge") == "1" and not {cell.get("source"), cell.get("target")} <= vertices:
            raise ValueError(f"Invalid source or target: {cell.get('id')}")
        if cell.get("id").startswith("edge:relation:") and cell.find("mxGeometry/Array[@as='points']/mxPoint") is None:
            raise ValueError(f"Missing relationship waypoints: {cell.get('id')}")


def render_preview(path, entities, ovals, rays, diamonds, edges, crossings, offset, size):
    # The preview uses the same coordinates and waypoints as the editable XML.
    zoom = 3
    ox, oy = offset
    image = Image.new("RGB", (size[0] * zoom, size[1] * zoom), "white")
    draw = ImageDraw.Draw(image)

    def xy(point):
        return round((point[0] + ox) * zoom), round((point[1] + oy) * zoom)

    def line(points, color, width=1):
        draw.line([xy(point) for point in points], fill=color, width=round(width * zoom))

    def outline(box):
        left, top, right, bottom = box.bounds
        return [xy((left, top)), xy((right, bottom))]

    def label(box, palette, underline=False):
        face = fonts(palette.size * zoom, palette.bold)
        spacing = 2 * zoom
        box_left, box_top, box_right, box_bottom = draw.multiline_textbbox(
            (0, 0), box.label, font=face, spacing=spacing, align="center")
        x, y = xy((box.x, box.y))
        position = x - (box_left + box_right) / 2, y - (box_top + box_bottom) / 2
        draw.multiline_text(position, box.label, font=face, fill=palette.text, spacing=spacing, align="center")
        if underline:
            draw.line((x - (box_right - box_left) / 2, y + (box_bottom - box_top) / 2 + zoom,
                       x + (box_right - box_left) / 2, y + (box_bottom - box_top) / 2 + zoom),
                      fill=palette.text, width=zoom)

    def draw_connector(edge):
        jumps = crossings[edge.source.id, edge.target.id]
        radius = 6
        for start, end in zip(edge.points, edge.points[1:]):
            dx, dy = STEP[path_direction(start, end)]
            vertical = dx == 0
            index = int(vertical)
            pause = [point for point in jumps if point[1 - index] == start[1 - index]
                     and min(start[index], end[index]) < point[index] < max(start[index], end[index])]
            cursor = start
            for x, y in sorted(pause, key=lambda point: abs(point[index] - start[index])):
                arc = []
                for step in range(13):
                    angle = math.pi * step / 12
                    forward = -radius * math.cos(angle)
                    outward = radius * math.sin(angle)
                    arc.append((x + dx * forward + (outward if vertical else 0),
                                y + dy * forward - (0 if vertical else outward)))
                line([cursor] + arc, RELATION_COLOR, RELATION_WIDTH)
                cursor = arc[-1]
            line((cursor, end), RELATION_COLOR, RELATION_WIDTH)

    def draw_marker(edge):
        x, y = edge.points[0]
        dx, dy = STEP[path_direction(*edge.points[:2])]

        def point(along, across):
            return x + along * dx - across * dy, y + along * dy + across * dx

        def bar(along):
            line((point(along, -4), point(along, 4)), RELATION_COLOR, RELATION_WIDTH)

        def circle(along):
            cx, cy = point(along, 0)
            draw.ellipse([xy((cx - 3, cy - 3)), xy((cx + 3, cy + 3))],
                         outline=RELATION_COLOR, fill="white", width=round(RELATION_WIDTH * zoom))

        if edge.marker in {"ERzeroToMany", "ERoneToMany"}:
            line((point(1, -5), point(9, 0), point(1, 5)), RELATION_COLOR, RELATION_WIDTH)
        else:
            bar(5)
        if edge.marker in {"ERzeroToOne", "ERzeroToMany"}:
            circle(14)
        else:
            bar(10 if edge.marker == "ERmandOne" else 14)

    for ray in rays.values():
        line(ray, SPOKE_COLOR, SPOKE_WIDTH)
    for edge in edges:
        draw_connector(edge)
        draw_marker(edge)

    for box in entities.values():
        draw.rectangle(outline(box), outline=ENTITY.stroke, fill=ENTITY.fill, width=round(ENTITY.width * zoom))
        label(box, ENTITY)
    for group in ovals.values():
        for box in group:
            primary = box.label == "id"
            palette = PRIMARY if primary else ATTRIBUTE
            draw.ellipse(outline(box), outline=palette.stroke, fill=palette.fill, width=round(palette.width * zoom))
            label(box, palette, underline=primary)
    for box in diamonds:
        left, top, right, bottom = box.bounds
        points = [(left, box.y), (box.x, top), (right, box.y), (box.x, bottom)]
        draw.polygon([xy(point) for point in points], fill=DIAMOND.fill)
        line(points + points[:1], DIAMOND.stroke, DIAMOND.width)
        label(box, DIAMOND)

    image.resize((2 * size[0], 2 * size[1]), Image.Resampling.LANCZOS).save(path, optimize=True)


def main():
    columns, relations = read_schema()
    outputs = []
    for full, name in ((False, "Compact"), (True, "Full_NonFK")):
        entities = make_entities(full)
        ovals, rays = make_attributes(entities, columns, full)
        diamonds, edges, crossings = make_relations(entities, ovals, rays, relations, full)
        boxes = list(entities.values()) + [oval for group in ovals.values() for oval in group] + diamonds
        crossing_count = validate_layout(boxes, edges)
        min_gap, max_gap = attribute_gaps(entities, ovals, rays)
        centers = [(box.x, box.y) for box in entities.values()]
        spacing = median(min(math.dist(point, other) for other in centers if point != other) for point in centers)
        offset, size = page_geometry(boxes, edges)
        tree = drawio_tree(name, entities, ovals, rays, diamonds, edges, offset, size)
        stem = ROOT / "docs" / f"GigKampus_ERD_{name}"
        outputs.append((stem, tree, (entities, ovals, rays, diamonds, edges, crossings, offset, size)))
        print(f"{name}: {len(entities)} entities, {sum(map(len, ovals.values()))} attributes, "
              f"{len(diamonds)} diamonds, attribute gap {min_gap:.2f}-{max_gap:.2f} px, "
              f"{crossing_count} crossings, 0 shape overlaps, 0 collinear overlaps, 0 oval cuts, "
              f"{GRID} px lane grid, nearest-entity median {spacing:.1f} px, canvas {size[0]} x {size[1]}", flush=True)
    for stem, tree, preview in outputs:
        tree.write(stem.with_suffix(".drawio"), encoding="utf-8", xml_declaration=True)
        render_preview(stem.with_suffix(".png"), *preview)


if __name__ == "__main__":
    main()
