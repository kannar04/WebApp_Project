"""Generate the editable Home 2 Home draw.io ERD from sqlerd graph JSON.

The bundled draw.io SQL importer supplies the source-backed table/relationship
model. This script adds a readable two-page layout and fixes three comment
fragments that the importer's deliberately small SQL parser treats as columns.
"""

from __future__ import annotations

import json
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent
GRAPH_PATH = ROOT / "database-erd.graph.json"
OUTPUT_PATH = ROOT / "database-erd.drawio"

GROUPS = {
    "Identity & Access": ["users", "user_roles", "sessions", "password_reset_tokens"],
    "Catalogue": [
        "property_types", "cancellation_policies", "listings", "listing_photos",
        "amenities", "listing_amenities",
    ],
    "Booking": [
        "listing_availability", "bookings", "booking_nights", "booking_events",
        "booking_cancellations",
    ],
    "Community": ["reviews", "wishlist_items", "reports"],
    "Operations": [
        "notifications", "admin_audit_logs", "listing_moderation_events",
        "account_status_events",
    ],
}

PALETTE = {
    "Identity & Access": ("#f3e8ff", "#8b5cf6"),
    "Catalogue": ("#dcfce7", "#22c55e"),
    "Booking": ("#dbeafe", "#3b82f6"),
    "Community": ("#fef3c7", "#f59e0b"),
    "Operations": ("#ffedd5", "#f97316"),
}

TABLE_GROUP = {table: group for group, tables in GROUPS.items() for table in tables}

OPTIONAL_FKS = {
    ("listings", "reviewed_by"),
    ("booking_events", "actor_id"),
    ("reports", "listing_id"),
    ("reports", "booking_id"),
    ("reports", "assigned_admin_id"),
    ("notifications", "booking_id"),
    ("notifications", "booking_event_id"),
}

UNIQUE_FKS = {
    ("booking_cancellations", "booking_id"),
    ("reviews", "booking_id"),
}

OVERVIEW_POSITIONS = {
    "user_roles": (40, 90),
    "sessions": (40, 230),
    "password_reset_tokens": (40, 370),
    "account_status_events": (40, 510),
    "admin_audit_logs": (40, 650),
    "users": (420, 370),
    "property_types": (770, 80),
    "cancellation_policies": (1040, 80),
    "amenities": (1310, 80),
    "listing_amenities": (1310, 220),
    "listings": (870, 370),
    "listing_photos": (740, 780),
    "listing_availability": (1000, 780),
    "listing_moderation_events": (1260, 780),
    "wishlist_items": (1580, 80),
    "bookings": (1440, 430),
    "booking_nights": (1510, 780),
    "booking_events": (1760, 380),
    "booking_cancellations": (1760, 560),
    "reviews": (1760, 740),
    "reports": (2020, 180),
    "notifications": (2020, 460),
}


def add_cell(parent, cell_id, value="", style="", *, vertex=False, edge=False,
             cell_parent="1", source=None, target=None, geometry=None, attrs=None):
    attributes = {"id": cell_id, "value": value, "style": style, "parent": cell_parent}
    if vertex:
        attributes["vertex"] = "1"
    if edge:
        attributes["edge"] = "1"
    if source:
        attributes["source"] = source
    if target:
        attributes["target"] = target
    if attrs:
        attributes.update(attrs)
    cell = ET.SubElement(parent, "mxCell", attributes)
    if geometry is not None:
        geometry = dict(geometry)
        points = geometry.pop("points", None)
        geom_attrs = {key: str(value) for key, value in geometry.items()}
        geom = ET.SubElement(cell, "mxGeometry", geom_attrs)
        if points:
            point_array = ET.SubElement(geom, "Array", {"as": "points"})
            for x, y in points:
                ET.SubElement(point_array, "mxPoint", {"x": str(x), "y": str(y)})
    return cell


def new_page(mxfile, page_id, name, width, height):
    diagram = ET.SubElement(mxfile, "diagram", {"id": page_id, "name": name})
    model = ET.SubElement(diagram, "mxGraphModel", {
        "dx": "1600", "dy": "1000", "grid": "1", "gridSize": "10",
        "guides": "1", "tooltips": "1", "connect": "1", "arrows": "1",
        "fold": "1", "page": "1", "pageScale": "1",
        "pageWidth": str(width), "pageHeight": str(height), "math": "0", "shadow": "0",
        "background": "#f8fafc",
    })
    root = ET.SubElement(model, "root")
    ET.SubElement(root, "mxCell", {"id": "0"})
    ET.SubElement(root, "mxCell", {"id": "1", "parent": "0"})
    return root


