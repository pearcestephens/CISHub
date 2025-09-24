"""
Advanced alarm system for CISHub with system shutdown capabilities.
Monitors queue health and triggers alarms with various notification channels.
"""
import asyncio
import json
import smtplib
from datetime import datetime, timezone, timedelta
from typing import Dict, List, Optional, Any, Callable, Set
from enum import Enum
from dataclasses import dataclass, asdict
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
import httpx
from sqlalchemy import select, and_
from sqlalchemy.ext.asyncio import AsyncSession
from cishub.config.settings import settings
from cishub.config.logging import LoggerMixin, log_performance
from cishub.core.models import SystemAlarm, AlarmSeverity, SystemStatus
from cishub.core.queue_manager import QueueHealth
from cishub.utils.database import db_manager, BaseRepository


class AlarmType(str, Enum):
    """Types of system alarms."""
    QUEUE_BACKUP = "queue_backup"
    HIGH_ERROR_RATE = "high_error_rate"
    PROCESSING_TIMEOUT = "processing_timeout"
    OVERDUE_TASKS = "overdue_tasks"
    SYSTEM_ERROR = "system_error"
    DATABASE_ERROR = "database_error"
    REDIS_ERROR = "redis_error"
    HTTP_ERROR = "http_error"
    RESOURCE_EXHAUSTION = "resource_exhaustion"
    SYSTEM_SHUTDOWN = "system_shutdown"


@dataclass
class AlarmConfig:
    """Configuration for alarm thresholds and behaviors."""
    queue_backup_threshold: int = 100
    error_rate_threshold: float = 10.0
    processing_timeout_threshold: int = 300
    resource_cpu_threshold: float = 80.0
    resource_memory_threshold: float = 85.0
    resource_disk_threshold: float = 90.0
    consecutive_failures_threshold: int = 5
    alarm_cooldown_seconds: int = 300
    critical_alarm_shutdown: bool = True
    auto_recovery_enabled: bool = True


@dataclass
class AlarmEvent:
    """Represents an alarm event."""
    alarm_type: AlarmType
    severity: AlarmSeverity
    title: str
    description: str
    queue_name: Optional[str] = None
    task_id: Optional[str] = None
    component: Optional[str] = None
    context_data: Optional[Dict[str, Any]] = None
    tags: Optional[Dict[str, str]] = None
    auto_resolve: bool = False
    requires_acknowledgment: bool = False


class NotificationChannel:
    """Base class for notification channels."""
    
    def __init__(self, name: str):
        self.name = name
        self.logger = None
    
    async def send_notification(self, alarm_event: AlarmEvent, alarm_id: int) -> bool:
        """Send notification for alarm event."""
        raise NotImplementedError
    
    def set_logger(self, logger):
        """Set logger for the channel."""
        self.logger = logger


