#!/bin/bash

# Fans MarvinReminder.php out across shops, one process per shop.
#
# MarvinReminder.php now defines the request-scoped "shop_id" constant once,
# up front, and can only ever handle the single shop it was given — a
# constant can't be redefined within a process, so looping over shops inside
# one PHP process (the old approach) silently dropped reminders for every
# shop after the first. This is what cron should call, not MarvinReminder.php
# directly:
#
#   * * * * * /usr/bin/bash /path/to/v2/src/Scripts/marvin_reminder.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SHOPS=(98 99 100 101 102)

for shop_id in "${SHOPS[@]}"; do
    php "$SCRIPT_DIR/MarvinReminder.php" "$shop_id"
done
