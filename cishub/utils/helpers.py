"""
Utility helper functions for CISHub system.
Provides common utilities and helper functions used across the application.
"""
import hashlib
import hmac
import secrets
import uuid
from datetime import datetime, timezone, timedelta
from typing import Any, Dict, List, Optional, Union
import json
import asyncio
from functools import wraps
import time


def generate_correlation_id() -> str:
    """Generate a unique correlation ID."""
    return str(uuid.uuid4())


def generate_secure_token(length: int = 32) -> str:
    """Generate a cryptographically secure random token."""
    return secrets.token_urlsafe(length)


def compute_hmac_signature(data: Union[str, bytes], secret: str, algorithm: str = 'sha256') -> str:
    """Compute HMAC signature for webhook verification."""
    if isinstance(data, str):
        data = data.encode('utf-8')
    
    signature = hmac.new(
        secret.encode('utf-8'),
        data,
        getattr(hashlib, algorithm)
    ).hexdigest()
    
    return f"{algorithm}={signature}"


def verify_webhook_signature(payload: Union[str, bytes], signature: str, secret: str) -> bool:
    """Verify webhook signature."""
    try:
        if isinstance(payload, str):
            payload = payload.encode('utf-8')
        
        # Extract algorithm and signature
        if '=' in signature:
            algorithm, received_signature = signature.split('=', 1)
        else:
            algorithm, received_signature = 'sha256', signature
        
        # Compute expected signature
        expected_signature = hmac.new(
            secret.encode('utf-8'),
            payload,
            getattr(hashlib, algorithm)
        ).hexdigest()
        
        # Use constant time comparison
        return hmac.compare_digest(expected_signature, received_signature)
        
    except Exception:
        return False


def safe_json_serialize(obj: Any) -> str:
    """Safely serialize object to JSON with datetime handling."""
    def json_serializer(obj):
        if isinstance(obj, datetime):
            return obj.isoformat()
        elif isinstance(obj, timedelta):
            return obj.total_seconds()
        elif hasattr(obj, '__dict__'):
            return obj.__dict__
        else:
            return str(obj)
    
    return json.dumps(obj, default=json_serializer, ensure_ascii=False)


def safe_json_deserialize(json_str: str, default: Any = None) -> Any:
    """Safely deserialize JSON string."""
    try:
        return json.loads(json_str)
    except (json.JSONDecodeError, TypeError):
        return default


def format_duration(seconds: float) -> str:
    """Format duration in seconds to human-readable string."""
    if seconds < 1:
        return f"{seconds * 1000:.0f}ms"
    elif seconds < 60:
        return f"{seconds:.1f}s"
    elif seconds < 3600:
        minutes = seconds / 60
        return f"{minutes:.1f}m"
    elif seconds < 86400:
        hours = seconds / 3600
        return f"{hours:.1f}h"
    else:
        days = seconds / 86400
        return f"{days:.1f}d"


def format_bytes(bytes_value: int) -> str:
    """Format bytes to human-readable string."""
    for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
        if bytes_value < 1024.0:
            return f"{bytes_value:.1f} {unit}"
        bytes_value /= 1024.0
    return f"{bytes_value:.1f} PB"


def chunk_list(lst: List[Any], chunk_size: int) -> List[List[Any]]:
    """Split a list into chunks of specified size."""
    return [lst[i:i + chunk_size] for i in range(0, len(lst), chunk_size)]


def flatten_dict(d: Dict[str, Any], parent_key: str = '', sep: str = '.') -> Dict[str, Any]:
    """Flatten a nested dictionary."""
    items = []
    for k, v in d.items():
        new_key = f"{parent_key}{sep}{k}" if parent_key else k
        if isinstance(v, dict):
            items.extend(flatten_dict(v, new_key, sep=sep).items())
        else:
            items.append((new_key, v))
    return dict(items)


def unflatten_dict(d: Dict[str, Any], sep: str = '.') -> Dict[str, Any]:
    """Unflatten a dictionary with dot-separated keys."""
    result = {}
    for key, value in d.items():
        parts = key.split(sep)
        current = result
        for part in parts[:-1]:
            if part not in current:
                current[part] = {}
            current = current[part]
        current[parts[-1]] = value
    return result


def merge_dicts(*dicts: Dict[str, Any]) -> Dict[str, Any]:
    """Merge multiple dictionaries recursively."""
    result = {}
    for dictionary in dicts:
        for key, value in dictionary.items():
            if key in result and isinstance(result[key], dict) and isinstance(value, dict):
                result[key] = merge_dicts(result[key], value)
            else:
                result[key] = value
    return result


