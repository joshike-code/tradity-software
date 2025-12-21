<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

class CreateKycDataTable extends AbstractMigration
{
    public function change()
    {
        $this->table('kyc_data', ['id' => false, 'primary_key' => ['key']])
            ->addColumn('key', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('value', 'text')
            ->create();
    }
}