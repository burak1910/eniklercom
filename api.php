<?php
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ]);
} else {
    session_set_cookie_params(0, '/; samesite=Lax', '', !empty($_SERVER['HTTPS']), true);
}
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Gizli ayarlar httpdocs dışından okunur
$config = require dirname(__DIR__) . '/config/enikler_config.php';

$pdo = null;
try {
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    // Veritabanı bağlantısı yoksa pdo null kalır, fallback devreye girer
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'get_all') {
        $sort = $_GET['sort'] ?? 'popular'; // popular, newest, oldest, random, likes
        $data = [];

        if ($pdo) {
            $orderBy = "ORDER BY (likes / GREATEST(likes + dislikes, 1)) DESC, likes DESC, id DESC";
            if ($sort === 'newest') {
                $orderBy = "ORDER BY id DESC";
            } elseif ($sort === 'oldest') {
                $orderBy = "ORDER BY id ASC";
            } elseif ($sort === 'random') {
                $orderBy = "ORDER BY RAND()";
            } elseif ($sort === 'likes') {
                $orderBy = "ORDER BY likes DESC, id DESC";
            }

            $stmt = $pdo->query("SELECT id, image_url, likes, dislikes FROM enikler $orderBy");
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($list as $r) {
                $l = (int)$r['likes'];
                $d = (int)$r['dislikes'];
                $tot = $l + $d;
                $ratio = $tot > 0 ? (int)round(($l / $tot) * 100) : 0;
                $data[] = [
                    'id' => (int)$r['id'],
                    'image_url' => $r['image_url'],
                    'likes' => $l,
                    'dislikes' => $d,
                    'total' => $tot,
                    'ratio' => $ratio
                ];
            }
        }

        // DB boşsa veya bağlanamadıysa uploads dizinindeki görselleri listele
        if (empty($data)) {
            $dir = __DIR__ . '/uploads';
            if (is_dir($dir)) {
                $files = scandir($dir);
                $simId = 1;
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..' || is_dir($dir . '/' . $f)) continue;
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                        $data[] = [
                            'id' => $simId++,
                            'image_url' => $f,
                            'likes' => 0,
                            'dislikes' => 0,
                            'total' => 0,
                            'ratio' => 100
                        ];
                    }
                }
            }
        }

        echo json_encode([
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
            'enikler' => $data
        ]);
        exit;
    }
    elseif ($action === 'download') {
        $id = (int)($_GET['id'] ?? 0);
        $file = basename($_GET['file'] ?? '');

        $filename = '';
        if ($id > 0 && $pdo) {
            $st = $pdo->prepare("SELECT image_url FROM enikler WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['image_url'])) {
                $filename = basename($row['image_url']);
            }
        }
        if (!$filename && $file) {
            $filename = $file;
        }

        $path = __DIR__ . '/uploads/' . $filename;
        if ($filename && is_file($path)) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'forbidden']);
                exit;
            }

            $dlName = 'enik-' . ($id > 0 ? $id : pathinfo($filename, PATHINFO_FILENAME)) . '.' . $ext;
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $dlName . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        } else {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'not_found']);
            exit;
        }
    }
    elseif ($action === 'get_random') {
        if (!$pdo) {
            // DB yoksa uploads üzerinden rastgele ver
            $dir = __DIR__ . '/uploads';
            $files = is_dir($dir) ? scandir($dir) : [];
            $valid = [];
            foreach ($files as $f) {
                if ($f === '.' || $f === '..' || is_dir($dir . '/' . $f)) continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $valid[] = $f;
                }
            }
            if ($valid) {
                $pick = $valid[array_rand($valid)];
                echo json_encode(['id' => 1, 'image_url' => $pick, 'likes' => 10, 'dislikes' => 0]);
                exit;
            }
            echo json_encode(null);
            exit;
        }

        $seen = array_map('intval', $_SESSION['seen'] ?? []);
        $total = (int)$pdo->query("SELECT COUNT(*) FROM enikler")->fetchColumn();

        // Hepsi görüldüyse listeyi kısalt, tek kayıt varsa sıfırla
        if ($total <= 1 || count($seen) >= $total) {
            $seen = array_slice($seen, -1);
            if ($total <= 1) $seen = [];
        }

        if ($seen) {
            $ph = implode(',', array_fill(0, count($seen), '?'));
            $stmt = $pdo->prepare("SELECT id, image_url, likes, dislikes FROM enikler WHERE id NOT IN ($ph) ORDER BY RAND() LIMIT 1");
            $stmt->execute($seen);
        } else {
            $stmt = $pdo->query("SELECT id, image_url, likes, dislikes FROM enikler ORDER BY RAND() LIMIT 1");
        }

        $enik = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($enik) {
            $seen[] = (int)$enik['id'];
            $limit = max(1, min(20, $total - 1));
            $_SESSION['seen'] = array_slice($seen, -$limit);
        }

        echo json_encode($enik ?: null);
    }
    elseif ($action === 'vote') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error']);
            exit;
        }

        $id   = (int)($_POST['id'] ?? 0);
        $type = $_POST['type'] ?? '';

        if ($id <= 0 || !in_array($type, ['like', 'dislike'], true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error']);
            exit;
        }

        // Aynı oturum aynı fotoya bir kez oy verebilsin
        $voted = $_SESSION['voted'] ?? [];
        if (in_array($id, $voted, true)) {
            echo json_encode(['status' => 'already_voted']);
            exit;
        }

        if (!$pdo) {
            $voted[] = $id;
            $_SESSION['voted'] = array_slice($voted, -500);
            echo json_encode(['status' => 'success']);
            exit;
        }

        $col = $type === 'like' ? 'likes' : 'dislikes';
        $stmt = $pdo->prepare("UPDATE enikler SET $col = $col + 1 WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $voted[] = $id;
            $_SESSION['voted'] = array_slice($voted, -500);
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(404);
            echo json_encode(['status' => 'error']);
        }
    }
    else {
        http_response_code(400);
        echo json_encode(['status' => 'error']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
