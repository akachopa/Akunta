#!/bin/sh
# Test suite memakai PostgreSQL, bukan SQLite, karena sebagian aturan akuntansi
# ditegakkan oleh CHECK constraint dan partial unique index di level database
# (plan.md §24.2, §44.16). Database test dibuat sekali saat volume diinisialisasi.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE ${POSTGRES_DB}_testing OWNER $POSTGRES_USER;
EOSQL
