<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';

pos_require_user();
if (!has_permission('create_order')) {
    json_out(['ok'=>false,'error'=>'Forbidden'],403);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) json_out(['ok'=>false,'error'=>'Invalid payload'],400);

$user = pos_user();
$tenantId = tenant_id();
$branchId = 1;

$tableId = isset($input['table_id']) ? (int)$input['table_id'] : null;
$guests  = isset($input['guests']) ? max(1, (int)$input['guests']) : null;
$items   = $input['items'] ?? [];
if (!$items || !is_array($items)) json_out(['ok'=>false,'error'=>'Cart is empty'],422);

function get_setting(PDO $pdo, int $tenantId, string $key, $default='0.00'){
    $stmt=$pdo->prepare("SELECT `value` FROM settings WHERE tenant_id=:t AND `key`=:k LIMIT 1");
    $stmt->execute([':t'=>$tenantId,':k'=>$key]);
    $row=$stmt->fetch();
    return $row ? $row['value'] : $default;
}

$pdo = db();
$taxPercent = (float)get_setting($pdo, $tenantId, 'tax_percent', '0.00');
$servicePercent = (float)get_setting($pdo, $tenantId, 'service_percent', '0.00');

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO orders
        (tenant_id, branch_id, user_id, table_id, customer_name, order_type, status,
         guest_count, aggregator_id, commission_percent, commission_amount,
         subtotal_amount, tax_percent, tax_amount, service_percent, service_amount,
         discount_amount, total_amount, payment_status, payment_method, order_reference)
        VALUES
        (:tenant_id, :branch_id, :user_id, :table_id, NULL, 'dine_in', 'pending',
         :guest_count, NULL, 0.00, 0.00,
         0.00, :tax_percent, 0.00, :service_percent, 0.00,
         0.00, 0.00, 'unpaid', 'cash', NULL)
    ");
    $stmt->execute([
        ':tenant_id'      => (int)$tenantId,
        ':branch_id'      => (int)$branchId,
        ':user_id'        => (int)$user['id'],
        ':table_id'       => $tableId ?: null,
        ':guest_count'    => $guests,
        ':tax_percent'    => $taxPercent,
        ':service_percent'=> $servicePercent,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $subtotal = 0.0;

    foreach ($items as $line) {
        $qty = isset($line['qty']) ? max(1,(int)$line['qty'])
             : (isset($line['quantity']) ? max(1,(int)$line['quantity']) : 1);

        if (!empty($line['product_id'])) {
            $pid = (int)$line['product_id'];
            $ps = $pdo->prepare("SELECT id, name_en, price FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
            $ps->execute([':id'=>$pid, ':t'=>$tenantId]);
            $prod = $ps->fetch();
            if (!$prod) throw new RuntimeException('Product not found: '.$pid);
            $name = $prod['name_en']; $unit = (float)$prod['price'];
        } else {
            $name = trim((string)($line['name'] ?? 'Item'));
            $unit = (float)($line['price'] ?? 0.0);
        }

        $lineSubtotal = $unit * $qty;
        $subtotal += $lineSubtotal;

        $ins = $pdo->prepare("
            INSERT INTO order_items
            (order_id, product_id, product_name, unit_price, quantity, notes, line_subtotal)
            VALUES (:order_id, :product_id, :product_name, :unit_price, :quantity, :notes, :line_subtotal)
        ");
        $ins->execute([
            ':order_id'     => $orderId,
            ':product_id'   => !empty($line['product_id']) ? (int)$line['product_id'] : 0,
            ':product_name' => $name,
            ':unit_price'   => $unit,
            ':quantity'     => $qty,
            ':notes'        => isset($line['notes']) ? substr((string)$line['notes'],0,500) : null,
            ':line_subtotal'=> $lineSubtotal,
        ]);

        if (!empty($line['variations']) && is_array($line['variations'])) {
            $orderItemId = (int)$pdo->lastInsertId();
            $vstmt = $pdo->prepare("
                INSERT INTO order_item_variations
                (order_item_id, variation_group, variation_value, price_delta)
                VALUES (:oi, :g, :v, :d)
            ");
            foreach ($line['variations'] as $v) {
                $vg = substr((string)($v['group'] ?? ''), 0, 120);
                $vv = substr((string)($v['value'] ?? ''), 0, 120);
                $vd = (float)($v['price_delta'] ?? 0.0);
                $vstmt->execute([':oi'=>$orderItemId, ':g'=>$vg, ':v'=>$vv, ':d'=>$vd]);
                $subtotal += $vd;
            }
        }
    }

    $taxAmount = round($subtotal * ($taxPercent / 100), 3);
    $serviceAmount = round($subtotal * ($servicePercent / 100), 3);
    $discountAmount = 0.00;
    $total = round($subtotal + $taxAmount + $serviceAmount - $discountAmount, 3);

    $upd = $pdo->prepare("
        UPDATE orders
        SET subtotal_amount = :subtotal,
            tax_amount      = :tax_amount,
            service_amount  = :service_amount,
            discount_amount = :discount_amount,
            total_amount    = :total_amount
        WHERE id = :id
    ");
    $upd->execute([
        ':subtotal'       => $subtotal,
        ':tax_amount'     => $taxAmount,
        ':service_amount' => $serviceAmount,
        ':discount_amount'=> $discountAmount,
        ':total_amount'   => $total,
        ':id'             => $orderId,
    ]);

    $pdo->commit();
    json_out(['ok'=>true,'order_id'=>$orderId,'total'=>$total]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'Failed to create order'],500);
}