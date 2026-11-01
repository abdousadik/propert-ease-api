<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/** Test-only fault injection after a real membership write, before commit. */
final class FailingMembershipConnection extends Connection
{
    public bool $failAfterMembershipWrite = false;

    public function executeStatement($sql, array $params = [], array $types = [])
    {
        $affected = parent::executeStatement($sql, $params, $types);

        if (
            $this->failAfterMembershipWrite
            && preg_match('/^(UPDATE membership SET|DELETE FROM membership|INSERT INTO membership)/i', $sql) === 1
        ) {
            $this->failAfterMembershipWrite = false;
            throw new \RuntimeException('Injected failure after membership SQL.');
        }

        return $affected;
    }
}
