#!/bin/bash
# #56 restore test: load the newest backup into a scratch database, compare, then delete it.
# Prints no passwords. The live database is only read.
set -u
wp() { /opt/cpanel/ea-php82/root/usr/bin/php ~/.latoc/wp-cli-2.11.0.phar --path="$HOME/latoc.vayhub.org" --url=https://latoc.vayhub.org "$@"; }
DBU=$(wp config get DB_USER); DBN=$(wp config get DB_NAME); DBH=$(wp config get DB_HOST); P=$(wp db prefix)
SCR="${DBU%%_*}_rtest"
CNF=$(mktemp); chmod 600 "$CNF"
cleanup() { rm -f "$CNF"; uapi Mysql delete_database name="$SCR" >/dev/null 2>&1 && echo "== scratch database deleted"; }
trap cleanup EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$DBU" "$(wp config get DB_PASSWORD)" "$DBH" > "$CNF"
q() { mysql --defaults-extra-file="$CNF" -N -e "$2" "$1"; }

B=$(ls -1t ~/.latoc/backups/*.sql.gz | head -1)
echo "== backup: $(basename "$B") ($(du -h "$B" | cut -f1)); gzip check: $(gzip -t "$B" && echo ok)"
uapi Mysql create_database name="$SCR" | grep -m1 -E 'status:'
uapi Mysql set_privileges_on_database user="$DBU" database="$SCR" privileges=ALL%20PRIVILEGES | grep -m1 -E 'status:'
START=$(date +%s)
if gunzip -c "$B" | mysql --defaults-extra-file="$CNF" "$SCR"; then echo "== import ok in $(( $(date +%s) - START ))s"; else echo "== IMPORT FAILED"; exit 1; fi
echo "== tables: live=$(q "$DBN" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DBN'") restored=$(q "$SCR" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SCR'")"
for t in options users posts blogs jlife_campaigns jlife_enrollments jlife_group_members jlife_soap_entries jlife_score_ledger jlife_audit_log; do
  printf '   %-22s live=%-6s restored=%s\n' "$t" "$(q "$DBN" "SELECT COUNT(*) FROM ${P}$t" 2>/dev/null || echo -)" "$(q "$SCR" "SELECT COUNT(*) FROM ${P}$t" 2>/dev/null || echo -)"
done
echo "== restored siteurl: $(q "$SCR" "SELECT option_value FROM ${P}options WHERE option_name='siteurl'")"
