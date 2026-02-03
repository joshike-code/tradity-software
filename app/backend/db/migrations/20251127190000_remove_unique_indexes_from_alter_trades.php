<?php

use Phinx\Migration\AbstractMigration;

class RemoveUniqueIndexesFromAlterTrades extends AbstractMigration
{
    /**
     * Remove unique indexes on trade_ref and pair columns
     * These were preventing multiple alters from existing for the same trade/pair
     * which is needed for account_pair mode to coexist with pair mode
     */
    public function change()
    {
        $table = $this->table('alter_trades');
        
        // Remove the unique indexes
        $table->removeIndexByName('trade_ref')
              ->removeIndexByName('pair')
              ->save();
        
        // Optionally add them back as non-unique indexes for query performance
        $table->addIndex(['trade_ref'])
              ->addIndex(['pair'])
              ->addIndex(['account', 'pair'])  // Composite index for account_pair lookups
              ->save();
    }
}
