<?php
// 共通設定ファイルを読み込み
include 'config.php';

// データ取得
try {
    // データベース接続が確立されていることを確認
    if (!isset($pdo)) {
        throw new PDOException("PDOオブジェクトがconfig.phpで初期化されていません。");
    }

    // `inventory` テーブルが存在するか確認
    $stmt_check_inventory = $pdo->query("SHOW TABLES LIKE 'inventory'");
    $inventory_table_exists = $stmt_check_inventory->rowCount() > 0;

    // `transactions` テーブルが存在するか確認
    $stmt_check_transactions = $pdo->query("SHOW TABLES LIKE 'transactions'");
    $transactions_table_exists = $stmt_check_transactions->rowCount() > 0;

    // `daily_settlement` テーブルが存在するか確認
    $stmt_check_daily_settlement = $pdo->query("SHOW TABLES LIKE 'daily_settlement'");
    $daily_settlement_table_exists = $stmt_check_daily_settlement->rowCount() > 0;

    // `app_settings` テーブルが存在するか確認
    $stmt_check_app_settings = $pdo->query("SHOW TABLES LIKE 'app_settings'");
    $app_settings_table_exists = $stmt_check_app_settings->rowCount() > 0;

    // 必要なすべてのテーブルが存在するか
    $all_tables_exist = $inventory_table_exists && $transactions_table_exists && $daily_settlement_table_exists && $app_settings_table_exists;

    if (!$all_tables_exist) {
        // テーブルが存在しない場合、初期化を促す
        $_SESSION['error'] = '❌ データベーステーブルが見つかりません。システムを初期化してください。';
        $categories = []; // カテゴリも空にする
        $total_items = 0;
        $low_stock_count = 0;
        $expiring_count = 0;
        $total_value = 0;
    } else {
        // テーブルが存在する場合のみデータを取得
        $categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $total_items = $pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
        $low_stock_count = $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
        $expiring_count = $pdo->query("
            SELECT COUNT(*) FROM inventory 
            WHERE expiry_date IS NOT NULL 
            AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ")->fetchColumn();
        $total_value = $pdo->query("SELECT SUM(quantity * cost_price) FROM inventory")->fetchColumn() ?? 0;
    }
    
} catch (PDOException $e) {
    // データベース接続は成功したが、クエリでエラーが発生した場合
    $categories = [];
    $total_items = 0;
    $low_stock_count = 0;
    $expiring_count = 0;
    $total_value = 0;
    $_SESSION['error'] = '❌ データベース接続は成功しましたが、クエリにエラーが発生しました。テーブルが存在しない可能性があります。' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cinderella cafe レジ・在庫管理システム</title>
    <?php echo getCommonCSS(); ?>
    <style>
        .welcome-section {
            text-align: center;
            margin-bottom: 40px;
        }
        .welcome-title {
            font-size: 3em;
            color: #667eea;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        .welcome-subtitle {
            font-size: 1.3em;
            color: #666;
            margin-bottom: 30px;
        }
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        .menu-item {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-decoration: none;
            transition: all 0.3s ease;
            text-align: center;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .menu-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(102, 126, 234, 0.4);
        }
        .menu-icon {
            font-size: 3em;
            margin-bottom: 15px;
            display: block;
        }
        .menu-title {
            font-size: 1.5em;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .menu-description {
            font-size: 1em;
            opacity: 0.9;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-left: 5px solid #667eea;
        }
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            color: #666;
        }
        .status-ok {
            border-left-color: #28a745;
        }
        .status-ok .stat-number {
            color: #28a745;
        }
        .status-warning {
            border-left-color: #ffc107;
        }
        .status-warning .stat-number {
            color: #ffc107;
        }
        .status-danger {
            border-left-color: #dc3545;
        }
        .status-danger .stat-number {
            color: #dc3545;
        }
        .quick-start {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
        }
        .quick-start h3 {
            color: #667eea;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏰 Cinderella cafe</h1>
            <p>レジ・在庫管理システム</p>
        </div>

        <div class="content">
            <!-- ナビゲーション -->
            <?php echo getNavigation('index'); ?>

            <!-- メッセージ表示 -->
            <?php showMessage(); ?>

            <!-- システム初期化（テーブルが存在しない場合） -->
            <?php if (!$all_tables_exist): ?>
                <div class="quick-start">
                    <h3>🔧 システム初期化が必要です</h3>
                    <p style="margin-bottom: 20px;">データベーステーブルが作成されていません。以下のボタンでシステムを初期化してください。</p>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="create_tables">
                        <button type="submit" class="btn success" style="font-size: 18px; padding: 15px 30px;">
                            📦 システムを初期化する
                        </button>
                    </form>
                </div>
            <?php else: ?>

            <!-- 統計カード -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_items; ?></div>
                    <div class="stat-label">総商品数</div>
                </div>
                <div class="stat-card <?php echo $low_stock_count > 0 ? 'status-warning' : 'status-ok'; ?>">
                    <div class="stat-number"><?php echo $low_stock_count; ?></div>
                    <div class="stat-label">在庫不足</div>
                </div>
                <div class="stat-card <?php echo $expiring_count > 0 ? 'status-danger' : 'status-ok'; ?>">
                    <div class="stat-number"><?php echo $expiring_count; ?></div>
                    <div class="stat-label">期限間近</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">¥<?php echo number_format($total_value); ?></div>
                    <div class="stat-label">総在庫価値</div>
                </div>
            </div>

            <!-- アラート表示 -->
            <?php if ($low_stock_count > 0): ?>
                <div class="alert warning">
                    <strong>⚠️ 在庫不足警告:</strong> <?php echo $low_stock_count; ?>件の商品が発注点を下回っています
                    <a href="select.php?tab=alerts&status=low_stock" style="margin-left: 10px; color: #856404; text-decoration: underline;">詳細を確認</a>
                </div>
            <?php endif; ?>

            <?php if ($expiring_count > 0): ?>
                <div class="alert warning">
                    <strong>📅 賞味期限警告:</strong> <?php echo $expiring_count; ?>件の商品が7日以内に期限切れになります
                    <a href="select.php?tab=alerts&status=expiring" style="margin-left: 10px; color: #856404; text-decoration: underline;">詳細を確認</a>
                </div>
            <?php endif; ?>

            <!-- メニューグリッド -->
            <div class="menu-grid">
                <a href="input.php?tab=pos" class="menu-item">
                    <span class="menu-icon">🛒</span>
                    <div class="menu-title">注文入力・会計</div>
                    <div class="menu-description">商品の会計処理を実行</div>
                </a>

                <a href="select.php?tab=settlement" class="menu-item">
                    <span class="menu-icon">💰</span>
                    <div class="menu-title">点検・精算</div>
                    <div class="menu-description">日次の売上確認と精算処理</div>
                </a>

                <a href="input.php?tab=inventory_ops" class="menu-item">
                    <span class="menu-icon">📦</span>
                    <div class="menu-title">商品管理・入出庫</div>
                    <div class="menu-description">新商品の登録や在庫の増減</div>
                </a>
                
                <a href="select.php?tab=inventory" class="menu-item">
                    <span class="menu-icon">📊</span>
                    <div class="menu-title">在庫一覧・履歴</div>
                    <div class="menu-description">現在の在庫状況と入出庫履歴</div>
                </a>
            </div>

            <!-- システム情報 -->
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 40px; text-align: center;">
                <h4 style="color: #667eea; margin-bottom: 15px;">📱 システム情報</h4>
                <p style="color: #666; margin-bottom: 10px;">
                    <strong>現在時刻:</strong> <?php echo date('Y年m月d日 H:i:s'); ?>
                </p>
                <p style="color: #666; margin-bottom: 10px;">
                    <strong>PHPバージョン:</strong> <?php echo phpversion(); ?>
                </p>
                <p style="color: #666;">
                    <strong>システム状態:</strong> 
                    <?php if (empty($categories)): ?>
                        <span style="color: #ffc107;">⚠️ 初期化待ち</span>
                    <?php else: ?>
                        <span style="color: #28a745;">✅ 正常稼働中</span>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ページ読み込み時のアニメーション
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.container');
            container.style.opacity = '0';
            container.style.transform = 'translateY(20px)';
            
            setTimeout(function() {
                container.style.transition = 'all 0.8s ease';
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);

            // メニューアイテムのホバーエフェクト強化
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)'; // 少し控えめなアニメーション
                    this.style.boxShadow = '0 12px 25px rgba(102, 126, 234, 0.5)'; // シャドウを強調
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '0 10px 20px rgba(102, 126, 234, 0.3)';
                });
            });
        });

        // 統計カードのアニメーション
        function animateNumbers() {
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(element => {
                const finalValue = parseInt(element.textContent.replace(/[^\d]/g, ''));
                if (finalValue > 0) {
                    let currentValue = 0;
                    const increment = Math.ceil(finalValue / 20);
                    const timer = setInterval(() => {
                        currentValue += increment;
                        if (currentValue >= finalValue) {
                            currentValue = finalValue;
                            clearInterval(timer);
                        }
                        
                        if (element.textContent.includes('¥')) {
                            element.textContent = '¥' + currentValue.toLocaleString();
                        } else {
                            element.textContent = currentValue;
                        }
                    }, 50);
                }
            });
        }

        // ページ読み込み後に数字アニメーション実行
        setTimeout(animateNumbers, 500);
    </script>
</body>
</html>
