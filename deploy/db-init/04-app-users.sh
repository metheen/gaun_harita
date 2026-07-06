#!/bin/sh
set -eu

required() {
  key="$1"
  value="$(eval "printf '%s' \"\${$key:-}\"")"
  if [ -z "$value" ]; then
    echo "HATA: $key tanımlı değil" >&2
    exit 1
  fi
}

validate_identifier() {
  key="$1"
  value="$(eval "printf '%s' \"\${$key}\"")"
  case "$value" in
    ''|*[!A-Za-z0-9_]*)
      echo "HATA: $key yalnızca harf, rakam ve alt çizgi içermeli" >&2
      exit 1
      ;;
  esac
}

sql_escape() {
  printf '%s' "$1" | sed "s/'/''/g"
}

for key in MARIADB_ROOT_PASSWORD MARIADB_DATABASE DB_READ_USER DB_READ_PASS DB_WRITE_USER DB_WRITE_PASS; do
  required "$key"
done

for key in MARIADB_DATABASE DB_READ_USER DB_WRITE_USER; do
  validate_identifier "$key"
done

read_pass="$(sql_escape "$DB_READ_PASS")"
write_pass="$(sql_escape "$DB_WRITE_PASS")"

mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS '${DB_READ_USER}'@'%' IDENTIFIED BY '${read_pass}';
CREATE USER IF NOT EXISTS '${DB_WRITE_USER}'@'%' IDENTIFIED BY '${write_pass}';

GRANT SELECT ON \`${MARIADB_DATABASE}\`.* TO '${DB_READ_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON \`${MARIADB_DATABASE}\`.* TO '${DB_WRITE_USER}'@'%';

FLUSH PRIVILEGES;
SQL
