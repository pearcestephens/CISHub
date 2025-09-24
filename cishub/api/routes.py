"""
FastAPI routes for CISHub API.
Provides RESTful endpoints for queue management, monitoring, and system control.
"""
from datetime import datetime, timezone
from typing import Dict, List, Optional, Any
from fastapi import FastAPI, HTTPException, Depends, status, BackgroundTasks, Header
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from sqlalchemy.ext.asyncio import AsyncSession
from cishub.config.settings import settings
from cishub.config.logging import get_logger, configure_logging
from cishub.core.queue_manager import queue_manager, TaskSubmission, QueuePriority
from cishub.core.models import TaskStatus, AlarmSeverity
from cishub.monitoring.health_checker import health_monitor
from cishub.monitoring.alarm_system import alarm_system, AlarmEvent, AlarmType
from cishub.utils.database import get_async_db

# Configure logging
configure_logging()
logger = get_logger(__name__)

# Create FastAPI app
app = FastAPI(
    title="CISHub API",
    description="Robust queue integration system with monitoring and alarm capabilities",
    version="1.0.0",
    docs_url="/docs",
    redoc_url="/redoc"
)

# Configure CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.api.allow_origins,
    allow_credentials=settings.api.allow_credentials,
    allow_methods=settings.api.allow_methods,
    allow_headers=settings.api.allow_headers,
)


# Pydantic models for API

class TaskSubmissionRequest(BaseModel):
    """Request model for task submission."""
    task_type: str = Field(..., description="Type of task to process")
    task_name: str = Field(..., description="Human-readable task name")
    payload: Dict[str, Any] = Field(..., description="Task payload data")
    priority: QueuePriority = Field(QueuePriority.NORMAL, description="Task priority")
    queue_name: str = Field("default", description="Queue name")
    correlation_id: Optional[str] = Field(None, description="Correlation ID for tracking")
    scheduled_at: Optional[datetime] = Field(None, description="Schedule task for future execution")
    timeout_seconds: Optional[int] = Field(None, description="Task timeout in seconds")
    tags: Optional[Dict[str, Any]] = Field(None, description="Additional tags")
    retry_limit: Optional[int] = Field(None, description="Maximum retry attempts")


class TaskResponse(BaseModel):
    """Response model for task information."""
    id: str
    task_type: str
    task_name: str
    status: str
    priority: str
    retry_count: int
    max_retries: int
    error_message: Optional[str]
    created_at: Optional[str]
    started_at: Optional[str]
    completed_at: Optional[str]
    duration_seconds: Optional[float]
    is_overdue: bool
    correlation_id: Optional[str]
    tags: Optional[Dict[str, Any]]
    celery_status: Optional[Dict[str, Any]]


class QueueHealthResponse(BaseModel):
    """Response model for queue health."""
    queue_name: str
    is_healthy: bool
    pending_count: int
    processing_count: int
    failed_count: int
    error_rate: float
    avg_processing_time: float
    last_processed_at: Optional[str]
    issues: List[str]


class SystemHealthResponse(BaseModel):
    """Response model for system health."""
    overall_status: str
    timestamp: str
    uptime_seconds: float
    summary: Dict[str, int]
    components: List[Dict[str, Any]]
    system_metrics: Dict[str, Any]


class AlarmResponse(BaseModel):
    """Response model for alarms."""
    id: int
    alarm_type: str
    severity: str
    title: str
    description: str
    component: Optional[str]
    is_active: bool
    acknowledged: bool
    acknowledged_by: Optional[str]
    triggered_at: Optional[str]
    occurrence_count: int
    context_data: Optional[Dict[str, Any]]
    tags: Optional[Dict[str, Any]]


class AlarmAcknowledgmentRequest(BaseModel):
    """Request model for alarm acknowledgment."""
    acknowledged_by: str = Field(..., description="Who acknowledged the alarm")
    notes: Optional[str] = Field(None, description="Optional acknowledgment notes")


