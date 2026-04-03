import {
	fetchRuntimeJson,
	type RuntimeAccountSnapshotResponse,
	type RuntimeLoginStartResponse,
	type RuntimeLoginStatusResponse,
	type RuntimeTextResponse,
} from "./codexRuntimeContainer";

export { CodexRuntimeContainer } from "./codexRuntimeContainer";

interface Env {
	DB: D1Database;
	BROKER_ADMIN_TOKEN: string;
	BROKER_ENCRYPTION_KEY: string;
	BROKER_ALLOWED_MODELS?: string;
	BROKER_DEFAULT_MODEL?: string;
	BROKER_REASONING_EFFORT?: string;
	BROKER_RUNTIME_MODE?: string;
	CODEX_RUNTIME: DurableObjectNamespace;
}

interface SiteRow {
	site_id: string;
	site_secret_ciphertext: string;
	default_model: string;
	default_reasoning_effort: string;
	allowed_models_json: string;
}

interface AuthSessionRow {
	auth_session_id: string;
	broker_code_ciphertext: string | null;
	broker_code_hash: string | null;
	completed_at: string | null;
	connection_id: string | null;
	device_user_code: string | null;
	device_verification_url: string | null;
	expires_at: string;
	local_state: string;
	return_url: string;
	runtime_error: string | null;
	runtime_status: string;
	site_id: string;
	wp_user_id: number;
}

interface ConnectionRow {
	account_email: string | null;
	allowed_models_json: string;
	auth_mode: string | null;
	broker_user_id: string | null;
	connection_id: string;
	default_model: string | null;
	plan_type: string | null;
	rate_limits_json: string;
	runtime_payload_json: string;
	session_expires_at: string | null;
	site_id: string;
	status: string;
	wp_user_id: number;
}

interface SignedRequestContext {
	bodyText: string;
	payload: Record<string, unknown>;
	site: SiteRow;
}

type RuntimeMode = "container" | "unavailable" | "unimplemented";

const TIMESTAMP_SKEW_SECONDS = 300;
const CONNECT_SESSION_TTL_SECONDS = 600;

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		try {
			return await routeRequest(request, env);
		} catch (error) {
			if (error instanceof HttpError) {
				return errorResponse(error.code, error.message, error.status);
			}

			console.error("Unhandled broker error", error);

			return errorResponse(
				"internal_error",
				error instanceof Error ? error.message : "Unexpected error.",
				500
			);
		}
	},
};

async function routeRequest(request: Request, env: Env): Promise<Response> {
	const url = new URL(request.url);
	const pathname = normalizePath(url.pathname);

	if ("GET" === request.method && "/readyz" === pathname) {
		return jsonResponse({ ok: true });
	}

	if ("GET" === request.method && "/healthz" === pathname) {
		return jsonResponse({
			defaultModel: env.BROKER_DEFAULT_MODEL ?? "gpt-5.3-codex",
			ok: true,
			runtimeMode: getRuntimeMode(env),
		});
	}

	if ("POST" === request.method && "/v1/admin/installation-codes" === pathname) {
		return createInstallationCode(request, env);
	}

	if ("POST" === request.method && "/v1/wordpress/installations/exchange" === pathname) {
		return exchangeInstallationCode(request, env);
	}

	if ("POST" === request.method && "/v1/wordpress/connections/start" === pathname) {
		return startConnection(request, env, pathname);
	}

	if ("POST" === request.method && "/v1/wordpress/connections/exchange" === pathname) {
		return exchangeConnection(request, env, pathname);
	}

	if ("GET" === request.method && pathname.startsWith("/v1/wordpress/connections/")) {
		return readConnection(request, env, pathname);
	}

	if (
		"POST" === request.method &&
		pathname.startsWith("/v1/wordpress/connections/") &&
		pathname.endsWith("/disconnect")
	) {
		return disconnectConnection(request, env, pathname);
	}

	if ("GET" === request.method && "/v1/wordpress/models" === pathname) {
		return listModels(request, env, pathname);
	}

	if ("POST" === request.method && "/v1/wordpress/support/text" === pathname) {
		return textSupport(request, env, pathname);
	}

	if ("POST" === request.method && "/v1/wordpress/responses/text" === pathname) {
		return textResponse(request, env, pathname);
	}

	if ("GET" === request.method && pathname.startsWith("/connect/")) {
		return connectPage(request, env, pathname);
	}

	return errorResponse("not_found", "Route not found.", 404);
}

async function createInstallationCode(request: Request, env: Env): Promise<Response> {
	if (!isAuthorizedAdminRequest(request, env)) {
		return errorResponse("unauthorized", "Missing or invalid admin token.", 401);
	}

	const payload = await readJsonBody(request);
	const label = stringOrNull(payload.label);
	const expiresInSeconds = clampNumber(payload.expiresInSeconds, 300, 86400, 3600);
	const installationCode = `inst_${randomId(24)}`;
	const codeHash = await sha256Hex(installationCode);
	const now = isoNow();
	const expiresAt = isoSecondsFromNow(expiresInSeconds);

	await env.DB.prepare(
		`INSERT INTO installation_codes (code_hash, label, created_at, expires_at, metadata_json)
		 VALUES (?1, ?2, ?3, ?4, ?5)`
	)
		.bind(codeHash, label, now, expiresAt, JSON.stringify({ source: "admin_api" }))
		.run();

	return jsonResponse({
		expiresAt,
		installationCode,
		label,
	});
}

