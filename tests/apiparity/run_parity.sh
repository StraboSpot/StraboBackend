#!/bin/bash
# API parity, PHP 7.4 (dev strabo-php) vs PHP 8.4 (strabo-php8). Dev only.
# Each side runs inside its own container against http://localhost, then the results are compared.
# Usage: tests/apiparity/run_parity.sh [--only=field,versions,micro,exp,samples,search,microsync,voice,web] [-v]
# pcre.jit=0 is for this CLI client only: on the arm64 dev machine the PHP 8.4 CLI segfaults inside
# libpcre2 (JIT) part way through a full run (gdb: SIGSEGV in libpcre2-8.so.0). Servers are unaffected.
set -e
T=/srv/app/www/tests/apiparity/api_parity.php
docker exec strabo-php php -d pcre.jit=0 $T --side=A "$@"
docker exec strabo-php8 php -d pcre.jit=0 $T --side=B "$@"
docker exec strabo-php php -d pcre.jit=0 $T --compare