class EmergencyShutdownRequest(BaseModel):
    """Request model for emergency shutdown."""
    reason: str = Field(..., description="Reason for shutdown")
    initiated_by: str = Field(..., description="Who initiated the shutdown")
    force: bool = Field(False, description="Force shutdown even if tasks are running")


# Dependency functions

async def verify_shutdown_token(authorization: str = Header(None)) -> bool:
    """Verify shutdown authorization token."""
    if not authorization:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Authorization header required"
        )
    
    token = authorization.replace("Bearer ", "")
    if token != settings.api.shutdown_token:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Invalid shutdown token"
        )
    
    return True


# Health and status endpoints

@app.get("/health", response_model=SystemHealthResponse, tags=["Health"])
async def get_system_health():
    """Get comprehensive system health status."""
    try:
        report = await health_monitor.perform_health_check()
        return SystemHealthResponse(**report.to_dict())
    except Exception as e:
        logger.error("get_system_health_failed", error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to get system health"
        )


@app.get("/health/quick", tags=["Health"])
async def get_quick_health():
    """Get quick health check (lightweight)."""
    return {
        "status": "healthy",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "service": "cishub-api"
    }


@app.get("/health/components", tags=["Health"])
async def get_component_health():
    """Get health status of individual components."""
    try:
        report = health_monitor.get_last_report()
        if not report:
            report = await health_monitor.perform_health_check()
        
        return {
            "components": [comp.to_dict() for comp in report.components],
            "last_check": report.timestamp.isoformat()
        }
    except Exception as e:
        logger.error("get_component_health_failed", error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to get component health"
        )


# Task management endpoints

@app.post("/tasks", response_model=Dict[str, str], tags=["Tasks"])
async def submit_task(request: TaskSubmissionRequest, background_tasks: BackgroundTasks):
    """Submit a new task for processing."""
    try:
        # Create task submission
        task_submission = TaskSubmission(
            task_type=request.task_type,
            task_name=request.task_name,
            payload=request.payload,
            priority=request.priority,
            queue_name=request.queue_name,
            correlation_id=request.correlation_id,
            scheduled_at=request.scheduled_at,
            timeout_seconds=request.timeout_seconds,
            tags=request.tags,
            retry_limit=request.retry_limit
        )
        
        # Submit task
        task_id = await queue_manager.submit_task(task_submission)
        
        logger.info(
            "task_submitted_via_api",
            task_id=task_id,
            task_type=request.task_type,
            queue=request.queue_name
        )
        
        return {"task_id": task_id, "status": "submitted"}
        
    except Exception as e:
        logger.error("submit_task_failed", error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Failed to submit task: {str(e)}"
        )


@app.get("/tasks/{task_id}", response_model=TaskResponse, tags=["Tasks"])
async def get_task_status(task_id: str):
    """Get status of a specific task."""
    try:
        task_info = await queue_manager.get_task_status(task_id)
        if not task_info:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Task not found"
            )
        
        return TaskResponse(**task_info)
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error("get_task_status_failed", task_id=task_id, error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to get task status"
        )


@app.delete("/tasks/{task_id}", tags=["Tasks"])
async def cancel_task(task_id: str):
    """Cancel a task."""
    try:
        success = await queue_manager.cancel_task(task_id)
        if not success:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Task not found"
            )
        
        logger.info("task_cancelled_via_api", task_id=task_id)
        return {"task_id": task_id, "status": "cancelled"}
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error("cancel_task_failed", task_id=task_id, error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to cancel task"
        )


# Queue management endpoints

@app.get("/queues/{queue_name}/health", response_model=QueueHealthResponse, tags=["Queues"])
async def get_queue_health(queue_name: str):
    """Get health status of a specific queue."""
    try:
        health = await queue_manager.get_queue_health(queue_name)
        
        return QueueHealthResponse(
            queue_name=health.queue_name,
            is_healthy=health.is_healthy,
            pending_count=health.pending_count,
            processing_count=health.processing_count,
            failed_count=health.failed_count,
            error_rate=health.error_rate,
            avg_processing_time=health.avg_processing_time,
            last_processed_at=health.last_processed_at.isoformat() if health.last_processed_at else None,
            issues=health.issues
        )
        
    except Exception as e:
        logger.error("get_queue_health_failed", queue=queue_name, error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Failed to get queue health: {str(e)}"
        )


