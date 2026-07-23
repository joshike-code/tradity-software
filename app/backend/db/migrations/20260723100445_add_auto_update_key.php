<?php

use Phinx\Migration\AbstractMigration;

class AddAutoUpdateKey extends AbstractMigration
{
    public function up()
    {
        $key = 'auto_update';
        $value = 'no';

        $exists = $this->fetchRow("SELECT * FROM platform WHERE `key` = '$key'");

        if (!$exists) {
            $this->execute("INSERT INTO platform (`key`, `value`) VALUES ('$key', '$value')");
        }
    }

    public function down()
    {
        $this->execute("DELETE FROM platform WHERE `key` = 'auto_update'");
    }
}
