#!/usr/bin/env php
<?php declare(strict_types=1);
fwrite(STDERR, "[LEGACY] Worker replaced. Use CLI: php assets/services/queue/bin/run-jobs.php --limit=200 --type=pull_products\n");
exit(1);
    
    /**
     * Execute job based on type
     */
    private function executeJob($jobType, $payload, $traceId) {
        switch ($jobType) {
            case VendConsignmentClient::JOB_CONSIGNMENT_CREATE:
                return $this->executeVendRequest('POST', $payload['endpoint'], [
                    'type' => $payload['type'],
                    'data' => $payload['consignment_data']
                ], $payload, $traceId);
                
            case VendConsignmentClient::JOB_CONSIGNMENT_ADD_LINE:
                return $this->executeVendRequest('POST', $payload['endpoint'], 
                    $payload['lines'], $payload, $traceId);
                
            case VendConsignmentClient::JOB_CONSIGNMENT_STATUS:
                return $this->executeVendRequest('PUT', $payload['endpoint'], [
                    'status' => $payload['status']
                ], $payload, $traceId);
                
            default:
                echo "Unknown job type: {$jobType}\n";
                return false;
        }
    }
    
    /**
     * Execute Vend API request with proper error handling
     */
    private function executeVendRequest($method, $endpoint, $data, $payload, $traceId) {
        $vendConfig = $this->getVendConfig();
        $url = $vendConfig['base_url'] . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $vendConfig['access_token'],
                'Content-Type: application/json',
                'X-Idempotency-Key: ' . ($payload['idempotency_key'] ?? ''),
            ],
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log the request/response
        $this->logVendCall($method, $url, $data, $response, $httpCode, $traceId);
        
        // Handle HTTP response codes
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->logEvent('QUEUE_SUCCESS', [
                'method' => $method,
                'endpoint' => $endpoint,
                'http_code' => $httpCode
            ], $traceId);
            return true;
            
        } elseif ($httpCode === 401) {
            throw new Exception("Vend token expired (401)");
            
        } elseif ($httpCode === 409) {
            // Conflict - often means duplicate request (idempotency worked)
            echo "Vend conflict (409) - treating as success\n";
            return true;
            
        } elseif ($httpCode === 429) {
            throw new Exception("Vend rate limited (429)");
            
        } elseif ($httpCode >= 500) {
            throw new Exception("Vend server error ({$httpCode})");
            
        } else {
            $this->logEvent('QUEUE_FAIL', [
                'method' => $method,
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response' => substr($response, 0, 500)
            ], $traceId);
            return false;
        }
    }
    
    // Helper methods...
    
    private function lockJob($jobId) {
        $sql = "UPDATE queue_jobs 
                SET status = 'processing', 
                    locked_by = ?, 
                    locked_at = NOW(),
                    attempts = attempts + 1
                WHERE id = ? AND status = 'queued'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('si', $this->workerId, $jobId);
        $stmt->execute();
        
        return $stmt->affected_rows > 0;
    }
    
    private function updateHeartbeat($jobId) {
        $sql = "UPDATE queue_jobs SET locked_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
    }
    
    private function markJobSuccess($jobId, $traceId) {
        $sql = "UPDATE queue_jobs 
                SET status = 'success', 
                    completed_at = NOW(),
                    locked_by = NULL,
                    locked_at = NULL
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        
        echo "Job {$jobId} completed successfully\n";
    }
    
    private function handleJobFailure($job, $errorMessage = null) {
        $jobId = $job['id'];
        $attempts = $job['attempts'];
        $maxAttempts = $job['max_attempts'];
        $traceId = $job['trace_id'];
        
        if ($attempts >= $maxAttempts) {
            // Max retries reached - mark as failed
            $sql = "UPDATE queue_jobs 
                    SET status = 'failed',
                        last_error = ?,
                        completed_at = NOW(),
                        locked_by = NULL,
                        locked_at = NULL
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('si', $errorMessage, $jobId);
            $stmt->execute();
            
            $this->logEvent('QUEUE_FAIL', [
                'job_id' => $jobId,
                'attempts' => $attempts,
                'error' => $errorMessage
            ], $traceId);
            
            echo "Job {$jobId} failed permanently after {$attempts} attempts\n";
            return true;
            
        } else {
            // Retry later with exponential backoff
            $backoffSeconds = min(300, pow(2, $attempts) * 10);
            $retryAt = date('Y-m-d H:i:s', time() + $backoffSeconds);
            
            $sql = "UPDATE queue_jobs 
                    SET status = 'queued',
                        last_error = ?,
                        retry_at = ?,
                        locked_by = NULL,
                        locked_at = NULL
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('ssi', $errorMessage, $retryAt, $jobId);
            $stmt->execute();
            
            $this->logEvent('QUEUE_RETRY', [
                'job_id' => $jobId,
                'attempt' => $attempts,
                'retry_in_seconds' => $backoffSeconds,
                'error' => $errorMessage
            ], $traceId);
            
            echo "Job {$jobId} will retry in {$backoffSeconds} seconds\n";
            return true;
        }
    }
    
    private function getVendConfig() {
        $sql = "SELECT id, config_value FROM configuration WHERE id IN (21, 22, 23, 40)";
        $result = $this->db->query($sql);
        
        $config = [];
        while ($row = $result->fetch_assoc()) {
            $config[$row['id']] = $row['config_value'];
        }
        
        $mode = $config[40] ?? 'live';
        $baseUrl = 'https://secure.vendhq.com/api';
        
        return [
            'base_url' => $baseUrl,
            'access_token' => $config[23] ?? ''
        ];
    }
    
    private function getBatchSize() {
        $hour = (int)date('H');
        $isBusinessHours = ($hour >= 9 && $hour <= 17);
        
        return $isBusinessHours ? 
            $this->config[self::QUEUE_BATCH_SIZE_BUSINESS] :
            $this->config[self::QUEUE_BATCH_SIZE_OFFHOURS];
    }
    
    private function getMaxRuntime() {
        $hour = (int)date('H');
        $isBusinessHours = ($hour >= 9 && $hour <= 17);
        
        return $isBusinessHours ?
            $this->config[self::QUEUE_RUNTIME_BUSINESS] :
            $this->config[self::QUEUE_RUNTIME_OFFHOURS];
    }
    
    private function logEvent($eventType, $eventData, $traceId) {
        $sql = "INSERT INTO transfer_logs (event_type, event_data, source_system, trace_id, created_at)
                VALUES (?, ?, 'QueueWorker', ?, NOW())";
        
        $eventDataJson = json_encode($eventData);
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sss', $eventType, $eventDataJson, $traceId);
        $stmt->execute();
    }
    
    private function logVendCall($method, $url, $requestData, $response, $httpCode, $traceId) {
        $logData = [
            'method' => $method,
            'url' => $url,
            'request' => $requestData,
            'response_code' => $httpCode,
            'response' => substr($response, 0, 1000),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $this->logEvent('VEND_API_CALL', $logData, $traceId);
    }
    
    private function logJobError($jobId, $traceId, $errorMessage) {
        $this->logEvent('JOB_ERROR', [
            'job_id' => $jobId,
            'error' => $errorMessage,
            'worker_id' => $this->workerId
        ], $traceId);
    }
    
    private function logError($eventType, $message) {
        $this->logEvent($eventType, [
            'message' => $message,
            'worker_id' => $this->workerId
        ], 'worker_' . time());
    }
    
    public function shutdown() {
        $this->isRunning = false;
    }
}

// CLI entry point
if (php_sapi_name() === 'cli') {
    echo "CIS Queue Worker Starting...\n";
    
    $worker = new QueueWorker();
    
    // Handle shutdown signals
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use ($worker) {
            echo "Received SIGTERM, shutting down gracefully...\n";
            $worker->shutdown();
        });
        
        pcntl_signal(SIGINT, function() use ($worker) {
            echo "Received SIGINT, shutting down gracefully...\n";
            $worker->shutdown();
        });
    }
    
    $worker->run();
}
