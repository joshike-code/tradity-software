<?php

use Phinx\Migration\AbstractMigration;

class AddSwapConfig extends AbstractMigration
{
    public function up()
    {
        $key = 'swap_amount';
        $value = '0';

        $exists = $this->fetchRow("SELECT * FROM platform WHERE `key` = '$key'");

        if (!$exists) {
            $this->execute("INSERT INTO platform (`key`, `value`) VALUES ('$key', '$value')");
        }
    }

    public function down()
    {
        $this->execute("DELETE FROM platform WHERE `key` = 'swap_amount'");
    }
}