async function exchangeInstallationCode(request: Request, env: Env): Promise<Response> {
	const payload = await readJsonBody(request);
	const installationCode = requiredString(payload.installationCode, "installationCode");
	const siteUrl = requiredString(payload.siteUrl, "siteUrl");
	const homeUrl = requiredString(payload.homeUrl, "homeUrl");
	const adminEmail = requiredString(payload.adminEmail, "adminEmail");
	const wpVersion = stringOrNull(payload.wpVersion);
	const pluginVersion = stringOrNull(payload.pluginVersion);
	const codeHash = await sha256Hex(installationCode);
	const installation = await env.DB.prepare(
		`SELECT code_hash, expires_at, consumed_at
		 FROM installation_codes
		 WHERE code_hash = ?1`
	)
		.bind(codeHash)
		.first<{ code_hash: string; consumed_at: string | null; expires_at: string }>();

	if (!installation) {
		return errorResponse("invalid_installation_code", "Installation code is invalid.", 400);
	}

	if (installation.consumed_at) {
		return errorResponse("installation_code_consumed", "Installation code has already been used.", 409);
	}

	if (Date.parse(installation.expires_at) <= Date.now()) {
		return errorResponse("installation_code_expired", "Installation code has expired.", 400);
	}

	const siteId = `site_${randomId(20)}`;
	const siteSecret = randomId(48);
	const now = isoNow();
	const allowedModels = parseAllowedModels(env);

	await env.DB.batch([
		env.DB.prepare(
			`INSERT INTO sites (
				site_id,
				site_url,
				home_url,
				admin_email,
				site_secret_ciphertext,
				plugin_version,
				wp_version,
				default_model,
				default_reasoning_effort,
				allowed_models_json,
				created_at,
				updated_at
			) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?11)`
		).bind(
			siteId,
			siteUrl,
			homeUrl,
			adminEmail,
			await encryptString(siteSecret, env.BROKER_ENCRYPTION_KEY),
			pluginVersion,
			wpVersion,
			env.BROKER_DEFAULT_MODEL ?? "gpt-5.3-codex",
			env.BROKER_REASONING_EFFORT ?? "medium",
			JSON.stringify(allowedModels),
			now
		),
		env.DB.prepare(
			`UPDATE installation_codes
			 SET consumed_at = ?2
			 WHERE code_hash = ?1`
		).bind(codeHash, now),
	]);

	return jsonResponse({
		allowedModels,
		brokerBaseUrl: brokerBaseUrl(request),
		defaultModel: env.BROKER_DEFAULT_MODEL ?? "gpt-5.3-codex",
		siteId,
		siteSecret,
	});
}

async function startConnection(request: Request, env: Env, pathname: string): Promise<Response> {
	const signed = await verifySignedRequest(request, env, pathname);
	const wpUserId = requiredNumber(signed.payload.wpUserId, "wpUserId");
	const wpUserEmail = requiredString(signed.payload.wpUserEmail, "wpUserEmail");
	const wpUserDisplayName = requiredString(
		signed.payload.wpUserDisplayName,
		"wpUserDisplayName"
	);
	const state = requiredString(signed.payload.state, "state");
	const returnUrl = requiredString(signed.payload.returnUrl, "returnUrl");
	const authSessionId = `auth_${randomId(20)}`;
	const now = isoNow();
	const runtimeMode = getRuntimeMode(env);

	let runtimeStatus = "runtime_unavailable";
	let runtimeError: string | null =
		"Codex runtime adapter is not configured for this broker deployment.";
	let deviceVerificationUrl: string | null = null;
	let deviceUserCode: string | null = null;

	if ("container" === runtimeMode) {
		try {
			const runtime = await fetchRuntimeJson<RuntimeLoginStartResponse>(
				env,
				signed.site.site_id,
				wpUserId,
				"/runtime/login/start",
				{
					body: JSON.stringify({ authSessionId }),
					method: "POST",
				}
			);

			runtimeStatus = "awaiting_user";
			runtimeError = null;
			deviceVerificationUrl = runtime.verificationUrl;
			deviceUserCode = runtime.userCode;
		} catch (error) {
			runtimeStatus = "runtime_error";
			runtimeError =
				error instanceof Error
					? error.message
					: "Failed to start the runtime login flow.";
		}
	} else if ("unimplemented" === runtimeMode) {
		runtimeStatus = "runtime_unimplemented";
		runtimeError = "This broker runtime mode has not been implemented yet.";
	}

	await env.DB.prepare(
		`INSERT INTO auth_sessions (
			auth_session_id,
			site_id,
			wp_user_id,
			wp_user_email,
			wp_user_display_name,
			local_state,
			return_url,
			runtime_status,
			runtime_error,
			device_verification_url,
			device_user_code,
			broker_code_hash,
			broker_code_ciphertext,
			connection_id,
			expires_at,
			completed_at,
			created_at,
			updated_at
		) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, NULL, NULL, NULL, ?12, NULL, ?13, ?13)`
	)
		.bind(
			authSessionId,
			signed.site.site_id,
			wpUserId,
			wpUserEmail,
			wpUserDisplayName,
			state,
			returnUrl,
			runtimeStatus,
			runtimeError,
			deviceVerificationUrl,
			deviceUserCode,
			isoSecondsFromNow(CONNECT_SESSION_TTL_SECONDS),
			now
		)
		.run();

	return jsonResponse({
		connectUrl: `${brokerBaseUrl(request)}/connect/${authSessionId}`,
	});
}

