"""
Comprehensive health monitoring system for CISHub.
Monitors all system components and provides health status reporting.
"""
import asyncio
import psutil
import time
from datetime import datetime, timezone, timedelta
from typing import Dict, List, Optional, Any, Tuple
from dataclasses import dataclass
from enum import Enum
import httpx
import redis.asyncio as redis
import sqlalchemy
from sqlalchemy import text
from cishub.config.settings import settings
from cishub.config.logging import LoggerMixin, log_performance
from cishub.core.models import SystemStatus
from cishub.utils.database import db_manager
from cishub.monitoring.alarm_system import alarm_system, AlarmEvent, AlarmType, AlarmSeverity


class HealthStatus(str, Enum):
    """Health status levels."""
    HEALTHY = "healthy"
    DEGRADED = "degraded"
    CRITICAL = "critical"
    UNKNOWN = "unknown"


@dataclass
class ComponentHealth:
    """Health status of a system component."""
    name: str
    status: HealthStatus
    response_time_ms: Optional[float] = None
    last_check: Optional[datetime] = None
    error_message: Optional[str] = None
    details: Optional[Dict[str, Any]] = None
    
    def to_dict(self) -> Dict[str, Any]:
        return {
            'name': self.name,
            'status': self.status.value,
            'response_time_ms': self.response_time_ms,
            'last_check': self.last_check.isoformat() if self.last_check else None,
            'error_message': self.error_message,
            'details': self.details or {}
        }


@dataclass
class SystemHealthReport:
    """Overall system health report."""
    overall_status: HealthStatus
    components: List[ComponentHealth]
    timestamp: datetime
    uptime_seconds: float
    total_checks: int
    healthy_components: int
    degraded_components: int
    critical_components: int
    system_metrics: Dict[str, Any]
    
    def to_dict(self) -> Dict[str, Any]:
        return {
            'overall_status': self.overall_status.value,
            'components': [comp.to_dict() for comp in self.components],
            'timestamp': self.timestamp.isoformat(),
            'uptime_seconds': self.uptime_seconds,
            'summary': {
                'total_checks': self.total_checks,
                'healthy_components': self.healthy_components,
                'degraded_components': self.degraded_components,
                'critical_components': self.critical_components
            },
            'system_metrics': self.system_metrics
        }


class DatabaseHealthChecker(LoggerMixin):
    """Database health checker."""
    
    async def check_health(self) -> ComponentHealth:
        """Check database health."""
        start_time = time.time()
        
        try:
            async with db_manager.get_async_session() as session:
                # Test basic connectivity
                await session.execute(text("SELECT 1"))
                
                # Test table access
                from cishub.core.models import Queue
                from sqlalchemy import select, func
                
                count_query = select(func.count(Queue.id))
                result = await session.execute(count_query)
                queue_count = result.scalar()
                
                response_time = (time.time() - start_time) * 1000
                
                # Get connection pool info
                connection_info = await db_manager.get_connection_info()
                
                return ComponentHealth(
                    name="database",
                    status=HealthStatus.HEALTHY,
                    response_time_ms=response_time,
                    last_check=datetime.now(timezone.utc),
                    details={
                        'queue_count': queue_count,
                        'connection_pool': connection_info
                    }
                )
                
        except Exception as e:
            response_time = (time.time() - start_time) * 1000
            self.log_error(e, "database_health_check_failed")
            
            return ComponentHealth(
                name="database",
                status=HealthStatus.CRITICAL,
                response_time_ms=response_time,
                last_check=datetime.now(timezone.utc),
                error_message=str(e)
            )


class RedisHealthChecker(LoggerMixin):
    """Redis health checker."""
    
    async def check_health(self) -> ComponentHealth:
        """Check Redis health."""
        start_time = time.time()
        
        try:
            redis_client = redis.from_url(settings.redis.url)
            
            # Test basic connectivity
            await redis_client.ping()
            
            # Test read/write operations
            test_key = "health_check_test"
            test_value = str(int(time.time()))
            
            await redis_client.set(test_key, test_value, ex=10)
            retrieved_value = await redis_client.get(test_key)
            
            if retrieved_value.decode() != test_value:
                raise Exception("Redis read/write test failed")
            
            # Get Redis info
            info = await redis_client.info()
            
            response_time = (time.time() - start_time) * 1000
            
            await redis_client.aclose()
            
            return ComponentHealth(
                name="redis",
                status=HealthStatus.HEALTHY,
                response_time_ms=response_time,
                last_check=datetime.now(timezone.utc),
                details={
                    'connected_clients': info.get('connected_clients', 0),
                    'used_memory_human': info.get('used_memory_human', 'unknown'),
                    'uptime_in_seconds': info.get('uptime_in_seconds', 0)
                }
            )
            
        except Exception as e:
            response_time = (time.time() - start_time) * 1000
            self.log_error(e, "redis_health_check_failed")
            
            return ComponentHealth(
                name="redis",
                status=HealthStatus.CRITICAL,
                response_time_ms=response_time,
                last_check=datetime.now(timezone.utc),
                error_message=str(e)
            )


