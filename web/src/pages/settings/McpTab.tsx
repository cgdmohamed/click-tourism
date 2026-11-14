import { useEffect, useState } from 'react'
import { useAuth } from '../../auth/AuthProvider'
import McpStatusCard from '../../components/McpStatusCard'
import McpTokensCard from '../../components/McpTokensCard'
import { useI18n } from '../../i18n'
import { API_BASE } from '../../lib/api'
import {
  claudeCodeCommand,
  claudeDesktopConfig,
  gatewayDocker,
  gatewayWarning,
  healthCheck,
  listToolsCheck,
  normalizeGatewayUrl,
  openAiRequest,
} from '../../lib/mcpGuide'
import { useFeatureFlags } from '../../lib/useSetting'
import { Button, Card, CardHeader, Field, InlineNotice, Input } from '../../components/ui'

const GATEWAY_KEY = 'tourism_erp_mcp_gateway_url'

function storedGateway(): string {
  try {
    return localStorage.getItem(GATEWAY_KEY) ?? ''
  } catch {
    return ''
  }
}

function Snippet({ text, label }: { text: string; label: string }) {
  const { locale } = useI18n()
  const [copied, setCopied] = useState(false)
  async function copy() {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard can be blocked; the text stays selectable.
    }
  }
  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center justify-between gap-2">
        <span className="text-small font-semibold text-text-subtle">{label}</span>
        <Button type="button" variant="secondary" icon="copy" onClick={() => void copy()}>
          {copied ? (locale === 'ar' ? 'تم النسخ' : 'Copied') : locale === 'ar' ? 'نسخ' : 'Copy'}
        </Button>
      </div>
      <pre dir="ltr" className="text-small overflow-x-auto rounded-12 border border-border-subtle bg-surface-subtle p-3 text-start" tabIndex={0}>
        <code>{text}</code>
      </pre>
    </div>
  )
}

type Client = 'claude' | 'openai'

/**
 * Settings > MCP (ADR-0024): switch, tokens, per-client instructions (Claude and OpenAI) and a
 * test. Snippets never contain a real token; the person pastes the one shown at creation.
 */
