from __future__ import annotations

import json
import os
import subprocess
import threading
import time
import traceback
import uuid
from contextlib import contextmanager
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Callable
from urllib.parse import parse_qs, urlparse

CODEX_BIN = os.environ.get("CODEX_BIN", "/usr/local/bin/codex")
CODEX_HOME = Path(os.environ.get("CODEX_HOME", "/var/lib/codex"))
PORT = int(os.environ.get("CODEX_RUNTIME_PORT", "8080"))
REQUEST_TIMEOUT = float(os.environ.get("CODEX_RUNTIME_REQUEST_TIMEOUT", "60"))
TURN_TIMEOUT = float(os.environ.get("CODEX_RUNTIME_TURN_TIMEOUT", "300"))
LOGIN_TIMEOUT = float(os.environ.get("CODEX_RUNTIME_LOGIN_TIMEOUT", "1800"))
INITIALIZE_CAPABILITY_OPTOUTS = [
    "codex/event/agent_message_content_delta",
    "codex/event/reasoning_content_delta",
    "codex/event/item_started",
    "codex/event/item_completed",
    "codex/event/task_started",
    "codex/event/task_complete",
]


class JsonRpcError(RuntimeError):
    def __init__(self, message: str, *, code: int | None = None, data: Any = None) -> None:
        super().__init__(message)
        self.code = code
        self.data = data


class JsonRpcSession:
    def __init__(self) -> None:
        self._proc: subprocess.Popen[str] | None = None
        self._reader: threading.Thread | None = None
        self._write_lock = threading.Lock()
        self._state_lock = threading.RLock()
        self._notification_condition = threading.Condition(self._state_lock)
        self._next_request_id = 1
        self._pending: dict[int, dict[str, Any]] = {}
        self._notifications: list[dict[str, Any]] = []
        self._transport_error: str | None = None
        self._closed = False

    def start(self) -> JsonRpcSession:
        if self._proc is not None:
            return self

        ensure_codex_home()
        env = os.environ.copy()
        env["CODEX_HOME"] = str(CODEX_HOME)
        env["HOME"] = str(CODEX_HOME)

        self._proc = subprocess.Popen(
            [CODEX_BIN, "app-server"],
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            text=True,
            bufsize=1,
            env=env,
        )
        self._reader = threading.Thread(target=self._reader_loop, daemon=True)
        self._reader.start()
        self.request(
            "initialize",
            {
                "protocolVersion": "1",
                "clientInfo": {
                    "name": "codex-broker-runtime",
                    "version": "0.1.0",
                },
                "capabilities": {
                    "optOutNotificationMethods": INITIALIZE_CAPABILITY_OPTOUTS,
                },
            },
            timeout=REQUEST_TIMEOUT,
        )
        return self

    def close(self) -> None:
        with self._state_lock:
            if self._closed:
                return
            self._closed = True
            proc = self._proc
            self._proc = None
            self._transport_error = self._transport_error or "Session closed."
            for waiter in self._pending.values():
                waiter["event"].set()
            self._notification_condition.notify_all()

        if proc is None:
            return

        if proc.stdin is not None and not proc.stdin.closed:
            proc.stdin.close()

        if proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=2)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait(timeout=2)

    def request(
        self,
        method: str,
        params: dict[str, Any] | None = None,
        *,
        timeout: float = REQUEST_TIMEOUT,
    ) -> Any:
        self.start()

        with self._state_lock:
            self._raise_if_broken()
            request_id = self._next_request_id
            self._next_request_id += 1
            waiter = {
                "event": threading.Event(),
                "response": None,
            }
            self._pending[request_id] = waiter

        self._send(
            {
                "jsonrpc": "2.0",
                "id": request_id,
                "method": method,
                "params": params,
            }
        )

        if not waiter["event"].wait(timeout):
            with self._state_lock:
                self._pending.pop(request_id, None)
            raise TimeoutError(f"{method} timed out after {timeout:.1f}s")

        response = waiter["response"]
        if not isinstance(response, dict):
            raise RuntimeError(f"{method} returned an invalid JSON-RPC response.")

        error = response.get("error")
        if isinstance(error, dict):
            raise JsonRpcError(
                str(error.get("message") or f"{method} failed."),
                code=error.get("code") if isinstance(error.get("code"), int) else None,
                data=error.get("data"),
            )

        return response.get("result")

    def wait_for_notification(
        self,
        predicate: Callable[[dict[str, Any]], bool],
        *,
        timeout: float | None = None,
    ) -> dict[str, Any]:
        deadline = None if timeout is None else time.monotonic() + timeout

        with self._notification_condition:
            while True:
                self._raise_if_broken()

                for index, notification in enumerate(self._notifications):
                    if predicate(notification):
                        return self._notifications.pop(index)

                if deadline is None:
                    self._notification_condition.wait()
                    continue

                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError("Timed out waiting for notification.")
                self._notification_condition.wait(timeout=remaining)

    def _send(self, payload: dict[str, Any]) -> None:
        proc = self._proc
        if proc is None or proc.stdin is None or proc.stdin.closed:
            raise RuntimeError("JSON-RPC session is not connected.")

        line = json.dumps(payload, separators=(",", ":")) + "\n"

        with self._write_lock:
            try:
                proc.stdin.write(line)
                proc.stdin.flush()
            except Exception as exc:  # pragma: no cover - transport failure
                self._set_transport_error(f"Failed writing to Codex app-server: {exc}")
                raise RuntimeError("Failed writing to Codex app-server.") from exc

    def _reader_loop(self) -> None:
        proc = self._proc
        if proc is None or proc.stdout is None:
            self._set_transport_error("Codex app-server process is unavailable.")
            return

        try:
            while True:
                line = proc.stdout.readline()
                if not line:
                    break

                message = json.loads(line)
                with self._notification_condition:
                    if isinstance(message, dict) and "id" in message:
                        request_id = message.get("id")
                        if isinstance(request_id, int):
                            waiter = self._pending.pop(request_id, None)
                            if waiter is not None:
                                waiter["response"] = message
                                waiter["event"].set()
                        continue

                    if isinstance(message, dict):
                        self._notifications.append(message)
                        self._notification_condition.notify_all()
        except Exception as exc:
            self._set_transport_error(f"Codex app-server transport failed: {exc}")
            return

        return_code = proc.poll()
        self._set_transport_error(
            f"Codex app-server exited unexpectedly with code {return_code}."
        )

    def _set_transport_error(self, message: str) -> None:
        with self._notification_condition:
            if self._transport_error is None:
                self._transport_error = message
            for waiter in self._pending.values():
                waiter["event"].set()
            self._notification_condition.notify_all()

    def _raise_if_broken(self) -> None:
        if self._transport_error:
            raise RuntimeError(self._transport_error)


