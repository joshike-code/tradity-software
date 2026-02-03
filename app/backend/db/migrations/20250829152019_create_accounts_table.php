<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

class CreateAccountsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('accounts')
            ->addColumn('user_id', 'integer', ['limit' => 100])
            ->addColumn('id_hash', 'string', ['limit' => 100])
            ->addColumn('type', 'enum', [
                'values' => ['demo', 'real'],
                'default' => 'demo'
            ])
            ->addColumn('balance', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0'])
            ->addColumn('leverage', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_REGULAR, 'default' => 1000])
            ->addColumn('first_deposit', 'enum', [
                'values' => ['no', 'yes'],
                'default' => 'no'
            ])
            ->addColumn('status', 'enum', [
                'values' => ['active', 'suspended', 'closed'],
                'default' => 'active'
            ])
            ->addColumn('date', 'timestamp', [
                'default' => Literal::from('CURRENT_TIMESTAMP')
            ])
            ->create();
    }
}
