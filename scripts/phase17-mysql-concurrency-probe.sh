#!/usr/bin/env bash
set -euo pipefail

if [[ "${IFC_PHASE17_PROBE:-}" != "1" ]]; then
  echo "Set IFC_PHASE17_PROBE=1 to run the isolated lock probe." >&2
  exit 2
fi
if [[ -z "${MYSQL_PWD:-}" ]]; then
  echo "MYSQL_PWD must be set for the disposable phase17 probe database." >&2
  exit 2
fi

container='ifc-phase17-mysql-1'
database='ifc_phase17_concurrency'
sql='START TRANSACTION; SELECT value FROM lock_probe WHERE id=1 FOR UPDATE; DO SLEEP(0.1); UPDATE lock_probe SET value=value+1 WHERE id=1; COMMIT;'
start_ns=$(date +%s%N)
pids=()
for _ in {1..8}; do
  docker exec -e MYSQL_PWD="$MYSQL_PWD" "$container" mysql -uifc_dev -D "$database" -e "$sql" >/dev/null &
  pids+=("$!")
done
for pid in "${pids[@]}"; do
  wait "$pid"
done
end_ns=$(date +%s%N)
elapsed_ms=$(((end_ns - start_ns) / 1000000))
value=$(docker exec -e MYSQL_PWD="$MYSQL_PWD" "$container" mysql -uifc_dev -D "$database" -Nse 'SELECT value FROM lock_probe WHERE id=1')
printf 'eight_parallel_lock_transactions_ms=%s\nfinal_value=%s\n' "$elapsed_ms" "$value"
[[ "$value" == '8' ]]
