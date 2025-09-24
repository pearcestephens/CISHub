# Multi-stage build for CISHub
FROM python:3.11-slim as base

# Set environment variables
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# Install system dependencies
RUN apt-get update && apt-get install -y \
    gcc \
    g++ \
    libpq-dev \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Create app user
RUN groupadd --gid 1000 appuser && \
    useradd --uid 1000 --gid appuser --shell /bin/bash --create-home appuser

# Set work directory
WORKDIR /app

# Copy requirements first for better caching
COPY requirements.txt .

# Install Python dependencies
RUN pip install --no-deps -r requirements.txt

# Copy application code
COPY . .

# Change ownership to app user
RUN chown -R appuser:appuser /app

# Switch to app user
USER appuser

# Expose ports
EXPOSE 8000 8001

# Health check
HEALTHCHECK --interval=30s --timeout=30s --start-period=5s --retries=3 \
    CMD python main.py health || exit 1

#############################################
# Production API Server
#############################################
FROM base as api-server

CMD ["python", "main.py", "api"]

#############################################
# Production Dashboard Server
#############################################
FROM base as dashboard-server

CMD ["python", "main.py", "dashboard"]

#############################################
# Production Worker
#############################################
FROM base as worker

# Install additional worker dependencies if needed
RUN pip install flower

CMD ["python", "main.py", "worker"]

#############################################
# Production Monitor
#############################################
FROM base as monitor

CMD ["python", "main.py", "monitor"]

#############################################
# Development Environment
#############################################
FROM base as development

USER root

# Install development dependencies
RUN pip install pytest pytest-asyncio pytest-cov black flake8 mypy

# Install additional development tools
RUN apt-get update && apt-get install -y \
    vim \
    git \
    htop \
    && rm -rf /var/lib/apt/lists/*

USER appuser

# Default command for development
CMD ["python", "main.py", "api", "--debug"]