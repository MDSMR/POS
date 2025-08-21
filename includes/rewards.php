<?php
// public_html/includes/rewards.php — Rewards engine (cash-back ladder only, backend)
// Requires: config/db.php provides db() + use_backend_session()
declare(strict_types=1);

/**
 * Notes:
 * - This module awards a single-use cashback voucher when an order (closed + paid) qualifies.
 * - Programs are read from `loyalty_programs` where:
 *      status = 'active', program_type = 'cashback', date window is valid.
 * - Enrollment progress is tracked in `loyalty_program_enrollments`.
 * - A single voucher is inserted into `vouchers`, and a ledger entry into `loyalty_ledger`.
 * - Idempotency: caller should only invoke on transitions (became closed/paid). We also
 *   perform basic eligibility checks to avoid double-issuing.
 */

function rw_db(): PDO { return db(); }

/**
 * Fetch active cashback programs for a tenant.
 * Decodes JSON earn_rule into array (safe).
 */
function rw_get_active_cashback_programs(int $tenantId): array {
    $pdo = rw_db();
    $sql = "SELECT id, tenant_id, name, status, program_type, earn_rule_json, start_at, end_at
            FROM loyalty_programs
            WHERE tenant_id = :t
              AND status = 'active'
              AND program_type = 'cashback'
              AND (start_at IS NULL OR start_at <= NOW())
              AND (end_at   IS NULL OR end_at   >= NOW())";
    $st = $pdo->prepare($sql);
    $st->execute([':t' => $tenantId]);
    $rows = $st->fetchAll() ?: [];

    foreach ($rows as &$r) {
        $r['earn_rule'] = null;
        if (!empty($r['earn_rule_json'])) {
            try {
                $decoded = json_decode((string)$r['earn_rule_json'], true, 512, JSON_THROW_ON_ERROR);
                // ensure array
                $r['earn_rule'] = is_array($decoded) ? $decoded : null;
            } catch (Throwable $e) {
                $r['earn_rule'] = null;
            }
        }
    }
    unset($r);
    return $rows;
}