async function exchangeConnection(request: Request, env: Env, pathname: string): Promise<Response> {
	const signed = await verifySignedRequest(request, env, pathname);
	const wpUserId = requiredNumber(signed.payload.wpUserId, "wpUserId");
	const state = requiredString(signed.payload.state, "state");
	const brokerCode = requiredString(signed.payload.brokerCode, "brokerCode");
	let session = await env.DB.prepare(
		`SELECT *
		 FROM auth_sessions
		 WHERE site_id = ?1
		   AND wp_user_id = ?2
		   AND local_state = ?3
		 ORDER BY created_at DESC
		 LIMIT 1`
	)
		.bind(signed.site.site_id, wpUserId, state)
		.first<AuthSessionRow>();

	if (!session) {
		return errorResponse("invalid_state", "Connection state is invalid or expired.", 400);
	}

	if ("container" === getRuntimeMode(env)) {
		session = await refreshAuthSessionFromRuntime(env, session);
	}

	if (Date.parse(session.expires_at) <= Date.now()) {
		return errorResponse("auth_session_expired", "Connection session has expired.", 400);
	}

	if (!session.broker_code_hash) {
		return errorResponse(
			"connection_not_ready",
			session.runtime_error ?? "Connection has not completed on the broker yet.",
			409
		);
	}

	if (!safeEquals(session.broker_code_hash, await sha256Hex(brokerCode))) {
		return errorResponse("invalid_broker_code", "Broker code is invalid.", 400);
	}

	if (!session.connection_id) {
		return errorResponse("missing_connection", "No linked connection is available.", 409);
	}

	const connection = await env.DB.prepare(
		`SELECT *
		 FROM user_connections
		 WHERE site_id = ?1
		   AND wp_user_id = ?2
		   AND connection_id = ?3`
	)
		.bind(signed.site.site_id, wpUserId, session.connection_id)
		.first<ConnectionRow>();

	if (!connection) {
		return errorResponse("missing_connection", "Linked connection record is missing.", 404);
	}

	return jsonResponse(mapConnectionRecord(connection, signed.site));
}

async function readConnection(request: Request, env: Env, pathname: string): Promise<Response> {
	if (pathname.endsWith("/disconnect")) {
		return errorResponse("method_not_allowed", "Use POST for disconnect.", 405);
	}

	const signed = await verifySignedRequest(request, env, pathname);
	const wpUserId = requiredNumber(
		new URL(request.url).searchParams.get("wpUserId"),
		"wpUserId"
	);
	const connectionId = pathname.split("/").pop();

	if (!connectionId) {
		return errorResponse("invalid_connection_id", "Connection ID is required.", 400);
	}

	let connection = await env.DB.prepare(
		`SELECT *
		 FROM user_connections
		 WHERE site_id = ?1
		   AND wp_user_id = ?2
		   AND connection_id = ?3`
	)
		.bind(signed.site.site_id, wpUserId, connectionId)
		.first<ConnectionRow>();

	if (!connection) {
		return errorResponse("connection_not_found", "Connection not found.", 404);
	}

	if ("container" === getRuntimeMode(env) && "linked" === connection.status) {
		connection = await refreshConnectionFromRuntime(env, signed.site, connection);
	}

	return jsonResponse(mapConnectionRecord(connection, signed.site));
}

async function disconnectConnection(
	request: Request,
	env: Env,
	pathname: string
): Promise<Response> {
	const targetPath = pathname.replace(/\/disconnect$/, "");
	const signed = await verifySignedRequest(request, env, pathname);
	const wpUserId = requiredNumber(signed.payload.wpUserId, "wpUserId");
	const connectionId = targetPath.split("/").pop();

	if (!connectionId) {
		return errorResponse("invalid_connection_id", "Connection ID is required.", 400);
	}

	await env.DB.prepare(
		`UPDATE user_connections
		 SET status = 'revoked',
		     updated_at = ?4
		 WHERE site_id = ?1
		   AND wp_user_id = ?2
		   AND connection_id = ?3`
	)
		.bind(signed.site.site_id, wpUserId, connectionId, isoNow())
		.run();

	if ("container" === getRuntimeMode(env)) {
		try {
			await fetchRuntimeJson(
				env,
				signed.site.site_id,
				wpUserId,
				"/runtime/session/clear",
				{
					body: JSON.stringify({}),
					method: "POST",
				}
			);
		} catch (error) {
			console.warn("Failed to clear runtime auth on disconnect", error);
		}
	}

	return jsonResponse({ ok: true });
}

async function listModels(request: Request, env: Env, pathname: string): Promise<Response> {
	await verifySignedRequest(request, env, pathname);

	const models = parseAllowedModels(env).map((modelId) => ({
		defaultReasoningEffort: env.BROKER_REASONING_EFFORT ?? "medium",
		displayName: modelId,
		id: modelId,
		inputModalities: ["text"],
		isDefault: modelId === (env.BROKER_DEFAULT_MODEL ?? "gpt-5.3-codex"),
		model: modelId,
		supportedReasoningEfforts: ["minimal", "low", "medium", "high"],
	}));

	return jsonResponse({ data: models });
}

async function textSupport(request: Request, env: Env, pathname: string): Promise<Response> {
	const signed = await verifySignedRequest(request, env, pathname);
	const wpUserId = requiredNumber(signed.payload.wpUserId, "wpUserId");
	const connection = await env.DB.prepare(
		`SELECT *
		 FROM user_connections
		 WHERE site_id = ?1
		   AND wp_user_id = ?2`
	)
		.bind(signed.site.site_id, wpUserId)
		.first<ConnectionRow>();

	if (!connection) {
		return jsonResponse({ ready: false, reason: "user_unlinked" });
	}

	if ("linked" !== connection.status) {
		return jsonResponse({ ready: false, reason: connection.status });
	}

	if ("unavailable" === getRuntimeMode(env)) {
		return jsonResponse({ ready: false, reason: "runtime_unavailable" });
	}

	return jsonResponse({ ready: true, reason: "ready" });
}