def retry_async(max_attempts: int = 3, delay: float = 1.0, backoff_factor: float = 2.0):
    """Async retry decorator with exponential backoff."""
    def decorator(func):
        @wraps(func)
        async def wrapper(*args, **kwargs):
            last_exception = None
            for attempt in range(max_attempts):
                try:
                    return await func(*args, **kwargs)
                except Exception as e:
                    last_exception = e
                    if attempt < max_attempts - 1:
                        wait_time = delay * (backoff_factor ** attempt)
                        await asyncio.sleep(wait_time)
                    else:
                        raise last_exception
        return wrapper
    return decorator


def retry_sync(max_attempts: int = 3, delay: float = 1.0, backoff_factor: float = 2.0):
    """Synchronous retry decorator with exponential backoff."""
    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            last_exception = None
            for attempt in range(max_attempts):
                try:
                    return func(*args, **kwargs)
                except Exception as e:
                    last_exception = e
                    if attempt < max_attempts - 1:
                        wait_time = delay * (backoff_factor ** attempt)
                        time.sleep(wait_time)
                    else:
                        raise last_exception
        return wrapper
    return decorator


def rate_limit(calls_per_second: float):
    """Rate limiting decorator."""
    min_interval = 1.0 / calls_per_second
    last_called = [0.0]
    
    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            elapsed = time.time() - last_called[0]
            left_to_wait = min_interval - elapsed
            if left_to_wait > 0:
                time.sleep(left_to_wait)
            last_called[0] = time.time()
            return func(*args, **kwargs)
        return wrapper
    return decorator


async def rate_limit_async(calls_per_second: float):
    """Async rate limiting decorator."""
    min_interval = 1.0 / calls_per_second
    last_called = [0.0]
    
    def decorator(func):
        @wraps(func)
        async def wrapper(*args, **kwargs):
            elapsed = time.time() - last_called[0]
            left_to_wait = min_interval - elapsed
            if left_to_wait > 0:
                await asyncio.sleep(left_to_wait)
            last_called[0] = time.time()
            return await func(*args, **kwargs)
        return wrapper
    return decorator


