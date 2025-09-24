"""
Celery worker implementation for CISHub task processing.
Handles task execution with proper error handling, retries, and monitoring.
"""
import asyncio
import traceback
from datetime import datetime, timezone, timedelta
from typing import Dict, Any, Optional, Callable
import celery
from celery import Celery, Task
from celery.signals import (
    task_prerun, task_postrun, task_failure, task_retry,
    worker_ready, worker_shutdown
)
from cishub.config.settings import settings
from cishub.config.logging import get_logger, configure_logging
from cishub.core.models import TaskStatus
from cishub.utils.database import db_manager
from cishub.monitoring.alarm_system import alarm_system, AlarmEvent, AlarmType, AlarmSeverity

# Configure logging for Celery
configure_logging()
logger = get_logger(__name__)

# Create Celery app
app = Celery('cishub')
app.conf.update(
    broker_url=settings.queue.broker_url,
    result_backend=settings.queue.result_backend,
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


class CISHubTask(Task):
    """Custom task class with enhanced error handling and monitoring."""
    
    def on_failure(self, exc, task_id, args, kwargs, einfo):
        """Handle task failure."""
        logger.error(
            "task_failed",
            task_id=task_id,
            exception=str(exc),
            exception_type=type(exc).__name__,
            args=args[:2] if args else [],  # Limit args logging
            exc_info=True
        )
        
        # Update task status in database
        asyncio.create_task(
            self._update_task_status(task_id, TaskStatus.FAILED, error_message=str(exc))
        )
    
    def on_retry(self, exc, task_id, args, kwargs, einfo):
        """Handle task retry."""
        logger.warning(
            "task_retrying",
            task_id=task_id,
            exception=str(exc),
            retry_count=self.request.retries,
            max_retries=self.max_retries
        )
        
        # Update task status in database
        asyncio.create_task(
            self._update_task_status(task_id, TaskStatus.RETRYING, error_message=str(exc))
        )
    
    def on_success(self, retval, task_id, args, kwargs):
        """Handle task success."""
        logger.info(
            "task_completed",
            task_id=task_id,
            return_value_type=type(retval).__name__ if retval else None
        )
        
        # Update task status in database
        asyncio.create_task(
            self._update_task_status(task_id, TaskStatus.COMPLETED, result=retval)
        )
    
    async def _update_task_status(self, task_id: str, status: TaskStatus, 
                                  error_message: str = None, result: Any = None):
        """Update task status in database."""
        try:
            async with db_manager.get_async_session() as session:
                from sqlalchemy import select, update
                from cishub.core.models import Task as TaskModel
                
                # Find task by worker_id (Celery task ID)
                query = select(TaskModel).where(TaskModel.worker_id == task_id)
                db_result = await session.execute(query)
                task = db_result.scalar_one_or_none()
                
                if not task:
                    logger.warning("task_not_found_in_db", celery_task_id=task_id)
                    return
                
                # Update task fields
                update_fields = {
                    'status': status.value,
                    'updated_at': datetime.now(timezone.utc)
                }
                
                if status == TaskStatus.PROCESSING:
                    update_fields['started_at'] = datetime.now(timezone.utc)
                elif status in [TaskStatus.COMPLETED, TaskStatus.FAILED, TaskStatus.CANCELLED]:
                    update_fields['completed_at'] = datetime.now(timezone.utc)
                
                if status == TaskStatus.RETRYING:
                    update_fields['retry_count'] = task.retry_count + 1
                    update_fields['last_error_at'] = datetime.now(timezone.utc)
                
                if error_message:
                    update_fields['error_message'] = error_message
                    update_fields['error_traceback'] = traceback.format_exc()
                
                if result is not None:
                    update_fields['result'] = result if isinstance(result, dict) else {'value': result}
                
                # Update the task
                update_query = update(TaskModel).where(TaskModel.worker_id == task_id).values(**update_fields)
                await session.execute(update_query)
                await session.commit()
                
        except Exception as e:
            logger.error("failed_to_update_task_status", task_id=task_id, error=str(e), exc_info=True)


# Set custom task class
app.Task = CISHubTask


# Task processors registry
TASK_PROCESSORS: Dict[str, Callable] = {}


def register_task_processor(task_type: str):
    """Decorator to register task processors."""
    def decorator(func):
        TASK_PROCESSORS[task_type] = func
        logger.info("task_processor_registered", task_type=task_type, function=func.__name__)
        return func
    return decorator


@app.task(bind=True, max_retries=3, default_retry_delay=60)
def process_task(self, payload: Dict[str, Any]) -> Dict[str, Any]:
    """Main task processing function."""
    task_type = payload.get('task_type')
    task_name = payload.get('task_name', 'unknown')
    correlation_id = payload.get('correlation_id')
    
    logger.info(
        "processing_task",
        task_id=self.request.id,
        task_type=task_type,
        task_name=task_name,
        correlation_id=correlation_id
    )
    
    # Update task status to processing
    asyncio.create_task(
        self._update_task_status(self.request.id, TaskStatus.PROCESSING)
    )
    
    try:
        # Get task processor
        processor = TASK_PROCESSORS.get(task_type)
        if not processor:
            raise ValueError(f"No processor registered for task type: {task_type}")
        
        # Execute the task
        start_time = datetime.now(timezone.utc)
        
        if asyncio.iscoroutinefunction(processor):
            # Handle async processors
            loop = asyncio.new_event_loop()
            asyncio.set_event_loop(loop)
            try:
                result = loop.run_until_complete(processor(payload))
            finally:
                loop.close()
        else:
            # Handle sync processors
            result = processor(payload)
        
        end_time = datetime.now(timezone.utc)
        duration = (end_time - start_time).total_seconds()
        
        logger.info(
            "task_processed_successfully",
            task_id=self.request.id,
            task_type=task_type,
            duration_seconds=duration,
            correlation_id=correlation_id
        )
        
        return {
            'success': True,
            'result': result,
            'duration_seconds': duration,
            'processed_at': end_time.isoformat()
        }
        
    except Exception as exc:
        logger.error(
            "task_processing_failed",
            task_id=self.request.id,
            task_type=task_type,
            error=str(exc),
            exc_info=True
        )
        
        # Check if we should retry
        if self.request.retries < self.max_retries:
            logger.info(
                "retrying_task",
                task_id=self.request.id,
                retry_count=self.request.retries + 1,
                max_retries=self.max_retries
            )
            
            # Calculate retry delay (exponential backoff)
            retry_delay = min(
                self.default_retry_delay * (2 ** self.request.retries),
                3600  # Max 1 hour
            )
            
            raise self.retry(exc=exc, countdown=retry_delay)
        
        # Max retries reached, fail the task
        raise exc


# Built-in task processors

@register_task_processor('lightspeed_sync')
async def process_lightspeed_sync(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Process Lightspeed synchronization tasks."""
    logger.info("processing_lightspeed_sync", payload_keys=list(payload.keys()))
    
    # TODO: Implement actual Lightspeed synchronization logic
    # This is a placeholder for the actual implementation
    
    sync_type = payload.get('sync_type', 'full')
    entity_type = payload.get('entity_type', 'products')
    
    # Simulate processing time
    await asyncio.sleep(2)
    
    # Return result
    return {
        'sync_type': sync_type,
        'entity_type': entity_type,
        'records_processed': 150,
        'records_updated': 25,
        'records_created': 10,
        'errors': []
    }


@register_task_processor('data_validation')
def process_data_validation(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Process data validation tasks."""
    logger.info("processing_data_validation", payload_keys=list(payload.keys()))
    
    # TODO: Implement actual data validation logic
    
    data = payload.get('data', {})
    validation_rules = payload.get('validation_rules', [])
    
    # Simulate validation
    errors = []
    warnings = []
    
    # Return validation result
    return {
        'valid': len(errors) == 0,
        'errors': errors,
        'warnings': warnings,
        'validated_fields': len(data),
        'applied_rules': len(validation_rules)
    }


@register_task_processor('webhook_processing')
async def process_webhook(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Process webhook events."""
    logger.info("processing_webhook", payload_keys=list(payload.keys()))
    
    webhook_type = payload.get('webhook_type')
    webhook_data = payload.get('webhook_data', {})
    
    # TODO: Implement actual webhook processing logic
    
    # Simulate processing
    await asyncio.sleep(1)
    
    return {
        'webhook_type': webhook_type,
        'processed': True,
        'actions_taken': ['data_sync', 'cache_invalidation'],
        'timestamp': datetime.now(timezone.utc).isoformat()
    }


@register_task_processor('system_maintenance')
def process_system_maintenance(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Process system maintenance tasks."""
    logger.info("processing_system_maintenance", payload_keys=list(payload.keys()))
    
    maintenance_type = payload.get('maintenance_type', 'cleanup')
    
    # TODO: Implement actual maintenance logic
    
    result = {
        'maintenance_type': maintenance_type,
        'completed': True,
        'duration_seconds': 30
    }
    
    if maintenance_type == 'cleanup':
        result.update({
            'cleaned_records': 1250,
            'freed_space_mb': 45.7
        })
    elif maintenance_type == 'health_check':
        result.update({
            'services_checked': 5,
            'all_healthy': True,
            'issues_found': []
        })
    
    return result


# Celery signal handlers

@worker_ready.connect
def worker_ready_handler(sender=None, **kwargs):
    """Handle worker ready signal."""
    logger.info("celery_worker_ready", worker_name=sender)
    
    # Initialize database connection
    asyncio.create_task(initialize_worker())


@worker_shutdown.connect
def worker_shutdown_handler(sender=None, **kwargs):
    """Handle worker shutdown signal."""
    logger.info("celery_worker_shutdown", worker_name=sender)
    
    # Cleanup resources
    asyncio.create_task(cleanup_worker())


@task_prerun.connect
def task_prerun_handler(sender=None, task_id=None, task=None, args=None, kwargs=None, **kwds):
    """Handle task prerun signal."""
    logger.debug(
        "task_prerun",
        task_id=task_id,
        task_name=task.name if task else None
    )


@task_postrun.connect
def task_postrun_handler(sender=None, task_id=None, task=None, args=None, kwargs=None, 
                        retval=None, state=None, **kwds):
    """Handle task postrun signal."""
    logger.debug(
        "task_postrun",
        task_id=task_id,
        task_name=task.name if task else None,
        state=state
    )


@task_failure.connect
def task_failure_handler(sender=None, task_id=None, exception=None, traceback=None, einfo=None, **kwds):
    """Handle task failure signal."""
    logger.error(
        "task_failure_signal",
        task_id=task_id,
        exception=str(exception),
        task_name=sender.name if sender else None
    )
    
    # Trigger alarm for task failures
    asyncio.create_task(
        alarm_system.trigger_alarm(
            AlarmEvent(
                alarm_type=AlarmType.SYSTEM_ERROR,
                severity=AlarmSeverity.ERROR,
                title=f"Task Failure: {sender.name if sender else 'Unknown'}",
                description=f"Task {task_id} failed with error: {str(exception)}",
                task_id=task_id,
                component="celery_worker",
                context_data={
                    'task_name': sender.name if sender else None,
                    'exception_type': type(exception).__name__ if exception else None,
                    'exception_message': str(exception) if exception else None
                }
            )
        )
    )


@task_retry.connect
def task_retry_handler(sender=None, task_id=None, reason=None, einfo=None, **kwds):
    """Handle task retry signal."""
    logger.warning(
        "task_retry_signal",
        task_id=task_id,
        reason=str(reason),
        task_name=sender.name if sender else None
    )


async def initialize_worker():
    """Initialize worker resources."""
    try:
        logger.info("initializing_worker_resources")
        
        # Initialize database
        db_manager.initialize()
        
        # Initialize alarm system
        await alarm_system.trigger_alarm(
            AlarmEvent(
                alarm_type=AlarmType.SYSTEM_ERROR,
                severity=AlarmSeverity.INFO,
                title="Worker Started",
                description="Celery worker has started successfully",
                component="celery_worker",
                auto_resolve=True
            )
        )
        
        logger.info("worker_initialization_complete")
        
    except Exception as e:
        logger.error("worker_initialization_failed", error=str(e), exc_info=True)


async def cleanup_worker():
    """Cleanup worker resources."""
    try:
        logger.info("cleaning_up_worker_resources")
        
        # Close database connections
        if db_manager._initialized:
            db_manager.close()
        
        logger.info("worker_cleanup_complete")
        
    except Exception as e:
        logger.error("worker_cleanup_failed", error=str(e), exc_info=True)


# Health check task
@app.task(bind=True)
def health_check(self) -> Dict[str, Any]:
    """Health check task for monitoring worker status."""
    return {
        'worker_id': self.request.id,
        'status': 'healthy',
        'timestamp': datetime.now(timezone.utc).isoformat(),
        'registered_processors': list(TASK_PROCESSORS.keys())
    }


if __name__ == '__main__':
    # Run worker
    app.start()