async function textResponse(request: Request, env: Env, pathname: string): Promise<Response> {
	const signed = await verifySignedRequest(request, env, pathname);
	const wpUserId = requiredNumber(signed.payload.wpUserId, "wpUserId");
	const connectionId = requiredString(signed.payload.connectionId, "connectionId");
	const input = requiredString(signed.payload.input, "input");

	const connection = await env.DB.prepare(
		`SELECT *
		 FROM user_connections
		 WHERE site_id = ?1
		   AND wp_user_id = ?2
		   AND connection_id = ?3`
	)
		.bind(signed.site.site_id, wpUserId, connectionId)
		.first<ConnectionRow>();

	if (!connection) {
		return errorResponse("connection_not_found", "Connection not found.", 404);
	}

	if ("linked" !== connection.status) {
		return errorResponse("connection_not_ready", "Connection is not ready for generation.", 409);
	}

	if ("container" !== getRuntimeMode(env)) {
		return errorResponse(
			"runtime_unavailable",
			"Codex execution is not available in this Cloudflare Worker shell yet. Add a stateful Codex runtime adapter before using text generation.",
			503
		);
	}

	const response = await fetchRuntimeJson<RuntimeTextResponse>(
		env,
		signed.site.site_id,
		wpUserId,
		"/runtime/responses/text",
		{
			body: JSON.stringify({
				input,
				model: pickRequestedModel(
					stringOrNull(signed.payload.model),
					parseJsonArray(connection.allowed_models_json, parseAllowedModelsFromSite(signed.site)),
					connection.default_model ?? signed.site.default_model
				),
				reasoningEffort:
					stringOrNull(signed.payload.reasoningEffort) ?? signed.site.default_reasoning_effort,
				requestId: stringOrNull(signed.payload.requestId) ?? `req_${randomId(20)}`,
				responseFormat:
					signed.payload.responseFormat &&
					"object" === typeof signed.payload.responseFormat &&
					!Array.isArray(signed.payload.responseFormat)
						? signed.payload.responseFormat
						: null,
				systemInstruction: stringOrNull(signed.payload.systemInstruction),
			}),
			method: "POST",
		}
	);

	await updateConnectionFromTextResponse(env, signed.site, connection, response);

	return jsonResponse({
		account: response.account ?? mapConnectionAccount(connection),
		finishReason: response.finishReason,
		model: response.model ?? connection.default_model ?? signed.site.default_model,
		outputText: response.outputText,
		rateLimits:
			response.rateLimits ??
			parseJsonObject(connection.rate_limits_json, {
				limitId: "codex",
				planType: connection.plan_type ?? "unknown",
				primary: null,
				secondary: null,
			}),
		requestId: response.requestId,
		structuredOutput: response.structuredOutput,
		usage: response.usage,
	});
}

async function connectPage(request: Request, env: Env, pathname: string): Promise<Response> {
	const segments = pathname.split("/").filter(Boolean);
	const authSessionId = segments[1];
	const isStatusRequest = "status" === segments[2];

	if (!authSessionId) {
		return errorResponse("invalid_auth_session", "Auth session not found.", 404);
	}

	let session = await readAuthSession(env, authSessionId);

	if (!session) {
		return errorResponse("invalid_auth_session", "Auth session not found.", 404);
	}

	if ("container" === getRuntimeMode(env)) {
		session = await refreshAuthSessionFromRuntime(env, session);
	}

	if (isStatusRequest) {
		return jsonResponse({
			authSessionId: session.auth_session_id,
			completedAt: session.completed_at,
			deviceUserCode: session.device_user_code,
			deviceVerificationUrl: session.device_verification_url,
			redirectUrl: await buildSessionRedirectUrl(env, session),
			returnUrl: session.return_url,
			runtimeError: session.runtime_error,
			runtimeStatus: session.runtime_status,
		});
	}

	const statusUrl = `${brokerBaseUrl(request)}/connect/${authSessionId}/status`;
	const html = `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Codex Broker Connection</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4f1ea;
      --card: #fffaf1;
      --ink: #1f1a14;
      --muted: #5f564a;
      --accent: #8d5a2b;
      --border: #d9cfbe;
      --error: #8a1f11;
    }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: Georgia, "Times New Roman", serif;
      background:
        radial-gradient(circle at top left, rgba(141, 90, 43, 0.10), transparent 30%),
        linear-gradient(180deg, #f8f4ec 0%, var(--bg) 100%);
      color: var(--ink);
      display: grid;
      place-items: center;
      padding: 24px;
    }
    main {
      width: min(720px, 100%);
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(31, 26, 20, 0.08);
      padding: 32px;
    }
    h1 {
      margin: 0 0 16px;
      font-size: clamp(2rem, 5vw, 3rem);
      line-height: 1;
    }
    p {
      margin: 0 0 16px;
      color: var(--muted);
      font-size: 1rem;
      line-height: 1.6;
    }
    .status {
      margin: 24px 0;
      padding: 16px;
      border-radius: 14px;
      background: #f3e5d5;
      border: 1px solid #d7b996;
      color: var(--ink);
      font-family: ui-monospace, SFMono-Regular, monospace;
      white-space: pre-wrap;
    }
    .code {
      font-size: clamp(1.5rem, 5vw, 2.5rem);
      letter-spacing: 0.2em;
      font-weight: 700;
      color: var(--accent);
    }
    .error {
      color: var(--error);
      white-space: pre-wrap;
    }
    a.button {
      display: inline-block;
      padding: 12px 16px;
      border-radius: 999px;
      background: var(--accent);
      color: #fff;
      text-decoration: none;
      font-weight: 600;
    }
  </style>
</head>
<body>
  <main>
    <h1>Connect your Codex account</h1>
    <p id="lead">Preparing a managed ChatGPT device-code login for this WordPress user.</p>
    <div class="status" id="runtime-status">Loading session state…</div>
    <div id="device-block" hidden>
      <p>Open the verification page in another tab and enter this code:</p>
      <div class="code" id="device-code"></div>
      <p><a class="button" id="verification-link" href="#" target="_blank" rel="noopener noreferrer">Open verification page</a></p>
    </div>
    <p class="error" id="error-block" hidden></p>
    <p><a class="button" href="${escapeHtml(session.return_url)}">Return to WordPress</a></p>
  </main>
  <script>
    const statusUrl = ${JSON.stringify(statusUrl)};
    const lead = document.getElementById("lead");
    const runtimeStatus = document.getElementById("runtime-status");
    const deviceBlock = document.getElementById("device-block");
    const deviceCode = document.getElementById("device-code");
    const verificationLink = document.getElementById("verification-link");
    const errorBlock = document.getElementById("error-block");

    async function refreshStatus() {
      const response = await fetch(statusUrl, {
        headers: { Accept: "application/json" },
      });
      const data = await response.json();
      runtimeStatus.textContent =
        "Auth session: " + data.authSessionId + "\\n" +
        "Runtime status: " + data.runtimeStatus + "\\n" +
        "Runtime error: " + (data.runtimeError || "None");

      if (data.redirectUrl) {
        lead.textContent = "Connection complete. Returning to WordPress…";
        window.location.replace(data.redirectUrl);
        return;
      }

      if (data.deviceVerificationUrl && data.deviceUserCode) {
        lead.textContent = "Complete the ChatGPT device-code login, then this page will return to WordPress automatically.";
        deviceBlock.hidden = false;
        verificationLink.href = data.deviceVerificationUrl;
        deviceCode.textContent = data.deviceUserCode;
      }

      if (data.runtimeError) {
        errorBlock.hidden = false;
        errorBlock.textContent = data.runtimeError;
      }

      window.setTimeout(refreshStatus, 3000);
    }

    refreshStatus().catch((error) => {
      errorBlock.hidden = false;
      errorBlock.textContent = error instanceof Error ? error.message : String(error);
    });
  </script>
</body>
</html>`;

	return new Response(html, {
		headers: {
			"content-type": "text/html; charset=utf-8",
		},
	});
}

