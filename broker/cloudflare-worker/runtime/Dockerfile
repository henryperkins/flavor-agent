FROM node:22-bookworm-slim AS node_runtime

FROM python:3.12-slim-bookworm

ARG CODEX_NPM_VERSION=0.118.0

COPY --from=node_runtime /usr/local/ /usr/local/

ENV PYTHONDONTWRITEBYTECODE=1 \
	PYTHONUNBUFFERED=1 \
	CODEX_HOME=/var/lib/codex \
	CODEX_BIN=/usr/local/bin/codex \
	CODEX_RUNTIME_PORT=8080

RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates \
	&& rm -rf /var/lib/apt/lists/*

RUN npm install -g "@openai/codex@${CODEX_NPM_VERSION}"

RUN mkdir -p /app /var/lib/codex

COPY app/main.py /app/main.py

EXPOSE 8080

CMD ["python", "/app/main.py"]
