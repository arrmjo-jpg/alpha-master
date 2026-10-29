# AlphaMaster-DB — MySQL 8.4 LTS
FROM mysql:8.4

HEALTHCHECK --interval=10s --timeout=5s --retries=10 \
  CMD mysqladmin ping -h 127.0.0.1 --silent || exit 1
