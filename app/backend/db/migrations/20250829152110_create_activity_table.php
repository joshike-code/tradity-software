<?php

use Phinx\Migration\AbstractMigration;

class CreateActivityTable extends AbstractMigration
{
    public function change()
    {
        $this->table('activity')
            ->addColumn('user_id', 'integer', ['limit' => 100])
            ->addColumn('action', 'string', ['limit' => 100])
            ->addColumn('browser', 'string', ['limit' => 100])
            ->addColumn('country',  'string', [
                'limit' => 5,
                'default' => 'US'
            ])
            ->addColumn('ip_address', 'string', ['limit' => 23])
            ->addColumn('status', 'string', ['limit' => 100])
            ->addColumn('date', 'datetime', ['precision' => 6])
            ->create();
    }
}
