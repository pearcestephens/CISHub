"""
Structured logging configuration for CISHub system.
Provides consistent, structured logging across all components.
"""
import sys
import logging
from typing import Dict, Any
import structlog
from structlog.stdlib import LoggerFactory
from cishub.config.settings import settings


def configure_logging() -> None:
    """Configure structured logging for the application."""
    
    # Configure structlog
    structlog.configure(
        processors=[
            structlog.contextvars.merge_contextvars,
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.processors.StackInfoRenderer(),
            structlog.dev.set_exc_info if settings.debug else structlog.processors.format_exc_info,
            structlog.processors.JSONRenderer() if not settings.debug else structlog.dev.ConsoleRenderer()
        ],
        wrapper_class=structlog.make_filtering_bound_logger(
            logging.getLevelName(settings.log_level.upper())
        ),
        logger_factory=LoggerFactory(),
        cache_logger_on_first_use=True,
    )
    
    # Configure standard library logging
    logging.basicConfig(
        format="%(message)s",
        stream=sys.stdout,
        level=getattr(logging, settings.log_level.upper())
    )
    
    # Set specific loggers
    logging.getLogger("uvicorn").setLevel(logging.INFO)
    logging.getLogger("uvicorn.access").setLevel(logging.WARNING)
    logging.getLogger("sqlalchemy.engine").setLevel(logging.WARNING)
    logging.getLogger("celery").setLevel(logging.INFO)
    logging.getLogger("redis").setLevel(logging.WARNING)


def get_logger(name: str) -> structlog.BoundLogger:
    """Get a configured logger for a module."""
    return structlog.get_logger(name)


class LoggerMixin:
    """Mixin class to add structured logging to any class."""
    
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.logger = get_logger(self.__class__.__module__)
    
    def log_operation(self, operation: str, **context: Any) -> None:
        """Log an operation with context."""
        self.logger.info(
            "operation_executed",
            operation=operation,
            class_name=self.__class__.__name__,
            **context
        )
    
    def log_error(self, error: Exception, operation: str = None, **context: Any) -> None:
        """Log an error with context."""
        self.logger.error(
            "error_occurred",
            error=str(error),
            error_type=type(error).__name__,
            operation=operation,
            class_name=self.__class__.__name__,
            **context,
            exc_info=True
        )
    
    def log_warning(self, message: str, **context: Any) -> None:
        """Log a warning with context."""
        self.logger.warning(
            message,
            class_name=self.__class__.__name__,
            **context
        )
    
    def log_debug(self, message: str, **context: Any) -> None:
        """Log debug information with context."""
        self.logger.debug(
            message,
            class_name=self.__class__.__name__,
            **context
        )


def log_function_call(func):
    """Decorator to log function calls with parameters and results."""
    def wrapper(*args, **kwargs):
        logger = get_logger(func.__module__)
        
        # Log function entry
        logger.debug(
            "function_called",
            function=func.__name__,
            args_count=len(args),
            kwargs_keys=list(kwargs.keys())
        )
        
        try:
            result = func(*args, **kwargs)
            logger.debug(
                "function_completed",
                function=func.__name__,
                success=True
            )
            return result
        except Exception as e:
            logger.error(
                "function_failed",
                function=func.__name__,
                error=str(e),
                error_type=type(e).__name__,
                exc_info=True
            )
            raise
    
    return wrapper


def log_performance(operation: str):
    """Decorator to log performance metrics for operations."""
    def decorator(func):
        def wrapper(*args, **kwargs):
            import time
            logger = get_logger(func.__module__)
            
            start_time = time.time()
            logger.debug(
                "performance_start",
                operation=operation,
                function=func.__name__
            )
            
            try:
                result = func(*args, **kwargs)
                duration = time.time() - start_time
                logger.info(
                    "performance_completed",
                    operation=operation,
                    function=func.__name__,
                    duration_seconds=duration,
                    success=True
                )
                return result
            except Exception as e:
                duration = time.time() - start_time
                logger.error(
                    "performance_failed",
                    operation=operation,
                    function=func.__name__,
                    duration_seconds=duration,
                    error=str(e),
                    success=False,
                    exc_info=True
                )
                raise
        
        return wrapper
    return decorator