async function refreshAuthSessionFromRuntime(
	env: Env,
	session: AuthSessionRow
): Promise<AuthSessionRow> {
	if (session.completed_at || Date.parse(session.expires_at) <= Date.now()) {
		return session;
	}

	try {
		const status = await fetchRuntimeJson<RuntimeLoginStatusResponse>(
			env,
			session.site_id,
			session.wp_user_id,
			`/runtime/login/status?authSessionId=${encodeURIComponent(session.auth_session_id)}`
		);

		if ("completed" === status.status) {
			return finalizeCompletedAuthSession(env, session);
		}

		await env.DB.prepare(
			`UPDATE auth_sessions
			 SET runtime_status = ?2,
			     runtime_error = ?3,
			     device_verification_url = ?4,
			     device_user_code = ?5,
			     updated_at = ?6
			 WHERE auth_session_id = ?1`
		)
			.bind(
				session.auth_session_id,
				"pending" === status.status ? "awaiting_user" : "runtime_error",
				status.error,
				status.verificationUrl,
				status.userCode,
				isoNow()
			)
			.run();
	} catch (error) {
		await env.DB.prepare(
			`UPDATE auth_sessions
			 SET runtime_status = 'runtime_error',
			     runtime_error = ?2,
			     updated_at = ?3
			 WHERE auth_session_id = ?1`
		)
			.bind(
				session.auth_session_id,
				error instanceof Error ? error.message : "Failed to refresh runtime status.",
				isoNow()
			)
			.run();
	}

	return (await readAuthSession(env, session.auth_session_id)) ?? session;
}

async function finalizeCompletedAuthSession(
	env: Env,
	session: AuthSessionRow
): Promise<AuthSessionRow> {
	if (session.completed_at && session.connection_id) {
		return session;
	}

	const site = await env.DB.prepare(
		`SELECT site_id, site_secret_ciphertext, default_model, default_reasoning_effort, allowed_models_json
		 FROM sites
		 WHERE site_id = ?1`
	)
		.bind(session.site_id)
		.first<SiteRow>();

	if (!site) {
		throw new HttpError(404, "site_not_found", "Site registration could not be loaded.");
	}

	const snapshot = await fetchRuntimeJson<RuntimeAccountSnapshotResponse>(
		env,
		session.site_id,
		session.wp_user_id,
		"/runtime/account/snapshot"
	);
	const existing = await env.DB.prepare(
		`SELECT *
		 FROM user_connections
		 WHERE site_id = ?1
		   AND wp_user_id = ?2`
	)
		.bind(session.site_id, session.wp_user_id)
		.first<ConnectionRow>();
	const connectionId = existing?.connection_id ?? `conn_${randomId(20)}`;
	const brokerCode = `bc_${randomId(24)}`;

	await upsertConnectionFromSnapshot(
		env,
		site,
		session.wp_user_id,
		connectionId,
		snapshot,
		"linked"
	);

	await env.DB.prepare(
		`UPDATE auth_sessions
		 SET runtime_status = 'linked',
		     runtime_error = NULL,
		     broker_code_hash = ?2,
		     broker_code_ciphertext = ?3,
		     connection_id = ?4,
		     completed_at = ?5,
		     updated_at = ?5
		 WHERE auth_session_id = ?1`
	)
		.bind(
			session.auth_session_id,
			await sha256Hex(brokerCode),
			await encryptString(brokerCode, env.BROKER_ENCRYPTION_KEY),
			connectionId,
			isoNow()
		)
		.run();

	return (await readAuthSession(env, session.auth_session_id)) ?? session;
}