class SlackNotificationChannel(NotificationChannel):
    """Slack webhook notification channel."""
    
    def __init__(self, webhook_url: str):
        super().__init__("slack")
        self.webhook_url = webhook_url
    
    async def send_notification(self, alarm_event: AlarmEvent, alarm_id: int) -> bool:
        """Send notification to Slack."""
        if not self.webhook_url:
            return False
        
        try:
            # Build Slack message
            color = self._get_color(alarm_event.severity)
            
            message = {
                "attachments": [
                    {
                        "color": color,
                        "title": f"🚨 CISHub Alarm: {alarm_event.title}",
                        "text": alarm_event.description,
                        "fields": [
                            {"title": "Alarm ID", "value": str(alarm_id), "short": True},
                            {"title": "Severity", "value": alarm_event.severity.value.upper(), "short": True},
                            {"title": "Type", "value": alarm_event.alarm_type.value, "short": True},
                            {"title": "Timestamp", "value": datetime.now(timezone.utc).isoformat(), "short": True}
                        ]
                    }
                ]
            }
            
            # Add optional fields
            if alarm_event.queue_name:
                message["attachments"][0]["fields"].append({
                    "title": "Queue", "value": alarm_event.queue_name, "short": True
                })
            
            if alarm_event.component:
                message["attachments"][0]["fields"].append({
                    "title": "Component", "value": alarm_event.component, "short": True
                })
            
            # Add context data if available
            if alarm_event.context_data:
                context_text = "\n".join([f"• {k}: {v}" for k, v in alarm_event.context_data.items()][:5])
                message["attachments"][0]["fields"].append({
                    "title": "Context", "value": context_text, "short": False
                })
            
            # Send to Slack
            async with httpx.AsyncClient() as client:
                response = await client.post(
                    self.webhook_url,
                    json=message,
                    timeout=10.0
                )
                response.raise_for_status()
            
            if self.logger:
                self.logger.log_operation("slack_notification_sent", alarm_id=alarm_id)
            
            return True
            
        except Exception as e:
            if self.logger:
                self.logger.log_error(e, "slack_notification_failed", alarm_id=alarm_id)
            return False
    
    def _get_color(self, severity: AlarmSeverity) -> str:
        """Get color for Slack message based on severity."""
        color_map = {
            AlarmSeverity.INFO: "good",
            AlarmSeverity.WARNING: "warning",
            AlarmSeverity.ERROR: "danger",
            AlarmSeverity.CRITICAL: "#ff0000"
        }
        return color_map.get(severity, "warning")


class EmailNotificationChannel(NotificationChannel):
    """Email notification channel."""
    
    def __init__(self, smtp_host: str, smtp_port: int, username: str, password: str, recipients: List[str]):
        super().__init__("email")
        self.smtp_host = smtp_host
        self.smtp_port = smtp_port
        self.username = username
        self.password = password
        self.recipients = recipients
    
    async def send_notification(self, alarm_event: AlarmEvent, alarm_id: int) -> bool:
        """Send email notification."""
        if not self.recipients or not self.username or not self.password:
            return False
        
        try:
            # Create email message
            msg = MIMEMultipart()
            msg['From'] = self.username
            msg['To'] = ', '.join(self.recipients)
            msg['Subject'] = f"CISHub Alarm: {alarm_event.title}"
            
            # Build email body
            body = self._build_email_body(alarm_event, alarm_id)
            msg.attach(MIMEText(body, 'html'))
            
            # Send email
            await asyncio.get_event_loop().run_in_executor(
                None, self._send_smtp_email, msg
            )
            
            if self.logger:
                self.logger.log_operation("email_notification_sent", alarm_id=alarm_id, recipients=len(self.recipients))
            
            return True
            
        except Exception as e:
            if self.logger:
                self.logger.log_error(e, "email_notification_failed", alarm_id=alarm_id)
            return False
    
    def _send_smtp_email(self, msg: MIMEMultipart) -> None:
        """Send email via SMTP."""
        server = smtplib.SMTP(self.smtp_host, self.smtp_port)
        server.starttls()
        server.login(self.username, self.password)
        server.send_message(msg)
        server.quit()
    
    def _build_email_body(self, alarm_event: AlarmEvent, alarm_id: int) -> str:
        """Build HTML email body."""
        severity_color = self._get_severity_color(alarm_event.severity)
        
        html = f"""
        <html>
        <body>
            <h2 style="color: {severity_color};">🚨 CISHub System Alarm</h2>
            
            <table border="1" style="border-collapse: collapse; width: 100%;">
                <tr><td><strong>Alarm ID</strong></td><td>{alarm_id}</td></tr>
                <tr><td><strong>Title</strong></td><td>{alarm_event.title}</td></tr>
                <tr><td><strong>Severity</strong></td><td style="color: {severity_color};">{alarm_event.severity.value.upper()}</td></tr>
                <tr><td><strong>Type</strong></td><td>{alarm_event.alarm_type.value}</td></tr>
                <tr><td><strong>Timestamp</strong></td><td>{datetime.now(timezone.utc).isoformat()}</td></tr>
        """
        
        if alarm_event.queue_name:
            html += f"<tr><td><strong>Queue</strong></td><td>{alarm_event.queue_name}</td></tr>"
        
        if alarm_event.component:
            html += f"<tr><td><strong>Component</strong></td><td>{alarm_event.component}</td></tr>"
        
        html += "</table>"
        
        html += f"<h3>Description</h3><p>{alarm_event.description}</p>"
        
        if alarm_event.context_data:
            html += "<h3>Context Data</h3><ul>"
            for key, value in alarm_event.context_data.items():
                html += f"<li><strong>{key}</strong>: {value}</li>"
            html += "</ul>"
        
        html += """
            <hr>
            <p><em>This is an automated alert from CISHub system monitoring.</em></p>
        </body>
        </html>
        """
        
        return html
    
    def _get_severity_color(self, severity: AlarmSeverity) -> str:
        """Get color for severity."""
        color_map = {
            AlarmSeverity.INFO: "#0066cc",
            AlarmSeverity.WARNING: "#ff9900",
            AlarmSeverity.ERROR: "#cc0000",
            AlarmSeverity.CRITICAL: "#990000"
        }
        return color_map.get(severity, "#666666")


