"""
Queue management system with robust error handling and monitoring.
Provides centralized queue operations with health monitoring and alarm integration.
"""
import asyncio
import json
from datetime import datetime, timezone, timedelta
from typing import Dict, List, Optional, Any, Callable
from enum import Enum
from dataclasses import dataclass
from celery import Celery
from celery.result import AsyncResult
from sqlalchemy import select, and_, func
from sqlalchemy.ext.asyncio import AsyncSession
from cishub.config.settings import settings
from cishub.config.logging import LoggerMixin, log_performance
from cishub.core.models import (
    Queue, Task, TaskStatus, QueuePriority, QueueMetrics,
    SystemAlarm, AlarmSeverity
)
from cishub.utils.database import db_manager, BaseRepository


class QueueManagerError(Exception):
    """Base exception for queue manager operations."""
    pass


class QueueNotFoundError(QueueManagerError):
    """Raised when a queue is not found."""
    pass


class TaskNotFoundError(QueueManagerError):
    """Raised when a task is not found."""
    pass


@dataclass
class TaskSubmission:
    """Data class for task submission."""
    task_type: str
    task_name: str
    payload: Dict[str, Any]
    priority: QueuePriority = QueuePriority.NORMAL
    queue_name: str = "default"
    correlation_id: Optional[str] = None
    scheduled_at: Optional[datetime] = None
    timeout_seconds: Optional[int] = None
    tags: Optional[Dict[str, Any]] = None
    retry_limit: Optional[int] = None


@dataclass
class QueueHealth:
    """Data class for queue health status."""
    queue_name: str
    is_healthy: bool
    pending_count: int
    processing_count: int
    failed_count: int
    error_rate: float
    avg_processing_time: float
    last_processed_at: Optional[datetime]
    issues: List[str]


class QueueRepository(BaseRepository):
    """Repository for queue operations."""
    
    def __init__(self):
        super().__init__(Queue)
    
    async def get_by_name(self, session: AsyncSession, name: str) -> Optional[Queue]:
        """Get queue by name."""
        query = select(Queue).where(Queue.name == name)
        result = await session.execute(query)
        return result.scalar_one_or_none()
    
    async def get_active_queues(self, session: AsyncSession) -> List[Queue]:
        """Get all active queues."""
        query = select(Queue).where(Queue.is_active == True)
        result = await session.execute(query)
        return result.scalars().all()


class TaskRepository(BaseRepository):
    """Repository for task operations."""
    
    def __init__(self):
        super().__init__(Task)
    
    async def get_by_status(self, session: AsyncSession, status: TaskStatus, limit: int = 100) -> List[Task]:
        """Get tasks by status."""
        query = select(Task).where(Task.status == status.value).limit(limit)
        result = await session.execute(query)
        return result.scalars().all()
    
    async def get_overdue_tasks(self, session: AsyncSession) -> List[Task]:
        """Get overdue tasks."""
        now = datetime.now(timezone.utc)
        query = select(Task).where(
            and_(
                Task.status == TaskStatus.PROCESSING.value,
                Task.timeout_at < now
            )
        )
        result = await session.execute(query)
        return result.scalars().all()
    
    async def get_queue_stats(self, session: AsyncSession, queue_id: int) -> Dict[str, int]:
        """Get task statistics for a queue."""
        query = select(
            func.count().label('total'),
            func.sum(func.case((Task.status == TaskStatus.PENDING.value, 1), else_=0)).label('pending'),
            func.sum(func.case((Task.status == TaskStatus.PROCESSING.value, 1), else_=0)).label('processing'),
            func.sum(func.case((Task.status == TaskStatus.COMPLETED.value, 1), else_=0)).label('completed'),
            func.sum(func.case((Task.status == TaskStatus.FAILED.value, 1), else_=0)).label('failed')
        ).where(Task.queue_id == queue_id)
        
        result = await session.execute(query)
        row = result.first()
        
        return {
            'total': row.total or 0,
            'pending': row.pending or 0,
            'processing': row.processing or 0,
            'completed': row.completed or 0,
            'failed': row.failed or 0
        }