/** Load a single order (any tenant); tenant check is done by caller. */
function rw_order_row(int $orderId): ?array {
    $pdo = rw_db();
    $st = $pdo->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
    $st->execute([':id' => $orderId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Get positive customer id for an order or null if not available. */
function rw_customer_id_for_order(array $ord): ?int {
    $cid = $ord['customer_id'] ?? null;
    if ($cid === null) return null;
    $cid = (int)$cid;
    return ($cid > 0) ? $cid : null;
}

/**
 * Check if an order qualifies for earning under a rule.
 * Rule keys supported:
 * - eligible_channels: ['pos','web','callcenter'] (default ['pos'])
 * - exclude_aggregators: bool (default false)
 * - exclude_discounted_orders: bool (default false)
 * - eligible_branches: 'all' | [branchId,...] (default 'all')
 * - min_order_amount: number (default 0)
 * - basis: 'subtotal_excl_tax_service' | 'subtotal_incl_tax_service' (default excl.)
 * Eligibility requires: status in ['closed','served','ready'] AND payment_status = 'paid' AND not voided.
 */
function rw_is_order_eligible(array $ord, array $rule): bool {
    // Channel check
    $chan    = $ord['source_channel'] ?? 'pos';
    $allowed = $rule['eligible_channels'] ?? ['pos'];
    if (is_array($allowed) && $allowed && !in_array($chan, $allowed, true)) return false;

    // Aggregators
    if (!empty($rule['exclude_aggregators'])) {
        $ag = (int)($ord['aggregator_id'] ?? 0);
        if ($ag > 0) return false;
    }

    // Exclude discounted orders
    if (!empty($rule['exclude_discounted_orders'])) {
        $disc = (float)($ord['discount_amount'] ?? 0);
        if ($disc > 0) return false;
    }

    // Branch filter
    if (!empty($rule['eligible_branches']) && $rule['eligible_branches'] !== 'all') {
        $list     = $rule['eligible_branches'];
        $branchId = (int)($ord['branch_id'] ?? 0);
        if (!is_array($list) || !in_array($branchId, array_map('intval', $list), true)) return false;
    }

    // Minimum order
    $min  = (float)($rule['min_order_amount'] ?? 0);
    $base = rw_order_base_amount($ord, $rule);
    if ($base + 0.00001 < $min) return false;

    // Status & payment gate: only when closed & paid & not voided/refunded
    $status   = (string)($ord['status'] ?? '');
    $pay      = (string)($ord['payment_status'] ?? '');
    $isVoided = ((int)($ord['is_voided'] ?? 0) === 1) || $status === 'voided' || $pay === 'voided';
    if ($isVoided) return false;
    if (!in_array($status, ['closed','served','ready'], true)) return false;
    if ($pay !== 'paid') return false;

    return true;
}

/** Compute the base amount used for cashback calculation according to the rule. */
function rw_order_base_amount(array $ord, array $rule): float {
    $basis    = $rule['basis'] ?? 'subtotal_excl_tax_service';
    $subtotal = (float)($ord['subtotal_amount'] ?? 0);
    $discount = (float)($ord['discount_amount'] ?? 0);
    $tax      = (float)($ord['tax_amount'] ?? 0);
    $service  = (float)($ord['service_amount'] ?? 0);

    switch ($basis) {
        case 'subtotal_incl_tax_service':
            return max(0.0, $subtotal + $tax + $service - $discount);
        case 'subtotal_excl_tax_service':
        default:
            return max(0.0, $subtotal - $discount);
    }
}

/**
 * Determine the next ladder tier to award on this qualifying visit.
 * Returns: ['enroll_row'=>?array, 'visit_count'=>int, 'tier'=>?array]
 */
function rw_next_ladder_tier(int $tenantId, int $programId, int $customerId, array $rule): array {
    $pdo = rw_db();
    // Fetch enrollment (if any)
    $st = $pdo->prepare("SELECT id, qualifying_visit_count
                         FROM loyalty_program_enrollments
                         WHERE tenant_id = :t AND program_id = :p AND customer_id = :c
                         LIMIT 1");
    $st->execute([':t'=>$tenantId, ':p'=>$programId, ':c'=>$customerId]);
    $row        = $st->fetch();
    $visitCount = $row ? (int)$row['qualifying_visit_count'] : 0;

    $ladder = $rule['ladder'] ?? [];
    // Normalize and sort ladder by 'visit' ascending
    $ladder = is_array($ladder) ? $ladder : [];
    usort($ladder, static fn($a, $b) => ((int)($a['visit'] ?? 0)) <=> ((int)($b['visit'] ?? 0)));

    $targetVisit = $visitCount + 1;
    $tier        = null;

    foreach ($ladder as $t) {
        if ((int)($t['visit'] ?? 0) === $targetVisit) { $tier = $t; break; }
    }

    if (!$tier) {
        $cycle = $rule['ladder_cycle'] ?? 'stop'; // 'stop' | 'loop'
        if ($cycle === 'loop' && count($ladder) > 0) {
            $tier = $ladder[($targetVisit - 1) % count($ladder)];
        } else {
            $tier = null; // no tier => stop earning
        }
    }

    return [
        'enroll_row'  => $row ?: null,
        'visit_count' => $visitCount,
        'tier'        => $tier,
    ];
}

/** Generate a unique voucher code for a tenant (attempts several times). */
function rw_generate_voucher_code(PDO $pdo, int $tenantId): string {
    for ($i = 0; $i < 10; $i++) {
        $code = 'CB' . strtoupper(bin2hex(random_bytes(4)));
        $st = $pdo->prepare("SELECT 1 FROM vouchers WHERE tenant_id = :t AND code = :c LIMIT 1");
        $st->execute([':t' => $tenantId, ':c' => $code]);
        if (!$st->fetchColumn()) return $code;
    }
    return 'CB' . (string)time();
}

/**
 * Issue cashback for a closed+paid order (idempotent via eligibility & caller transition guard).
 * @return array{issued:bool, voucher_id?:int, amount?:float, code?:string, reason?:string}
 */
function rewards_issue_cashback_for_order(int $tenantId, int $orderId): array {
    $pdo = rw_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Load & tenant-check order
    $ord = rw_order_row($orderId);
    if (!$ord || (int)$ord['tenant_id'] !== $tenantId) {
        return ['issued' => false, 'reason' => 'order_not_found'];
    }

    $customerId = rw_customer_id_for_order($ord);
    if (!$customerId) {
        return ['issued' => false, 'reason' => 'no_customer'];
    }

    $programs = rw_get_active_cashback_programs($tenantId);
    if (!count($programs)) {
        return ['issued' => false, 'reason' => 'no_program'];
    }

    $out = ['issued' => false];

    foreach ($programs as $pg) {
        $programId = (int)$pg['id'];
        $rule      = $pg['earn_rule'] ?? null;
        if (!$rule || !is_array($rule)) continue;

        // Eligibility
        if (!rw_is_order_eligible($ord, $rule)) continue;

        // Determine tier and compute cashback
        $tierInfo = rw_next_ladder_tier($tenantId, $programId, $customerId, $rule);
        $tier     = $tierInfo['tier'];
        if (!$tier) continue;

        $base = rw_order_base_amount($ord, $rule);
        $pct  = (float)($tier['percent'] ?? 0);
        $cash = round($base * ($pct / 100.0), 2);
        if ($cash <= 0) continue;

        // Expiry (optional)
        $expiresDays = (int)($tier['expires_days'] ?? 0);
        $expiresAt   = $expiresDays > 0 ? date('Y-m-d H:i:s', time() + $expiresDays * 86400) : null;

        // Begin award transaction
        $pdo->beginTransaction();
        try {
            // (1) Upsert enrollment and increment visit count
            if (!empty($tierInfo['enroll_row'])) {
                $pdo->prepare("
                    UPDATE loyalty_program_enrollments
                       SET qualifying_visit_count = qualifying_visit_count + 1,
                           last_qualifying_order_id = :o,
                           last_qualifying_at = NOW(),
                           updated_at = NOW()
                     WHERE id = :id
                     LIMIT 1
                ")->execute([
                    ':o'  => $orderId,
                    ':id' => $tierInfo['enroll_row']['id']
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO loyalty_program_enrollments
                        (tenant_id, program_id, customer_id, qualifying_visit_count, last_qualifying_order_id, last_qualifying_at, created_at)
                    VALUES
                        (:t, :p, :c, 1, :o, NOW(), NOW())
                ")->execute([
                    ':t' => $tenantId,
                    ':p' => $programId,
                    ':c' => $customerId,
                    ':o' => $orderId
                ]);
            }

            // (2) Create voucher (single-use, uses_remaining = 1)
            $code = rw_generate_voucher_code($pdo, $tenantId);
            $ins  = $pdo->prepare("
                INSERT INTO vouchers
                  (tenant_id, code, type, value, min_order_amount, max_discount_amount,
                   single_use, uses_remaining, starts_at, expires_at, status, pos_visible,
                   restrictions_json, created_at, updated_at)
                VALUES
                  (:t, :code, 'cashback', :val, 0.00, :val,
                   1, 1, NOW(), :exp, 'active', 1,
                   NULL, NOW(), NOW())
            ");
            $ins->execute([
                ':t'    => $tenantId,
                ':code' => $code,
                ':val'  => $cash,
                ':exp'  => $expiresAt
            ]);
            $voucherId = (int)$pdo->lastInsertId();

            // (3) Ledger entry
            $pdo->prepare("
                INSERT INTO loyalty_ledger
                    (tenant_id, program_id, customer_id, order_id, type, cash_delta, voucher_id, expires_at, reason, created_at)
                VALUES
                    (:t, :p, :c, :o, 'cashback_earn', :amt, :vid, :exp, 'cashback_ladder', NOW())
            ")->execute([
                ':t'   => $tenantId,
                ':p'   => $programId,
                ':c'   => $customerId,
                ':o'   => $orderId,
                ':amt' => $cash,
                ':vid' => $voucherId,
                ':exp' => $expiresAt
            ]);

            $pdo->commit();

            // Return success for the first program that issues a voucher
            $out = ['issued' => true, 'voucher_id' => $voucherId, 'amount' => $cash, 'code' => $code];
            break;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Skip this program; continue to the next if any
            continue;
        }
    }

    return $out;
}