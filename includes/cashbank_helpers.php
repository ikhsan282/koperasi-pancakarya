<?php
declare(strict_types=1);

/**
 * Post a cash/bank transaction with atomic balance update
 * 
 * @param int $account_id Cash/bank account ID
 * @param string $type 'debit' (masuk) or 'credit' (keluar)
 * @param float $amount Transaction amount
 * @param string $ref_type Reference type enum value
 * @param int|null $ref_id Reference ID to source transaction
 * @param string $description Transaction description
 * @param string $date Transaction date (Y-m-d format)
 * @return int Transaction ID
 * @throws Exception on failure
 */
function post_cashbank_transaction(
    int $account_id,
    string $type,
    float $amount,
    string $ref_type,
    ?int $ref_id,
    string $description,
    string $date
): int {
    if (!in_array($type, ['debit', 'credit'])) {
        throw new InvalidArgumentException("Invalid transaction type: {$type}");
    }
    
    if ($amount <= 0) {
        throw new InvalidArgumentException("Amount must be positive: {$amount}");
    }
    
    $db = db();
    $db->begin_transaction();
    
    try {
        // Lock account row and get current balance
        $stmt = $db->prepare('SELECT balance, is_active FROM cash_bank_accounts WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $account_id);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        
        if (!$account) {
            throw new RuntimeException("Account not found: {$account_id}");
        }
        
        if (!$account['is_active']) {
            throw new RuntimeException("Account is inactive: {$account_id}");
        }
        
        $balance_before = (float) $account['balance'];
        $balance_after = $type === 'debit' 
            ? $balance_before + $amount 
            : $balance_before - $amount;
        
        if ($balance_after < 0) {
            throw new RuntimeException("Insufficient balance. Current: " . rupiah($balance_before) . ", Required: " . rupiah($amount));
        }
        
        // Insert transaction
        $user_id = current_user()['id'] ?? null;
        $stmt = $db->prepare(
            'INSERT INTO cash_bank_transactions 
            (account_id, transaction_type, amount, balance_before, balance_after, 
             reference_type, reference_id, description, transaction_date, processed_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'isdddsissi',
            $account_id, $type, $amount, $balance_before, $balance_after,
            $ref_type, $ref_id, $description, $date, $user_id
        );
        $stmt->execute();
        $transaction_id = $db->insert_id;
        
        // Update account balance
        $stmt = $db->prepare('UPDATE cash_bank_accounts SET balance = ? WHERE id = ?');
        $stmt->bind_param('di', $balance_after, $account_id);
        $stmt->execute();
        
        $db->commit();
        return $transaction_id;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Get default cash/bank account (first active account)
 * @return int|null Account ID or null if none exists
 */
function get_default_cashbank_account(): ?int
{
    $stmt = db()->prepare('SELECT id FROM cash_bank_accounts WHERE is_active = 1 ORDER BY id LIMIT 1');
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? (int) $result['id'] : null;
}

/**
 * Get cash/bank account details
 * @return array|null Account data or null if not found
 */
function get_cashbank_account(int $account_id): ?array
{
    $stmt = db()->prepare('SELECT * FROM cash_bank_accounts WHERE id = ?');
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Get total balance across all active accounts
 * @return float Total balance
 */
function get_total_cashbank_balance(): float
{
    $stmt = db()->query('SELECT COALESCE(SUM(balance), 0) AS total FROM cash_bank_accounts WHERE is_active = 1');
    return (float) $stmt->fetch_assoc()['total'];
}
