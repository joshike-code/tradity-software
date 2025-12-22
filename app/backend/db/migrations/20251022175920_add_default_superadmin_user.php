<?php

use Phinx\Migration\AbstractMigration;

class AddDefaultSuperadminUser extends AbstractMigration
{
    public function up()
    {
        $hashedPassword = password_hash('1234', PASSWORD_DEFAULT);
        $id_hash = rand(1000000000, 9999999999);

        // Insert the superadmin user
        $this->execute("
            INSERT INTO users (
                role, permissions, avatar, fname, lname, email, phone, country, current_account,
                ref_code, referred_by, password, otp_2fa, date_registered
            ) VALUES (
                'superadmin', NULL, 'bundle/account/avatars/err.png', 'super', 'admin', 'owner@tradity.com',
                '', NULL, '{$id_hash}', '', NULL, '{$hashedPassword}', 'no', CURRENT_TIMESTAMP
            )
        ");

        // Get the inserted user ID
        $userId = $this->getAdapter()->getConnection()->lastInsertId();

        // Create demo account for the superadmin
        $this->execute("
            INSERT INTO accounts (user_id, id_hash, type, balance, leverage, date)
            VALUES (
                {$userId}, {$id_hash}, 'demo', 0.00, 1000, CURRENT_TIMESTAMP
            )
        ");
    }

    public function down()
    {
        // Get user ID before deleting
        $result = $this->fetchRow("SELECT id FROM users WHERE email = 'owner@tradity.com'");
        
        if ($result) {
            $userId = $result['id'];
            
            // Delete associated accounts first (foreign key constraint)
            $this->execute("DELETE FROM accounts WHERE user = {$userId}");
            
            // Then delete the user
            $this->execute("DELETE FROM users WHERE id = {$userId}");
        }
    }
}