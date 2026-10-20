# --- Development image (Vite dev server with hot reload) ---
FROM node:24-alpine AS dev

WORKDIR /app

COPY ./frontend/package*.json ./
RUN npm install \
    && cat package.json package-lock.json | sha256sum | cut -d' ' -f1 > node_modules/.deps-hash

COPY ./frontend .

# Keeps the node_modules volume in sync with package files on every start
COPY ./Docker/node/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 5173

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["npm", "run", "dev", "--", "--host"]

# --- Production build stage ---
FROM node:24-alpine AS build

WORKDIR /app
COPY ./frontend/package*.json ./
RUN npm install
COPY ./frontend .
RUN npm run build

# --- Production runtime (serve static files via nginx) ---
FROM nginx:alpine AS production

COPY --from=build /app/dist /usr/share/nginx/html
COPY ./Docker/nginx/vue.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
