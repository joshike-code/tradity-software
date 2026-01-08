<?php

use Phinx\Migration\AbstractMigration;

class UpdateTradePriceToString extends AbstractMigration
{
    public function change()
    {
        $this->table('trades')
            ->changeColumn('price', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => null
            ])
            ->changeColumn('trade_price', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => null
            ])
            ->update();
    }
}
