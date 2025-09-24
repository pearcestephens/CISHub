"""
Database utilities and connection management.
Provides connection pooling, session management, and database operations.
"""
import asyncio
from contextlib import asynccontextmanager, contextmanager
from typing import AsyncGenerator, Generator, Optional
from sqlalchemy import create_engine, MetaData
from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession, async_sessionmaker
from sqlalchemy.orm import sessionmaker, Session
from sqlalchemy.pool import QueuePool
from cishub.config.settings import settings
from cishub.config.logging import LoggerMixin
from cishub.core.models import Base


class DatabaseManager(LoggerMixin):
    """Database connection and session management."""
    
    def __init__(self):
        super().__init__()
        self._sync_engine = None
        self._async_engine = None
        self._sync_session_factory = None
        self._async_session_factory = None
        self._initialized = False
    
    def initialize(self) -> None:
        """Initialize database engines and session factories."""
        if self._initialized:
            return
        
        self.log_operation("initializing_database_connections")
        
        try:
            # Create synchronous engine
            self._sync_engine = create_engine(
                settings.database.url,
                poolclass=QueuePool,
                pool_size=settings.database.pool_size,
                max_overflow=settings.database.max_overflow,
                pool_timeout=settings.database.pool_timeout,
                pool_recycle=settings.database.pool_recycle,
                echo=settings.debug
            )
            
            # Create asynchronous engine
            async_url = settings.database.url.replace("postgresql://", "postgresql+asyncpg://")
            self._async_engine = create_async_engine(
                async_url,
                pool_size=settings.database.pool_size,
                max_overflow=settings.database.max_overflow,
                pool_timeout=settings.database.pool_timeout,
                pool_recycle=settings.database.pool_recycle,
                echo=settings.debug
            )
            
            # Create session factories
            self._sync_session_factory = sessionmaker(
                bind=self._sync_engine,
                autocommit=False,
                autoflush=False
            )
            
            self._async_session_factory = async_sessionmaker(
                bind=self._async_engine,
                class_=AsyncSession,
                autocommit=False,
                autoflush=False
            )
            
            self._initialized = True
            self.log_operation("database_connections_initialized")
            
        except Exception as e:
            self.log_error(e, "database_initialization_failed")
            raise
    
    async def create_tables(self) -> None:
        """Create all database tables."""
        if not self._initialized:
            self.initialize()
        
        self.log_operation("creating_database_tables")
        
        try:
            async with self._async_engine.begin() as conn:
                await conn.run_sync(Base.metadata.create_all)
            self.log_operation("database_tables_created")
        except Exception as e:
            self.log_error(e, "database_table_creation_failed")
            raise
    
    async def drop_tables(self) -> None:
        """Drop all database tables."""
        if not self._initialized:
            self.initialize()
        
        self.log_operation("dropping_database_tables")
        
        try:
            async with self._async_engine.begin() as conn:
                await conn.run_sync(Base.metadata.drop_all)
            self.log_operation("database_tables_dropped")
        except Exception as e:
            self.log_error(e, "database_table_drop_failed")
            raise
    
    @contextmanager
    def get_sync_session(self) -> Generator[Session, None, None]:
        """Get a synchronous database session."""
        if not self._initialized:
            self.initialize()
        
        session = self._sync_session_factory()
        try:
            yield session
            session.commit()
        except Exception:
            session.rollback()
            raise
        finally:
            session.close()
    
    @asynccontextmanager
    async def get_async_session(self) -> AsyncGenerator[AsyncSession, None]:
        """Get an asynchronous database session."""
        if not self._initialized:
            self.initialize()
        
        session = self._async_session_factory()
        try:
            yield session
            await session.commit()
        except Exception:
            await session.rollback()
            raise
        finally:
            await session.close()
    
    async def health_check(self) -> bool:
        """Perform database health check."""
        try:
            async with self.get_async_session() as session:
                await session.execute("SELECT 1")
            return True
        except Exception as e:
            self.log_error(e, "database_health_check_failed")
            return False
    
    async def get_connection_info(self) -> dict:
        """Get database connection information."""
        if not self._initialized:
            return {"status": "not_initialized"}
        
        try:
            pool = self._sync_engine.pool
            return {
                "status": "connected",
                "pool_size": pool.size(),
                "checked_in": pool.checkedin(),
                "checked_out": pool.checkedout(),
                "overflow": pool.overflow(),
                "invalid": pool.invalid()
            }
        except Exception as e:
            self.log_error(e, "get_connection_info_failed")
            return {"status": "error", "error": str(e)}
    
    def close(self) -> None:
        """Close database connections."""
        self.log_operation("closing_database_connections")
        
        if self._sync_engine:
            self._sync_engine.dispose()
        
        if self._async_engine:
            asyncio.create_task(self._async_engine.aclose())
        
        self._initialized = False
        self.log_operation("database_connections_closed")


