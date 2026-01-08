<?php

use Phinx\Migration\AbstractMigration;

class CreateHistoricalCandlesCacheTable extends AbstractMigration
{
    /**
     * Create historical_candles_cache table for smart caching of OHLC data
     * 
     * This table stores fetched candles from Twelve Data API to minimize API calls
     * and improve performance. Cache entries are keyed by pair, interval, and timestamp.
     */
    public function change()
    {
        $table = $this->table('historical_candles_cache', ['id' => false, 'primary_key' => ['id']]);
        
        $table
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('pair', 'string', ['limit' => 20, 'comment' => 'Trading pair (e.g., EUR/USD)'])
            ->addColumn('interval', 'string', ['limit' => 10, 'comment' => 'Candle interval (1m, 5m, 1h, 1d, etc.)'])
            ->addColumn('timestamp', 'biginteger', ['signed' => false, 'comment' => 'Candle open timestamp in milliseconds'])
            ->addColumn('open', 'string', ['limit' => 30, 'comment' => 'Open price as string (preserves decimals)'])
            ->addColumn('high', 'string', ['limit' => 30, 'comment' => 'High price as string (preserves decimals)'])
            ->addColumn('low', 'string', ['limit' => 30, 'comment' => 'Low price as string (preserves decimals)'])
            ->addColumn('close', 'string', ['limit' => 30, 'comment' => 'Close price as string (preserves decimals)'])
            ->addColumn('volume', 'string', ['limit' => 30, 'null' => true, 'comment' => 'Volume as string (if available)'])
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'twelve_data', 'comment' => 'Data source (twelve_data, finnhub, etc.)'])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            
            // Unique constraint: One candle per pair+interval+timestamp
            ->addIndex(['pair', 'interval', 'timestamp'], ['unique' => true, 'name' => 'idx_unique_candle'])
            
            // Query optimization indexes
            ->addIndex(['pair', 'interval', 'timestamp'], ['name' => 'idx_pair_interval_time'])
            ->addIndex(['timestamp'], ['name' => 'idx_timestamp'])
            ->addIndex(['created_at'], ['name' => 'idx_created_at'])
            
            ->create();
        
        echo "✓ Created historical_candles_cache table for smart caching\n";
        echo "  - Stores OHLC data as strings (preserves decimal precision)\n";
        echo "  - Unique constraint on pair+interval+timestamp\n";
        echo "  - Optimized indexes for fast lookups\n";
        echo "  - Reduces Twelve Data API calls (800/day limit)\n";
    }
}
