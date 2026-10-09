<?php
/**
 * Atomic sequence generation for unique identifiers
 * Prevents race conditions in concurrent operations
 */

/**
 * Generate next sequence number atomically
 * Uses LAST_INSERT_ID() trick for atomic increment without gaps
 * 
 * @param string $sequence_name Unique name for this sequence
 * @return int Next sequence value
 */
function next_sequence($sequence_name) {
    $db = db();
    
    // Ensure sequence exists
    $stmt = $db->prepare('INSERT INTO sequences (name, current_value) VALUES (?, 0) ON DUPLICATE KEY UPDATE name = name');
    $stmt->bind_param('s', $sequence_name);
    $stmt->execute();
    
    // Atomic increment: UPDATE returns LAST_INSERT_ID with new value
    $stmt = $db->prepare('UPDATE sequences SET current_value = LAST_INSERT_ID(current_value + 1) WHERE name = ?');
    $stmt->bind_param('s', $sequence_name);
    $stmt->execute();
    
    return (int) $db->insert_id;
}

/**
 * Generate member number atomically
 * Format: KP-YYYYMM-XXXX
 * 
 * @return string Member number
 */
function generate_member_number() {
    $prefix = 'KP-' . date('Ym') . '-';
    $sequence_name = 'member_number_' . date('Ym');
    $next = next_sequence($sequence_name);
    return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate loan number atomically
 * Format: L-YYYYMM-XXXX
 * 
 * @return string Loan number
 */
function generate_loan_number() {
    $prefix = 'L-' . date('Ym') . '-';
    $sequence_name = 'loan_number_' . date('Ym');
    $next = next_sequence($sequence_name);
    return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate savings account number atomically
 * Format: SIM-{member_number}-{type_id}-XX
 * 
 * @param string $member_number Member number
 * @param int $type_id Savings type ID
 * @return string Account number
 */
function generate_savings_account_number($member_number, $type_id) {
    $prefix = 'SIM-' . $member_number . '-' . $type_id;
    $sequence_name = 'savings_account_' . $member_number . '_' . $type_id;
    $next = next_sequence($sequence_name);
    return $prefix . '-' . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
}
