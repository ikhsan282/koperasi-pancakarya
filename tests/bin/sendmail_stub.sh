#!/bin/sh
# Test double for sendmail: records each delivery to $MAIL_LOG and discards the message.
cat > /dev/null
echo DELIVERED >> "${MAIL_LOG:?MAIL_LOG must be set}"
