#!/usr/bin/env python3
"""
Main application runner for CISHub system.
Provides command-line interface to run different components of the system.
"""
import asyncio
import sys
import signal
import argparse
from typing import Optional
import uvicorn
from cishub.config.settings import settings
from cishub.config.logging import configure_logging, get_logger
from cishub.core.queue_manager import queue_manager
from cishub.monitoring.health_checker import health_monitor
from cishub.monitoring.alarm_system import alarm_system
from cishub.utils.database import db_manager

# Configure logging
configure_logging()
logger = get_logger(__name__)


class CISHubApplication:
    """Main application class for CISHub system."""
    
    def __init__(self):
        self.shutdown_event = asyncio.Event()
        self.components_started = []
    
    async def initialize_system(self):
        """Initialize the CISHub system components."""
        logger.info("initializing_cishub_system")
        
        try:
            # Initialize database
            logger.info("initializing_database")
            db_manager.initialize()
            await db_manager.create_tables()
            self.components_started.append("database")
            
            # Initialize queue manager
            logger.info("initializing_queue_manager")
            await queue_manager.initialize()
            self.components_started.append("queue_manager")
            
            # Start monitoring systems
            logger.info("starting_monitoring_systems")
            await health_monitor.start_monitoring(interval=30)
            await queue_manager.start_monitoring(interval=30)
            self.components_started.extend(["health_monitor", "queue_monitoring"])
            
            # Connect alarm system to queue health monitoring
            queue_manager.add_health_check_callback(alarm_system.process_queue_health)
            
            # Add shutdown callback to alarm system
            alarm_system.add_shutdown_callback(self.emergency_shutdown_callback)
            
            logger.info("cishub_system_initialized", components=self.components_started)
            
        except Exception as e:
            logger.error("system_initialization_failed", error=str(e), exc_info=True)
            raise
    
    async def emergency_shutdown_callback(self, alarm_event, reason: str):
        """Callback for emergency shutdown triggered by alarm system."""
        logger.critical(
            "emergency_shutdown_triggered",
            reason=reason,
            alarm_type=alarm_event.alarm_type.value if alarm_event else None
        )
        
        # Trigger graceful shutdown
        self.shutdown_event.set()
    
    async def shutdown_system(self):
        """Shutdown the CISHub system gracefully."""
        logger.info("shutting_down_cishub_system")
        
        try:
            # Stop monitoring systems
            if "queue_monitoring" in self.components_started:
                await queue_manager.stop_monitoring()
            
            if "health_monitor" in self.components_started:
                await health_monitor.stop_monitoring()
            
            # Shutdown queue manager
            if "queue_manager" in self.components_started:
                await queue_manager.shutdown()
            
            # Close database connections
            if "database" in self.components_started:
                db_manager.close()
            
            logger.info("cishub_system_shutdown_complete")
            
        except Exception as e:
            logger.error("system_shutdown_failed", error=str(e), exc_info=True)
    
    def setup_signal_handlers(self):
        """Setup signal handlers for graceful shutdown."""
        def signal_handler(signum, frame):
            logger.info("shutdown_signal_received", signal=signum)
            self.shutdown_event.set()
        
        signal.signal(signal.SIGINT, signal_handler)
        signal.signal(signal.SIGTERM, signal_handler)
    
    async def run_api_server(self):
        """Run the API server."""
        logger.info("starting_api_server", host=settings.api.host, port=settings.api.port)
        
        try:
            # Initialize system
            await self.initialize_system()
            
            # Setup signal handlers
            self.setup_signal_handlers()
            
            # Create and configure uvicorn server
            config = uvicorn.Config(
                "cishub.api.routes:app",
                host=settings.api.host,
                port=settings.api.port,
                log_level=settings.log_level.lower(),
                reload=settings.debug,
                access_log=True,
                loop="asyncio"
            )
            
            server = uvicorn.Server(config)
            
            # Run server with shutdown handling
            server_task = asyncio.create_task(server.serve())
            shutdown_task = asyncio.create_task(self.shutdown_event.wait())
            
            # Wait for either server completion or shutdown signal
            done, pending = await asyncio.wait(
                [server_task, shutdown_task],
                return_when=asyncio.FIRST_COMPLETED
            )
            
            # Cancel pending tasks
            for task in pending:
                task.cancel()
                try:
                    await task
                except asyncio.CancelledError:
                    pass
            
            # Shutdown server if it's still running
            if not server_task.done():
                server.should_exit = True
                await server_task
            
        except Exception as e:
            logger.error("api_server_failed", error=str(e), exc_info=True)
            raise
        finally:
            await self.shutdown_system()
    
    async def run_dashboard_server(self):
        """Run the dashboard server."""
        logger.info("starting_dashboard_server", host=settings.dashboard.host, port=settings.dashboard.port)
        
        try:
            # Initialize system
            await self.initialize_system()
            
            # Setup signal handlers
            self.setup_signal_handlers()
            
            # Create and configure uvicorn server
            config = uvicorn.Config(
                "cishub.dashboard.app:app",
                host=settings.dashboard.host,
                port=settings.dashboard.port,
                log_level=settings.log_level.lower(),
                reload=settings.debug,
                access_log=True,
                loop="asyncio"
            )
            
            server = uvicorn.Server(config)
            
            # Run server with shutdown handling
            server_task = asyncio.create_task(server.serve())
            shutdown_task = asyncio.create_task(self.shutdown_event.wait())
            
            # Wait for either server completion or shutdown signal
            done, pending = await asyncio.wait(
                [server_task, shutdown_task],
                return_when=asyncio.FIRST_COMPLETED
            )
            
            # Cancel pending tasks
            for task in pending:
                task.cancel()
                try:
                    await task
                except asyncio.CancelledError:
                    pass
            
            # Shutdown server if it's still running
            if not server_task.done():
                server.should_exit = True
                await server_task
            
        except Exception as e:
            logger.error("dashboard_server_failed", error=str(e), exc_info=True)
            raise
        finally:
            await self.shutdown_system()
    
    async def run_worker(self):
        """Run Celery worker."""
        logger.info("starting_celery_worker")
        
        try:
            # Initialize system components needed by worker
            db_manager.initialize()
            await db_manager.create_tables()
            
            # Import and start Celery worker
            from cishub.core.worker import app as celery_app
            
            # Run worker with signal handling
            self.setup_signal_handlers()
            
            # Start worker in a separate process/thread
            worker = celery_app.Worker(
                loglevel=settings.log_level.upper(),
                concurrency=settings.queue.worker_concurrency,
                prefetch_multiplier=settings.queue.worker_prefetch_multiplier
            )
            
            # Start worker and wait for shutdown
            worker_task = asyncio.create_task(
                asyncio.get_event_loop().run_in_executor(None, worker.start)
            )
            shutdown_task = asyncio.create_task(self.shutdown_event.wait())
            
            done, pending = await asyncio.wait(
                [worker_task, shutdown_task],
                return_when=asyncio.FIRST_COMPLETED
            )
            
            # Cancel pending tasks
            for task in pending:
                task.cancel()
                try:
                    await task
                except asyncio.CancelledError:
                    pass
            
            # Stop worker
            worker.stop()
            
        except Exception as e:
            logger.error("celery_worker_failed", error=str(e), exc_info=True)
            raise
        finally:
            db_manager.close()
    
    async def run_monitoring_only(self):
        """Run only monitoring systems (for dedicated monitoring instances)."""
        logger.info("starting_monitoring_only_mode")
        
        try:
            # Initialize system
            await self.initialize_system()
            
            # Setup signal handlers
            self.setup_signal_handlers()
            
            logger.info("monitoring_systems_started")
            
            # Wait for shutdown signal
            await self.shutdown_event.wait()
            
        except Exception as e:
            logger.error("monitoring_only_failed", error=str(e), exc_info=True)
            raise
        finally:
            await self.shutdown_system()
    
    async def run_health_check(self):
        """Run a one-time health check and exit."""
        logger.info("performing_health_check")
        
        try:
            # Initialize minimal components for health check
            db_manager.initialize()
            
            # Perform health check
            report = await health_monitor.perform_health_check()
            
            # Print results
            print(f"Overall Status: {report.overall_status.value.upper()}")
            print(f"Timestamp: {report.timestamp}")
            print(f"Uptime: {report.uptime_seconds:.1f} seconds")
            print("\nComponent Status:")
            
            for component in report.components:
                status_symbol = {
                    'healthy': '✓',
                    'degraded': '⚠',
                    'critical': '✗',
                    'unknown': '?'
                }.get(component.status.value, '?')
                
                response_time = f" ({component.response_time_ms:.1f}ms)" if component.response_time_ms else ""
                error_info = f" - {component.error_message}" if component.error_message else ""
                
                print(f"  {status_symbol} {component.name.upper()}{response_time}{error_info}")
            
            # Exit with appropriate code
            exit_code = 0 if report.overall_status.value == 'healthy' else 1
            sys.exit(exit_code)
            
        except Exception as e:
            logger.error("health_check_failed", error=str(e), exc_info=True)
            print(f"Health check failed: {e}")
            sys.exit(2)
        finally:
            db_manager.close()