class CeleryHealthChecker(LoggerMixin):
    """Celery health checker."""
    
    async def check_health(self) -> ComponentHealth:
        """Check Celery health."""
        start_time = time.time()
        
        try:
            from cishub.core.worker import app
            
            # Get active workers
            inspect = app.control.inspect()
            active_workers = inspect.active()
            registered_tasks = inspect.registered()
            
            if not active_workers:
                return ComponentHealth(
                    name="celery",
                    status=HealthStatus.CRITICAL,
                    response_time_ms=(time.time() - start_time) * 1000,
                    last_check=datetime.now(timezone.utc),
                    error_message="No active Celery workers found"
                )
            
            # Test task submission (if workers are available)
            total_workers = len(active_workers)
            total_registered_tasks = sum(len(tasks) for tasks in registered_tasks.values()) if registered_tasks else 0
            
            response_time = (time.time() - start_time) * 1000
            
            return ComponentHealth(
                name="celery",
                status=HealthStatus.HEALTHY,
                response_time_ms=response_time,
                last_check=datetime.now(timezone.utc),
                details={
                    'active_workers': total_workers,
                    'registered_tasks': total_registered_tasks,
                    'worker_names': list(active_workers.keys()) if active_workers else []
                }
            )
            
        except Exception as e:
            response_time = (time.time() - start_time) * 1000
            self.log_error(e, "celery_health_check_failed")
            
            return ComponentHealth(
                name="celery",
                status=HealthStatus.CRITICAL,
                response_time_ms=response_time,
                last_check=datetime.now(timezone.utc),
                error_message=str(e)
            )


class SystemResourceChecker(LoggerMixin):
    """System resource health checker."""
    
    async def check_health(self) -> ComponentHealth:
        """Check system resource health."""
        start_time = time.time()
        
        try:
            # Get CPU usage
            cpu_percent = psutil.cpu_percent(interval=1)
            
            # Get memory usage
            memory = psutil.virtual_memory()
            memory_percent = memory.percent
            
            # Get disk usage
            disk = psutil.disk_usage('/')
            disk_percent = (disk.used / disk.total) * 100
            
            # Get network stats
            network = psutil.net_io_counters()
            
            # Get process count
            process_count = len(psutil.pids())
            
            # Determine health status
            status = HealthStatus.HEALTHY
            issues = []
            
            if cpu_percent > settings.monitoring.cpu_threshold:
                status = HealthStatus.DEGRADED if cpu_percent < 95 else HealthStatus.CRITICAL
                issues.append(f"High CPU usage: {cpu_percent:.1f}%")
            
            if memory_percent > settings.monitoring.memory_threshold:
                status = HealthStatus.DEGRADED if memory_percent < 95 else HealthStatus.CRITICAL
                issues.append(f"High memory usage: {memory_percent:.1f}%")
            
            if disk_percent > settings.monitoring.disk_threshold:
                status = HealthStatus.DEGRADED if disk_percent < 98 else HealthStatus.CRITICAL
                issues.append(f"High disk usage: {disk_percent:.1f}%")
            
            response_time = (time.time() - start_time) * 1000
            
            return ComponentHealth(
                name="system_resources",
                status=status,
                response_time_ms=response_time,
                last_check=datetime.now(timezone.utc),
                error_message="; ".join(issues) if issues else None,
                details={
                    'cpu_percent': cpu_percent,
                    'memory_percent': memory_percent,
                    'memory_available_gb': memory.available / (1024**3),
                    'disk_percent': disk_percent,
                    'disk_free_gb': disk.free / (1024**3),
                    'network_bytes_sent': network.bytes_sent,
                    'network_bytes_recv': network.bytes_recv,
                    'process_count': process_count
                }
            )
            
        except Exception as e:
            response_time = (time.time() - start_time) * 1000
            self.log_error(e, "system_resource_check_failed")
            
            return ComponentHealth(
                name="system_resources",
                status=HealthStatus.CRITICAL,
                response_time_ms=response_time,
                last_check=datetime.now(timezone.utc),
                error_message=str(e)
            )


