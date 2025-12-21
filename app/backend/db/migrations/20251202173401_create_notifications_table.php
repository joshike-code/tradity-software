<?php

use Phinx\Migration\AbstractMigration;

class CreateNotificationsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('notifications')
            ->addColumn('user_id', 'integer', ['limit' => 100])
            ->addColumn('type', 'enum', [
                'values' => ['info', 'success', 'warning', 'alert'],
                'default' => 'info'
            ])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('message', 'text')
            ->addColumn('cta', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('cta_link', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_read', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['user_id'])
            ->addIndex(['is_read'])
            ->addIndex(['created_at'])
            ->create();
    }
}