class SystemShutdownHandler(LoggerMixin):
    """Handles system shutdown in response to critical alarms."""
    
    def __init__(self):
        super().__init__()
        self.shutdown_callbacks: List[Callable] = []
        self.shutdown_in_progress = False
    
    def add_shutdown_callback(self, callback: Callable) -> None:
        """Add a callback to execute during shutdown."""
        self.shutdown_callbacks.append(callback)
    
    async def trigger_emergency_shutdown(self, alarm_event: AlarmEvent, reason: str) -> None:
        """Trigger emergency system shutdown."""
        if self.shutdown_in_progress:
            self.log_warning("shutdown_already_in_progress")
            return
        
        self.shutdown_in_progress = True
        self.log_operation(
            "emergency_shutdown_triggered",
            alarm_type=alarm_event.alarm_type.value,
            reason=reason
        )
        
        try:
            # Update system status
            await self._update_system_status(reason)
            
            # Execute shutdown callbacks
            for callback in self.shutdown_callbacks:
                try:
                    if asyncio.iscoroutinefunction(callback):
                        await callback(alarm_event, reason)
                    else:
                        callback(alarm_event, reason)
                except Exception as e:
                    self.log_error(e, "shutdown_callback_failed")
            
            self.log_operation("emergency_shutdown_completed")
            
        except Exception as e:
            self.log_error(e, "emergency_shutdown_failed")
            raise
    
    async def _update_system_status(self, reason: str) -> None:
        """Update system status to indicate shutdown."""
        async with db_manager.get_async_session() as session:
            try:
                # Get or create system status
                status_query = select(SystemStatus).limit(1)
                result = await session.execute(status_query)
                status = result.scalar_one_or_none()
                
                if not status:
                    status = SystemStatus()
                    session.add(status)
                
                # Update status
                status.is_operational = False
                status.shutdown_requested = True
                status.shutdown_reason = reason
                status.overall_health = "critical"
                status.last_updated = datetime.now(timezone.utc)
                
                await session.commit()
                
            except Exception as e:
                self.log_error(e, "update_system_status_failed")


class AlarmRepository(BaseRepository):
    """Repository for alarm operations."""
    
    def __init__(self):
        super().__init__(SystemAlarm)
    
    async def get_active_alarms(self, session: AsyncSession) -> List[SystemAlarm]:
        """Get all active alarms."""
        query = select(SystemAlarm).where(SystemAlarm.is_active == True)
        result = await session.execute(query)
        return result.scalars().all()
    
    async def get_recent_alarm(self, session: AsyncSession, alarm_type: AlarmType, minutes: int = 5) -> Optional[SystemAlarm]:
        """Get recent alarm of specific type."""
        cutoff_time = datetime.now(timezone.utc) - timedelta(minutes=minutes)
        query = select(SystemAlarm).where(
            and_(
                SystemAlarm.alarm_type == alarm_type.value,
                SystemAlarm.triggered_at > cutoff_time
            )
        ).order_by(SystemAlarm.triggered_at.desc()).limit(1)
        
        result = await session.execute(query)
        return result.scalar_one_or_none()
    
    async def resolve_alarm(self, session: AsyncSession, alarm_id: int) -> bool:
        """Resolve an alarm."""
        alarm = await self.get_by_id(session, alarm_id)
        if not alarm:
            return False
        
        alarm.is_active = False
        alarm.resolved_at = datetime.now(timezone.utc)
        await session.flush()
        return True


