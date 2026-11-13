// Prints the tool catalogue (src/schemas.js is the single source) as JSON.
// The ERP's in-product assistant (stage 3) ships this snapshot as
// backend/resources/assistant/tools.json; AssistantCatalogueParityTest fails when the two drift.
//   npm run export:catalogue
import { TOOLS } from '../src/schemas.js';

const tools = TOOLS.map(({ name, description, route, report, inputSchema }) => ({
  name,
  description,
  route,
  report: report === true,
  inputSchema,
}));

process.stdout.write(`${JSON.stringify({ tools }, null, 2)}\n`);