def create_argument_parser() -> argparse.ArgumentParser:
    """Create command line argument parser."""
    parser = argparse.ArgumentParser(
        description="CISHub - Robust queue integration system",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python main.py api          # Run API server
  python main.py dashboard    # Run dashboard server
  python main.py worker       # Run Celery worker
  python main.py monitor      # Run monitoring only
  python main.py health       # Perform health check
        """
    )
    
    parser.add_argument(
        "command",
        choices=["api", "dashboard", "worker", "monitor", "health"],
        help="Command to run"
    )
    
    parser.add_argument(
        "--host",
        help="Host to bind to (overrides config)"
    )
    
    parser.add_argument(
        "--port",
        type=int,
        help="Port to bind to (overrides config)"
    )
    
    parser.add_argument(
        "--debug",
        action="store_true",
        help="Enable debug mode"
    )
    
    parser.add_argument(
        "--log-level",
        choices=["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"],
        help="Log level (overrides config)"
    )
    
    return parser


async def main():
    """Main entry point."""
    parser = create_argument_parser()
    args = parser.parse_args()
    
    # Override settings with command line arguments
    if args.debug:
        settings.debug = True
    
    if args.log_level:
        settings.log_level = args.log_level
    
    if args.host:
        settings.api.host = args.host
        settings.dashboard.host = args.host
    
    if args.port:
        if args.command in ["api"]:
            settings.api.port = args.port
        elif args.command in ["dashboard"]:
            settings.dashboard.port = args.port
    
    # Reconfigure logging with updated settings
    configure_logging()
    
    # Create application instance
    app = CISHubApplication()
    
    # Run appropriate command
    try:
        if args.command == "api":
            await app.run_api_server()
        elif args.command == "dashboard":
            await app.run_dashboard_server()
        elif args.command == "worker":
            await app.run_worker()
        elif args.command == "monitor":
            await app.run_monitoring_only()
        elif args.command == "health":
            await app.run_health_check()
        else:
            parser.print_help()
            sys.exit(1)
    
    except KeyboardInterrupt:
        logger.info("application_interrupted")
        sys.exit(0)
    except Exception as e:
        logger.error("application_failed", error=str(e), exc_info=True)
        sys.exit(1)


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\nShutdown requested by user")
        sys.exit(0)
    except Exception as e:
        print(f"Fatal error: {e}")
        sys.exit(1)