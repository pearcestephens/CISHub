"""
Configuration management for CISHub system.
Centralizes all configuration with proper validation and environment variable support.
"""
import os
from typing import Optional, List
from pydantic import BaseSettings, validator
from dotenv import load_dotenv

load_dotenv()


class DatabaseSettings(BaseSettings):
    """Database configuration settings."""
    url: str = os.getenv("DATABASE_URL", "postgresql://localhost:5432/cishub")
    test_url: str = os.getenv("TEST_DATABASE_URL", "postgresql://localhost:5432/cishub_test")
    pool_size: int = 10
    max_overflow: int = 20
    pool_timeout: int = 30
    pool_recycle: int = 3600


class RedisSettings(BaseSettings):
    """Redis configuration settings."""
    url: str = os.getenv("REDIS_URL", "redis://localhost:6379/0")
    test_url: str = os.getenv("REDIS_TEST_URL", "redis://localhost:6379/1")
    max_connections: int = 50
    retry_on_timeout: bool = True
    socket_keepalive: bool = True


class QueueSettings(BaseSettings):
    """Queue and Celery configuration settings."""
    broker_url: str = os.getenv("CELERY_BROKER_URL", "redis://localhost:6379/2")
    result_backend: str = os.getenv("CELERY_RESULT_BACKEND", "redis://localhost:6379/3")
    
    # Health monitoring settings
    health_check_interval: int = int(os.getenv("QUEUE_HEALTH_CHECK_INTERVAL", "30"))
    processing_timeout: int = int(os.getenv("QUEUE_PROCESSING_TIMEOUT", "300"))
    backup_threshold: int = int(os.getenv("QUEUE_BACKUP_THRESHOLD", "100"))
    error_threshold: int = int(os.getenv("QUEUE_ERROR_THRESHOLD", "10"))
    
    # Worker settings
    worker_concurrency: int = 4
    worker_prefetch_multiplier: int = 1
    task_soft_time_limit: int = 300
    task_time_limit: int = 600
    
    # Retry settings
    default_retry_delay: int = 60
    max_retries: int = 3
    retry_backoff: bool = True
    retry_jitter: bool = True


class MonitoringSettings(BaseSettings):
    """Monitoring and alerting configuration."""
    alarm_cooldown_period: int = int(os.getenv("ALARM_COOLDOWN_PERIOD", "300"))
    metrics_port: int = 9090
    health_check_port: int = 8080
    
    # Thresholds
    cpu_threshold: float = 80.0
    memory_threshold: float = 85.0
    disk_threshold: float = 90.0
    response_time_threshold: float = 2.0


class AlertSettings(BaseSettings):
    """Alert notification configuration."""
    slack_webhook_url: Optional[str] = os.getenv("SLACK_WEBHOOK_URL")
    email_smtp_host: str = os.getenv("EMAIL_SMTP_HOST", "localhost")
    email_smtp_port: int = int(os.getenv("EMAIL_SMTP_PORT", "587"))
    email_username: Optional[str] = os.getenv("EMAIL_USERNAME")
    email_password: Optional[str] = os.getenv("EMAIL_PASSWORD")
    email_recipients: List[str] = os.getenv("ALERT_EMAIL_RECIPIENTS", "").split(",")
    
    @validator("email_recipients", pre=True)
    def parse_email_recipients(cls, v):
        if isinstance(v, str):
            return [email.strip() for email in v.split(",") if email.strip()]
        return v


class APISettings(BaseSettings):
    """API configuration settings."""
    host: str = os.getenv("API_HOST", "0.0.0.0")
    port: int = int(os.getenv("API_PORT", "8001"))
    secret_key: str = os.getenv("SECRET_KEY", "dev-secret-key")
    shutdown_token: str = os.getenv("SHUTDOWN_ENDPOINT_TOKEN", "emergency-shutdown-token")
    
    # CORS settings
    allow_origins: List[str] = ["*"]
    allow_credentials: bool = True
    allow_methods: List[str] = ["*"]
    allow_headers: List[str] = ["*"]


class DashboardSettings(BaseSettings):
    """Dashboard configuration settings."""
    host: str = os.getenv("DASHBOARD_HOST", "0.0.0.0")
    port: int = int(os.getenv("DASHBOARD_PORT", "8000"))
    
    # WebSocket settings
    websocket_ping_interval: int = 20
    websocket_ping_timeout: int = 10


class ExternalIntegrationSettings(BaseSettings):
    """External service integration settings."""
    lightspeed_api_url: str = os.getenv("LIGHTSPEED_API_URL", "https://api.lightspeedapp.com")
    lightspeed_api_key: Optional[str] = os.getenv("LIGHTSPEED_API_KEY")
    webhook_secret: Optional[str] = os.getenv("WEBHOOK_SECRET")
    
    # Rate limiting
    api_rate_limit: int = 100  # requests per minute
    api_burst_limit: int = 10  # burst requests


class Settings(BaseSettings):
    """Main settings class combining all configuration sections."""
    debug: bool = os.getenv("DEBUG", "False").lower() == "true"
    log_level: str = os.getenv("LOG_LEVEL", "INFO")
    environment: str = os.getenv("ENVIRONMENT", "development")
    
    # Component settings
    database: DatabaseSettings = DatabaseSettings()
    redis: RedisSettings = RedisSettings()
    queue: QueueSettings = QueueSettings()
    monitoring: MonitoringSettings = MonitoringSettings()
    alerts: AlertSettings = AlertSettings()
    api: APISettings = APISettings()
    dashboard: DashboardSettings = DashboardSettings()
    external: ExternalIntegrationSettings = ExternalIntegrationSettings()
    
    class Config:
        env_file = ".env"
        case_sensitive = False


# Global settings instance
settings = Settings()