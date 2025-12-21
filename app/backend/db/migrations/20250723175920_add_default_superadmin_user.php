<?php

use Phinx\Migration\AbstractMigration;
require_once __DIR__ . '/../../services/TradeAccountService.php';

class AddDefaultSuperadminUser extends AbstractMigration
{
    public function up()
    {
        $hashedPassword = password_hash('1234', PASSWORD_DEFAULT);
        $id_hash = rand(1000000000, 9999999999);

        $this->execute("
            INSERT INTO users (
                role, permissions, avatar, fname, lname, email, phone, country, current_account,
                ref_code, referred_by, password, otp_2fa, date_registered
            ) VALUES (
                'superadmin', NULL, 'bundle/account/avatars/err.png', 'super', 'admin', 'owner@tradity.com',
                '', NULL, '{$id_hash}', '', NULL, '{$hashedPassword}', 'no', CURRENT_TIMESTAMP
            )
        ");

        $user_id = ''; //Get the insert_id
        TradeAccountService::createAccount($user_id, 'demo', 10000);
    }

    public function down()
    {
        // Optional: remove the user during rollback
        $this->execute("DELETE FROM users WHERE email = 'owner@tradity.com'");
    }
}