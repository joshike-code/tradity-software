<?php

use Phinx\Migration\AbstractMigration;

class AddCommissionConfig extends AbstractMigration
{
    public function up()
    {
        $key = 'commission_amount';
        $value = '0';

        $exists = $this->fetchRow("SELECT * FROM platform WHERE `key` = '$key'");

        if (!$exists) {
            $this->execute("INSERT INTO platform (`key`, `value`) VALUES ('$key', '$value')");
        }
    }

    public function down()
    {
        $this->execute("DELETE FROM platform WHERE `key` = 'commission_amount'");
    }
}
