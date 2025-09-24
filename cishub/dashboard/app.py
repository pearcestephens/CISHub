"""
FastAPI dashboard application for CISHub monitoring.
Provides real-time monitoring dashboard with WebSocket updates.
"""
import asyncio
import json
from datetime import datetime, timezone
from typing import Dict, List, Any, Optional
from fastapi import FastAPI, Request, WebSocket, WebSocketDisconnect, Depends
from fastapi.templating import Jinja2Templates
from fastapi.staticfiles import StaticFiles
from fastapi.responses import HTMLResponse
from starlette.websockets import WebSocketState
import httpx
from cishub.config.settings import settings
from cishub.config.logging import get_logger, configure_logging
from cishub.monitoring.health_checker import health_monitor
from cishub.core.queue_manager import queue_manager
from cishub.monitoring.alarm_system import alarm_system

# Configure logging
configure_logging()
logger = get_logger(__name__)

# Create FastAPI app
app = FastAPI(
    title="CISHub Dashboard",
    description="Real-time monitoring dashboard for CISHub",
    version="1.0.0"
)

# Setup templates
templates = Jinja2Templates(directory="cishub/dashboard/templates")

# Setup static files (if we had any)
# app.mount("/static", StaticFiles(directory="cishub/dashboard/static"), name="static")


class ConnectionManager:
    """Manages WebSocket connections for real-time updates."""
    
    def __init__(self):
        self.active_connections: List[WebSocket] = []
        self.logger = get_logger(__name__)
    
    async def connect(self, websocket: WebSocket):
        """Accept a new WebSocket connection."""
        await websocket.accept()
        self.active_connections.append(websocket)
        self.logger.info("websocket_connected", total_connections=len(self.active_connections))
    
    def disconnect(self, websocket: WebSocket):
        """Remove a WebSocket connection."""
        if websocket in self.active_connections:
            self.active_connections.remove(websocket)
        self.logger.info("websocket_disconnected", total_connections=len(self.active_connections))
    
    async def send_personal_message(self, message: str, websocket: WebSocket):
        """Send a message to a specific WebSocket."""
        try:
            if websocket.client_state == WebSocketState.CONNECTED:
                await websocket.send_text(message)
        except Exception as e:
            self.logger.error("send_personal_message_failed", error=str(e))
            self.disconnect(websocket)
    
    async def broadcast(self, message: str):
        """Broadcast a message to all connected WebSockets."""
        if not self.active_connections:
            return
        
        disconnected = []
        for connection in self.active_connections:
            try:
                if connection.client_state == WebSocketState.CONNECTED:
                    await connection.send_text(message)
                else:
                    disconnected.append(connection)
            except Exception as e:
                self.logger.error("broadcast_failed", error=str(e))
                disconnected.append(connection)
        
        # Clean up disconnected connections
        for connection in disconnected:
            self.disconnect(connection)
    
    async def broadcast_json(self, data: Dict[str, Any]):
        """Broadcast JSON data to all connected WebSockets."""
        await self.broadcast(json.dumps(data))


# Global connection manager
manager = ConnectionManager()


@app.get("/", response_class=HTMLResponse)
async def dashboard_home(request: Request):
    """Main dashboard page."""
    return templates.TemplateResponse("dashboard.html", {"request": request})


@app.get("/health", response_class=HTMLResponse)
async def health_dashboard(request: Request):
    """Health monitoring dashboard."""
    return templates.TemplateResponse("health.html", {"request": request})


@app.get("/queues", response_class=HTMLResponse)
async def queue_dashboard(request: Request):
    """Queue monitoring dashboard."""
    return templates.TemplateResponse("queues.html", {"request": request})


@app.get("/alarms", response_class=HTMLResponse)
async def alarm_dashboard(request: Request):
    """Alarm monitoring dashboard."""
    return templates.TemplateResponse("alarms.html", {"request": request})


