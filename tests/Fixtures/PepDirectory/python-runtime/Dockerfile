# Test-only runtime; build with this directory as the entire context.
FROM python:3.12-slim@sha256:a6e34c598f2467ed0e9a8d349809fcd8b5c603269512df273a0bb1784edc11b1
ENV PYTHONDONTWRITEBYTECODE=1 PIP_DISABLE_PIP_VERSION_CHECK=1
RUN python -m pip --isolated install --no-cache-dir --only-binary=:all: --no-deps --index-url https://pypi.org/simple psycopg2-binary==2.9.12
WORKDIR /work