async function refreshConnectionFromRuntime(
	env: Env,
	site: SiteRow,
	connection: ConnectionRow
): Promise<ConnectionRow> {
	const snapshot = await fetchRuntimeJson<RuntimeAccountSnapshotResponse>(
		env,
		connection.site_id,
		connection.wp_user_id,
		"/runtime/account/snapshot"
	);

	await upsertConnectionFromSnapshot(
		env,
		site,
		connection.wp_user_id,
		connection.connection_id,
		snapshot,
		connection.status
	);

	return (
		(await env.DB.prepare(
			`SELECT *
			 FROM user_connections
			 WHERE site_id = ?1
			   AND wp_user_id = ?2
			   AND connection_id = ?3`
		)
			.bind(connection.site_id, connection.wp_user_id, connection.connection_id)
			.first<ConnectionRow>()) ?? connection
	);
}

async function upsertConnectionFromSnapshot(
	env: Env,
	site: SiteRow,
	wpUserId: number,
	connectionId: string,
	snapshot: RuntimeAccountSnapshotResponse,
	status: string
): Promise<void> {
	const allowedModels = resolveAllowedModels(site, snapshot.models);
	const defaultModel = selectDefaultModel(site, snapshot, allowedModels);
	const account = snapshot.account;
	const rateLimits = snapshot.rateLimits ?? {
		limitId: "codex",
		planType: account.planType ?? "unknown",
		primary: null,
		secondary: null,
	};
	const now = isoNow();

	await env.DB.prepare(
		`INSERT INTO user_connections (
			connection_id,
			site_id,
			wp_user_id,
			status,
			broker_user_id,
			account_email,
			plan_type,
			auth_mode,
			default_model,
			allowed_models_json,
			rate_limits_json,
			session_expires_at,
			runtime_payload_json,
			created_at,
			updated_at
		) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, NULL, ?12, ?13, ?13)
		ON CONFLICT(site_id, wp_user_id) DO UPDATE SET
			connection_id = excluded.connection_id,
			status = excluded.status,
			broker_user_id = excluded.broker_user_id,
			account_email = excluded.account_email,
			plan_type = excluded.plan_type,
			auth_mode = excluded.auth_mode,
			default_model = excluded.default_model,
			allowed_models_json = excluded.allowed_models_json,
			rate_limits_json = excluded.rate_limits_json,
			session_expires_at = excluded.session_expires_at,
			runtime_payload_json = excluded.runtime_payload_json,
			updated_at = excluded.updated_at`
	)
		.bind(
			connectionId,
			site.site_id,
			wpUserId,
			status,
			account.email ?? `wp-user-${wpUserId}`,
			account.email,
			account.planType,
			account.authMode ?? "chatgpt",
			defaultModel,
			JSON.stringify(allowedModels),
			JSON.stringify(rateLimits),
			JSON.stringify(snapshot),
			now
		)
		.run();
}

async function updateConnectionFromTextResponse(
	env: Env,
	site: SiteRow,
	connection: ConnectionRow,
	response: RuntimeTextResponse
): Promise<void> {
	const account = response.account ?? mapConnectionAccount(connection);
	const rateLimits =
		response.rateLimits ??
		parseJsonObject(connection.rate_limits_json, {
			limitId: "codex",
			planType: connection.plan_type ?? "unknown",
			primary: null,
			secondary: null,
		});

	await env.DB.prepare(
		`UPDATE user_connections
		 SET account_email = ?4,
		     plan_type = ?5,
		     auth_mode = ?6,
		     default_model = ?7,
		     rate_limits_json = ?8,
		     runtime_payload_json = ?9,
		     updated_at = ?10
		 WHERE site_id = ?1
		   AND wp_user_id = ?2
		   AND connection_id = ?3`
	)
		.bind(
			connection.site_id,
			connection.wp_user_id,
			connection.connection_id,
			account.email,
			account.planType,
			account.authMode,
			response.model ?? connection.default_model ?? site.default_model,
			JSON.stringify(rateLimits),
			JSON.stringify({
				lastResponse: {
					account,
					finishReason: response.finishReason,
					model: response.model,
					rateLimits,
				},
			}),
			isoNow()
		)
		.run();
}

async function readAuthSession(env: Env, authSessionId: string): Promise<AuthSessionRow | null> {
	return env.DB.prepare(
		`SELECT *
		 FROM auth_sessions
		 WHERE auth_session_id = ?1`
	)
		.bind(authSessionId)
		.first<AuthSessionRow>();
}

async function buildSessionRedirectUrl(
	env: Env,
	session: AuthSessionRow
): Promise<string | null> {
	if (!session.completed_at || !session.broker_code_ciphertext) {
		return null;
	}

	const redirectUrl = new URL(session.return_url);
	redirectUrl.searchParams.set("broker_code", await decryptString(session.broker_code_ciphertext, env.BROKER_ENCRYPTION_KEY));
	redirectUrl.searchParams.set("state", session.local_state);

	return redirectUrl.toString();
}