@app.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket):
    """WebSocket endpoint for real-time updates."""
    await manager.connect(websocket)
    
    try:
        while True:
            # Wait for client messages (like subscription requests)
            data = await websocket.receive_text()
            message = json.loads(data)
            
            if message.get("type") == "subscribe":
                # Handle subscription to specific data streams
                subscription_type = message.get("subscription")
                logger.info("websocket_subscription", type=subscription_type)
                
                # Send initial data based on subscription
                if subscription_type == "health":
                    health_data = await get_health_data()
                    await websocket.send_text(json.dumps({
                        "type": "health_update",
                        "data": health_data
                    }))
                elif subscription_type == "queues":
                    queue_data = await get_queue_data()
                    await websocket.send_text(json.dumps({
                        "type": "queue_update",
                        "data": queue_data
                    }))
                elif subscription_type == "alarms":
                    alarm_data = await get_alarm_data()
                    await websocket.send_text(json.dumps({
                        "type": "alarm_update", 
                        "data": alarm_data
                    }))
            
    except WebSocketDisconnect:
        manager.disconnect(websocket)
    except Exception as e:
        logger.error("websocket_error", error=str(e), exc_info=True)
        manager.disconnect(websocket)


async def get_health_data() -> Dict[str, Any]:
    """Get current health data."""
    try:
        report = health_monitor.get_last_report()
        if not report:
            report = await health_monitor.perform_health_check()
        
        return {
            "overall_status": report.overall_status.value,
            "timestamp": report.timestamp.isoformat(),
            "uptime_seconds": report.uptime_seconds,
            "components": [
                {
                    "name": comp.name,
                    "status": comp.status.value,
                    "response_time_ms": comp.response_time_ms,
                    "error_message": comp.error_message,
                    "details": comp.details or {}
                }
                for comp in report.components
            ],
            "summary": {
                "total": report.total_checks,
                "healthy": report.healthy_components,
                "degraded": report.degraded_components,
                "critical": report.critical_components
            },
            "system_metrics": report.system_metrics
        }
    except Exception as e:
        logger.error("get_health_data_failed", error=str(e), exc_info=True)
        return {"error": "Failed to get health data"}


async def get_queue_data() -> Dict[str, Any]:
    """Get current queue data."""
    try:
        # Get list of queues
        queues_data = []
        
        # For demo purposes, check health of default queue
        try:
            health = await queue_manager.get_queue_health("default")
            queues_data.append({
                "name": health.queue_name,
                "is_healthy": health.is_healthy,
                "pending_count": health.pending_count,
                "processing_count": health.processing_count,
                "failed_count": health.failed_count,
                "error_rate": health.error_rate,
                "avg_processing_time": health.avg_processing_time,
                "last_processed_at": health.last_processed_at.isoformat() if health.last_processed_at else None,
                "issues": health.issues
            })
        except Exception as e:
            logger.warning("get_default_queue_health_failed", error=str(e))
        
        return {
            "queues": queues_data,
            "timestamp": datetime.now(timezone.utc).isoformat()
        }
    except Exception as e:
        logger.error("get_queue_data_failed", error=str(e), exc_info=True)
        return {"error": "Failed to get queue data"}


async def get_alarm_data() -> Dict[str, Any]:
    """Get current alarm data."""
    try:
        alarms = await alarm_system.get_active_alarms()
        
        return {
            "alarms": alarms,
            "summary": {
                "total": len(alarms),
                "critical": len([a for a in alarms if a.get("severity") == "critical"]),
                "error": len([a for a in alarms if a.get("severity") == "error"]),
                "warning": len([a for a in alarms if a.get("severity") == "warning"]),
                "unacknowledged": len([a for a in alarms if not a.get("acknowledged")])
            },
            "timestamp": datetime.now(timezone.utc).isoformat()
        }
    except Exception as e:
        logger.error("get_alarm_data_failed", error=str(e), exc_info=True)
        return {"error": "Failed to get alarm data"}


