<?php

use Phinx\Migration\AbstractMigration;

class AddStartTimestampToAlterTrades extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('alter_trades');
        $table->addColumn('start_timestamp', 'integer', [
            'null' => false,
            'default' => 0,
            'comment' => 'Unix timestamp for WebSocket processing (avoids timezone issues)'
        ])
        ->update();
    }
}
