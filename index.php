<?php
/**
 * KACHOOLI — Premium Women's Lingerie & Loungewear
 * Unified Single-File Web Application (PHP + MySQL + HTML/CSS/JS)
 * Optimized for shared hosting (Diana Host, 5GB limit).
 */

// 1. Session & Environment Configuration
session_start();

// Helper to get environment variables from getenv, $_ENV, or .env file
function get_env_var($key, $default = '') {
    if (getenv($key) !== false) {
        return getenv($key);
    }
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    static $env = null;
    if ($env === null) {
        $env = [];
        $path = __DIR__ . '/.env';
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                    $env[$name] = trim($value, '"\'');
                }
            }
        }
    }
    return isset($env[$key]) ? $env[$key] : $default;
}

// 2. Database Connection & Auto-Initialization
function get_db_connection() {
    $host = get_env_var('DB_HOST', '127.0.0.1');
    $port = get_env_var('DB_PORT', '3306');
    $dbname = get_env_var('DB_NAME', 'kacooli_enp360');
    $user = get_env_var('DB_USER', 'root');
    $password = get_env_var('DB_PASSWORD', '');

    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        seed_database_if_empty($pdo);
        return $pdo;
    } catch (PDOException $e) {
        // If connection fails due to missing database, try to create it
        if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
            try {
                $dsn_no_db = "mysql:host=$host;port=$port;charset=utf8mb4";
                $pdo_no_db = new PDO($dsn_no_db, $user, $password);
                $pdo_no_db->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                
                // Connect to newly created database
                $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);

                // Run database/schema.sql if available
                $schema_path = __DIR__ . '/database/schema.sql';
                if (file_exists($schema_path)) {
                    $sql = file_get_contents($schema_path);
                    // Split schema into statements and execute
                    // Note: Basic parsing, splits by semicolon + newline
                    $queries = preg_split('/;\s*$/m', $sql);
                    foreach ($queries as $query) {
                        $query = trim($query);
                        if (!empty($query)) {
                            $pdo->exec($query);
                        }
                    }
                }
                seed_database_if_empty($pdo);
                return $pdo;
            } catch (PDOException $e2) {
                throw new Exception("Database auto-initialization failed: " . $e2->getMessage());
            }
        } else {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
}

function get_numeric_product_id($sku) {
    $prefix = substr($sku, 0, 1);
    $num = intval(substr($sku, 2));
    if ($prefix === 'B') {
        return $num; // 1 - 26
    } elseif ($prefix === 'U') {
        return 100 + $num; // 101 - 109
    } elseif ($prefix === 'I') {
        return 200 + $num; // 201 - 206
    }
    return $num > 0 ? $num : 999;
}

function seed_database_if_empty($pdo) {
    // 1. Seed categories
    $cat_count = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($cat_count == 0) {
        $categories = [
            [1, 'Bra/Lingerie', 'bra-lingerie', 'Premium Bra & Lingerie styles'],
            [2, 'Underwear', 'underwear', 'Comfortable underwear styles'],
            [3, 'Innerwear', 'innerwear', 'Premium women\'s secret innerwear']
        ];
        $ins = $pdo->prepare("INSERT INTO categories (id, name, slug, description, is_active) VALUES (?, ?, ?, ?, 1)");
        foreach ($categories as $cat) {
            $ins->execute($cat);
        }
    }

    // 2. Seed products
    $prod_count = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    if ($prod_count == 0) {
        $products = get_product_catalog();
        if (!empty($products)) {
            $ins_prod = $pdo->prepare("INSERT INTO products (id, category_id, name, slug, sku, short_description, description, price, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            foreach ($products as $p) {
                $id = get_numeric_product_id($p['id']);
                $cat_id = ($p['category'] === 'Bra/Lingerie') ? 1 : (($p['category'] === 'Underwear') ? 2 : 3);
                $slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $p['name']));
                $sku = $p['id'];
                $short_desc = substr($p['desc'], 0, 250);
                $desc = $p['desc'];
                // Use the first size's price as the base price
                $price = isset($p['sizes'][0]['price']) ? $p['sizes'][0]['price'] : 0;
                $ins_prod->execute([$id, $cat_id, $p['name'], $slug, $sku, $short_desc, $desc, $price]);
            }
        }
    }
}

// 3. Normalized Unique Character-Based Fingerprinting Functions
function generate_email_fingerprint($email) {
    $email = strtolower(trim($email));
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return md5($email);
    }
    
    $local = $parts[0];
    $domain = $parts[1];
    
    // Normalize GMail sub-addressing (+ tags) and dots
    if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
        $local = explode('+', $local)[0];
        $local = str_replace('.', '', $local);
    } else {
        $local = explode('+', $local)[0];
    }
    
    $normalized_local = preg_replace('/[^a-z0-9]/', '', $local);
    $normalized_domain = preg_replace('/[^a-z0-9.]/', '', $domain);
    $full = $normalized_local . '@' . $normalized_domain;
    
    // Character frequency mapping
    $chars = str_split($full);
    sort($chars);
    $counts = array_count_values($chars);
    
    $sig = [];
    foreach ($counts as $char => $count) {
        $sig[] = $count . $char;
    }
    return 'e:' . implode('-', $sig);
}

function generate_whatsapp_fingerprint($whatsapp) {
    $number = preg_replace('/[^0-9]/', '', $whatsapp);
    
    // Strip common country prefix (Bangladesh: 880 / 88)
    if (strpos($number, '880') === 0) {
        $number = substr($number, 3);
    } elseif (strpos($number, '88') === 0 && strlen($number) > 10) {
        $number = substr($number, 2);
    } elseif (strpos($number, '0') !== 0 && strlen($number) == 10) {
        $number = '0' . $number;
    }
    
    // Character frequency mapping
    $chars = str_split($number);
    sort($chars);
    $counts = array_count_values($chars);
    
    $sig = [];
    foreach ($counts as $char => $count) {
        $sig[] = $count . $char;
    }
    return 'w:' . implode('-', $sig);
}

// Store tracking source in session if present in URL
if (isset($_GET['src'])) {
    $src = strtolower(trim($_GET['src']));
    if (in_array($src, ['walkin', 'fb', 'ig'])) {
        $_SESSION['promo_source'] = $src;
    }
}

