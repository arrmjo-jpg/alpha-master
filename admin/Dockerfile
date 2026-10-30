# AlphaMaster-Admin — the React admin, built once and served by an unprivileged nginx.
# nginx also proxies /api and /sanctum to the API, so the SPA and the API share one origin
# and the admin can use Sanctum's cookie session instead of storing tokens (ADR-0003).

FROM node:24-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

FROM nginxinc/nginx-unprivileged:1.29-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1
