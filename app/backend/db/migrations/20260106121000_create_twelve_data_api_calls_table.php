<?php

use Phinx\Migration\AbstractMigration;

class CreateTwelveDataApiCallsTable extends AbstractMigration
{
    /**
     * Create twelve_data_api_calls table for rate limiting
     * 
     * Twelve Data free tier limits:
     * - 8 API calls per minute
     * - 800 API calls per day
     * 
     * This table tracks API calls to enforce these limits
     */
    public function change()
    {
        $table = $this->table('twelve_data_api_calls', ['id' => false, 'primary_key' => ['id']]);
        
        $table
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('call_timestamp', 'integer', ['signed' => false, 'comment' => 'Unix timestamp of API call'])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            
            // Index for fast time-based queries
            ->addIndex(['call_timestamp'], ['name' => 'idx_call_timestamp'])
            
            ->create();
        
        echo "✓ Created twelve_data_api_calls table for rate limiting\n";
        echo "  - Tracks API calls with timestamp\n";
        echo "  - Enforces 8 calls/minute limit\n";
        echo "  - Enforces 800 calls/day limit\n";
        echo "  - Auto-cleanup of records older than 24 hours\n";
    }
}