// 4. API Request Handling (Router)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    try {
        $pdo = get_db_connection();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }

    // A. Registration Endpoint
    if ($action === 'register') {
        $name = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $preference = trim($_POST['preference'] ?? '');

        if (empty($name) || empty($email) || empty($whatsapp)) {
            echo json_encode(['status' => 'error', 'message' => 'Full Name, Email, and WhatsApp number are required.']);
            exit;
        }

        $email_fp = generate_email_fingerprint($email);
        $whatsapp_fp = generate_whatsapp_fingerprint($whatsapp);

        // Check duplicates based on fingerprints
        $stmt = $pdo->prepare("SELECT * FROM vip_registrations WHERE email_fingerprint = ? OR whatsapp_fingerprint = ?");
        $stmt->execute([$email_fp, $whatsapp_fp]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['is_verified']) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'This email or WhatsApp number is already registered and verified as a VIP.'
                ]);
                exit;
            } else {
                // Resend OTP for unverified registration
                $otp = sprintf("%06d", rand(100000, 999999));
                $update = $pdo->prepare("UPDATE vip_registrations SET otp_code = ?, full_name = ? WHERE id = ?");
                $update->execute([$otp, $name, $existing['id']]);
                
                $_SESSION['pending_vip_id'] = $existing['id'];
                echo json_encode([
                    'status' => 'pending',
                    'message' => 'Unverified registration found. Simulated OTP code has been resent.',
                    'mock_otp' => $otp
                ]);
                exit;
            }
        }

        // Create new VIP registration
        $otp = sprintf("%06d", rand(100000, 999999));
        do {
            $access_pass = 'KC-' . rand(10000, 99999);
            $check_pass = $pdo->prepare("SELECT id FROM vip_registrations WHERE access_pass = ?");
            $check_pass->execute([$access_pass]);
        } while ($check_pass->fetch());

        $insert = $pdo->prepare("INSERT INTO vip_registrations (full_name, email, whatsapp, email_fingerprint, whatsapp_fingerprint, otp_code, is_verified, access_pass) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
        $insert->execute([$name, $email, $whatsapp, $email_fp, $whatsapp_fp, $otp, $access_pass]);
        
        $_SESSION['pending_vip_id'] = $pdo->lastInsertId();
        
        echo json_encode([
            'status' => 'pending',
            'message' => 'Simulated OTP generated successfully.',
            'mock_otp' => $otp
        ]);
        exit;
    }

    // B. OTP Verification Endpoint
    if ($action === 'verify') {
        $otp_code = trim($_POST['otp_code'] ?? '');
        $pending_id = $_SESSION['pending_vip_id'] ?? null;

        if (!$pending_id) {
            echo json_encode(['status' => 'error', 'message' => 'No active registration found. Please submit registration form.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM vip_registrations WHERE id = ?");
        $stmt->execute([$pending_id]);
        $vip = $stmt->fetch();

        if (!$vip) {
            echo json_encode(['status' => 'error', 'message' => 'Registration details not found.']);
            exit;
        }

        if ($vip['otp_code'] === $otp_code) {
            $update = $pdo->prepare("UPDATE vip_registrations SET is_verified = 1 WHERE id = ?");
            $update->execute([$pending_id]);

            $_SESSION['vip_verified'] = true;
            $_SESSION['vip_access_pass'] = $vip['access_pass'];
            $_SESSION['vip_name'] = $vip['full_name'];
            $_SESSION['vip_email'] = $vip['email'];
            $_SESSION['vip_whatsapp'] = $vip['whatsapp'];

            echo json_encode([
                'status' => 'success',
                'message' => 'OTP verified successfully.',
                'access_pass' => $vip['access_pass'],
                'name' => $vip['full_name'],
                'email' => $vip['email'],
                'whatsapp' => $vip['whatsapp']
            ]);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid OTP code. Please try again.']);
            exit;
        }
    }

    // C. Order Checkout Endpoint
    if ($action === 'checkout') {
        $cart = json_decode($_POST['cart'] ?? '[]', true);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $postal_code = trim($_POST['postal_code'] ?? '');
        $shipping_zone = trim($_POST['shipping_zone'] ?? 'inside');
        
        $subtotal = floatval($_POST['subtotal'] ?? 0);
        $discount = floatval($_POST['discount'] ?? 0);
        $shipping = floatval($_POST['shipping'] ?? 0);
        $total = floatval($_POST['total'] ?? 0);
        $applied_discount_rate = intval($_POST['applied_discount_rate'] ?? 0);

        if (empty($cart) || empty($name) || empty($email) || empty($phone) || empty($address) || empty($city)) {
            echo json_encode(['status' => 'error', 'message' => 'All checkout fields are required and cart cannot be empty.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $order_number = 'KC-ORD-' . rand(100000, 999999);
            
            // Insert Order
            $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, billing_address_line1, billing_city, billing_postal_code, subtotal, discount_amount, shipping_amount, total_amount, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'cod', ?)");
            $stmt->execute([
                $order_number,
                $name,
                $email,
                $phone,
                $address,
                $city,
                $postal_code,
                $subtotal,
                $discount,
                $shipping,
                $total,
                "Applied Discount: " . $applied_discount_rate . "%"
            ]);
            $order_id = $pdo->lastInsertId();

            // Insert Order Items
            $insert_item = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, product_sku, unit_price, quantity, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($cart as $item) {
                // Get a base product ID mapped uniquely
                $prod_id = get_numeric_product_id($item['id']);
                $insert_item->execute([
                    $order_id,
                    $prod_id,
                    $item['name'],
                    $item['id'] . '-' . $item['size'],
                    $item['price'],
                    $item['quantity'],
                    $item['price'] * $item['quantity']
                ]);
            }

            $pdo->commit();

            // Clear session cart
            $_SESSION['cart_saved'] = [];

            // Compile WhatsApp Redirect URL
            $wa_number = '+8801974424264'; // Campaign business WhatsApp
            
            // Format order summary message
            $msg = "Hello KACHOOLI! 🌸\n";
            $msg .= "I would like to confirm my Early Access Order.\n\n";
            $msg .= "Order Number: *" . $order_number . "*\n";
            $msg .= "Name: " . $name . "\n";
            $msg .= "WhatsApp: " . $phone . "\n";
            $msg .= "Address: " . $address . ", " . $city . " (" . $postal_code . ")\n\n";
            $msg .= "*Order Items:*\n";
            
            foreach ($cart as $idx => $item) {
                $msg .= ($idx + 1) . ". " . $item['name'] . " (" . $item['size'] . ") x " . $item['quantity'] . " - " . ($item['price'] * $item['quantity']) . " BDT\n";
            }
            
            $msg .= "\nSubtotal: " . $subtotal . " BDT\n";
            if ($discount > 0) {
                $msg .= "Discount (" . $applied_discount_rate . "%): -" . $discount . " BDT\n";
            }
            $msg .= "Delivery Fee: " . $shipping . " BDT\n";
            $msg .= "*Total Amount: " . $total . " BDT*\n\n";
            $msg .= "Payment Method: Cash on Delivery (COD)\n";
            $msg .= "Please confirm my order. Thank you!";

            $wa_url = "https://api.whatsapp.com/send?phone=" . urlencode($wa_number) . "&text=" . urlencode($msg);

            echo json_encode([
                'status' => 'success',
                'order_number' => $order_number,
                'whatsapp_url' => $wa_url
            ]);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Checkout failed: ' . $e->getMessage()]);
            exit;
        }
    }

    // D. Admin Login Endpoint
    if ($action === 'admin-login') {
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        
        $cfg_user = get_env_var('ADMIN_USER', 'admin');
        $cfg_pass = get_env_var('ADMIN_PASSWORD', 'admin123');

        if ($user === $cfg_user && $pass === $cfg_pass) {
            $_SESSION['admin_logged_in'] = true;
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid admin credentials. Check your config.']);
        }
        exit;
    }
}

// 5. CSV Data Export Handlers
if (isset($_GET['export']) && isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    try {
        $pdo = get_db_connection();
    } catch (Exception $e) {
        die("Database connection failed: " . $e->getMessage());
    }

    if ($_GET['export'] === 'vip') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="kachooli_vip_registrations_' . date('Ymd_His') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Full Name', 'Email', 'WhatsApp', 'Email Fingerprint', 'WhatsApp Fingerprint', 'Access Pass', 'Verified', 'Created At']);
        $stmt = $pdo->query("SELECT id, full_name, email, whatsapp, email_fingerprint, whatsapp_fingerprint, access_pass, is_verified, created_at FROM vip_registrations ORDER BY id DESC");
        while ($row = $stmt->fetch()) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    if ($_GET['export'] === 'orders') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="kachooli_orders_' . date('Ymd_His') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Order Number', 'Customer Name', 'Customer Email', 'Customer Phone', 'Address', 'City', 'Subtotal', 'Discount', 'Shipping', 'Total', 'Placed At']);
        $stmt = $pdo->query("SELECT id, order_number, customer_name, customer_email, customer_phone, billing_address_line1, billing_city, subtotal, discount_amount, shipping_amount, total_amount, created_at FROM orders ORDER BY id DESC");
        while ($row = $stmt->fetch()) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}

// Admin logout handler
if (isset($_GET['admin_logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: index.php?admin=1');
    exit;
}

// 6. Define Product Dataset
// Quality segments: Import (eligible for 35% Grand Eid discount), Export, Local
function get_product_catalog() {
    return [
    // BRA CATEGORY (B-001 to B-026)
    [
        'id' => 'B-001', 'category' => 'Bra/Lingerie', 'name' => 'T-Shirt Bra', 'segment' => 'Local', 'image' => 'G_1.png',
        'desc' => 'Designed for a flawless, invisible finish under any top, featuring molded seamless cups and wire-free support.',
        'sizes' => [
            ['size' => '32B', 'price' => 1400],
            ['size' => '34B', 'price' => 1450],
            ['size' => '36C', 'price' => 1500],
            ['size' => '38D', 'price' => 1600],
        ]
    ],
    [
        'id' => 'B-002', 'category' => 'Bra/Lingerie', 'name' => 'Sports Bra', 'segment' => 'Export', 'image' => 'G_2.png',
        'desc' => 'High-impact compression bra engineered with moisture-wicking modal linings for ultimate activity support.',
        'sizes' => [
            ['size' => 'S', 'price' => 3200],
            ['size' => 'M', 'price' => 3300],
            ['size' => 'L', 'price' => 3400],
            ['size' => 'XL', 'price' => 3500],
        ]
    ],
    [
        'id' => 'B-003', 'category' => 'Bra/Lingerie', 'name' => 'Push-Up Bra', 'segment' => 'Import', 'image' => 'G_3.png',
        'desc' => 'Accentuates your natural curves with premium gel-cushioned cups and elegant satin detailing.',
        'sizes' => [
            ['size' => '32B', 'price' => 5200],
            ['size' => '34C', 'price' => 5400],
            ['size' => '36D', 'price' => 5600],
        ]
    ],
    [
        'id' => 'B-004', 'category' => 'Bra/Lingerie', 'name' => 'Bralette', 'segment' => 'Local', 'image' => 'G_4.png',
        'desc' => 'Ultra-lightweight and wire-free, made of fine stretch lace for a soft, breathable fit.',
        'sizes' => [
            ['size' => 'S', 'price' => 1150],
            ['size' => 'M', 'price' => 1200],
            ['size' => 'L', 'price' => 1250],
        ]
    ],
    [
        'id' => 'B-005', 'category' => 'Bra/Lingerie', 'name' => 'Strapless Bra', 'segment' => 'Export', 'image' => 'G_5.png',
        'desc' => 'Designed to stay in place all day with non-slip silicone linings and versatile convertible straps.',
        'sizes' => [
            ['size' => '32B', 'price' => 2900],
            ['size' => '34B', 'price' => 3000],
            ['size' => '36C', 'price' => 3100],
            ['size' => '38C', 'price' => 3200],
        ]
    ],
    [
        'id' => 'B-006', 'category' => 'Bra/Lingerie', 'name' => 'Plunge Bra', 'segment' => 'Import', 'image' => 'G_6.png',
        'desc' => 'Perfect for low-cut necklines, combining deep plunge cups with delicate scalloped lace overlays.',
        'sizes' => [
            ['size' => '32B', 'price' => 6400],
            ['size' => '34C', 'price' => 6600],
            ['size' => '36D', 'price' => 6800],
            ['size' => '38DD', 'price' => 7200],
        ]
    ],
    [
        'id' => 'B-007', 'category' => 'Bra/Lingerie', 'name' => 'Balconette Bra', 'segment' => 'Export', 'image' => 'G_7.png',
        'desc' => 'Features wide-set straps and a horizontal neckline, offering structured support and a classic lift.',
        'sizes' => [
            ['size' => '32C', 'price' => 3700],
            ['size' => '34C', 'price' => 3800],
            ['size' => '36D', 'price' => 3900],
            ['size' => '38D', 'price' => 4000],
        ]
    ],
    [
        'id' => 'B-008', 'category' => 'Bra/Lingerie', 'name' => 'Wireless Bra', 'segment' => 'Local', 'image' => 'G_8.png',
        'desc' => 'Provides weightless, natural shaping with soft, seamless cups and an elastic support band.',
        'sizes' => [
            ['size' => 'S', 'price' => 1750],
            ['size' => 'M', 'price' => 1800],
            ['size' => 'L', 'price' => 1850],
            ['size' => 'XL', 'price' => 1950],
        ]
    ],
    [
        'id' => 'B-009', 'category' => 'Bra/Lingerie', 'name' => 'Underwire Bra', 'segment' => 'Export', 'image' => 'G_9.png',
        'desc' => 'Maximum coverage and all-day comfort featuring wide cushioned straps and reinforced support panels.',
        'sizes' => [
            ['size' => '34B', 'price' => 3000],
            ['size' => '36C', 'price' => 3100],
            ['size' => '38D', 'price' => 3200],
            ['size' => '40D', 'price' => 3300],
        ]
    ],
    [
        'id' => 'B-010', 'category' => 'Bra/Lingerie', 'name' => 'Minimizer Bra', 'segment' => 'Import', 'image' => 'G_10.png',
        'desc' => 'Engineered to visually reduce bust projection while maintaining a smooth and natural profile.',
        'sizes' => [
            ['size' => '36C', 'price' => 7000],
            ['size' => '38D', 'price' => 7200],
            ['size' => '40DD', 'price' => 7500],
            ['size' => '42F', 'price' => 8000],
        ]
    ],
    [
        'id' => 'B-011', 'category' => 'Bra/Lingerie', 'name' => 'Maternity/Nursing Bra', 'segment' => 'Export', 'image' => 'G_11.png',
        'desc' => 'Designed with easy one-handed drop-down cups and soft lining for comfortable nursing.',
        'sizes' => [
            ['size' => '34C', 'price' => 3500],
            ['size' => '36D', 'price' => 3600],
            ['size' => '38E', 'price' => 3750],
            ['size' => '40F', 'price' => 4000],
        ]
    ],
    [
        'id' => 'B-012', 'category' => 'Bra/Lingerie', 'name' => 'Demi Bra', 'segment' => 'Local', 'image' => 'G_12.png',
        'desc' => 'Low-cut cups that offer natural lift and shape, perfect for square or scoop necklines.',
        'sizes' => [
            ['size' => '32A', 'price' => 1500],
            ['size' => '34B', 'price' => 1550],
            ['size' => '36C', 'price' => 1650],
        ]
    ],
    [
        'id' => 'B-013', 'category' => 'Bra/Lingerie', 'name' => 'Full Coverage Bra', 'segment' => 'Export', 'image' => 'G_13.png',
        'desc' => 'Maximum coverage and all-day comfort featuring wide cushioned straps and reinforced support panels.',
        'sizes' => [
            ['size' => '34C', 'price' => 3400],
            ['size' => '36D', 'price' => 3500],
            ['size' => '38DD', 'price' => 3650],
            ['size' => '40E', 'price' => 3900],
        ]
    ],
    [
        'id' => 'B-014', 'category' => 'Bra/Lingerie', 'name' => 'Front-Closure Bra', 'segment' => 'Local', 'image' => 'G_14.png',
        'desc' => 'Features a premium, secure front clasp for easy wear and a smooth, seamless back design.',
        'sizes' => [
            ['size' => '34B', 'price' => 1850],
            ['size' => '36C', 'price' => 1900],
            ['size' => '38D', 'price' => 2000],
        ]
    ],
    [
        'id' => 'B-015', 'category' => 'Bra/Lingerie', 'name' => 'Halter Bra', 'segment' => 'Export', 'image' => 'G_15.png',
        'desc' => 'Features neck-wrapping straps, providing an open shoulder look and specialized back configuration.',
        'sizes' => [
            ['size' => 'S', 'price' => 2500],
            ['size' => 'M', 'price' => 2600],
            ['size' => 'L', 'price' => 2700],
        ]
    ],
    [
        'id' => 'B-016', 'category' => 'Bra/Lingerie', 'name' => 'Racerback Bra', 'segment' => 'Export', 'image' => 'G_16.png',
        'desc' => 'Features cross-back straps, providing extra shoulder blade freedom and distributed support.',
        'sizes' => [
            ['size' => 'S', 'price' => 2900],
            ['size' => 'M', 'price' => 3000],
            ['size' => 'L', 'price' => 3100],
            ['size' => 'XL', 'price' => 3250],
        ]
    ],
    [
        'id' => 'B-017', 'category' => 'Bra/Lingerie', 'name' => 'Multiway/Convertible Bra', 'segment' => 'Import', 'image' => 'G_17.png',
        'desc' => 'Features multi-way adjustable straps to wear strapless, halter, cross-back, or one-shoulder.',
        'sizes' => [
            ['size' => '32B', 'price' => 5800],
            ['size' => '34C', 'price' => 6000],
            ['size' => '36D', 'price' => 6400],
        ]
    ],
    [
        'id' => 'B-018', 'category' => 'Bra/Lingerie', 'name' => 'Sticky/Adhesive Bra', 'segment' => 'Import', 'image' => 'G_18.png',
        'desc' => 'Backless and strapless medical-grade silicone cups that adhere comfortably to the skin.',
        'sizes' => [
            ['size' => 'A', 'price' => 4400],
            ['size' => 'B', 'price' => 4500],
            ['size' => 'C', 'price' => 4650],
            ['size' => 'D', 'price' => 4900],
        ]
    ],
    [
        'id' => 'B-019', 'category' => 'Bra/Lingerie', 'name' => 'Lingerie Bodysuit', 'segment' => 'Import', 'image' => 'G_19.png',
        'desc' => 'Exquisite full-body intimate wear combining sheer panel designs with structured luxury linings.',
        'sizes' => [
            ['size' => 'S', 'price' => 8500],
            ['size' => 'M', 'price' => 8800],
            ['size' => 'L', 'price' => 9200],
            ['size' => 'XL', 'price' => 9600],
        ]
    ],
    [
        'id' => 'B-020', 'category' => 'Bra/Lingerie', 'name' => 'Corset / Bustier', 'segment' => 'Import', 'image' => 'G_20.png',
        'desc' => 'Provides structured boning and vintage style lace-up details for a defined waist and lift.',
        'sizes' => [
            ['size' => '32', 'price' => 11000],
            ['size' => '34', 'price' => 11250],
            ['size' => '36', 'price' => 11500],
            ['size' => '38', 'price' => 11900],
            ['size' => '40', 'price' => 12300],
        ]
    ],
    [
        'id' => 'B-021', 'category' => 'Bra/Lingerie', 'name' => 'Bridal Bra', 'segment' => 'Import', 'image' => 'G_21.png',
        'desc' => 'Detailed white/ivory lace styling with delicate floral embroidery, designed for special occasions.',
        'sizes' => [
            ['size' => '32B', 'price' => 9800],
            ['size' => '34C', 'price' => 10200],
            ['size' => '36D', 'price' => 10600],
            ['size' => '38DD', 'price' => 11200],
        ]
    ],
    [
        'id' => 'B-022', 'category' => 'Bra/Lingerie', 'name' => 'Longline Bra', 'segment' => 'Export', 'image' => 'G_22.png',
        'desc' => 'Features an extended underband that smooths the silhouette and provides additional torso support.',
        'sizes' => [
            ['size' => '32B', 'price' => 4200],
            ['size' => '34C', 'price' => 4350],
            ['size' => '36D', 'price' => 4500],
            ['size' => '38D', 'price' => 4650],
        ]
    ],
    [
        'id' => 'B-023', 'category' => 'Bra/Lingerie', 'name' => 'Camisole Bra', 'segment' => 'Local', 'image' => 'G_23.png',
        'desc' => 'Dual function tank styling with integrated support cups, providing casual lounge support.',
        'sizes' => [
            ['size' => 'S', 'price' => 1250],
            ['size' => 'M', 'price' => 1300],
            ['size' => 'L', 'price' => 1350],
            ['size' => 'XL', 'price' => 1450],
        ]
    ],
    [
        'id' => 'B-024', 'category' => 'Bra/Lingerie', 'name' => 'Bandeau Bra', 'segment' => 'Local', 'image' => 'G_24.png',
        'desc' => 'A strapless pull-on style made of soft modal fabric, perfect for layered looks.',
        'sizes' => [
            ['size' => 'S', 'price' => 1000],
            ['size' => 'M', 'price' => 1050],
            ['size' => 'L', 'price' => 1100],
        ]
    ],
    [
        'id' => 'B-025', 'category' => 'Bra/Lingerie', 'name' => 'Shelf Bra', 'segment' => 'Export', 'image' => 'G_25.png',
        'desc' => 'Open configuration framing bust contours with structured underlying support scaffolding.',
        'sizes' => [
            ['size' => '32B', 'price' => 3100],
            ['size' => '34C', 'price' => 3250],
            ['size' => '36D', 'price' => 3400],
        ]
    ],
    [
        'id' => 'B-026', 'category' => 'Bra/Lingerie', 'name' => 'Peep/Cut-out Bra', 'segment' => 'Import', 'image' => 'G_26.png',
        'desc' => 'An alluring open-cup design featuring scalloped lace trims and satin ribbons.',
        'sizes' => [
            ['size' => 'S', 'price' => 7500],
            ['size' => 'M', 'price' => 7800],
            ['size' => 'L', 'price' => 8200],
        ]
    ],

    // UNDERWEAR CATEGORY (U-001 to U-009)
    [
        'id' => 'U-001', 'category' => 'Underwear', 'name' => 'Briefs (Classic)', 'segment' => 'Local', 'image' => 'daily_luxe.png',
        'desc' => 'Classic everyday briefs made from premium breathable cotton, offering full coverage and standard comfort.',
        'sizes' => [
            ['size' => 'S', 'price' => 550],
            ['size' => 'M', 'price' => 580],
            ['size' => 'L', 'price' => 600],
            ['size' => 'XL', 'price' => 650],
            ['size' => 'XXL', 'price' => 700],
            ['size' => '3XL', 'price' => 750],
        ]
    ],
    [
        'id' => 'U-002', 'category' => 'Underwear', 'name' => 'Bikinis (Underwear)', 'segment' => 'Export', 'image' => 'daily_luxe.png',
        'desc' => 'Elegant bikinis with a low-rise fit and soft lace side panels, designed for a chic look and daily comfort.',
        'sizes' => [
            ['size' => 'S', 'price' => 1050],
            ['size' => 'M', 'price' => 1100],
            ['size' => 'L', 'price' => 1150],
            ['size' => 'XL', 'price' => 1200],
        ]
    ],
    [
        'id' => 'U-003', 'category' => 'Underwear', 'name' => 'Thongs / G-strings', 'segment' => 'Import', 'image' => 'daily_luxe.png',
        'desc' => 'Ultra-fine stretch silk thong featuring minimal coverage and a seamless waistband, invisible under tight clothes.',
        'sizes' => [
            ['size' => 'S', 'price' => 1650],
            ['size' => 'M', 'price' => 1700],
            ['size' => 'L', 'price' => 1800],
        ]
    ],
    [
        'id' => 'U-004', 'category' => 'Underwear', 'name' => 'Boyshorts', 'segment' => 'Export', 'image' => 'daily_luxe.png',
        'desc' => 'Full-coverage boyshorts with a smooth waistband, perfect for activewear and lounging.',
        'sizes' => [
            ['size' => 'S', 'price' => 1150],
            ['size' => 'M', 'price' => 1200],
            ['size' => 'L', 'price' => 1250],
            ['size' => 'XL', 'price' => 1300],
        ]
    ],
    [
        'id' => 'U-005', 'category' => 'Underwear', 'name' => 'Hipsters', 'segment' => 'Local', 'image' => 'daily_luxe.png',
        'desc' => 'Comfortable hipster panties with soft elastic flat seams, offering a smooth finish.',
        'sizes' => [
            ['size' => 'S', 'price' => 700],
            ['size' => 'M', 'price' => 730],
            ['size' => 'L', 'price' => 750],
            ['size' => 'XL', 'price' => 800],
            ['size' => 'XXL', 'price' => 850],
        ]
    ],
    [
        'id' => 'U-006', 'category' => 'Underwear', 'name' => 'High-Waisted Panties', 'segment' => 'Export', 'image' => 'daily_luxe.png',
        'desc' => 'High-rise fit panties with light tummy control and delicate lace details.',
        'sizes' => [
            ['size' => 'M', 'price' => 1250],
            ['size' => 'L', 'price' => 1300],
            ['size' => 'XL', 'price' => 1350],
            ['size' => 'XXL', 'price' => 1400],
            ['size' => '3XL', 'price' => 1500],
        ]
    ],
    [
        'id' => 'U-007', 'category' => 'Underwear', 'name' => 'Seamless Underwear', 'segment' => 'Import', 'image' => 'daily_luxe.png',
        'desc' => 'Laser-cut seamless underwear made from smooth micro-modal fabric for a weightless feel.',
        'sizes' => [
            ['size' => 'S', 'price' => 1850],
            ['size' => 'M', 'price' => 1900],
            ['size' => 'L', 'price' => 2000],
            ['size' => 'XL', 'price' => 2100],
        ]
    ],
    [
        'id' => 'U-008', 'category' => 'Underwear', 'name' => 'Control Briefs', 'segment' => 'Export', 'image' => 'daily_luxe.png',
        'desc' => 'Firm tummy-control shaping briefs with high-stretch elastane for a contoured silhouette.',
        'sizes' => [
            ['size' => 'M', 'price' => 2300],
            ['size' => 'L', 'price' => 2380],
            ['size' => 'XL', 'price' => 2480],
            ['size' => 'XXL', 'price' => 2600],
            ['size' => '3XL', 'price' => 2800],
        ]
    ],
    [
        'id' => 'U-009', 'category' => 'Underwear', 'name' => 'Tangas', 'segment' => 'Import', 'image' => 'daily_luxe.png',
        'desc' => 'Beautiful lace and silk tanga panties, featuring thin side straps and a flattering cut.',
        'sizes' => [
            ['size' => 'S', 'price' => 1750],
            ['size' => 'M', 'price' => 1800],
            ['size' => 'L', 'price' => 1900],
        ]
    ],

    // INNERWEAR CATEGORY (I-001 to I-006)
    [
        'id' => 'I-001', 'category' => 'Innerwear', 'name' => 'Camisoles & Slips', 'segment' => 'Local', 'image' => 'silk_collection.png',
        'desc' => 'Lightweight everyday camisole with adjustable spaghetti straps, made of soft organic cotton.',
        'sizes' => [
            ['size' => 'S', 'price' => 900],
            ['size' => 'M', 'price' => 950],
            ['size' => 'L', 'price' => 1000],
            ['size' => 'XL', 'price' => 1080],
            ['size' => 'XXL', 'price' => 1150],
        ]
    ],
    [
        'id' => 'I-002', 'category' => 'Innerwear', 'name' => 'Chemises & Babydolls', 'segment' => 'Import', 'image' => 'silk_collection.png',
        'desc' => 'Exquisite satin babydoll slip featuring sheer lace cups and a flared georgette skirt.',
        'sizes' => [
            ['size' => 'S', 'price' => 4800],
            ['size' => 'M', 'price' => 5000],
            ['size' => 'L', 'price' => 5250],
            ['size' => 'XL', 'price' => 5600],
        ]
    ],
    [
        'id' => 'I-003', 'category' => 'Innerwear', 'name' => 'Shapewear Bodysuits', 'segment' => 'Import', 'image' => 'silk_collection.png',
        'desc' => 'All-in-one shaping bodysuit with targeted compression panels to sculpt the waist and hips.',
        'sizes' => [
            ['size' => 'S', 'price' => 6800],
            ['size' => 'M', 'price' => 7000],
            ['size' => 'L', 'price' => 7300],
            ['size' => 'XL', 'price' => 7600],
            ['size' => 'XXL', 'price' => 8100],
            ['size' => '3XL', 'price' => 8600],
        ]
    ],
    [
        'id' => 'I-004', 'category' => 'Innerwear', 'name' => 'Thermal Innerwear Top', 'segment' => 'Export', 'image' => 'silk_collection.png',
        'desc' => 'Thermal long-sleeve base layer top with heat-retention brushed fleece lining for winter comfort.',
        'sizes' => [
            ['size' => 'S', 'price' => 2100],
            ['size' => 'M', 'price' => 2180],
            ['size' => 'L', 'price' => 2280],
            ['size' => 'XL', 'price' => 2400],
        ]
    ],
    [
        'id' => 'I-005', 'category' => 'Innerwear', 'name' => 'Thermal Innerwear Bottom', 'segment' => 'Export', 'image' => 'silk_collection.png',
        'desc' => 'Thermal base layer leggings featuring flatlock seams and lightweight thermal knit.',
        'sizes' => [
            ['size' => 'S', 'price' => 2100],
            ['size' => 'M', 'price' => 2180],
            ['size' => 'L', 'price' => 2280],
            ['size' => 'XL', 'price' => 2400],
        ]
    ],
    [
        'id' => 'I-006', 'category' => 'Innerwear', 'name' => 'Slip Shorts / Anti-chafing', 'segment' => 'Local', 'image' => 'silk_collection.png',
        'desc' => 'Seamless anti-chafing slip shorts made from cool-touch modal fabric, perfect under skirts.',
        'sizes' => [
            ['size' => 'S', 'price' => 1050],
            ['size' => 'M', 'price' => 1100],
            ['size' => 'L', 'price' => 1150],
            ['size' => 'XL', 'price' => 1200],
            ['size' => 'XXL', 'price' => 1300],
        ]
    ],
];
}
$products = get_product_catalog();

// Helper to determine if we should render admin panel
$is_admin_mode = isset($_GET['admin']);
$admin_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="KACHOOLI — Premium Women's Lingerie, Panties, and Intimate Innerwear. Luxury fabrics and precision comfort. Launching soon in Bangladesh.">
    <meta name="author" content="KACHOOLI">
    <title>KACHOOLI — Premium Women's Lingerie & Loungewear | Early Access</title>

    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💎</text></svg>">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    <style>
        :root {
            --color-bg: #fcf8f3;
            --color-surface: #ffffff;
            --color-surface-alt: #f7efe7;
            --color-cream: #f8f1e8;
            --color-blush: #f6d9d1;
            --color-blush-soft: #fbe9e4;
            --color-black: #151112;
            --color-charcoal: #2c2426;
            --color-text: #2a2224;
            --color-muted: #7b6f73;
            --color-border: rgba(42, 34, 36, 0.12);
            --color-gold: #c8a46a;
            --color-gold-hover: #bfa063;
            --color-gold-soft: rgba(200, 164, 106, 0.12);
            --color-shadow: 0 18px 40px rgba(28, 18, 21, 0.08);
            --color-shadow-soft: 0 10px 24px rgba(28, 18, 21, 0.06);
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-pill: 999px;
            --font-sans: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif;
            --font-serif: 'Cormorant Garamond', 'Times New Roman', serif;
            --max-width: 1200px;
        }

        /* Basic Reset */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        html {
            scroll-behavior: smooth;
        }
        body {
            font-family: var(--font-sans);
            color: var(--color-text);
            background: radial-gradient(circle at 10% 20%, rgba(246, 217, 209, 0.3), transparent 40%),
                        radial-gradient(circle at 90% 80%, rgba(200, 164, 106, 0.1), transparent 40%),
                        var(--color-bg);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }
        .container {
            width: min(100% - 3rem, var(--max-width));
            margin-inline: auto;
        }

        /* Announcement Strip */
        .announcement-bar {
            background-color: var(--color-black);
            color: var(--color-cream);
            text-align: center;
            padding: 0.6rem 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            border-bottom: 1px solid rgba(200, 164, 106, 0.25);
            z-index: 101;
            position: relative;
        }

        /* Header */
        header {
            padding: 1.25rem 0;
            border-bottom: 1px solid rgba(42, 34, 36, 0.05);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(252, 248, 243, 0.85);
        }
        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-family: var(--font-serif);
            font-size: 1.5rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            color: var(--color-black);
            text-decoration: none;
        }
        .brand-logo {
            height: 2.2rem;
            width: auto;
            object-fit: contain;
            display: block;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .header-badge {
            background: var(--color-gold-soft);
            color: var(--color-gold);
            border: 1px solid var(--color-gold);
            padding: 0.35rem 0.75rem;
            border-radius: var(--radius-pill);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .cart-icon-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-black);
            transition: transform 0.3s ease;
        }
        .cart-icon-btn:hover {
            transform: scale(1.05);
        }
        .cart-badge-count {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--color-gold);
            color: var(--color-black);
            font-size: 0.65rem;
            font-weight: 700;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* 3D Muse Icon */
        .woman-3d-icon {
            width: 2.8rem;
            height: 2.8rem;
            border-radius: 50%;
            overflow: hidden;
            cursor: pointer;
            box-shadow: var(--color-shadow-soft);
            border: 1px solid rgba(200, 164, 106, 0.3);
            transition: transform 0.3s ease, border-color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #ffffff, var(--color-cream));
        }
        .woman-3d-icon:hover {
            transform: translateY(-2px) scale(1.05);
            border-color: var(--color-gold);
        }
        .woman-3d-icon svg {
            width: 100%;
            height: 100%;
        }

        /* VIEW STATES */
        .page-state {
            display: none;
        }
        .page-state.active-state {
            display: block;
        }

        /* Countdown/Coming Soon Hero */
        .hero {
            position: relative;
            width: 100%;
            height: 650px;
            display: flex;
            overflow: hidden;
            background: var(--color-bg);
            border-bottom: 1px solid var(--color-border);
        }
        .coming-soon-layer {
            position: relative;
            width: 42%;
            height: 100%;
            background: var(--color-bg);
            z-index: 10;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3rem 4rem 3rem 3rem;
            border-right: 1px solid var(--color-border);
            text-align: left;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.2em;
            color: var(--color-gold);
            text-transform: uppercase;
            margin-bottom: 1rem;
        }
        .eyebrow::after {
            content: "";
            width: 25px;
            height: 1px;
            background: currentColor;
            opacity: 0.5;
        }
        .hero-title {
            font-family: var(--font-serif);
            font-size: clamp(2.0rem, 4.5vw, 3.2rem);
            line-height: 1.1;
            color: var(--color-black);
            font-weight: 400;
            margin-bottom: 1.25rem;
        }
        .hero-title span {
            font-style: italic;
            color: var(--color-gold);
        }
        .hero-lead {
            max-width: 48ch;
            margin: 0 0 2.5rem 0;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            color: var(--color-muted);
            font-weight: 300;
            line-height: 1.6;
        }
        .countdown-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            max-width: 480px;
            width: 100%;
        }
        .countdown-item {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 1rem 0.5rem;
            text-align: center;
            box-shadow: var(--color-shadow-soft);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease, border-color 0.3s ease;
        }
        .countdown-item::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--color-blush), var(--color-gold));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .countdown-item:hover {
            transform: translateY(-2px);
            border-color: rgba(200, 164, 106, 0.4);
            box-shadow: var(--color-shadow);
        }
        .countdown-item:hover::before {
            opacity: 1;
        }
        .countdown-val {
            font-family: var(--font-serif);
            font-size: clamp(1.6rem, 3.5vw, 2.2rem);
            font-weight: 600;
            color: var(--color-black);
            line-height: 1.1;
            margin-bottom: 0.2rem;
        }
        .countdown-label {
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--color-gold);
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .hero-slideshow {
            position: relative;
            width: 58%;
            height: 100%;
            z-index: 1;
            background: #151112;
            cursor: grab;
        }
        .hero-slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: none;
            align-items: center;
            justify-content: flex-end;
            user-select: none;
            pointer-events: none;
        }
        .hero-slide.active, .hero-slide.exiting {
            display: flex;
            pointer-events: auto;
        }
        .hero-slide.active { z-index: 3; }
        .hero-slide.exiting { z-index: 2; }
        .hero-slide-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            filter: brightness(0.9);
            pointer-events: none;
        }
        .slide-details-container {
            position: absolute;
            right: 6%;
            bottom: 8%;
            width: 80%;
            max-width: 380px;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--color-shadow);
            z-index: 5;
            text-align: left;
            opacity: 0;
            transform: translateY(30px);
            transition: transform 1.2s cubic-bezier(0.16, 1, 0.3, 1), opacity 1.2s ease;
        }
        .hero-slide.active .slide-details-container {
            transform: translateY(0);
            opacity: 1;
        }
        .slide-badge {
            display: inline-block;
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--color-gold);
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-bottom: 0.75rem;
            border: 1px solid var(--color-gold-soft);
            padding: 0.25rem 0.6rem;
            border-radius: var(--radius-pill);
            background: var(--color-gold-soft);
        }
        .slide-title {
            font-family: var(--font-serif);
            font-size: clamp(1.6rem, 3.5vw, 2.2rem);
            color: var(--color-black);
            font-weight: 500;
            margin-bottom: 0.75rem;
            line-height: 1.2;
        }
        .slide-desc {
            color: var(--color-charcoal);
            font-size: 0.9rem;
            line-height: 1.55;
            margin-bottom: 1.5rem;
            font-weight: 400;
        }
        .btn-preorder {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: var(--color-black);
            color: #fff;
            border: 1px solid var(--color-black);
            border-radius: var(--radius-pill);
            padding: 0.8rem 1.6rem;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--color-shadow-soft);
        }
        .btn-preorder:hover {
            background: var(--color-gold);
            border-color: var(--color-gold);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(200, 164, 106, 0.25);
        }

        /* Slide Transition Classes */
        .slide-in-right { animation: slideInRight 1.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .slide-out-left { animation: slideOutLeft 1.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .slide-in-left { animation: slideInLeft 1.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .slide-out-right { animation: slideOutRight 1.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

        @keyframes slideInRight { from { transform: translateX(100%); } to { transform: translateX(0); } }
        @keyframes slideOutLeft { from { transform: translateX(0); } to { transform: translateX(-100%); } }
        @keyframes slideInLeft { from { transform: translateX(-100%); } to { transform: translateX(0); } }
        @keyframes slideOutRight { from { transform: translateX(0); } to { transform: translateX(100%); } }

        /* Teaser Showcase Cards */
        .showcase {
            padding: 5rem 0;
            border-top: 1px solid var(--color-border);
            background: rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(8px);
        }
        .section-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 3.5rem;
        }
        .section-title {
            font-family: var(--font-serif);
            font-size: clamp(2rem, 4vw, 2.8rem);
            color: var(--color-black);
            font-weight: 400;
            margin-bottom: 0.8rem;
            letter-spacing: 0.05em;
        }
        .section-desc {
            color: var(--color-muted);
            font-size: 0.98rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-weight: 600;
        }
        .teaser-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }
        .teaser-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--color-shadow-soft);
            transition: transform 0.4s cubic-bezier(0.165, 0.84, 0.44, 1), box-shadow 0.4s ease, border-color 0.4s ease;
            display: flex;
            flex-direction: column;
        }
        .teaser-card:hover {
            transform: translateY(-8px);
            border-color: rgba(200, 164, 106, 0.35);
            box-shadow: var(--color-shadow);
        }
        .teaser-media {
            position: relative;
            aspect-ratio: 4 / 5;
            overflow: hidden;
            background: linear-gradient(135deg, var(--color-cream), var(--color-blush-soft));
        }
        .teaser-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.8s ease;
        }
        .teaser-card:hover .teaser-media img {
            transform: scale(1.05);
        }
        .teaser-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid var(--color-border);
            color: var(--color-black);
            padding: 0.35rem 0.8rem;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border-radius: var(--radius-pill);
            backdrop-filter: blur(4px);
            z-index: 10;
        }
        .teaser-body {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        .teaser-title {
            font-family: var(--font-serif);
            font-size: 1.4rem;
            color: var(--color-black);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        .teaser-text {
            color: var(--color-muted);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* VIP Form Card & Section */
        .vip-section {
            padding: 6rem 0;
            background: radial-gradient(circle at center, rgba(246, 217, 209, 0.25), transparent 70%), var(--color-surface);
            border-top: 1px solid var(--color-border);
            border-bottom: 1px solid var(--color-border);
            position: relative;
        }
        .vip-card {
            max-width: 640px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(200, 164, 106, 0.25);
            border-radius: var(--radius-lg);
            padding: 3rem 2.5rem;
            box-shadow: var(--color-shadow);
            backdrop-filter: blur(12px);
            position: relative;
            overflow: hidden;
            transition: all 0.5s ease;
        }
        .vip-title {
            font-family: var(--font-serif);
            font-size: 2.2rem;
            color: var(--color-black);
            text-align: center;
            margin-bottom: 0.8rem;
            font-weight: 400;
            letter-spacing: 0.05em;
        }
        .vip-subtitle {
            color: var(--color-muted);
            text-align: center;
            font-size: 0.95rem;
            margin-bottom: 2.5rem;
            max-width: 48ch;
            margin-inline: auto;
            line-height: 1.6;
        }
        .vip-notice {
            display: block;
            margin-top: 0.5rem;
            color: var(--color-gold);
            font-weight: 700;
            font-size: 0.82rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .form-group {
            margin-bottom: 1.25rem;
            text-align: left;
        }
        .form-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--color-charcoal);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.5rem;
        }
        .form-input {
            width: 100%;
            background: var(--color-bg);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-pill);
            padding: 0.85rem 1.4rem;
            font-family: var(--font-sans);
            font-size: 0.92rem;
            color: var(--color-black);
            transition: all 0.3s ease;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--color-gold);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(200, 164, 106, 0.12);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .checkbox-container {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.84rem;
            color: var(--color-muted);
            cursor: pointer;
            user-select: none;
            margin-top: 0.5rem;
        }
        .checkbox-container input {
            margin-top: 0.2rem;
            accent-color: var(--color-gold);
        }
        .btn-submit {
            width: 100%;
            background: var(--color-black);
            color: #fff;
            border: 1px solid var(--color-black);
            border-radius: var(--radius-pill);
            padding: 1rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: var(--color-shadow-soft);
        }
        .btn-submit:hover {
            background: var(--color-gold);
            border-color: var(--color-gold);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(200, 164, 106, 0.25);
        }
        .btn-submit svg {
            width: 1rem;
            height: 1rem;
            transition: transform 0.3s ease;
        }
        .btn-submit:hover svg {
            transform: translateX(3px);
        }

        /* Simulated OTP Modal styling */
        .otp-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(21, 17, 18, 0.6);
            backdrop-filter: blur(8px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .otp-modal {
            background: var(--color-surface);
            border: 1px solid var(--color-gold);
            border-radius: var(--radius-lg);
            padding: 3rem;
            max-width: 480px;
            width: calc(100% - 2rem);
            text-align: center;
            box-shadow: var(--color-shadow);
            animation: scaleIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) both;
        }
        .otp-title {
            font-family: var(--font-serif);
            font-size: 1.8rem;
            color: var(--color-black);
            margin-bottom: 0.5rem;
        }
        .otp-subtitle {
            font-size: 0.9rem;
            color: var(--color-muted);
            margin-bottom: 2rem;
        }
        .otp-code-box {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }
        .otp-input-field {
            width: 3.5rem;
            height: 4rem;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 1.8rem;
            font-weight: 700;
            text-align: center;
            background: var(--color-bg);
            color: var(--color-black);
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .otp-input-field:focus {
            border-color: var(--color-gold);
            box-shadow: 0 0 0 4px rgba(200, 164, 106, 0.12);
        }
        .otp-simulated-notification {
            background: var(--color-gold-soft);
            border: 1px dashed var(--color-gold);
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            color: var(--color-charcoal);
            margin-bottom: 1.5rem;
            line-height: 1.4;
            font-weight: 500;
        }

        /* Success VIP State */
        .vip-success-container {
            display: none;
            text-align: center;
            padding: 1rem 0;
            animation: scaleIn 0.5s ease both;
        }
        .success-seal {
            width: 5rem;
            height: 5rem;
            background: rgba(200, 164, 106, 0.12);
            border: 1px solid var(--color-gold);
            color: var(--color-gold);
            border-radius: 50%;
            display: grid;
            place-items: center;
            margin: 0 auto 1.5rem;
            box-shadow: var(--color-shadow-soft);
        }
        .success-title {
            font-family: var(--font-serif);
            font-size: 2.2rem;
            color: var(--color-black);
            margin-bottom: 0.75rem;
        }
        .success-message {
            color: var(--color-muted);
            font-size: 0.98rem;
            line-height: 1.6;
            margin-bottom: 2rem;
            max-width: 44ch;
            margin-inline: auto;
        }
        .vip-details-box {
            background: var(--color-cream);
            border: 1px dashed var(--color-gold);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            display: inline-block;
            text-align: left;
            margin-bottom: 2rem;
        }
        .vip-detail-row {
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            color: var(--color-charcoal);
        }
        .vip-detail-row strong { font-weight: 600; }
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: transparent;
            border: 1px solid rgba(42, 34, 36, 0.15);
            color: var(--color-charcoal);
            border-radius: var(--radius-pill);
            padding: 0.75rem 1.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            border-color: var(--color-black);
            background: rgba(42, 34, 36, 0.02);
        }

        /* ---------------- EARLY ACCESS SHOP STATE ---------------- */
        .shop-hero {
            background: linear-gradient(rgba(21,17,18,0.7), rgba(21,17,18,0.7)), url('bridal_edit.png') no-repeat center center;
            background-size: cover;
            color: #fff;
            text-align: center;
            padding: 6rem 0 5rem;
            border-bottom: 1px solid var(--color-border);
        }
        .shop-hero-title {
            font-family: var(--font-serif);
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 400;
            margin-bottom: 1rem;
            letter-spacing: 0.05em;
        }
        .shop-hero-subtitle {
            color: var(--color-blush);
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.25em;
            text-transform: uppercase;
        }

        /* Interactive Discount Bar */
        .discount-status-banner {
            background: var(--color-black);
            border-bottom: 1px solid rgba(200, 164, 106, 0.2);
            color: var(--color-cream);
            padding: 1rem 0;
            font-size: 0.85rem;
            text-align: center;
        }
        .discount-inner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--color-gold-soft);
            color: var(--color-gold);
            border: 1px solid var(--color-gold);
            padding: 0.3rem 0.8rem;
            border-radius: var(--radius-pill);
            font-weight: 700;
            font-size: 0.78rem;
            text-transform: uppercase;
        }
        .discount-tasks-box {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .task-checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.78rem;
            cursor: pointer;
            color: var(--color-muted);
            transition: color 0.3s;
        }
        .task-checkbox-label.completed {
            color: var(--color-cream);
        }
        .task-checkbox-label input {
            accent-color: var(--color-gold);
        }

        /* Catalog Layout */
        .catalog-container {
            padding: 4rem 0 6rem;
        }
        .catalog-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 3rem;
            gap: 1.5rem;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--color-border);
            padding-bottom: 1.5rem;
        }
        .category-filters {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .btn-filter {
            background: transparent;
            border: 1px solid rgba(42, 34, 36, 0.1);
            color: var(--color-charcoal);
            padding: 0.6rem 1.25rem;
            border-radius: var(--radius-pill);
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
        }
        .btn-filter:hover, .btn-filter.active {
            background: var(--color-black);
            color: #fff;
            border-color: var(--color-black);
        }
        .sort-select {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-pill);
            padding: 0.6rem 2rem 0.6rem 1.25rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--color-charcoal);
            outline: none;
            cursor: pointer;
            appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2210%22 height=%226%22><path d=%22M0 0h10L5 6z%22 fill=%22%237b6f73%22/></svg>');
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 10px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 2.5rem 2rem;
        }

        /* Product Card */
        .product-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--color-shadow-soft);
            transition: transform 0.4s ease, box-shadow 0.4s ease, border-color 0.4s ease;
            display: flex;
            flex-direction: column;
            position: relative;
            text-align: left;
        }
        .product-card:hover {
            transform: translateY(-6px);
            border-color: rgba(200, 164, 106, 0.3);
            box-shadow: var(--color-shadow);
        }
        .segment-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            padding: 0.3rem 0.7rem;
            border-radius: var(--radius-pill);
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            z-index: 10;
            backdrop-filter: blur(4px);
        }
        .segment-badge.import {
            background: rgba(21, 17, 18, 0.85);
            color: var(--color-gold);
            border: 1px solid var(--color-gold);
        }
        .segment-badge.export {
            background: rgba(255, 255, 255, 0.9);
            color: #4a5d6e;
            border: 1px solid rgba(74, 93, 110, 0.2);
        }
        .segment-badge.local {
            background: rgba(255, 255, 255, 0.9);
            color: var(--color-muted);
            border: 1px solid var(--color-border);
        }

        .product-media {
            aspect-ratio: 1 / 1.1;
            overflow: hidden;
            position: relative;
            background: linear-gradient(135deg, var(--color-cream), var(--color-blush-soft));
        }
        .product-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.8s ease;
        }
        .product-card:hover .product-media img {
            transform: scale(1.05);
        }

        .product-info {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        .product-name-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
        }
        .product-name {
            font-family: var(--font-serif);
            font-size: 1.25rem;
            color: var(--color-black);
            font-weight: 500;
        }
        .product-id-tag {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--color-muted);
            border: 1px solid var(--color-border);
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            background: var(--color-bg);
        }
        .product-desc {
            font-size: 0.82rem;
            color: var(--color-muted);
            line-height: 1.5;
            margin-bottom: 1.25rem;
            min-height: 3rem;
        }
        .size-selector-row {
            margin-bottom: 1.5rem;
        }
        .price-row {
            display: flex;
            align-items: baseline;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
        }
        .price-original {
            text-decoration: line-through;
            color: var(--color-muted);
            font-size: 0.88rem;
        }
        .price-discounted {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--color-gold);
        }
        .discount-pct {
            font-size: 0.72rem;
            font-weight: 700;
            background: var(--color-gold-soft);
            color: var(--color-gold);
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
        }

        .btn-add-cart {
            width: 100%;
            background: var(--color-black);
            color: #fff;
            border: 1px solid var(--color-black);
            border-radius: var(--radius-pill);
            padding: 0.75rem;
            font-weight: 600;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .btn-add-cart:hover {
            background: var(--color-gold);
            border-color: var(--color-gold);
            box-shadow: 0 4px 12px rgba(200, 164, 106, 0.2);
        }

        /* ---------------- SHOPPING CART DRAWER ---------------- */
        .cart-drawer-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(21,17,18,0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
        }
        .cart-drawer {
            position: fixed;
            top: 0;
            right: -460px;
            width: 100%;
            max-width: 440px;
            height: 100%;
            background: var(--color-surface);
            box-shadow: -10px 0 40px rgba(21,17,18,0.15);
            z-index: 1001;
            transition: right 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
        }
        .cart-drawer.open {
            right: 0;
        }
        .cart-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--color-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cart-title {
            font-family: var(--font-serif);
            font-size: 1.5rem;
            color: var(--color-black);
            font-weight: 500;
        }
        .btn-close-cart {
            background: transparent;
            border: none;
            cursor: pointer;
            color: var(--color-muted);
            padding: 0.5rem;
        }
        .cart-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex-grow: 1;
        }
        .cart-empty-message {
            text-align: center;
            color: var(--color-muted);
            padding: 3rem 0;
            font-size: 0.95rem;
        }
        .cart-items-list {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .cart-item {
            display: flex;
            gap: 1rem;
            align-items: center;
            border-bottom: 1px solid rgba(42,34,36,0.05);
            padding-bottom: 1rem;
        }
        .cart-item-img {
            width: 4rem;
            height: 4.5rem;
            border-radius: var(--radius-sm);
            object-fit: cover;
            background: var(--color-cream);
        }
        .cart-item-info {
            flex-grow: 1;
            text-align: left;
        }
        .cart-item-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--color-black);
            margin-bottom: 0.2rem;
        }
        .cart-item-meta {
            font-size: 0.75rem;
            color: var(--color-muted);
            margin-bottom: 0.4rem;
        }
        .cart-qty-ctrl {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .btn-qty {
            background: var(--color-cream);
            border: none;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cart-item-qty-val {
            font-size: 0.8rem;
            font-weight: 600;
        }
        .cart-item-price {
            font-weight: 700;
            color: var(--color-gold);
            font-size: 0.95rem;
            text-align: right;
            min-width: 70px;
        }

        /* Checkout Form in Cart */
        .cart-checkout-form {
            border-top: 1px solid var(--color-border);
            padding-top: 1.5rem;
            margin-top: 1.5rem;
            text-align: left;
        }
        .checkout-title {
            font-family: var(--font-serif);
            font-size: 1.25rem;
            color: var(--color-black);
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .cart-totals {
            background: var(--color-cream);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.88rem;
            color: var(--color-charcoal);
        }
        .total-row.grand-total {
            border-top: 1px solid rgba(42,34,36,0.1);
            padding-top: 0.5rem;
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--color-black);
        }

        /* ---------------- ADMIN STATE (ADMIN PORTAL) ---------------- */
        .admin-section {
            padding: 5rem 0 7rem;
        }
        .admin-login-card {
            max-width: 420px;
            margin: 0 auto;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 3rem 2.5rem;
            box-shadow: var(--color-shadow);
            text-align: center;
        }
        .admin-dashboard {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 3rem 2.5rem;
            box-shadow: var(--color-shadow);
            text-align: left;
        }
        .admin-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            border-bottom: 1px solid var(--color-border);
            padding-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .admin-title {
            font-family: var(--font-serif);
            font-size: 2.2rem;
            color: var(--color-black);
            font-weight: 400;
        }
        .admin-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        .stat-card {
            background: var(--color-bg);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            text-align: center;
        }
        .stat-val {
            font-family: var(--font-serif);
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--color-gold);
            line-height: 1.1;
            margin-bottom: 0.3rem;
        }
        .stat-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--color-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .admin-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .btn-tab {
            background: transparent;
            border: none;
            padding: 0.6rem 1.25rem;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--color-muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .btn-tab.active {
            color: var(--color-black);
            border-bottom-color: var(--color-gold);
        }
        .admin-tab-content {
            display: none;
        }
        .admin-tab-content.active-content {
            display: block;
        }
        .table-responsive {
            overflow-x: auto;
            margin-bottom: 1.5rem;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .admin-table th, .admin-table td {
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--color-border);
            text-align: left;
        }
        .admin-table th {
            background: var(--color-bg);
            font-weight: 700;
            color: var(--color-charcoal);
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.05em;
        }
        .admin-table tr:last-child td {
            border-bottom: none;
        }
        .admin-table tr:hover td {
            background: rgba(200, 164, 106, 0.03);
        }
        .admin-actions-bar {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        /* ---------------- BLUEPRINT DRAWER ---------------- */
        .blueprint-section {
            background: var(--color-cream);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 3rem 2.5rem;
            margin-top: 4rem;
            text-align: left;
        }
        .blueprint-title {
            font-family: var(--font-serif);
            font-size: 1.8rem;
            color: var(--color-black);
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(42,34,36,0.1);
            padding-bottom: 0.75rem;
        }
        .blueprint-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .blueprint-card h4 {
            font-family: var(--font-serif);
            font-size: 1.3rem;
            margin-bottom: 0.75rem;
            color: var(--color-black);
        }
        .blueprint-card p {
            font-size: 0.88rem;
            color: var(--color-muted);
            margin-bottom: 1.25rem;
        }
        .code-block {
            background: var(--color-black);
            color: #a9b2c3;
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            font-family: monospace;
            font-size: 0.8rem;
            overflow-x: auto;
            white-space: pre;
            margin-bottom: 1rem;
            border: 1px solid rgba(255,255,255,0.1);
        }

        /* ---------------- FOOTER & VALUES ---------------- */
        .values-section {
            padding: 5rem 0;
            background: var(--color-bg);
            border-top: 1px solid var(--color-border);
        }
        .values-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }
        .value-card {
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid rgba(42, 34, 36, 0.05);
            border-radius: var(--radius-lg);
            padding: 2.5rem 1.75rem;
            text-align: center;
            transition: transform 0.3s ease, background 0.3s ease;
        }
        .value-card:hover {
            transform: translateY(-4px);
            background: #fff;
            border-color: var(--color-border);
        }
        .value-icon {
            width: 3.5rem;
            height: 3.5rem;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 50%;
            display: grid;
            place-items: center;
            margin: 0 auto 1.5rem;
            color: var(--color-gold);
            box-shadow: var(--color-shadow-soft);
        }
        .value-title {
            font-family: var(--font-serif);
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--color-black);
            margin-bottom: 0.6rem;
        }
        .value-text {
            color: var(--color-muted);
            font-size: 0.88rem;
            line-height: 1.55;
        }

        /* Floating WhatsApp Button */
        .whatsapp-float {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background-color: #25d366;
            color: white;
            border-radius: 50px;
            padding: 0.8rem 1.4rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            box-shadow: 0 10px 25px rgba(37, 211, 102, 0.3);
            z-index: 999;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: pulseWhatsApp 2s infinite;
            text-decoration: none;
        }
        .whatsapp-float:hover {
            transform: scale(1.04) translateY(-2px);
            background-color: #20ba59;
        }
        .whatsapp-icon {
            width: 1.2rem;
            height: 1.2rem;
            fill: currentColor;
        }

        footer {
            margin-top: auto;
            background: var(--color-black);
            color: var(--color-cream);
            padding: 4rem 0 2rem;
            font-size: 0.9rem;
            border-top: 1px solid rgba(200, 164, 106, 0.2);
        }
        .footer-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
            text-align: center;
        }
        .footer-brand {
            font-family: var(--font-serif);
            font-size: 1.8rem;
            color: #fff;
            letter-spacing: 0.05em;
        }
        .footer-nav {
            display: flex;
            gap: 2rem;
            list-style: none;
        }
        .footer-link {
            color: var(--color-muted);
            transition: color 0.3s ease;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            text-decoration: none;
        }
        .footer-link:hover {
            color: var(--color-gold);
        }
        .copyright {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            width: 100%;
            font-size: 0.78rem;
            color: var(--color-muted);
            letter-spacing: 0.04em;
        }
        .copyright a {
            color: var(--color-gold);
            text-decoration: underline;
        }

        /* 3D QR Code Simulation Modal */
        .qr-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(21,17,18,0.6);
            backdrop-filter: blur(6px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .qr-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            max-width: 400px;
            text-align: center;
            box-shadow: var(--color-shadow);
        }
        .qr-svg-holder {
            margin: 1.5rem auto;
            width: 200px;
            height: 200px;
            border: 1px solid var(--color-border);
            padding: 10px;
            border-radius: 8px;
        }

        /* Animations */
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        @keyframes pulseWhatsApp {
            0% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.5); }
            70% { box-shadow: 0 0 0 15px rgba(37, 211, 102, 0); }
            100% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); }
        }

        /* Responsive Mobile Layout adjustments */
        @media (max-width: 1024px) {
            .coming-soon-layer { width: 45%; padding: 2.5rem 2rem; }
            .hero-slideshow { width: 55%; }
        }
        @media (max-width: 768px) {
            .hero { display: block; height: 100vh; min-height: 700px; }
            .coming-soon-layer {
                position: absolute; top: 1rem; left: 1rem; right: 1rem;
                width: calc(100% - 2rem); height: auto; padding: 1.25rem;
                background: rgba(252, 248, 243, 0.9); backdrop-filter: blur(16px);
                border: 1px solid rgba(255,255,255,0.6); border-radius: var(--radius-md);
                box-shadow: var(--color-shadow); text-align: center;
            }
            .hero-title { font-size: clamp(1.4rem, 4.5vw, 1.8rem); margin-bottom: 0.5rem; text-align: center; }
            .hero-lead { display: none; }
            .eyebrow { font-size: 0.65rem; margin-bottom: 0.4rem; justify-content: center; }
            .eyebrow::after { display: none; }
            .countdown-container { max-width: 100%; gap: 0.5rem; }
            .countdown-item { padding: 0.5rem 0.25rem; }
            .countdown-val { font-size: 1.2rem; }
            .countdown-label { font-size: 0.55rem; }
            .hero-slideshow { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
            .slide-details-container {
                position: absolute; bottom: 1.5rem; left: 1rem; right: 1rem;
                width: calc(100% - 2rem); max-width: none; padding: 1.25rem;
                background: rgba(255, 255, 255, 0.92);
            }
            .teaser-grid { grid-template-columns: 1fr; max-width: 420px; margin: 0 auto; }
            .values-grid { grid-template-columns: 1fr; max-width: 500px; margin: 0 auto; }
            .whatsapp-float span { display: none; }
        }
    </style>
</head>
<body>

    <!-- Top Announcement Strip -->
    <div class="announcement-bar">
        ✨ Priority launch list is open. Sign up below for VIP Early Access & WhatsApp order routing.
    </div>

    <!-- Elegant Header -->
    <header>
        <div class="container header-inner">
            <a href="index.php" class="brand">
                <img src="Kachooli.png" alt="KACHOOLI" class="brand-logo" onerror="this.style.display='none';">
                <span>KACHOOLI</span>
            </a>
            <div class="header-right">
                <!-- Session Badge indicator -->
                <div id="session-vip-badge" class="header-badge" style="display:none;">VIP MEMBER</div>
                
                <!-- Cart Button -->
                <button class="cart-icon-btn" onclick="toggleCartDrawer(true)" title="Shopping Cart">
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                    <span id="cart-badge" class="cart-badge-count">0</span>
                </button>

                <!-- Muse Icon & Admin Redirect -->
                <div class="woman-3d-icon" onclick="window.location.href='index.php?admin=1'" title="Admin Dashboard Access">
                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="womanGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#fbe9e4" />
                                <stop offset="35%" stop-color="#f6d9d1" />
                                <stop offset="70%" stop-color="#c8a46a" />
                                <stop offset="100%" stop-color="#151112" />
                            </linearGradient>
                        </defs>
                        <circle cx="50" cy="50" r="45" fill="url(#womanGrad)" />
                        <path d="M50 24c-7.18 0-13 5.82-13 13s5.82 13 13 13 13-5.82 13-13-5.82-13-13-13zm-26 44c0-12.15 9.85-22 22-22h8c12.15 0 22 9.85 22 22v2H24v-2z" fill="#ffffff" opacity="0.9" />
                    </svg>
                </div>
            </div>
        </div>
    </header>

    <?php if ($is_admin_mode): ?>
    <!-- ----------------- ADMIN DASHBOARD VIEW STATE ----------------- -->
    <main class="container admin-section">
        <?php if (!$admin_logged_in): ?>
            <!-- Admin Login Screen -->
            <div class="admin-login-card">
                <h3 class="vip-title" style="margin-bottom: 0.50rem;">Admin Portal</h3>
                <p class="vip-subtitle" style="margin-bottom: 2rem;">Please authenticate using system credentials.</p>
                
                <form id="admin-login-form" onsubmit="handleAdminLogin(event)">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" id="admin_username" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" id="admin_password" class="form-input" required>
                    </div>
                    <button type="submit" class="btn-submit">Authenticate</button>
                </form>
            </div>
        <?php else: 
            // Query Database Stats
            $db = get_db_connection();
            $total_vip = $db->query("SELECT COUNT(*) FROM vip_registrations")->fetchColumn();
            $verified_vip = $db->query("SELECT COUNT(*) FROM vip_registrations WHERE is_verified = 1")->fetchColumn();
            $total_orders = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $revenue = $db->query("SELECT SUM(total_amount) FROM orders")->fetchColumn() ?: 0;
            
            $registrations = $db->query("SELECT * FROM vip_registrations ORDER BY id DESC LIMIT 50")->fetchAll();
            $orders = $db->query("SELECT * FROM orders ORDER BY id DESC LIMIT 50")->fetchAll();
        ?>
            <!-- Admin Dashboard Panel -->
            <div class="admin-dashboard">
                <div class="admin-header-row">
                    <div>
                        <h2 class="admin-title">KACHOOLI HQ Dashboard</h2>
                        <p style="font-size:0.85rem; color:var(--color-muted);">System status, orders, and VIP registrants</p>
                    </div>
                    <div>
                        <a href="index.php?admin_logout=1" class="btn-secondary" style="font-size:0.75rem;">Logout HQ</a>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="admin-stats-grid">
                    <div class="stat-card">
                        <div class="stat-val"><?php echo $total_vip; ?></div>
                        <div class="stat-label">Total VIP Signups</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val"><?php echo $verified_vip; ?></div>
                        <div class="stat-label">Verified Members</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val"><?php echo $total_orders; ?></div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val"><?php echo number_format($revenue); ?> BDT</div>
                        <div class="stat-label">Total Revenue</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="admin-tabs">
                    <button class="btn-tab active" onclick="switchAdminTab('orders-tab', this)">Orders List</button>
                    <button class="btn-tab" onclick="switchAdminTab('vip-tab', this)">VIP Registrants</button>
                    <button class="btn-tab" onclick="switchAdminTab('blueprints-tab', this)">Blueprints & Integrations</button>
                </div>

                <!-- Tab 1: Orders -->
                <div id="orders-tab" class="admin-tab-content active-content">
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Phone / WhatsApp</th>
                                    <th>Location</th>
                                    <th>Totals</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr><td colspan="7" style="text-align:center;">No orders placed yet.</td></tr>
                                <?php else: foreach ($orders as $ord): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($ord['order_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($ord['customer_name']); ?><br><span style="font-size:0.75rem; color:var(--color-muted);"><?php echo htmlspecialchars($ord['customer_email']); ?></span></td>
                                        <td><?php echo htmlspecialchars($ord['customer_phone']); ?></td>
                                        <td><?php echo htmlspecialchars($ord['billing_city']); ?></td>
                                        <td>
                                            Total: <strong><?php echo number_format($ord['total_amount']); ?> BDT</strong><br>
                                            <span style="font-size:0.75rem; color:var(--color-muted);">Disc: -<?php echo number_format($ord['discount_amount']); ?></span>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($ord['created_at'])); ?></td>
                                        <td>
                                            <!-- Pre-compiled WhatsApp API Trigger to recheck with customer -->
                                            <a href="https://api.whatsapp.com/send?phone=<?php echo urlencode($ord['customer_phone']); ?>&text=Hi%20<?php echo urlencode($ord['customer_name']); ?>!%20This%20is%20Kachooli%20concierge%20confirming%20order%20<?php echo urlencode($ord['order_number']); ?>." target="_blank" class="btn-secondary" style="font-size: 0.72rem; padding: 0.35rem 0.75rem;">Contact Customer</a>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="admin-actions-bar">
                        <a href="index.php?export=orders" class="btn-secondary">Export Orders CSV</a>
                    </div>
                </div>

                <!-- Tab 2: VIP Registrants -->
                <div id="vip-tab" class="admin-tab-content">
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Pass ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>WhatsApp</th>
                                    <th>Status</th>
                                    <th>Fingerprints (Email / WA)</th>
                                    <th>Registered At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($registrations)): ?>
                                    <tr><td colspan="7" style="text-align:center;">No VIP registrants.</td></tr>
                                <?php else: foreach ($registrations as $v): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($v['access_pass']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($v['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($v['email']); ?></td>
                                        <td><?php echo htmlspecialchars($v['whatsapp']); ?></td>
                                        <td>
                                            <?php if ($v['is_verified']): ?>
                                                <span class="status-badge" style="background:#d4edda; color:#155724; border-color:#c3e6cb;">Verified</span>
                                            <?php else: ?>
                                                <span class="status-badge" style="background:#f8d7da; color:#721c24; border-color:#f5c6cb;">Unverified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-size:0.7rem; display:block; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($v['email_fingerprint']); ?>"><?php echo htmlspecialchars($v['email_fingerprint']); ?></span>
                                            <span style="font-size:0.7rem; display:block; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($v['whatsapp_fingerprint']); ?>"><?php echo htmlspecialchars($v['whatsapp_fingerprint']); ?></span>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($v['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="admin-actions-bar">
                        <a href="index.php?export=vip" class="btn-secondary">Export VIP CSV</a>
                    </div>
                </div>

                <!-- Tab 3: Blueprints -->
                <div id="blueprints-tab" class="admin-tab-content">
                    <div class="blueprint-card">
                        <h4>Cloudflare Static Asset Management Blueprint</h4>
                        <p>Configure Cloudflare CDN and R2 Bucket to serve product images (G_1.png to G_26.png) with 100% caching efficiency. This reduces shared host bandwidth limits completely.</p>
                        <div class="code-block"># Cloudflare R2 Upload & Cache Configuration
1. Initialize a Cloudflare R2 Bucket named 'kachooli-catalog-assets'.
2. Set Custom Domain mapping (e.g. assets.kachooli.com).
3. Set cache-control headers on R2 assets:
   "Cache-Control: public, max-age=31536000, immutable"
4. Create Cloudflare Page Rule targeting:
   "assets.kachooli.com/*" -> Cache Level: "Cache Everything", Edge Cache TTL: "1 Month"</div>
                    </div>

                    <div class="blueprint-card">
                        <h4>Google Sheets VIP synchronization Apps Script Webhook</h4>
                        <p>Sync registrations database records instantly to a shared Google Workspace spreadsheet for business analysis using a simple Apps Script. Add this Apps Script in your spreadsheet extension editor.</p>
                        <div class="code-block">/* Google Apps Script Spreadsheet Sync Receiver */
function doPost(e) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  var params = JSON.parse(e.postData.contents);
  
  // Append fields: Name, Email, WhatsApp, Access Pass, Timestamp
  sheet.appendRow([
    new Date(),
    params.name,
    params.email,
    params.whatsapp,
    params.access_pass,
    params.is_verified ? "Verified" : "Pending"
  ]);
  
  return ContentService.createTextOutput(JSON.stringify({status: "success"}))
                       .setMimeType(ContentService.MimeType.JSON);
}</div>
                    </div>

                    <div class="blueprint-card">
                        <h4>Affiliate Referral Tracking Blueprint</h4>
                        <p>Trace social affiliate leads using cookie parameters. Append affiliate code checks and store cookie parameters upon user entrance.</p>
                        <div class="code-block">// PHP Code snippet to store affiliate source cookie (Expires in 30 days)
if (isset($_GET['ref'])) {
    $ref_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['ref']);
    setcookie('kachooli_affiliate_id', $ref_id, time() + (86400 * 30), "/");
    $_SESSION['affiliate_ref'] = $ref_id;
}</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php else: ?>
    <!-- ----------------- STANDALONE USER FRONTEND VIEW STATE ----------------- -->

    <!-- State 1: Coming Soon Landing View -->
    <main id="state-landing" class="page-state active-state">
        <section class="hero" id="hero-carousel-section">
            <div class="hero-slideshow" id="hero-slideshow">
                <!-- Slides injected dynamically by JS -->
            </div>
            
            <div class="coming-soon-layer">
                <div class="eyebrow">Coming Soon - Launching Exclusive Collection</div>
                <h1 class="hero-title">Elevating the Art of <br><span>Intimate Luxury</span></h1>
                <p class="hero-lead">
                    Redefining comfort, support, and elegance. Discover a bespoke collection of fine lingerie, silk loungewear, and premium everyday innerwear, crafted to feel like a second skin.
                </p>

                <!-- Countdown -->
                <div class="countdown-container" id="countdown">
                    <div class="countdown-item">
                        <div class="countdown-val" id="days">00</div>
                        <div class="countdown-label">Days</div>
                    </div>
                    <div class="countdown-item">
                        <div class="countdown-val" id="hours">24</div>
                        <div class="countdown-label">H</div>
                    </div>
                    <div class="countdown-item">
                        <div class="countdown-val" id="mins">30</div>
                        <div class="countdown-label">Minute</div>
                    </div>
                    <div class="countdown-item">
                        <div class="countdown-val" id="secs">00</div>
                        <div class="countdown-label">Seconds</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Teasers Showcase -->
        <section class="showcase">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">SNEAK PEEK THE COLLECTION</h2>
                    <p class="section-desc">A BRIEF PREVIEW OF THE UPCOMING DESIGNS</p>
                </div>

                <div class="teaser-grid">
                    <div class="teaser-card">
                        <div class="teaser-media">
                            <span class="teaser-badge">Best Seller No. 1</span>
                            <img src="aurelia_lace_bra.png" alt="Aurelia French Lace Bra" onerror="this.src='daily_luxe.png';">
                        </div>
                        <div class="teaser-body">
                            <h3 class="teaser-title">No. 3: Push-Up Bra</h3>
                            <p class="teaser-text">Handcrafted from delicate French Chantilly lace and lined with 100% pure organic mulberry silk. Features a supportive gel underwire and satin-cushioned straps.</p>
                        </div>
                    </div>

                    <div class="teaser-card">
                        <div class="teaser-media">
                            <span class="teaser-badge">Wireless Excellence</span>
                            <img src="cloudsoft_wireless_bra.png" alt="CloudSoft Wireless Bra" onerror="this.src='daily_luxe.png';">
                        </div>
                        <div class="teaser-body">
                            <h3 class="teaser-title">No. 8: Wireless Bra</h3>
                            <p class="teaser-text">Engineered from ultra-fine Italian micro-modal fabric for a weightless, invisible feel under clothing. Designed without wires to offer natural lift and seamless support.</p>
                        </div>
                    </div>

                    <div class="teaser-card">
                        <div class="teaser-media">
                            <span class="teaser-badge">Premium Collection</span>
                            <img src="verona_plunge_bralette.png" alt="Verona Plunge Lace Bralette" onerror="this.src='daily_luxe.png';">
                        </div>
                        <div class="teaser-body">
                            <h3 class="teaser-title">No. 26: Peep/Cut-out Bra</h3>
                            <p class="teaser-text">An exquisite plunge bralette combining premium floral lace overlays with a soft, wide elastic underband. Finished with adjustable silk straps for a custom fit.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- VIP Sign-up Form Section -->
        <section class="vip-section" id="rsvp">
            <div class="container">
                <div class="vip-card">
                    <h2 class="vip-title">JOIN THE VIP CIRCLE</h2>
                    <p class="vip-subtitle">
                        Unlock the exclusive early access storefront, priority reservation order list, and verified loyalty rewards instantly.
                        <span class="vip-notice">Email and WhatsApp authentication required.</span>
                    </p>

                    <form id="vip-register-form" onsubmit="handleVIPRegister(event)">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="fullname" class="form-label">Full Name</label>
                                <input type="text" id="fullname" class="form-input" placeholder="e.g., Sarah Khan" required>
                            </div>
                            <div class="form-group">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" id="email" class="form-input" placeholder="e.g., sarah@gmail.com" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="whatsapp" class="form-label">WhatsApp Number</label>
                            <input type="tel" id="whatsapp" class="form-input" placeholder="e.g., +880 1712-345678" required>
                            <label class="checkbox-container">
                                <input type="checkbox" id="whatsapp-updates" checked>
                                Send early-access order links and style guides to my WhatsApp number
                            </label>
                        </div>
                        <div class="form-group">
                            <label for="bra-preference" class="form-label">Pre-order Bra Preference (Optional)</label>
                            <select id="bra-preference" class="form-input" style="appearance: none; background-image: url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2210%22 height=%226%22><path d=%22M0 0h10L5 6z%22 fill=%22%237b6f73%22/></svg>'); background-repeat: no-repeat; background-position: right 1.25rem center; background-size: 10px;">
                                <option value="" disabled selected>Select your preferred bra type</option>
                                <?php foreach ($products as $p): if ($p['category'] === 'Bra/Lingerie'): ?>
                                    <option value="<?php echo htmlspecialchars($p['name']); ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn-submit" id="btn-vip-submit">
                            <span>Register & Verify Access</span>
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <!-- State 2: Unlocked Early Access Shop View -->
    <main id="state-shop" class="page-state">
        <section class="shop-hero">
            <div class="container">
                <span class="shop-hero-subtitle">Bespoke Collection Storefront</span>
                <h1 class="shop-hero-title">Early Access Shop</h1>
                <p style="font-size: 1.05rem; font-weight:300; max-width:600px; margin:0 auto; opacity:0.9;">
                    Welcome to the premium reservation catalog. Select sizes to view correct campaign pricing and check out directly via WhatsApp.
                </p>
            </div>
        </section>

        <!-- Interactive Discount Bar -->
        <div class="discount-status-banner">
            <div class="container discount-inner">
                <div>
                    Active Discount Tier: 
                    <span id="active-discount-badge" class="status-badge">20% VIP OFF</span>
                </div>
                <div class="discount-tasks-box">
                    <span style="font-size:0.78rem; font-weight:700; color:var(--color-gold);">Unlock Grand Eid Offer (35% OFF Import Wear):</span>
                    <label id="task-ig-label" class="task-checkbox-label">
                        <input type="checkbox" id="task-ig" onchange="recalculateShopPrices()"> Like & Follow Instagram
                    </label>
                    <label id="task-yt-label" class="task-checkbox-label">
                        <input type="checkbox" id="task-yt" onchange="recalculateShopPrices()"> Subscribe YouTube
                    </label>
                </div>
                <!-- Action to trigger QR scan simulator -->
                <div>
                    <button class="btn-secondary" style="font-size:0.7rem; padding:0.25rem 0.6rem;" onclick="simulateQRScan()">Simulate QR Scan (5%)</button>
                </div>
            </div>
        </div>

        <section class="catalog-container">
            <div class="container">
                <div class="catalog-controls">
                    <div class="category-filters">
                        <button class="btn-filter active" onclick="filterCatalog('All', this)">All products</button>
                        <button class="btn-filter" onclick="filterCatalog('Bra/Lingerie', this)">Bras & Lingerie</button>
                        <button class="btn-filter" onclick="filterCatalog('Underwear', this)">Panties & Underwear</button>
                        <button class="btn-filter" onclick="filterCatalog('Innerwear', this)">Loungewear & Secrets</button>
                    </div>
                    <div>
                        <select class="sort-select" onchange="sortCatalog(this.value)">
                            <option value="pop">Sort by Popularity</option>
                            <option value="low">Price: Low to High</option>
                            <option value="high">Price: High to Low</option>
                        </select>
                    </div>
                </div>

                <!-- Products Grid -->
                <div class="product-grid" id="shop-product-grid">
                    <!-- Injected dynamically by JS -->
                </div>
            </div>
        </section>
    </main>
    <?php endif; ?>

    <!-- Brand Values Grid Section (Visible on frontend states) -->
    <?php if (!$is_admin_mode): ?>
    <section class="values-section">
        <div class="container">
            <div class="values-grid">
                <div class="value-card">
                    <div class="value-icon">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                    </div>
                    <h3 class="value-title">Artisanal Materials</h3>
                    <p class="value-text">Sourced from selected global mills. Utilizing premium quality stretch lace and mulberry silk linings.</p>
                </div>

                <div class="value-card">
                    <div class="value-icon">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path>
                        </svg>
                    </div>
                    <h3 class="value-title">Precision Comfort</h3>
                    <p class="value-text">Each silhouette is tested and fine-tuned for structured support, eliminating dig-in and offering seamless wear.</p>
                </div>

                <div class="value-card">
                    <div class="value-icon">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                    </div>
                    <h3 class="value-title">Private Packaging</h3>
                    <p class="value-text">All orders are wrapped in tissue and shipped in unbranded, discrete outer packaging to secure your privacy.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Floating WhatsApp CTA -->
    <a href="https://wa.me/8801974424264?text=Hello%20KACHOOLI%2C%20I%20would%20like%20to%20inquire%20about%20your%20upcoming%20luxury%20collection." target="_blank" rel="noopener noreferrer" class="whatsapp-float">
        <svg class="whatsapp-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946C.06 5.348 5.397.01 12.008.01c3.202.001 6.212 1.246 8.477 3.514 2.266 2.268 3.507 5.28 3.505 8.484-.004 6.657-5.34 11.997-11.953 11.997-2.005-.001-3.973-.502-5.724-1.455L0 24zm6.59-4.846c1.666.988 3.311 1.487 5.408 1.489 5.485.002 9.948-4.46 9.95-9.95.001-2.658-1.026-5.158-2.896-7.03C17.24 1.8 14.743.774 12.012.774c-5.49 0-9.958 4.468-9.96 9.96-.001 2.102.502 3.784 1.493 5.465L2.556 21.75l5.59-1.464zM18.067 15c-.328-.164-1.942-.957-2.24-1.066-.3-.11-.518-.165-.736.164-.219.328-.847 1.066-1.037 1.284-.19.219-.38.246-.708.082-.328-.164-1.386-.51-2.64-1.627-.975-.87-1.633-1.946-1.825-2.274-.19-.328-.02-.505.143-.668.148-.147.328-.383.493-.575.164-.19.219-.328.328-.547.11-.219.055-.41-.027-.574-.082-.164-.736-1.776-1.009-2.433-.267-.641-.56-.553-.736-.562-.172-.008-.37-.01-.568-.01-.197 0-.519.074-.79.37-.272.296-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.942-.795 2.217-1.56.275-.767.275-1.424.192-1.56-.083-.137-.3-.22-.628-.383z" />
        </svg>
        <span>Pre-order Concierge</span>
    </a>
    <?php endif; ?>

    <!-- Footer -->
    <footer>
        <div class="container footer-inner">
            <div class="footer-brand">KACHOOLI</div>
            <ul class="footer-nav">
                <li><a href="index.php" class="footer-link">VIP Access</a></li>
                <li><a href="mailto:concierge@kachooli.com" class="footer-link">Support Concierge</a></li>
                <li><a href="index.php?admin=1" class="footer-link">Admin HQ</a></li>
            </ul>
            <div class="copyright">
                &copy; 2026 <a href="#">KACHOOLI</a>. All rights reserved. | Handcrafted Luxury Innerwear & Loungewear.
            </div>
        </div>
    </footer>

    <!-- OTP MODAL OVERLAY -->
    <div id="otp-modal-overlay" class="otp-modal-overlay">
        <div class="otp-modal">
            <h3 class="otp-title">Secure Verification</h3>
            <p class="otp-subtitle">A verification code has been dispatched to your email and WhatsApp number. Enter the code below.</p>
            
            <!-- Simulated SMS/Mail Box for local developer staging convenience -->
            <div class="otp-simulated-notification" id="otp-developer-simulator">
                [MOCK SERVICE] Simulated OTP code sent: <strong id="simulated-otp-code">000000</strong>
            </div>

            <div class="otp-code-box">
                <input type="text" class="otp-input-field" maxlength="1" onkeyup="focusNextOTP(this, 0)" id="otp-d1">
                <input type="text" class="otp-input-field" maxlength="1" onkeyup="focusNextOTP(this, 1)" id="otp-d2">
                <input type="text" class="otp-input-field" maxlength="1" onkeyup="focusNextOTP(this, 2)" id="otp-d3">
                <input type="text" class="otp-input-field" maxlength="1" onkeyup="focusNextOTP(this, 3)" id="otp-d4">
                <input type="text" class="otp-input-field" maxlength="1" onkeyup="focusNextOTP(this, 4)" id="otp-d5">
                <input type="text" class="otp-input-field" maxlength="1" onkeyup="focusNextOTP(this, 5)" id="otp-d6">
            </div>

            <button class="btn-submit" onclick="submitOTPVerify()">Verify & Enter Shop</button>
            <button class="btn-secondary" style="margin-top:1rem; width:100%;" onclick="closeOTPModal()">Cancel</button>
        </div>
    </div>

    <!-- QR CODE SIMULATION MODAL -->
    <div id="qr-modal" class="qr-modal">
        <div class="qr-card">
            <h3 class="otp-title">Retail Walk-in Scan</h3>
            <p class="otp-subtitle" style="margin-bottom: 1.5rem;">Scan this QR code at our retail outlet to unlock a 5% walk-in customer discount.</p>
            
            <div class="qr-svg-holder">
                <!-- Inline SVG QR Mockup -->
                <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                    <rect width="100" height="100" fill="#fff" />
                    <!-- Outer boxes -->
                    <rect x="5" y="5" width="25" height="25" fill="#151112" />
                    <rect x="10" y="10" width="15" height="15" fill="#fff" />
                    <rect x="13" y="13" width="9" height="9" fill="#151112" />

                    <rect x="70" y="5" width="25" height="25" fill="#151112" />
                    <rect x="75" y="10" width="15" height="15" fill="#fff" />
                    <rect x="78" y="13" width="9" height="9" fill="#151112" />

                    <rect x="5" y="70" width="25" height="25" fill="#151112" />
                    <rect x="10" y="75" width="15" height="15" fill="#fff" />
                    <rect x="13" y="78" width="9" height="9" fill="#151112" />
                    
                    <!-- Random bits -->
                    <rect x="40" y="10" width="10" height="5" fill="#151112" />
                    <rect x="55" y="5" width="5" height="15" fill="#151112" />
                    <rect x="45" y="25" width="15" height="5" fill="#151112" />
                    
                    <rect x="35" y="35" width="10" height="10" fill="#151112" />
                    <rect x="55" y="35" width="15" height="5" fill="#151112" />
                    <rect x="45" y="50" width="5" height="25" fill="#151112" />
                    
                    <rect x="35" y="75" width="15" height="10" fill="#151112" />
                    <rect x="70" y="70" width="10" height="5" fill="#151112" />
                    <rect x="85" y="80" width="10" height="10" fill="#151112" />
                    <rect x="65" y="85" width="10" height="10" fill="#151112" />
                    <!-- Logo mark in center -->
                    <circle cx="50" cy="50" r="10" fill="#c8a46a" />
                    <text x="50" y="53" font-size="8" font-weight="700" fill="#fff" text-anchor="middle">KC</text>
                </svg>
            </div>
            
            <button class="btn-submit" onclick="applyQRScanRedirect()">Apply & Unlock 5% Discount</button>
            <button class="btn-secondary" style="margin-top:1rem; width:100%;" onclick="document.getElementById('qr-modal').style.display='none'">Close</button>
        </div>
    </div>

    <!-- CART SIDE DRAWER PANEL -->
    <div id="cart-drawer-overlay" class="cart-drawer-overlay" onclick="toggleCartDrawer(false)"></div>
    <div id="cart-drawer" class="cart-drawer">
        <div class="cart-header">
            <h3 class="cart-title">Your Cart</h3>
            <button class="btn-close-cart" onclick="toggleCartDrawer(false)">
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="cart-body">
            <!-- Empty state -->
            <div id="cart-empty-view" class="cart-empty-message">
                Your luxury cart is currently empty.<br>
                <span style="font-size:0.8rem; color:var(--color-muted); margin-top:0.5rem; display:block;">Browse early access storefront to add styles.</span>
            </div>

            <!-- Items list -->
            <div id="cart-items-container" class="cart-items-list"></div>

            <!-- Order Totals -->
            <div id="cart-summary-box" style="display:none;">
                <div class="cart-totals">
                    <div class="total-row">
                        <span>Items Subtotal:</span>
                        <span id="summary-subtotal">0 BDT</span>
                    </div>
                    <div class="total-row" id="summary-discount-row" style="color:var(--color-gold);">
                        <span>Campaign Discount (<span id="summary-discount-pct">0</span>%):</span>
                        <span id="summary-discount-val">-0 BDT</span>
                    </div>
                    <div class="total-row">
                        <span>Delivery Fee:</span>
                        <span id="summary-shipping">0 BDT</span>
                    </div>
                    <div class="total-row grand-total">
                        <span>Total Payable:</span>
                        <span id="summary-total">0 BDT</span>
                    </div>
                </div>

                <!-- Checkout Form -->
                <div class="cart-checkout-form">
                    <h4 class="checkout-title">Delivery Details</h4>
                    <form id="checkout-form" onsubmit="handleCheckout(event)">
                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <input type="text" id="chk_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" id="chk_email" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">WhatsApp Number</label>
                            <input type="tel" id="chk_phone" class="form-input" placeholder="e.g. +88017..." required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Delivery Address</label>
                            <input type="text" id="chk_address" class="form-input" placeholder="House #, Road #, Area" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">City</label>
                                <input type="text" id="chk_city" class="form-input" placeholder="e.g. Dhaka" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Postal Code</label>
                                <input type="text" id="chk_postal" class="form-input" placeholder="e.g. 1209">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Delivery Zone</label>
                            <select id="chk_zone" class="form-input" onchange="updateCartTotals()" style="appearance: none; background-image: url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2210%22 height=%226%22><path d=%22M0 0h10L5 6z%22 fill=%22%237b6f73%22/></svg>'); background-repeat: no-repeat; background-position: right 1.25rem center; background-size: 10px;">
                                <option value="inside" selected>Inside Dhaka (80 BDT)</option>
                                <option value="outside">Outside Dhaka (150 BDT)</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-top:1.5rem;">
                            <label class="checkbox-container">
                                <input type="checkbox" checked disabled>
                                Cash on Delivery (COD) pre-selected
                            </label>
                        </div>

                        <button type="submit" class="btn-submit" id="btn-checkout-submit">
                            <span>Place Order on WhatsApp</span>
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Script Core -->
    <script>
        // Catalog dataset
        const productCatalog = <?php echo json_encode($products); ?>;
        
        // Active tracking properties
        const promoSource = <?php echo json_encode($_SESSION['promo_source'] ?? null); ?>;
        let activeVipSession = <?php echo json_encode(isset($_SESSION['vip_verified']) ? [
            'verified' => true,
            'access_pass' => $_SESSION['vip_access_pass'],
            'name' => $_SESSION['vip_name'],
            'email' => $_SESSION['vip_email'],
            'whatsapp' => $_SESSION['vip_whatsapp']
        ] : null); ?>;

        // Current client-side checkout cart state
        let cart = JSON.parse(localStorage.getItem('kachooli_cart') || '[]');

        // Slide data for landing teaser slides
        const braTeaserSlides = [
            { id: 3, name: "Push-Up Bra", img: "G_3.png", desc: "Accentuates your natural curves with premium gel-cushioned cups and elegant satin detailing." },
            { id: 8, name: "Wireless Bra", img: "G_8.png", desc: "Provides weightless, natural shaping with soft, seamless cups and an elastic support band." },
            { id: 10, name: "Minimizer Bra", img: "G_10.png", desc: "Engineered to visually reduce bust projection while maintaining a smooth and natural profile." },
            { id: 19, name: "Lingerie Bodysuit", img: "G_19.png", desc: "Exquisite full-body intimate wear combining sheer panel designs with structured luxury linings." },
            { id: 21, name: "Bridal Bra", img: "G_21.png", desc: "Detailed white/ivory lace styling with delicate floral embroidery, designed for special occasions." },
            { id: 26, name: "Peep/Cut-out Bra", img: "G_26.png", desc: "An alluring open-cup design featuring scalloped lace trims and satin ribbons." }
        ];

        let currentSlideIndex = 0;
        let isAnimating = false;
        let autoSlideTimer = null;

        // On Page Load Initialization
        document.addEventListener('DOMContentLoaded', () => {
            initAppStates();
            initCountdown();
            initCartUI();
        });

        // Toggle user view states: landing vs shop storefront
        function initAppStates() {
            const hasVIP = activeVipSession && activeVipSession.verified;
            const stateLanding = document.getElementById('state-landing');
            const stateShop = document.getElementById('state-shop');
            const badge = document.getElementById('session-vip-badge');

            if (hasVIP) {
                if (stateLanding) stateLanding.classList.remove('active-state');
                if (stateShop) {
                    stateShop.classList.add('active-state');
                    renderCatalogGrid();
                    recalculateShopPrices();
                }
                if (badge) {
                    badge.innerText = `VIP: ${activeVipSession.access_pass}`;
                    badge.style.display = 'block';
                }
                // Pre-populate checkout form
                document.getElementById('chk_name').value = activeVipSession.name;
                document.getElementById('chk_email').value = activeVipSession.email;
                document.getElementById('chk_phone').value = activeVipSession.whatsapp;
            } else {
                if (stateShop) stateShop.classList.remove('active-state');
                if (stateLanding) stateLanding.classList.add('active-state');
                initTeaserSlideshow();
            }
        }

        // Countdown timer (24h 30m launch timeline)
        function initCountdown() {
            const daysEl = document.getElementById('days');
            const hoursEl = document.getElementById('hours');
            const minsEl = document.getElementById('mins');
            const secsEl = document.getElementById('secs');
            if (!daysEl) return;

            const launchDate = new Date(Date.now() + (24 * 60 * 60 * 1000) + (30 * 60 * 1000));

            function update() {
                const distance = launchDate.getTime() - new Date().getTime();
                if (distance < 0) {
                    document.getElementById('countdown').innerHTML = "<div style='grid-column: span 4; font-family: var(--font-serif); font-size: 2rem; color: var(--color-gold);'>Launching Today!</div>";
                    return;
                }
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const mins = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const secs = Math.floor((distance % (1000 * 60)) / 1000);

                daysEl.innerText = String(days).padStart(2, '0');
                hoursEl.innerText = String(hours).padStart(2, '0');
                minsEl.innerText = String(mins).padStart(2, '0');
                secsEl.innerText = String(secs).padStart(2, '0');
            }
            update();
            setInterval(update, 1000);
        }

        // Landing Teaser Carousel Slider
        function initTeaserSlideshow() {
            const slideshow = document.getElementById('hero-slideshow');
            if (!slideshow) return;

            let html = "";
            braTeaserSlides.forEach((bra, idx) => {
                const activeClass = idx === 0 ? "active" : "";
                html += `
                    <div class="hero-slide ${activeClass}" data-index="${idx}">
                        <img src="${bra.img}" alt="${bra.name}" class="hero-slide-bg" onerror="this.src='daily_luxe.png';">
                        <div class="slide-details-container">
                            <span class="slide-badge">KACHOOLI Couture</span>
                            <h2 class="slide-title">No. ${bra.id}: ${bra.name}</h2>
                            <p class="slide-desc">${bra.desc}</p>
                            <button class="btn-preorder" onclick="scrollToRegister('${bra.name}')">
                                <span>Register to Shop</span>
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width:1.1rem; height:1.1rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                `;
            });
            slideshow.innerHTML = html;
            startAutoSlide();
        }

        function startAutoSlide() {
            if (autoSlideTimer) clearInterval(autoSlideTimer);
            autoSlideTimer = setInterval(() => {
                const nextIdx = (currentSlideIndex + 1) % braTeaserSlides.length;
                goToSlide(nextIdx);
            }, 3000);
        }

        function goToSlide(nextIdx) {
            if (isAnimating) return;
            isAnimating = true;

            const slides = document.querySelectorAll('.hero-slide');
            if (!slides.length) return;
            const currentSlide = slides[currentSlideIndex];
            const nextSlide = slides[nextIdx];

            slides.forEach(s => s.classList.remove('slide-in-right', 'slide-out-left', 'exiting'));

            nextSlide.classList.add('active', 'slide-in-right');
            currentSlide.classList.add('exiting', 'slide-out-left');

            setTimeout(() => {
                currentSlide.classList.remove('active', 'exiting', 'slide-out-left');
                nextSlide.classList.remove('slide-in-right');
                currentSlideIndex = nextIdx;
                isAnimating = false;
            }, 1200);
        }

        function scrollToRegister(pref) {
            const select = document.getElementById('bra-preference');
            if (select) select.value = pref;
            document.getElementById('rsvp').scrollIntoView({ behavior: 'smooth' });
        }

        // ------------------ VIP REGISTRATION FLOW ------------------
        function handleVIPRegister(event) {
            event.preventDefault();
            const btn = document.getElementById('btn-vip-submit');
            const fullname = document.getElementById('fullname').value;
            const email = document.getElementById('email').value;
            const whatsapp = document.getElementById('whatsapp').value;
            const preference = document.getElementById('bra-preference').value;

            btn.disabled = true;
            btn.querySelector('span').innerText = 'Generating Ticket...';

            const formData = new FormData();
            formData.append('fullname', fullname);
            formData.append('email', email);
            formData.append('whatsapp', whatsapp);
            formData.append('preference', preference);

            fetch('index.php?action=register', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.querySelector('span').innerText = 'Register & Verify Access';

                if (data.status === 'pending') {
                    // Open simulated OTP modal
                    document.getElementById('simulated-otp-code').innerText = data.mock_otp;
                    document.getElementById('otp-modal-overlay').style.display = 'flex';
                    // clear OTP fields
                    for (let i = 1; i <= 6; i++) document.getElementById('otp-d' + i).value = '';
                    document.getElementById('otp-d1').focus();
                } else {
                    alert(data.message);
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.querySelector('span').innerText = 'Register & Verify Access';
                alert('Connection failure: ' + err.message);
            });
        }

        function focusNextOTP(el, idx) {
            if (el.value.length === 1 && idx < 5) {
                document.getElementById('otp-d' + (idx + 2)).focus();
            }
        }

        function closeOTPModal() {
            document.getElementById('otp-modal-overlay').style.display = 'none';
        }

        function submitOTPVerify() {
            let code = "";
            for (let i = 1; i <= 6; i++) {
                code += document.getElementById('otp-d' + i).value;
            }
            if (code.length !== 6) {
                alert('Please enter complete 6-digit code.');
                return;
            }

            const formData = new FormData();
            formData.append('otp_code', code);

            fetch('index.php?action=verify', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    closeOTPModal();
                    activeVipSession = {
                        verified: true,
                        access_pass: data.access_pass,
                        name: data.name,
                        email: data.email,
                        whatsapp: data.whatsapp
                    };
                    initAppStates();
                } else {
                    alert(data.message);
                }
            })
            .catch(err => alert('Verification failed: ' + err.message));
        }

        // ------------------ CATALOG FRONTEND DRAWING ------------------
        let currentFilter = 'All';
        let currentSort = 'pop';

        function filterCatalog(cat, el) {
            document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
            currentFilter = cat;
            renderCatalogGrid();
        }

        function sortCatalog(val) {
            currentSort = val;
            renderCatalogGrid();
        }

        // Calculate active campaign discount rates based on source & steps
        function getDiscountRates() {
            // Default discount for verified VIP members: 20%
            let defaultRate = 20;

            // Checked social verification states
            const likedIG = document.getElementById('task-ig') && document.getElementById('task-ig').checked;
            const subYT = document.getElementById('task-yt') && document.getElementById('task-yt').checked;

            // Grand Eid Offer: 35% on Premium/Import products only.
            let hasGrandEid = likedIG && subYT;

            // Non-VIP leads discount parsing (only if VIP is not active, but VIP is always active to reach here)
            // But we keep tracking parameter updates in badge if needed
            let walkinRate = promoSource === 'walkin' ? 5 : (promoSource === 'fb' ? 10 : (promoSource === 'ig' ? 15 : 0));

            return {
                baseVIPRate: defaultRate,
                grandEidRate: 35,
                hasGrandEid: hasGrandEid,
                walkinRate: walkinRate
            };
        }

        function recalculateShopPrices() {
            const rates = getDiscountRates();
            const badge = document.getElementById('active-discount-badge');
            
            // Render labels completed styling
            if (document.getElementById('task-ig').checked) {
                document.getElementById('task-ig-label').classList.add('completed');
            } else {
                document.getElementById('task-ig-label').classList.remove('completed');
            }
            if (document.getElementById('task-yt').checked) {
                document.getElementById('task-yt-label').classList.add('completed');
            } else {
                document.getElementById('task-yt-label').classList.remove('completed');
            }

            if (rates.hasGrandEid) {
                badge.innerText = "35% Grand Eid Activated";
            } else {
                badge.innerText = "20% VIP OFF Active";
            }

            // Loop cards on screen and update active price outputs
            productCatalog.forEach(p => {
                const card = document.getElementById(`prod-card-${p.id}`);
                if (!card) return;
                
                const sizeSelect = card.querySelector('.size-select-element');
                const selectedIndex = sizeSelect.selectedIndex;
                const basePrice = parseFloat(p.sizes[selectedIndex].price);

                // Determine discount rate:
                // 1. If Local category -> Excluded from 35% Grand Eid, only gets VIP 20%.
                // 2. If Export category -> Excluded from 35%, gets VIP 20%.
                // 3. If Import segment -> Eligible for 35% Grand Eid if completed tasks; else VIP 20%.
                let rate = rates.baseVIPRate;
                if (p.segment === 'Import' && rates.hasGrandEid) {
                    rate = rates.grandEidRate;
                }

                const discountedPrice = Math.round(basePrice * (1 - rate / 100));

                card.querySelector('.price-original').innerText = `${basePrice} BDT`;
                card.querySelector('.price-discounted').innerText = `${discountedPrice} BDT`;
                card.querySelector('.discount-pct').innerText = `${rate}% OFF`;
            });

            updateCartTotals();
        }

        // Render storefront catalog product grid
        function renderCatalogGrid() {
            const grid = document.getElementById('shop-product-grid');
            if (!grid) return;

            // Filter items
            let filtered = productCatalog.filter(p => currentFilter === 'All' || p.category === currentFilter);

            // Sort items
            if (currentSort === 'low') {
                filtered.sort((a, b) => a.sizes[0].price - b.sizes[0].price);
            } else if (currentSort === 'high') {
                filtered.sort((a, b) => b.sizes[0].price - a.sizes[0].price);
            } else {
                // Popularity/ID sort
                filtered.sort((a, b) => a.id.localeCompare(b.id));
            }

            let html = "";
            filtered.forEach(p => {
                // Generate size select options
                let options = p.sizes.map((s, idx) => `<option value="${s.size}" data-price="${s.price}">${s.size} (${s.price} BDT)</option>`).join('');
                
                const segmentBadgeClass = p.segment.toLowerCase();
                const isImport = p.segment === 'Import' ? 'Import / Premium' : p.segment;

                html += `
                    <div class="product-card" id="prod-card-${p.id}">
                        <span class="segment-badge ${segmentBadgeClass}">${isImport}</span>
                        <div class="product-media">
                            <img src="${p.image}" alt="${p.name}" onerror="this.src='daily_luxe.png';">
                        </div>
                        <div class="product-info">
                            <div class="product-name-row">
                                <h3 class="product-name">${p.name}</h3>
                                <span class="product-id-tag">${p.id}</span>
                            </div>
                            <p class="product-desc">${p.desc}</p>
                            
                            <div class="size-selector-row">
                                <label class="form-label" style="font-size:0.68rem;">Select Size</label>
                                <select class="form-input size-select-element" style="padding:0.5rem 1rem;" onchange="handleCardSizeChange('${p.id}')">
                                    ${options}
                                </select>
                            </div>

                            <div class="price-row">
                                <span class="price-original">${p.sizes[0].price} BDT</span>
                                <span class="price-discounted">${p.sizes[0].price} BDT</span>
                                <span class="discount-pct">0% OFF</span>
                            </div>

                            <button class="btn-add-cart" onclick="addToCart('${p.id}')">
                                Add to Reservation
                            </button>
                        </div>
                    </div>
                `;
            });
            grid.innerHTML = html;
            recalculateShopPrices();
        }

        function handleCardSizeChange(prodId) {
            recalculateShopPrices();
        }

        // ------------------ SHOPPING CART CONTROLLER ------------------
        function toggleCartDrawer(open) {
            const drawer = document.getElementById('cart-drawer');
            const overlay = document.getElementById('cart-drawer-overlay');
            if (open) {
                drawer.classList.add('open');
                overlay.style.display = 'block';
            } else {
                drawer.classList.remove('open');
                overlay.style.display = 'none';
            }
        }

        function addToCart(prodId) {
            const product = productCatalog.find(p => p.id === prodId);
            if (!product) return;

            const card = document.getElementById(`prod-card-${prodId}`);
            const sizeSelect = card.querySelector('.size-select-element');
            const selectedSize = sizeSelect.value;
            
            const rates = getDiscountRates();
            let rate = rates.baseVIPRate;
            if (product.segment === 'Import' && rates.hasGrandEid) {
                rate = rates.grandEidRate;
            }

            const sizeObj = product.sizes.find(s => s.size === selectedSize);
            const originalPrice = parseFloat(sizeObj.price);
            const finalPrice = Math.round(originalPrice * (1 - rate / 100));

            // Check if item already exists in cart with same size
            const existing = cart.find(item => item.id === prodId && item.size === selectedSize);
            if (existing) {
                existing.quantity += 1;
            } else {
                cart.push({
                    id: prodId,
                    name: product.name,
                    size: selectedSize,
                    price: finalPrice,
                    originalPrice: originalPrice,
                    discountRate: rate,
                    quantity: 1,
                    segment: product.segment
                });
            }

            saveCart();
            initCartUI();
            toggleCartDrawer(true);
        }

        function saveCart() {
            localStorage.setItem('kachooli_cart', JSON.stringify(cart));
        }

        function updateCartQty(idx, delta) {
            cart[idx].quantity += delta;
            if (cart[idx].quantity <= 0) {
                cart.splice(idx, 1);
            }
            saveCart();
            initCartUI();
        }

        function initCartUI() {
            const countBadge = document.getElementById('cart-badge');
            const emptyView = document.getElementById('cart-empty-view');
            const itemsContainer = document.getElementById('cart-items-container');
            const summaryBox = document.getElementById('cart-summary-box');

            if (!itemsContainer) return;

            // Update item count count
            const totalQty = cart.reduce((acc, curr) => acc + curr.quantity, 0);
            countBadge.innerText = totalQty;

            if (cart.length === 0) {
                emptyView.style.display = 'block';
                itemsContainer.innerHTML = '';
                summaryBox.style.display = 'none';
                return;
            }

            emptyView.style.display = 'none';
            summaryBox.style.display = 'block';

            // Draw items
            let html = "";
            cart.forEach((item, idx) => {
                const prod = productCatalog.find(p => p.id === item.id);
                const img = prod ? prod.image : 'daily_luxe.png';
                html += `
                    <div class="cart-item">
                        <img src="${img}" alt="${item.name}" class="cart-item-img" onerror="this.src='daily_luxe.png';">
                        <div class="cart-item-info">
                            <h4 class="cart-item-name">${item.name}</h4>
                            <div class="cart-item-meta">Size: ${item.size} | Category: ${item.segment}</div>
                            <div class="cart-qty-ctrl">
                                <button class="btn-qty" onclick="updateCartQty(${idx}, -1)">-</button>
                                <span class="cart-item-qty-val">${item.quantity}</span>
                                <button class="btn-qty" onclick="updateCartQty(${idx}, 1)">+</button>
                            </div>
                        </div>
                        <div class="cart-item-price">${item.price * item.quantity} BDT</div>
                    </div>
                `;
            });
            itemsContainer.innerHTML = html;
            updateCartTotals();
        }

        // Calculate checkout totals dynamically
        function updateCartTotals() {
            if (cart.length === 0) return;

            const rates = getDiscountRates();
            let itemsSubtotal = 0;
            let totalDiscount = 0;

            cart.forEach(item => {
                // Re-evaluate discount rate based on latest tasks
                let rate = rates.baseVIPRate;
                if (item.segment === 'Import' && rates.hasGrandEid) {
                    rate = rates.grandEidRate;
                }
                
                const origTotal = item.originalPrice * item.quantity;
                const discPrice = Math.round(item.originalPrice * (1 - rate / 100)) * item.quantity;
                
                itemsSubtotal += origTotal;
                totalDiscount += (origTotal - discPrice);
            });

            // Delivery zones pricing
            const zone = document.getElementById('chk_zone').value;
            const shipping = zone === 'inside' ? 80 : 150;
            const finalPayable = (itemsSubtotal - totalDiscount) + shipping;

            document.getElementById('summary-subtotal').innerText = `${itemsSubtotal} BDT`;
            document.getElementById('summary-discount-val').innerText = `-${totalDiscount} BDT`;
            document.getElementById('summary-shipping').innerText = `${shipping} BDT`;
            document.getElementById('summary-total').innerText = `${finalPayable} BDT`;

            const dispRate = rates.hasGrandEid ? "35% (Import Only)" : "20%";
            document.getElementById('summary-discount-pct').innerText = dispRate;
        }

        // Submit Checkout to Server to get precompiled WhatsApp redirect url
        function handleCheckout(event) {
            event.preventDefault();
            const btn = document.getElementById('btn-checkout-submit');
            
            const name = document.getElementById('chk_name').value;
            const email = document.getElementById('chk_email').value;
            const phone = document.getElementById('chk_phone').value;
            const address = document.getElementById('chk_address').value;
            const city = document.getElementById('chk_city').value;
            const postal = document.getElementById('chk_postal').value;
            const zone = document.getElementById('chk_zone').value;

            btn.disabled = true;
            btn.querySelector('span').innerText = 'Redirecting to WhatsApp...';

            const subtotalVal = cart.reduce((acc, curr) => acc + (curr.originalPrice * curr.quantity), 0);
            const totalDisc = cart.reduce((acc, curr) => {
                const rate = curr.segment === 'Import' && getDiscountRates().hasGrandEid ? 35 : 20;
                return acc + ((curr.originalPrice - Math.round(curr.originalPrice * (1 - rate / 100))) * curr.quantity);
            }, 0);
            const shippingVal = zone === 'inside' ? 80 : 150;
            const grandTotal = (subtotalVal - totalDisc) + shippingVal;

            const formData = new FormData();
            formData.append('cart', JSON.stringify(cart));
            formData.append('name', name);
            formData.append('email', email);
            formData.append('phone', phone);
            formData.append('address', address);
            formData.append('city', city);
            formData.append('postal_code', postal);
            formData.append('shipping_zone', zone);
            formData.append('subtotal', subtotalVal);
            formData.append('discount', totalDisc);
            formData.append('shipping', shippingVal);
            formData.append('total', grandTotal);
            formData.append('applied_discount_rate', getDiscountRates().hasGrandEid ? 35 : 20);

            fetch('index.php?action=checkout', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.querySelector('span').innerText = 'Place Order on WhatsApp';

                if (data.status === 'success') {
                    // Empty local cart
                    cart = [];
                    saveCart();
                    initCartUI();
                    toggleCartDrawer(false);
                    
                    // Trigger redirection
                    window.location.href = data.whatsapp_url;
                } else {
                    alert(data.message);
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.querySelector('span').innerText = 'Place Order on WhatsApp';
                alert('Order failure: ' + err.message);
            });
        }

        // ------------------ QR CODE DISCOUNTS SIMULATION ------------------
        function simulateQRScan() {
            document.getElementById('qr-modal').style.display = 'flex';
        }

        function applyQRScanRedirect() {
            // Apply walk-in promo source
            window.location.href = 'index.php?src=walkin#rsvp';
        }

        // ------------------ ADMIN SYSTEM HANDLERS ------------------
        function handleAdminLogin(event) {
            event.preventDefault();
            const u = document.getElementById('admin_username').value;
            const p = document.getElementById('admin_password').value;

            const formData = new FormData();
            formData.append('username', u);
            formData.append('password', p);

            fetch('index.php?action=admin-login', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            })
            .catch(err => alert('Authentication error: ' + err.message));
        }

        function switchAdminTab(tabId, el) {
            document.querySelectorAll('.btn-tab').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.admin-tab-content').forEach(c => c.classList.remove('active-content'));

            el.classList.add('active');
            document.getElementById(tabId).classList.add('active-content');
        }
    </script>
</body>
</html>