class CeleryManager(LoggerMixin):
    """Celery task management wrapper."""
    
    def __init__(self):
        super().__init__()
        self.celery_app = None
        self._initialized = False
    
    def initialize(self) -> None:
        """Initialize Celery application."""
        if self._initialized:
            return
        
        self.log_operation("initializing_celery")
        
        self.celery_app = Celery(
            'cishub',
            broker=settings.queue.broker_url,
            backend=settings.queue.result_backend
        )
        
        # Configure Celery
        self.celery_app.conf.update(
            worker_concurrency=settings.queue.worker_concurrency,
            worker_prefetch_multiplier=settings.queue.worker_prefetch_multiplier,
            task_soft_time_limit=settings.queue.task_soft_time_limit,
            task_time_limit=settings.queue.task_time_limit,
            task_default_retry_delay=settings.queue.default_retry_delay,
            task_max_retries=settings.queue.max_retries,
            task_acks_late=True,
            worker_disable_rate_limits=True,
            task_reject_on_worker_lost=True,
            task_serializer='json',
            result_serializer='json',
            accept_content=['json'],
            result_expires=3600,
            timezone='UTC',
            enable_utc=True,
        )
        
        self._initialized = True
        self.log_operation("celery_initialized")
    
    def submit_task(self, task_submission: TaskSubmission) -> str:
        """Submit task to Celery."""
        if not self._initialized:
            self.initialize()
        
        try:
            # Create task payload
            payload = {
                'task_type': task_submission.task_type,
                'task_name': task_submission.task_name,
                'payload': task_submission.payload,
                'correlation_id': task_submission.correlation_id,
                'tags': task_submission.tags or {}
            }
            
            # Submit to Celery
            result = self.celery_app.send_task(
                'cishub.worker.process_task',
                args=[payload],
                queue=task_submission.queue_name.lower(),
                priority=self._get_priority_value(task_submission.priority),
                eta=task_submission.scheduled_at,
                expires=task_submission.timeout_seconds
            )
            
            self.log_operation(
                "task_submitted_to_celery",
                task_id=result.id,
                task_type=task_submission.task_type,
                queue=task_submission.queue_name
            )
            
            return result.id
            
        except Exception as e:
            self.log_error(e, "celery_task_submission_failed")
            raise
    
    def get_task_status(self, task_id: str) -> Dict[str, Any]:
        """Get task status from Celery."""
        if not self._initialized:
            self.initialize()
        
        try:
            result = AsyncResult(task_id, app=self.celery_app)
            return {
                'id': task_id,
                'status': result.status,
                'result': result.result,
                'traceback': result.traceback,
                'successful': result.successful(),
                'failed': result.failed()
            }
        except Exception as e:
            self.log_error(e, "get_celery_task_status_failed", task_id=task_id)
            raise
    
    def revoke_task(self, task_id: str, terminate: bool = False) -> bool:
        """Revoke a Celery task."""
        if not self._initialized:
            self.initialize()
        
        try:
            self.celery_app.control.revoke(task_id, terminate=terminate)
            self.log_operation("task_revoked", task_id=task_id, terminate=terminate)
            return True
        except Exception as e:
            self.log_error(e, "task_revoke_failed", task_id=task_id)
            return False
    
    def _get_priority_value(self, priority: QueuePriority) -> int:
        """Convert priority enum to Celery priority value."""
        priority_map = {
            QueuePriority.LOW: 1,
            QueuePriority.NORMAL: 5,
            QueuePriority.HIGH: 8,
            QueuePriority.CRITICAL: 10
        }
        return priority_map.get(priority, 5)