def ensure_codex_home() -> None:
    CODEX_HOME.mkdir(parents=True, exist_ok=True)


def auth_file_path() -> Path:
    return CODEX_HOME / "auth.json"


def now_timestamp() -> int:
    return int(time.time())


@contextmanager
def app_server_session() -> Any:
    session = JsonRpcSession().start()
    try:
        yield session
    finally:
        session.close()


class RuntimeState:
    def __init__(self) -> None:
        self._lock = threading.RLock()
        self._login_sessions: dict[str, dict[str, Any]] = {}

    def start_login(self, auth_session_id: str) -> dict[str, Any]:
        with self._lock:
            existing = self._login_sessions.get(auth_session_id)
            if existing and existing["status"] in {"pending", "completed"}:
                return self._public_login_payload(existing)

            session = JsonRpcSession().start()
            response = session.request(
                "account/login/start",
                {"type": "chatgptDeviceCode"},
                timeout=REQUEST_TIMEOUT,
            )
            if not isinstance(response, dict) or "chatgptDeviceCode" != response.get("type"):
                session.close()
                raise RuntimeError("Device-code login did not return a device-code response.")

            login_id = optional_string(response.get("loginId"))
            verification_url = optional_string(response.get("verificationUrl"))
            user_code = optional_string(response.get("userCode"))
            if not login_id or not verification_url or not user_code:
                session.close()
                raise RuntimeError("Device-code login response was incomplete.")

            login_session = {
                "authSessionId": auth_session_id,
                "loginId": login_id,
                "verificationUrl": verification_url,
                "userCode": user_code,
                "status": "pending",
                "error": None,
                "authJson": None,
                "updatedAt": now_timestamp(),
                "_rpc": session,
            }
            self._login_sessions[auth_session_id] = login_session

            watcher = threading.Thread(
                target=self._watch_login_completion,
                args=(auth_session_id,),
                daemon=True,
            )
            watcher.start()

            return self._public_login_payload(login_session)

    def login_status(self, auth_session_id: str) -> tuple[dict[str, Any], int]:
        with self._lock:
            session = self._login_sessions.get(auth_session_id)

            if not session:
                return (
                    {
                        "authSessionId": auth_session_id,
                        "loginId": None,
                        "verificationUrl": None,
                        "userCode": None,
                        "status": "missing",
                        "error": "Login session was not found in the runtime.",
                    },
                    HTTPStatus.NOT_FOUND,
                )

            payload = self._public_login_payload(session)
            if session.get("authJson"):
                payload["authJson"] = session["authJson"]
            return payload, HTTPStatus.OK

    def account_snapshot(self) -> dict[str, Any]:
        with app_server_session() as session:
            account = session.request("account/read", {"refresh": True}, timeout=REQUEST_TIMEOUT)
            rate_limits = session.request("account/rateLimits/read", timeout=REQUEST_TIMEOUT)
            models = session.request("model/list", {"includeHidden": False}, timeout=REQUEST_TIMEOUT)

        return {
            "account": normalize_account_payload(account),
            "authJson": export_auth_json(),
            "defaultModel": select_default_model(models),
            "models": normalize_models_payload(models),
            "rateLimits": normalize_rate_limits_payload(rate_limits),
        }

    def generate_text(self, payload: dict[str, Any]) -> dict[str, Any]:
        input_text = require_string(payload.get("input"), "input")
        request_id = optional_string(payload.get("requestId")) or str(uuid.uuid4())
        model = optional_string(payload.get("model"))
        reasoning_effort = optional_string(payload.get("reasoningEffort"))
        system_instruction = optional_string(payload.get("systemInstruction"))
        response_format = payload.get("responseFormat")

        with app_server_session() as session:
            thread_params: dict[str, Any] = {
                "ephemeral": True,
            }
            if model:
                thread_params["model"] = model
            if system_instruction:
                thread_params["developerInstructions"] = system_instruction

            thread_started = session.request("thread/start", thread_params, timeout=REQUEST_TIMEOUT)
            thread_id = require_nested_string(thread_started, "thread", "id")

            turn_payload: dict[str, Any] = {
                "threadId": thread_id,
                "input": [{"type": "text", "text": input_text}],
            }
            if model:
                turn_payload["model"] = model
            if reasoning_effort:
                turn_payload["effort"] = reasoning_effort
            if (
                isinstance(response_format, dict)
                and "json_schema" == response_format.get("type")
                and isinstance(response_format.get("schema"), dict)
            ):
                turn_payload["outputSchema"] = response_format["schema"]

            started = session.request("turn/start", turn_payload, timeout=REQUEST_TIMEOUT)
            turn_id = require_nested_string(started, "turn", "id")
            output_parts: list[str] = []
            output_text: str | None = None
            finish_reason = "stop"

            while True:
                notification = session.wait_for_notification(
                    lambda message: notification_matches_turn(message, turn_id),
                    timeout=TURN_TIMEOUT,
                )
                method = notification.get("method")
                params = notification.get("params")

                if "item/agentMessage/delta" == method and isinstance(params, dict):
                    delta = params.get("delta")
                    if isinstance(delta, str):
                        output_parts.append(delta)
                    continue

                if "item/completed" == method and isinstance(params, dict):
                    item = params.get("item")
                    if isinstance(item, dict) and "agentMessage" == item.get("type"):
                        item_text = item.get("text")
                        if isinstance(item_text, str):
                            output_text = item_text
                    continue

                if "turn/completed" != method or not isinstance(params, dict):
                    continue

                turn = params.get("turn")
                if isinstance(turn, dict) and turn.get("error"):
                    raise RuntimeError(str(turn["error"]))
                break

            if not output_text:
                output_text = "".join(output_parts).strip()

            if not output_text:
                output_text = self._read_final_agent_message(session, thread_id, turn_id)

            rate_limits = session.request("account/rateLimits/read", timeout=REQUEST_TIMEOUT)
            account = session.request("account/read", {"refresh": False}, timeout=REQUEST_TIMEOUT)

        structured_output = None
        if response_format and output_text:
            try:
                structured_output = json.loads(output_text)
            except json.JSONDecodeError:
                structured_output = None

        return {
            "account": normalize_account_payload(account),
            "authJson": export_auth_json(),
            "finishReason": finish_reason,
            "model": model or None,
            "outputText": output_text,
            "rateLimits": normalize_rate_limits_payload(rate_limits),
            "requestId": request_id,
            "structuredOutput": structured_output,
            "usage": {
                "inputTokens": 0,
                "outputTokens": 0,
            },
        }

    def _read_final_agent_message(
        self,
        session: JsonRpcSession,
        thread_id: str,
        turn_id: str,
    ) -> str:
        response = session.request(
            "thread/read",
            {"threadId": thread_id, "includeTurns": True},
            timeout=REQUEST_TIMEOUT,
        )
        if not isinstance(response, dict):
            return ""

        thread = response.get("thread")
        if not isinstance(thread, dict):
            return ""

        turns = thread.get("turns")
        if not isinstance(turns, list):
            return ""

        for turn in turns:
            if not isinstance(turn, dict) or turn.get("id") != turn_id:
                continue
            items = turn.get("items")
            if not isinstance(items, list):
                continue
            for item in reversed(items):
                if not isinstance(item, dict) or "agentMessage" != item.get("type"):
                    continue
                item_text = item.get("text")
                if isinstance(item_text, str) and item_text.strip():
                    return item_text.strip()
        return ""

    def _watch_login_completion(self, auth_session_id: str) -> None:
        session_record: dict[str, Any] | None = None
        rpc_session: JsonRpcSession | None = None
        login_id = ""

        try:
            with self._lock:
                session_record = self._login_sessions.get(auth_session_id)
                if not session_record:
                    return
                rpc_session = session_record.get("_rpc")
                login_id = str(session_record.get("loginId") or "")

            if not rpc_session or not login_id:
                raise RuntimeError("Runtime login watcher could not access the app-server session.")

            notification = rpc_session.wait_for_notification(
                lambda message: is_login_completed_notification(message, login_id),
                timeout=LOGIN_TIMEOUT,
            )
            params = notification.get("params")
            if not isinstance(params, dict):
                raise RuntimeError("Login completion notification was malformed.")

            with self._lock:
                current = self._login_sessions.get(auth_session_id)
                if not current:
                    return
                current["updatedAt"] = now_timestamp()
                if params.get("success") is True:
                    current["status"] = "completed"
                    current["error"] = None
                    current["authJson"] = export_auth_json()
                else:
                    current["status"] = "error"
                    current["error"] = optional_string(params.get("error")) or "Device-code login failed."
        except Exception as exc:
            with self._lock:
                current = self._login_sessions.get(auth_session_id)
                if current:
                    current["status"] = "error"
                    current["error"] = str(exc)
                    current["updatedAt"] = now_timestamp()
        finally:
            if rpc_session is not None:
                rpc_session.close()
            with self._lock:
                current = self._login_sessions.get(auth_session_id)
                if current:
                    current.pop("_rpc", None)

    def _public_login_payload(self, session: dict[str, Any]) -> dict[str, Any]:
        return {
            "authSessionId": session["authSessionId"],
            "loginId": session["loginId"],
            "verificationUrl": session["verificationUrl"],
            "userCode": session["userCode"],
            "status": session["status"],
            "error": session["error"],
        }


