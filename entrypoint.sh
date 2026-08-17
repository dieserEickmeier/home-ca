#!/bin/bash
set -e
mkdir -p /app/data/ca
chmod 700 /app/data/ca
touch /app/data/ca/index.txt

# Auto-Renewal per Cron: prüft alle 6h fällige Zertifikate (auto_renew=1),
# erneuert sie und pusht bei hinterlegter NPM-Verknüpfung automatisch.
mkdir -p /etc/crontabs
cat > /etc/crontabs/root << 'CRON'
0 */6 * * * php /app/src/cron/renew-check.php >> /app/data/renew.log 2>&1
CRON
crond -b -l 8

# OCSP-Responder-Supervisor: openssl ocsp läd seine Index-DB nur beim Start.
# Diese Schleife beobachtet die mtime von index.txt und startet den
# Responder neu, sobald CaManager sie nach Genehmigung/Widerruf neu schreibt.
(
  last_mtime=""
  ocsp_pid=""
  while true; do
    if [ -f /app/data/ca/ca.crt ] && [ -f /app/data/ca/ca.key ]; then
      cur_mtime=$(stat -c %Y /app/data/ca/index.txt 2>/dev/null || echo "0")
      if [ "$cur_mtime" != "$last_mtime" ]; then
        if [ -n "$ocsp_pid" ] && kill -0 "$ocsp_pid" 2>/dev/null; then
          kill "$ocsp_pid" 2>/dev/null
          wait "$ocsp_pid" 2>/dev/null
        fi
        openssl ocsp \
          -index /app/data/ca/index.txt \
          -CA /app/data/ca/ca.crt \
          -rsigner /app/data/ca/ca.crt \
          -rkey /app/data/ca/ca.key \
          -port 2560 \
          > /app/data/ocsp.log 2>&1 &
        ocsp_pid=$!
        last_mtime="$cur_mtime"
      fi
    fi
    sleep 3
  done
) &

exec php -S 0.0.0.0:80 -t /app/public
