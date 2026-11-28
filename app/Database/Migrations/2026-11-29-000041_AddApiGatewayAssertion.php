<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WorkSuite API Gateway (docs/api-gateway.md): the API console credential an API key answers for
 * (`api_keys.gateway_credential_id`, matched against the assertion's `cred`), and the seen assertion
 * jtis (hashed, kept until they expire) shared by every PHP-FPM worker so an assertion can't be replayed.
 */
class AddApiGatewayAssertion extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('gateway_credential_id', 'api_keys')) {
            $this->forge->addColumn('api_keys', [
                'gateway_credential_id' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            ]);
            $this->forge->addKey('gateway_credential_id', false, false, 'api_keys_gateway_credential_id')->processIndexes('api_keys');
        }
        if (! $this->db->tableExists('gateway_seen_jti')) {
            $this->forge->addField([
                'jti'        => ['type' => 'CHAR', 'constraint' => 64],
                'expires_at' => ['type' => 'DATETIME'],
            ])->addKey('jti', true)->addKey('expires_at')->createTable('gateway_seen_jti');
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('gateway_seen_jti', true);
        if ($this->db->fieldExists('gateway_credential_id', 'api_keys')) {
            $this->forge->dropKey('api_keys', 'api_keys_gateway_credential_id', false);
            $this->forge->dropColumn('api_keys', 'gateway_credential_id');
        }
    }
}
