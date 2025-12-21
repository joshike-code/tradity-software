<?php

use Phinx\Migration\AbstractMigration;

class AddLastPriceToPairs extends AbstractMigration
{
    public function change()
    {
        $this->table('pairs')
            ->addColumn('last_price', 'decimal', [
                'precision' => 20,
                'scale' => 8,
                'null' => true,
                'default' => null,
                'after' => 'status',
                'comment' => 'Last known price for the pair (fallback when WebSocket is unavailable)'
            ])
            ->update();
    }
}