@app.get("/queues", tags=["Queues"])
async def list_queues(db: AsyncSession = Depends(get_async_db)):
    """List all queues with their basic information."""
    try:
        from sqlalchemy import select
        from cishub.core.models import Queue
        
        query = select(Queue)
        result = await db.execute(query)
        queues = result.scalars().all()
        
        return [
            {
                "id": queue.id,
                "name": queue.name,
                "description": queue.description,
                "priority": queue.priority,
                "is_active": queue.is_active,
                "max_workers": queue.max_workers,
                "retry_limit": queue.retry_limit,
                "timeout_seconds": queue.timeout_seconds,
                "created_at": queue.created_at.isoformat() if queue.created_at else None
            }
            for queue in queues
        ]
        
    except Exception as e:
        logger.error("list_queues_failed", error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to list queues"
        )


# Alarm management endpoints

@app.get("/alarms", response_model=List[AlarmResponse], tags=["Alarms"])
async def get_active_alarms():
    """Get all active alarms."""
    try:
        alarms = await alarm_system.get_active_alarms()
        return [AlarmResponse(**alarm) for alarm in alarms]
        
    except Exception as e:
        logger.error("get_active_alarms_failed", error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to get alarms"
        )


@app.post("/alarms/{alarm_id}/acknowledge", tags=["Alarms"])
async def acknowledge_alarm(alarm_id: int, request: AlarmAcknowledgmentRequest):
    """Acknowledge an alarm."""
    try:
        success = await alarm_system.acknowledge_alarm(alarm_id, request.acknowledged_by)
        if not success:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Alarm not found"
            )
        
        logger.info(
            "alarm_acknowledged_via_api",
            alarm_id=alarm_id,
            acknowledged_by=request.acknowledged_by
        )
        
        return {"alarm_id": alarm_id, "status": "acknowledged"}
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error("acknowledge_alarm_failed", alarm_id=alarm_id, error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to acknowledge alarm"
        )


@app.post("/alarms/{alarm_id}/resolve", tags=["Alarms"])
async def resolve_alarm(alarm_id: int, request: AlarmAcknowledgmentRequest):
    """Resolve an alarm."""
    try:
        success = await alarm_system.resolve_alarm(alarm_id, request.acknowledged_by)
        if not success:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Alarm not found"
            )
        
        logger.info(
            "alarm_resolved_via_api",
            alarm_id=alarm_id,
            resolved_by=request.acknowledged_by
        )
        
        return {"alarm_id": alarm_id, "status": "resolved"}
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error("resolve_alarm_failed", alarm_id=alarm_id, error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to resolve alarm"
        )


# System control endpoints

@app.post("/system/shutdown", tags=["System Control"])
async def emergency_shutdown(
    request: EmergencyShutdownRequest,
    authorized: bool = Depends(verify_shutdown_token)
):
    """Trigger emergency system shutdown."""
    try:
        logger.warning(
            "emergency_shutdown_requested",
            reason=request.reason,
            initiated_by=request.initiated_by,
            force=request.force
        )
        
        # Create shutdown alarm
        shutdown_alarm = AlarmEvent(
            alarm_type=AlarmType.SYSTEM_SHUTDOWN,
            severity=AlarmSeverity.CRITICAL,
            title="Emergency System Shutdown",
            description=f"Emergency shutdown requested: {request.reason}",
            component="api",
            context_data={
                "initiated_by": request.initiated_by,
                "reason": request.reason,
                "force": request.force,
                "timestamp": datetime.now(timezone.utc).isoformat()
            }
        )
        
        # Trigger shutdown through alarm system
        await alarm_system.shutdown_handler.trigger_emergency_shutdown(
            shutdown_alarm,
            f"API shutdown request by {request.initiated_by}: {request.reason}"
        )
        
        return {
            "status": "shutdown_initiated",
            "reason": request.reason,
            "initiated_by": request.initiated_by,
            "timestamp": datetime.now(timezone.utc).isoformat()
        }
        
    except Exception as e:
        logger.error("emergency_shutdown_failed", error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to initiate shutdown"
        )


