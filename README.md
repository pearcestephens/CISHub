# CISHub - Complete System Overhaul

CISHub is a robust, reliable, and traceable queue integration system designed to keep data in sync with advanced monitoring and automatic failure response capabilities.

## 🚀 Key Features

### Core Functionality
- **Robust Queue Management**: Advanced Celery-based task processing with health monitoring
- **Real-time Monitoring**: Comprehensive system health monitoring with configurable thresholds
- **Advanced Alarm System**: Multi-channel notifications (Slack, Email) with automatic system shutdown
- **Emergency Response**: Automatic system shutdown on critical failures to prevent data corruption
- **RESTful API**: Complete API for queue management, monitoring, and system control
- **Real-time Dashboard**: WebSocket-powered dashboard for live monitoring

### Monitoring & Alerting
- **Queue Health Monitoring**: Tracks queue backup, error rates, processing timeouts
- **System Resource Monitoring**: CPU, memory, disk usage tracking
- **Component Health Checks**: Database, Redis, Celery worker monitoring
- **External Service Monitoring**: API endpoint health checking
- **Configurable Thresholds**: Customizable alerts for various failure scenarios
- **Alarm Escalation**: Automatic system shutdown for critical issues

### Safety & Reliability
- **Circuit Breaker Pattern**: Prevents cascade failures
- **Exponential Backoff**: Intelligent retry mechanisms
- **Dead Letter Queues**: Failed task handling and recovery
- **Graceful Degradation**: System continues operating during partial failures
- **Audit Logging**: Complete operation tracking for compliance

## 🏗️ Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Dashboard     │    │   API Server    │    │   Workers       │
│   (Port 8000)   │    │   (Port 8001)   │    │   (Celery)      │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
    ┌─────────────────────────────┴─────────────────────────────┐
    │                 Core System                               │
    ├─────────────────┬─────────────────┬─────────────────────┤
    │ Queue Manager   │ Health Monitor  │ Alarm System        │
    │ - Task Submit   │ - Component     │ - Multi-channel     │
    │ - Health Check  │   Health        │   Notifications     │
    │ - Retry Logic   │ - Metrics       │ - Auto Shutdown     │
    └─────────────────┴─────────────────┴─────────────────────┘
                                 │
    ┌─────────────────────────────┴─────────────────────────────┐
    │                Infrastructure                            │
    ├─────────────────┬─────────────────┬─────────────────────┤
    │   PostgreSQL    │     Redis       │   External APIs     │
    │   - Task Data   │   - Queues      │   - Lightspeed      │
    │   - Metrics     │   - Cache       │   - Webhooks        │
    │   - Audit Log   │   - Sessions    │   - Monitoring      │
    └─────────────────┴─────────────────┴─────────────────────┘
```

## 🚦 Alarm System

The system includes a comprehensive alarm system that monitors various failure scenarios:

### Alarm Types
- **Queue Backup**: When queues exceed threshold (default: 100 tasks)
- **High Error Rate**: When error rate exceeds threshold (default: 10%)
- **Processing Timeout**: When no tasks processed for configured time (default: 5 minutes)
- **System Errors**: Database, Redis, or worker failures
- **Resource Exhaustion**: High CPU/memory/disk usage
- **External Service Failures**: API endpoint failures

### Emergency Shutdown
Critical alarms trigger automatic system shutdown to prevent:
- Data corruption
- System overload
- Cascade failures
- Resource exhaustion

## 🔧 Installation & Setup

### Prerequisites
- Python 3.11+
- PostgreSQL 12+
- Redis 6+
- Docker & Docker Compose (recommended)

### Quick Start with Docker

1. **Clone the repository**:
```bash
git clone https://github.com/pearcestephens/CISHub.git
cd CISHub
```

2. **Configure environment**:
```bash
cp .env.example .env
# Edit .env with your settings
```

3. **Start the system**:
```bash
docker-compose up -d
```

4. **Access the interfaces**:
- Dashboard: http://localhost:8000
- API: http://localhost:8001/docs
- Flower (Celery Monitor): http://localhost:5555

### Manual Installation

1. **Install dependencies**:
```bash
pip install -r requirements.txt
```

2. **Setup databases**:
```bash
# PostgreSQL setup
createdb cishub
# Redis should be running on default port
```

3. **Configure environment**:
```bash
cp .env.example .env
# Edit .env with your database URLs and settings
```

4. **Initialize the system**:
```bash
python main.py health  # Check system health
```

5. **Start components**:
```bash
# Terminal 1: API Server
python main.py api

