# File: pdfservice/Dockerfile
# Description: strabo-node, the internal Node service beside strabo-php.
#              Dependencies install inside the image (prod has no npm), so
#              each host builds its own sharp binary for its own platform.
#              Built by docker-compose with context ./www/pdfservice.

FROM node:24-bookworm-slim

ENV NODE_ENV=production
# Dates in the PDF are formatted in local time, like the app on a user's
# machine; use the server's zone (same as strabo-postgres)
ENV TZ=America/Chicago

# Spot labels are SVG text drawn by sharp: it needs real fonts. Liberation
# Sans stands in for Arial (same metrics); DejaVu covers other scripts.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends fontconfig fonts-liberation fonts-dejavu-core \
	&& rm -rf /var/lib/apt/lists/* \
	&& fc-cache -f
# www-data has no home folder; give fontconfig a writable cache location
ENV XDG_CACHE_HOME=/tmp/cache

WORKDIR /opt/pdfservice

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY server.js renderer.js ./
COPY shims ./shims
COPY vendor ./vendor

# www-data (uid 33), the same user as Apache in strabo-php, so files this
# service writes into straboMicroFiles have the owner PHP expects
USER www-data

EXPOSE 3000
CMD ["node", "server.js"]
