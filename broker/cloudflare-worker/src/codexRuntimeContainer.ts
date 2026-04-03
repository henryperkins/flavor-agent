import { Container } from "@cloudflare/containers";

export interface RuntimeLoginStartResponse {
	authSessionId: string;
	loginId: string;
	status: "pending";
	verificationUrl: string;
	userCode: string;
}

export interface RuntimeLoginStatusResponse {
	authSessionId: string;
	loginId: string | null;
	status: "pending" | "completed" | "error" | "missing";
	verificationUrl: string | null;
	userCode: string | null;
	error: string | null;
	authStored?: boolean;
}

export interface RuntimeModel {
	defaultReasoningEffort?: string | null;
	displayName?: string;
	id?: string;
	inputModalities?: string[];
	isDefault?: boolean;
	model: string;
	supportedReasoningEfforts?: string[];
}

export interface RuntimeAccountSnapshotResponse {
	account: {
		authMode: string | null;
		email: string | null;
		planType: string | null;
		type: string | null;
	};
	authStored: boolean;
	defaultModel: string | null;
	models: RuntimeModel[];
	rateLimits: Record<string, unknown>;
}

export interface RuntimeTextResponse {
	account?: RuntimeAccountSnapshotResponse["account"];
	authStored?: boolean;
	finishReason: string;
	model: string | null;
	outputText: string;
	rateLimits?: Record<string, unknown>;
	requestId: string;
	structuredOutput?: unknown;
	usage: {
		inputTokens: number;
		outputTokens: number;
		reasoningTokens?: number;
	};
}

interface RuntimeContainerEnv {
	DB: D1Database;
	BROKER_ENCRYPTION_KEY: string;
	BROKER_ADMIN_TOKEN: string;
	BROKER_ALLOWED_MODELS?: string;
	BROKER_DEFAULT_MODEL?: string;
	BROKER_REASONING_EFFORT?: string;
	BROKER_RUNTIME_MODE?: string;
	CODEX_RUNTIME: DurableObjectNamespace<CodexRuntimeContainer>;
}

const AUTH_STORAGE_KEY = "codex-auth-json";
const RUNTIME_PORT = 8080;

export function runtimeObjectName(siteId: string, wpUserId: number): string {
	return `${siteId}:${wpUserId}`;
}

export async function fetchRuntimeJson<T>(
	env: { CODEX_RUNTIME: DurableObjectNamespace },
	siteId: string,
	wpUserId: number,
	path: string,
	init: RequestInit = {}
): Promise<T> {
	const stub = env.CODEX_RUNTIME.getByName(runtimeObjectName(siteId, wpUserId));
	const headers = new Headers(init.headers);

	if (
		undefined !== init.body &&
		!headers.has("content-type") &&
		("string" === typeof init.body || init.body instanceof Uint8Array)
	) {
		headers.set("content-type", "application/json; charset=utf-8");
	}

	const response = await stub.fetch(
		new Request(`https://runtime.internal${path}`, {
			...init,
			headers,
		})
	);
	const text = await response.text();
	const data = "" === text ? {} : JSON.parse(text);

	if (!response.ok) {
		const message =
			data && "object" === typeof data && "error" in data
				? String((data as { error?: { message?: string } }).error?.message ?? "Runtime request failed.")
				: `Runtime request failed with status ${response.status}.`;
		throw new Error(message);
	}

	return data as T;
}

export class CodexRuntimeContainer extends Container<RuntimeContainerEnv> {
	defaultPort = RUNTIME_PORT;
	sleepAfter = "10m";