async function verifySignedRequest(
	request: Request,
	env: Env,
	expectedPath: string
): Promise<SignedRequestContext> {
	const siteId = request.headers.get("X-Codex-Site-Id");
	const timestamp = request.headers.get("X-Codex-Timestamp");
	const signature = request.headers.get("X-Codex-Signature");

	if (!siteId || !timestamp || !signature) {
		throw new HttpError(401, "unauthorized", "Missing broker signature headers.");
	}

	const timestampSeconds = Number.parseInt(timestamp, 10);

	if (!Number.isFinite(timestampSeconds)) {
		throw new HttpError(401, "unauthorized", "Timestamp header is invalid.");
	}

	if (Math.abs(Math.floor(Date.now() / 1000) - timestampSeconds) > TIMESTAMP_SKEW_SECONDS) {
		throw new HttpError(401, "unauthorized", "Timestamp is outside the allowed skew window.");
	}

	const site = await env.DB.prepare(
		`SELECT site_id, site_secret_ciphertext, default_model, default_reasoning_effort, allowed_models_json
		 FROM sites
		 WHERE site_id = ?1`
	)
		.bind(siteId)
		.first<SiteRow>();

	if (!site) {
		throw new HttpError(401, "unauthorized", "Unknown site identity.");
	}

	const bodyText =
		"GET" === request.method || "HEAD" === request.method ? "" : await request.text();
	const bodyHash = await sha256Hex(bodyText);
	const siteSecret = await decryptString(site.site_secret_ciphertext, env.BROKER_ENCRYPTION_KEY);
	const expectedSignature = await hmacHex(
		siteSecret,
		`${request.method.toUpperCase()}\n${expectedPath}\n${timestamp}\n${bodyHash}`
	);

	if (!safeEquals(expectedSignature, signature)) {
		throw new HttpError(401, "unauthorized", "Signature check failed.");
	}

	return {
		bodyText,
		payload: "" === bodyText ? {} : parseJsonObject(bodyText),
		site,
	};
}

function mapConnectionRecord(connection: ConnectionRow, site: SiteRow): Record<string, unknown> {
	return {
		account: mapConnectionAccount(connection),
		brokerUserId: connection.broker_user_id ?? "",
		connectionId: connection.connection_id,
		defaults: {
			model: connection.default_model ?? site.default_model,
			reasoningEffort: site.default_reasoning_effort,
		},
		models: parseJsonArray(connection.allowed_models_json, parseAllowedModelsFromSite(site)),
		rateLimits: parseJsonObject(connection.rate_limits_json, {
			limitId: "codex",
			planType: connection.plan_type ?? "unknown",
			primary: null,
			secondary: null,
		}),
		sessionExpiresAt: connection.session_expires_at,
		status: connection.status,
	};
}

function mapConnectionAccount(connection: ConnectionRow): {
	authMode: string | null;
	email: string | null;
	planType: string | null;
} {
	return {
		authMode: connection.auth_mode ?? "chatgpt",
		email: connection.account_email,
		planType: connection.plan_type,
	};
}

function resolveAllowedModels(site: SiteRow, runtimeModels: RuntimeAccountSnapshotResponse["models"]): string[] {
	const runtimeIds = runtimeModels
		.map((model) => model.model)
		.filter((value): value is string => "string" === typeof value && "" !== value);
	const siteModels = parseAllowedModelsFromSite(site);

	if (runtimeIds.length > 0) {
		return runtimeIds;
	}

	return siteModels;
}

function selectDefaultModel(
	site: SiteRow,
	snapshot: RuntimeAccountSnapshotResponse,
	allowedModels: string[]
): string {
	if (snapshot.defaultModel && allowedModels.includes(snapshot.defaultModel)) {
		return snapshot.defaultModel;
	}

	if (allowedModels.includes(site.default_model)) {
		return site.default_model;
	}

	return allowedModels[0] ?? site.default_model;
}

function pickRequestedModel(
	requestedModel: string | null,
	allowedModels: string[],
	fallbackModel: string
): string {
	if (requestedModel && allowedModels.includes(requestedModel)) {
		return requestedModel;
	}

	if (allowedModels.includes(fallbackModel)) {
		return fallbackModel;
	}

	return allowedModels[0] ?? fallbackModel;
}

function getRuntimeMode(env: Env): RuntimeMode {
	return "container" === env.BROKER_RUNTIME_MODE
		? "container"
		: "unavailable" === env.BROKER_RUNTIME_MODE || !env.BROKER_RUNTIME_MODE
			? "unavailable"
			: "unimplemented";
}

function parseAllowedModels(env: Env): string[] {
	const raw = env.BROKER_ALLOWED_MODELS ?? "gpt-5-codex,gpt-5.3-codex";
	return raw
		.split(",")
		.map((value) => value.trim())
		.filter(Boolean);
}

function parseAllowedModelsFromSite(site: SiteRow): string[] {
	return parseJsonArray(site.allowed_models_json, [site.default_model]);
}

function parseJsonArray(input: string, fallback: string[]): string[] {
	try {
		const parsed = JSON.parse(input);
		return Array.isArray(parsed)
			? parsed.filter((value): value is string => "string" === typeof value)
			: fallback;
	} catch {
		return fallback;
	}
}

function parseJsonObject(
	input: string,
	fallback: Record<string, unknown> = {}
): Record<string, unknown> {
	try {
		const parsed = JSON.parse(input);
		return parsed && "object" === typeof parsed && !Array.isArray(parsed)
			? (parsed as Record<string, unknown>)
			: fallback;
	} catch {
		return fallback;
	}
}

async function readJsonBody(request: Request): Promise<Record<string, unknown>> {
	const text = await request.text();
	return "" === text ? {} : parseJsonObject(text);
}

