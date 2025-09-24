"""
Pydantic schemas for API request/response validation.
Provides comprehensive data validation and serialization.
"""
from datetime import datetime
from typing import Dict, List, Optional, Any, Union
from enum import Enum
from pydantic import BaseModel, Field, validator
from cishub.core.models import TaskStatus, QueuePriority, AlarmSeverity


class APIResponseStatus(str, Enum):
    """Standard API response status values."""
    SUCCESS = "success"
    ERROR = "error"
    WARNING = "warning"
    PENDING = "pending"


class PaginationRequest(BaseModel):
    """Request model for pagination."""
    page: int = Field(1, ge=1, description="Page number (1-based)")
    page_size: int = Field(50, ge=1, le=1000, description="Items per page")
    
    @property
    def offset(self) -> int:
        """Calculate offset for database queries."""
        return (self.page - 1) * self.page_size


class PaginationResponse(BaseModel):
    """Response model for paginated data."""
    page: int
    page_size: int
    total_items: int
    total_pages: int
    has_next: bool
    has_previous: bool


class BaseResponse(BaseModel):
    """Base response model."""
    status: APIResponseStatus
    message: Optional[str] = None
    timestamp: datetime = Field(default_factory=datetime.utcnow)


class ErrorResponse(BaseResponse):
    """Error response model."""
    status: APIResponseStatus = APIResponseStatus.ERROR
    error_code: Optional[str] = None
    error_details: Optional[Dict[str, Any]] = None


# Task-related schemas

class TaskSubmissionRequest(BaseModel):
    """Enhanced task submission request."""
    task_type: str = Field(..., min_length=1, max_length=100, description="Type of task to process")
    task_name: str = Field(..., min_length=1, max_length=200, description="Human-readable task name")
    payload: Dict[str, Any] = Field(..., description="Task payload data")
    priority: QueuePriority = Field(QueuePriority.NORMAL, description="Task priority")
    queue_name: str = Field("default", min_length=1, max_length=100, description="Queue name")
    correlation_id: Optional[str] = Field(None, max_length=100, description="Correlation ID for tracking")
    scheduled_at: Optional[datetime] = Field(None, description="Schedule task for future execution")
    timeout_seconds: Optional[int] = Field(None, ge=1, le=86400, description="Task timeout in seconds")
    tags: Optional[Dict[str, str]] = Field(None, description="Additional tags")
    retry_limit: Optional[int] = Field(None, ge=0, le=10, description="Maximum retry attempts")
    
    @validator('scheduled_at')
    def validate_scheduled_at(cls, v):
        if v and v <= datetime.utcnow():
            raise ValueError('scheduled_at must be in the future')
        return v
    
    @validator('tags')
    def validate_tags(cls, v):
        if v:
            for key, value in v.items():
                if not isinstance(key, str) or not isinstance(value, str):
                    raise ValueError('All tags must be string key-value pairs')
                if len(key) > 50 or len(value) > 200:
                    raise ValueError('Tag keys must be ≤50 chars, values ≤200 chars')
        return v


class TaskSubmissionResponse(BaseResponse):
    """Response for task submission."""
    status: APIResponseStatus = APIResponseStatus.SUCCESS
    task_id: str
    queue_name: str
    estimated_start_time: Optional[datetime] = None


class TaskStatusResponse(BaseModel):
    """Detailed task status response."""
    id: str
    task_type: str
    task_name: str
    status: TaskStatus
    priority: QueuePriority
    queue_name: str
    retry_count: int
    max_retries: int
    error_message: Optional[str]
    error_traceback: Optional[str]
    created_at: Optional[datetime]
    started_at: Optional[datetime]
    completed_at: Optional[datetime]
    scheduled_at: Optional[datetime]
    timeout_at: Optional[datetime]
    duration_seconds: Optional[float]
    is_overdue: bool
    correlation_id: Optional[str]
    tags: Optional[Dict[str, str]]
    result: Optional[Dict[str, Any]]
    worker_id: Optional[str]
    
    class Config:
        use_enum_values = True


class TaskListRequest(PaginationRequest):
    """Request for listing tasks with filters."""
    status: Optional[TaskStatus] = Field(None, description="Filter by task status")
    task_type: Optional[str] = Field(None, description="Filter by task type")
    queue_name: Optional[str] = Field(None, description="Filter by queue name")
    correlation_id: Optional[str] = Field(None, description="Filter by correlation ID")
    created_after: Optional[datetime] = Field(None, description="Filter tasks created after this date")
    created_before: Optional[datetime] = Field(None, description="Filter tasks created before this date")


class TaskListResponse(BaseModel):
    """Response for task listing."""
    tasks: List[TaskStatusResponse]
    pagination: PaginationResponse


# Queue-related schemas

