# account-frontend — RE-Account Next.js frontend
# Targets:
#   dev  → used by re-root infra/local/docker-compose.yml (code is bind-mounted)
#   prod → default target, the image pushed to ECR for EKS
# NEXT_PUBLIC_* values are baked in at build time, so pass them as build args.

FROM node:22-alpine AS base
WORKDIR /app

FROM base AS deps
COPY package.json package-lock.json ./
RUN npm ci

FROM deps AS dev
EXPOSE 3742
CMD ["npx", "next", "dev", "--port", "3742", "--hostname", "0.0.0.0"]

FROM deps AS build
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_APP_URL
ARG NEXT_PUBLIC_TENANT_DOMAIN
ENV NEXT_TELEMETRY_DISABLED=1
COPY . .
RUN npm run build

FROM base AS prod
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 PORT=3742 HOSTNAME=0.0.0.0
COPY --from=build --chown=node:node /app/public ./public
COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static
USER node
EXPOSE 3742
CMD ["node", "server.js"]