class ExternalServiceChecker(LoggerMixin):
    """External service health checker."""
    
    async def check_health(self) -> ComponentHealth:
        """Check external service health."""
        start_time = time.time()
        
        try:
            # Check Lightspeed API if configured
            if not settings.external.lightspeed_api_url:
                return ComponentHealth(
                    name="external_services",
                    status=HealthStatus.UNKNOWN,
                    response_time_ms=0,
                    last_check=datetime.now(timezone.utc),
                    error_message="No external services configured"
                )
            
            async with httpx.AsyncClient(timeout=30.0) as client:
                # Test basic connectivity to Lightspeed API
                response = await client.get(
                    f"{settings.external.lightspeed_api_url}/",
                    headers={'User-Agent': 'CISHub-HealthCheck/1.0'}
                )
                
                response_time = (time.time() - start_time) * 1000
                
                # Determine status based on response
                if response.status_code < 400:
                    status = HealthStatus.HEALTHY
                    error_message = None
                elif response.status_code < 500:
                    status = HealthStatus.DEGRADED
                    error_message = f"Client error: {response.status_code}"
                else:
                    status = HealthStatus.CRITICAL
                    error_message = f"Server error: {response.status_code}"
                
                return ComponentHealth(
                    name="external_services",
                    status=status,
                    response_time_ms=response_time,
                    last_check=datetime.now(timezone.utc),
                    error_message=error_message,
                    details={
                        'lightspeed_api_status': response.status_code,
                        'lightspeed_api_url': settings.external.lightspeed_api_url
                    }
                )
                
        except Exception as e:
            response_time = (time.time() - start_time) * 1000
            self.log_error(e, "external_service_check_failed")
            
            return ComponentHealth(
                name="external_services",
                status=HealthStatus.CRITICAL,
                response_time_ms=response_time,
                last_check=datetime.now(timezone.utc),
                error_message=str(e)
            )