def clean_fields(node):
    lines = node["label"].splitlines()
    table = lines[0]
    fields = []
    for line in lines[1:]:
        if line in {": Asia/Ho_Chi_Minh'", ": VND'", "host: or"}:
            continue
        left, field_type = line.rsplit(": ", 1)
        marker = ""
        name = left
        if left.startswith("PK "):
            marker, name = "PK", left[3:]
        elif left.startswith("FK "):
            marker, name = "FK", left[3:]
        fields.append([marker, name, field_type])
    return table, fields


def normalize_fields(nodes, edges):
    normalized = {}
    foreign_fields = {(edge["source"], edge["label"]) for edge in edges}
    for table, node in nodes.items():
        _, fields = clean_fields(node)
        for field in fields:
            marker, name, _ = field
            if (table, name) in foreign_fields and "FK" not in marker:
                field[0] = "PK, FK" if marker == "PK" else "FK"
        normalized[table] = fields
    return normalized


def edge_style(table, field, *, detailed=False):
    source_cardinality = "ERzeroToOne" if (table, field) in UNIQUE_FKS else "ERmany"
    target_cardinality = "ERzeroToOne" if (table, field) in OPTIONAL_FKS else "ERone"
    stroke = PALETTE[TABLE_GROUP[table]][1]
    return (
        "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;"
        f"html=1;strokeColor={stroke};strokeWidth=1.35;fontSize=9;textRotation=0;"
        "fontColor=#475569;labelBackgroundColor=#ffffff;dashed=0;"
        f"startArrow={source_cardinality};startFill=0;"
        f"endArrow={target_cardinality};endFill=0;"
        + ("opacity=78;" if detailed else "")
    )


def add_title(root, page, subtitle, width):
    add_cell(
        root, f"{page}-title", "<b>Home 2 Home Database ERD</b>",
        "text;html=1;horizontal=1;rotation=0;textRotation=0;align=left;verticalAlign=middle;fontSize=28;fontColor=#0f172a;",
        vertex=True, geometry={"x": 50, "y": 24, "width": width - 100, "height": 38, "as": "geometry"},
    )
    add_cell(
        root, f"{page}-subtitle", subtitle,
        "text;html=1;horizontal=1;rotation=0;textRotation=0;align=left;verticalAlign=middle;fontSize=12;fontColor=#64748b;",
        vertex=True, geometry={"x": 50, "y": 66, "width": width - 100, "height": 20, "as": "geometry"},
    )


def build_overview(mxfile, nodes, edges):
    root = new_page(mxfile, "overview", "Relationship Overview", 2320, 980)
    add_title(root, "overview", "22 tables · 33 foreign keys · crow's-foot notation", 2320)
    for table in nodes:
        x, y = OVERVIEW_POSITIONS[table]
        group = TABLE_GROUP[table]
        fill, stroke = PALETTE[group]
        add_cell(
            root, f"overview-{table}", f"<b>{table}</b><br><font style='font-size:10px'>{group}</font>",
            "rounded=1;arcSize=10;whiteSpace=wrap;html=1;align=center;verticalAlign=middle;"
            f"fillColor={fill};strokeColor={stroke};fontColor=#1f2937;fontSize=13;shadow=0;",
            vertex=True,
            geometry={"x": x, "y": y, "width": 220, "height": 72, "as": "geometry"},
            attrs={"data-model-id": table, "data-kind": "table", "data-domain": group,
                   "data-provenance": "database-schema.sql"},
        )
    for index, edge in enumerate(edges):
        table, field, target = edge["source"], edge["label"], edge["target"]
        add_cell(
            root, f"overview-edge-{index}", field, edge_style(table, field), edge=True,
            source=f"overview-{table}", target=f"overview-{target}",
            geometry={"relative": "1", "as": "geometry"},
            attrs={"data-model-id": f"{table}.{field}-{target}.id", "data-kind": "foreign-key"},
        )
    legend_x = 390
    for index, (group, (fill, stroke)) in enumerate(PALETTE.items()):
        add_cell(
            root, f"overview-legend-{index}", group,
            "rounded=1;whiteSpace=wrap;html=1;align=center;verticalAlign=middle;fontSize=10;"
            f"fillColor={fill};strokeColor={stroke};fontColor=#374151;",
            vertex=True,
            geometry={"x": legend_x + index * 150, "y": 890, "width": 130, "height": 30, "as": "geometry"},
        )