class QueueCreateRequest(BaseModel):
    """Request to create a new queue."""
    name: str = Field(..., min_length=1, max_length=100, regex=r'^[a-zA-Z0-9_-]+$')
    description: Optional[str] = Field(None, max_length=500)
    priority: QueuePriority = Field(QueuePriority.NORMAL)
    max_workers: int = Field(4, ge=1, le=50)
    retry_limit: int = Field(3, ge=0, le=10)
    timeout_seconds: int = Field(300, ge=30, le=3600)


class QueueUpdateRequest(BaseModel):
    """Request to update queue settings."""
    description: Optional[str] = Field(None, max_length=500)
    priority: Optional[QueuePriority] = None
    is_active: Optional[bool] = None
    max_workers: Optional[int] = Field(None, ge=1, le=50)
    retry_limit: Optional[int] = Field(None, ge=0, le=10)
    timeout_seconds: Optional[int] = Field(None, ge=30, le=3600)


class QueueResponse(BaseModel):
    """Queue information response."""
    id: int
    name: str
    description: Optional[str]
    priority: QueuePriority
    is_active: bool
    max_workers: int
    retry_limit: int
    timeout_seconds: int
    created_at: datetime
    updated_at: datetime
    
    class Config:
        use_enum_values = True


class QueueHealthResponse(BaseModel):
    """Queue health status response."""
    queue_name: str
    is_healthy: bool
    pending_count: int
    processing_count: int
    completed_count: int
    failed_count: int
    error_rate: float
    success_rate: float
    avg_processing_time: float
    max_processing_time: Optional[float]
    min_processing_time: Optional[float]
    last_processed_at: Optional[datetime]
    issues: List[str]
    recommendations: List[str] = []


class QueueMetricsRequest(BaseModel):
    """Request for queue metrics."""
    queue_name: Optional[str] = None
    start_time: Optional[datetime] = None
    end_time: Optional[datetime] = None
    granularity: str = Field("hour", regex=r'^(minute|hour|day)$')


class QueueMetricsResponse(BaseModel):
    """Queue metrics response."""
    queue_name: str
    time_range: Dict[str, datetime]
    metrics: List[Dict[str, Any]]
    summary: Dict[str, Any]


# Health and monitoring schemas

class ComponentHealthResponse(BaseModel):
    """Individual component health response."""
    name: str
    status: str
    response_time_ms: Optional[float]
    last_check: Optional[datetime]
    error_message: Optional[str]
    details: Dict[str, Any] = {}


class SystemHealthResponse(BaseModel):
    """Comprehensive system health response."""
    overall_status: str
    timestamp: datetime
    uptime_seconds: float
    components: List[ComponentHealthResponse]
    summary: Dict[str, int]
    system_metrics: Dict[str, Any]
    recommendations: List[str] = []


class HealthHistoryRequest(BaseModel):
    """Request for health history."""
    component: Optional[str] = None
    start_time: Optional[datetime] = None
    end_time: Optional[datetime] = None
    granularity: str = Field("hour", regex=r'^(minute|hour|day)$')


# Alarm-related schemas

class AlarmResponse(BaseModel):
    """Alarm information response."""
    id: int
    alarm_type: str
    severity: AlarmSeverity
    title: str
    description: str
    queue_name: Optional[str]
    task_id: Optional[str]
    component: Optional[str]
    is_active: bool
    acknowledged: bool
    acknowledged_by: Optional[str]
    acknowledged_at: Optional[datetime]
    triggered_at: datetime
    resolved_at: Optional[datetime]
    last_occurrence: datetime
    occurrence_count: int
    context_data: Optional[Dict[str, Any]]
    tags: Optional[Dict[str, str]]
    duration_minutes: Optional[float]
    
    class Config:
        use_enum_values = True


class AlarmListRequest(PaginationRequest):
    """Request for listing alarms with filters."""
    severity: Optional[AlarmSeverity] = None
    alarm_type: Optional[str] = None
    is_active: Optional[bool] = None
    acknowledged: Optional[bool] = None
    component: Optional[str] = None
    triggered_after: Optional[datetime] = None
    triggered_before: Optional[datetime] = None


class AlarmListResponse(BaseModel):
    """Response for alarm listing."""
    alarms: List[AlarmResponse]
    pagination: PaginationResponse


class AlarmActionRequest(BaseModel):
    """Request for alarm actions (acknowledge, resolve)."""
    action_by: str = Field(..., min_length=1, max_length=100)
    notes: Optional[str] = Field(None, max_length=1000)
    notify_team: bool = Field(False, description="Send notification to team")


class AlarmActionResponse(BaseResponse):
    """Response for alarm actions."""
    alarm_id: int
    action: str
    action_by: str
    action_timestamp: datetime


# System control schemas

