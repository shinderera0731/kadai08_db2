<?php
// 共通設定ファイルを読み込み
include 'config.php';

// データ取得
try {
    // カテゴリ一覧
    $categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    
    // 在庫一覧
    $inventory = $pdo->query("
        SELECT i.*, c.name as category_name 
        FROM inventory i 
        LEFT JOIN categories c ON i.category_id = c.id 
        ORDER BY c.name, i.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // 在庫不足商品
    $low_stock = array_filter($inventory, function($item) {
        return $item['quantity'] <= $item['reorder_level'];
    });
    
    // 賞味期限間近商品（7日以内）
    $expiring_soon = array_filter($inventory, function($item) {
        return $item['expiry_date'] && strtotime($item['expiry_date']) <= strtotime('+7 days');
    });
    
    // 最近の入出庫履歴
    $recent_movements = $pdo->query("
        SELECT sm.*, i.name as item_name, i.unit
        FROM stock_movements sm 
        JOIN inventory i ON sm.item_id = i.id 
        ORDER BY sm.created_at DESC 
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 統計情報計算
    $total_items = count($inventory);
    $total_value = array_sum(array_map(function($item) { 
        return $item['quantity'] * $item['cost_price']; 
    }, $inventory));
    
    $low_stock_count = count($low_stock);
    $expiring_count = count($expiring_soon);

    // レジ関連データ取得
    $today = date('Y-m-d');
    $initial_cash_float = 0;
    $total_sales_cash = 0;
    $expected_cash_on_hand = 0;
    $actual_cash_on_hand_display = '';
    $discrepancy_display = '';
    $settlement_exists = false;

    // 今日の精算データを取得 (daily_settlementテーブル)
    $stmt_settlement = $pdo->prepare("SELECT * FROM daily_settlement WHERE settlement_date = ?");
    $stmt_settlement->execute([$today]);
    $settlement_data = $stmt_settlement->fetch(PDO::FETCH_ASSOC);
    if ($settlement_data) {
        $initial_cash_float = $settlement_data['initial_cash_float'];
        $actual_cash_on_hand_display = $settlement_data['actual_cash_on_hand'] !== null ? number_format($settlement_data['actual_cash_on_hand'], 0) : '';
        $discrepancy_display = $settlement_data['discrepancy'] !== null ? number_format($settlement_data['discrepancy'], 0) : '';
        $settlement_exists = true;
    }

    // 今日の売上合計を取得 (transactionsテーブル)
    $stmt_sales = $pdo->prepare("SELECT SUM(total_amount) AS total_sales FROM transactions WHERE DATE(transaction_date) = ?");
    $stmt_sales->execute([$today]);
    $result_sales = $stmt_sales->fetch(PDO::FETCH_ASSOC);
    if ($result_sales && $result_sales['total_sales'] !== null) {
        $total_sales_cash = $result_sales['total_sales'];
    }
    $expected_cash_on_hand = $initial_cash_float + $total_sales_cash;

    // アプリケーション設定取得 (app_settingsテーブル)
    $current_tax_rate = 10;
    $current_low_stock_threshold = 5;
    $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (isset($stmt_settings['tax_rate'])) {
        $current_tax_rate = (float)$stmt_settings['tax_rate'];
    }
    if (isset($stmt_settings['low_stock_threshold'])) {
        $current_low_stock_threshold = (int)$stmt_settings['low_stock_threshold'];
    }

} catch (PDOException $e) {
    $categories = [];
    $inventory = [];
    $low_stock = [];
    $expiring_soon = [];
    $recent_movements = [];
    $total_items = 0;
    $total_value = 0;
    $low_stock_count = 0;
    $expiring_count = 0;
    $initial_cash_float = 0;
    $total_sales_cash = 0;
    $expected_cash_on_hand = 0;
    $actual_cash_on_hand_display = '';
    $discrepancy_display = '';
    $settlement_exists = false;
    $current_tax_rate = 10;
    $current_low_stock_threshold = 5;
    // エラーメッセージを表示 (デバッグ用)
    // echo '<div class="alert error">データベースエラー: ' . $e->getMessage() . '</div>';
}

// フィルタリング機能 (在庫一覧用)
$filter_category = $_GET['category'] ?? '';
$filter_status = $_GET['status'] ?? '';

if ($filter_category || $filter_status) {
    $filtered_inventory = array_filter($inventory, function($item) use ($filter_category, $filter_status) {
        $category_match = !$filter_category || $item['category_id'] == $filter_category;
        
        $status_match = true;
        if ($filter_status === 'low_stock') {
            $status_match = $item['quantity'] <= $item['reorder_level'];
        } elseif ($filter_status === 'normal') {
            $status_match = $item['quantity'] > $item['reorder_level'];
        } elseif ($filter_status === 'expiring') {
            // 賞味期限が設定されており、かつ今日から7日以内
            $status_match = $item['expiry_date'] && strtotime($item['expiry_date']) <= strtotime('+7 days');
        }
        
        return $category_match && $status_match;
    });
} else {
    $filtered_inventory = $inventory;
}

// 現在アクティブなタブ
$active_tab = $_GET['tab'] ?? 'inventory'; // デフォルトは在庫一覧
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>在庫・精算 - 🏰 Cinderella cafe</title>
    <?php echo getCommonCSS(); ?>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        .filter-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filter-form .form-group {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 10px;
        }
        .quick-actions {
            text-align: center;
            margin-bottom: 30px;
        }
        .tab-buttons {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .tab-button {
            flex: 1;
            padding: 15px;
            background: #e9ecef;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
            white-space: nowrap; /* ボタン内のテキストを折り返さない */
        }
        .tab-button.active {
            background: #667eea;
            color: white;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-form .form-group {
                display: block;
                margin-right: 0;
            }
            .tab-buttons {
                flex-direction: column;
            }
        }
        /* 精算画面のスタイル */
        .info-box {
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 1.125rem;
        }
        .info-item:last-child {
            margin-bottom: 0;
        }
        .info-label {
            font-weight: 600;
            color: #374151;
        }
        .info-value {
            font-weight: 700;
            color: #047857;
        }
        .discrepancy-positive {
            color: #dc2626; /* Red */
        }
        .discrepancy-negative {
            color: #16a34a; /* Green */
        }
        .denomination-input-group {
            display: flex;
            align-items: center;
            margin-bottom: 0.25rem;
        }
        .denomination-input-group label {
            flex: 1;
            text-align: right;
            padding-right: 0.75rem;
            font-size: 1rem;
            font-weight: 500;
        }
        .denomination-input-group input {
            flex: 2;
            padding: 0.4rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            text-align: right;
            font-size: 0.9rem;
        }
        /* モーダルスタイル */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto; /* 5% from the top and centered */
            padding: 20px;
            border: 1px solid #888;
            width: 80%; /* Could be more or less, depending on screen size */
            max-width: 600px;
            border-radius: 10px;
            box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2),0 6px 20px 0 rgba(0,0,0,0.19);
            position: relative;
        }
        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        .modal-body .form-group {
            margin-bottom: 15px;
        }
        .modal-body .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .modal-body .form-group input,
        .modal-body .form-group select,
        .modal-body .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .modal-body .form-group input:focus,
        .modal-body .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        .modal-footer {
            padding-top: 15px;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏰 Cinderella cafe</h1>
            <p>在庫・精算管理システム</p>
        </div>

        <div class="content">
            <!-- ナビゲーション -->
            <?php echo getNavigation('select'); ?>

            <!-- メッセージ表示 -->
            <?php showMessage(); ?>

            <!-- システム初期化（テーブルが存在しない場合） -->
            <?php if (empty($categories) && empty($inventory)): ?>
                <div class="card">
                    <h3>🔧 システム初期化が必要です</h3>
                    <p>データベーステーブルが作成されていません。</p>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="index.php" class="btn success">🏠 ホームに戻る</a>
                    </div>
                </div>
            <?php else: ?>

            <!-- タブナビゲーション -->
            <div class="tab-buttons">
                <button class="tab-button <?php echo $active_tab === 'inventory' ? 'active' : ''; ?>" onclick="switchTab('inventory')">📦 在庫一覧</button>
                <button class="tab-button <?php echo $active_tab === 'alerts' ? 'active' : ''; ?>" onclick="switchTab('alerts')">⚠️ 警告一覧</button>
                <button class="tab-button <?php echo $active_tab === 'history' ? 'active' : ''; ?>" onclick="switchTab('history')">📋 入出庫履歴</button>
                <button class="tab-button <?php echo $active_tab === 'settlement' ? 'active' : ''; ?>" onclick="switchTab('settlement')">💰 点検・精算</button>
                <button class="tab-button <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" onclick="switchTab('settings')">⚙️ 設定</button>
            </div>

            <!-- 在庫一覧タブ -->
            <div id="inventory" class="tab-content <?php echo $active_tab === 'inventory' ? 'active' : ''; ?>">
                <!-- フィルター -->
                <div class="filter-form">
                    <h4>🔍 絞り込み検索</h4>
                    <form method="GET">
                        <input type="hidden" name="tab" value="inventory">
                        <div class="form-group">
                            <label>カテゴリ</label>
                            <select name="category" onchange="this.form.submit()">
                                <option value="">全て</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" 
                                        <?php echo $filter_category == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>状態</label>
                            <select name="status" onchange="this.form.submit()">
                                <option value="">全て</option>
                                <option value="normal" <?php echo $filter_status === 'normal' ? 'selected' : ''; ?>>正常在庫</option>
                                <option value="low_stock" <?php echo $filter_status === 'low_stock' ? 'selected' : ''; ?>>在庫不足</option>
                                <option value="expiring" <?php echo $filter_status === 'expiring' ? 'selected' : ''; ?>>期限間近</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn" style="margin-top: 24px;">🔍 検索</button>
                            <a href="?tab=inventory" class="btn" style="background: #6c757d; margin-top: 24px;">🔄 リセット</a>
                        </div>
                    </form>
                </div>

                <!-- 在庫一覧テーブル -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>商品名</th>
                                <th>カテゴリ</th>
                                <th>在庫数</th>
                                <th>単位</th>
                                <th>仕入価格</th>
                                <th>販売価格</th>
                                <th>発注点</th>
                                <th>状態</th>
                                <th>賞味期限</th>
                                <th>在庫価値</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($filtered_inventory) > 0): ?>
                                <?php foreach ($filtered_inventory as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['category_name'] ?? '未分類'); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                        <td>¥<?php echo number_format($item['cost_price']); ?></td>
                                        <td>¥<?php echo number_format($item['selling_price']); ?></td>
                                        <td><?php echo $item['reorder_level']; ?></td>
                                        <td>
                                            <?php if ($item['quantity'] <= $item['reorder_level']): ?>
                                                <span class="status-badge status-low">要発注</span>
                                            <?php elseif ($item['expiry_date'] && strtotime($item['expiry_date']) <= strtotime('+7 days')): ?>
                                                <span class="status-badge status-warning">期限間近</span>
                                            <?php else: ?>
                                                <span class="status-badge status-normal">正常</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($item['expiry_date']): ?>
                                                <?php echo $item['expiry_date']; ?>
                                                <?php if (strtotime($item['expiry_date']) <= strtotime('+7 days')): ?>
                                                    ⚠️
                                                <?php endif; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>¥<?php echo number_format($item['quantity'] * $item['cost_price']); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-small" style="background: #007bff; margin-right: 5px;" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">編集</button>
                                            <form method="POST" action="create.php" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn danger btn-small" onclick="return confirm('商品「<?php echo htmlspecialchars($item['name']); ?>」を削除しますか？\n※この操作は元に戻せません。')">🗑️</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; padding: 40px; color: #666;">
                                        <?php if ($filter_category || $filter_status): ?>
                                            🔍 検索条件に一致する商品がありません
                                        <?php else: ?>
                                            📦 登録されている商品がありません
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 警告一覧タブ -->
            <div id="alerts" class="tab-content <?php echo $active_tab === 'alerts' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>⚠️ 在庫不足商品</h3>
                    <?php if (count($low_stock) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>商品名</th>
                                        <th>現在庫数</th>
                                        <th>発注点</th>
                                        <th>不足数</th>
                                        <th>仕入先</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($low_stock as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo $item['quantity']; ?><?php echo $item['unit']; ?></td>
                                            <td><?php echo $item['reorder_level']; ?><?php echo $item['unit']; ?></td>
                                            <td class="status-badge status-low">
                                                <?php echo max(0, $item['reorder_level'] - $item['quantity']); ?><?php echo $item['unit']; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['supplier'] ?? '未設定'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #28a745; font-weight: bold;">✅ 在庫不足の商品はありません</p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>📅 賞味期限間近商品</h3>
                    <?php if (count($expiring_soon) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>商品名</th>
                                        <th>在庫数</th>
                                        <th>賞味期限</th>
                                        <th>残り日数</th>
                                        <th>状態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expiring_soon as $item): ?>
                                        <?php 
                                        $days_until_expiry = floor((strtotime($item['expiry_date']) - time()) / (60 * 60 * 24));
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo $item['quantity']; ?><?php echo $item['unit']; ?></td>
                                            <td><?php echo $item['expiry_date']; ?></td>
                                            <td>
                                                <?php if ($days_until_expiry < 0): ?>
                                                    <span class="status-badge status-low">期限切れ</span>
                                                <?php elseif ($days_until_expiry == 0): ?>
                                                    <span class="status-badge status-warning">本日</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-warning"><?php echo $days_until_expiry; ?>日</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($days_until_expiry < 0): ?>
                                                    <span style="color: #dc3545;">🗑️ 廃棄推奨</span>
                                                <?php elseif ($days_until_expiry <= 3): ?>
                                                    <span style="color: #fd7e14;">⚡ 早期販売推奨</span>
                                                <?php else: ?>
                                                    <span style="color: #ffc107;">⚠️ 注意</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #28a745; font-weight: bold;">✅ 期限間近の商品はありません</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 入出庫履歴タブ -->
            <div id="history" class="tab-content <?php echo $active_tab === 'history' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>📋 最近の入出庫履歴</h3>
                    <?php if (count($recent_movements) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>日時</th>
                                        <th>商品名</th>
                                        <th>処理</th>
                                        <th>数量</th>
                                        <th>理由</th>
                                        <th>担当者</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_movements as $movement): ?>
                                        <tr>
                                            <td><?php echo date('m/d H:i', strtotime($movement['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($movement['item_name']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $movement['movement_type'] === '入庫' ? 'status-normal' : 'status-warning'; ?>">
                                                    <?php echo $movement['movement_type']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $movement['quantity']; ?><?php echo $movement['unit']; ?></td>
                                            <td><?php echo htmlspecialchars($movement['reason'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($movement['created_by']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666;">📝 履歴データがありません</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 点検・精算タブ -->
            <div id="settlement" class="tab-content <?php echo $active_tab === 'settlement' ? 'active' : ''; ?>">
                <div class="info-box">
                    <h2 style="font-size: 1.8em; font-weight: bold; color: #374151; margin-bottom: 1em; text-align: center;">本日のサマリー (<?php echo htmlspecialchars($today); ?>)</h2>
                    <div class="info-item">
                        <span class="info-label">釣銭準備金:</span>
                        <span class="info-value">¥<?php echo number_format($initial_cash_float, 0); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">本日の売上 (現金):</span>
                        <span class="info-value">¥<?php echo number_format($total_sales_cash, 0); ?></span>
                    </div>
                    <div class="info-item" style="border-top: 1px solid #a7f3d0; padding-top: 0.75rem; margin-top: 0.75rem;">
                        <span class="info-label" style="font-size: 1.2em;">予想手元金額:</span>
                        <span class="info-value" style="font-size: 1.2em;">¥<?php echo number_format($expected_cash_on_hand, 0); ?></span>
                    </div>
                    <?php if ($settlement_exists && $settlement_data['actual_cash_on_hand'] !== null): ?>
                        <div class="info-item">
                            <span class="info-label">実際手元金額:</span>
                            <span class="info-value">¥<?php echo $actual_cash_on_hand_display; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">差異:</span>
                            <span class="info-value <?php echo ($settlement_data['discrepancy'] > 0) ? 'discrepancy-positive' : (($settlement_data['discrepancy'] < 0) ? 'discrepancy-negative' : ''); ?>">
                                ¥<?php echo $discrepancy_display; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>💰 釣銭準備金の設定</h3>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="set_cash_float">
                        <div class="form-group">
                            <label for="initial_cash_float">金額:</label>
                            <input type="number" id="initial_cash_float" name="initial_cash_float" step="1" min="0" class="form-input" value="<?php echo htmlspecialchars($initial_cash_float); ?>" required>
                        </div>
                        <button type="submit" class="btn success">釣銭準備金を設定/更新</button>
                    </form>
                </div>

                <div class="card">
                    <h3>✅ 精算</h3>
                    <?php if (!$settlement_exists || $initial_cash_float == 0): ?>
                        <p class="alert error">※ 精算を行う前に、まず釣銭準備金を設定してください。</p>
                    <?php endif; ?>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="settle_up">
                        <div class="form-group">
                            <h4 style="font-size: 1.2em; margin-bottom: 0.8em; color: #333;">実際手元金額の内訳</h4>
                            <div class="denomination-input-group">
                                <label for="bill_10000">10,000円札:</label>
                                <input type="number" id="bill_10000" name="bill_10000" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="bill_5000">5,000円札:</label>
                                <input type="number" id="bill_5000" name="bill_5000" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="bill_1000">1,000円札:</label>
                                <input type="number" id="bill_1000" name="bill_1000" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_500">500円玉:</label>
                                <input type="number" id="coin_500" name="coin_500" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_100">100円玉:</label>
                                <input type="number" id="coin_100" name="coin_100" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_50">50円玉:</label>
                                <input type="number" id="coin_50" name="coin_50" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_10">10円玉:</label>
                                <input type="number" id="coin_10" name="coin_10" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_5">5円玉:</label>
                                <input type="number" id="coin_5" name="coin_5" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_1">1円玉:</label>
                                <input type="number" id="coin_1" name="coin_1" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                        </div>

                        <div style="text-align: right; font-size: 1.5em; font-weight: bold; color: #667eea; border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                            実際手元金額合計: ¥<span id="actual_cash_total_display">0</span>
                        </div>

                        <input type="hidden" id="actual_cash_on_hand" name="actual_cash_on_hand" value="0">
                        <button type="submit" class="btn success" style="width: 100%; font-size: 1.2em; padding: 15px; margin-top: 20px;" <?php echo (!$settlement_exists || $initial_cash_float == 0) ? 'disabled' : ''; ?>>精算する</button>
                    </form>
                </div>
            </div>

            <!-- 設定タブ -->
            <div id="settings" class="tab-content <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>⚙️ アプリケーション設定</h3>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="save_app_settings">
                        <div class="form-group">
                            <label for="tax_rate">税率 (%) :</label>
                            <input type="number" id="tax_rate" name="tax_rate" step="0.01" min="0" max="100" value="<?php echo htmlspecialchars($current_tax_rate); ?>" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="low_stock_threshold">低在庫アラート閾値 (個) :</label>
                            <input type="number" id="low_stock_threshold" name="low_stock_threshold" step="1" min="0" value="<?php echo htmlspecialchars($current_low_stock_threshold); ?>" class="form-input" required>
                        </div>
                        <button type="submit" class="btn success">設定を保存</button>
                    </form>
                </div>
                <div class="card">
                    <h3>店舗情報設定</h3>
                    <p>店舗名や住所、連絡先などの情報を設定します。</p>
                    <button class="btn" style="background: #6c757d;">編集 (未実装)</button>
                </div>
                <div class="card">
                    <h3>レシート設定</h3>
                    <p>レシートに表示するメッセージやロゴなどを設定します。</p>
                    <button class="btn" style="background: #6c757d;">編集 (未実装)</button>
                </div>
                <div class="card">
                    <h3>データ管理</h3>
                    <p>データベースのバックアップやデータのインポート/エクスポートなどを行います。</p>
                    <button class="btn" style="background: #6c757d;">実行 (未実装)</button>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <!-- 商品編集モーダル -->
    <div id="editItemModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditModal()">&times;</span>
            <h3 style="color: #667eea; margin-bottom: 15px;">📝 商品編集</h3>
            <div class="modal-body">
                <form id="editItemForm" method="POST" action="create.php">
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="id" id="modal_edit_id">
                    <div class="form-group">
                        <label for="modal_name">商品名 <span style="color: red;">*</span></label>
                        <input type="text" name="name" id="modal_name" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_category_id">カテゴリ <span style="color: red;">*</span></label>
                        <select name="category_id" id="modal_category_id" required>
                            <option value="">選択してください</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="modal_quantity">在庫数 <span style="color: red;">*</span></label>
                        <input type="number" name="quantity" id="modal_quantity" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_unit">単位 <span style="color: red;">*</span></label>
                        <input type="text" name="unit" id="modal_unit" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_reorder_level">発注点</label>
                        <input type="number" name="reorder_level" id="modal_reorder_level" min="0">
                    </div>
                    <div class="form-group">
                        <label for="modal_cost_price">仕入価格（円） <span style="color: red;">*</span></label>
                        <input type="number" name="cost_price" id="modal_cost_price" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_selling_price">販売価格（円） <span style="color: red;">*</span></label>
                        <input type="number" name="selling_price" id="modal_selling_price" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_supplier">仕入先</label>
                        <input type="text" name="supplier" id="modal_supplier">
                    </div>
                    <div class="form-group">
                        <label for="modal_expiry_date">賞味期限</label>
                        <input type="date" name="expiry_date" id="modal_expiry_date">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn success">💾 更新</button>
                        <button type="button" class="btn" style="background: #6c757d;" onclick="closeEditModal()">キャンセル</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // PHPから渡されたインベントリデータをJavaScriptで利用可能にする
        const allInventoryItems = <?php echo json_encode($inventory); ?>;
        const allCategories = <?php echo json_encode($categories); ?>;

        function switchTab(tabName) {
            document.querySelectorAll('.tab-button').forEach(button => button.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            document.querySelector(`.tab-button[onclick="switchTab(\'${tabName}\')"]`).classList.add('active');
            document.getElementById(tabName).classList.add('active');

            // URLのハッシュを更新 (ページリロードなしでタブ状態を維持)
            history.replaceState(null, null, '?tab=' + tabName);
        }

        // ページロード時にURLのタブパラメータをチェックして表示
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const initialTab = urlParams.get('tab');
            if (initialTab) {
                switchTab(initialTab);
            } else {
                // デフォルトタブをアクティブにする
                switchTab('inventory'); // select.phpのデフォルトタブを在庫一覧に設定
            }
        });

        // 商品編集モーダルを開く関数
        function openEditModal(itemData) {
            document.getElementById('modal_edit_id').value = itemData.id;
            document.getElementById('modal_name').value = itemData.name;
            document.getElementById('modal_category_id').value = itemData.category_id;
            document.getElementById('modal_quantity').value = itemData.quantity;
            document.getElementById('modal_unit').value = itemData.unit;
            document.getElementById('modal_cost_price').value = itemData.cost_price;
            document.getElementById('modal_selling_price').value = itemData.selling_price;
            document.getElementById('modal_reorder_level').value = itemData.reorder_level;
            document.getElementById('modal_supplier').value = itemData.supplier;
            document.getElementById('modal_expiry_date').value = itemData.expiry_date;

            document.getElementById('editItemModal').style.display = 'block';
        }

        // 商品編集モーダルを閉じる関数
        function closeEditModal() {
            document.getElementById('editItemModal').style.display = 'none';
        }

        // モーダル外クリックで閉じる
        window.onclick = function(event) {
            const modal = document.getElementById('editItemModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // 精算画面の金種計算
        const denominations = {
            'bill_10000': 10000,
            'bill_5000': 5000,
            'bill_1000': 1000,
            'coin_500': 500,
            'coin_100': 100,
            'coin_50': 50,
            'coin_10': 10,
            'coin_5': 5,
            'coin_1': 1
        };

        function calculateActualCash() {
            let totalActualCash = 0;
            for (const id in denominations) {
                const inputElement = document.getElementById(id);
                const count = parseInt(inputElement.value) || 0;
                totalActualCash += count * denominations[id];
            }
            document.getElementById('actual_cash_on_hand').value = totalActualCash;
            document.getElementById('actual_cash_total_display').textContent = totalActualCash.toLocaleString();
        }

        // 精算タブがアクティブになったときに金種計算を初期化
        document.addEventListener('DOMContentLoaded', function() {
            // 初期ロード時に精算タブがアクティブなら計算実行
            if (document.getElementById('settlement') && document.getElementById('settlement').classList.contains('active')) {
                calculateActualCash();
            }
            // タブが切り替わったときに計算実行
            document.querySelectorAll('.tab-button').forEach(button => {
                button.addEventListener('click', function() {
                    const tabName = this.onclick.toString().match(/switchTab\('([^']+)'\)/)[1];
                    if (tabName === 'settlement') {
                        // 少し遅延させて要素が描画されてから計算
                        setTimeout(calculateActualCash, 100); 
                    }
                });
            });
        });

        // すべての金種入力フィールドにイベントリスナーを設定
        document.querySelectorAll('.denomination-input').forEach(input => {
            input.addEventListener('input', calculateActualCash);
        });

        // 削除確認の強化 (在庫一覧タブ内)
        document.querySelectorAll('#inventory form button.danger').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('本当に削除しますか？\n※この操作は元に戻せません。')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
