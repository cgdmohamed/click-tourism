// Gateway configuration comes only from the environment; nothing is stored in the repo.

const PUBLIC_ID = /^[A-Za-z0-9_-]{8,64}$/;

export function loadConfig(env = process.env) {
  const rawBase = (env.API_BASE_URL ?? '').trim();
  if (!rawBase) throw new Error('API_BASE_URL is required (e.g. https://erp.example.com).');

  let url;
  try {
    url = new URL(rawBase);
  } catch {
    throw new Error('API_BASE_URL is not a valid URL.');
  }
  if (!['http:', 'https:'].includes(url.protocol)) throw new Error('API_BASE_URL must be http(s).');

  const path = url.pathname.replace(/\/+$/, '');
  const baseUrl = `${url.origin}${path.endsWith('/api/v1') ? path : `${path}/api/v1`}`;

  const token = (env.MCP_TOKEN ?? '').trim();
  if (!token) throw new Error('MCP_TOKEN is required (create one in the ERP: Profile, MCP tokens).');

  const branchId = (env.MCP_BRANCH_ID ?? '').trim() || null;
  if (branchId && !PUBLIC_ID.test(branchId)) throw new Error('MCP_BRANCH_ID is not a valid branch public id.');

  // Defense in depth only: the ERP masks contacts itself via the mcp.mask_contacts setting.
  const maskContacts = !['0', 'false', 'no', 'off'].includes((env.MCP_MASK_CONTACTS ?? 'true').toLowerCase());

  return { baseUrl, token, branchId, maskContacts, timeoutMs: 15000 };
}
