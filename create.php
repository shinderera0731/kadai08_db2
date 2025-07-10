<?php
// 共通設定ファイルを読み込み
include 'config.php';

// POSTデータが送信されているかチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = '不正なアクセスです。';
    header('Location: index.php');
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // データベーステーブル作成
        case 'create_tables':
            if (createTables($pdo)) {
                $_SESSION['message'] = '✅ データベーステーブルが正常に作成されました。システムの準備が完了しました！';
            } else {
                $_SESSION['error'] = '❌ テーブル作成に失敗しました。';
            }
            header('Location: index.php');
            exit;

        // 新商品追加 (inventoryテーブル用)
        case 'add_item':
            // 入力値の検証
            $name = trim($_POST['name'] ?? '');
            $category_id = (int)($_POST['category_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            $cost_price = (float)($_POST['cost_price'] ?? 0);
            $selling_price = (float)($_POST['selling_price'] ?? 0);
            $reorder_level = (int)($_POST['reorder_level'] ?? 10);
            $supplier = trim($_POST['supplier'] ?? '') ?: null;
            $expiry_date = $_POST['expiry_date'] ?: null;

            // 必須項目チェック
            if (empty($name) || $category_id <= 0 || empty($unit) || $cost_price < 0 || $selling_price < 0) {
                $_SESSION['error'] = '❌ 必須項目が入力されていないか、不正な値が含まれています。';
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            // 同名商品の重複チェック
            $stmt = $pdo->prepare("SELECT id FROM inventory WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $_SESSION['error'] = "❌ 商品「{$name}」は既に登録されています。";
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            // 商品追加
            $stmt = $pdo->prepare("INSERT INTO inventory (name, category_id, quantity, unit, cost_price, selling_price, reorder_level, supplier, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name,
                $category_id,
                $quantity,
                $unit,
                $cost_price,
                $selling_price,
                $reorder_level,
                $supplier,
                $expiry_date
            ]);

            $item_id = $pdo->lastInsertId();

            // 初期在庫の履歴記録
            if ($quantity > 0) {
                $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, created_by) VALUES (?, '入庫', ?, '新商品登録', 'システム')");
                $stmt->execute([$item_id, $quantity]);
            }

            $_SESSION['message'] = "✅ 商品「{$name}」が正常に追加されました。初期在庫: {$quantity}{$unit}";
            header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
            exit;

        // 在庫更新（入出庫処理）(inventoryテーブル用)
        case 'update_stock':
            $item_id = (int)($_POST['item_id'] ?? 0);
            $new_quantity = (int)($_POST['new_quantity'] ?? 0);
            $movement_type = $_POST['movement_type'] ?? '';
            $reason = trim($_POST['reason'] ?? '') ?: null;

            // 入力値の検証
            if ($item_id <= 0 || $new_quantity <= 0 || empty($movement_type)) {
                $_SESSION['error'] = '❌ 必須項目が入力されていないか、不正な値が含まれています。';
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            // 有効な処理種別かチェック
            $valid_types = ['入庫', '出庫', '廃棄', '調整'];
            if (!in_array($movement_type, $valid_types)) {
                $_SESSION['error'] = '❌ 無効な処理種別です。';
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            // 現在の在庫数取得
            $stmt = $pdo->prepare("SELECT name, quantity, unit FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                $_SESSION['error'] = '❌ 指定された商品が見つかりません。';
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            $old_quantity = $current['quantity'];
            $item_name = $current['name'];
            $unit = $current['unit'];

            // 新しい在庫数計算
            switch ($movement_type) {
                case '入庫':
                    $final_quantity = $old_quantity + $new_quantity;
                    $change_amount = $new_quantity;
                    break;
                    
                case '出庫':
                case '廃棄':
                    if ($new_quantity > $old_quantity) {
                        $_SESSION['error'] = "❌ {$movement_type}数量（{$new_quantity}）が現在の在庫数（{$old_quantity}）を超えています。";
                        header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                        exit;
                    }
                    $final_quantity = $old_quantity - $new_quantity;
                    $change_amount = $new_quantity;
                    break;
                    
                case '調整':
                    // 調整の場合は、入力値を最終在庫数として扱う
                    $final_quantity = $new_quantity;
                    $change_amount = abs($new_quantity - $old_quantity);
                    break;
            }

            // 在庫数更新
            $stmt = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
            $stmt->execute([$final_quantity, $item_id]);

            // 履歴記録
            if ($movement_type === '調整') {
                // 調整の場合は、増減に応じて履歴を記録
                if ($new_quantity > $old_quantity) {
                    $log_type = '入庫';
                    $log_reason = $reason ?: '棚卸調整（増加）';
                } else {
                    $log_type = '出庫';
                    $log_reason = $reason ?: '棚卸調整（減少）';
                }
            } else {
                $log_type = $movement_type;
                $log_reason = $reason ?: $movement_type;
            }

            $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, created_by) VALUES (?, ?, ?, ?, 'システム')");
            $stmt->execute([$item_id, $log_type, $change_amount, $log_reason]);

            // 成功メッセージ
            $operation_desc = [
                '入庫' => '入庫しました',
                '出庫' => '出庫しました',
                '廃棄' => '廃棄しました',
                '調整' => '調整しました'
            ];

            $_SESSION['message'] = "✅ 「{$item_name}」を{$operation_desc[$movement_type]}。" . 
                                 " 変更: {$old_quantity}{$unit} → {$final_quantity}{$unit}";

            // 在庫不足警告
            $stmt = $pdo->prepare("SELECT reorder_level FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $reorder_level = $stmt->fetchColumn();

            if ($final_quantity <= $reorder_level) {
                $_SESSION['message'] .= " ⚠️ 発注点を下回りました！";
            }

            header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
            exit;

        // 商品削除 (inventoryテーブル用)
        case 'delete_item':
            $item_id = (int)($_POST['item_id'] ?? 0);

            if ($item_id <= 0) {
                $_SESSION['error'] = '❌ 無効な商品IDです。';
                header('Location: select.php?tab=inventory'); // タブ指定を追加
                exit;
            }

            // 商品名を取得
            $stmt = $pdo->prepare("SELECT name FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $item_name = $stmt->fetchColumn();

            if (!$item_name) {
                $_SESSION['error'] = '❌ 指定された商品が見つかりません。';
                header('Location: select.php?tab=inventory'); // タブ指定を追加
                exit;
            }

            // 関連する履歴データも削除
            $stmt = $pdo->prepare("DELETE FROM stock_movements WHERE item_id = ?");
            $stmt->execute([$item_id]);

            // 商品削除
            $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);

            $_SESSION['message'] = "✅ 商品「{$item_name}」とその履歴データを削除しました。";
            header('Location: select.php?tab=inventory'); // タブ指定を追加
            exit;

        // 商品更新 (inventoryテーブル用)
        case 'update_item':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $category_id = (int)($_POST['category_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0); // quantityはstockに相当
            $unit = trim($_POST['unit'] ?? '');
            $cost_price = (float)($_POST['cost_price'] ?? 0);
            $selling_price = (float)($_POST['selling_price'] ?? 0);
            $reorder_level = (int)($_POST['reorder_level'] ?? 10);
            $supplier = trim($_POST['supplier'] ?? '') ?: null;
            $expiry_date = $_POST['expiry_date'] ?: null;

            if ($id <= 0 || empty($name) || $category_id <= 0 || empty($unit) || $cost_price < 0 || $selling_price < 0 || $quantity < 0) {
                $_SESSION['error'] = '❌ 必須項目が入力されていないか、不正な値が含まれています。';
                header('Location: select.php?tab=inventory'); // 編集モードに戻る
                exit;
            }

            // 同名商品の重複チェック (自身を除く)
            $stmt = $pdo->prepare("SELECT id FROM inventory WHERE name = ? AND id != ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                $_SESSION['error'] = "❌ 商品「{$name}」は既に登録されています。別の商品名を使用してください。";
                header('Location: select.php?tab=inventory'); // 編集モードに戻る
                exit;
            }

            $stmt = $pdo->prepare("UPDATE inventory SET name = ?, category_id = ?, quantity = ?, unit = ?, cost_price = ?, selling_price = ?, reorder_level = ?, supplier = ?, expiry_date = ? WHERE id = ?");
            $stmt->execute([
                $name,
                $category_id,
                $quantity,
                $unit,
                $cost_price,
                $selling_price,
                $reorder_level,
                $supplier,
                $expiry_date,
                $id
            ]);

            $_SESSION['message'] = "✅ 商品「{$name}」が正常に更新されました。";
            header('Location: select.php?tab=inventory'); // 在庫一覧に戻る
            exit;

        // レジ会計処理 (transactionsテーブルとinventoryテーブル用)
        case 'checkout':
            // セッションからカート情報を取得
            if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
                $_SESSION['error'] = '❌ カートが空です。';
                header('Location: input.php?tab=pos'); // タブ指定を追加
                exit;
            }

            // 税率の読み込み
            $tax_rate = 10; // デフォルト税率
            $stmt_tax = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'tax_rate'");
            $stmt_tax->execute();
            $result_tax = $stmt_tax->fetch(PDO::FETCH_ASSOC);
            if ($result_tax) {
                $tax_rate = (float)$result_tax['setting_value'];
            }

            $subtotal_amount = 0;
            foreach ($_SESSION['cart'] as $item) {
                $subtotal_amount += $item['price'] * $item['quantity'];
            }

            $tax_amount = $subtotal_amount * ($tax_rate / 100);
            $total_amount = $subtotal_amount + $tax_amount;

            $cash_received = (float)($_POST['cash_received'] ?? 0);

            if ($cash_received < $total_amount) {
                $_SESSION['error'] = '❌ 受取金額が合計金額より少ないです。';
                header('Location: input.php?tab=pos'); // タブ指定を追加
                exit;
            }

            $pdo->beginTransaction(); // トランザクション開始
            $stock_update_success = true;

            // inventoryテーブルの在庫を減らす
            foreach ($_SESSION['cart'] as $item) {
                $stmt_update_stock = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
                $stmt_update_stock->execute([$item['quantity'], $item['id'], $item['quantity']]);
                if ($stmt_update_stock->rowCount() === 0) {
                    $stock_update_success = false;
                    break; // 在庫不足または更新失敗
                }
            }

            if ($stock_update_success) {
                $change_given = $cash_received - $total_amount;
                $items_json = json_encode(array_values($_SESSION['cart']));

                // transactionsテーブルに記録
                $stmt_insert_transaction = $pdo->prepare("INSERT INTO transactions (total_amount, cash_received, change_given, items_json) VALUES (?, ?, ?, ?)");
                $stmt_insert_transaction->execute([$total_amount, $cash_received, $change_given, $items_json]);

                $pdo->commit(); // 全ての操作が成功したらコミット
                $_SESSION['message'] = "✅ 会計が完了しました！お釣り: ¥" . number_format($change_given, 0);

                // 会計後の在庫アラートチェック (inventoryテーブル)
                $low_stock_threshold = 5; // デフォルト閾値
                $stmt_threshold = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'low_stock_threshold'");
                $stmt_threshold->execute();
                $result_threshold = $stmt_threshold->fetch(PDO::FETCH_ASSOC);
                if ($result_threshold) {
                    $low_stock_threshold = (int)$result_threshold['setting_value'];
                }

                foreach ($_SESSION['cart'] as $item) {
                    $stmt_check_stock = $pdo->prepare("SELECT name, quantity FROM inventory WHERE id = ?");
                    $stmt_check_stock->execute([$item['id']]);
                    $current_stock_data = $stmt_check_stock->fetch(PDO::FETCH_ASSOC);
                    
                    if ($current_stock_data && $current_stock_data['quantity'] <= $low_stock_threshold) {
                        $_SESSION['message'] .= "<br>**在庫アラート:** " . htmlspecialchars($current_stock_data['name']) . " の在庫が残り " . htmlspecialchars($current_stock_data['quantity']) . " 個です。閾値: " . htmlspecialchars($low_stock_threshold) . "個";
                    }
                }
                $_SESSION['cart'] = []; // カートをクリア

            } else {
                $pdo->rollBack(); // 失敗したらロールバック
                $_SESSION['error'] = '❌ 会計中に在庫不足が発生しました。再度ご確認ください。';
            }
            header('Location: input.php?tab=pos'); // タブ指定を追加
            exit;

        // 釣銭準備金の設定/更新 (daily_settlementテーブル用)
        case 'set_cash_float':
            $new_cash_float = (float)($_POST['initial_cash_float'] ?? 0);
            $settlement_date = date('Y-m-d');

            if ($new_cash_float < 0) {
                $_SESSION['error'] = '❌ 釣銭準備金は0以上で入力してください。';
                header('Location: select.php?tab=settlement'); // タブ指定を追加
                exit;
            }

            // 今日の売上合計を取得
            $total_sales_cash = $pdo->query("SELECT SUM(total_amount) FROM transactions WHERE DATE(transaction_date) = CURDATE()")->fetchColumn() ?? 0;
            $expected_cash_on_hand = $new_cash_float + $total_sales_cash;

            // 既存レコードの確認
            $stmt_check = $pdo->prepare("SELECT id FROM daily_settlement WHERE settlement_date = ?");
            $stmt_check->execute([$settlement_date]);
            $existing_settlement = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($existing_settlement) {
                // 更新
                $stmt_update = $pdo->prepare("UPDATE daily_settlement SET initial_cash_float = ?, total_sales_cash = ?, expected_cash_on_hand = ? WHERE settlement_date = ?");
                $stmt_update->execute([$new_cash_float, $total_sales_cash, $expected_cash_on_hand, $settlement_date]);
            } else {
                // 挿入
                $stmt_insert = $pdo->prepare("INSERT INTO daily_settlement (settlement_date, initial_cash_float, total_sales_cash, expected_cash_on_hand) VALUES (?, ?, ?, ?)");
                $stmt_insert->execute([$settlement_date, $new_cash_float, $total_sales_cash, $expected_cash_on_hand]);
            }

            $_SESSION['message'] = '✅ 釣銭準備金が正常に設定されました。';
            header('Location: select.php?tab=settlement'); // タブ指定を追加
            exit;
        
        // 精算処理 (daily_settlementテーブル用)
        case 'settle_up':
            $actual_cash_on_hand = (float)($_POST['actual_cash_on_hand'] ?? 0);
            $settlement_date = date('Y-m-d');

            // 今日の精算データを取得
            $stmt_data = $pdo->prepare("SELECT initial_cash_float, total_sales_cash FROM daily_settlement WHERE settlement_date = ?");
            $stmt_data->execute([$settlement_date]);
            $daily_data = $stmt_data->fetch(PDO::FETCH_ASSOC);

            if (!$daily_data) {
                $_SESSION['error'] = '❌ 今日の釣銭準備金が設定されていません。先に設定してください。';
                header('Location: select.php?tab=settlement'); // タブ指定を追加
                exit;
            }

            $expected_cash_on_hand = $daily_data['initial_cash_float'] + $daily_data['total_sales_cash'];
            $discrepancy = $actual_cash_on_hand - $expected_cash_on_hand;

            $stmt_update = $pdo->prepare("UPDATE daily_settlement SET actual_cash_on_hand = ?, discrepancy = ? WHERE settlement_date = ?");
            $stmt_update->execute([$actual_cash_on_hand, $discrepancy, $settlement_date]);

            $_SESSION['message'] = '✅ 精算が完了しました！差異: ¥' . number_format($discrepancy, 0);
            header('Location: select.php?tab=settlement'); // タブ指定を追加
            exit;

        // アプリケーション設定保存 (app_settingsテーブル用)
        case 'save_app_settings':
            if (isset($_POST['tax_rate'])) {
                $new_tax_rate = (float)($_POST['tax_rate'] ?? 10);
                if ($new_tax_rate >= 0 && $new_tax_rate <= 100) {
                    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('tax_rate', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$new_tax_rate, $new_tax_rate]);
                    $_SESSION['message'] = '✅ 税率が正常に保存されました。';
                } else {
                    $_SESSION['error'] = '❌ 税率は0から100の間の数値を入力してください。';
                }
            }
            if (isset($_POST['low_stock_threshold'])) {
                $new_low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 5);
                if ($new_low_stock_threshold >= 0) {
                    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('low_stock_threshold', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$new_low_stock_threshold, $new_low_stock_threshold]);
                    if (!isset($_SESSION['message'])) { // 税率メッセージがなければ
                        $_SESSION['message'] = '✅ 低在庫アラート閾値が正常に保存されました。';
                    }
                } else {
                    if (!isset($_SESSION['error'])) { // 税率エラーがなければ
                        $_SESSION['error'] = '❌ 低在庫アラート閾値は0以上の数値を入力してください。';
                    }
                }
            }
            header('Location: select.php?tab=settings'); // 設定タブにリダイレクト
            exit;

        default:
            $_SESSION['error'] = '❌ 無効な操作です。';
            header('Location: index.php');
            exit;
    }

} catch (PDOException $e) {
    // データベースエラー
    error_log("Database Error: " . $e->getMessage());
    $_SESSION['error'] = '❌ データベースエラーが発生しました。しばらく待ってから再度お試しください。' . $e->getMessage();
    
    // エラーの種類に応じてリダイレクト先を決定
    if (in_array($action, ['add_item', 'update_stock', 'update_item'])) {
        // add_item, update_stock, update_item の場合は input.php の inventory_ops タブへ
        $redirect_tab = 'inventory_ops';
        if ($action === 'update_item' && isset($_POST['id'])) {
            $redirect_url = 'input.php?tab=' . $redirect_tab . '&edit_id=' . (int)$_POST['id'];
        } else {
            $redirect_url = 'input.php?tab=' . $redirect_tab;
        }
        header('Location: ' . $redirect_url);
    } elseif ($action === 'checkout') {
        header('Location: input.php?tab=pos');
    } elseif ($action === 'set_cash_float' || $action === 'settle_up' || $action === 'save_app_settings' || $action === 'delete_item') {
        // set_cash_float, settle_up, save_app_settings, delete_item の場合は select.php の適切なタブへ
        $redirect_tab = 'inventory'; // デフォルト
        if ($action === 'set_cash_float' || $action === 'settle_up') {
            $redirect_tab = 'settlement';
        } elseif ($action === 'save_app_settings') {
            $redirect_tab = 'settings';
        }
        header('Location: select.php?tab=' . $redirect_tab);
    } else {
        header('Location: index.php');
    }
    exit;
    
} catch (Exception $e) {
    // その他のエラー
    error_log("General Error: " . $e->getMessage());
    $_SESSION['error'] = '❌ システムエラーが発生しました。管理者にお問い合わせください。';
    header('Location: index.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>処理完了 - 🏰 Cinderella cafe</title>
    <?php echo getCommonCSS(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏰 Cinderella cafe</h1>
            <p>処理完了</p>
        </div>

        <div class="content">
            <div class="card">
                <h3>⚠️ 予期しないエラー</h3>
                <p>処理中に予期しないエラーが発生しました。</p>
                <p>自動でリダイレクトされない場合は、以下のリンクをクリックしてください。</p>
                
                <div style="text-align: center; margin-top: 30px;">
                    <a href="index.php" class="btn">🏠 ホームに戻る</a>
                    <a href="select.php" class="btn">📊 在庫一覧に戻る</a>
                    <a href="input.php" class="btn">➕ 入力画面に戻る</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 3秒後に自動リダイレクト
        setTimeout(function() {
            window.location.href = 'index.php';
        }, 3000);
    </script>
</body>
</html>
