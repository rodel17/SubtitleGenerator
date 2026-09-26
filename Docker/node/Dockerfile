# --- Development image (Vite dev server with hot reload) ---
FROM node:20-alpine AS dev

WORKDIR /app

COPY ./frontend/package*.json ./
RUN npm install

COPY ./frontend .

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host"]

# --- Production build stage ---
FROM node:20-alpine AS build

WORKDIR /app
COPY ./frontend/package*.json ./
RUN npm install
COPY ./frontend .
RUN npm run build

# --- Production runtime (serve static files via nginx) ---
FROM nginx:alpine AS production

COPY --from=build /app/dist /usr/share/nginx/html
COPY ./docker/nginx/vue.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
