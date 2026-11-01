<?php

namespace App\Tests\Functional;

use App\Enum\MembershipRole;
use App\Tests\Support\CreatesProjectAccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProjectDateValidationTest extends WebTestCase
{
    use CreatesProjectAccessFixtures;

    #[DataProvider('dateInputs')]
    public function testNulDateIsRejectedWithoutChangingProjects(string $method, string $field): void
    {
        $client = static::createClient();
        [$organization, $project, , , $token] =
            $this->createProjectAccessFixtures(MembershipRole::Owner);
        $project->setDeliveryDate(new \DateTimeImmutable('2027-06-01 12:00:00', new \DateTimeZone('UTC')));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $before = $em->getConnection()->fetchAllAssociative('SELECT * FROM project ORDER BY id');
        $url = '/api/organizations/'.$organization->getId().'/projects';
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $payload = [$field => "2027-06-01 12:00:00\0"];

        if ($method === 'GET') {
            $client->request('GET', $url, $payload, server: $headers);
        } else {
            $payload['label'] = 'Must not be saved';
            if ($method === 'POST') {
                $payload += ['name' => 'Rejected project', 'address' => 'Example address',
                    'postalCode' => '20000', 'numberOfFloors' => 1];
            }
            $client->jsonRequest($method, $method === 'PATCH' ? $url.'/'.$project->getId() : $url,
                $payload, $headers);
        }

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['error' => [
            'code' => 'validation_failed',
            'message' => $method === 'GET' ? 'Invalid search filters.' : 'Invalid project data.',
            'details' => [$field => 'Must be null or a valid UTC date in YYYY-MM-DD HH:MM:SS format.'],
        ]], json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($before, static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()->fetchAllAssociative('SELECT * FROM project ORDER BY id'));
    }

    public static function dateInputs(): iterable
    {
        yield 'create' => ['POST', 'deliveryDate'];
        yield 'patch' => ['PATCH', 'deliveryDate'];
        yield 'minimum search bound' => ['GET', 'deliveryDateMin'];
        yield 'maximum search bound' => ['GET', 'deliveryDateMax'];
    }
}
