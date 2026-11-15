<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SuspiciousRequest;
use App\Repository\SuspiciousRequestRepository;
use App\Service\BotDetectorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class BotDetectorServiceTest extends TestCase
{
    public static function counts(): iterable
    {
        yield 'zero' => [0, false];
        yield 'below threshold' => [29, false];
        yield 'exact threshold' => [30, false];
        yield 'above threshold' => [31, true];
    }

    #[DataProvider('counts')]
    public function testUsesOneCacheReadWithExplicitTtl(int $count, bool $expected): void
    {
        $ip = '192.0.2.10';
        $repo = $this->createMock(SuspiciousRequestRepository::class);
        $repo->expects(self::once())->method('countRecentByIp')
            ->with($ip, self::callback(static function (mixed $since): bool {
                return $since instanceof \DateTimeImmutable
                    && abs($since->getTimestamp() - (time() - 60)) <= 5;
            }))->willReturn($count);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(SuspiciousRequest::class)->willReturn($repo);
        $item = $this->createMock(ItemInterface::class);
        $item->expects(self::once())->method('expiresAfter')->with(5)->willReturnSelf();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')
            ->with('bot_rate_v2_'.md5($ip), self::isType('callable'), 0.0)
            ->willReturnCallback(static fn(string $key, callable $callback, ?float $beta = null): int => $callback($item));

        self::assertSame($expected, $this->exceeded(new BotDetectorService($em, $cache), $ip));
    }

    public function testLegacyZeroDoesNotMaskCountAndNewValueIsCached(): void
    {
        $ip = '2001:db8::1';
        $cache = new ArrayAdapter();
        $cache->get('bot_rate_'.md5($ip), static fn(ItemInterface $item): int => 0);
        $repo = $this->createMock(SuspiciousRequestRepository::class);
        $repo->expects(self::once())->method('countRecentByIp')->with($ip, self::isInstanceOf(\DateTimeImmutable::class))->willReturn(31);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(SuspiciousRequest::class)->willReturn($repo);
        $service = new BotDetectorService($em, $cache);

        self::assertTrue($this->exceeded($service, $ip));
        self::assertTrue($this->exceeded($service, $ip));
    }

    public function testCountsAreIsolatedByIp(): void
    {
        $repo = $this->createMock(SuspiciousRequestRepository::class);
        $repo->expects(self::exactly(2))->method('countRecentByIp')->willReturnCallback(static fn(string $ip, \DateTimeImmutable $since): int => $ip === '192.0.2.1' ? 31 : 0);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('getRepository')->with(SuspiciousRequest::class)->willReturn($repo);
        $service = new BotDetectorService($em, new ArrayAdapter());

        self::assertTrue($this->exceeded($service, '192.0.2.1'));
        self::assertFalse($this->exceeded($service, '192.0.2.2'));
    }

    private function exceeded(BotDetectorService $service, string $ip): bool
    {
        return (new \ReflectionMethod(BotDetectorService::class, 'isRateLimitExceeded'))->invoke($service, $ip);
    }
}