export function McpTab() {
  const { locale } = useI18n()
  const { can } = useAuth()
  const { flags } = useFeatureFlags()
  const ar = locale === 'ar'
  const L = (a: string, e: string) => (ar ? a : e)
  const [client, setClient] = useState<Client>('claude')
  const [gatewayInput, setGatewayInput] = useState(storedGateway)
  const gateway = normalizeGatewayUrl(gatewayInput)
  const apiUrl = API_BASE.startsWith('http') ? API_BASE.replace(/\/api\/v1\/?$/, '') : window.location.origin

  useEffect(() => {
    try {
      localStorage.setItem(GATEWAY_KEY, gatewayInput)
    } catch {
      // not available (private window): the field simply is not remembered
    }
  }, [gatewayInput])

  const tokensVisible = can('mcp.use') && flags?.mcp_enabled === true

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader
          title={L('ربط MCP بـClaude وOpenAI', 'Connect MCP to Claude and OpenAI')}
          subtitle={L(
            'يتيح لبرنامج ذكاء اصطناعي خارجي قراءة بيانات النظام بصلاحياتك أنت، للقراءة فقط. لا يكتب ولا يعتمد ولا يرحّل.',
            'Lets an external AI app read ERP data with your own permissions, read-only. It cannot write, approve or post.',
          )}
          icon="sparkles"
        />
        <ol className="text-medium flex list-decimal flex-col gap-1 px-9 py-4 text-text-default">
          <li>{L('فعّل MCP من البطاقة الأولى.', 'Switch MCP on in the first card.')}</li>
          <li>{L('أنشئ رمزاً (يظهر مرة واحدة) وانسخه.', 'Create a token (shown once) and copy it.')}</li>
          <li>{L('اختر التطبيق (Claude أو OpenAI) واتبع الخطوات.', 'Pick the app (Claude or OpenAI) and follow its steps.')}</li>
          <li>{L('اختبر الاتصال بأمر الاختبار في الأسفل.', 'Test the connection with the command at the bottom.')}</li>
        </ol>
      </Card>

      <McpStatusCard />

      {tokensVisible ? (
        <McpTokensCard />
      ) : (
        <InlineNotice tone="warning">
          {flags?.mcp_enabled === false
            ? L('فعّل MCP أولاً ثم ستظهر هنا بطاقة إنشاء الرموز.', 'Switch MCP on first; the token card then appears here.')
            : L('إنشاء الرموز يتطلب صلاحية MCP (mcp.use). اطلبها من المدير.', 'Creating tokens needs the MCP permission (mcp.use). Ask an administrator.')}
        </InlineNotice>
      )}

      <Card>
        <CardHeader title={L('تعليمات الاتصال', 'Connection instructions')} icon="settings" />
        <div className="flex flex-col gap-5 px-5 py-5">
          <div role="group" aria-label={L('اختر التطبيق', 'Choose the app')} className="flex flex-wrap gap-2">
            {(['claude', 'openai'] as const).map((id) => (
              <button
                key={id}
                type="button"
                aria-pressed={client === id}
                onClick={() => setClient(id)}
                className={
                  client === id
                    ? 'label rounded-12 border border-border-selected bg-surface-selected px-4 py-2 text-text-default'
                    : 'label rounded-12 border border-border-subtle px-4 py-2 text-text-subtle transition hover:bg-surface-hovered'
                }
              >
                {id === 'claude' ? 'Claude' : 'OpenAI'}
              </button>
            ))}
          </div>

          {client === 'claude' ? (
            <>
              <section className="flex flex-col gap-3" aria-label="Claude Desktop">
                <h3 className="label">{L('Claude Desktop (على جهازك، الأبسط)', 'Claude Desktop (on your computer, simplest)')}</h3>
                <p className="text-medium text-text-subtle">
                  {L(
                    'يعمل الـgateway كعملية محلية على جهازك. يلزم Node 20 أو أحدث ونسخة من مجلد mcp-gateway (npm ci). افتح ملف claude_desktop_config.json (الإعدادات ← المطوّر ← تعديل الإعدادات) وأضف:',
                    'The gateway runs as a local process on your computer. You need Node 20+ and a copy of the mcp-gateway folder (npm ci). Open claude_desktop_config.json (Settings, Developer, Edit config) and add:',
                  )}
                </p>
                <Snippet label="claude_desktop_config.json" text={claudeDesktopConfig(apiUrl)} />
                <p className="text-small text-text-subtle">
                  {L(
                    'استبدل المسار بمسار مجلدك والرمز برمزك، ثم أعد تشغيل Claude Desktop. ستظهر أدوات click-tourism-erp.',
                    'Replace the path with your folder and the token with yours, then restart Claude Desktop. The click-tourism-erp tools appear.',
                  )}
                </p>
              </section>

              <section className="flex flex-col gap-3 border-t border-border-subtle pt-5" aria-label="Claude Code">
                <h3 className="label">{L('Claude Code (عبر خادم بعيد)', 'Claude Code (through a remote server)')}</h3>
                <GatewayField value={gatewayInput} onChange={setGatewayInput} gateway={gateway} />
                <Snippet label={L('أمر الإضافة', 'Add command')} text={claudeCodeCommand(gateway)} />
              </section>

              <InlineNotice tone="warning">
                {L(
                  'موصّلات claude.ai المخصصة (من المتصفح) تتطلب تسجيل دخول OAuth وهو غير مدعوم هنا حالياً. استعمل Claude Desktop أو Claude Code.',
                  'Custom connectors on claude.ai (in the browser) require OAuth sign-in, which is not supported here yet. Use Claude Desktop or Claude Code.',
                )}
              </InlineNotice>
            </>
          ) : (
            <section className="flex flex-col gap-3" aria-label="OpenAI">
              <h3 className="label">{L('OpenAI (Responses API / Agents SDK)', 'OpenAI (Responses API / Agents SDK)')}</h3>
              <p className="text-medium text-text-subtle">
                {L(
                  'تتصل OpenAI بخادم MCP بعيد عبر HTTPS، لذا شغّل الـgateway بوضع HTTP على عنوان عام ثم اكتب عنوانه هنا:',
                  'OpenAI connects to a remote MCP server over HTTPS, so run the gateway in HTTP mode on a public address and type it here:',
                )}
              </p>
              <GatewayField value={gatewayInput} onChange={setGatewayInput} gateway={gateway} />
              <Snippet label={L('تشغيل الـgateway (Docker)', 'Run the gateway (Docker)')} text={gatewayDocker(apiUrl)} />
              <Snippet label={L('طلب Responses API', 'Responses API request')} text={openAiRequest(gateway)} />
              <p className="text-small text-text-subtle">
                {L(
                  'الحقل authorization هو رمز MCP الخاص بك، وتحمل أدوات القراءة الخمس عشرة وسم القراءة فقط. تطبيق ChatGPT نفسه يطلب OAuth لموصّلاته المخصصة وهو غير مدعوم هنا؛ استعمل الـAPI أو Agents SDK.',
                  'The authorization field is your own MCP token, and the 15 tools are marked read-only. The ChatGPT app itself asks for OAuth on custom connectors, which is not supported here; use the API or the Agents SDK.',
                )}
              </p>
            </section>
          )}
        </div>
      </Card>

      <Card>
        <CardHeader title={L('اختبر الاتصال', 'Test the connection')} icon="listChecks" />
        <div className="flex flex-col gap-4 px-5 py-5">
          <p className="text-medium text-text-subtle">
            {L(
              'الأمر الأول يتحقق أن الـgateway يعمل، والثاني يتحقق أن الرمز صالح وأن النظام يرد (يجب أن تظهر 15 أداة). ثم ارجع إلى بطاقة الحالة أعلاه لترى «آخر اتصال».',
              'The first command checks the gateway is up; the second checks the token is valid and the ERP answers (15 tools should be listed). Then look at "last connection" in the status card above.',
            )}
          </p>
          <Snippet label={L('الـgateway يعمل', 'Gateway is up')} text={healthCheck(gateway)} />
          <Snippet label={L('الرمز والنظام', 'Token and ERP')} text={listToolsCheck(gateway)} />
        </div>
      </Card>

      <Card>
        <CardHeader title={L('الأمان', 'Safety')} icon="shield" />
        <ul className="text-medium flex list-disc flex-col gap-1 px-9 py-4 text-text-default">
          <li>{L('الرمز يعمل بصلاحياتك وفروعك أنت ولا يتجاوزها، وللقراءة فقط.', 'A token works with your own permissions and branches and never exceeds them; it is read-only.')}</li>
          <li>{L('لا تلصق الرمز في محادثة أو ملف مشترك. ينتهي تلقائياً ويمكنك إلغاؤه من الملف الشخصي.', 'Never paste the token into a chat or shared file. It expires on its own and you can revoke it in your profile.')}</li>
          <li>{L('استعمل HTTPS دائماً للـgateway البعيد. لا تفتحه بدون TLS.', 'Always serve a remote gateway over HTTPS. Never expose it without TLS.')}</li>
          <li>{L('لتقييد الرمز بفرع واحد أرسل الترويسة X-Branch-Id (أو MCP_BRANCH_ID للتشغيل المحلي).', 'To pin a token to one branch send the X-Branch-Id header (or MCP_BRANCH_ID for the local run).')}</li>
        </ul>
      </Card>
    </div>
  )
}