class QueueManager(LoggerMixin):
    """Main queue management system."""
    
    def __init__(self):
        super().__init__()
        self.queue_repo = QueueRepository()
        self.task_repo = TaskRepository()
        self.celery_manager = CeleryManager()
        self.health_check_callbacks: List[Callable[[QueueHealth], None]] = []
        self._monitoring_task = None
        self._shutdown_requested = False
    
    async def initialize(self) -> None:
        """Initialize queue manager."""
        self.log_operation("initializing_queue_manager")
        
        # Initialize database
        db_manager.initialize()
        await db_manager.create_tables()
        
        # Initialize Celery
        self.celery_manager.initialize()
        
        # Create default queue if it doesn't exist
        await self._ensure_default_queue()
        
        self.log_operation("queue_manager_initialized")
    
    async def _ensure_default_queue(self) -> None:
        """Ensure default queue exists."""
        async with db_manager.get_async_session() as session:
            default_queue = await self.queue_repo.get_by_name(session, "default")
            if not default_queue:
                await self.queue_repo.create(
                    session,
                    name="default",
                    description="Default processing queue",
                    priority=QueuePriority.NORMAL.value,
                    is_active=True,
                    max_workers=4,
                    retry_limit=3,
                    timeout_seconds=300
                )
                self.log_operation("default_queue_created")
    
    @log_performance("submit_task")
    async def submit_task(self, task_submission: TaskSubmission) -> str:
        """Submit a task for processing."""
        self.log_operation(
            "submitting_task",
            task_type=task_submission.task_type,
            queue=task_submission.queue_name
        )
        
        async with db_manager.get_async_session() as session:
            # Get or create queue
            queue = await self.queue_repo.get_by_name(session, task_submission.queue_name)
            if not queue:
                raise QueueNotFoundError(f"Queue '{task_submission.queue_name}' not found")
            
            if not queue.is_active:
                raise QueueManagerError(f"Queue '{task_submission.queue_name}' is not active")
            
            # Set defaults from queue if not specified
            if task_submission.timeout_seconds is None:
                task_submission.timeout_seconds = queue.timeout_seconds
            if task_submission.retry_limit is None:
                task_submission.retry_limit = queue.retry_limit
            
            # Submit to Celery
            celery_task_id = self.celery_manager.submit_task(task_submission)
            
            # Create database record
            timeout_at = None
            if task_submission.timeout_seconds:
                base_time = task_submission.scheduled_at or datetime.now(timezone.utc)
                timeout_at = base_time + timedelta(seconds=task_submission.timeout_seconds)
            
            task = await self.task_repo.create(
                session,
                queue_id=queue.id,
                task_type=task_submission.task_type,
                task_name=task_submission.task_name,
                payload=task_submission.payload,
                status=TaskStatus.PENDING.value,
                priority=task_submission.priority.value,
                max_retries=task_submission.retry_limit,
                correlation_id=task_submission.correlation_id,
                scheduled_at=task_submission.scheduled_at or datetime.now(timezone.utc),
                timeout_at=timeout_at,
                tags=task_submission.tags
            )
            
            # Update task with Celery ID
            task.worker_id = celery_task_id
            await session.flush()
            
            self.log_operation(
                "task_submitted",
                task_id=str(task.id),
                celery_task_id=celery_task_id,
                queue=task_submission.queue_name
            )
            
            return str(task.id)
    
    async def get_task_status(self, task_id: str) -> Optional[Dict[str, Any]]:
        """Get task status."""
        async with db_manager.get_async_session() as session:
            task = await self.task_repo.get_by_id(session, task_id)
            if not task:
                return None
            
            # Get Celery status if available
            celery_status = None
            if task.worker_id:
                try:
                    celery_status = self.celery_manager.get_task_status(task.worker_id)
                except Exception as e:
                    self.log_warning("failed_to_get_celery_status", task_id=task_id, error=str(e))
            
            return {
                'id': str(task.id),
                'task_type': task.task_type,
                'task_name': task.task_name,
                'status': task.status,
                'priority': task.priority,
                'retry_count': task.retry_count,
                'max_retries': task.max_retries,
                'error_message': task.error_message,
                'created_at': task.created_at.isoformat() if task.created_at else None,
                'started_at': task.started_at.isoformat() if task.started_at else None,
                'completed_at': task.completed_at.isoformat() if task.completed_at else None,
                'duration_seconds': task.duration_seconds,
                'is_overdue': task.is_overdue,
                'correlation_id': task.correlation_id,
                'tags': task.tags,
                'celery_status': celery_status
            }
    
    async def cancel_task(self, task_id: str) -> bool:
        """Cancel a task."""
        async with db_manager.get_async_session() as session:
            task = await self.task_repo.get_by_id(session, task_id)
            if not task:
                raise TaskNotFoundError(f"Task '{task_id}' not found")
            
            # Cancel in Celery if possible
            if task.worker_id:
                self.celery_manager.revoke_task(task.worker_id, terminate=True)
            
            # Update database status
            await self.task_repo.update(
                session,
                task_id,
                status=TaskStatus.CANCELLED.value,
                completed_at=datetime.now(timezone.utc)
            )
            
            self.log_operation("task_cancelled", task_id=task_id)
            return True
    
    async def get_queue_health(self, queue_name: str) -> QueueHealth:
        """Get health status for a queue."""
        async with db_manager.get_async_session() as session:
            queue = await self.queue_repo.get_by_name(session, queue_name)
            if not queue:
                raise QueueNotFoundError(f"Queue '{queue_name}' not found")
            
            # Get task statistics
            stats = await self.task_repo.get_queue_stats(session, queue.id)
            
            # Calculate error rate
            total_processed = stats['completed'] + stats['failed']
            error_rate = (stats['failed'] / total_processed * 100) if total_processed > 0 else 0
            
            # Get recent metrics
            recent_metrics = await self._get_recent_metrics(session, queue.id)
            avg_processing_time = recent_metrics.get('avg_processing_time', 0.0) if recent_metrics else 0.0
            
            # Get last processed task timestamp
            last_processed_query = select(Task.completed_at).where(
                and_(
                    Task.queue_id == queue.id,
                    Task.status == TaskStatus.COMPLETED.value
                )
            ).order_by(Task.completed_at.desc()).limit(1)
            
            result = await session.execute(last_processed_query)
            last_processed_at = result.scalar_one_or_none()
            
            # Determine health issues
            issues = []
            is_healthy = True
            
            # Check for backup
            if stats['pending'] > settings.queue.backup_threshold:
                issues.append(f"Queue backup: {stats['pending']} pending tasks")
                is_healthy = False
            
            # Check error rate
            if error_rate > settings.queue.error_threshold:
                issues.append(f"High error rate: {error_rate:.1f}%")
                is_healthy = False
            
            # Check processing timeout
            if last_processed_at:
                time_since_last = datetime.now(timezone.utc) - last_processed_at
                if time_since_last.total_seconds() > settings.queue.processing_timeout:
                    issues.append(f"No processing for {time_since_last.total_seconds():.0f} seconds")
                    is_healthy = False
            
            # Check for overdue tasks
            overdue_tasks = await self.task_repo.get_overdue_tasks(session)
            if overdue_tasks:
                issues.append(f"{len(overdue_tasks)} overdue tasks")
                is_healthy = False
            
            return QueueHealth(
                queue_name=queue_name,
                is_healthy=is_healthy,
                pending_count=stats['pending'],
                processing_count=stats['processing'],
                failed_count=stats['failed'],
                error_rate=error_rate,
                avg_processing_time=avg_processing_time,
                last_processed_at=last_processed_at,
                issues=issues
            )
    
    async def _get_recent_metrics(self, session: AsyncSession, queue_id: int) -> Optional[Dict[str, Any]]:
        """Get most recent metrics for a queue."""
        query = select(QueueMetrics).where(
            QueueMetrics.queue_id == queue_id
        ).order_by(QueueMetrics.timestamp.desc()).limit(1)
        
        result = await session.execute(query)
        metrics = result.scalar_one_or_none()
        
        if metrics:
            return {
                'avg_processing_time': metrics.avg_processing_time,
                'error_rate': metrics.error_rate,
                'success_rate': metrics.success_rate
            }
        return None
    
    def add_health_check_callback(self, callback: Callable[[QueueHealth], None]) -> None:
        """Add a callback for health check results."""
        self.health_check_callbacks.append(callback)
    
    async def start_monitoring(self, interval: int = None) -> None:
        """Start queue monitoring."""
        if self._monitoring_task:
            return
        
        interval = interval or settings.queue.health_check_interval
        self.log_operation("starting_queue_monitoring", interval=interval)
        
        self._monitoring_task = asyncio.create_task(self._monitoring_loop(interval))
    
    async def stop_monitoring(self) -> None:
        """Stop queue monitoring."""
        if self._monitoring_task:
            self.log_operation("stopping_queue_monitoring")
            self._monitoring_task.cancel()
            try:
                await self._monitoring_task
            except asyncio.CancelledError:
                pass
            self._monitoring_task = None
    
    async def _monitoring_loop(self, interval: int) -> None:
        """Main monitoring loop."""
        while not self._shutdown_requested:
            try:
                await self._perform_health_checks()
                await asyncio.sleep(interval)
            except asyncio.CancelledError:
                break
            except Exception as e:
                self.log_error(e, "monitoring_loop_error")
                await asyncio.sleep(min(interval, 60))  # Fallback interval
    
    async def _perform_health_checks(self) -> None:
        """Perform health checks on all active queues."""
        async with db_manager.get_async_session() as session:
            active_queues = await self.queue_repo.get_active_queues(session)
            
            for queue in active_queues:
                try:
                    health = await self.get_queue_health(queue.name)
                    
                    # Call registered callbacks
                    for callback in self.health_check_callbacks:
                        try:
                            if asyncio.iscoroutinefunction(callback):
                                await callback(health)
                            else:
                                callback(health)
                        except Exception as e:
                            self.log_error(e, "health_check_callback_failed", queue=queue.name)
                    
                    # Store metrics
                    await self._store_queue_metrics(session, queue.id, health)
                    
                except Exception as e:
                    self.log_error(e, "queue_health_check_failed", queue=queue.name)
    
    async def _store_queue_metrics(self, session: AsyncSession, queue_id: int, health: QueueHealth) -> None:
        """Store queue metrics in database."""
        try:
            # Get system metrics (simplified for now)
            cpu_usage = 0.0  # TODO: Implement actual system metrics
            memory_usage = 0.0
            disk_usage = 0.0
            
            metrics_repo = BaseRepository(QueueMetrics)
            await metrics_repo.create(
                session,
                queue_id=queue_id,
                timestamp=datetime.now(timezone.utc),
                pending_count=health.pending_count,
                processing_count=health.processing_count,
                failed_count=health.failed_count,
                avg_processing_time=health.avg_processing_time,
                error_rate=health.error_rate,
                success_rate=100.0 - health.error_rate,
                cpu_usage=cpu_usage,
                memory_usage=memory_usage,
                disk_usage=disk_usage
            )
        except Exception as e:
            self.log_error(e, "store_queue_metrics_failed", queue_id=queue_id)
    
    async def shutdown(self) -> None:
        """Shutdown queue manager."""
        self.log_operation("shutting_down_queue_manager")
        
        self._shutdown_requested = True
        await self.stop_monitoring()
        
        self.log_operation("queue_manager_shutdown_complete")


# Global queue manager instance
queue_manager = QueueManager()