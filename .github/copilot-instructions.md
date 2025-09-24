# CISHub - Lightspeed and Queue Integration

CISHub is a Lightspeed and Queue integration system designed to be a robust, reliable, and traceable method of keeping data in sync between systems.

**Always reference these instructions first and fallback to search or bash commands only when you encounter unexpected information that does not match the info here.**

## Project Status
This repository is currently in its initial development phase. The codebase contains only basic project documentation and is ready for development setup.

## Working Effectively

### Repository Setup
Since this is a minimal repository, development setup will depend on the technology stack chosen. Based on the integration nature of the project, consider these common patterns:

**For Node.js/TypeScript development:**
- Run `npm init -y` to initialize package.json
- Install common dependencies: `npm install --save typescript @types/node`
- Install development dependencies: `npm install --save-dev jest @types/jest ts-jest nodemon`
- Create `tsconfig.json` for TypeScript configuration
- Set up build script: `npm run build` 
- Set up test script: `npm run test`

**For Python development:**
- Create `requirements.txt` for dependencies
- Set up virtual environment: `python -m venv venv && source venv/bin/activate`
- Install dependencies: `pip install -r requirements.txt`
- Run tests: `python -m pytest`

**For .NET development:**
- Initialize solution: `dotnet new sln`
- Create project: `dotnet new console -n CISHub`
- Add project to solution: `dotnet sln add CISHub/CISHub.csproj`
- Build: `dotnet build` -- Typically takes 10-30 seconds for initial builds. Set timeout to 5+ minutes.
- Test: `dotnet test` -- Typically takes under 1 minute. Set timeout to 3+ minutes.

### Current Repository State
- **Build Status**: No build system configured yet
- **Dependencies**: None installed
- **Tests**: No test framework set up
- **Documentation**: Basic README.md exists

### Current Repository Structure
```
/
├── README.md                          # Basic project documentation
└── .github/
    └── copilot-instructions.md        # This instructions file
```

## Development Guidelines

### When Adding Code
1. **Choose Technology Stack**: Based on integration requirements, select appropriate technology (Node.js, Python, .NET, etc.)
2. **Set up Build System**: Configure package management and build tools
3. **Add Logging**: Implement comprehensive logging for traceability (key requirement mentioned in README)
4. **Error Handling**: Implement robust error handling for reliability
5. **Configuration Management**: Use environment variables or config files for API keys and endpoints

### Integration Patterns
For Lightspeed POS integrations, commonly needed components:
- **API Client**: For Lightspeed API communication
- **Queue System**: For reliable message processing (consider Redis, RabbitMQ, or AWS SQS)
- **Data Mapping**: Transform data between Lightspeed and target system formats
- **Sync Engine**: Handle bidirectional or unidirectional data synchronization
- **Webhook Handlers**: Process real-time notifications from Lightspeed
- **Database Layer**: Store sync state, logs, and configuration

### Validation Requirements
When implementing features:
1. **Test API Connectivity**: Verify connection to Lightspeed API
2. **Test Queue Operations**: Ensure messages are properly queued and processed
3. **Test Data Transformation**: Validate data mapping between systems
4. **Test Error Scenarios**: Handle network failures, API rate limits, malformed data
5. **Test Sync Integrity**: Verify data consistency between systems

### Common Commands (To Be Updated Based on Tech Stack)
Currently no build commands available. Update this section when technology stack is chosen:

```bash
# Example for future Node.js setup:
# npm install          # Install dependencies
# npm run build        # Build the application
# npm run test         # Run tests
# npm run dev          # Start development server
# npm run lint         # Run linting
```

## Repository Structure (Future)
Recommended structure based on integration project patterns:
```
/src
  /api          # API client implementations
  /queue        # Queue handling logic
  /mappers      # Data transformation logic
  /sync         # Synchronization engine
  /webhooks     # Webhook handlers
/tests          # Test files
/config         # Configuration files
/docs           # Documentation
/scripts        # Build and deployment scripts
```

## Key Integration Considerations
- **Rate Limiting**: Lightspeed APIs have rate limits - implement proper throttling
- **Authentication**: Secure API key management and OAuth flows
- **Data Consistency**: Ensure atomic operations and proper rollback mechanisms  
- **Monitoring**: Track sync success rates, error rates, and performance metrics
- **Scalability**: Design for handling multiple store locations and high transaction volumes

## Critical Reminders
- **NEVER CANCEL** long-running operations during development
- Always implement comprehensive logging for traceability
- Test all integration endpoints thoroughly
- Validate data integrity after sync operations
- Handle API authentication renewal automatically
- Implement circuit breaker patterns for external API calls

## Next Steps
1. Choose and document the technology stack
2. Set up basic project structure and build system
3. Implement Lightspeed API client
4. Set up queue system for reliable processing
5. Create data mapping and sync logic
6. Add comprehensive testing
7. Update these instructions with specific commands and timing expectations

**Note**: This file will be updated as the project evolves and build systems are implemented.

## Common tasks
The following are outputs from frequently referenced files and commands to save time:

### Repository root files
```bash
ls -la
total 16
drwxr-xr-x 4 runner runner 4096 Sep 24 01:29 .
drwxr-xr-x 3 runner runner 4096 Sep 24 01:28 ..
drwxr-xr-x 8 runner runner 4096 Sep 24 01:28 .git
drwxrwxr-x 2 runner runner 4096 Sep 24 01:29 .github
-rw-r--r-- 1 runner runner  139 Sep 24 01:28 README.md
```

### README.md content
```markdown
# CISHub
CISHUB IS A LIGHTSPEED AND QUEUE INTEGRATION THAT IS DESIGNED TO BE A ROBUST RELIABLE AND TRACABLE METHOD OF KEEPING DATA IN SYNC
```

### Available development environments
- Node.js v20.19.5 is available
- Python 3.12.3 is available  
- .NET 8.0.119 is available

All setup commands for these technologies have been validated and work correctly.