	async fetch(request: Request): Promise<Response> {
		const url = new URL(request.url);

		if ("GET" === request.method && "/runtime/healthz" === url.pathname) {
			return this.jsonResponse({
				authStored: await this.hasStoredAuth(),
				ok: true,
				running: (await this.getState()).status,
			});
		}

		if ("POST" === request.method && "/runtime/login/start" === url.pathname) {
			const payload = await this.readJsonBody(request);
			const authSessionId = this.requiredString(payload.authSessionId, "authSessionId");
			const response = await this.callRuntimeJson<RuntimeLoginStartResponse>("/login/start", {
				authSessionId,
			});
			return this.jsonResponse(response);
		}

		if ("GET" === request.method && "/runtime/login/status" === url.pathname) {
			const authSessionId = this.requiredString(
				url.searchParams.get("authSessionId"),
				"authSessionId"
			);
			const response = await this.callRuntimeJson<
				RuntimeLoginStatusResponse & { authJson?: string | null }
			>(`/login/status?authSessionId=${encodeURIComponent(authSessionId)}`);

			if ("completed" === response.status && response.authJson) {
				await this.saveStoredAuth(response.authJson);
			}

			return this.jsonResponse({
				authSessionId: response.authSessionId,
				authStored: await this.hasStoredAuth(),
				error: response.error,
				loginId: response.loginId,
				status: response.status,
				userCode: response.userCode,
				verificationUrl: response.verificationUrl,
			});
		}

		if ("GET" === request.method && "/runtime/account/snapshot" === url.pathname) {
			await this.bootstrapStoredAuth();
			const response = await this.callRuntimeJson<
				RuntimeAccountSnapshotResponse & { authJson?: string | null }
			>("/account/snapshot");

			if (response.authJson) {
				await this.saveStoredAuth(response.authJson);
			}

			return this.jsonResponse({
				account: response.account,
				authStored: await this.hasStoredAuth(),
				defaultModel: response.defaultModel,
				models: response.models,
				rateLimits: response.rateLimits,
			});
		}

		if ("POST" === request.method && "/runtime/responses/text" === url.pathname) {
			await this.bootstrapStoredAuth();
			const payload = await this.readJsonBody(request);
			const response = await this.callRuntimeJson<RuntimeTextResponse & { authJson?: string | null }>(
				"/responses/text",
				payload
			);

			if (response.authJson) {
				await this.saveStoredAuth(response.authJson);
			}

			return this.jsonResponse({
				account: response.account,
				authStored: await this.hasStoredAuth(),
				finishReason: response.finishReason,
				model: response.model,
				outputText: response.outputText,
				rateLimits: response.rateLimits ?? {},
				requestId: response.requestId,
				structuredOutput: response.structuredOutput,
				usage: response.usage,
			});
		}

		if ("POST" === request.method && "/runtime/session/clear" === url.pathname) {
			await this.deleteStoredAuth();
			await this.callRuntimeJson("/session/clear", {});
			return this.jsonResponse({ ok: true });
		}

		return this.jsonError("not_found", "Runtime route not found.", 404);
	}

	private async callRuntimeJson<T>(
		path: string,
		payload?: Record<string, unknown>
	): Promise<T> {
		await this.startAndWaitForPorts({ ports: [RUNTIME_PORT] });

		const response = await this.containerFetch(
			`http://runtime${path}`,
			{
				body: undefined === payload ? undefined : JSON.stringify(payload),
				headers:
					undefined === payload
						? undefined
						: {
								"content-type": "application/json; charset=utf-8",
							},
				method: undefined === payload ? "GET" : "POST",
			},
			RUNTIME_PORT
		);
		const text = await response.text();
		const data = "" === text ? {} : JSON.parse(text);

		if (!response.ok) {
			const message =
				data && "object" === typeof data && "error" in data
					? String((data as { error?: { message?: string } }).error?.message ?? "Runtime request failed.")
					: `Runtime request failed with status ${response.status}.`;
			throw new Error(message);
		}

		return data as T;
	}

	private async bootstrapStoredAuth(): Promise<void> {
		const authJson = await this.readStoredAuth();

		if (!authJson) {
			return;
		}

		await this.callRuntimeJson("/session/bootstrap", {
			authJson,
		});
	}

	private async readStoredAuth(): Promise<string | null> {
		const value = await this.ctx.storage.get<string>(AUTH_STORAGE_KEY);
		return "string" === typeof value && "" !== value ? value : null;
	}

	private async hasStoredAuth(): Promise<boolean> {
		return null !== (await this.readStoredAuth());
	}

	private async saveStoredAuth(authJson: string): Promise<void> {
		await this.ctx.storage.put(AUTH_STORAGE_KEY, authJson);
	}

	private async deleteStoredAuth(): Promise<void> {
		await this.ctx.storage.delete(AUTH_STORAGE_KEY);
	}

	private async readJsonBody(request: Request): Promise<Record<string, unknown>> {
		const text = await request.text();

		if ("" === text) {
			return {};
		}

		const payload = JSON.parse(text);

		if (!payload || "object" !== typeof payload || Array.isArray(payload)) {
			throw new Error("Request body must be a JSON object.");
		}

		return payload as Record<string, unknown>;
	}

	private requiredString(value: unknown, fieldName: string): string {
		if ("string" !== typeof value || "" === value.trim()) {
			throw new Error(`${fieldName} is required.`);
		}

		return value.trim();
	}

	private jsonResponse(payload: unknown, status = 200): Response {
		return new Response(JSON.stringify(payload, null, 2), {
			status,
			headers: {
				"content-type": "application/json; charset=utf-8",
			},
		});
	}

	private jsonError(code: string, message: string, status: number): Response {
		return this.jsonResponse(
			{
				error: {
					code,
					message,
				},
			},
			status
		);
	}
}