def full_table_height(fields):
    visible_rows = min(4, len(fields))
    overflow_row = 1 if len(fields) > 4 else 0
    return 34 + (visible_rows + overflow_row) * 24


def build_full_schema(mxfile, nodes, edges, fields_by_table):
    # Wide inter-domain corridors leave room for explicit relationship routes.
    # The top 300 px are reserved for long cross-domain links.
    width = 2540
    column_x = dict(zip(GROUPS, [50, 550, 1050, 1550, 2050]))
    table_positions = {}
    max_bottom = 0
    for group, tables in GROUPS.items():
        y = 440
        for table in tables:
            height = full_table_height(fields_by_table[table])
            table_positions[table] = (column_x[group], y, 330, height)
            y += height + 70
        max_bottom = max(max_bottom, y)
    root = new_page(mxfile, "full-schema", "Full Schema", width, max_bottom + 80)
    add_title(
        root, "full",
        "Up to 4 fields per table · \"...\" indicates additional fields · PK/FK relationships preserved",
        width,
    )

    for group, x in column_x.items():
        fill, stroke = PALETTE[group]
        add_cell(
            root, f"domain-{group.lower().replace(' ', '-').replace('&', 'and')}", group,
            "rounded=1;arcSize=14;whiteSpace=wrap;html=1;horizontal=1;rotation=0;textRotation=0;"
            "align=left;verticalAlign=middle;fontStyle=1;"
            f"fillColor={fill};strokeColor={stroke};strokeWidth=1.5;fontColor={stroke};"
            "fontSize=13;spacingLeft=12;shadow=0;",
            vertex=True,
            geometry={"x": x, "y": 390, "width": 330, "height": 34, "as": "geometry"},
        )

    row_ids = {}
    row_centers = {}
    for table, fields in fields_by_table.items():
        x, y, table_width, table_height = table_positions[table]
        group = TABLE_GROUP[table]
        fill, stroke = PALETTE[group]
        add_cell(
            root, f"table-{table}", table,
            "shape=table;rounded=1;arcSize=12;horizontal=1;rotation=0;textRotation=0;"
            "startSize=34;container=1;"
            "collapsible=1;childLayout=tableLayout;fixedRows=1;"
            "rowLines=0;fontStyle=1;whiteSpace=wrap;html=1;align=left;verticalAlign=middle;"
            f"fillColor={fill};strokeColor={stroke};strokeWidth=1.5;fontColor=#0f172a;"
            "fontSize=13;spacingLeft=10;shadow=1;",
            vertex=True,
            geometry={"x": x, "y": y, "width": table_width, "height": table_height, "as": "geometry"},
            attrs={"data-model-id": table, "data-kind": "table", "data-domain": group,
                   "data-provenance": "database-schema.sql"},
        )
        visible_fields = fields[:4]
        for row_index, (marker, name, field_type) in enumerate(visible_fields):
            row_id = f"row-{table}-{name}"
            row_ids[(table, name)] = row_id
            key_color = "#9a6700" if "PK" in marker else "#6650a4" if "FK" in marker else "#6b7280"
            marker_text = marker or ""
            prefix = f"<font color='{key_color}'><b>{marker_text}</b></font>&nbsp;&nbsp;" if marker_text else ""
            value = f"{prefix}{name} <font color='#64748b'>: {field_type}</font>"
            row_fill = "#ffffff" if row_index % 2 == 0 else "#f8fafc"
            add_cell(
                root, row_id, value,
                "shape=tableRow;horizontal=1;rotation=0;textRotation=0;startSize=0;swimlaneHead=0;swimlaneBody=0;"
                f"fillColor={row_fill};collapsible=0;dropTarget=0;points=[[0,0.5],[1,0.5]];"
                "portConstraint=eastwest;whiteSpace=wrap;html=1;align=left;verticalAlign=middle;"
                "fontSize=10;fontFamily=Consolas;fontColor=#334155;spacingLeft=8;spacingRight=7;",
                vertex=True, cell_parent=f"table-{table}",
                geometry={"y": 34 + row_index * 24, "width": table_width, "height": 24, "as": "geometry"},
                attrs={"data-field": name, "data-key": marker, "data-type": field_type},
            )
            row_centers[(table, name)] = y + 34 + row_index * 24 + 12

        hidden_count = len(fields) - len(visible_fields)
        if hidden_count:
            add_cell(
                root, f"row-{table}-more", f"...  ({hidden_count} more fields)",
                "shape=tableRow;horizontal=1;rotation=0;textRotation=0;startSize=0;swimlaneHead=0;swimlaneBody=0;"
                "fillColor=#f8fafc;collapsible=0;dropTarget=0;points=[[0,0.5],[1,0.5]];"
                "portConstraint=eastwest;whiteSpace=wrap;html=1;align=left;verticalAlign=middle;"
                "fontSize=10;fontStyle=2;fontFamily=Consolas;fontColor=#94a3b8;spacingLeft=8;",
                vertex=True, cell_parent=f"table-{table}",
                geometry={"y": 34 + len(visible_fields) * 24, "width": table_width,
                          "height": 24, "as": "geometry"},
                attrs={"data-hidden-field-count": str(hidden_count)},
            )

    group_index = {group: index for index, group in enumerate(GROUPS)}
    same_column_count = {group: 0 for group in GROUPS}
    long_route_count = 0
    for index, edge in enumerate(edges):
        table, field, target = edge["source"], edge["label"], edge["target"]
        source_id = f"table-{table}"
        target_id = f"table-{target}"
        source_group, target_group = TABLE_GROUP[table], TABLE_GROUP[target]
        source_column, target_column = group_index[source_group], group_index[target_group]
        sx, _, sw, _ = table_positions[table]
        tx, _, tw, _ = table_positions[target]
        sy = table_positions[table][1] + table_positions[table][3] // 2
        ty = table_positions[target][1] + table_positions[target][3] // 2

        if source_column == target_column:
            # Same-domain links use independent tracks on the right of the column.
            track = sx + sw + 45 + same_column_count[source_group] * 14
            same_column_count[source_group] += 1
            points = [(track, sy), (track, ty)]
            port_style = "exitX=1;exitY=0.5;entryX=1;entryY=0.5;"
        elif abs(source_column - target_column) == 1:
            # Adjacent domains share the empty corridor between their table columns.
            left_edge = min(sx + sw, tx + tw)
            right_edge = max(sx, tx)
            track = (left_edge + right_edge) // 2
            points = [(track, sy), (track, ty)]
            if source_column < target_column:
                port_style = "exitX=1;exitY=0.5;entryX=0;entryY=0.5;"
            else:
                port_style = "exitX=0;exitY=0.5;entryX=1;entryY=0.5;"
        else:
            # Long links travel above every table, then return through the target's
            # neighboring corridor. Each gets a separate horizontal lane.
            lane_y = 100 + long_route_count * 12
            long_route_count += 1
            if source_column < target_column:
                source_track, target_track = sx + sw + 45, tx - 45
                port_style = "exitX=1;exitY=0.5;entryX=0;entryY=0.5;"
            else:
                source_track, target_track = sx - 45, tx + tw + 45
                port_style = "exitX=0;exitY=0.5;entryX=1;entryY=0.5;"
            points = [
                (source_track, sy), (source_track, lane_y),
                (target_track, lane_y), (target_track, ty),
            ]
        add_cell(
            root, f"full-edge-{index}",
            "" if field in {item[1] for item in fields_by_table[table][:4]} else field,
            edge_style(table, field, detailed=True) + port_style,
            edge=True,
            source=source_id, target=target_id,
            geometry={"relative": "1", "as": "geometry", "points": points},
            attrs={"data-model-id": f"{table}.{field}-{target}.id", "data-kind": "foreign-key",
                   "data-optional-parent": str((table, field) in OPTIONAL_FKS).lower(),
                   "data-unique-child": str((table, field) in UNIQUE_FKS).lower()},
        )


def main():
    graph = json.loads(GRAPH_PATH.read_text(encoding="utf-8"))
    nodes = {node["id"]: node for node in graph["nodes"]}
    edges = graph["edges"]
    fields_by_table = normalize_fields(nodes, edges)

    mxfile = ET.Element("mxfile", {
        "host": "drawio-skill", "agent": "database-erd-generator", "version": "26.0.0",
        "compressed": "false",
    })
    # Put the complete property-level ERD first; the previous compact overview
    # hid columns and encouraged automatic routes to cross table cards.
    build_full_schema(mxfile, nodes, edges, fields_by_table)
    ET.indent(mxfile, space="  ")
    ET.ElementTree(mxfile).write(OUTPUT_PATH, encoding="utf-8", xml_declaration=True)
    property_count = sum(len(fields) for fields in fields_by_table.values())
    visible_property_count = sum(min(4, len(fields)) for fields in fields_by_table.values())
    print(
        f"wrote {OUTPUT_PATH.name} (1 page, {len(nodes)} tables, "
        f"{visible_property_count}/{property_count} properties displayed, "
        f"{len(edges)} relationships)"
    )


if __name__ == "__main__":
    main()
