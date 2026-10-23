<?php

use Phinx\Migration\AbstractMigration;

class RemoveUniqueUserIdFromPendingWithdrawals extends AbstractMigration
{
    /**
     * Remove unique index on user_id in pending_withdrawals
     * Users should be able to submit withdrawals across multiple accounts or subsequent requests.
     * Replace with standard non-unique indexes on user_id and account for query performance.
     */
    public function change()
    {
        $table = $this->table('pending_withdrawals');
        
        // Remove the unique index on user_id
        $table->removeIndexByName('user_id')
              ->save();
        
        // Add non-unique indexes for fast lookups
        $table->addIndex(['user_id'])
              ->addIndex(['account'])
              ->save();
    }
}
