"""
Database models for CISHub system.
Defines all database entities with proper relationships and constraints.
"""
from datetime import datetime, timezone
from enum import Enum
from typing import Optional, Dict, Any
from sqlalchemy import (
    Column, Integer, String, DateTime, Boolean, Text, JSON, 
    ForeignKey, Index, UniqueConstraint, Float
)
from sqlalchemy.orm import declarative_base, relationship
from sqlalchemy.dialects.postgresql import UUID
import uuid

Base = declarative_base()


class TaskStatus(str, Enum):
    """Task processing status enumeration."""
    PENDING = "pending"
    PROCESSING = "processing"
    COMPLETED = "completed"
    FAILED = "failed"
    RETRYING = "retrying"
    CANCELLED = "cancelled"


class QueuePriority(str, Enum):
    """Queue priority levels."""
    LOW = "low"
    NORMAL = "normal"
    HIGH = "high"
    CRITICAL = "critical"


class AlarmSeverity(str, Enum):
    """Alarm severity levels."""
    INFO = "info"
    WARNING = "warning"
    ERROR = "error"
    CRITICAL = "critical"


class Queue(Base):
    """Queue model for managing different processing queues."""
    __tablename__ = "queues"
    
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(100), unique=True, nullable=False, index=True)
    description = Column(Text)
    priority = Column(String(20), default=QueuePriority.NORMAL.value)
    is_active = Column(Boolean, default=True)
    max_workers = Column(Integer, default=4)
    retry_limit = Column(Integer, default=3)
    timeout_seconds = Column(Integer, default=300)
    
    # Timestamps
    created_at = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc))
    updated_at = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc), onupdate=lambda: datetime.now(timezone.utc))
    
    # Relationships
    tasks = relationship("Task", back_populates="queue", cascade="all, delete-orphan")
    metrics = relationship("QueueMetrics", back_populates="queue", cascade="all, delete-orphan")
    
    def __repr__(self):
        return f"<Queue(name='{self.name}', priority='{self.priority}', active={self.is_active})>"


class Task(Base):
    """Task model for individual processing tasks."""
    __tablename__ = "tasks"
    
    id = Column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4, index=True)
    queue_id = Column(Integer, ForeignKey("queues.id"), nullable=False)
    
    # Task details
    task_type = Column(String(100), nullable=False, index=True)
    task_name = Column(String(200), nullable=False)
    payload = Column(JSON)
    result = Column(JSON)
    
    # Status tracking
    status = Column(String(20), default=TaskStatus.PENDING.value, index=True)
    priority = Column(String(20), default=QueuePriority.NORMAL.value)
    retry_count = Column(Integer, default=0)
    max_retries = Column(Integer, default=3)
    
    # Error handling
    error_message = Column(Text)
    error_traceback = Column(Text)
    last_error_at = Column(DateTime(timezone=True))
    
    # Timing
    created_at = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc), index=True)
    started_at = Column(DateTime(timezone=True))
    completed_at = Column(DateTime(timezone=True))
    scheduled_at = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc))
    timeout_at = Column(DateTime(timezone=True))
    
    # Metadata
    correlation_id = Column(String(100), index=True)
    worker_id = Column(String(100))
    tags = Column(JSON)
    
    # Relationships
    queue = relationship("Queue", back_populates="tasks")
    
    # Indexes
    __table_args__ = (
        Index("idx_task_status_created", "status", "created_at"),
        Index("idx_task_queue_status", "queue_id", "status"),
        Index("idx_task_correlation", "correlation_id"),
        Index("idx_task_scheduled", "scheduled_at"),
    )
    
    @property
    def duration_seconds(self) -> Optional[float]:
        """Calculate task duration in seconds."""
        if self.started_at and self.completed_at:
            return (self.completed_at - self.started_at).total_seconds()
        return None
    
    @property
    def is_overdue(self) -> bool:
        """Check if task is overdue based on timeout."""
        if self.timeout_at and datetime.now(timezone.utc) > self.timeout_at:
            return True
        return False
    
    def __repr__(self):
        return f"<Task(id='{self.id}', type='{self.task_type}', status='{self.status}')>"


class QueueMetrics(Base):
    """Queue metrics for monitoring and alerting."""
    __tablename__ = "queue_metrics"
    
    id = Column(Integer, primary_key=True, index=True)
    queue_id = Column(Integer, ForeignKey("queues.id"), nullable=False)
    
    # Metrics data
    timestamp = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc), index=True)
    pending_count = Column(Integer, default=0)
    processing_count = Column(Integer, default=0)
    completed_count = Column(Integer, default=0)
    failed_count = Column(Integer, default=0)
    
    # Performance metrics
    avg_processing_time = Column(Float)
    max_processing_time = Column(Float)
    min_processing_time = Column(Float)
    
    # System metrics
    cpu_usage = Column(Float)
    memory_usage = Column(Float)
    disk_usage = Column(Float)
    
    # Error rates
    error_rate = Column(Float, default=0.0)
    success_rate = Column(Float, default=100.0)
    
    # Relationships
    queue = relationship("Queue", back_populates="metrics")
    
    # Indexes
    __table_args__ = (
        Index("idx_metrics_queue_timestamp", "queue_id", "timestamp"),
        Index("idx_metrics_timestamp", "timestamp"),
    )
    
    def __repr__(self):
        return f"<QueueMetrics(queue_id={self.queue_id}, timestamp='{self.timestamp}')>"


