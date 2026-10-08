<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProjectUploadTest extends ApiTestCase
{
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            @unlink($path);
        }
        foreach (glob(dirname(__DIR__, 2).'/var/test-uploads/*') ?: [] as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function file(string $type = 'png', bool $oversized = false, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'project-image-');
        $this->fixtures[] = $path;
        if ($type === 'text') {
            file_put_contents($path, '<?php echo "bad";');
        } else {
            $image = imagecreatetruecolor(2, 2);
            match ($type) {
                'jpeg' => imagejpeg($image, $path),
                'webp' => imagewebp($image, $path),
                default => imagepng($image, $path),
            };
            imagedestroy($image);
        }
        if ($oversized) {
            file_put_contents($path, str_repeat('x', 5 * 1024 * 1024), FILE_APPEND);
        }
        return new UploadedFile($path, 'misleading.php', 'image/jpeg', $error, true);
    }

    private function upload(array $files, string $token, array $fields = []): array
    {
        $data = array_replace($this->projectData(), ['numberOfFloors' => '3'], $fields);
        $this->client->request('POST', '/api/project', $data, $files, ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('imageTypes')]
    public function testValidImagesUseDetectedExtensions(string $type, string $extension): void
    {
        $token = $this->token($this->user());
        $body = $this->upload(['picture' => $this->file($type)], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\\.'.$extension.'$/', $body['data']['picture']);
        self::assertFileExists(dirname(__DIR__, 2).'/var/test-uploads/'.$body['data']['picture']);
        self::assertSame('01234', $body['data']['postalCode']);
    }

    public static function imageTypes(): array
    {
        return [['png', 'png'], ['jpeg', 'jpg'], ['webp', 'webp']];
    }

    public function testInvalidUploadsAndMultipartNumbers(): void
    {
        $token = $this->token($this->user());
        foreach ([['picture' => $this->file('text')], ['picture' => $this->file('png', true)], ['picture' => $this->file('png', false, UPLOAD_ERR_PARTIAL)], ['picture' => [$this->file()]], ['attachment' => $this->file()]] as $files) {
            $this->upload($files, $token);
            self::assertResponseStatusCodeSame(422);
        }
        foreach (['1.5', '1e2', '-1', '2147483648', str_repeat('9', 100)] as $number) {
            $this->upload([], $token, ['numberOfFloors' => $number]);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame([], glob(dirname(__DIR__, 2).'/var/test-uploads/*') ?: []);
    }

    public function testPersistenceFailureRemovesMovedPicture(): void
    {
        $token = $this->token($this->user());
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        if ($connection->getDatabasePlatform()->getName() !== 'sqlite') {
            self::markTestSkipped('Real SQLite trigger injects persistence failure; valid and invalid uploads run on both databases.');
        }
        $connection->executeStatement("CREATE TRIGGER reject_project BEFORE INSERT ON project BEGIN SELECT RAISE(ABORT, 'test persistence failure'); END");
        $body = $this->upload(['picture' => $this->file()], $token);
        self::assertResponseStatusCodeSame(500);
        self::assertSame('Internal Server Error', $body['error']['message']);
        self::assertSame([], glob(dirname(__DIR__, 2).'/var/test-uploads/*') ?: []);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM project'));
    }

    #[DataProvider('malformedTextFields')]
    public function testMalformedMultipartTextDoesNotPersist(string $field): void
    {
        $token = $this->token($this->user());
        $this->upload([], $token, [$field => "Home\xff"]);
        self::assertResponseStatusCodeSame(422);
        $body = $this->request('GET', '/api/project', null, $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    public static function malformedTextFields(): array
    {
        return [['name'], ['label'], ['address'], ['postalCode']];
    }

    public function testMalformedMultipartFieldNameReturnsJsonError(): void
    {
        $token = $this->token($this->user());
        $this->client->request('POST', '/api/project', array_replace($this->projectData(), ['numberOfFloors' => '3', "bad\xff" => 'value']), [], ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('error', json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
