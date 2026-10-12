#!/bin/sh
# Se ejecuta solo la primera vez que se crea el volumen de PostgreSQL.
# La API usa un rol propio SIN superusuario ni BYPASSRLS: así Row-Level Security
# aplica siempre. El superusuario (POSTGRES_USER) queda solo para administración.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
CREATE ROLE "$APP_DB_USER" LOGIN PASSWORD '$APP_DB_PASSWORD' NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB;
CREATE DATABASE "$APP_DB_NAME" OWNER "$APP_DB_USER";
CREATE DATABASE "${APP_DB_NAME}_test" OWNER "$APP_DB_USER";
SQL
