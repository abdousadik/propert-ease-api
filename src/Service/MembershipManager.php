<?php

namespace App\Service;

use App\Enum\MembershipRole;
use App\Exception\MembershipChangeRejected;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Membership writers serialize on the organization row before reading permissions
 * or counting Owners. The lock lasts until the transaction commits or rolls back.
 * See README's membership transaction contract before adding another writer.
 */
final class MembershipManager
{
    public function __construct(private readonly Connection $connection)
    {
    }

    private function mutate(
        int $organizationId,
        int $membershipId,
        int $actorId,
        ?MembershipRole $newRole
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $organizationId,
            $membershipId,
            $actorId,
            $newRole
        ): void {
            $actorRole = $this->lockAndGetActorRole(
                $connection,
                $organizationId,
                $actorId
            );

            $targetRole = $connection->fetchOne(
                'SELECT role FROM membership WHERE organization_id = ? AND id = ?',
                [$organizationId, $membershipId]
            );

            if ($targetRole === false) {
                throw new MembershipChangeRejected(
                    'not_found', 404, 'Membership not found.'
                );
            }

            if ($actorRole !== MembershipRole::Owner->value) {
                throw new MembershipChangeRejected(
                    'forbidden', 403, 'Only Owners can manage memberships.'
                );
            }

            if (
                $targetRole === MembershipRole::Owner->value
                && $newRole !== MembershipRole::Owner
            ) {
                $ownerCount = (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM membership WHERE organization_id = ? AND role = ?',
                    [$organizationId, MembershipRole::Owner->value]
                );

                if ($ownerCount <= 1) {
                    throw new MembershipChangeRejected(
                        'last_owner',
                        409,
                        'The organization must retain at least one Owner.'
                    );
                }
            }

            if ($newRole === null) {
                $connection->executeStatement(
                    'DELETE FROM membership WHERE organization_id = ? AND id = ?',
                    [$organizationId, $membershipId]
                );
            } else {
                $connection->executeStatement(
                    'UPDATE membership SET role = ? WHERE organization_id = ? AND id = ?',
                    [$newRole->value, $organizationId, $membershipId]
                );
            }
        });
    }

    public function change(
        int $organizationId,
        int $membershipId,
        int $actorId,
        MembershipRole $newRole
    ): void {
        $this->mutate($organizationId, $membershipId, $actorId, $newRole);
    }

    public function remove(
        int $organizationId,
        int $membershipId,
        int $actorId
    ): void {
        $this->mutate($organizationId, $membershipId, $actorId, null);
    }

    private function lockAndGetActorRole(
        Connection $connection,
        int $organizationId,
        int $actorId
    ): string {
        $connection->executeStatement(
            'UPDATE organization SET name = name WHERE id = ?',
            [$organizationId]
        );

        $organizationExists = $connection->fetchOne(
            'SELECT id FROM organization WHERE id = ?',
            [$organizationId]
        );

        $actorRole = $connection->fetchOne(
            'SELECT role FROM membership WHERE organization_id = ? AND user_id = ?',
            [$organizationId, $actorId]
        );

        if ($organizationExists === false || $actorRole === false) {
            throw new MembershipChangeRejected(
                'not_found', 404, 'Organization not found.'
            );
        }

        return $actorRole;
    }

    public function add(
        int $organizationId,
        int $actorId,
        string $email,
        MembershipRole $role
    ): array {
        try {
            return $this->connection->transactional(
                function (Connection $connection) use (
                    $organizationId,
                    $actorId,
                    $email,
                    $role
                ): array {
                    $actorRole = $this->lockAndGetActorRole(
                        $connection,
                        $organizationId,
                        $actorId
                    );

                    if ($actorRole !== MembershipRole::Owner->value) {
                        throw new MembershipChangeRejected(
                            'forbidden',
                            403,
                            'Only Owners can manage memberships.'
                        );
                    }

                    $user = $connection->fetchAssociative(
                        'SELECT id, first_name, last_name FROM user WHERE email = ?',
                        [$email]
                    );

                    if ($user === false) {
                        throw new MembershipChangeRejected(
                            'user_not_found',
                            404,
                            'No registered user matches this email.'
                        );
                    }

                    $connection->insert('membership', [
                        'organization_id' => $organizationId,
                        'user_id' => $user['id'],
                        'role' => $role->value,
                    ]);

                    return [
                        'id' => (int) $connection->lastInsertId(),
                        'user' => [
                            'id' => (int) $user['id'],
                            'firstName' => $user['first_name'],
                            'lastName' => $user['last_name'],
                        ],
                        'role' => $role->value,
                    ];
                }
            );
        } catch (UniqueConstraintViolationException) {
            throw new MembershipChangeRejected(
                'membership_already_exists',
                409,
                'This user already belongs to the organization.'
            );
        }
    }
}