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

# OCSP-Responder-Supervisor: openssl ocsp läd seine Index-DB nur beim Start,
# deshalb Neustart bei Änderung der index.txt (nach Genehmigung/Widerruf).
#
# Zusätzlich: openssl ocsp hat einen bekannten Bug (openssl/openssl#31129) -
# öffnet ein Client die TCP-Verbindung und schließt sie wieder, bevor er eine
# Anfrage schickt (Port-Scanner, Health-Checks, random Netzwerk-Rauschen),
# hängt der Responder in einer Retry-Schleife auf der toten Verbindung fest
# und läuft dauerhaft auf 100% CPU. Ungepatcht in OpenSSL selbst, deshalb
# hier über CPU-Zeit-Messung erkennen und automatisch neu starten.
start_ocsp() {
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
  last_cpu_ticks=0
}

(
  last_mtime=""
  ocsp_pid=""
  last_cpu_ticks=0
  hz=$(getconf CLK_TCK 2>/dev/null || echo 100)
  # Schwelle: >80% CPU im Schnitt über das 3s-Messintervall gilt als hängend.
  threshold=$(( hz * 3 * 8 / 10 ))

  while true; do
    if [ -f /app/data/ca/ca.crt ] && [ -f /app/data/ca/ca.key ]; then
      cur_mtime=$(stat -c %Y /app/data/ca/index.txt 2>/dev/null || echo "0")

      if [ "$cur_mtime" != "$last_mtime" ]; then
        start_ocsp
        last_mtime="$cur_mtime"
      elif [ -n "$ocsp_pid" ] && kill -0 "$ocsp_pid" 2>/dev/null; then
        cur_ticks=$(awk '{print $14+$15}' /proc/"$ocsp_pid"/stat 2>/dev/null || echo 0)
        delta=$(( cur_ticks - last_cpu_ticks ))
        if [ "$last_cpu_ticks" -gt 0 ] && [ "$delta" -gt "$threshold" ]; then
          echo "$(date -u +%FT%TZ) OCSP-Responder haengt (CPU-Spin, bekannter OpenSSL-Bug) - Neustart" >> /app/data/ocsp.log
          start_ocsp
        fi
        last_cpu_ticks="$cur_ticks"
      fi
    fi
    sleep 3
  done
) &

exec php -S 0.0.0.0:80 -t /app/public