class CircuitBreaker:
    """Circuit breaker pattern implementation."""
    
    def __init__(self, failure_threshold: int = 5, recovery_timeout: float = 60.0, expected_exception: Exception = Exception):
        self.failure_threshold = failure_threshold
        self.recovery_timeout = recovery_timeout
        self.expected_exception = expected_exception
        self.failure_count = 0
        self.last_failure_time = None
        self.state = 'closed'  # closed, open, half-open
    
    def __call__(self, func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            return self._call(func, *args, **kwargs)
        return wrapper
    
    def _call(self, func, *args, **kwargs):
        if self.state == 'open':
            if self._should_attempt_reset():
                self.state = 'half-open'
            else:
                raise Exception("Circuit breaker is open")
        
        try:
            result = func(*args, **kwargs)
            self._on_success()
            return result
        except self.expected_exception as e:
            self._on_failure()
            raise e
    
    def _should_attempt_reset(self) -> bool:
        return (self.last_failure_time and 
                time.time() - self.last_failure_time >= self.recovery_timeout)
    
    def _on_success(self):
        self.failure_count = 0
        self.state = 'closed'
    
    def _on_failure(self):
        self.failure_count += 1
        self.last_failure_time = time.time()
        if self.failure_count >= self.failure_threshold:
            self.state = 'open'


class AsyncCircuitBreaker:
    """Async circuit breaker pattern implementation."""
    
    def __init__(self, failure_threshold: int = 5, recovery_timeout: float = 60.0, expected_exception: Exception = Exception):
        self.failure_threshold = failure_threshold
        self.recovery_timeout = recovery_timeout
        self.expected_exception = expected_exception
        self.failure_count = 0
        self.last_failure_time = None
        self.state = 'closed'  # closed, open, half-open
    
    def __call__(self, func):
        @wraps(func)
        async def wrapper(*args, **kwargs):
            return await self._call(func, *args, **kwargs)
        return wrapper
    
    async def _call(self, func, *args, **kwargs):
        if self.state == 'open':
            if self._should_attempt_reset():
                self.state = 'half-open'
            else:
                raise Exception("Circuit breaker is open")
        
        try:
            result = await func(*args, **kwargs)
            self._on_success()
            return result
        except self.expected_exception as e:
            self._on_failure()
            raise e
    
    def _should_attempt_reset(self) -> bool:
        return (self.last_failure_time and 
                time.time() - self.last_failure_time >= self.recovery_timeout)
    
    def _on_success(self):
        self.failure_count = 0
        self.state = 'closed'
    
    def _on_failure(self):
        self.failure_count += 1
        self.last_failure_time = time.time()
        if self.failure_count >= self.failure_threshold:
            self.state = 'open'


def validate_email(email: str) -> bool:
    """Validate email address format."""
    import re
    pattern = r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$'
    return re.match(pattern, email) is not None


def validate_url(url: str) -> bool:
    """Validate URL format."""
    import re
    pattern = r'^https?://(?:[-\w.])+(?:[:\d]+)?(?:/(?:[\w/_.])*(?:\?(?:[\w&=%.])*)?(?:#(?:\w*))?)?$'
    return re.match(pattern, url) is not None


def sanitize_filename(filename: str) -> str:
    """Sanitize filename by removing invalid characters."""
    import re
    # Remove or replace invalid characters
    filename = re.sub(r'[<>:"/\\|?*]', '_', filename)
    # Remove control characters
    filename = re.sub(r'[\x00-\x1F\x7F]', '', filename)
    # Limit length
    if len(filename) > 255:
        name, ext = filename.rsplit('.', 1) if '.' in filename else (filename, '')
        max_name_length = 255 - len(ext) - 1 if ext else 255
        filename = f"{name[:max_name_length]}.{ext}" if ext else name[:255]
    
    return filename.strip('. ')


def get_nested_value(dictionary: Dict[str, Any], key_path: str, default: Any = None, separator: str = '.') -> Any:
    """Get nested dictionary value using dot notation."""
    keys = key_path.split(separator)
    value = dictionary
    
    try:
        for key in keys:
            value = value[key]
        return value
    except (KeyError, TypeError):
        return default


def set_nested_value(dictionary: Dict[str, Any], key_path: str, value: Any, separator: str = '.') -> None:
    """Set nested dictionary value using dot notation."""
    keys = key_path.split(separator)
    current = dictionary
    
    for key in keys[:-1]:
        if key not in current or not isinstance(current[key], dict):
            current[key] = {}
        current = current[key]
    
    current[keys[-1]] = value


def get_utc_now() -> datetime:
    """Get current UTC datetime."""
    return datetime.now(timezone.utc)


def get_timestamp() -> int:
    """Get current timestamp in seconds."""
    return int(time.time())


def get_timestamp_ms() -> int:
    """Get current timestamp in milliseconds."""
    return int(time.time() * 1000)


def parse_iso_datetime(iso_string: str) -> Optional[datetime]:
    """Parse ISO 8601 datetime string."""
    try:
        return datetime.fromisoformat(iso_string.replace('Z', '+00:00'))
    except (ValueError, AttributeError):
        return None


def truncate_string(text: str, max_length: int, suffix: str = '...') -> str:
    """Truncate string to maximum length with optional suffix."""
    if len(text) <= max_length:
        return text
    
    truncate_length = max_length - len(suffix)
    return text[:truncate_length] + suffix if truncate_length > 0 else suffix[:max_length]


def mask_sensitive_data(text: str, mask_char: str = '*', preserve_length: int = 4) -> str:
    """Mask sensitive data while preserving some characters."""
    if len(text) <= preserve_length * 2:
        return mask_char * len(text)
    
    preserved_start = text[:preserve_length]
    preserved_end = text[-preserve_length:]
    masked_middle = mask_char * (len(text) - preserve_length * 2)
    
    return preserved_start + masked_middle + preserved_end


def is_valid_uuid(uuid_string: str) -> bool:
    """Check if string is a valid UUID."""
    try:
        uuid.UUID(uuid_string)
        return True
    except (ValueError, TypeError):
        return False


def calculate_exponential_backoff(attempt: int, base_delay: float = 1.0, max_delay: float = 60.0, jitter: bool = True) -> float:
    """Calculate exponential backoff delay with optional jitter."""
    delay = min(base_delay * (2 ** attempt), max_delay)
    
    if jitter:
        # Add random jitter (±25%)
        jitter_range = delay * 0.25
        delay += (secrets.randbelow(int(jitter_range * 2 * 1000)) / 1000) - jitter_range
    
    return max(0, delay)


class BatchProcessor:
    """Utility for processing items in batches."""
    
    def __init__(self, batch_size: int = 100, max_workers: int = 5):
        self.batch_size = batch_size
        self.max_workers = max_workers
    
    async def process_async(self, items: List[Any], processor_func, **kwargs) -> List[Any]:
        """Process items asynchronously in batches."""
        batches = chunk_list(items, self.batch_size)
        results = []
        
        semaphore = asyncio.Semaphore(self.max_workers)
        
        async def process_batch(batch):
            async with semaphore:
                return await processor_func(batch, **kwargs)
        
        tasks = [process_batch(batch) for batch in batches]
        batch_results = await asyncio.gather(*tasks, return_exceptions=True)
        
        for result in batch_results:
            if isinstance(result, Exception):
                raise result
            results.extend(result if isinstance(result, list) else [result])
        
        return results
    
    def process_sync(self, items: List[Any], processor_func, **kwargs) -> List[Any]:
        """Process items synchronously in batches."""
        batches = chunk_list(items, self.batch_size)
        results = []
        
        for batch in batches:
            batch_result = processor_func(batch, **kwargs)
            results.extend(batch_result if isinstance(batch_result, list) else [batch_result])
        
        return results