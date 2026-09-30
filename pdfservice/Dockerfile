# File: pdfservice/Dockerfile
# Description: strabo-node, the internal Node service beside strabo-php.
#              Dependencies install inside the image (prod has no npm), so
#              each host builds its own sharp binary for its own platform.
#              Built by docker-compose with context ./www/pdfservice.

FROM node:24-bookworm-slim

ENV NODE_ENV=production
WORKDIR /opt/pdfservice

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY server.js ./

# www-data (uid 33), the same user as Apache in strabo-php, so files this
# service writes into straboMicroFiles have the owner PHP expects
USER www-data

EXPOSE 3000
CMD ["node", "server.js"]
