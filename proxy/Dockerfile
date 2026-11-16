# pluck-proxy: contract v1 service. Chromium comes from Playwright's own install (matching version).
FROM python:3.13-slim

ENV PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

WORKDIR /app
COPY pyproject.toml ./
COPY pluck_proxy ./pluck_proxy
RUN pip install . \
 && playwright install --with-deps chromium \
 && useradd --system --create-home pluck \
 && chmod -R a+rX /ms-playwright

USER pluck
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD python -c "import urllib.request as u; u.urlopen('http://127.0.0.1:8080/healthz', timeout=4)"

# One worker: politeness, cooldown and cache state are in-process and must be shared.
CMD ["uvicorn", "pluck_proxy.app:app_from_env", "--factory", "--host", "0.0.0.0", "--port", "8080", "--workers", "1"]
