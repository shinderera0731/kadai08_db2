<?php
// データベース接続設定
if ($_SERVER['SERVER_NAME'] === 'localhost') {
    // ローカル環境
    $db_name = 'cafe_management';
    $db_host = 'localhost';
    $db_id   = 'root';
    $db_pw   = '';
} else {
    // さくらサーバー
    $db_name = 'gs-cinderella_pos_system'; // データベース名
    $db_host = 'mysql3109.db.sakura.ne.jp'; // ホスト名
    $db_id   = 'gs-cinderella_pos_system';   // ユーザー名
    $db_pw   = '';                           // パスワード
}

// データベース接続
try {
    $server_info = 'mysql:dbname=' . $db_name . ';charset=utf8;host=' . $db_host;
    $pdo = new PDO($server_info, $db_id, $db_pw);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// テーブル作成関数
function createTables($pdo) {
    try {
        // 商品カテゴリテーブル
        $sql = "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);

        // 在庫テーブル (productsテーブルの役割も兼ねる)
        $sql = "CREATE TABLE IF NOT EXISTS inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            category_id INT,
            quantity INT NOT NULL DEFAULT 0,
            unit VARCHAR(20) NOT NULL,
            cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            reorder_level INT DEFAULT 10,
            supplier VARCHAR(100),
            expiry_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id)
        )";
        $pdo->exec($sql);

        // 入出庫履歴テーブル
        $sql = "CREATE TABLE IF NOT EXISTS stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT,
            movement_type ENUM('入庫', '出庫', '廃棄', '調整') NOT NULL,
            quantity INT NOT NULL,
            reason VARCHAR(200),
            reference_no VARCHAR(50),
            created_by VARCHAR(50) DEFAULT 'システム',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES inventory(id)
        )";
        $pdo->exec($sql);

        // 取引履歴テーブル (レジ会計用)
        $sql = "CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            total_amount DECIMAL(10, 2) NOT NULL,
            cash_received DECIMAL(10, 2) NOT NULL,
            change_given DECIMAL(10, 2) NOT NULL,
            items_json TEXT NOT NULL
        )";
        $pdo->exec($sql);

        // 日次精算テーブル
        $sql = "CREATE TABLE IF NOT EXISTS daily_settlement (
            id INT AUTO_INCREMENT PRIMARY KEY,
            settlement_date DATE NOT NULL UNIQUE,
            initial_cash_float DECIMAL(10, 2) NOT NULL,
            total_sales_cash DECIMAL(10, 2) NOT NULL,
            expected_cash_on_hand DECIMAL(10, 2) NOT NULL,
            actual_cash_on_hand DECIMAL(10, 2) NULL,
            discrepancy DECIMAL(10, 2) NULL
        )";
        $pdo->exec($sql);

        // アプリケーション設定テーブル
        $sql = "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(255) PRIMARY KEY,
            setting_value TEXT
        )";
        $pdo->exec($sql);

        // デフォルトカテゴリ挿入
        $categories_data = ['ドリンク', 'お酒','フード', '原材料', '包装資材', 'その他'];
        foreach ($categories_data as $category_name) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
            $stmt->execute([$category_name]);
        }

        // レジ用初期商品挿入 (inventoryテーブル用)
        // category_idを取得するために、categoriesテーブルからIDを事前に取得
        $stmt_cat_id = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
        
        $initial_products_data = [
            ['コーヒー', 'ドリンク', 50, '個', 150.00, 300.00, 10, 'A社', null],
            ['紅茶', 'ドリンク', 60, '個', 100.00, 250.00, 10, 'B社', null],
            ['サンドイッチ', 'フード', 30, '個', 250.00, 450.00, 5, 'C社', date('Y-m-d', strtotime('+3 days'))],
            ['ショートケーキ', 'フード', 20, '個', 300.00, 500.00, 5, 'D社', date('Y-m-d', strtotime('+5 days'))],
            ['オレンジジュース', 'ドリンク', 70, '本', 100.00, 200.00, 15, 'E社', null]
        ];
        
        foreach ($initial_products_data as $product_data) {
            $stmt_cat_id->execute([$product_data[1]]); // カテゴリ名からIDを取得
            $category_id = $stmt_cat_id->fetchColumn();

            if ($category_id) {
                // INSERT IGNORE を使用して、既存の商品があればスキップ
                $stmt = $pdo->prepare("INSERT IGNORE INTO inventory (name, category_id, quantity, unit, cost_price, selling_price, reorder_level, supplier, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $product_data[0], // name
                    $category_id,     // category_id
                    $product_data[2], // quantity
                    $product_data[3], // unit
                    $product_data[4], // cost_price
                    $product_data[5], // selling_price
                    $product_data[6], // reorder_level
                    $product_data[7], // supplier
                    $product_data[8]  // expiry_date
                ]);
            }
        }

        // デフォルト設定値挿入 (app_settingsテーブル用)
        $default_settings = [
            'tax_rate' => '10', // デフォルト税率
            'low_stock_threshold' => '5' // デフォルト低在庫アラート閾値
        ];
        foreach ($default_settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }

        return true;
    } catch (PDOException $e) {
        // エラーログ出力
        error_log("Database Table Creation Error: " . $e->getMessage());
        return false;
    }
}

// セッション開始
session_start();

// メッセージ表示関数
function showMessage() {
    if (isset($_SESSION['message'])) {
        echo '<div class="alert success">' . htmlspecialchars($_SESSION['message']) . '</div>';
        unset($_SESSION['message']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert error">' . htmlspecialchars($_SESSION['error']) . '</div>';
        unset($_SESSION['error']);
    }
}

// 共通CSSスタイル
function getCommonCSS() {
    return '
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        .content {
            padding: 30px;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .nav {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: center;
        }
        .nav a {
            display: inline-block;
            margin: 0 10px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.3s;
        }
        .nav a:hover {
            background: #5a6fd8;
        }
        .nav a.active {
            background: #764ba2;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            background: #667eea;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #5a6fd8;
        }
        .btn.danger {
            background: #dc3545;
        }
        .btn.danger:hover {
            background: #c82333;
        }
        .btn.success {
            background: #28a745;
        }
        .btn.success:hover {
            background: #218838;
        }
        .table-container {
            overflow-x: auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        th {
            background: #f8f9fa;
            font-weight: bold;
            color: #495057;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-low {
            background: #f8d7da;
            color: #721c24;
        }
        .status-normal {
            background: #d4edda;
            color: #155724;
        }
        .status-warning {
            background: #fff3cd;
            color: #856404;
        }
        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card h3 {
            color: #667eea;
            margin-bottom: 15px;
        }
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .nav a {
                display: block;
                margin: 5px 0;
            }
        }
    </style>';
}

// 共通ナビゲーション
function getNavigation($current_page = '') {
    return '
    <div class="nav">
        <a href="index.php"' . ($current_page === 'index' ? ' class="active"' : '') . '>🏠 ホーム</a>
        <a href="input.php"' . ($current_page === 'input' ? ' class="active"' : '') . '>🛒 レジ・入出庫</a>
        <a href="select.php"' . ($current_page === 'select' ? ' class="active"' : '') . '>📊 在庫・精算</a>
    </div>';
}
?>
