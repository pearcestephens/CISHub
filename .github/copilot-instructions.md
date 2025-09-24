# CISHub - Lightspeed and Queue Integration

CISHub is designed to be a robust, reliable, and traceable method of keeping data in sync between Lightspeed and Queue systems.

**ALWAYS follow these instructions first and fallback to search or bash commands only when you encounter unexpected information that does not match the info here.**

## Current Repository State

**CRITICAL**: This repository is currently in the initial development phase and contains only a README.md file. No build system, dependencies, or application code exists yet.

## Working Effectively

### Initial Setup
Since this repository is minimal and contains no build system or dependencies yet:

- Clone the repository: `git clone https://github.com/pearcestephens/CISHub.git`
- Navigate to directory: `cd CISHub`
- Review the README: `cat README.md`

### Current File Structure
```
CISHub/
├── README.md          # Basic project description
└── .github/
    └── copilot-instructions.md  # This file
```

### Build Instructions
**CURRENT STATE**: No build system exists yet.

When build system is added, validate these common patterns:
- Node.js projects: Look for `package.json`, then run `npm install` and `npm run build`
- Python projects: Look for `requirements.txt` or `pyproject.toml`, then set up virtual environment
- Go projects: Look for `go.mod`, then run `go build`
- .NET projects: Look for `.csproj` or `.sln` files, then run `dotnet build`

**TIMEOUT GUIDELINES**: When build system is implemented:
- Set timeouts of 60+ minutes for build commands - NEVER CANCEL builds
- Set timeouts of 30+ minutes for test commands - NEVER CANCEL tests
- Document actual build times once measured

### Testing Instructions
**CURRENT STATE**: No test framework exists yet.

When tests are added, common patterns to check:
- Node.js: `npm test` or `npm run test`
- Python: `pytest` or `python -m pytest`
- Go: `go test ./...`
- .NET: `dotnet test`

### Running the Application
**CURRENT STATE**: No application code exists yet.

Given the project description mentions "Lightspeed and Queue Integration", expect future implementations might include:
- Web API endpoints for integration management
- Background services for data synchronization
- Configuration for external system connections
- Database schemas for tracking sync operations

### Validation Steps
When application code is added, always validate:

1. **Integration Connectivity**: Test connections to both Lightspeed and Queue systems
2. **Data Sync Operations**: Verify that data flows correctly between systems
3. **Error Handling**: Test failure scenarios and recovery mechanisms
4. **Logging and Traceability**: Ensure operations are properly logged for debugging

### Linting and Code Quality
**CURRENT STATE**: No linting configuration exists yet.

When code is added, check for these common linting tools:
- Node.js: ESLint, Prettier (`npm run lint`, `npm run format`)
- Python: Black, Flake8, mypy (`black .`, `flake8 .`)
- Go: gofmt, golint (`go fmt ./...`, `golint ./...`)
- .NET: EditorConfig, dotnet format (`dotnet format`)

Always run linting before committing changes.

### Git Workflow
- Main development branch: Check `git branch -r` to identify primary branch
- Create feature branches for new work
- Ensure all commits have clear, descriptive messages
- Run validation steps before pushing changes

### Common File Locations (Future Reference)
When the codebase is developed, expect these common locations:
- Configuration files: Look in root directory or `config/` folder
- API documentation: Likely in `docs/` or inline with code
- Database migrations: Common in `migrations/` or `sql/` folders
- External service configurations: Often in environment files or config folders

### Troubleshooting
**Current Issues**: None - repository is minimal

**Future Development**: When adding code, document any specific requirements:
- Required environment variables
- External service dependencies
- Database setup requirements
- Special development environment needs

### Development Environment Setup
**CURRENT STATE**: No specific requirements

When environment setup is needed, validate these common requirements:
- Runtime versions (Node.js, Python, Go, .NET)
- Database systems (PostgreSQL, MySQL, SQLite)
- Message queue systems (RabbitMQ, Redis, AWS SQS)
- Lightspeed API credentials and sandbox environment access
- Environment variable configuration

### Common Integration Patterns (Future Reference)
When developing integration features, expect these patterns:
- **Event-Driven Architecture**: Using webhooks and message queues for real-time sync
- **Batch Processing**: Scheduled sync jobs for large data volumes
- **Circuit Breaker**: Preventing cascade failures when external APIs are down
- **Exponential Backoff**: Retry strategies for failed API calls
- **Data Versioning**: Handling schema changes in integrated systems
- **Conflict Resolution**: Strategies for handling simultaneous updates

### Repository Navigation
**Current Structure**: Only README.md exists

**Key Areas to Monitor** (for future development):
- `/src` or `/app` - Main application code
- `/api` - API endpoint definitions
- `/models` - Data models and schemas  
- `/services` - Business logic and external integrations
- `/tests` - Test suites
- `/docs` - Documentation
- `/config` - Configuration files

### CI/CD Pipeline
**CURRENT STATE**: Only Copilot workflow exists

When CI/CD is implemented, ensure:
- All builds complete successfully (expect 30-60+ minute build times)
- All tests pass (expect 15-30+ minute test suites)
- Linting and code quality checks pass
- Integration tests with external systems work

**CRITICAL REMINDER**: NEVER CANCEL builds or tests that appear to be taking a long time. Build and test operations for integration systems commonly take 45+ minutes. Always set appropriate timeouts and wait for completion.

## Integration-Specific Notes

Since this is a Lightspeed and Queue integration system:

### Lightspeed System Context
Lightspeed typically refers to:
- **Lightspeed Retail**: Point-of-sale system for retail businesses
- **Lightspeed Restaurant**: POS system for restaurants and hospitality
- **Lightspeed eCommerce**: E-commerce platform for online stores

### Expected Components (Future Development)
- **API Connectors**: Classes/modules for Lightspeed REST API interactions
- **Queue Handlers**: Message queue processing (likely RabbitMQ, Redis, or similar)
- **Data Mappers**: Translation logic between Lightspeed and Queue system data formats
- **Sync Orchestrators**: Controllers managing bidirectional data synchronization
- **Webhook Processors**: Handlers for real-time Lightspeed notifications
- **Audit Trails**: Comprehensive logging systems for tracking all sync operations
- **Error Recovery**: Retry mechanisms, dead letter queues, and failure handling
- **Configuration Management**: Environment-based settings for API credentials and sync rules

### Testing Integration Features (Future)
When integration code exists, always test:
1. **Lightspeed API Connectivity**: Verify authentication and API access to Lightspeed systems
2. **Queue System Health**: Test message queue connectivity and processing
3. **Data Sync Accuracy**: Ensure inventory, orders, and customer data sync correctly
4. **Webhook Processing**: Test real-time event handling from Lightspeed
5. **Error Scenarios**: Test API rate limits, network failures, and data conflicts
6. **Performance Under Load**: Validate sync operations handle expected data volumes
7. **Rollback Capabilities**: Test data recovery and sync rollback mechanisms
8. **Idempotency**: Ensure repeated sync operations don't create duplicates

### Security Considerations (Future)
- **API Credentials**: Secure storage of Lightspeed API keys and OAuth tokens
- **Queue Authentication**: Message queue access credentials and connection security
- **Data Encryption**: Protect sensitive customer and transaction data in transit and at rest
- **Access Control**: Role-based permissions for integration management
- **Audit Logging**: Comprehensive logging for compliance and debugging
- **Rate Limiting**: Respect Lightspeed API rate limits to avoid service interruption
- **PCI Compliance**: Handle payment data according to security standards (if applicable)

This file will need updates as the codebase develops. Always validate instructions against the actual codebase state and update this file when development patterns are established.