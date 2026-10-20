FROM python:3.12-slim

ENV XDG_CACHE_HOME=/opt/cbm-native

RUN pip install --no-cache-dir codebase-memory-mcp==0.12.1 \
    && codebase-memory-mcp --version

ENTRYPOINT ["/opt/cbm-native/codebase-memory-mcp/0.12.1/codebase-memory-mcp"]
