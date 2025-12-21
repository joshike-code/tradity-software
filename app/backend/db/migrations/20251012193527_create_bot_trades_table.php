<?php

use Phinx\Migration\AbstractMigration;

class CreateBotTradesTable extends AbstractMigration
{
    public function change()
    {
        $this->table('bot_trades')
            ->addColumn('userid', 'integer', ['limit' => 100])
            ->addColumn('account', 'string', ['limit' => 100])
            ->addColumn('ref', 'string', ['limit' => 100])
            ->addColumn('trade_acc', 'enum', [
                'values' => ['demo', 'real'],
                'default' => null
            ])
            ->addColumn('stake', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('profit', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0'])
            ->addColumn('increment_rate', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('is_paused', 'enum', [
                'values' => ['false', 'true'],
                'default' => 'false'
            ])
            ->addColumn('start_time', 'datetime', ['precision' => 6])
            ->addColumn('paused_time', 'datetime', ['precision' => 6])
            ->addColumn('resume_time', 'datetime', ['precision' => 6])
            ->addColumn('close_time', 'datetime', ['precision' => 6])
            ->addIndex(['account'], ['unique' => true])
            ->create();
    }
}
