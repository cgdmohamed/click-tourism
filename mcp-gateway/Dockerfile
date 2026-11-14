# Read-only MCP gateway (stages 1-2). Not part of any production compose file; never give it
# database credentials. Two entries: stdio (default, `src/index.js`, run next to the MCP client) and
# Streamable HTTP (`src/http.js`, a remote server for OpenAI and remote Claude clients, ADR-0024).
FROM node:22-alpine
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts
COPY src ./src
USER node
# API_BASE_URL (and MCP_TOKEN for stdio) are supplied at run time (docker run -e ...).
# HTTP mode needs no MCP_TOKEN: every request carries the caller's own token. HTTPS is terminated by the proxy.
EXPOSE 8787
ENTRYPOINT ["node"]
CMD ["src/index.js"]
