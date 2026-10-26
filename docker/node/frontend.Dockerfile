# Frontend Cora (React + Vite) servito da nginx, che inoltra al backend gli
# indirizzi del PHP rimasti uguali. Contesto di build: la radice del repository.
#   docker build -f docker/node/frontend.Dockerfile -t cora-frontend .
FROM node:24-bookworm-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
COPY backend/package.json backend/
COPY frontend/package.json frontend/
RUN npm ci -w frontend --no-audit --no-fund
COPY frontend/ frontend/
RUN npm run build -w frontend

FROM nginx:1.27-alpine
# Origine del CRM per il widget di assistenza (CSP), come ConnecteedWidget::origin().
ENV CRM_ORIGIN=https://fase.adichub.com \
    BACKEND_URL=http://backend:3100 \
    REALTIME_URL=http://realtime:3001 \
    CSP_UPGRADE="; upgrade-insecure-requests"
COPY docker/node/spa-headers.inc /etc/nginx/snippets/spa-headers.inc
COPY docker/node/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY --from=build /app/frontend/dist /usr/share/nginx/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -q -O /dev/null http://127.0.0.1/index.html || exit 1
