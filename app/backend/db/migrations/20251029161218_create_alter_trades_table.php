<?php

use Phinx\Migration\AbstractMigration;

class CreateAlterTradesTable extends AbstractMigration
{
    public function change()
    {
        $this->table('alter_trades')
            ->addColumn('trade_ref', 'string', ['limit' => 100])
            ->addColumn('pair', 'string', ['limit' => 100])
            ->addColumn('account', 'string', ['limit' => 100])
            ->addColumn('acc_type', 'enum', [
                'values' => ['demo', 'real'],
                'default' => null
            ])
            ->addColumn('account_pair', 'string', ['limit' => 100])
            ->addColumn('start_price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('target_price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('time', 'string', ['limit' => 100, 'default' => '0'])
            ->addColumn('alter_mode', 'enum', [
                'values' => ['trade', 'pair', 'account_pair'],
                'default' => null
            ])
            ->addColumn('alter_chart', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'false'
            ])
            ->addColumn('close', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'false'
            ])
            ->addColumn('reason', 'enum', [
                'values' => ['admin', 'tutorial'],
                'default' => 'admin'
            ])
            ->addColumn('date', 'datetime', ['precision' => 6])
            ->addIndex(['trade_ref'], ['unique' => true])
            ->addIndex(['pair'], ['unique' => true])
            ->create();
    }
}
