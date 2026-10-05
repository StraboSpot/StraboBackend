# File: livesvc/Dockerfile
# Description: strabo-live, the real-time notice service for StraboMicro
#              collaboration (WebSocket notices + presence). Dependencies
#              install inside the image (prod has no npm). Built by
#              docker-compose with context ./www/livesvc. Reads the database
#              login and JWT secret from the mounted www/includes/config.inc.php.

FROM node:24-bookworm-slim

ENV NODE_ENV=production
ENV TZ=America/Chicago

WORKDIR /opt/livesvc

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY server.js config.js jwt.js ./

# www-data (uid 33), the same user as Apache in strabo-php, which can read config.inc.php
USER www-data

EXPOSE 3001
CMD ["node", "server.js"]