function requiredString(value: unknown, fieldName: string): string {
	if ("string" !== typeof value || "" === value.trim()) {
		throw new HttpError(400, "invalid_request", `${fieldName} is required.`);
	}

	return value.trim();
}

function stringOrNull(value: unknown): string | null {
	return "string" === typeof value && "" !== value.trim() ? value.trim() : null;
}

function requiredNumber(value: unknown, fieldName: string): number {
	const numericValue =
		"number" === typeof value
			? value
			: "string" === typeof value
				? Number.parseInt(value, 10)
				: Number.NaN;

	if (!Number.isFinite(numericValue)) {
		throw new HttpError(400, "invalid_request", `${fieldName} must be a number.`);
	}

	return numericValue;
}

function clampNumber(
	value: unknown,
	minimum: number,
	maximum: number,
	fallback: number
): number {
	if ("number" !== typeof value || !Number.isFinite(value)) {
		return fallback;
	}

	return Math.min(maximum, Math.max(minimum, Math.floor(value)));
}

function normalizePath(pathname: string): string {
	if ("/" === pathname) {
		return pathname;
	}

	return pathname.replace(/\/+$/, "");
}

function brokerBaseUrl(request: Request): string {
	const url = new URL(request.url);
	return `${url.protocol}//${url.host}`;
}

function jsonResponse(payload: Record<string, unknown>, status = 200): Response {
	return new Response(JSON.stringify(payload, null, 2), {
		status,
		headers: {
			"content-type": "application/json; charset=utf-8",
		},
	});
}

function errorResponse(code: string, message: string, status: number): Response {
	return jsonResponse(
		{
			error: {
				code,
				message,
			},
		},
		status
	);
}

function isoNow(): string {
	return new Date().toISOString();
}

function isoSecondsFromNow(seconds: number): string {
	return new Date(Date.now() + seconds * 1000).toISOString();
}

function randomId(length: number): string {
	const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789";
	const bytes = crypto.getRandomValues(new Uint8Array(length));
	let output = "";

	for (const value of bytes) {
		output += alphabet[value % alphabet.length];
	}

	return output;
}

function isAuthorizedAdminRequest(request: Request, env: Env): boolean {
	const authorization = request.headers.get("Authorization");

	if (!authorization || !authorization.startsWith("Bearer ")) {
		return false;
	}

	return safeEquals(authorization.slice("Bearer ".length), env.BROKER_ADMIN_TOKEN);
}

async function sha256Hex(input: string): Promise<string> {
	const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(input));
	return toHex(new Uint8Array(digest));
}

async function hmacHex(secret: string, payload: string): Promise<string> {
	const key = await crypto.subtle.importKey(
		"raw",
		new TextEncoder().encode(secret),
		{ name: "HMAC", hash: "SHA-256" },
		false,
		["sign"]
	);
	const signature = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(payload));
	return toHex(new Uint8Array(signature));
}

async function encryptString(plaintext: string, keyMaterial: string): Promise<string> {
	const key = await importAesKey(keyMaterial);
	const iv = crypto.getRandomValues(new Uint8Array(12));
	const ciphertext = await crypto.subtle.encrypt(
		{ name: "AES-GCM", iv },
		key,
		new TextEncoder().encode(plaintext)
	);
	return `${toBase64(iv)}.${toBase64(new Uint8Array(ciphertext))}`;
}

async function decryptString(ciphertext: string, keyMaterial: string): Promise<string> {
	const [ivB64, payloadB64] = ciphertext.split(".");

	if (!ivB64 || !payloadB64) {
		throw new Error("Encrypted secret is malformed.");
	}

	const key = await importAesKey(keyMaterial);
	const iv = fromBase64(ivB64);
	const payload = fromBase64(payloadB64);
	const decrypted = await crypto.subtle.decrypt({ name: "AES-GCM", iv }, key, payload);
	return new TextDecoder().decode(decrypted);
}

async function importAesKey(keyMaterial: string): Promise<CryptoKey> {
	const rawKey = fromBase64(keyMaterial);

	if (32 !== rawKey.byteLength) {
		throw new Error("BROKER_ENCRYPTION_KEY must decode to exactly 32 bytes.");
	}

	return crypto.subtle.importKey("raw", rawKey, "AES-GCM", false, ["encrypt", "decrypt"]);
}

function toHex(bytes: Uint8Array): string {
	return Array.from(bytes)
		.map((value) => value.toString(16).padStart(2, "0"))
		.join("");
}

function safeEquals(left: string, right: string): boolean {
	if (left.length !== right.length) {
		return false;
	}

	let mismatch = 0;

	for (let index = 0; index < left.length; index += 1) {
		mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index);
	}

	return 0 === mismatch;
}

function toBase64(bytes: Uint8Array): string {
	let binary = "";

	for (const value of bytes) {
		binary += String.fromCharCode(value);
	}

	return btoa(binary);
}

function fromBase64(value: string): Uint8Array {
	const binary = atob(value);
	const output = new Uint8Array(binary.length);

	for (let index = 0; index < binary.length; index += 1) {
		output[index] = binary.charCodeAt(index);
	}

	return output;
}

function escapeHtml(value: string): string {
	return value
		.replaceAll("&", "&amp;")
		.replaceAll("<", "&lt;")
		.replaceAll(">", "&gt;")
		.replaceAll('"', "&quot;")
		.replaceAll("'", "&#39;");
}

class HttpError extends Error {
	code: string;
	status: number;

	constructor(status: number, code: string, message: string) {
		super(message);
		this.status = status;
		this.code = code;
	}
}