@app.get("/system/status", tags=["System Control"])
async def get_system_status(db: AsyncSession = Depends(get_async_db)):
    """Get current system status."""
    try:
        from sqlalchemy import select
        from cishub.core.models import SystemStatus
        
        query = select(SystemStatus).limit(1)
        result = await db.execute(query)
        status = result.scalar_one_or_none()
        
        if not status:
            return {
                "status": "unknown",
                "message": "System status not initialized"
            }
        
        return {
            "is_operational": status.is_operational,
            "is_maintenance_mode": status.is_maintenance_mode,
            "shutdown_requested": status.shutdown_requested,
            "shutdown_reason": status.shutdown_reason,
            "overall_health": status.overall_health,
            "queue_health": status.queue_health,
            "database_health": status.database_health,
            "redis_health": status.redis_health,
            "last_updated": status.last_updated.isoformat() if status.last_updated else None,
            "last_health_check": status.last_health_check.isoformat() if status.last_health_check else None,
            "uptime_started": status.uptime_started.isoformat() if status.uptime_started else None,
            "version": status.version,
            "environment": status.environment
        }
        
    except Exception as e:
        logger.error("get_system_status_failed", error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to get system status"
        )


# Test endpoints (for development/testing)

@app.post("/test/alarm", tags=["Testing"])
async def trigger_test_alarm():
    """Trigger a test alarm (for testing purposes)."""
    if not settings.debug:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Test endpoints only available in debug mode"
        )
    
    try:
        await alarm_system.trigger_alarm(
            AlarmEvent(
                alarm_type=AlarmType.SYSTEM_ERROR,
                severity=AlarmSeverity.WARNING,
                title="Test Alarm",
                description="This is a test alarm triggered via API",
                component="api_test",
                context_data={"test": True, "timestamp": datetime.now(timezone.utc).isoformat()}
            )
        )
        
        return {"status": "test_alarm_triggered"}
        
    except Exception as e:
        logger.error("trigger_test_alarm_failed", error=str(e), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to trigger test alarm"
        )


# Error handlers

@app.exception_handler(Exception)
async def global_exception_handler(request, exc):
    """Global exception handler."""
    logger.error(
        "unhandled_api_exception",
        path=request.url.path,
        method=request.method,
        error=str(exc),
        exc_info=True
    )
    
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "detail": "Internal server error",
            "timestamp": datetime.now(timezone.utc).isoformat()
        }
    )


# Startup and shutdown events

@app.on_event("startup")
async def startup_event():
    """Application startup event."""
    logger.info("api_starting_up")
    
    try:
        # Initialize queue manager
        await queue_manager.initialize()
        
        # Start monitoring
        await queue_manager.start_monitoring()
        await health_monitor.start_monitoring()
        
        # Connect alarm system to queue monitoring
        queue_manager.add_health_check_callback(alarm_system.process_queue_health)
        
        logger.info("api_startup_complete")
        
    except Exception as e:
        logger.error("api_startup_failed", error=str(e), exc_info=True)
        raise


@app.on_event("shutdown")
async def shutdown_event():
    """Application shutdown event."""
    logger.info("api_shutting_down")
    
    try:
        # Stop monitoring
        await queue_manager.stop_monitoring()
        await health_monitor.stop_monitoring()
        
        # Shutdown queue manager
        await queue_manager.shutdown()
        
        logger.info("api_shutdown_complete")
        
    except Exception as e:
        logger.error("api_shutdown_failed", error=str(e), exc_info=True)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "cishub.api.routes:app",
        host=settings.api.host,
        port=settings.api.port,
        reload=settings.debug,
        log_level=settings.log_level.lower()
    )