class SystemAlarm(Base):
    """System alarms for critical issues."""
    __tablename__ = "system_alarms"
    
    id = Column(Integer, primary_key=True, index=True)
    
    # Alarm details
    alarm_type = Column(String(100), nullable=False, index=True)
    severity = Column(String(20), default=AlarmSeverity.WARNING.value, index=True)
    title = Column(String(200), nullable=False)
    description = Column(Text)
    
    # Context
    queue_id = Column(Integer, ForeignKey("queues.id"))
    task_id = Column(UUID(as_uuid=True), ForeignKey("tasks.id"))
    component = Column(String(100))
    
    # Status
    is_active = Column(Boolean, default=True, index=True)
    acknowledged = Column(Boolean, default=False)
    acknowledged_by = Column(String(100))
    acknowledged_at = Column(DateTime(timezone=True))
    
    # Timing
    triggered_at = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc), index=True)
    resolved_at = Column(DateTime(timezone=True))
    last_occurrence = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc))
    
    # Metadata
    occurrence_count = Column(Integer, default=1)
    context_data = Column(JSON)
    tags = Column(JSON)
    
    # Indexes
    __table_args__ = (
        Index("idx_alarm_active_severity", "is_active", "severity"),
        Index("idx_alarm_type_triggered", "alarm_type", "triggered_at"),
    )
    
    @property
    def duration_minutes(self) -> Optional[float]:
        """Calculate alarm duration in minutes."""
        end_time = self.resolved_at or datetime.now(timezone.utc)
        return (end_time - self.triggered_at).total_seconds() / 60
    
    def __repr__(self):
        return f"<SystemAlarm(type='{self.alarm_type}', severity='{self.severity}', active={self.is_active})>"


class SystemStatus(Base):
    """System-wide status tracking."""
    __tablename__ = "system_status"
    
    id = Column(Integer, primary_key=True, index=True)
    
    # System state
    is_operational = Column(Boolean, default=True)
    is_maintenance_mode = Column(Boolean, default=False)
    shutdown_requested = Column(Boolean, default=False)
    shutdown_reason = Column(Text)
    
    # Health indicators
    overall_health = Column(String(20), default="healthy")  # healthy, degraded, critical
    queue_health = Column(String(20), default="healthy")
    database_health = Column(String(20), default="healthy")
    redis_health = Column(String(20), default="healthy")
    
    # Metrics
    total_queues = Column(Integer, default=0)
    active_queues = Column(Integer, default=0)
    total_tasks_processed = Column(Integer, default=0)
    current_active_tasks = Column(Integer, default=0)
    
    # Timestamps
    last_updated = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc), index=True)
    last_health_check = Column(DateTime(timezone=True))
    uptime_started = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc))
    
    # Additional data
    version = Column(String(50))
    environment = Column(String(50))
    metadata = Column(JSON)
    
    def __repr__(self):
        return f"<SystemStatus(operational={self.is_operational}, health='{self.overall_health}')>"


class AuditLog(Base):
    """Audit log for tracking system operations."""
    __tablename__ = "audit_logs"
    
    id = Column(Integer, primary_key=True, index=True)
    
    # Event details
    event_type = Column(String(100), nullable=False, index=True)
    entity_type = Column(String(100))
    entity_id = Column(String(100))
    
    # Action details
    action = Column(String(100), nullable=False)
    description = Column(Text)
    old_values = Column(JSON)
    new_values = Column(JSON)
    
    # Context
    user_id = Column(String(100))
    session_id = Column(String(100))
    ip_address = Column(String(45))
    user_agent = Column(Text)
    
    # Timing
    timestamp = Column(DateTime(timezone=True), default=lambda: datetime.now(timezone.utc), index=True)
    
    # Additional data
    metadata = Column(JSON)
    tags = Column(JSON)
    
    # Indexes
    __table_args__ = (
        Index("idx_audit_event_timestamp", "event_type", "timestamp"),
        Index("idx_audit_entity", "entity_type", "entity_id"),
    )
    
    def __repr__(self):
        return f"<AuditLog(event='{self.event_type}', action='{self.action}', timestamp='{self.timestamp}')>"