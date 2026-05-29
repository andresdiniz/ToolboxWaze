<?php

namespace App\Service;

use App\Entity\SuspiciousRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class BotDetectorService
{
    private const MALICIOUS_UA_PATTERNS = [
        '/masscan/i', '/zgrab/i', '/nikto/i', '/sqlmap/i', '/nmap/i',
        '/python-requests/i', '/go-http-client/i', '/libwww-perl/i',
        '/wget\//i', '/curl\/[0-2]\./i', '/scrapy/i', '/nuclei/i',
        '/dirbuster/i', '/gobuster/i', '/wfuzz/i', '/hydra/i',
        '/metasploit/i', '/nessus/i', '/acunetix/i', '/burpsuite/i',
    ];

    private const SUSPICIOUS_PATHS = [
        '/.env', '/wp-admin', '/wp-login.php', '/xmlrpc.php',
        '/phpmyadmin', '/.git/config', '/admin/config.php',
        '/shell.php', '/c99.php', '/r57.php', '/config.bak',
        '/etc/passwd', '/proc/self/environ',
    ];

    public function __construct(private EntityManagerInterface $em) {}

    public function analyze(Request $request): array
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $ua = $request->headers->get('User-Agent', '');
        $path = $request->getPathInfo();
        $reasons = [];

        foreach (self::MALICIOUS_UA_PATTERNS as $pattern) {
            if (preg_match($pattern, $ua)) {
                $reasons[] = 'malicious_ua';
                break;
            }
        }

        if (empty(trim($ua))) {
            $reasons[] = 'empty_user_agent';
        }

        foreach (self::SUSPICIOUS_PATHS as $suspiciousPath) {
            if (stripos($path, $suspiciousPath) !== false) {
                $reasons[] = 'suspicious_path:' . $suspiciousPath;
                break;
            }
        }

        if ($this->isRateLimitExceeded($ip)) {
            $reasons[] = 'rate_limit_exceeded';
        }

        if (!empty($reasons)) {
            $isMaliciousUa = in_array('malicious_ua', $reasons);
            $action = ($isMaliciousUa || count($reasons) >= 2) ? 'block' : 'flag';
            $this->persistSuspicion($ip, $ua, $path, $reasons, $action);
            return ['action' => $action, 'reasons' => $reasons];
        }

        return ['action' => 'allow'];
    }

    private function isRateLimitExceeded(string $ip): bool
    {
        $since = new \DateTimeImmutable('-1 minute');
        $count = $this->em->getRepository(SuspiciousRequest::class)
            ->countRecentByIp($ip, $since);

        return $count > 30;
    }

    private function persistSuspicion(string $ip, string $ua, string $path, array $reasons, string $action): void
    {
        $record = new SuspiciousRequest();
        $record->setIp($ip)
               ->setUserAgent(mb_substr($ua, 0, 500))
               ->setPath(mb_substr($path, 0, 500))
               ->setReasons(implode(', ', $reasons))
               ->setAction($action)
               ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($record);
        $this->em->flush();
    }
}
