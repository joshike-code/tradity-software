<?php

use Phinx\Migration\AbstractMigration;

class UpdateTradeSltpToString extends AbstractMigration
{
    public function change()
    {
        $this->table('trades')
            ->changeColumn('stop_loss', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => null
            ])
            ->changeColumn('take_profit', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => null
            ])
            ->update();
    }
}
