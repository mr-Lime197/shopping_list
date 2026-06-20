#!/usr/bin/env python3
"""
Shopping List Sync Server
Run with: python3 server.py
Requires: pip install flask flask-cors
Default port: 5000
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import sqlite3
import os
import time

app = Flask(__name__)
CORS(app)

DB_PATH = "shopping.db"

# ── Schema ──────────────────────────────────────────────────────────────────

def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn

def init_db():
    conn = get_db()
    c = conn.cursor()

    c.execute("""
        CREATE TABLE IF NOT EXISTS products (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            name     TEXT NOT NULL UNIQUE,
            unit     TEXT NOT NULL,
            store    TEXT NOT NULL,
            updated  INTEGER NOT NULL DEFAULT 0
        )
    """)

    c.execute("""
        CREATE TABLE IF NOT EXISTS shopping_list (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL REFERENCES products(id),
            quantity   REAL    NOT NULL DEFAULT 1,
            checked    INTEGER NOT NULL DEFAULT 0,
            in_store   INTEGER NOT NULL DEFAULT 0,
            updated    INTEGER NOT NULL DEFAULT 0
        )
    """)

    conn.commit()
    conn.close()
    print("Database ready:", DB_PATH)

# ── Helpers ──────────────────────────────────────────────────────────────────

def now_ms():
    return int(time.time() * 1000)

# ── Products ─────────────────────────────────────────────────────────────────

@app.route("/products", methods=["GET"])
def get_products():
    conn = get_db()
    rows = conn.execute("SELECT * FROM products ORDER BY name").fetchall()
    conn.close()
    return jsonify([dict(r) for r in rows])

@app.route("/products", methods=["POST"])
def add_product():
    d = request.json
    if not d or not d.get("name") or not d.get("unit") or not d.get("store"):
        return jsonify({"error": "name, unit and store are required"}), 400
    try:
        conn = get_db()
        conn.execute(
            "INSERT INTO products (name, unit, store, updated) VALUES (?,?,?,?)",
            (d["name"].strip(), d["unit"].strip(), d["store"].strip(), now_ms())
        )
        conn.commit()
        row = conn.execute("SELECT * FROM products WHERE name=?", (d["name"].strip(),)).fetchone()
        conn.close()
        return jsonify(dict(row)), 201
    except sqlite3.IntegrityError:
        return jsonify({"error": "Product already exists"}), 409

@app.route("/products/<int:pid>", methods=["PUT"])
def update_product(pid):
    d = request.json
    conn = get_db()
    conn.execute(
        "UPDATE products SET name=?, unit=?, store=?, updated=? WHERE id=?",
        (d["name"], d["unit"], d["store"], now_ms(), pid)
    )
    conn.commit()
    row = conn.execute("SELECT * FROM products WHERE id=?", (pid,)).fetchone()
    conn.close()
    if not row:
        return jsonify({"error": "Not found"}), 404
    return jsonify(dict(row))

@app.route("/products/<int:pid>", methods=["DELETE"])
def delete_product(pid):
    conn = get_db()
    conn.execute("DELETE FROM products WHERE id=?", (pid,))
    conn.commit()
    conn.close()
    return jsonify({"ok": True})

# ── Shopping list ─────────────────────────────────────────────────────────────

@app.route("/shopping", methods=["GET"])
def get_shopping():
    conn = get_db()
    rows = conn.execute("""
        SELECT sl.*, p.name, p.unit, p.store
        FROM shopping_list sl
        JOIN products p ON p.id = sl.product_id
        ORDER BY p.name
    """).fetchall()
    conn.close()
    return jsonify([dict(r) for r in rows])

@app.route("/shopping", methods=["POST"])
def add_shopping():
    d = request.json
    if not d or not d.get("product_id"):
        return jsonify({"error": "product_id required"}), 400
    conn = get_db()
    # upsert: if product already in list, just update quantity
    existing = conn.execute(
        "SELECT id FROM shopping_list WHERE product_id=?", (d["product_id"],)
    ).fetchone()
    if existing:
        conn.execute(
            "UPDATE shopping_list SET quantity=?, updated=? WHERE id=?",
            (d.get("quantity", 1), now_ms(), existing["id"])
        )
        conn.commit()
        row = conn.execute("SELECT sl.*, p.name, p.unit, p.store FROM shopping_list sl JOIN products p ON p.id=sl.product_id WHERE sl.id=?", (existing["id"],)).fetchone()
    else:
        cur = conn.execute(
            "INSERT INTO shopping_list (product_id, quantity, updated) VALUES (?,?,?)",
            (d["product_id"], d.get("quantity", 1), now_ms())
        )
        conn.commit()
        row = conn.execute("SELECT sl.*, p.name, p.unit, p.store FROM shopping_list sl JOIN products p ON p.id=sl.product_id WHERE sl.id=?", (cur.lastrowid,)).fetchone()
    conn.close()
    return jsonify(dict(row)), 201

@app.route("/shopping/<int:sid>", methods=["PUT"])
def update_shopping(sid):
    d = request.json
    conn = get_db()
    fields, vals = [], []
    for col in ("quantity", "checked", "in_store"):
        if col in d:
            fields.append(f"{col}=?")
            vals.append(d[col])
    if fields:
        vals += [now_ms(), sid]
        conn.execute(f"UPDATE shopping_list SET {','.join(fields)}, updated=? WHERE id=?", vals)
        conn.commit()
    row = conn.execute("SELECT sl.*, p.name, p.unit, p.store FROM shopping_list sl JOIN products p ON p.id=sl.product_id WHERE sl.id=?", (sid,)).fetchone()
    conn.close()
    if not row:
        return jsonify({"error": "Not found"}), 404
    return jsonify(dict(row))

@app.route("/shopping/<int:sid>", methods=["DELETE"])
def delete_shopping(sid):
    conn = get_db()
    conn.execute("DELETE FROM shopping_list WHERE id=?", (sid,))
    conn.commit()
    conn.close()
    return jsonify({"ok": True})

@app.route("/shopping/finish", methods=["POST"])
def finish_shopping():
    """Remove all checked items from the shopping list."""
    conn = get_db()
    conn.execute("DELETE FROM shopping_list WHERE checked=1")
    conn.commit()
    conn.close()
    return jsonify({"ok": True})

@app.route("/shopping/activate", methods=["POST"])
def activate_shopping():
    """Set in_store=1 for all items (go to store mode)."""
    conn = get_db()
    conn.execute("UPDATE shopping_list SET in_store=1, checked=0, updated=?", (now_ms(),))
    conn.commit()
    conn.close()
    return jsonify({"ok": True})

# ── Full sync snapshot ────────────────────────────────────────────────────────

@app.route("/sync", methods=["GET"])
def sync():
    conn = get_db()
    products = [dict(r) for r in conn.execute("SELECT * FROM products ORDER BY name").fetchall()]
    shopping = [dict(r) for r in conn.execute("""
        SELECT sl.*, p.name, p.unit, p.store
        FROM shopping_list sl JOIN products p ON p.id=sl.product_id ORDER BY p.name
    """).fetchall()]
    conn.close()
    return jsonify({"products": products, "shopping": shopping, "ts": now_ms()})

# ── Main ──────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    init_db()
    print("Starting Shopping List Server on http://127.0.0.1:5000")
    print("All devices on the same network can connect using your machine's IP.")
    app.run(host="127.0.0.1", port=5000, debug=True)