class SystemStatusResponse(BaseModel):
    """System status response."""
    is_operational: bool
    is_maintenance_mode: bool
    shutdown_requested: bool
    shutdown_reason: Optional[str]
    overall_health: str
    queue_health: str
    database_health: str
    redis_health: str
    total_queues: int
    active_queues: int
    total_tasks_processed: int
    current_active_tasks: int
    last_updated: Optional[datetime]
    last_health_check: Optional[datetime]
    uptime_started: datetime
    version: Optional[str]
    environment: Optional[str]


class MaintenanceModeRequest(BaseModel):
    """Request to enable/disable maintenance mode."""
    enabled: bool
    reason: Optional[str] = Field(None, max_length=500)
    duration_minutes: Optional[int] = Field(None, ge=1, le=1440)
    initiated_by: str = Field(..., min_length=1, max_length=100)


class EmergencyShutdownRequest(BaseModel):
    """Request for emergency system shutdown."""
    reason: str = Field(..., min_length=1, max_length=500)
    initiated_by: str = Field(..., min_length=1, max_length=100)
    force: bool = Field(False, description="Force shutdown even if tasks are running")
    delay_seconds: int = Field(0, ge=0, le=300, description="Delay before shutdown")


class ShutdownResponse(BaseResponse):
    """Response for shutdown request."""
    shutdown_id: str
    reason: str
    initiated_by: str
    scheduled_at: datetime
    estimated_completion: Optional[datetime]


# Analytics and reporting schemas

class TaskAnalyticsRequest(BaseModel):
    """Request for task analytics."""
    start_time: Optional[datetime] = None
    end_time: Optional[datetime] = None
    task_type: Optional[str] = None
    queue_name: Optional[str] = None
    group_by: str = Field("day", regex=r'^(hour|day|week|month)$')


class TaskAnalyticsResponse(BaseModel):
    """Task analytics response."""
    time_range: Dict[str, datetime]
    total_tasks: int
    completed_tasks: int
    failed_tasks: int
    average_duration: float
    success_rate: float
    trends: List[Dict[str, Any]]
    top_task_types: List[Dict[str, Any]]
    queue_performance: List[Dict[str, Any]]


class SystemMetricsRequest(BaseModel):
    """Request for system metrics."""
    start_time: Optional[datetime] = None
    end_time: Optional[datetime] = None
    metric_types: List[str] = Field(default_factory=lambda: ["cpu", "memory", "disk"])
    granularity: str = Field("hour", regex=r'^(minute|hour|day)$')


class SystemMetricsResponse(BaseModel):
    """System metrics response."""
    time_range: Dict[str, datetime]
    metrics: Dict[str, List[Dict[str, Any]]]
    current_values: Dict[str, float]
    thresholds: Dict[str, float]
    alerts_triggered: int


# Webhook schemas

class WebhookEventRequest(BaseModel):
    """Webhook event request."""
    event_type: str = Field(..., min_length=1, max_length=100)
    source: str = Field(..., min_length=1, max_length=100)
    payload: Dict[str, Any]
    signature: Optional[str] = Field(None, description="Webhook signature for verification")
    timestamp: Optional[datetime] = Field(default_factory=datetime.utcnow)


class WebhookEventResponse(BaseResponse):
    """Webhook event response."""
    event_id: str
    processed: bool
    processing_time_ms: float
    actions_taken: List[str]


# Configuration schemas

class ConfigurationUpdateRequest(BaseModel):
    """Request to update system configuration."""
    section: str = Field(..., regex=r'^[a-zA-Z0-9_]+$')
    settings: Dict[str, Any]
    updated_by: str = Field(..., min_length=1, max_length=100)
    reason: Optional[str] = Field(None, max_length=500)


class ConfigurationResponse(BaseModel):
    """Configuration response."""
    section: str
    settings: Dict[str, Any]
    last_updated: datetime
    updated_by: Optional[str]
    validation_errors: List[str] = []


# Batch operation schemas

class BatchTaskSubmissionRequest(BaseModel):
    """Request for batch task submission."""
    tasks: List[TaskSubmissionRequest] = Field(..., min_items=1, max_items=100)
    batch_id: Optional[str] = Field(None, max_length=100)
    fail_fast: bool = Field(False, description="Stop on first failure")


class BatchTaskSubmissionResponse(BaseModel):
    """Response for batch task submission."""
    batch_id: str
    total_tasks: int
    successful_submissions: int
    failed_submissions: int
    submitted_task_ids: List[str]
    errors: List[Dict[str, Any]]


class BatchOperationStatus(BaseModel):
    """Status of a batch operation."""
    batch_id: str
    operation_type: str
    total_items: int
    processed_items: int
    successful_items: int
    failed_items: int
    status: str
    started_at: datetime
    estimated_completion: Optional[datetime]
    errors: List[Dict[str, Any]]