#!/usr/bin/env php
<?php declare(strict_types=1);
fwrite(STDERR, "[LEGACY] Reconciler replaced. Use CLI: php assets/services/queue/bin/reap-stale.php\n");
exit(1);
    
    /**
     * Ensure DLQ table exists
     */
    private function ensureDLQTable() {
        if (!$this->tableExists('queue_dlq')) {
            $sql = "CREATE TABLE queue_dlq (
                id INT AUTO_INCREMENT PRIMARY KEY,
                original_job_id INT NOT NULL,
                job_type VARCHAR(100) NOT NULL,
                trace_id VARCHAR(100) NOT NULL,
                failure_reason TEXT,
                moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                replayed_at TIMESTAMP NULL,
                INDEX idx_job_type (job_type),
                INDEX idx_moved_at (moved_at)
            )";
            
            $this->db->query($sql);
            echo "Created queue_dlq table\n";
        }
    }
    
    /**
     * Check if table exists
     */
    private function tableExists($tableName) {
        $sql = "SHOW TABLES LIKE ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0;
    }
    
    /**
     * Log event to transfer_logs
     */
    private function logEvent($eventType, $eventData, $traceId = null) {
        if (!$traceId) {
            $traceId = 'reconciler_' . time();
        }
        
        $sql = "INSERT INTO transfer_logs (event_type, event_data, source_system, trace_id, created_at)
                VALUES (?, ?, 'QueueReconciler', ?, NOW())";
        
        $eventDataJson = json_encode($eventData);
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sss', $eventType, $eventDataJson, $traceId);
        $stmt->execute();
    }
}

// CLI entry point for reconciler
if (php_sapi_name() === 'cli') {
    echo "CIS Queue Reconciler Starting...\n";
    
    $reconciler = new QueueReconciler();
    
    // Check for command line arguments
    $command = $argv[1] ?? 'reconcile';
    
    switch ($command) {
        case 'reconcile':
            $reconciler->reconcile();
            break;
            
        case 'stats':
            $stats = $reconciler->getHealthStats();
            echo "Queue Health Statistics:\n";
            echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'replay':
            if (!isset($argv[2])) {
                echo "Usage: php reconciler.php replay <dlq_id>\n";
                exit(1);
            }
            $dlqId = (int)$argv[2];
            $newJobId = $reconciler->replayDLQJob($dlqId);
            echo "Replayed DLQ job {$dlqId} as new job {$newJobId}\n";
            break;
            
        default:
            echo "Usage: php reconciler.php [reconcile|stats|replay]\n";
            exit(1);
    }
}
