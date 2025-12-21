<?php

use Phinx\Migration\AbstractMigration;

class AddLivechatKey extends AbstractMigration
{
    public function up()
    {
        $key = 'tawk_src_url';
        $value = '******';

        $exists = $this->fetchRow("SELECT * FROM platform WHERE `key` = '$key'");

        if (!$exists) {
            $this->execute("INSERT INTO platform (`key`, `value`) VALUES ('$key', '$value')");
        }
    }

    public function down()
    {
        $this->execute("DELETE FROM platform WHERE `key` = 'tawk_src_url'");
    }
}
