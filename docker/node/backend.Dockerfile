# Backend Cora (NestJS). Contesto di build: la radice del repository.
#   docker build -f docker/node/backend.Dockerfile -t cora-backend .
FROM node:24-bookworm-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
COPY backend/package.json backend/
COPY frontend/package.json frontend/
RUN npm ci -w backend --no-audit --no-fund
COPY backend/ backend/
RUN npm run build -w backend

FROM node:24-bookworm-slim
# pdftotext (poppler) per i saldi letti dalle buste paga; tini per i segnali.
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils tini \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
ENV NODE_ENV=production \
    APP_PORT=3100 \
    STORAGE_DIR=/data/storage \
    UPLOAD_DIR=/data/uploads \
    RISORSE_DIR=/app/backend/risorse
COPY package.json package-lock.json ./
COPY backend/package.json backend/
COPY frontend/package.json frontend/
RUN npm ci -w backend --omit=dev --no-audit --no-fund && npm cache clean --force
COPY --from=build /app/backend/dist backend/dist
COPY backend/risorse backend/risorse
RUN mkdir -p /data/storage /data/uploads && chown -R node:node /data
USER node
WORKDIR /app/backend
EXPOSE 3100
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:3100/api/v1/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
ENTRYPOINT ["tini", "--"]
CMD ["node", "dist/main.js"]