def is_login_completed_notification(message: dict[str, Any], login_id: str) -> bool:
    if "account/login/completed" != message.get("method"):
        return False
    params = message.get("params")
    return isinstance(params, dict) and params.get("loginId") == login_id


def notification_matches_turn(message: dict[str, Any], turn_id: str) -> bool:
    method = message.get("method")
    params = message.get("params")
    if not isinstance(params, dict):
        return False

    direct_turn_id = params.get("turnId")
    if isinstance(direct_turn_id, str):
        return direct_turn_id == turn_id

    turn = params.get("turn")
    if isinstance(turn, dict) and isinstance(turn.get("id"), str):
        return turn["id"] == turn_id

    return "turn/completed" == method


def optional_string(value: Any) -> str | None:
    return value.strip() if isinstance(value, str) and value.strip() else None


def require_string(value: Any, field_name: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise RuntimeError(f"{field_name} is required.")
    return value.strip()


def require_nested_string(payload: Any, *path: str) -> str:
    current = payload
    for segment in path:
        if not isinstance(current, dict):
            raise RuntimeError(f"Missing {'.'.join(path)} in response payload.")
        current = current.get(segment)
    if not isinstance(current, str) or not current:
        raise RuntimeError(f"Missing {'.'.join(path)} in response payload.")
    return current


def export_auth_json() -> str | None:
    path = auth_file_path()
    if not path.is_file():
        return None
    return path.read_text(encoding="utf-8")


def import_auth_json(auth_json: str) -> None:
    ensure_codex_home()
    auth_file_path().write_text(auth_json, encoding="utf-8")


def clear_auth_json() -> None:
    path = auth_file_path()
    if path.exists():
        path.unlink()


def normalize_account_payload(payload: Any) -> dict[str, Any]:
    if not isinstance(payload, dict):
        return {
            "authMode": None,
            "email": None,
            "planType": None,
            "type": None,
        }

    account = payload.get("account")
    if not isinstance(account, dict):
        return {
            "authMode": None,
            "email": None,
            "planType": None,
            "type": None,
        }

    account_type = optional_string(account.get("type"))
    email = optional_string(account.get("email"))
    plan_type = optional_string(account.get("planType"))

    return {
        "authMode": account_type,
        "email": email,
        "planType": plan_type,
        "type": account_type,
    }


def normalize_rate_limits_payload(payload: Any) -> dict[str, Any]:
    if not isinstance(payload, dict):
        return {}
    rate_limits = payload.get("rateLimits")
    return rate_limits if isinstance(rate_limits, dict) else payload


def normalize_models_payload(payload: Any) -> list[dict[str, Any]]:
    if not isinstance(payload, dict):
        return []
    data = payload.get("data")
    return data if isinstance(data, list) else []


def select_default_model(payload: Any) -> str | None:
    models = normalize_models_payload(payload)
    for model in models:
        if isinstance(model, dict) and model.get("isDefault") and isinstance(model.get("model"), str):
            return model["model"]
    if models and isinstance(models[0], dict) and isinstance(models[0].get("model"), str):
        return models[0]["model"]
    return None


STATE = RuntimeState()


class RuntimeHandler(BaseHTTPRequestHandler):
    server_version = "CodexBrokerRuntime/0.1"

    def do_GET(self) -> None:  # noqa: N802
        self._dispatch()

    def do_POST(self) -> None:  # noqa: N802
        self._dispatch()

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A003
        return

    def _dispatch(self) -> None:
        try:
            parsed = urlparse(self.path)

            if "GET" == self.command and parsed.path in {"/ping", "/healthz"}:
                self._json_response({"ok": True, "authStored": auth_file_path().is_file()})
                return

            if "POST" == self.command and "/session/bootstrap" == parsed.path:
                payload = self._read_json_body()
                import_auth_json(require_string(payload.get("authJson"), "authJson"))
                self._json_response({"ok": True})
                return

            if "POST" == self.command and "/session/clear" == parsed.path:
                clear_auth_json()
                self._json_response({"ok": True})
                return

            if "POST" == self.command and "/login/start" == parsed.path:
                payload = self._read_json_body()
                auth_session_id = require_string(payload.get("authSessionId"), "authSessionId")
                self._json_response(STATE.start_login(auth_session_id))
                return

            if "GET" == self.command and "/login/status" == parsed.path:
                auth_session_id = require_string(
                    parse_qs(parsed.query).get("authSessionId", [None])[0],
                    "authSessionId",
                )
                payload, status = STATE.login_status(auth_session_id)
                self._json_response(payload, status=status)
                return

            if "GET" == self.command and "/account/snapshot" == parsed.path:
                self._json_response(STATE.account_snapshot())
                return

            if "POST" == self.command and "/responses/text" == parsed.path:
                self._json_response(STATE.generate_text(self._read_json_body()))
                return

            self._json_error("not_found", "Runtime route not found.", HTTPStatus.NOT_FOUND)
        except Exception as exc:
            self._json_error(
                "runtime_error",
                f"{exc}\n{traceback.format_exc(limit=5)}",
                HTTPStatus.BAD_GATEWAY,
            )

    def _read_json_body(self) -> dict[str, Any]:
        length = int(self.headers.get("content-length", "0") or "0")
        raw = self.rfile.read(length) if length else b""
        if not raw:
            return {}
        payload = json.loads(raw.decode("utf-8"))
        if not isinstance(payload, dict):
            raise RuntimeError("Request body must be a JSON object.")
        return payload

    def _json_response(self, payload: dict[str, Any], status: int = HTTPStatus.OK) -> None:
        encoded = json.dumps(payload, indent=2).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def _json_error(self, code: str, message: str, status: int) -> None:
        self._json_response({"error": {"code": code, "message": message}}, status=status)


def main() -> None:
    ensure_codex_home()
    server = ThreadingHTTPServer(("0.0.0.0", PORT), RuntimeHandler)
    server.serve_forever()


if __name__ == "__main__":
    main()
