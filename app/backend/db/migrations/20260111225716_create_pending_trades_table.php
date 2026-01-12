<?php

use Phinx\Migration\AbstractMigration;

class CreatePendingTradesTable extends AbstractMigration
{
    public function change()
    {
        $this->table('pending_trades')
            ->addColumn('userid', 'integer', ['limit' => 100])
            ->addColumn('account', 'string', ['limit' => 100])
            ->addColumn('ref', 'string', ['limit' => 100])
            ->addColumn('pair', 'string', ['limit' => 100])
            ->addColumn('type', 'enum', [
                'values' => ['buy', 'sell'],
                'default' => null
            ])
            ->addColumn('order_type', 'enum', [
                'values' => ['buy_stop', 'sell_stop', 'buy_limit', 'sell_limit'],
                'default' => null
            ])
            ->addColumn('trigger_price', 'string', ['limit' => 100])
            ->addColumn('current_price', 'string', ['limit' => 100])
            ->addColumn('margin', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('lot', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('leverage', 'integer', ['limit' => 100])
            ->addColumn('stop_loss', 'string', ['limit' => 100])
            ->addColumn('take_profit', 'string', ['limit' => 100])
            ->addColumn('expiry_date', 'datetime', ['null' => true])
            ->addColumn('trade_acc', 'enum', [
                'values' => ['demo', 'real'],
                'default' => null
            ])
            ->addColumn('date', 'datetime')
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'triggered', 'cancelled', 'expired'],
                'default' => 'pending'
            ])
            ->create();
    }
}
