<?php
declare(strict_types=1);

/**
 * Calculate monthly interest for a savings account.
 * 
 * @param int $account_id Savings account ID
 * @param int $period_month Month (1-12)
 * @param int $period_year Year (e.g., 2026)
 * @return array{balance_base: float, interest_rate: float, interest_amount: float, account_number: string, member_name: string, type_name: string}|null
 * @throws InvalidArgumentException
 */
function calculate_monthly_interest(int $account_id, int $period_month, int $period_year): ?array
{
    if ($period_month < 1 || $period_month > 12) {
        throw new InvalidArgumentException('Month must be between 1-12.');
    }
    if ($period_year < 2000 || $period_year > 2100) {
        throw new InvalidArgumentException('Invalid year.');
    }

    // Get account info with interest rate from savings type
    $stmt = db()->prepare('SELECT sa.id, sa.account_number, sa.balance, sa.status,
                                  m.full_name AS member_name,
                                  st.name AS type_name, st.interest_rate
                           FROM savings_accounts sa
                           JOIN members m ON sa.member_id = m.id
                           JOIN savings_types st ON sa.savings_type_id = st.id
                           WHERE sa.id = ?');
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();

    if (!$account) {
        return null;
    }

    // Skip if account is closed or has zero interest rate
    if ($account['status'] !== 'active' || (float) $account['interest_rate'] <= 0) {
        return null;
    }

    $balance = (float) $account['balance'];
    $annual_rate = (float) $account['interest_rate'];
    
    // Monthly interest = balance * (annual_rate / 100) / 12
    $interest_amount = round($balance * ($annual_rate / 100) / 12, 2);

    return [
        'balance_base' => $balance,
        'interest_rate' => $annual_rate,
        'interest_amount' => $interest_amount,
        'account_number' => $account['account_number'],
        'member_name' => $account['member_name'],
        'type_name' => $account['type_name'],
    ];
}

/**
 * Post interest for a savings account.
 * Creates savings_interest_history record and savings_transaction.
 * Must be called within a transaction.
 * 
 * @param int $account_id Savings account ID
 * @param int $period_month Month (1-12)
 * @param int $period_year Year
 * @param int $posted_by User ID posting the interest
 * @return bool Success
 * @throws Exception
 */
function post_interest_for_account(int $account_id, int $period_month, int $period_year, int $posted_by): bool
{
    $interest = calculate_monthly_interest($account_id, $period_month, $period_year);
    
    if (!$interest || $interest['interest_amount'] <= 0) {
        return false; // Skip accounts with no interest
    }

    // Period dates
    $period_start = sprintf('%04d-%02d-01', $period_year, $period_month);
    $last_day = date('t', strtotime($period_start));
    $period_end = sprintf('%04d-%02d-%02d', $period_year, $period_month, $last_day);
    $posted_date = date('Y-m-d');

    // Check if already posted for this period
    $stmt = db()->prepare('SELECT id FROM savings_interest_history 
                          WHERE savings_account_id = ? 
                          AND period_start = ? AND period_end = ?');
    $stmt->bind_param('iss', $account_id, $period_start, $period_end);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        throw new Exception('Interest sudah diposting untuk periode ini.');
    }

    // Lock account and get current balance
    $stmt = db()->prepare('SELECT balance FROM savings_accounts WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $locked = $stmt->get_result()->fetch_assoc();
    if (!$locked) {
        throw new Exception('Rekening tidak ditemukan.');
    }

    $balance_before = (float) $locked['balance'];
    $interest_amount = $interest['interest_amount'];
    $balance_after = $balance_before + $interest_amount;

    // Insert interest history
    $stmt = db()->prepare('INSERT INTO savings_interest_history 
                          (savings_account_id, period_start, period_end, balance_base, 
                           interest_rate, interest_amount, posted_date, posted_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('issdddsi', 
        $account_id, $period_start, $period_end, 
        $interest['balance_base'], $interest['interest_rate'], 
        $interest_amount, $posted_date, $posted_by
    );
    $stmt->execute();

    // Insert transaction
    $description = sprintf('Bunga %s %d', 
        ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][$period_month],
        $period_year
    );
    $stmt = db()->prepare('INSERT INTO savings_transactions 
                          (savings_account_id, transaction_type, amount, balance_before, 
                           balance_after, transaction_date, description, processed_by)
                          VALUES (?, "deposit", ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('idddssi', 
        $account_id, $interest_amount, $balance_before, $balance_after, 
        $posted_date, $description, $posted_by
    );
    $stmt->execute();

    // Update account balance and last interest date
    $stmt = db()->prepare('UPDATE savings_accounts 
                          SET balance = ?, last_interest_date = ? 
                          WHERE id = ?');
    $stmt->bind_param('dsi', $balance_after, $posted_date, $account_id);
    $stmt->execute();

    return true;
}

/**
 * Get all eligible accounts for interest posting in a period.
 * 
 * @param int $period_month Month (1-12)
 * @param int $period_year Year
 * @return array Array of account data with calculated interest
 */
function get_accounts_for_interest_posting(int $period_month, int $period_year): array
{
    // Get all active accounts with positive interest rates
    $stmt = db()->prepare('SELECT sa.id, sa.account_number, sa.balance, sa.last_interest_date,
                                  m.member_number, m.full_name,
                                  st.name AS type_name, st.interest_rate
                           FROM savings_accounts sa
                           JOIN members m ON sa.member_id = m.id
                           JOIN savings_types st ON sa.savings_type_id = st.id
                           WHERE sa.status = "active" AND st.interest_rate > 0
                           ORDER BY m.full_name, st.id');
    $stmt->execute();
    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $period_start = sprintf('%04d-%02d-01', $period_year, $period_month);
    $last_day = date('t', strtotime($period_start));
    $period_end = sprintf('%04d-%02d-%02d', $period_year, $period_month, $last_day);

    $result = [];
    foreach ($accounts as $acc) {
        // Check if already posted
        $stmt = db()->prepare('SELECT id FROM savings_interest_history 
                              WHERE savings_account_id = ? 
                              AND period_start = ? AND period_end = ?');
        $stmt->bind_param('iss', $acc['id'], $period_start, $period_end);
        $stmt->execute();
        $posted = $stmt->get_result()->fetch_assoc();

        $interest = calculate_monthly_interest((int) $acc['id'], $period_month, $period_year);
        
        $result[] = [
            'id' => $acc['id'],
            'account_number' => $acc['account_number'],
            'member_number' => $acc['member_number'],
            'member_name' => $acc['full_name'],
            'type_name' => $acc['type_name'],
            'balance' => (float) $acc['balance'],
            'interest_rate' => (float) $acc['interest_rate'],
            'interest_amount' => $interest ? $interest['interest_amount'] : 0.0,
            'last_interest_date' => $acc['last_interest_date'],
            'already_posted' => (bool) $posted,
        ];
    }

    return $result;
}
