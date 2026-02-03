<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

class AddOnlineActivityToAccounts extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('accounts');
        $table->addColumn('online_status', 'enum', [
                'values' => ['online', 'away', 'offline'],
                'default' => 'offline',
                'after' => 'status'
            ])
            ->addColumn('last_activity', 'timestamp', [
                'null' => true,
                'after' => 'online_status'
            ])
            ->addColumn('last_heartbeat', 'timestamp', [
                'null' => true,
                'after' => 'last_activity'
            ])
            ->update();
    }
}