# Terminal 2: Dashboard
python main.py dashboard

# Terminal 3: Worker
python main.py worker

# Terminal 4: Monitoring (optional)
python main.py monitor
```

## 📊 Usage

### Submitting Tasks via API

```python
import httpx

# Submit a task
response = httpx.post("http://localhost:8001/tasks", json={
    "task_type": "lightspeed_sync",
    "task_name": "Sync Products",
    "payload": {
        "sync_type": "incremental",
        "entity_type": "products"
    },
    "priority": "high",
    "queue_name": "default"
})

task_id = response.json()["task_id"]

# Check task status
status = httpx.get(f"http://localhost:8001/tasks/{task_id}")
```

### Monitoring System Health

```python
# Get system health
health = httpx.get("http://localhost:8001/health")

# Get queue health
queue_health = httpx.get("http://localhost:8001/queues/default/health")

# Get active alarms
alarms = httpx.get("http://localhost:8001/alarms")
```

### Emergency Shutdown

```python
# Trigger emergency shutdown (requires token)
shutdown = httpx.post(
    "http://localhost:8001/system/shutdown",
    headers={"Authorization": "Bearer your-shutdown-token"},
    json={
        "reason": "Critical system issue detected",
        "initiated_by": "admin_user"
    }
)
```

## ⚙️ Configuration

### Key Settings

```python
# Queue Configuration
QUEUE_HEALTH_CHECK_INTERVAL = 30  # seconds
QUEUE_PROCESSING_TIMEOUT = 300    # seconds
QUEUE_BACKUP_THRESHOLD = 100      # tasks
QUEUE_ERROR_THRESHOLD = 10        # percentage

# Alarm Configuration
ALARM_COOLDOWN_PERIOD = 300       # seconds
SLACK_WEBHOOK_URL = "https://..."
EMAIL_RECIPIENTS = "admin@company.com"

# System Safety
SHUTDOWN_ENDPOINT_TOKEN = "secure-token"
```

### Environment Variables

See `.env.example` for complete configuration options.

## 🔍 Monitoring

### Dashboard Features
- Real-time system status
- Queue health metrics
- Active alarms display
- Component status indicators
- WebSocket live updates

### Health Endpoints
- `/health` - Comprehensive system health
- `/health/quick` - Lightweight health check
- `/health/components` - Individual component status

### Metrics Collection
- Task processing times
- Error rates and patterns
- System resource usage
- Queue throughput
- Component response times

## 🚨 Alarm Handling

### Notification Channels
1. **Slack**: Real-time team notifications
2. **Email**: Detailed alarm information
3. **Dashboard**: Visual alerts and acknowledgment

### Alarm Lifecycle
1. **Detection**: Continuous monitoring detects issues
2. **Evaluation**: Thresholds and patterns analyzed
3. **Escalation**: Severity determined, notifications sent
4. **Response**: Manual acknowledgment or auto-resolution
5. **Shutdown**: Critical alarms trigger system shutdown

## 🛡️ Security

### Authentication
- API endpoint protection
- Emergency shutdown token validation
- Webhook signature verification

### Data Protection
- Sensitive data masking in logs
- Secure token generation
- HMAC signature validation

## 📈 Performance

### Scalability
- Horizontal worker scaling
- Load balancing support
- Resource-aware processing

### Optimization
- Connection pooling
- Intelligent retry mechanisms
- Circuit breaker patterns
- Efficient batch processing

## 🧪 Testing

```bash
# Run tests
pytest

# Run with coverage
pytest --cov=cishub

# Run specific test types
pytest tests/unit/
pytest tests/integration/
pytest tests/load/
```

## 📝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Run the test suite
6. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

For support and questions:
- Create an issue on GitHub
- Check the documentation
- Review the health check output
- Monitor system logs

## 🔄 Version History

### v1.0.0 - Complete System Overhaul
- ✅ Robust queue management system
- ✅ Advanced monitoring and health checking
- ✅ Multi-channel alarm system with auto-shutdown
- ✅ Real-time dashboard with WebSocket updates
- ✅ Comprehensive API with emergency controls
- ✅ Docker containerization and orchestration
- ✅ Production-ready logging and metrics
