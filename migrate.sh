#!/bin/bash
# Alternative migration script using mysql command-line client
# Run: bash migrate.sh

DB_HOST="localhost"
DB_USER="root"
DB_PASS="root"
DB_NAME="koperasi_pancakarya"

echo "Checking database connection..."
if ! command -v mysql &> /dev/null; then
    echo "ERROR: mysql command not found. Install mysql-client or run SQL manually."
    echo "SQL file location: database/migration_phase1_critical.sql"
    exit 1
fi

echo "Testing connection..."
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1" 2>/dev/null
if [ $? -ne 0 ]; then
    echo "ERROR: Cannot connect to database. Check credentials."
    exit 1
fi

echo "✓ Connected"
echo ""
echo "Checking migration status..."

# Check if already migrated
ALREADY_MIGRATED=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sse "SHOW COLUMNS FROM loans LIKE 'admin_fee_pct'" 2>/dev/null | wc -l)

if [ "$ALREADY_MIGRATED" -gt 0 ]; then
    echo "✓ Migration already applied"
else
    echo "Running migration..."
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < database/migration_phase1_critical.sql
    if [ $? -eq 0 ]; then
        echo "✓ Migration completed"
    else
        echo "✗ Migration failed"
        exit 1
    fi
fi

echo ""
echo "Verification:"
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SHOW COLUMNS FROM loans LIKE 'admin_fee%';
SHOW COLUMNS FROM loan_payments LIKE 'penalty%';
SHOW TABLES LIKE 'settings';
" 2>/dev/null

echo ""
echo "Settings values:"
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sse "SELECT CONCAT('  ', \`key\`, ' = ', \`value\`) FROM settings WHERE \`key\` IN ('penalty_rate_per_day', 'default_admin_fee_pct')" 2>/dev/null

echo ""
echo "✓ Done!"