class AlarmSystem(LoggerMixin):
    """Main alarm system coordinating monitoring, notifications, and responses."""
    
    def __init__(self, config: Optional[AlarmConfig] = None):
        super().__init__()
        self.config = config or AlarmConfig()
        self.alarm_repo = AlarmRepository()
        self.notification_channels: List[NotificationChannel] = []
        self.shutdown_handler = SystemShutdownHandler()
        self.consecutive_failures: Dict[str, int] = {}
        self.last_alarm_times: Dict[str, datetime] = {}
        self._setup_notification_channels()
    
    def _setup_notification_channels(self) -> None:
        """Setup notification channels based on configuration."""
        # Setup Slack channel
        if settings.alerts.slack_webhook_url:
            slack_channel = SlackNotificationChannel(settings.alerts.slack_webhook_url)
            slack_channel.set_logger(self)
            self.notification_channels.append(slack_channel)
        
        # Setup email channel
        if (settings.alerts.email_username and 
            settings.alerts.email_password and 
            settings.alerts.email_recipients):
            email_channel = EmailNotificationChannel(
                settings.alerts.email_smtp_host,
                settings.alerts.email_smtp_port,
                settings.alerts.email_username,
                settings.alerts.email_password,
                settings.alerts.email_recipients
            )
            email_channel.set_logger(self)
            self.notification_channels.append(email_channel)
    
    async def process_queue_health(self, queue_health: QueueHealth) -> None:
        """Process queue health and trigger alarms if needed."""
        if not queue_health.is_healthy:
            for issue in queue_health.issues:
                await self._analyze_and_trigger_alarm(queue_health, issue)
        else:
            # Reset consecutive failures for this queue
            self.consecutive_failures.pop(queue_health.queue_name, None)
    
    async def _analyze_and_trigger_alarm(self, queue_health: QueueHealth, issue: str) -> None:
        """Analyze issue and trigger appropriate alarm."""
        try:
            # Determine alarm type and severity
            alarm_event = self._create_alarm_event(queue_health, issue)
            
            if not alarm_event:
                return
            
            # Check if we should trigger this alarm (cooldown logic)
            if not self._should_trigger_alarm(alarm_event):
                return
            
            # Update consecutive failures
            self.consecutive_failures[queue_health.queue_name] = \
                self.consecutive_failures.get(queue_health.queue_name, 0) + 1
            
            # Check if this warrants critical escalation
            if self.consecutive_failures[queue_health.queue_name] >= self.config.consecutive_failures_threshold:
                alarm_event.severity = AlarmSeverity.CRITICAL
                alarm_event.title = f"CRITICAL: {alarm_event.title}"
                alarm_event.description += f"\n\nConsecutive failures: {self.consecutive_failures[queue_health.queue_name]}"
            
            # Trigger the alarm
            await self.trigger_alarm(alarm_event)
            
        except Exception as e:
            self.log_error(e, "analyze_and_trigger_alarm_failed", queue=queue_health.queue_name, issue=issue)
    
    def _create_alarm_event(self, queue_health: QueueHealth, issue: str) -> Optional[AlarmEvent]:
        """Create alarm event based on queue health issue."""
        if "backup" in issue.lower():
            return AlarmEvent(
                alarm_type=AlarmType.QUEUE_BACKUP,
                severity=AlarmSeverity.WARNING,
                title=f"Queue Backup Detected: {queue_health.queue_name}",
                description=f"Queue '{queue_health.queue_name}' has excessive pending tasks. {issue}",
                queue_name=queue_health.queue_name,
                context_data={
                    "pending_count": queue_health.pending_count,
                    "processing_count": queue_health.processing_count,
                    "threshold": self.config.queue_backup_threshold
                }
            )
        
        elif "error rate" in issue.lower():
            return AlarmEvent(
                alarm_type=AlarmType.HIGH_ERROR_RATE,
                severity=AlarmSeverity.ERROR,
                title=f"High Error Rate: {queue_health.queue_name}",
                description=f"Queue '{queue_health.queue_name}' has high error rate. {issue}",
                queue_name=queue_health.queue_name,
                context_data={
                    "error_rate": queue_health.error_rate,
                    "failed_count": queue_health.failed_count,
                    "threshold": self.config.error_rate_threshold
                }
            )
        
        elif "processing" in issue.lower() and "timeout" in issue.lower():
            return AlarmEvent(
                alarm_type=AlarmType.PROCESSING_TIMEOUT,
                severity=AlarmSeverity.ERROR,
                title=f"Processing Timeout: {queue_health.queue_name}",
                description=f"Queue '{queue_health.queue_name}' has processing timeout. {issue}",
                queue_name=queue_health.queue_name,
                context_data={
                    "last_processed_at": queue_health.last_processed_at.isoformat() if queue_health.last_processed_at else None,
                    "timeout_threshold": self.config.processing_timeout_threshold
                }
            )
        
        elif "overdue" in issue.lower():
            return AlarmEvent(
                alarm_type=AlarmType.OVERDUE_TASKS,
                severity=AlarmSeverity.WARNING,
                title=f"Overdue Tasks: {queue_health.queue_name}",
                description=f"Queue '{queue_health.queue_name}' has overdue tasks. {issue}",
                queue_name=queue_health.queue_name,
                context_data={
                    "avg_processing_time": queue_health.avg_processing_time
                }
            )
        
        return None
    
    def _should_trigger_alarm(self, alarm_event: AlarmEvent) -> bool:
        """Check if alarm should be triggered based on cooldown."""
        alarm_key = f"{alarm_event.alarm_type.value}:{alarm_event.queue_name or 'system'}"
        
        now = datetime.now(timezone.utc)
        last_alarm_time = self.last_alarm_times.get(alarm_key)
        
        if last_alarm_time:
            time_since_last = now - last_alarm_time
            if time_since_last.total_seconds() < self.config.alarm_cooldown_seconds:
                return False
        
        self.last_alarm_times[alarm_key] = now
        return True
    
    @log_performance("trigger_alarm")
    async def trigger_alarm(self, alarm_event: AlarmEvent) -> int:
        """Trigger an alarm with notifications and potential system response."""
        self.log_operation(
            "triggering_alarm",
            alarm_type=alarm_event.alarm_type.value,
            severity=alarm_event.severity.value,
            queue=alarm_event.queue_name
        )
        
        # Store alarm in database
        alarm_id = await self._store_alarm(alarm_event)
        
        # Send notifications
        await self._send_notifications(alarm_event, alarm_id)
        
        # Handle critical alarms
        if alarm_event.severity == AlarmSeverity.CRITICAL and self.config.critical_alarm_shutdown:
            await self._handle_critical_alarm(alarm_event)
        
        self.log_operation("alarm_triggered", alarm_id=alarm_id)
        return alarm_id
    
    async def _store_alarm(self, alarm_event: AlarmEvent) -> int:
        """Store alarm in database."""
        async with db_manager.get_async_session() as session:
            # Check for recent similar alarm to update instead of creating new
            recent_alarm = await self.alarm_repo.get_recent_alarm(
                session, alarm_event.alarm_type, minutes=10
            )
            
            if recent_alarm and recent_alarm.is_active:
                # Update existing alarm
                recent_alarm.occurrence_count += 1
                recent_alarm.last_occurrence = datetime.now(timezone.utc)
                recent_alarm.description = alarm_event.description
                recent_alarm.context_data = alarm_event.context_data
                await session.flush()
                return recent_alarm.id
            else:
                # Create new alarm
                alarm = await self.alarm_repo.create(
                    session,
                    alarm_type=alarm_event.alarm_type.value,
                    severity=alarm_event.severity.value,
                    title=alarm_event.title,
                    description=alarm_event.description,
                    component=alarm_event.component,
                    context_data=alarm_event.context_data,
                    tags=alarm_event.tags,
                    is_active=True,
                    acknowledged=False
                )
                return alarm.id
    
    async def _send_notifications(self, alarm_event: AlarmEvent, alarm_id: int) -> None:
        """Send notifications through all configured channels."""
        notification_tasks = [
            channel.send_notification(alarm_event, alarm_id)
            for channel in self.notification_channels
        ]
        
        if notification_tasks:
            results = await asyncio.gather(*notification_tasks, return_exceptions=True)
            
            success_count = sum(1 for result in results if result is True)
            self.log_operation(
                "notifications_sent",
                alarm_id=alarm_id,
                total_channels=len(notification_tasks),
                successful=success_count
            )
    
    async def _handle_critical_alarm(self, alarm_event: AlarmEvent) -> None:
        """Handle critical alarms that may require system shutdown."""
        self.log_operation(
            "handling_critical_alarm",
            alarm_type=alarm_event.alarm_type.value,
            queue=alarm_event.queue_name
        )
        
        # Define which alarm types should trigger shutdown
        shutdown_alarm_types = {
            AlarmType.HIGH_ERROR_RATE,
            AlarmType.PROCESSING_TIMEOUT,
            AlarmType.SYSTEM_ERROR,
            AlarmType.DATABASE_ERROR,
            AlarmType.RESOURCE_EXHAUSTION
        }
        
        if alarm_event.alarm_type in shutdown_alarm_types:
            await self.shutdown_handler.trigger_emergency_shutdown(
                alarm_event,
                f"Critical alarm triggered: {alarm_event.title}"
            )
    
    async def resolve_alarm(self, alarm_id: int, resolved_by: str = None) -> bool:
        """Resolve an alarm."""
        async with db_manager.get_async_session() as session:
            success = await self.alarm_repo.resolve_alarm(session, alarm_id)
            if success:
                self.log_operation("alarm_resolved", alarm_id=alarm_id, resolved_by=resolved_by)
            return success
    
    async def acknowledge_alarm(self, alarm_id: int, acknowledged_by: str) -> bool:
        """Acknowledge an alarm."""
        async with db_manager.get_async_session() as session:
            alarm = await self.alarm_repo.get_by_id(session, alarm_id)
            if not alarm:
                return False
            
            alarm.acknowledged = True
            alarm.acknowledged_by = acknowledged_by
            alarm.acknowledged_at = datetime.now(timezone.utc)
            await session.flush()
            
            self.log_operation("alarm_acknowledged", alarm_id=alarm_id, acknowledged_by=acknowledged_by)
            return True
    
    async def get_active_alarms(self) -> List[Dict[str, Any]]:
        """Get all active alarms."""
        async with db_manager.get_async_session() as session:
            alarms = await self.alarm_repo.get_active_alarms(session)
            return [
                {
                    'id': alarm.id,
                    'alarm_type': alarm.alarm_type,
                    'severity': alarm.severity,
                    'title': alarm.title,
                    'description': alarm.description,
                    'component': alarm.component,
                    'is_active': alarm.is_active,
                    'acknowledged': alarm.acknowledged,
                    'acknowledged_by': alarm.acknowledged_by,
                    'triggered_at': alarm.triggered_at.isoformat() if alarm.triggered_at else None,
                    'occurrence_count': alarm.occurrence_count,
                    'context_data': alarm.context_data,
                    'tags': alarm.tags
                }
                for alarm in alarms
            ]
    
    def add_shutdown_callback(self, callback: Callable) -> None:
        """Add callback for system shutdown events."""
        self.shutdown_handler.add_shutdown_callback(callback)


# Global alarm system instance
alarm_system = AlarmSystem()