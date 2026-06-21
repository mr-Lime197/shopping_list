<?php
/**
 * Shopping List Sync Server — PHP / MySQL
 *
 * Setup:
 *   1. Copy this file to your web root (e.g. /var/www/html/shopping/index.php)
 *   2. Set the DB_* constants below to match your MySQL credentials.
 *   3. Run the SQL in schema.sql once to create the database & tables.
 *   4. Make sure mod_rewrite (Apache) or try_files (nginx) routes all
 *      requests to this file.  An .htaccess for Apache is included.
 *
 * Endpoints mirror the original Python/Flask server exactly:
 *
 *   GET    /products
 *   POST   /products
 *   PUT    /products/{id}
 *   DELETE /products/{id}
 *
 *   GET    /shopping
 *   POST   /shopping
 *   PUT    /shopping/{id}
 *   DELETE /shopping/{id}
 *   POST   /shopping/finish
 *   POST   /shopping/activate
 *
 *   GET    /sync
 */

// ── Configuration ─────────────────────────────────────────────────────────────

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'ttwallsk_joomla674');
define('DB_USER', 'ttwallsk_us8d6');
define('DB_PASS', 'dss@Yuy!ysh');
define('DB_CHARSET', 'utf8mb4');

// ── Bootstrap ─────────────────────────────────────────────────────────────────

header('Content-Type: application/json');

// CORS — allow all origins (same as Flask-CORS default)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Database ──────────────────────────────────────────────────────────────────

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function now_ms(): int {
    return (int)(microtime(true) * 1000);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function json_response(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_body(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

// ── Router ────────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];

// Strip query string and leading slash from the path
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = '/' . ltrim(substr($uri, 8), '/');

// Remove a sub-directory prefix if the app is not at the web root
// e.g. if deployed at /shopping/ set $base = '/shopping'
$base = '';
if ($base && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$uri = '/' . ltrim($uri, '/');
// ── Products ──────────────────────────────────────────────────────────────────

if ($uri === '/products' && $method === 'GET') {
    $rows = get_db()->query('SELECT * FROM products ORDER BY name')->fetchAll();
    json_response($rows);
}

if ($uri === '/products' && $method === 'POST') {
    $d = request_body();
    $name  = trim($d['name']  ?? '');
    $unit  = trim($d['unit']  ?? '');
    $store = trim($d['store'] ?? '');

    if ($name === '' || $unit === '' || $store === '') {
        json_response(['error' => 'name, unit and store are required'], 400);
    }

    $db = get_db();
    try {
        $stmt = $db->prepare(
            'INSERT INTO products (name, unit, store, updated) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $unit, $store, now_ms()]);
        $row = $db->prepare('SELECT * FROM products WHERE name = ?');
        $row->execute([$name]);
        json_response($row->fetch(), 201);
    } catch (PDOException $e) {
        // MySQL error 1062 = Duplicate entry
        if ($e->getCode() === '23000') {
            json_response(['error' => 'Product already exists'], 409);
        }
        throw $e;
    }
}

if (preg_match('#^/products/(\d+)$#', $uri, $m)) {
    $pid = (int)$m[1];
    $db  = get_db();

    if ($method === 'PUT') {
        $d = request_body();
        $stmt = $db->prepare(
            'UPDATE products SET name=?, unit=?, store=?, updated=? WHERE id=?'
        );
        $stmt->execute([$d['name'], $d['unit'], $d['store'], now_ms(), $pid]);

        $row = $db->prepare('SELECT * FROM products WHERE id = ?');
        $row->execute([$pid]);
        $product = $row->fetch();
        if (!$product) json_response(['error1' => 'Not found'], 404);
        json_response($product);
    }

    if ($method === 'DELETE') {
        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);
        json_response(['ok' => true]);
    }
}

// ── Shopping list ─────────────────────────────────────────────────────────────

$shopping_join = '
    SELECT sl.*, p.name, p.unit, p.store
    FROM shopping_list sl
    JOIN products p ON p.id = sl.product_id
';

if ($uri === '/shopping' && $method === 'GET') {
    $rows = get_db()->query($shopping_join . ' ORDER BY p.name')->fetchAll();
    json_response($rows);
}

if ($uri === '/shopping' && $method === 'POST') {
    $d = request_body();
    if (empty($d['product_id'])) {
        json_response(['error' => 'product_id required'], 400);
    }

    $db         = get_db();
    $product_id = (int)$d['product_id'];
    $quantity   = $d['quantity'] ?? 1;

    $check = $db->prepare('SELECT id FROM shopping_list WHERE product_id = ?');
    $check->execute([$product_id]);
    $existing = $check->fetch();

    if ($existing) {
        $db->prepare(
            'UPDATE shopping_list SET quantity=?, updated=? WHERE id=?'
        )->execute([$quantity, now_ms(), $existing['id']]);
        $sid = $existing['id'];
    } else {
        $ins = $db->prepare(
            'INSERT INTO shopping_list (product_id, quantity, updated) VALUES (?, ?, ?)'
        );
        $ins->execute([$product_id, $quantity, now_ms()]);
        $sid = (int)$db->lastInsertId();
    }

    $row = $db->prepare($shopping_join . ' WHERE sl.id = ?');
    $row->execute([$sid]);
    json_response($row->fetch(), 201);
}

// POST /shopping/finish and /shopping/activate must come before the /{id} pattern
if ($uri === '/shopping/finish' && $method === 'POST') {
    get_db()->exec('DELETE FROM shopping_list WHERE checked = 1');
    json_response(['ok' => true]);
}

if ($uri === '/shopping/activate' && $method === 'POST') {
    get_db()->prepare(
        'UPDATE shopping_list SET in_store=1, checked=0, updated=?'
    )->execute([now_ms()]);
    json_response(['ok' => true]);
}

if (preg_match('#^/shopping/(\d+)$#', $uri, $m)) {
    $sid = (int)$m[1];
    $db  = get_db();

    if ($method === 'PUT') {
        $d      = request_body();
        $cols   = [];
        $values = [];

        foreach (['quantity', 'checked', 'in_store'] as $col) {
            if (array_key_exists($col, $d)) {
                $cols[]   = "$col = ?";
                $values[] = $d[$col];
            }
        }

        if ($cols) {
            $values[] = now_ms();
            $values[] = $sid;
            $db->prepare(
                'UPDATE shopping_list SET ' . implode(', ', $cols) . ', updated = ? WHERE id = ?'
            )->execute($values);
        }

        $row = $db->prepare($shopping_join . ' WHERE sl.id = ?');
        $row->execute([$sid]);
        $item = $row->fetch();
        if (!$item) json_response(['error' => 'Not found'], 404);
        json_response($item);
    }

    if ($method === 'DELETE') {
        $db->prepare('DELETE FROM shopping_list WHERE id = ?')->execute([$sid]);
        json_response(['ok' => true]);
    }
}

// ── Sync snapshot ─────────────────────────────────────────────────────────────

if ($uri === '/sync' && $method === 'GET') {
    $db = get_db();
    $products = $db->query('SELECT * FROM products ORDER BY name')->fetchAll();
    $shopping = $db->query($shopping_join . ' ORDER BY p.name')->fetchAll();
    json_response(['products' => $products, 'shopping' => $shopping, 'ts' => now_ms()]);
}

// ── 404 fallthrough ───────────────────────────────────────────────────────────

json_response(['error2' => 'Not found'], 404);