function GatewayField({ value, onChange, gateway }: { value: string; onChange: (value: string) => void; gateway: string }) {
  const { locale } = useI18n()
  const ar = locale === 'ar'
  const insecure = gatewayWarning(gateway) === 'insecure'
  return (
    <div className="flex flex-col gap-2">
      <Field
        label={ar ? 'عنوان الـgateway' : 'Gateway address'}
        hint={ar ? 'العنوان العام الذي يعمل عليه الـgateway بوضع HTTP، مثل https://mcp.your-domain.com' : 'The public address of the gateway in HTTP mode, e.g. https://mcp.your-domain.com'}
      >
        <Input dir="ltr" value={value} placeholder="https://mcp.your-domain.com" onChange={(event) => onChange(event.target.value)} autoComplete="off" />
      </Field>
      {value.trim() !== '' && gateway === '' ? (
        <InlineNotice tone="negative">{ar ? 'العنوان غير صالح. اكتب عنواناً يبدأ بـhttps://' : 'Not a valid address. Type one that starts with https://'}</InlineNotice>
      ) : null}
      {insecure ? (
        <InlineNotice tone="warning">{ar ? 'العنوان بدون HTTPS. لن يقبله OpenAI، ولا يجوز في الإنتاج.' : 'This address is not HTTPS. OpenAI will not accept it and it must not be used in production.'}</InlineNotice>
      ) : null}
    </div>
  )
}