# Global database manager instance
db_manager = DatabaseManager()


# Dependency functions for FastAPI
def get_sync_db() -> Generator[Session, None, None]:
    """FastAPI dependency for synchronous database sessions."""
    with db_manager.get_sync_session() as session:
        yield session


async def get_async_db() -> AsyncGenerator[AsyncSession, None]:
    """FastAPI dependency for asynchronous database sessions."""
    async with db_manager.get_async_session() as session:
        yield session


class BaseRepository(LoggerMixin):
    """Base repository class with common database operations."""
    
    def __init__(self, model_class):
        super().__init__()
        self.model_class = model_class
    
    async def create(self, session: AsyncSession, **kwargs) -> object:
        """Create a new record."""
        try:
            instance = self.model_class(**kwargs)
            session.add(instance)
            await session.flush()
            await session.refresh(instance)
            self.log_operation("record_created", model=self.model_class.__name__, id=getattr(instance, 'id', None))
            return instance
        except Exception as e:
            self.log_error(e, "record_creation_failed", model=self.model_class.__name__)
            raise
    
    async def get_by_id(self, session: AsyncSession, record_id: int) -> Optional[object]:
        """Get a record by ID."""
        try:
            result = await session.get(self.model_class, record_id)
            return result
        except Exception as e:
            self.log_error(e, "get_by_id_failed", model=self.model_class.__name__, id=record_id)
            raise
    
    async def update(self, session: AsyncSession, record_id: int, **kwargs) -> Optional[object]:
        """Update a record by ID."""
        try:
            instance = await session.get(self.model_class, record_id)
            if not instance:
                return None
            
            for key, value in kwargs.items():
                if hasattr(instance, key):
                    setattr(instance, key, value)
            
            await session.flush()
            await session.refresh(instance)
            self.log_operation("record_updated", model=self.model_class.__name__, id=record_id)
            return instance
        except Exception as e:
            self.log_error(e, "record_update_failed", model=self.model_class.__name__, id=record_id)
            raise
    
    async def delete(self, session: AsyncSession, record_id: int) -> bool:
        """Delete a record by ID."""
        try:
            instance = await session.get(self.model_class, record_id)
            if not instance:
                return False
            
            await session.delete(instance)
            await session.flush()
            self.log_operation("record_deleted", model=self.model_class.__name__, id=record_id)
            return True
        except Exception as e:
            self.log_error(e, "record_deletion_failed", model=self.model_class.__name__, id=record_id)
            raise
    
    async def list_all(self, session: AsyncSession, limit: int = 100, offset: int = 0) -> list:
        """List all records with pagination."""
        try:
            from sqlalchemy import select
            query = select(self.model_class).limit(limit).offset(offset)
            result = await session.execute(query)
            return result.scalars().all()
        except Exception as e:
            self.log_error(e, "list_all_failed", model=self.model_class.__name__)
            raise
    
    async def count(self, session: AsyncSession) -> int:
        """Count total records."""
        try:
            from sqlalchemy import select, func
            query = select(func.count(self.model_class.id))
            result = await session.execute(query)
            return result.scalar()
        except Exception as e:
            self.log_error(e, "count_failed", model=self.model_class.__name__)
            raise