class HealthMonitor(LoggerMixin):
    """Main health monitoring system."""
    
    def __init__(self):
        super().__init__()
        self.checkers = [
            DatabaseHealthChecker(),
            RedisHealthChecker(),
            CeleryHealthChecker(),
            SystemResourceChecker(),
            ExternalServiceChecker()
        ]
        self.start_time = time.time()
        self.check_count = 0
        self._monitoring_task = None
        self._last_report = None
    
    @log_performance("health_check")
    async def perform_health_check(self) -> SystemHealthReport:
        """Perform comprehensive health check."""
        self.log_operation("performing_health_check")
        self.check_count += 1
        
        # Run all health checks concurrently
        check_tasks = [checker.check_health() for checker in self.checkers]
        component_healths = await asyncio.gather(*check_tasks, return_exceptions=True)
        
        # Process results
        components = []
        for i, result in enumerate(component_healths):
            if isinstance(result, Exception):
                self.log_error(result, f"health_check_failed", checker=self.checkers[i].__class__.__name__)
                components.append(ComponentHealth(
                    name=f"checker_{i}",
                    status=HealthStatus.CRITICAL,
                    last_check=datetime.now(timezone.utc),
                    error_message=str(result)
                ))
            else:
                components.append(result)
        
        # Calculate overall status
        overall_status = self._calculate_overall_status(components)
        
        # Count component statuses
        healthy_count = sum(1 for c in components if c.status == HealthStatus.HEALTHY)
        degraded_count = sum(1 for c in components if c.status == HealthStatus.DEGRADED)
        critical_count = sum(1 for c in components if c.status == HealthStatus.CRITICAL)
        
        # Get system metrics
        uptime = time.time() - self.start_time
        system_metrics = await self._get_system_metrics()
        
        # Create health report
        report = SystemHealthReport(
            overall_status=overall_status,
            components=components,
            timestamp=datetime.now(timezone.utc),
            uptime_seconds=uptime,
            total_checks=len(components),
            healthy_components=healthy_count,
            degraded_components=degraded_count,
            critical_components=critical_count,
            system_metrics=system_metrics
        )
        
        # Store report
        self._last_report = report
        
        # Update system status in database
        await self._update_system_status(report)
        
        # Check for alerts
        await self._check_for_alerts(report)
        
        self.log_operation(
            "health_check_completed",
            overall_status=overall_status.value,
            healthy=healthy_count,
            degraded=degraded_count,
            critical=critical_count
        )
        
        return report
    
    def _calculate_overall_status(self, components: List[ComponentHealth]) -> HealthStatus:
        """Calculate overall system status."""
        if any(c.status == HealthStatus.CRITICAL for c in components):
            return HealthStatus.CRITICAL
        elif any(c.status == HealthStatus.DEGRADED for c in components):
            return HealthStatus.DEGRADED
        elif all(c.status == HealthStatus.HEALTHY for c in components):
            return HealthStatus.HEALTHY
        else:
            return HealthStatus.UNKNOWN
    
    async def _get_system_metrics(self) -> Dict[str, Any]:
        """Get additional system metrics."""
        try:
            # Get basic system info
            cpu_count = psutil.cpu_count()
            memory_total = psutil.virtual_memory().total
            disk_total = psutil.disk_usage('/').total
            
            # Get load average (Unix-like systems)
            try:
                load_avg = psutil.getloadavg()
            except (AttributeError, OSError):
                load_avg = [0.0, 0.0, 0.0]
            
            return {
                'cpu_count': cpu_count,
                'memory_total_gb': memory_total / (1024**3),
                'disk_total_gb': disk_total / (1024**3),
                'load_average_1m': load_avg[0],
                'load_average_5m': load_avg[1],
                'load_average_15m': load_avg[2],
                'python_version': f"{psutil.sys.version_info.major}.{psutil.sys.version_info.minor}",
                'platform': psutil.sys.platform
            }
        except Exception as e:
            self.log_error(e, "get_system_metrics_failed")
            return {}
    
    async def _update_system_status(self, report: SystemHealthReport) -> None:
        """Update system status in database."""
        try:
            async with db_manager.get_async_session() as session:
                from sqlalchemy import select
                
                # Get or create system status record
                query = select(SystemStatus).limit(1)
                result = await session.execute(query)
                status = result.scalar_one_or_none()
                
                if not status:
                    status = SystemStatus()
                    session.add(status)
                
                # Update status fields
                status.overall_health = report.overall_status.value
                status.last_health_check = report.timestamp
                status.last_updated = report.timestamp
                
                # Update component health
                for component in report.components:
                    if component.name == "database":
                        status.database_health = component.status.value
                    elif component.name == "redis":
                        status.redis_health = component.status.value
                    elif component.name == "celery":
                        status.queue_health = component.status.value
                
                # Set operational status
                status.is_operational = report.overall_status in [HealthStatus.HEALTHY, HealthStatus.DEGRADED]
                
                await session.commit()
                
        except Exception as e:
            self.log_error(e, "update_system_status_failed")
    
    async def _check_for_alerts(self, report: SystemHealthReport) -> None:
        """Check if any alerts should be triggered."""
        try:
            # Check for critical components
            critical_components = [c for c in report.components if c.status == HealthStatus.CRITICAL]
            
            for component in critical_components:
                await alarm_system.trigger_alarm(
                    AlarmEvent(
                        alarm_type=AlarmType.SYSTEM_ERROR,
                        severity=AlarmSeverity.CRITICAL,
                        title=f"Critical Component Failure: {component.name}",
                        description=f"Component '{component.name}' is in critical state: {component.error_message}",
                        component=component.name,
                        context_data={
                            'component_name': component.name,
                            'error_message': component.error_message,
                            'response_time_ms': component.response_time_ms,
                            'details': component.details
                        }
                    )
                )
            
            # Check for resource exhaustion
            for component in report.components:
                if component.name == "system_resources" and component.status == HealthStatus.CRITICAL:
                    await alarm_system.trigger_alarm(
                        AlarmEvent(
                            alarm_type=AlarmType.RESOURCE_EXHAUSTION,
                            severity=AlarmSeverity.CRITICAL,
                            title="System Resource Exhaustion",
                            description=f"System resources are critically low: {component.error_message}",
                            component="system_resources",
                            context_data=component.details
                        )
                    )
                    
        except Exception as e:
            self.log_error(e, "check_for_alerts_failed")
    
    async def start_monitoring(self, interval: int = 60) -> None:
        """Start continuous health monitoring."""
        if self._monitoring_task:
            return
        
        self.log_operation("starting_health_monitoring", interval=interval)
        self._monitoring_task = asyncio.create_task(self._monitoring_loop(interval))
    
    async def stop_monitoring(self) -> None:
        """Stop health monitoring."""
        if self._monitoring_task:
            self.log_operation("stopping_health_monitoring")
            self._monitoring_task.cancel()
            try:
                await self._monitoring_task
            except asyncio.CancelledError:
                pass
            self._monitoring_task = None
    
    async def _monitoring_loop(self, interval: int) -> None:
        """Health monitoring loop."""
        while True:
            try:
                await self.perform_health_check()
                await asyncio.sleep(interval)
            except asyncio.CancelledError:
                break
            except Exception as e:
                self.log_error(e, "health_monitoring_loop_error")
                await asyncio.sleep(min(interval, 60))
    
    def get_last_report(self) -> Optional[SystemHealthReport]:
        """Get the last health report."""
        return self._last_report


# Global health monitor instance
health_monitor = HealthMonitor()