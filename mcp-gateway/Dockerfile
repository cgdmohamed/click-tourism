# Read-only MCP gateway (stage 1). Not part of any production compose file:
# run it next to the MCP client, never with database credentials.
FROM node:22-alpine
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts
COPY src ./src
USER node
# API_BASE_URL and MCP_TOKEN are supplied at run time (docker run -e ...).
ENTRYPOINT ["node", "src/index.js"]