# Background task to broadcast updates
async def broadcast_updates():
    """Background task to broadcast real-time updates."""
    while True:
        try:
            if manager.active_connections:
                # Broadcast health update
                health_data = await get_health_data()
                await manager.broadcast_json({
                    "type": "health_update",
                    "data": health_data
                })
                
                # Broadcast queue update
                queue_data = await get_queue_data()
                await manager.broadcast_json({
                    "type": "queue_update",
                    "data": queue_data
                })
                
                # Broadcast alarm update
                alarm_data = await get_alarm_data()
                await manager.broadcast_json({
                    "type": "alarm_update",
                    "data": alarm_data
                })
            
            # Wait for next update cycle
            await asyncio.sleep(10)  # Update every 10 seconds
            
        except Exception as e:
            logger.error("broadcast_updates_failed", error=str(e), exc_info=True)
            await asyncio.sleep(30)  # Wait longer on error


# API endpoints for dashboard data

@app.get("/api/dashboard/overview")
async def get_dashboard_overview():
    """Get dashboard overview data."""
    try:
        health_data = await get_health_data()
        queue_data = await get_queue_data()
        alarm_data = await get_alarm_data()
        
        return {
            "health": health_data,
            "queues": queue_data,
            "alarms": alarm_data,
            "timestamp": datetime.now(timezone.utc).isoformat()
        }
    except Exception as e:
        logger.error("get_dashboard_overview_failed", error=str(e), exc_info=True)
        return {"error": "Failed to get dashboard overview"}


@app.get("/api/dashboard/health")
async def get_dashboard_health():
    """Get health data for dashboard."""
    return await get_health_data()


@app.get("/api/dashboard/queues")
async def get_dashboard_queues():
    """Get queue data for dashboard."""
    return await get_queue_data()


@app.get("/api/dashboard/alarms")
async def get_dashboard_alarms():
    """Get alarm data for dashboard."""
    return await get_alarm_data()


@app.post("/api/dashboard/alarms/{alarm_id}/acknowledge")
async def acknowledge_alarm_dashboard(alarm_id: int, request: Dict[str, str]):
    """Acknowledge an alarm from dashboard."""
    try:
        acknowledged_by = request.get("acknowledged_by", "dashboard_user")
        success = await alarm_system.acknowledge_alarm(alarm_id, acknowledged_by)
        
        if success:
            logger.info("alarm_acknowledged_via_dashboard", alarm_id=alarm_id, user=acknowledged_by)
            return {"success": True, "message": "Alarm acknowledged"}
        else:
            return {"success": False, "message": "Alarm not found"}
            
    except Exception as e:
        logger.error("acknowledge_alarm_dashboard_failed", alarm_id=alarm_id, error=str(e), exc_info=True)
        return {"success": False, "message": "Failed to acknowledge alarm"}


# Startup and shutdown events

@app.on_event("startup")
async def startup_event():
    """Dashboard startup event."""
    logger.info("dashboard_starting_up")
    
    try:
        # Start background task for broadcasting updates
        asyncio.create_task(broadcast_updates())
        
        logger.info("dashboard_startup_complete")
        
    except Exception as e:
        logger.error("dashboard_startup_failed", error=str(e), exc_info=True)


@app.on_event("shutdown")
async def shutdown_event():
    """Dashboard shutdown event."""
    logger.info("dashboard_shutting_down")
    
    try:
        # Close all WebSocket connections
        for connection in manager.active_connections:
            try:
                await connection.close()
            except Exception:
                pass
        
        logger.info("dashboard_shutdown_complete")
        
    except Exception as e:
        logger.error("dashboard_shutdown_failed", error=str(e), exc_info=True)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "cishub.dashboard.app:app",
        host=settings.dashboard.host,
        port=settings.dashboard.port,
        reload=settings.debug,
        log_level=settings.log_level.lower()
    )